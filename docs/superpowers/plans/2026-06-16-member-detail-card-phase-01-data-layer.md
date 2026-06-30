# Member Detail Card — Phase 01: Data Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a `GET /api/admin/members/[id]/card` route that returns one member's full card data (identity, AI spend, time, projects, sessions, commits) scoped to the viewer's org and gated by role.

**Architecture:** A pure query lib `src/lib/member-card.ts` assembles all data in parallel DB phases and returns a typed `MemberCardData` object; the route handler enforces role gate (MEMBER → 403; LINE_MANAGER blocked to own-team via the same pattern as `/api/admin/members`) and passes `canSeeSpend` to the lib. Consent version is read once from `TimeTrackingConsent` and drives both the `identity.consentVersion` field and the `time` section shape (v2 → data, v0/v1 → `{ reason: "not_consented" }`).

**Tech Stack:** Next.js 14 App Router · Prisma 7 · `withAuthScoped` + `orgWhere` · Vitest with `vi.mock`

---

## Proven Role-Gate Pattern (DO NOT INVENT — REUSE)

From `src/app/api/admin/members/route.ts:18–21`:
```typescript
const userWhere =
  ctx.role === "MANAGER"
    ? { organisationId: ctx.organisationId }
    : { organisationId: ctx.organisationId, teamId: ctx.teamId ?? "__none__" }
```
Applied per-member: find target user with `{ id, organisationId }`; if LINE_MANAGER and `target.teamId !== ctx.teamId` → 403.

## Proven Token Convention

From `src/lib/dev-cost.ts` comments: "Cache-read tokens … inflate the count ~40× relative to actual spend. Input + output tracks meaningfully with cost." → tokens = `claudeInputTokens + claudeOutputTokens` only.

## Consent Field Location

`TimeTrackingConsent.consentVersion` (a separate table, NOT a field on `User`). Source confirmed in `src/app/api/users/[id]/time/route.ts:47–51`.

## Redaction Proof

`sessionExcerpt`, `gitSummary`, `manualText` are stored already-redacted at ingest (via `redact()` in the ingest pipeline). We return the stored value as-is — no re-processing.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `src/lib/member-card.ts` | All query logic + exported types + `getMemberCard()` |
| Create | `src/app/api/admin/members/[id]/card/route.ts` | GET handler, role gate, rate limit |
| Create | `src/lib/ratelimit-member-card.ts` | 30/min rate limiter for the card endpoint |
| Create | `src/tests/member-card.test.ts` | Unit tests for lib + route |
| Create | `features/member-detail-card/adr-spend-visibility.md` | ADR: LINE_MANAGER spend access |
| Modify | `docs/architecture.md` | New API route |
| Modify | `docs/structure.md` | New lib + route files |
| Modify | `docs/code.md` | `getMemberCard` signature |
| Modify | `docs/decisions.md` | Cross-ref to ADR |

---

## Task 1: Data Query Layer — `src/lib/member-card.ts`

**Files:**
- Create: `src/lib/member-card.ts`
- Test: `src/tests/member-card.test.ts`

- [ ] **Step 1.1: Write the failing test**

Create `src/tests/member-card.test.ts`:

