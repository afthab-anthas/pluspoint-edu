# Member Detail Card — Phase 03: Window Control + feedSummary

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Today/7d/30d window toggle to the member detail card, with dual-tz windowing (member-tz for time-at-keyboard, viewer-tz for events), and switch Recent Sessions from raw `sessionExcerpt` to clean `feedSummary`.

**Architecture:** Two independent windowing mechanisms coexist. Time section: query `MemberDailyTime` rows that match the member's own local calendar days (computed via `Intl.DateTimeFormat` from the timezone stored in their most-recent day row). Event sections (spend/sessions/commits/projects): reuse `resolveTimeWindow` from `src/lib/project-time-window.ts` exactly as the project page does; the viewer's local midnight is injected browser-side as `?todayCutoff=<epochMs>`. `lastSeenAt` stays all-time. The window param is additive time-narrowing on top of existing `organisationId + userId` scoping — it cannot widen access. Sessions switch from raw `sessionExcerpt` to `feedSummary: string | null` (a Groq-generated 5-8 word phrase); assembly falls back to `firstSentence(sessionExcerpt)` server-side so the client never imports from `gemini.ts`.

**Tech Stack:** TypeScript 5 · Prisma 7 · Next.js 14 App Router · Vitest · `Intl.DateTimeFormat` · `resolveTimeWindow` (existing) · `firstSentence` (existing, in `src/lib/gemini.ts`)

---

## File Map

| File | Change |
|---|---|
| `src/lib/member-card.ts` | Add `feedSummary` to sessions query+type; add `rawWindow?`/`viewerTodayMs?` params; add Phase 1 tz lookup; apply event `since` cutoff; apply member-tz time range |
| `src/app/api/admin/members/[id]/card/route.ts` | Read `?window` and `?todayCutoff` from URL; pass to `getMemberCard` |
| `src/app/admin/members/_components/member-card-fetch.ts` | Accept `opts?: { window?: TimeWindow; todayCutoff?: number }`; append to URL |
| `src/app/admin/members/_components/MemberDetailCard.tsx` | Add `WindowControl` toggle; inject `viewerTodayCutoff` on mount; refetch on window change; `SessionsSection` → `feedSummary ?? firstSentence ?? "—"`; `TimeSection` → member-tz note |
| `src/tests/member-card.test.ts` | Update SESSION_EVENTS fixture; update session assertion; add feedSummary tests; add windowing tests; add member-tz tests |
| `src/tests/member-card-route.test.ts` | Update `toHaveBeenCalledWith` assertions for new params; add window-passing test |
| `src/tests/member-card-fetch.test.ts` | Add test for URL with window + todayCutoff opts |

---

## Task 1: Add feedSummary to MemberSession (lib + tests)

Scope: add `feedSummary: string | null` to `MemberSession` type and the sessions DB select. Assembly maps `feedSummary: e.feedSummary ?? null`. Update existing session test; add null-feedSummary test.

**Files:**
- Modify: `src/lib/member-card.ts:54-60` (type) + `:180-190` (select) + `:277-283` (SessionRow + assembly)
- Modify: `src/tests/member-card.test.ts:75-84` (fixture) + `:210-219` (assertion) + add new test

---

- [ ] **Step 1: Write failing tests**

In `src/tests/member-card.test.ts`:

Replace the `SESSION_EVENTS` fixture (currently at line 75) to include `feedSummary`:

```ts
const SESSION_EVENTS = [
  {
    id: "event-1",
    feedSummary: "Fixed auth module refactor",
    sessionExcerpt: "Refactored the auth module",
    sessionDurationSeconds: 3_600,
    ingestedAt: new Date("2026-06-16T09:00:00Z"),
    project: { name: "Project Alpha" },
  },
]
```

Replace the test "returns recent sessions with already-redacted excerpt" (line 210):

```ts
it("returns recent sessions with feedSummary and excerpt", async () => {
  setupMocks({ consentVersion: 2, canSeeSpend: true })
  const result = await getMemberCard(TARGET_ID, ORG_ID, true)
  expect(result?.recentSessions[0]).toEqual({
    id: "event-1",
    feedSummary: "Fixed auth module refactor",
    sessionExcerpt: "Refactored the auth module",
    sessionDurationSeconds: 3_600,
    projectName: "Project Alpha",
    date: "2026-06-16T09:00:00.000Z",
  })
})
```

Add a new test after the "recent sessions" test:

```ts
it("sessions feedSummary is null when event has no feedSummary", async () => {
  mUser.mockResolvedValue(BASE_USER as never)
  mConsent.mockResolvedValue({ consentVersion: 2 } as never)
  mAggregate
    .mockResolvedValueOnce(LAST_SEEN_AGG as never)
    .mockResolvedValueOnce(SPEND_AGG as never)
  mDailyTime.mockResolvedValue(TIME_ROWS as never)
  mGroupBy.mockResolvedValue(GROUPED_PROJECTS as never)
  mProjectFindMany.mockResolvedValue(PROJECT_ROWS as never)
  mFindMany
    .mockResolvedValueOnce([{
      id: "event-1",
      feedSummary: null,
      sessionExcerpt: "Some transcript text",
      sessionDurationSeconds: 1800,
      ingestedAt: new Date("2026-06-16T09:00:00Z"),
      project: { name: "Project Alpha" },
    }] as never)
    .mockResolvedValueOnce([])

  const result = await getMemberCard(TARGET_ID, ORG_ID, true)
  expect(result?.recentSessions[0]?.feedSummary).toBeNull()
  expect(result?.recentSessions[0]?.sessionExcerpt).toBe("Some transcript text")
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=member-card.test
```