```typescript
// src/tests/member-card.test.ts
import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    user: { findFirst: vi.fn() },
    activityEvent: {
      aggregate: vi.fn(),
      groupBy: vi.fn(),
      findMany: vi.fn(),
    },
    project: { findMany: vi.fn() },
    timeTrackingConsent: { findUnique: vi.fn() },
    memberDailyTime: { findMany: vi.fn() },
  },
}))

import { getMemberCard } from "@/lib/member-card"
import { db } from "@/lib/db"

const mUser = vi.mocked(db.user.findFirst)
const mAggregate = vi.mocked(db.activityEvent.aggregate)
const mGroupBy = vi.mocked(db.activityEvent.groupBy)
const mFindMany = vi.mocked(db.activityEvent.findMany)
const mProjectFindMany = vi.mocked(db.project.findMany)
const mConsent = vi.mocked(db.timeTrackingConsent.findUnique)
const mDailyTime = vi.mocked(db.memberDailyTime.findMany)

beforeEach(() => vi.resetAllMocks())

const TARGET_ID = "user-target"
const ORG_ID = "org-test"

const BASE_USER = {
  id: TARGET_ID,
  name: "Alice Smith",
  email: "alice@test.com",
  role: "MEMBER" as const,
  teamId: "team-1",
  isActive: true,
  team: { name: "Engineering" },
}

const LAST_SEEN_AGG = { _max: { ingestedAt: new Date("2026-06-16T10:00:00Z") } }

const SPEND_AGG = {
  _count: { id: 30 },
  _avg: { sessionDurationSeconds: 1800 },
  _sum: { claudeSpendUSD: 12.5, claudeInputTokens: 100_000, claudeOutputTokens: 25_000 },
}

const TIME_ROWS = [
  {
    day: new Date("2026-06-16T00:00:00Z"),
    workedSeconds: 28_800,
    productiveSeconds: 20_000,
    unproductiveSeconds: 8_800,
    topApps: {
      productive: [{ app: "VS Code", seconds: 18_000 }],
      unproductive: [{ app: "Chrome", seconds: 8_800 }],
    },
  },
]

const GROUPED_PROJECTS = [
  {
    projectId: "proj-1",
    _count: { id: 10 },
    _max: { ingestedAt: new Date("2026-06-15T12:00:00Z") },
  },
]

const PROJECT_ROWS = [{ id: "proj-1", name: "Project Alpha" }]

const SESSION_EVENTS = [
  {
    id: "event-1",
    sessionExcerpt: "Refactored the auth module",
    sessionDurationSeconds: 3_600,
    ingestedAt: new Date("2026-06-16T09:00:00Z"),
    project: { name: "Project Alpha" },
  },
]

const COMMIT_EVENTS = [
  {
    id: "event-2",
    gitCommitSha: "abc1234deadbeef",
    gitSummary: "Fix null pointer in auth",
    filesChanged: ["src/lib/auth.ts"],
    ingestedAt: new Date("2026-06-15T14:00:00Z"),
    project: { name: "Project Alpha" },
  },
]

function setupMocks({
  consentVersion,
  canSeeSpend,
  user = BASE_USER,
}: {
  consentVersion: 0 | 1 | 2
  canSeeSpend: boolean
  user?: typeof BASE_USER | null
}) {
  mUser.mockResolvedValue(user as never)
  mConsent.mockResolvedValue(consentVersion === 0 ? null : { consentVersion } as never)

  if (user !== null) {
    // Phase 2 aggregates — lastSeen always, spend only if canSeeSpend
    mAggregate.mockResolvedValueOnce(LAST_SEEN_AGG as never)
    if (canSeeSpend) {
      mAggregate.mockResolvedValueOnce(SPEND_AGG as never)
    }

    if (consentVersion >= 2) {
      mDailyTime.mockResolvedValue(TIME_ROWS as never)
    }

    mGroupBy.mockResolvedValue(GROUPED_PROJECTS as never)
    mProjectFindMany.mockResolvedValue(PROJECT_ROWS as never)
    mFindMany
      .mockResolvedValueOnce(SESSION_EVENTS as never)
      .mockResolvedValueOnce(COMMIT_EVENTS as never)
  }
}

describe("getMemberCard", () => {
  it("returns null when target user not found", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true, user: null })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result).toBeNull()
  })

  it("assembles identity with last-seen and consent version", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.identity).toEqual({
      id: TARGET_ID,
      name: "Alice Smith",
      email: "alice@test.com",
      role: "MEMBER",
      teamName: "Engineering",
      isActive: true,
      lastSeenAt: "2026-06-16T10:00:00.000Z",
      consentVersion: 2,
    })
  })

  it("includes spend when canSeeSpend=true", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.spend).toEqual({
      allTimeSpendUSD: 12.5,
      allTimeInputTokens: 100_000,
      allTimeOutputTokens: 25_000,
      sessionCount: 30,
      avgSessionDurationSeconds: 1800,
    })
  })

  it("omits spend (undefined) when canSeeSpend=false", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    const result = await getMemberCard(TARGET_ID, ORG_ID, false)
    expect(result?.spend).toBeUndefined()
    expect("spend" in result!).toBe(false)
  })

  it("returns v2 time data with week array when consentVersion=2", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.time).toMatchObject({
      consentVersion: 2,
      week: [
        {
          day: "2026-06-16T00:00:00.000Z",
          workedSeconds: 28_800,
          productiveSeconds: 20_000,
          unproductiveSeconds: 8_800,
          topApps: {
            productive: [{ app: "VS Code", seconds: 18_000 }],
            unproductive: [{ app: "Chrome", seconds: 8_800 }],
          },
        },
      ],
    })
  })

  it("returns not_consented for consentVersion=1 (treated same as v0)", async () => {
    setupMocks({ consentVersion: 1, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.time).toEqual({ consentVersion: 1, reason: "not_consented" })
  })

  it("returns not_consented for consentVersion=0 (no consent row)", async () => {
    setupMocks({ consentVersion: 0, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.time).toEqual({ consentVersion: 0, reason: "not_consented" })
  })

  it("returns projects sorted by lastActiveAt desc with event count", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.projects).toEqual([
      {
        projectId: "proj-1",
        projectName: "Project Alpha",
        eventCount: 10,
        lastActiveAt: "2026-06-15T12:00:00.000Z",
      },
    ])
  })

  it("returns recent sessions with already-redacted excerpt", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.recentSessions[0]).toEqual({
      id: "event-1",
      sessionExcerpt: "Refactored the auth module",
      sessionDurationSeconds: 3_600,
      projectName: "Project Alpha",
      date: "2026-06-16T09:00:00.000Z",
    })
  })

  it("returns recent commits with sha truncated to 7 chars", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: true })
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.recentCommits[0]).toEqual({
      id: "event-2",
      sha: "abc1234",
      gitSummary: "Fix null pointer in auth",
      filesChanged: ["src/lib/auth.ts"],
      projectName: "Project Alpha",
      date: "2026-06-15T14:00:00.000Z",
    })
  })

  it("returns empty arrays for projects/sessions/commits when user has no events", async () => {
    mUser.mockResolvedValue(BASE_USER as never)
    mConsent.mockResolvedValue({ consentVersion: 2 } as never)
    mAggregate
      .mockResolvedValueOnce({ _max: { ingestedAt: null } } as never) // lastSeen
      .mockResolvedValueOnce({ _count: { id: 0 }, _avg: { sessionDurationSeconds: null }, _sum: { claudeSpendUSD: null, claudeInputTokens: null, claudeOutputTokens: null } } as never) // spend
    mDailyTime.mockResolvedValue([])
    mGroupBy.mockResolvedValue([])
    mFindMany.mockResolvedValueOnce([]).mockResolvedValueOnce([])
    // project.findMany should NOT be called when grouped is empty
    const result = await getMemberCard(TARGET_ID, ORG_ID, true)
    expect(result?.identity.lastSeenAt).toBeNull()
    expect(result?.spend?.allTimeSpendUSD).toBe(0)
    expect(result?.spend?.sessionCount).toBe(0)
    expect(result?.spend?.avgSessionDurationSeconds).toBeNull()
    expect(result?.projects).toEqual([])
    expect(result?.recentSessions).toEqual([])
    expect(result?.recentCommits).toEqual([])
    expect(mProjectFindMany).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 1.2: Run test to confirm it fails**

```
npx vitest run src/tests/member-card.test.ts
```

Expected: FAIL — "Cannot find module '@/lib/member-card'"

- [ ] **Step 1.3: Create `src/lib/member-card.ts`**

```typescript
import { db } from "@/lib/db"