Expected: FAIL — `feedSummary` property is not on `MemberSession` type and not returned.

- [ ] **Step 3: Implement — update MemberSession type**

In `src/lib/member-card.ts`, update the `MemberSession` type (line 54):

```ts
export type MemberSession = {
  id: string
  feedSummary: string | null   // Groq 5-8 word phrase; null for old events or manual entries
  sessionExcerpt: string | null
  sessionDurationSeconds: number | null
  projectName: string
  date: string
}
```

- [ ] **Step 4: Implement — add feedSummary to sessions query select**

In `src/lib/member-card.ts`, in the `sessions` `findMany` call (the `select` block around line 183), add `feedSummary: true`:

```ts
select: {
  id: true,
  feedSummary: true,
  sessionExcerpt: true,
  sessionDurationSeconds: true,
  ingestedAt: true,
  project: { select: { name: true } },
},
```

- [ ] **Step 5: Implement — update SessionRow type and assembly**

In `src/lib/member-card.ts`, update the `SessionRow` type and the mapping (around line 269):

```ts
type SessionRow = {
  id: string
  feedSummary: string | null
  sessionExcerpt: string | null
  sessionDurationSeconds: number | null
  ingestedAt: Date
  project: { name: string }
}

const recentSessions: MemberSession[] = (sessions as SessionRow[]).map((e) => ({
  id: e.id,
  feedSummary: e.feedSummary ?? null,
  sessionExcerpt: e.sessionExcerpt ?? null,
  sessionDurationSeconds: e.sessionDurationSeconds ?? null,
  projectName: e.project.name,
  date: e.ingestedAt.toISOString(),
}))
```

- [ ] **Step 6: Run tests to verify they pass**

```
npm test -- --testPathPattern=member-card.test
```

Expected: PASS (all tests in the file green).

- [ ] **Step 7: Type-check**

```
npx tsc --noEmit
```

Expected: 0 errors in `src/` (test errors are pre-existing and tracked separately).

- [ ] **Step 8: Commit**

```
git add src/lib/member-card.ts src/tests/member-card.test.ts
git commit -m "feat(member-card): add feedSummary to MemberSession type and sessions query"
```

---

## Task 2: Event-based windowing in getMemberCard (lib + tests)

Scope: add `rawWindow?: string` and `viewerTodayMs?: number` params to `getMemberCard`. Import and call `resolveTimeWindow` to compute `since`. Apply `ingestedAt: { gte: since }` to spend, sessions, commits, and projects queries. `lastSeenAt` aggregate stays all-time. Default window = 7d (rolling from now).

**Files:**
- Modify: `src/lib/member-card.ts` — add params, import, since computation, query modifications
- Modify: `src/tests/member-card.test.ts` — add windowing tests; update `setupMocks` to handle new param shape

---

- [ ] **Step 1: Write failing tests for event windowing**

Add these tests to `src/tests/member-card.test.ts`, inside the `describe("getMemberCard")` block:

```ts
describe("event-based windowing (viewer timezone)", () => {
  it("applies ingestedAt gte cutoff to sessions when window=today with todayCutoff", async () => {
    // viewer's local midnight = 2026-06-16T00:00:00Z
    const todayMs = new Date("2026-06-16T00:00:00Z").getTime()
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    await getMemberCard(TARGET_ID, ORG_ID, false, "today", todayMs)

    // sessions findMany is the first findMany call
    const sessionCallArgs = mFindMany.mock.calls[0][0] as { where: Record<string, unknown> }
    expect(sessionCallArgs.where).toMatchObject({
      ingestedAt: { gte: new Date(todayMs) },
    })
  })

  it("applies ingestedAt gte cutoff to commits when window=today", async () => {
    const todayMs = new Date("2026-06-16T00:00:00Z").getTime()
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    await getMemberCard(TARGET_ID, ORG_ID, false, "today", todayMs)

    // commits findMany is the second findMany call
    const commitCallArgs = mFindMany.mock.calls[1][0] as { where: Record<string, unknown> }
    expect(commitCallArgs.where).toMatchObject({
      ingestedAt: { gte: new Date(todayMs) },
    })
  })

  it("lastSeenAt aggregate has NO ingestedAt filter — stays all-time", async () => {
    const todayMs = new Date("2026-06-16T00:00:00Z").getTime()
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    await getMemberCard(TARGET_ID, ORG_ID, false, "today", todayMs)

    // aggregate is called twice: first call = lastSeenAt (no ingestedAt filter)
    const lastSeenCall = mAggregate.mock.calls[0][0] as { where: Record<string, unknown> }
    expect(lastSeenCall.where).not.toHaveProperty("ingestedAt")
  })

  it("defaults to 7d rolling window when no rawWindow supplied", async () => {
    const before = Date.now()
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    await getMemberCard(TARGET_ID, ORG_ID, false)
    const after = Date.now()

    const sessionCallArgs = mFindMany.mock.calls[0][0] as { where: { ingestedAt?: { gte: Date } } }
    const since = sessionCallArgs.where.ingestedAt?.gte
    expect(since).toBeInstanceOf(Date)
    // since should be ~7 days ago (between before-7d and after-7d, with small clock tolerance)
    const sevenDaysMs = 7 * 24 * 60 * 60 * 1000
    expect(since!.getTime()).toBeGreaterThanOrEqual(before - sevenDaysMs - 1000)
    expect(since!.getTime()).toBeLessThanOrEqual(after - sevenDaysMs + 1000)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=member-card.test
```