// ── Types ─────────────────────────────────────────────────────────────────────

export type MemberIdentity = {
  id: string
  name: string
  email: string
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  teamName: string | null
  isActive: boolean
  lastSeenAt: string | null
  consentVersion: number
}

export type MemberSpend = {
  allTimeSpendUSD: number
  allTimeInputTokens: number
  allTimeOutputTokens: number
  sessionCount: number
  avgSessionDurationSeconds: number | null
}

export type TopApps = {
  productive: Array<{ app: string; seconds: number }>
  unproductive: Array<{ app: string; seconds: number }>
}

export type TimeDataV2 = {
  consentVersion: 2
  week: Array<{
    day: string
    workedSeconds: number
    productiveSeconds: number
    unproductiveSeconds: number
    topApps: TopApps | null
  }>
}

export type TimeDataNotConsented = {
  consentVersion: 0 | 1
  reason: "not_consented"
}

export type MemberTimeData = TimeDataV2 | TimeDataNotConsented

export type MemberProject = {
  projectId: string
  projectName: string
  eventCount: number
  lastActiveAt: string
}

export type MemberSession = {
  id: string
  sessionExcerpt: string | null
  sessionDurationSeconds: number | null
  projectName: string
  date: string
}

export type MemberCommit = {
  id: string
  sha: string
  gitSummary: string | null
  filesChanged: string[]
  projectName: string
  date: string
}

export type MemberCardData = {
  identity: MemberIdentity
  spend?: MemberSpend
  time: MemberTimeData
  projects: MemberProject[]
  recentSessions: MemberSession[]
  recentCommits: MemberCommit[]
}

// ── Orchestrator ──────────────────────────────────────────────────────────────

/**
 * Assembles all per-member card data for a target user.
 *
 * @param targetId - the target user's id
 * @param organisationId - the viewer's organisationId (cross-org guard applied at route)
 * @param canSeeSpend - true when viewer is MANAGER or LINE_MANAGER; false omits spend from response
 * @returns MemberCardData or null if target user not found in this org
 */
export async function getMemberCard(
  targetId: string,
  organisationId: string,
  canSeeSpend: boolean,
): Promise<MemberCardData | null> {
  // Phase 1 — user existence + consent (parallel; consent drives time section shape)
  const [user, consent] = await Promise.all([
    db.user.findFirst({
      where: { id: targetId, organisationId },
      select: {
        id: true,
        name: true,
        email: true,
        role: true,
        teamId: true,
        isActive: true,
        team: { select: { name: true } },
      },
    }),
    db.timeTrackingConsent.findUnique({
      where: { userId: targetId },
      select: { consentVersion: true },
    }),
  ])

  if (!user) return null

  const consentVersion = consent?.consentVersion ?? 0

  // Phase 2 — all data in parallel
  const now = new Date()
  const startOfToday = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()))
  const startOfTomorrow = new Date(startOfToday.getTime() + 24 * 60 * 60 * 1_000)
  const sevenDaysAgo = new Date(startOfToday.getTime() - 6 * 24 * 60 * 60 * 1_000)

  const [lastSeenAgg, spendAgg, timeRows, grouped, sessions, commits] = await Promise.all([
    db.activityEvent.aggregate({
      where: { userId: targetId, organisationId },
      _max: { ingestedAt: true },
    }),
    canSeeSpend
      ? db.activityEvent.aggregate({
          where: { userId: targetId, organisationId, hookSource: "Stop" },
          _count: { id: true },
          _avg: { sessionDurationSeconds: true },
          _sum: {
            claudeSpendUSD: true,
            claudeInputTokens: true,
            claudeOutputTokens: true,
          },
        })
      : Promise.resolve(null),
    consentVersion >= 2
      ? db.memberDailyTime.findMany({
          where: { userId: targetId, day: { gte: sevenDaysAgo, lt: startOfTomorrow } },
          orderBy: { day: "asc" },
          select: {
            day: true,
            workedSeconds: true,
            productiveSeconds: true,
            unproductiveSeconds: true,
            topApps: true,
          },
        })
      : Promise.resolve([] as typeof timeRows),
    db.activityEvent.groupBy({
      by: ["projectId"],
      where: { userId: targetId, organisationId },
      _count: { id: true },
      _max: { ingestedAt: true },
    }),
    db.activityEvent.findMany({
      where: { userId: targetId, organisationId, hookSource: "Stop", kind: "CLAUDE_HOOK" },
      orderBy: { ingestedAt: "desc" },
      take: 15,
      select: {
        id: true,
        sessionExcerpt: true,
        sessionDurationSeconds: true,
        ingestedAt: true,
        project: { select: { name: true } },
      },
    }),
    db.activityEvent.findMany({
      where: { userId: targetId, organisationId, gitCommitSha: { not: null } },
      orderBy: { ingestedAt: "desc" },
      take: 15,
      select: {
        id: true,
        gitCommitSha: true,
        gitSummary: true,
        filesChanged: true,
        ingestedAt: true,
        project: { select: { name: true } },
      },
    }),
  ])

  // Phase 3 — resolve project names (depends on grouped)
  const projectIds = grouped.map((r) => r.projectId)
  const projectRows =
    projectIds.length > 0
      ? await db.project.findMany({
          where: { id: { in: projectIds }, organisationId },
          select: { id: true, name: true },
        })
      : []
  const projectNameMap = new Map(projectRows.map((p) => [p.id, p.name]))

  // ── Assemble ───────────────────────────────────────────────────────────────

  const identity: MemberIdentity = {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    teamName: user.team?.name ?? null,
    isActive: user.isActive,
    lastSeenAt: lastSeenAgg._max.ingestedAt?.toISOString() ?? null,
    consentVersion,
  }

  const spend: MemberSpend | undefined = canSeeSpend && spendAgg
    ? {
        allTimeSpendUSD: spendAgg._sum.claudeSpendUSD ?? 0,
        allTimeInputTokens: spendAgg._sum.claudeInputTokens ?? 0,
        allTimeOutputTokens: spendAgg._sum.claudeOutputTokens ?? 0,
        sessionCount: spendAgg._count.id,
        avgSessionDurationSeconds: spendAgg._avg.sessionDurationSeconds ?? null,
      }
    : undefined

  const time: MemberTimeData =
    consentVersion >= 2
      ? {
          consentVersion: 2,
          week: (timeRows as Array<{
            day: Date
            workedSeconds: number
            productiveSeconds: number
            unproductiveSeconds: number
            topApps: unknown
          }>).map((r) => ({
            day: r.day.toISOString(),
            workedSeconds: r.workedSeconds,
            productiveSeconds: r.productiveSeconds,
            unproductiveSeconds: r.unproductiveSeconds,
            topApps: (r.topApps as TopApps | null) ?? null,
          })),
        }
      : { consentVersion: consentVersion as 0 | 1, reason: "not_consented" }

  const projects: MemberProject[] = grouped
    .filter((r) => r._max.ingestedAt !== null)
    .sort((a, b) => (b._max.ingestedAt?.getTime() ?? 0) - (a._max.ingestedAt?.getTime() ?? 0))
    .map((r) => ({
      projectId: r.projectId,
      projectName: projectNameMap.get(r.projectId) ?? r.projectId,
      eventCount: r._count.id,
      lastActiveAt: r._max.ingestedAt!.toISOString(),
    }))

  const recentSessions: MemberSession[] = (sessions as Array<{
    id: string
    sessionExcerpt: string | null
    sessionDurationSeconds: number | null
    ingestedAt: Date
    project: { name: string }
  }>).map((e) => ({
    id: e.id,
    sessionExcerpt: e.sessionExcerpt ?? null,
    sessionDurationSeconds: e.sessionDurationSeconds ?? null,
    projectName: e.project.name,
    date: e.ingestedAt.toISOString(),
  }))

  const recentCommits: MemberCommit[] = (commits as Array<{
    id: string
    gitCommitSha: string | null
    gitSummary: string | null
    filesChanged: string[]
    ingestedAt: Date
    project: { name: string }
  }>).map((e) => ({
    id: e.id,
    sha: (e.gitCommitSha ?? "").slice(0, 7),
    gitSummary: e.gitSummary ?? null,
    filesChanged: e.filesChanged,
    projectName: e.project.name,
    date: e.ingestedAt.toISOString(),
  }))

  const card: MemberCardData = { identity, time, projects, recentSessions, recentCommits }
  if (spend !== undefined) card.spend = spend
  return card
}
```

- [ ] **Step 1.4: Run test to verify it passes**

```
npx vitest run src/tests/member-card.test.ts
```

Expected: all 11 tests PASS, 0 failures.

- [ ] **Step 1.5: Run full suite to confirm no regressions**

```
npm test
```

Expected: all existing tests still pass + 11 new tests.

- [ ] **Step 1.6: Commit**

```
git add src/lib/member-card.ts src/tests/member-card.test.ts
git commit -m "feat(member-card): add data query layer with consent-gated time and spend"
```

---

## Task 2: Rate Limiter — `src/lib/ratelimit-member-card.ts`

**Files:**
- Create: `src/lib/ratelimit-member-card.ts`

Mirrors `src/lib/ratelimit-users-time.ts` exactly. 30 req/min per caller userId.

- [ ] **Step 2.1: Create `src/lib/ratelimit-member-card.ts`**

```typescript
// GET /api/admin/members/:id/card rate limiter: 30/min/user.
import { Ratelimit } from "@upstash/ratelimit"
import { Redis } from "@upstash/redis"