Expected: FAIL — `getMemberCard` doesn't yet accept window params.

- [ ] **Step 3: Implement — add import + params + since computation**

At the top of `src/lib/member-card.ts`, add:

```ts
import { resolveTimeWindow } from "@/lib/project-time-window"
import type { TimeWindow } from "@/lib/project-time-window"
```

Update the function signature:

```ts
export async function getMemberCard(
  targetId: string,
  organisationId: string,
  canSeeSpend: boolean,
  rawWindow?: string,
  viewerTodayMs?: number,
): Promise<MemberCardData | null> {
```

After the existing Phase 1 block (after `if (!user) return null`), add before Phase 2:

```ts
  const resolved = resolveTimeWindow(rawWindow, viewerTodayMs)
  const tw: TimeWindow = resolved.window
  const since = resolved.start  // event cutoff (viewer timezone)
```

- [ ] **Step 4: Implement — apply since to event queries**

In the Phase 2 `Promise.all`, update:

**spendAgg** — add `ingestedAt: { gte: since }` to the where clause:
```ts
canSeeSpend
  ? db.activityEvent.aggregate({
      where: { userId: targetId, organisationId, hookSource: "Stop", ingestedAt: { gte: since } },
      _count: { id: true },
      _avg: { sessionDurationSeconds: true },
      _sum: {
        claudeSpendUSD: true,
        claudeInputTokens: true,
        claudeOutputTokens: true,
      },
    })
  : Promise.resolve(null),
```

**grouped** (projects groupBy) — add `ingestedAt: { gte: since }`:
```ts
db.activityEvent.groupBy({
  by: ["projectId"],
  where: { userId: targetId, organisationId, ingestedAt: { gte: since } },
  _count: { id: true },
  _max: { ingestedAt: true },
}),
```

**sessions findMany** — add `ingestedAt: { gte: since }`:
```ts
db.activityEvent.findMany({
  where: {
    userId: targetId,
    organisationId,
    hookSource: "Stop",
    kind: "CLAUDE_HOOK",
    ingestedAt: { gte: since },
  },
  orderBy: { ingestedAt: "desc" },
  take: 15,
  select: {
    id: true,
    feedSummary: true,
    sessionExcerpt: true,
    sessionDurationSeconds: true,
    ingestedAt: true,
    project: { select: { name: true } },
  },
}),
```

**commits findMany** — add `ingestedAt: { gte: since }`:
```ts
db.activityEvent.findMany({
  where: {
    userId: targetId,
    organisationId,
    gitCommitSha: { not: null },
    ingestedAt: { gte: since },
  },
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
```

**lastSeenAgg** — NO change (stays all-time):
```ts
db.activityEvent.aggregate({
  where: { userId: targetId, organisationId },  // no ingestedAt filter
  _max: { ingestedAt: true },
}),
```

- [ ] **Step 5: Run tests to verify they pass**

```
npm test -- --testPathPattern=member-card.test
```

Expected: PASS.

- [ ] **Step 6: Commit**

```
git add src/lib/member-card.ts src/tests/member-card.test.ts
git commit -m "feat(member-card): add event-based windowing with viewer-tz resolveTimeWindow"
```

---

## Task 3: Member-tz time windowing (lib + tests)

Scope: add a Phase 1 lookup of the member's most recent `MemberDailyTime.timezone`. Implement `computeMemberTimeRange(window, memberTz)` helper. Update the Phase 2 time query to use the member-tz day range instead of the hardcoded UTC range. Fall back to UTC when no timezone row exists.

**Files:**
- Modify: `src/lib/member-card.ts` — Phase 1 `latestTzRow` query; `computeMemberTimeRange` helper; updated time query
- Modify: `src/tests/member-card.test.ts` — add `memberDailyTime.findFirst` mock; add tz-windowing tests

---

- [ ] **Step 1: Update db mock to include memberDailyTime.findFirst**

In `src/tests/member-card.test.ts`, update the `vi.mock("@/lib/db", ...)` block to add `findFirst` to `memberDailyTime`:

```ts
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
    memberDailyTime: { findMany: vi.fn(), findFirst: vi.fn() },
  },
}))
```

Add `mDailyTimeFindFirst` alongside the existing mock refs:

```ts
const mDailyTimeFindFirst = vi.mocked(db.memberDailyTime.findFirst)
```

Update `setupMocks` to always set `mDailyTimeFindFirst.mockResolvedValue(null)` (no tz row by default — UTC fallback):

```ts
function setupMocks({
  consentVersion,
  canSeeSpend,
  user = BASE_USER as typeof BASE_USER | null,
}: {
  consentVersion: 0 | 1 | 2
  canSeeSpend: boolean
  user?: typeof BASE_USER | null
}) {
  mUser.mockResolvedValue(user as never)
  mConsent.mockResolvedValue(consentVersion === 0 ? null : ({ consentVersion } as never))
  mDailyTimeFindFirst.mockResolvedValue(null)   // no tz row by default (UTC fallback)

  if (user !== null) {
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
```

- [ ] **Step 2: Write failing tz tests**

Add a describe block to `src/tests/member-card.test.ts`:

```ts
describe("time-at-keyboard windowing (member timezone)", () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it("Today: queries the member's local calendar date as UTC midnight (member ahead of UTC)", async () => {
    // Asia/Dubai = UTC+4. Server time: 2026-06-16T21:00:00Z = 2026-06-17T01:00 Dubai.
    // Member's local today = June 17 → row day = "2026-06-17T00:00:00Z"
    vi.useFakeTimers()
    vi.setSystemTime(new Date("2026-06-16T21:00:00Z"))

    mUser.mockResolvedValue(BASE_USER as never)
    mConsent.mockResolvedValue({ consentVersion: 2 } as never)
    mDailyTimeFindFirst.mockResolvedValue({ timezone: "Asia/Dubai" } as never)
    mAggregate.mockResolvedValueOnce(LAST_SEEN_AGG as never)
    mGroupBy.mockResolvedValue(GROUPED_PROJECTS as never)
    mProjectFindMany.mockResolvedValue(PROJECT_ROWS as never)
    mFindMany.mockResolvedValueOnce(SESSION_EVENTS as never).mockResolvedValueOnce(COMMIT_EVENTS as never)
    mDailyTime.mockResolvedValue(TIME_ROWS as never)

    await getMemberCard(TARGET_ID, ORG_ID, false, "today")

    const dayCallArgs = mDailyTime.mock.calls[0][0] as { where: { day?: unknown } }
    expect(dayCallArgs.where.day).toEqual({
      gte: new Date("2026-06-17T00:00:00Z"),  // member's local June 17 as UTC midnight
      lt:  new Date("2026-06-18T00:00:00Z"),
    })
  })

  it("Today: UTC fallback when no timezone row exists", async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date("2026-06-16T15:00:00Z"))

    mUser.mockResolvedValue(BASE_USER as never)
    mConsent.mockResolvedValue({ consentVersion: 2 } as never)
    mDailyTimeFindFirst.mockResolvedValue(null)   // no tz row
    mAggregate.mockResolvedValueOnce(LAST_SEEN_AGG as never)
    mGroupBy.mockResolvedValue(GROUPED_PROJECTS as never)
    mProjectFindMany.mockResolvedValue(PROJECT_ROWS as never)
    mFindMany.mockResolvedValueOnce(SESSION_EVENTS as never).mockResolvedValueOnce(COMMIT_EVENTS as never)
    mDailyTime.mockResolvedValue(TIME_ROWS as never)

    await getMemberCard(TARGET_ID, ORG_ID, false, "today")

    const dayCallArgs = mDailyTime.mock.calls[0][0] as { where: { day?: unknown } }
    expect(dayCallArgs.where.day).toEqual({
      gte: new Date("2026-06-16T00:00:00Z"),  // UTC midnight (fallback)
      lt:  new Date("2026-06-17T00:00:00Z"),
    })
  })

  it("7d: queries 7 consecutive member-local days ending at member's today", async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date("2026-06-16T12:00:00Z"))

    mUser.mockResolvedValue(BASE_USER as never)
    mConsent.mockResolvedValue({ consentVersion: 2 } as never)
    mDailyTimeFindFirst.mockResolvedValue({ timezone: "America/New_York" } as never)  // UTC-4 in EDT
    mAggregate.mockResolvedValueOnce(LAST_SEEN_AGG as never)
    mGroupBy.mockResolvedValue(GROUPED_PROJECTS as never)
    mProjectFindMany.mockResolvedValue(PROJECT_ROWS as never)
    mFindMany.mockResolvedValueOnce(SESSION_EVENTS as never).mockResolvedValueOnce(COMMIT_EVENTS as never)
    mDailyTime.mockResolvedValue(TIME_ROWS as never)

    await getMemberCard(TARGET_ID, ORG_ID, false, "7d")

    const dayCallArgs = mDailyTime.mock.calls[0][0] as { where: { day?: unknown } }
    expect(dayCallArgs.where.day).toEqual({
      gte: new Date("2026-06-10T00:00:00Z"),  // 2026-06-16 (NY) minus 6 days = June 10
      lte: new Date("2026-06-16T00:00:00Z"),  // member's today in NY = June 16
    })
  })

  it("time query always includes organisationId in where clause", async () => {
    setupMocks({ consentVersion: 2, canSeeSpend: false })
    await getMemberCard(TARGET_ID, ORG_ID, false)

    const dayCallArgs = mDailyTime.mock.calls[0][0] as { where: Record<string, unknown> }
    expect(dayCallArgs.where).toMatchObject({ organisationId: ORG_ID })
  })
})
```

- [ ] **Step 3: Run tests to verify they fail**

```
npm test -- --testPathPattern=member-card.test
```

Expected: FAIL — `db.memberDailyTime.findFirst` is not called yet and time query is still hardcoded.

- [ ] **Step 4: Implement — add computeMemberTimeRange helper**

At module level in `src/lib/member-card.ts` (before `getMemberCard`), add:

```ts
function computeMemberTimeRange(
  window: TimeWindow,
  memberTz: string | null | undefined,
): { gte: Date; lt: Date } | { gte: Date; lte: Date } {
  const tz = memberTz && memberTz.length > 0 ? memberTz : "UTC"
  const todayStr = new Intl.DateTimeFormat("en-CA", { timeZone: tz }).format(new Date())
  const todayUTC = new Date(todayStr + "T00:00:00Z")

  if (window === "today") {
    return {
      gte: todayUTC,
      lt:  new Date(todayUTC.getTime() + 24 * 60 * 60 * 1_000),
    }
  }

  const daysBack = window === "30d" ? 29 : 6  // 30d = today + 29 prior; 7d = today + 6 prior
  return {
    gte: new Date(todayUTC.getTime() - daysBack * 24 * 60 * 60 * 1_000),
    lte: todayUTC,
  }
}
```