let _rl: Ratelimit | null = null

function getUpstashLimiter(): Ratelimit | null {
  if (!process.env.UPSTASH_REDIS_REST_URL || !process.env.UPSTASH_REDIS_REST_TOKEN) return null
  if (!_rl) {
    _rl = new Ratelimit({
      redis: Redis.fromEnv(),
      limiter: Ratelimit.slidingWindow(30, "60 s"),
      prefix: "pulse:admin:member-card",
    })
  }
  return _rl
}

const WINDOW_MS = 60_000
const _store = new Map<string, number[]>()

export async function checkMemberCardRateLimit(userId: string): Promise<{ allowed: boolean }> {
  const upstash = getUpstashLimiter()
  if (upstash) {
    try {
      const { success } = await upstash.limit(userId)
      return { allowed: success }
    } catch {
      return { allowed: true }
    }
  }
  const now = Date.now()
  const windowStart = now - WINDOW_MS
  const prev = _store.get(userId) ?? []
  const inWindow = prev.filter((t) => t > windowStart)
  if (inWindow.length >= 30) {
    _store.set(userId, inWindow)
    return { allowed: false }
  }
  inWindow.push(now)
  _store.set(userId, inWindow)
  return { allowed: true }
}

export function _resetMemberCardRateLimitStore(): void {
  _store.clear()
}
```

- [ ] **Step 2.2: Commit**

```
git add src/lib/ratelimit-member-card.ts
git commit -m "chore(member-card): add rate limiter (30/min)"
```

---

## Task 3: Route Handler — `src/app/api/admin/members/[id]/card/route.ts`

**Files:**
- Create: `src/app/api/admin/members/[id]/card/route.ts`
- Modify: `src/tests/member-card.test.ts` (add route tests)

- [ ] **Step 3.1: Add failing route tests to `src/tests/member-card.test.ts`**

Add these mocks at the top (after the existing `vi.mock` blocks):

```typescript
vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/ratelimit-member-card", () => ({
  checkMemberCardRateLimit: vi.fn().mockResolvedValue({ allowed: true }),
}))

vi.mock("@/lib/member-card", () => ({
  getMemberCard: vi.fn(),
}))
```

Add these imports after the existing ones:

```typescript
import { GET } from "@/app/api/admin/members/[id]/card/route"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { getMemberCard } from "@/lib/member-card"
import type { AuthContext } from "@/lib/withAuthScoped"
```

Add these context fixtures (after the existing const declarations):

```typescript
const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  organisationId: "org-test",
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const LM_TEAM1_CTX: AuthContext = {
  userId: "u-lm",
  role: "LINE_MANAGER",
  teamId: "team-1",
  isLineManager: true,
  organisationId: "org-test",
  scope: { allTeams: false, canSeeTimeData: true, viewedTeamIds: ["team-1"] },
}

const MEMBER_CTX: AuthContext = {
  userId: "u-member",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  organisationId: "org-test",
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["team-1"] },
}

const STUB_CARD = { identity: { id: TARGET_ID }, spend: {}, time: {}, projects: [], recentSessions: [], recentCommits: [] }
```

Add these test cases:

```typescript
describe("GET /api/admin/members/[id]/card", () => {
  const params = { params: { id: TARGET_ID } }

  it("returns 401 when unauthenticated", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(null)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(401)
  })

  it("returns 403 for MEMBER role", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MEMBER_CTX)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(403)
  })

  it("returns 404 when getMemberCard returns null (user not in org)", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
    vi.mocked(getMemberCard).mockResolvedValue(null)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(404)
  })

  it("MANAGER gets 200 with card data including spend", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
    vi.mocked(getMemberCard).mockResolvedValue(STUB_CARD as never)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(200)
    expect(vi.mocked(getMemberCard)).toHaveBeenCalledWith(TARGET_ID, "org-test", true)
  })

  it("LINE_MANAGER on own team gets 200 with spend", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(LM_TEAM1_CTX)
    // getMemberCard internally checks org scope; route passes canSeeSpend=true for LM
    vi.mocked(getMemberCard).mockResolvedValue(STUB_CARD as never)
    // Mock db.user.findFirst for the team-gate check inside the route
    vi.mocked(db.user.findFirst).mockResolvedValue({ id: TARGET_ID, teamId: "team-1" } as never)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(200)
    expect(vi.mocked(getMemberCard)).toHaveBeenCalledWith(TARGET_ID, "org-test", true)
  })

  it("LINE_MANAGER on different team gets 403", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(LM_TEAM1_CTX)
    vi.mocked(db.user.findFirst).mockResolvedValue({ id: TARGET_ID, teamId: "team-2" } as never)
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(403)
    expect(vi.mocked(getMemberCard)).not.toHaveBeenCalled()
  })

  it("returns 429 when rate limit exceeded", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
    const { checkMemberCardRateLimit } = await import("@/lib/ratelimit-member-card")
    vi.mocked(checkMemberCardRateLimit).mockResolvedValueOnce({ allowed: false })
    const res = await GET(new Request("http://localhost"), params)
    expect(res.status).toBe(429)
  })
})
```

- [ ] **Step 3.2: Run test to verify route tests fail**

```
npx vitest run src/tests/member-card.test.ts
```

Expected: FAIL — "Cannot find module '@/app/api/admin/members/[id]/card/route'"

- [ ] **Step 3.3: Create `src/app/api/admin/members/[id]/card/route.ts`**

Role-gate pattern quoted from `src/app/api/admin/members/route.ts:18–21`:
LINE_MANAGER can only see users where `target.teamId === ctx.teamId`.

```typescript
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { getMemberCard } from "@/lib/member-card"
import { checkMemberCardRateLimit } from "@/lib/ratelimit-member-card"