- [ ] **Step 5: Implement — add latestTzRow to Phase 1**

In `getMemberCard`, update the Phase 1 `Promise.all` to include a third parallel query:

```ts
const [user, consent, latestTzRow] = await Promise.all([
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
  db.memberDailyTime.findFirst({
    where: { userId: targetId, organisationId },
    orderBy: { day: "desc" },
    select: { timezone: true },
  }),
])
```

- [ ] **Step 6: Implement — update time query to use member-tz range**

In Phase 2, replace the time `findMany` call. Change from the hardcoded `sevenDaysAgo` approach to:

```ts
consentVersion >= 2
  ? db.memberDailyTime.findMany({
      where: {
        userId: targetId,
        organisationId,
        day: computeMemberTimeRange(tw, latestTzRow?.timezone),
      },
      orderBy: { day: "asc" },
      select: {
        day: true,
        workedSeconds: true,
        productiveSeconds: true,
        unproductiveSeconds: true,
        topApps: true,
      },
    })
  : Promise.resolve([] as DailyTimeRow[]),
```

Also remove the now-unused `startOfToday`, `startOfTomorrow`, and `sevenDaysAgo` variables (they were only used for the old time query). The `now` variable was also only used for those — remove it too.

- [ ] **Step 7: Run tests to verify they pass**

```
npm test -- --testPathPattern=member-card.test
```

Expected: PASS.

- [ ] **Step 8: Type-check**

```
npx tsc --noEmit
```

Expected: 0 errors in `src/`.

- [ ] **Step 9: Commit**

```
git add src/lib/member-card.ts src/tests/member-card.test.ts
git commit -m "feat(member-card): window time-at-keyboard by member's local timezone"
```

---

## Task 4: Route — accept and validate window params (route + tests)

Scope: `GET /api/admin/members/:id/card` reads `?window` and `?todayCutoff` from the request URL. Validates: window must be one of `["today","7d","30d"]` (done inside `resolveTimeWindow`; invalid values default to "7d"). `todayCutoff` is parsed as a number; non-numeric or missing defaults to `undefined`. Passes both to `getMemberCard`. RBAC and tenancy gates are unchanged.

**Files:**
- Modify: `src/app/api/admin/members/[id]/card/route.ts`
- Modify: `src/tests/member-card-route.test.ts`

---

- [ ] **Step 1: Write failing tests**

In `src/tests/member-card-route.test.ts`, add:

1. Update the two existing `toHaveBeenCalledWith` assertions that check `getMemberCard` args. They currently assert `(id, orgId, flag)` with 3 args. After the route change they'll get `(id, orgId, flag, rawWindow, viewerTodayMs)`. Update them:

Find line 161: `expect(mCard).toHaveBeenCalledWith("some-user-id", "org-a", true)`
Replace with:
```ts
expect(mCard).toHaveBeenCalledWith("some-user-id", "org-a", true, undefined, undefined)
```

Find line 173: `expect(mCard).toHaveBeenCalledWith("user-from-org-b", "org-a", true)`
Replace with:
```ts
expect(mCard).toHaveBeenCalledWith("user-from-org-b", "org-a", true, undefined, undefined)
```

2. Add a new describe block for window param forwarding:

```ts
describe("window param forwarding", () => {
  it("passes window and todayCutoff from search params to getMemberCard", async () => {
    mAuth.mockResolvedValue(MANAGER_CTX)
    mCard.mockResolvedValue(CARD_WITH_SPEND as never)

    const req = new Request("http://localhost?window=today&todayCutoff=1750032000000")
    const res = await GET(req, params("user-1"))
    expect(res.status).toBe(200)
    expect(mCard).toHaveBeenCalledWith("user-1", "org-a", true, "today", 1750032000000)
  })

  it("passes undefined for both when query params are absent", async () => {
    mAuth.mockResolvedValue(MANAGER_CTX)
    mCard.mockResolvedValue(CARD_WITH_SPEND as never)

    const res = await GET(makeReq(), params("user-1"))
    expect(res.status).toBe(200)
    expect(mCard).toHaveBeenCalledWith("user-1", "org-a", true, undefined, undefined)
  })

  it("passes undefined viewerTodayMs when todayCutoff is non-numeric", async () => {
    mAuth.mockResolvedValue(MANAGER_CTX)
    mCard.mockResolvedValue(CARD_WITH_SPEND as never)

    const req = new Request("http://localhost?window=7d&todayCutoff=notanumber")
    const res = await GET(req, params("user-1"))
    expect(res.status).toBe(200)
    expect(mCard).toHaveBeenCalledWith("user-1", "org-a", true, "7d", undefined)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=member-card-route.test
```

Expected: FAIL — route currently calls `getMemberCard` without window params.

- [ ] **Step 3: Implement — update route**

Replace the body of `src/app/api/admin/members/[id]/card/route.ts` with:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { getMemberCard } from "@/lib/member-card"
import { checkMemberCardRateLimit } from "@/lib/ratelimit-member-card"