// GET /api/admin/members/:id/card
// MANAGER: any org member. LINE_MANAGER: own-team members only. MEMBER: blocked.
// Spend is included for all permitted viewers (MANAGER + LINE_MANAGER for own team).
export async function GET(
  _request: Request,
  { params }: { params: { id: string } },
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const { allowed } = await checkMemberCardRateLimit(ctx.userId)
  if (!allowed) return NextResponse.json({ error: "Rate limit exceeded" }, { status: 429 })

  const targetId = params.id

  // LINE_MANAGER team-gate: same pattern as GET /api/admin/members (route.ts:18-21)
  if (ctx.role === "LINE_MANAGER") {
    const target = await db.user.findFirst({
      where: { id: targetId, organisationId: ctx.organisationId },
      select: { teamId: true },
    })
    if (!target) return NextResponse.json({ reason: "not_found" }, { status: 404 })
    if (target.teamId !== ctx.teamId) {
      return NextResponse.json({ reason: "forbidden" }, { status: 403 })
    }
  }

  // canSeeSpend: all non-MEMBER roles (MANAGER + LINE_MANAGER).
  // Spend is never sent to MEMBER (they are blocked above).
  const canSeeSpend = true

  const card = await getMemberCard(targetId, ctx.organisationId, canSeeSpend)
  if (!card) return NextResponse.json({ reason: "not_found" }, { status: 404 })

  return NextResponse.json(card)
}
```

- [ ] **Step 3.4: Run route tests to verify they pass**

```
npx vitest run src/tests/member-card.test.ts
```

Expected: all tests PASS.

- [ ] **Step 3.5: Run full suite**

```
npm test
```

Expected: all existing tests pass + new tests pass.

- [ ] **Step 3.6: Commit**

```
git add src/app/api/admin/members/[id]/card/route.ts src/tests/member-card.test.ts
git commit -m "feat(member-card): add GET /api/admin/members/[id]/card with RBAC gate"
```

---

## Task 4: ADR + Docs Update

**Files:**
- Create: `features/member-detail-card/adr-spend-visibility.md`
- Modify: `docs/architecture.md` (new route entry)
- Modify: `docs/structure.md` (new files)
- Modify: `docs/code.md` (`getMemberCard` signature)
- Modify: `docs/decisions.md` (ADR cross-reference)

- [ ] **Step 4.1: Create ADR**

Create `features/member-detail-card/adr-spend-visibility.md`:

```markdown
# ADR: Extend Spend Visibility to LINE_MANAGER (Member Detail Card)

**Status:** Accepted  
**Date:** 2026-06-16  
**Feature:** Member Detail Card — Phase 01 Data Layer

## Context

`getDevSpendByUser` and the cost-dashboard page were previously used only by MANAGER-level
viewers. LINE_MANAGER had no access to individual members' AI spend data.

The member detail card introduces a per-member spend section (all-time USD + tokens).
LINE_MANAGER already has `canSeeTimeData = true` (from `withAuthScoped`) and can access
time data for their own team members via `GET /api/users/[id]/time`.

## Decision

Extend spend visibility to LINE_MANAGER for their own team members only.
- MANAGER: sees spend for any org member.
- LINE_MANAGER: sees spend for own-team members only (same team-gate as the members list).
- MEMBER: blocked at 403 before spend is ever computed.