// GET /api/admin/members/:id/card
//
// Role gate — mirrors the pattern in src/app/api/admin/members/route.ts:18-21:
//   MANAGER    → any org member (org-scope enforced inside getMemberCard)
//   LINE_MANAGER → own-team members only (team check below, org-scoped lookup)
//   MEMBER     → blocked (403)
//
// canSeeSpend is computed from ctx.role on the server — never read from the request.
// Spend is visible to MANAGER and LINE_MANAGER (for own-team members). See ADR:
//   features/member-detail-card/adr-spend-visibility.md
//
// window and todayCutoff are time-narrowing only — they filter within the
// existing org+user+role gate and cannot widen access to other users or orgs.
export async function GET(
  request: Request,
  { params }: { params: { id: string } },
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const { allowed } = await checkMemberCardRateLimit(ctx.userId)
  if (!allowed) return NextResponse.json({ error: "Rate limit exceeded" }, { status: 429 })

  const targetId = params.id

  // LINE_MANAGER team gate — same org-scoped pattern as GET /api/admin/members.
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

  // Parse window params — invalid window defaults to "7d" inside resolveTimeWindow.
  // todayCutoff must be a positive finite number; otherwise pass undefined (UTC fallback in lib).
  const url = new URL(request.url)
  const rawWindow = url.searchParams.get("window") ?? undefined
  const rawCutoff = url.searchParams.get("todayCutoff")
  const viewerTodayMs =
    rawCutoff !== null && rawCutoff !== ""
      ? (Number.isFinite(Number(rawCutoff)) && Number(rawCutoff) > 0 ? Number(rawCutoff) : undefined)
      : undefined

  const canSeeSpend = ctx.role === "MANAGER" || ctx.role === "LINE_MANAGER"

  const card = await getMemberCard(targetId, ctx.organisationId, canSeeSpend, rawWindow, viewerTodayMs)
  if (!card) return NextResponse.json({ reason: "not_found" }, { status: 404 })

  return NextResponse.json(card)
}
```

- [ ] **Step 4: Run tests to verify they pass**

```
npm test -- --testPathPattern=member-card-route.test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/app/api/admin/members/[id]/card/route.ts src/tests/member-card-route.test.ts
git commit -m "feat(member-card): route accepts window and todayCutoff query params"
```

---

## Task 5: fetchMemberCard — window + todayCutoff opts (fetch util + tests)

Scope: `fetchMemberCard` gains optional `opts?: { window?: TimeWindow; todayCutoff?: number }`. When opts are present, appends `?window=...&todayCutoff=...` to the URL. Existing call sites that pass no opts continue to work unchanged (all-defaults).

**Files:**
- Modify: `src/app/admin/members/_components/member-card-fetch.ts`
- Modify: `src/tests/member-card-fetch.test.ts`

---

- [ ] **Step 1: Write failing tests**

In `src/tests/member-card-fetch.test.ts`, update the existing "returns ok:true" test to also assert the URL without opts (backwards compat), and add a new URL-construction test:

The existing test already asserts `expect(fetch).toHaveBeenCalledWith("/api/admin/members/u-1/card")` — this must remain passing.

Add after the existing tests:

```ts
it("appends window and todayCutoff to URL when opts provided", async () => {
  vi.mocked(fetch).mockResolvedValue(makeRes(200, { identity: { id: "u-1", name: "Alice" } }))
  await fetchMemberCard("u-1", { window: "today", todayCutoff: 1750032000000 })
  expect(fetch).toHaveBeenCalledWith(
    "/api/admin/members/u-1/card?window=today&todayCutoff=1750032000000"
  )
})

it("appends only window when todayCutoff not provided", async () => {
  vi.mocked(fetch).mockResolvedValue(makeRes(200, { identity: { id: "u-1" } }))
  await fetchMemberCard("u-1", { window: "7d" })
  expect(fetch).toHaveBeenCalledWith("/api/admin/members/u-1/card?window=7d")
})

it("calls base URL with no query string when opts omitted", async () => {
  vi.mocked(fetch).mockResolvedValue(makeRes(200, { identity: { id: "u-1" } }))
  await fetchMemberCard("u-1")
  expect(fetch).toHaveBeenCalledWith("/api/admin/members/u-1/card")
})
```

- [ ] **Step 2: Run tests to verify new ones fail**

```
npm test -- --testPathPattern=member-card-fetch.test
```

Expected: new tests FAIL — opts not yet accepted.

- [ ] **Step 3: Implement**

Replace `src/app/admin/members/_components/member-card-fetch.ts` with:

```ts
import type { MemberCardData } from "@/lib/member-card"
import type { TimeWindow } from "@/lib/project-time-window"

export type MemberCardResult =
  | { ok: true; data: MemberCardData }
  | { ok: false; status: number }

export type FetchMemberCardOpts = {
  window?: TimeWindow
  todayCutoff?: number
}

export async function fetchMemberCard(
  memberId: string,
  opts?: FetchMemberCardOpts,
): Promise<MemberCardResult> {
  const params = new URLSearchParams()
  if (opts?.window) params.set("window", opts.window)
  if (opts?.todayCutoff !== undefined) params.set("todayCutoff", String(opts.todayCutoff))

  const query = params.toString()
  const url = `/api/admin/members/${memberId}/card${query ? `?${query}` : ""}`

  const res = await fetch(url)
  if (res.ok) return { ok: true, data: (await res.json()) as MemberCardData }
  return { ok: false, status: res.status }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```
npm test -- --testPathPattern=member-card-fetch.test
```

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add src/app/admin/members/_components/member-card-fetch.ts src/tests/member-card-fetch.test.ts
git commit -m "feat(member-card): fetchMemberCard accepts window and todayCutoff opts"
```

---

## Task 6: MemberDetailCard UI — window toggle + sessions render + time note (component)

Scope: (1) `WindowControl` three-button toggle rendered below the identity meta row. (2) `viewerTodayCutoff` injected on mount via `new Date().setHours(0,0,0,0)`. (3) `cardState` refetches when `window` or `memberId` changes. (4) `SessionsSection` renders `s.feedSummary ?? firstSentence(s.sessionExcerpt) ?? null`; falls back to "—" when both null. (5) `TimeSection` shows `SectionLabel note="member's local time"` when v2 data is rendered.

**Files:**
- Modify: `src/app/admin/members/_components/MemberDetailCard.tsx`

No new server tests — this is client UI. Verified via `/visual-test` or manual browser check after this task.

---

- [ ] **Step 1: Add imports and TimeWindow type**

At the top of `MemberDetailCard.tsx`, the existing imports are:
```ts
import { useState, useEffect } from "react"
import { createPortal } from "react-dom"
import { fetchMemberCard } from "./member-card-fetch"
import type { MemberCardData, TimeDataV2, TopApps } from "@/lib/member-card"
```

Add:
```ts
import type { TimeWindow } from "@/lib/project-time-window"
import type { FetchMemberCardOpts } from "./member-card-fetch"
```

- [ ] **Step 2: Add firstSentence helper (inlined — do not import from gemini.ts)**

After the existing `fmtSec` function (line 53), add:

```ts
function firstSentence(text: string): string {
  const cut = text.search(/[.!\n]/)
  const raw = cut > 0 ? text.slice(0, cut) : text
  return raw.slice(0, 80).replace(/\s+\S*$/, "").trim()
}
```

- [ ] **Step 3: Add WindowControl component**

After the `Empty` component definition, add:

```ts
function WindowControl({
  active,
  onChange,
}: {
  active: TimeWindow
  onChange: (w: TimeWindow) => void
}) {
  const WINDOWS: TimeWindow[] = ["today", "7d", "30d"]
  const LABELS: Record<TimeWindow, string> = { today: "Today", "7d": "7d", "30d": "30d" }

  return (
    <div style={{
      display: "inline-flex",
      gap: "0",
      borderRadius: "7px",
      overflow: "hidden",
      border: "1px solid var(--border-mid)",
      background: "var(--background-chip)",
    }}>
      {WINDOWS.map((w) => (
        <button
          key={w}
          onClick={() => onChange(w)}
          style={{
            padding: "4px 12px",
            fontSize: "11px",
            fontFamily: "var(--font-mono)",
            fontWeight: 600,
            letterSpacing: "0.05em",
            cursor: "pointer",
            border: "none",
            borderRight: w !== "30d" ? "1px solid var(--border-mid)" : "none",
            background: active === w ? "rgba(14,226,158,0.15)" : "transparent",
            color: active === w ? "#0ee29e" : "var(--text-muted)",
            transition: "background 120ms ease-out, color 120ms ease-out",
          }}
        >
          {LABELS[w]}
        </button>
      ))}
    </div>
  )
}
```

- [ ] **Step 4: Update SessionsSection to render feedSummary**

Replace the current `SessionsSection` body. Change the excerpt `<p>` block (currently rendering `s.sessionExcerpt`) to:

```tsx
function SessionsSection({ sessions }: { sessions: MemberCardData["recentSessions"] }) {
  return (
    <section style={{ marginBottom: "22px" }}>
      <SectionLabel title="Recent Sessions" />
      {sessions.length === 0 ? (
        <Empty text="No sessions" />
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: "6px" }}>
          {sessions.map((s) => {
            const displayText =
              s.feedSummary ??
              (s.sessionExcerpt ? firstSentence(s.sessionExcerpt) || null : null)

            return (
              <div key={s.id} style={{
                padding: "10px 12px",
                background: "var(--surface-subtle, rgba(255,255,255,0.02))",
                border: "1px solid var(--border)",
                borderRadius: "8px",
              }}>
                <p style={{
                  margin: "0 0 6px",
                  fontSize: "11.5px",
                  color: displayText ? "var(--text-secondary)" : "var(--text-hint, #4b5563)",
                  fontFamily: "var(--font-sans)",
                  lineHeight: 1.55,
                  fontStyle: displayText ? "normal" : "italic",
                }}>
                  {displayText ?? "—"}
                </p>
                <div style={{ display: "flex", gap: "12px", alignItems: "center", flexWrap: "wrap" }}>
                  <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>{s.projectName}</span>
                  <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>{fmtDuration(s.sessionDurationSeconds)}</span>
                  <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)", marginLeft: "auto" }}>{fmtRelative(s.date)}</span>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </section>
  )
}
```

- [ ] **Step 5: Update TimeSection to add "member's local time" note**

In `TimeSection`, update the `SectionLabel` line that currently renders without a note (when v2 and week.length > 0):

Find the `SectionLabel` inside the `return (...)` block of `TimeSection` (around line 179):
```tsx
<SectionLabel title="Time at Keyboard" />
```

Change it to:
```tsx
<SectionLabel title="Time at Keyboard" note="member's local time" />
```

(Apply only inside the v2 branch that renders data — not in the `consentVersion !== 2` branch or the `week.length === 0` branch.)

- [ ] **Step 6: Update MemberDetailCard main component state and fetch**

In the `MemberDetailCard` function (currently starts around line 539), add:

1. Window state:
```ts
const [activeWindow, setActiveWindow] = useState<TimeWindow>("7d")
const [viewerTodayCutoff, setViewerTodayCutoff] = useState<number | undefined>(undefined)
```

2. Inject viewer local midnight on mount (add alongside `setMounted`):
```ts
useEffect(() => {
  setMounted(true)
  setViewerTodayCutoff(new Date().setHours(0, 0, 0, 0))
}, [])
```

3. Update the fetch `useEffect` to depend on `activeWindow` + `viewerTodayCutoff` and pass opts:

Replace the current fetch `useEffect`:
```ts
useEffect(() => {
  if (!memberId) { setCardState({ kind: "idle" }); return }
  setCardState({ kind: "loading" })
  const opts: FetchMemberCardOpts = { window: activeWindow }
  if (viewerTodayCutoff !== undefined) opts.todayCutoff = viewerTodayCutoff
  fetchMemberCard(memberId, opts).then((result) => {
    if (result.ok) {
      setCardState({ kind: "loaded", data: result.data })
    } else {
      setCardState({ kind: "error", status: result.status })
    }
  }).catch(() => { setCardState({ kind: "error", status: 0 }) })
}, [memberId, activeWindow, viewerTodayCutoff])
```

- [ ] **Step 7: Render WindowControl in the header**

In the `CardLoaded` component, in the header section (currently ends with the meta row `</div>` at line ~449), add the `WindowControl` below the meta row:

```tsx
{/* Window toggle */}
<div style={{ marginTop: "12px" }}>
  <WindowControl active={activeWindow} onChange={setActiveWindow} />
</div>
```

But `activeWindow` and `setActiveWindow` live in `MemberDetailCard`, not in `CardLoaded`. Pass them down:

Change `CardLoaded` signature:
```ts
function CardLoaded({
  data,
  onClose,
  activeWindow,
  onWindowChange,
}: {
  data: MemberCardData
  onClose: () => void
  activeWindow: TimeWindow
  onWindowChange: (w: TimeWindow) => void
})
```

Update the call site in the `MemberDetailCard` portal:
```tsx
{cardState.kind === "loaded" && (
  <CardLoaded
    data={cardState.data}
    onClose={onClose}
    activeWindow={activeWindow}
    onWindowChange={setActiveWindow}
  />
)}
```

Add the `WindowControl` in `CardLoaded`'s header, after the meta row closing `</div>`:
```tsx
{/* Window toggle — sits below the identity meta row */}
<div style={{ marginTop: "12px" }}>
  <WindowControl active={activeWindow} onChange={onWindowChange} />
</div>
```

- [ ] **Step 8: Run full test suite**

```
npm test
```

Expected: all tests pass.

- [ ] **Step 9: Type-check**

```
npx tsc --noEmit
```

Expected: 0 errors in `src/`.

- [ ] **Step 10: Commit**

```
git add src/app/admin/members/_components/MemberDetailCard.tsx src/app/admin/members/_components/member-card-fetch.ts
git commit -m "feat(member-card): add Today/7d/30d window toggle and feedSummary session display"
```

---

## Task 7: Docs update

Scope: update the seven mandatory docs for this phase. Changes affect: API route (windowed), lib module (`getMemberCard` signature), ADR for dual-tz windowing, risk.

---

- [ ] **Step 1: Update `docs/code.md`**

Find the `getMemberCard` entry and update its signature to include `rawWindow?: string` and `viewerTodayMs?: number` params.

- [ ] **Step 2: Update `docs/architecture.md`**

In the API routes section for `GET /api/admin/members/:id/card`, add `?window=today|7d|30d` and `?todayCutoff=<epochMs>` to the documented query params.

- [ ] **Step 3: Add ADR to `docs/decisions.md`**

Add entry: **ADR-MemberCard-DualTzWindowing** — member detail card uses two timezone systems: time-at-keyboard uses the member's own IANA timezone (from `MemberDailyTime.timezone`), event-based sections use the viewer's timezone via `resolveTimeWindow`. Rationale: a "day" of keyboard time is the member's local calendar day; event feeds are viewed from the viewer's perspective.

- [ ] **Step 4: Update `docs/glossary.md`**

Add entry: **member-tz window** — the time-at-keyboard window boundary computed from the member's own IANA timezone (stored per-row in `MemberDailyTime.timezone`). Contrast with **viewer-tz window** used for event-based sections.

- [ ] **Step 5: Run full test suite one final time**

```
npm test
```

Expected: PASS.

- [ ] **Step 6: Commit docs**

```
git add docs/
git commit -m "docs(member-card): ADR + API docs for phase-03 dual-tz windowing"
```

---

## Self-Review: Spec Coverage Check

| Requirement | Task |
|---|---|
| Today/7d/30d window control UI | Task 6 |
| Time-at-keyboard windows by MEMBER's timezone | Task 3 |
| Event sections window by VIEWER's timezone | Task 2 |
| `lastSeenAt` stays all-time | Task 2 (lastSeenAgg has no `since`) |
| feedSummary in sessions query | Task 1 |
| feedSummary ?? firstSentence fallback | Task 1 (server-side) + Task 6 (client render) |
| "member's local time" UI note | Task 6 (TimeSection note) |
| Default window = 7d | Task 2 (`resolveTimeWindow(undefined)` returns 7d) |
| Window cannot widen org/team/role gate | Confirmed: `since` is additive filter; org+userId always present |
| canSeeSpend unchanged by window | Task 2 (spendAgg adds `since` but `canSeeSpend` gate is unaffected) |
| Viewer todayCutoff injected browser-side | Task 6 (`new Date().setHours(0,0,0,0)` on mount) |
| Route validates window param | Task 4 (invalid window → 7d default via `resolveTimeWindow`) |
| Route validates todayCutoff param | Task 4 (non-numeric → undefined; lib falls back to UTC) |
| Proving target: real browser test | Task 6 step 8 + visual-test |