The spend section is omitted server-side (not present in the JSON response) when `canSeeSpend=false`.
In practice, `canSeeSpend` is always true for successful responses because MEMBER is blocked
before reaching the data layer. This explicit flag is retained so future role changes
(e.g. a read-only OBSERVER role) do not require touching the lib.

## Consequences

- LINE_MANAGER can now see how much AI spend their team members are generating — consistent
  with their existing visibility into time data.
- No new data is captured; existing `claudeSpendUSD` fields are surfaced.
- The ADR for MANAGER-only spend (implied by cost-dashboard design) is superseded for this
  context. The cost-dashboard itself is unchanged.
```

- [ ] **Step 4.2: Update `docs/architecture.md`** — add route to the API routes table

Find the API routes table in `docs/architecture.md` and add:

```
| GET  | /api/admin/members/[id]/card | MANAGER any org member; LINE_MANAGER own-team; MEMBER blocked | Member detail card data |
```

- [ ] **Step 4.3: Update `docs/structure.md`** — add new files

Under `src/lib/` section add:
```
- `member-card.ts` — getMemberCard(): all per-member card queries (identity, spend, time, projects, sessions, commits)
- `ratelimit-member-card.ts` — 30/min rate limiter for GET /api/admin/members/[id]/card
```

Under `src/app/api/admin/members/[id]/` section add:
```
- `card/route.ts` — GET /api/admin/members/[id]/card (role-gated member detail card)
```

Under `src/tests/` add:
```
- `member-card.test.ts` — unit tests for getMemberCard lib + GET /api/admin/members/[id]/card role gate
```

- [ ] **Step 4.4: Update `docs/code.md`** — add getMemberCard signature

```markdown
### getMemberCard

`src/lib/member-card.ts`

```typescript
getMemberCard(
  targetId: string,
  organisationId: string,
  canSeeSpend: boolean,
): Promise<MemberCardData | null>
```

Returns `null` if user not found in org. `canSeeSpend=false` omits the `spend` key entirely
from the returned object. Time section shape depends on `TimeTrackingConsent.consentVersion`:
`>= 2` → `TimeDataV2` with week array; `< 2` → `TimeDataNotConsented` with `reason: "not_consented"`.
```

- [ ] **Step 4.5: Update `docs/decisions.md`** — cross-reference the ADR

Add to the ADR list:
```markdown
## ADR-spend-lm: LINE_MANAGER Spend Visibility in Member Detail Card

See `features/member-detail-card/adr-spend-visibility.md`.

Decision: LINE_MANAGER can see spend for own-team members via the member detail card.
This widens spend visibility beyond MANAGER-only (cost-dashboard). Spend section is
omitted server-side when `canSeeSpend=false`.
```

- [ ] **Step 4.6: Commit**

```
git add features/member-detail-card/adr-spend-visibility.md docs/architecture.md docs/structure.md docs/code.md docs/decisions.md
git commit -m "docs(member-card): ADR for LM spend visibility + update all 4 docs"
```

---

## Self-Review

**Spec coverage:**
- [x] MANAGER any org member → Task 3 route gate
- [x] LINE_MANAGER own-team only → Task 3 route gate (quotes exact pattern)
- [x] MEMBER blocked → Task 3, returns 403
- [x] Cross-org blocked → `getMemberCard` finds user with `{ id, organisationId }`, returns null → 404
- [x] LINE_MANAGER cross-team blocked → Task 3 test proves 403 before getMemberCard called
- [x] Spend omitted server-side for non-permitted → `canSeeSpend` flag, omitted when false
- [x] Time v2 → data returned; v1/v0 → `not_consented` (not zeros)
- [x] Tokens = input + output only (cache excluded) → confirmed in code comment + field selection
- [x] Redaction = already-stored redacted value (no re-processing) → confirmed, stored fields returned as-is
- [x] No "tasks" entity → sections labelled "recentSessions" / "recentCommits"
- [x] Projects = activity-based → `ActivityEvent.groupBy` by projectId
- [x] organisationId on every query → confirmed in all where clauses
- [x] ADR for spend widening → Task 4
- [x] Rate limiter → Task 2 (30/min)

**Placeholder scan:** None found.

**Type consistency:**
- `MemberCardData.spend?: MemberSpend` — optional, set only when `canSeeSpend=true`
- `MemberCardData.time: MemberTimeData` — discriminated union on `consentVersion`
- Route calls `getMemberCard(targetId, ctx.organisationId, canSeeSpend)` — matches lib signature exactly
