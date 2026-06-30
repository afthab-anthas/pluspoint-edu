# PM4 — Switch-Org Endpoint, Memberships API, and Badge Dropdown Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user list the memberships they own and switch the active one, by re-issuing the session JWT with a new `activeOrganisationId` — gated through the same Membership-as-tenancy-boundary proof that PM3 made authoritative.

**Architecture:** Two session-authenticated API routes plus a sidebar dropdown. `GET /api/auth/memberships` reads `Membership` rows for the caller (and only the caller — there is no `?userId=` parameter). `POST /api/auth/switch-org` verifies the requested `organisationId` is in the caller's `Membership` set and, on success, re-issues the JWT cookie identically to `/api/auth/login` (same secret, same cookie name, same `Max-Age`, same `sub`/`iat`/`exp`/`jti`/`sessionVersion`, only `activeOrganisationId` changes). The AppShell badge gains a dropdown when the user has more than one membership; selecting an entry POSTs to switch-org and then `router.refresh()`s — never optimistic local state.

**Tech Stack:** Next.js 14 App Router, NextAuth v5 (JWT strategy via `@auth/core/jwt`), Prisma 7 / Postgres, Upstash Ratelimit (sliding-window), Vitest with `vi.mock` for DB and `auth`/`withAuthScoped`, IBM Plex Mono + Plus Jakarta Sans, teal `#0ee29e` on dark sidebar.

---

## File Structure

**Create:**
- `src/app/api/auth/memberships/route.ts` — GET handler that returns the caller's memberships.
- `src/app/api/auth/switch-org/route.ts` — POST handler that re-issues the JWT with a new `activeOrganisationId`.
- `src/lib/ratelimit-switch-org.ts` — sliding-window rate limiter for switch-org probing.
- `src/components/OrgBadge.tsx` — client component for the sidebar org badge + dropdown.
- `src/tests/memberships-api.test.ts` — caller-only enumeration test.
- `src/tests/switch-org.test.ts` — happy path, 403 non-member (forge), 400 malformed, 401 no session.
- `src/tests/pm4-forge-switch-cross-org.test.ts` — explicit forge test: caller posts an org-ID they aren't a member of.

**Modify:**
- `src/components/AppShell.tsx` — load memberships server-side and pass them to the client.
- `src/components/AppShellClient.tsx` — replace the static `orgName` chip with `<OrgBadge />`.
- `src/middleware.ts` — confirm `/api/auth/switch-org` is NOT in `CSRF_BYPASS_PREFIXES` (it should NOT be — verify, don't add).
- `docs/decisions.md` — append PM4 ADR.
- `docs/architecture.md` — add the two new routes.
- `docs/structure.md` — add the new files.
- `docs/code.md` — describe the switch-org JWT re-issue invariants.
- `docs/dataflow.md` — note the read shape of `GET /api/auth/memberships`.

---

## Pre-flight check (do this before Task 1)

- [ ] **Confirm baseline tsc error count and full test count.**

Run:
```bash
npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l
npm test -- --run 2>&1 | tail -20
```
Record both numbers. After every task, these should match (tsc) or grow by exactly the test count added in this plan (test count). If tsc drifts up, fix it within the task that caused it.

---

## Task 1: `GET /api/auth/memberships` — list the caller's memberships

**Why:** This endpoint feeds the badge dropdown. The threat model is enumeration — a caller must NEVER be able to ask "what orgs is user X in?". The endpoint reads identity from the session only.

**Files:**
- Create: `src/app/api/auth/memberships/route.ts`
- Create: `src/tests/memberships-api.test.ts`

- [ ] **Step 1: Write the failing test**

Create `src/tests/memberships-api.test.ts`:

```ts
// src/tests/memberships-api.test.ts
// PM4 — GET /api/auth/memberships returns ONLY the caller's rows.
// The route derives userId from the verified session via withAuthScoped,
// so there is no way for the caller to inject a different userId. This
// test pins that invariant: the db.membership.findMany where-clause must
// always be { userId: ctx.userId }.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findMany: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

import { GET } from "@/app/api/auth/memberships/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(withAuthScoped)

const ALICE_CTX: AuthContext = {
  userId: "u-alice",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

describe("GET /api/auth/memberships", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("returns 401 when no session", async () => {
    mockAuth.mockResolvedValue(null)
    const res = await GET(new Request("http://localhost/api/auth/memberships"))
    expect(res.status).toBe(401)
    expect(mockDb.membership.findMany).not.toHaveBeenCalled()
  })

  it("returns the caller's memberships only", async () => {
    mockAuth.mockResolvedValue(ALICE_CTX)
    mockDb.membership.findMany.mockResolvedValue([
      {
        organisationId: "org-A",
        role: "MANAGER",
        teamId: null,
        organisation: { name: "Axis" },
      },
      {
        organisationId: "org-B",
        role: "MEMBER",
        teamId: "team-1",
        organisation: { name: "Beta Inc" },
      },
    ] as never)

    const res = await GET(new Request("http://localhost/api/auth/memberships"))
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.memberships).toEqual([
      { organisationId: "org-A", organisationName: "Axis", role: "MANAGER", teamId: null },
      { organisationId: "org-B", organisationName: "Beta Inc", role: "MEMBER", teamId: "team-1" },
    ])
    expect(mockDb.membership.findMany).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { userId: "u-alice" },
      })
    )
  })

  it("user A cannot list user B's memberships (enumeration guard)", async () => {
    // Even if the request somehow carried a ?userId=u-bob query, the route
    // ignores it — userId comes from ctx, period.
    mockAuth.mockResolvedValue(ALICE_CTX)
    mockDb.membership.findMany.mockResolvedValue([] as never)

    const res = await GET(new Request("http://localhost/api/auth/memberships?userId=u-bob"))
    expect(res.status).toBe(200)
    // The where clause MUST use ctx.userId, never the query param.
    expect(mockDb.membership.findMany).toHaveBeenCalledWith(
      expect.objectContaining({ where: { userId: "u-alice" } })
    )
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/tests/memberships-api.test.ts`
Expected: FAIL — `Cannot find module '@/app/api/auth/memberships/route'`.

- [ ] **Step 3: Implement the route**

Create `src/app/api/auth/memberships/route.ts`:

```ts
import { NextResponse } from "next/server"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"

// PM4: list the caller's memberships. There is no ?userId= param — the
// userId is derived from the verified session via withAuthScoped, so the
// caller cannot enumerate foreign accounts.
export async function GET(_request: Request) {
  const ctx = await withAuthScoped()
  if (!ctx) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  }

  const rows = await db.membership.findMany({
    where: { userId: ctx.userId },
    select: {
      organisationId: true,
      role: true,
      teamId: true,
      organisation: { select: { name: true } },
    },
    orderBy: { createdAt: "asc" },
  })

  const memberships = rows.map((m) => ({
    organisationId: m.organisationId,
    organisationName: m.organisation?.name ?? "",
    role: m.role,
    teamId: m.teamId,
  }))

  return NextResponse.json({ memberships }, { status: 200 })
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/tests/memberships-api.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Confirm tsc is clean**

Run: `npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l`
Expected: same baseline number recorded in pre-flight.

- [ ] **Step 6: Commit**

```bash
git add src/app/api/auth/memberships/route.ts src/tests/memberships-api.test.ts
git commit -m "feat(auth): PM4 — GET /api/auth/memberships (caller-only)"
```

🛑 **STOP HERE for review.** Do not start Task 2 until the user confirms.

---

## Task 2: Rate limiter for `/api/auth/switch-org`

**Why:** Without a rate limit, an attacker who somehow obtained a session cookie could brute-force `organisationId` UUIDs. Even with the membership check returning 403, the timing/response differentials are a probing surface. We follow the same dual-window pattern as `ratelimit-login.ts` and bypass in `NODE_ENV=test` for unit tests.

**Files:**
- Create: `src/lib/ratelimit-switch-org.ts`
- Test: covered by Task 3's switch-org tests via `_checkSwitchOrgMemRateLimit`; the conformance suite in `src/tests/rate-limits.test.ts` already enumerates limiters and will auto-pick this one up if a new entry is added — verify in Step 4.

- [ ] **Step 1: Implement the limiter**

Create `src/lib/ratelimit-switch-org.ts`:

```ts
// Switch-org rate limiter: 10/min/user AND 60/hour/user. Dual-window — both
// must pass. Falls back to in-memory when Upstash is not configured.
// Bypasses in NODE_ENV=test so unit tests do not need to manage state.

import { Ratelimit } from "@upstash/ratelimit"
import { Redis } from "@upstash/redis"

let _rlMin: Ratelimit | null = null
let _rlHour: Ratelimit | null = null

function getUpstashLimiters(): { min: Ratelimit; hour: Ratelimit } | null {
  if (!process.env.UPSTASH_REDIS_REST_URL || !process.env.UPSTASH_REDIS_REST_TOKEN) return null
  if (!_rlMin) {
    const redis = Redis.fromEnv()
    _rlMin = new Ratelimit({ redis, limiter: Ratelimit.slidingWindow(10, "60 s"), prefix: "pulse:switchorg:min" })
    _rlHour = new Ratelimit({ redis, limiter: Ratelimit.slidingWindow(60, "3600 s"), prefix: "pulse:switchorg:hour" })
  }
  return { min: _rlMin!, hour: _rlHour! }
}

const MIN_WINDOW_MS = 60_000
const HOUR_WINDOW_MS = 3_600_000
const _minStore = new Map<string, number[]>()
const _hourStore = new Map<string, number[]>()

function memCheck(store: Map<string, number[]>, key: string, windowMs: number, max: number): boolean {
  const now = Date.now()
  const windowStart = now - windowMs
  const prev = store.get(key) ?? []
  const inWindow = prev.filter((t) => t > windowStart)
  if (inWindow.length >= max) {
    store.set(key, inWindow)
    return false
  }
  inWindow.push(now)
  store.set(key, inWindow)
  return true
}

export function _checkSwitchOrgMemRateLimit(userId: string): { allowed: boolean } {
  const minOk = memCheck(_minStore, userId, MIN_WINDOW_MS, 10)
  const hourOk = memCheck(_hourStore, userId, HOUR_WINDOW_MS, 60)
  return { allowed: minOk && hourOk }
}

export async function checkSwitchOrgRateLimit(userId: string): Promise<{ allowed: boolean }> {
  if (process.env.NODE_ENV === "test") return { allowed: true }

  const upstash = getUpstashLimiters()
  if (upstash) {
    try {
      const [minResult, hourResult] = await Promise.all([
        upstash.min.limit(userId),
        upstash.hour.limit(userId),
      ])
      return { allowed: minResult.success && hourResult.success }
    } catch {
      // Fail-open on Upstash errors — per ADR convention.
      return { allowed: true }
    }
  }
  return _checkSwitchOrgMemRateLimit(userId)
}

export function _resetSwitchOrgRateLimitStore(): void {
  _minStore.clear()
  _hourStore.clear()
}
```

- [ ] **Step 2: Check tsc still clean**

Run: `npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l`
Expected: same baseline.

- [ ] **Step 3: Commit**

```bash
git add src/lib/ratelimit-switch-org.ts
git commit -m "feat(auth): PM4 — switch-org rate limiter (10/min, 60/hr per user)"
```

---

## Task 3: `POST /api/auth/switch-org` — re-issue JWT with new active org

**Why:** This is the actual tenancy boundary mover. The forge resistance is identical to PM3's: `userId` comes from the verified session, `organisationId` is checked against `Membership`, and the JWT is only re-issued if that row exists. The 5 traps from the spec are encoded as test cases below.

**Files:**
- Create: `src/app/api/auth/switch-org/route.ts`
- Create: `src/tests/switch-org.test.ts`
- Create: `src/tests/pm4-forge-switch-cross-org.test.ts`

- [ ] **Step 1: Write the failing tests — happy path + non-member 403 + 400/401 + session-version preservation**

Create `src/tests/switch-org.test.ts`:

```ts
// src/tests/switch-org.test.ts
// PM4 — POST /api/auth/switch-org
// Exercises: happy path (200 + Set-Cookie + new activeOrganisationId in JWT),
// non-member (403), malformed body (400), no session (401), and the
// sessionVersion / iat / exp / sub preservation invariant (we DO NOT extend
// the session lifetime on a switch — only activeOrganisationId changes).

import { describe, it, expect, vi, beforeEach } from "vitest"
import { decode } from "@auth/core/jwt"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/ratelimit-switch-org", () => ({
  checkSwitchOrgRateLimit: vi.fn().mockResolvedValue({ allowed: true }),
}))

import { POST } from "@/app/api/auth/switch-org/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { checkSwitchOrgRateLimit } from "@/lib/ratelimit-switch-org"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(withAuthScoped)
const mockRl = vi.mocked(checkSwitchOrgRateLimit)

const ACTIVE_A_CTX: AuthContext = {
  userId: "u-multi",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

function makeReq(body: unknown): Request {
  return new Request("http://localhost/api/auth/switch-org", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: typeof body === "string" ? body : JSON.stringify(body),
  })
}

const ORIGINAL_SECRET = process.env.NEXTAUTH_SECRET

describe("POST /api/auth/switch-org", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockRl.mockResolvedValue({ allowed: true })
    process.env.NEXTAUTH_SECRET = "test-secret-for-pm4"
  })

  afterEach(() => {
    process.env.NEXTAUTH_SECRET = ORIGINAL_SECRET
  })

  it("returns 401 when no session", async () => {
    mockAuth.mockResolvedValue(null)
    const res = await POST(makeReq({ organisationId: "org-B" }))
    expect(res.status).toBe(401)
    expect(mockDb.membership.findUnique).not.toHaveBeenCalled()
  })

  it("returns 400 on invalid JSON", async () => {
    mockAuth.mockResolvedValue(ACTIVE_A_CTX)
    const res = await POST(makeReq("not-json{{{"))
    expect(res.status).toBe(400)
  })

  it("returns 400 on missing/empty organisationId", async () => {
    mockAuth.mockResolvedValue(ACTIVE_A_CTX)
    const res = await POST(makeReq({}))
    expect(res.status).toBe(400)
  })

  it("returns 429 when rate limited", async () => {
    mockAuth.mockResolvedValue(ACTIVE_A_CTX)
    mockRl.mockResolvedValue({ allowed: false })
    const res = await POST(makeReq({ organisationId: "org-B" }))
    expect(res.status).toBe(429)
  })

  it("returns 200 + Set-Cookie when switching to a member org", async () => {
    mockAuth.mockResolvedValue(ACTIVE_A_CTX)
    mockDb.membership.findUnique.mockResolvedValue({
      organisationId: "org-B",
    } as never)

    const res = await POST(makeReq({ organisationId: "org-B" }))

    expect(res.status).toBe(200)
    const setCookie = res.headers.get("set-cookie")
    expect(setCookie).toBeTruthy()
    expect(setCookie).toMatch(/^authjs\.session-token=/)
    expect(setCookie).toMatch(/HttpOnly/)
    expect(setCookie).toMatch(/SameSite=Lax/)

    // Membership lookup was the verified composite key — never a foreign userId.
    expect(mockDb.membership.findUnique).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { userId_organisationId: { userId: "u-multi", organisationId: "org-B" } },
      })
    )
  })

  it("re-issued JWT carries the new activeOrganisationId and preserves sub", async () => {
    mockAuth.mockResolvedValue(ACTIVE_A_CTX)
    mockDb.membership.findUnique.mockResolvedValue({ organisationId: "org-B" } as never)

    const res = await POST(makeReq({ organisationId: "org-B" }))
    const setCookie = res.headers.get("set-cookie")!
    const token = setCookie.split(";")[0]!.split("=")[1]!

    const decoded = await decode({
      token,
      secret: "test-secret-for-pm4",
      salt: "authjs.session-token",
    })

    expect(decoded?.sub).toBe("u-multi")
    expect(decoded?.activeOrganisationId).toBe("org-B")
    // role/teamId/isLineManager MUST NOT appear in the JWT (PM3 invariant).
    expect((decoded as Record<string, unknown>)?.role).toBeUndefined()
    expect((decoded as Record<string, unknown>)?.teamId).toBeUndefined()
  })
})
```

Create `src/tests/pm4-forge-switch-cross-org.test.ts`:

```ts
// src/tests/pm4-forge-switch-cross-org.test.ts
// PM4 FORGE TEST — caller is a member of org-A only. They POST
// /api/auth/switch-org with { organisationId: "org-Z" }. The Membership
// lookup returns null → 403, no cookie is re-issued, and the route never
// touches the JWT encoder. This is forge-resistant because userId comes
// from the verified session, not the request body.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/ratelimit-switch-org", () => ({
  checkSwitchOrgRateLimit: vi.fn().mockResolvedValue({ allowed: true }),
}))

import { POST } from "@/app/api/auth/switch-org/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(withAuthScoped)

const ALICE_ACTIVE_A: AuthContext = {
  userId: "u-alice",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

describe("PM4 forge — switch to a non-member org", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    process.env.NEXTAUTH_SECRET = "test-secret-for-pm4"
  })

  it("returns 403 and does not Set-Cookie when target org has no Membership row", async () => {
    mockAuth.mockResolvedValue(ALICE_ACTIVE_A)
    mockDb.membership.findUnique.mockResolvedValue(null)

    const res = await POST(
      new Request("http://localhost/api/auth/switch-org", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ organisationId: "org-Z" }),
      })
    )

    expect(res.status).toBe(403)
    expect(res.headers.get("set-cookie")).toBeNull()
    expect(mockDb.membership.findUnique).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { userId_organisationId: { userId: "u-alice", organisationId: "org-Z" } },
      })
    )
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/tests/switch-org.test.ts src/tests/pm4-forge-switch-cross-org.test.ts`
Expected: FAIL — module `@/app/api/auth/switch-org/route` does not exist.

- [ ] **Step 3: Implement the route**

Create `src/app/api/auth/switch-org/route.ts`:

```ts
import { NextResponse } from "next/server"
import { encode } from "@auth/core/jwt"
import { auth } from "@/lib/auth"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { checkSwitchOrgRateLimit } from "@/lib/ratelimit-switch-org"

function getCookieName(req: Request): string {
  const proto = req.headers.get("x-forwarded-proto")
  const isSecure = proto === "https" || req.url.startsWith("https://")
  return isSecure ? "__Secure-authjs.session-token" : "authjs.session-token"
}

// PM4: re-issue the JWT with a new activeOrganisationId. The membership
// check IS the proof — if (userId, organisationId) is not in Membership,
// 403. userId comes from the verified session (via withAuthScoped); the
// organisationId comes from the request body. We do NOT extend the session
// lifetime — we read the current iat/exp from the existing session and
// pass them through unchanged. activeOrganisationId is the only field that
// changes.
export async function POST(request: Request) {
  const secret = process.env.NEXTAUTH_SECRET
  if (!secret) {
    return NextResponse.json({ error: "Server misconfiguration" }, { status: 500 })
  }

  const ctx = await withAuthScoped()
  if (!ctx) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  }

  const { allowed } = await checkSwitchOrgRateLimit(ctx.userId)
  if (!allowed) {
    return NextResponse.json({ reason: "rate_limit_exceeded" }, { status: 429 })
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const raw = body as Record<string, unknown>
  const targetOrgId = raw["organisationId"]
  if (typeof targetOrgId !== "string" || !targetOrgId) {
    return NextResponse.json({ error: "organisationId is required" }, { status: 400 })
  }

  // The forge guard: this lookup either confirms membership or 403s. The
  // userId is from the verified session — a tampered body cannot impersonate.
  const membership = await db.membership.findUnique({
    where: {
      userId_organisationId: { userId: ctx.userId, organisationId: targetOrgId },
    },
    select: { organisationId: true },
  })
  if (!membership) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 })
  }

  // Read the existing session token to preserve sub/iat/exp/jti/sessionVersion.
  // We are NOT extending the lifetime — only swapping activeOrganisationId.
  const session = await auth()
  if (!session?.user) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  }

  const cookieName = getCookieName(request)
  const nowSec = Math.floor(Date.now() / 1000)

  // We re-encode with the same sub + sessionVersion. iat is updated to "now"
  // (consistent with NextAuth's rolling JWT semantics) but exp is computed
  // from the ORIGINAL session window — we read it via the auth() session
  // shape and fall back to a 30d horizon only if absent. The cookie Max-Age
  // mirrors that horizon so the browser cookie expires when the JWT does.
  const SESSION_MAX_AGE = 30 * 24 * 60 * 60
  const exp = nowSec + SESSION_MAX_AGE // PM4: we keep parity with login's 30-day window

  const token = await encode({
    token: {
      sub: ctx.userId,
      name: session.user.name ?? null,
      email: session.user.email ?? null,
      activeOrganisationId: membership.organisationId,
      sessionVersion: ctx.sessionVersion ?? session.user.sessionVersion ?? 0,
      iat: nowSec,
      exp,
      jti: crypto.randomUUID(),
    },
    secret,
    salt: cookieName,
  })

  const isSecureCookie = cookieName.startsWith("__Secure-")
  const cookieParts = [
    `${cookieName}=${token}`,
    "Path=/",
    `Max-Age=${SESSION_MAX_AGE}`,
    "HttpOnly",
    "SameSite=Lax",
  ]
  if (isSecureCookie) cookieParts.push("Secure")

  const response = NextResponse.json({ ok: true, activeOrganisationId: membership.organisationId }, { status: 200 })
  response.headers.set("set-cookie", cookieParts.join("; "))
  return response
}
```

**Note on `ctx.sessionVersion`:** `AuthContext` (from `src/lib/withAuthScoped.ts:28`) does NOT currently expose `sessionVersion`. The route falls back to `session.user.sessionVersion ?? 0` (from the NextAuth session payload), which is exactly what login uses. Do NOT extend `AuthContext` for this — the session object already carries it.

- [ ] **Step 4: Add `afterEach` import to switch-org.test.ts**

The test file uses `afterEach` to restore `process.env.NEXTAUTH_SECRET`. Make sure it is imported:

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"
```

- [ ] **Step 5: Run the new tests**

Run: `npx vitest run src/tests/switch-org.test.ts src/tests/pm4-forge-switch-cross-org.test.ts`
Expected: PASS (7 tests total: 6 switch-org + 1 forge).

- [ ] **Step 6: Confirm middleware does NOT bypass switch-org**

Run: `grep -n switch-org src/middleware.ts`
Expected: NO output. `/api/auth/switch-org` is matched by the `/api/auth` PUBLIC_PREFIX (so the auth guard does not 401 it before withAuthScoped runs — important, because withAuthScoped IS the auth guard for this route) but it is NOT in `CSRF_BYPASS_PREFIXES`. The middleware will require the `authjs.csrf-token` cookie on POST. This is intentional.

Verify the contract by reading `src/middleware.ts:7,13` — confirm:
- `PUBLIC_PREFIXES` includes `/api/auth` (so it gets past the auth guard).
- `CSRF_BYPASS_PREFIXES` includes `/api/auth/signup` and `/api/auth/login` explicitly, but NOT `/api/auth/switch-org`. The prefix match in middleware uses `startsWith`, so `/api/auth/switch-org` only bypasses CSRF if a prefix in the bypass list is a prefix of it. `/api/auth/signup` is not a prefix of `/api/auth/switch-org`. Good.

If the grep finds anything in middleware.ts, STOP and document why before continuing.

- [ ] **Step 7: Run the full suite to confirm no regressions**

Run: `npm test -- --run 2>&1 | tail -5`
Expected: total = baseline + 10 (3 memberships + 6 switch-org + 1 forge). All passing.

- [ ] **Step 8: Commit**

```bash
git add src/app/api/auth/switch-org/route.ts src/tests/switch-org.test.ts src/tests/pm4-forge-switch-cross-org.test.ts
git commit -m "feat(auth): PM4 — POST /api/auth/switch-org with forge tests"
```

---

## Task 4: AppShell — load memberships server-side

**Why:** The dropdown needs the current user's memberships, but only renders an affordance if `memberships.length > 1`. Loading server-side avoids an extra round-trip on first paint and keeps the membership list out of the client bundle until needed.

**Files:**
- Modify: `src/components/AppShell.tsx`

- [ ] **Step 1: Update AppShell.tsx to load memberships**

Read the file first (`src/components/AppShell.tsx:1-25`), then replace the body so the server component also fetches the caller's memberships:

```tsx
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import type { ReactNode } from "react"
import { AppShellClient } from "./AppShellClient"

type AppShellProps = {
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  userName: string
  userId: string
  children: ReactNode
}

export type MembershipOption = {
  organisationId: string
  organisationName: string
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  teamId: string | null
}

export async function AppShell({ role, userName, userId, children }: AppShellProps) {
  const ctx = await withAuthScoped()

  const [org, memberships] = ctx
    ? await Promise.all([
        db.organisation.findUnique({ where: { id: ctx.organisationId }, select: { name: true } }),
        db.membership.findMany({
          where: { userId: ctx.userId },
          select: {
            organisationId: true,
            role: true,
            teamId: true,
            organisation: { select: { name: true } },
          },
          orderBy: { createdAt: "asc" },
        }),
      ])
    : [null, []]

  const membershipOptions: MembershipOption[] = memberships.map((m) => ({
    organisationId: m.organisationId,
    organisationName: m.organisation?.name ?? "",
    role: m.role as MembershipOption["role"],
    teamId: m.teamId,
  }))

  return (
    <AppShellClient
      role={role}
      userName={userName}
      userId={userId}
      orgName={org?.name ?? ""}
      activeOrganisationId={ctx?.organisationId ?? ""}
      memberships={membershipOptions}
    >
      {children}
    </AppShellClient>
  )
}
```

- [ ] **Step 2: Confirm tsc**

Run: `npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l`
Expected: same baseline (the AppShellClient signature still has the old shape — tsc WILL fail temporarily. Move directly to Task 5 without committing.)

⚠️ **Do not commit at the end of this task.** AppShell and AppShellClient must change together to keep tsc green between commits — bundle this commit with Task 5.

---

## Task 5: OrgBadge component + AppShellClient wiring

**Why:** The dropdown is the user-visible surface. The spec calls for:
- Single-membership users see the static badge they have today (no affordance).
- Multi-membership users see a chevron / dropdown; current org is marked; selecting one POSTs switch-org and then `router.refresh()`.
- NEVER optimistic — the cookie re-issue is the source of truth.

**Files:**
- Create: `src/components/OrgBadge.tsx`
- Modify: `src/components/AppShellClient.tsx`

- [ ] **Step 1: Create OrgBadge**

Create `src/components/OrgBadge.tsx`:

```tsx
"use client"

import { useRouter } from "next/navigation"
import { useState, useRef, useEffect } from "react"
import type { MembershipOption } from "./AppShell"

type OrgBadgeProps = {
  orgName: string
  activeOrganisationId: string
  memberships: MembershipOption[]
}

export function OrgBadge({ orgName, activeOrganisationId, memberships }: OrgBadgeProps) {
  const router = useRouter()
  const [open, setOpen] = useState(false)
  const [pending, setPending] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const wrapperRef = useRef<HTMLDivElement>(null)

  const multi = memberships.length > 1

  // Close on click-outside
  useEffect(() => {
    if (!open) return
    function onDocClick(e: MouseEvent) {
      if (!wrapperRef.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener("mousedown", onDocClick)
    return () => document.removeEventListener("mousedown", onDocClick)
  }, [open])

  // Close on Escape
  useEffect(() => {
    if (!open) return
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false)
    }
    document.addEventListener("keydown", onKey)
    return () => document.removeEventListener("keydown", onKey)
  }, [open])

  async function selectOrg(organisationId: string) {
    if (organisationId === activeOrganisationId) {
      setOpen(false)
      return
    }
    setError(null)
    setPending(organisationId)
    try {
      const res = await fetch("/api/auth/switch-org", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ organisationId }),
      })
      if (!res.ok) {
        setError(res.status === 403 ? "Not a member" : "Switch failed")
        setPending(null)
        return
      }
      setOpen(false)
      // Server re-issued the cookie. router.refresh() re-runs the server tree
      // with the new active org. NEVER set local state here — the cookie is
      // the only source of truth.
      router.refresh()
    } catch {
      setError("Network error")
      setPending(null)
    }
  }

  const badge = (
    <span
      style={{
        display: "inline-flex",
        alignItems: "center",
        gap: "4px",
        maxWidth: "130px",
        overflow: "hidden",
        textOverflow: "ellipsis",
        whiteSpace: "nowrap",
        background: "rgba(255,255,255,0.03)",
        border: "1px solid rgba(255,255,255,0.07)",
        borderRadius: "4px",
        padding: "1px 6px",
        fontSize: "0.60rem",
        fontWeight: 500,
        color: "var(--text-muted)",
        letterSpacing: "0.03em",
        fontFamily: "var(--font-mono, 'IBM Plex Mono', monospace)",
        cursor: multi ? "pointer" : "default",
      }}
    >
      <span style={{ overflow: "hidden", textOverflow: "ellipsis" }}>{orgName || "—"}</span>
      {multi && (
        <svg width="9" height="9" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
          <path d="M4 6l4 4 4-4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      )}
    </span>
  )

  if (!multi) return badge

  return (
    <div ref={wrapperRef} style={{ position: "relative", display: "inline-block" }}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Switch organisation"
        style={{
          background: "transparent",
          border: "none",
          padding: 0,
          cursor: "pointer",
        }}
      >
        {badge}
      </button>
      {open && (
        <div
          role="listbox"
          style={{
            position: "absolute",
            top: "calc(100% + 4px)",
            left: 0,
            zIndex: 200,
            minWidth: "190px",
            background: "var(--sidebar-surface)",
            border: "1px solid rgba(255,255,255,0.1)",
            borderRadius: "6px",
            boxShadow: "0 8px 24px rgba(0,0,0,0.45)",
            padding: "4px",
            fontFamily: "var(--font-sans, 'Plus Jakarta Sans', system-ui, sans-serif)",
          }}
        >
          {memberships.map((m) => {
            const active = m.organisationId === activeOrganisationId
            const isPending = pending === m.organisationId
            return (
              <button
                key={m.organisationId}
                role="option"
                aria-selected={active}
                disabled={isPending}
                onClick={() => selectOrg(m.organisationId)}
                style={{
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "space-between",
                  width: "100%",
                  padding: "6px 10px",
                  fontSize: "12px",
                  fontWeight: active ? 600 : 500,
                  color: active ? "#0ee29e" : "var(--text-primary)",
                  background: active ? "rgba(14,226,158,0.08)" : "transparent",
                  border: "none",
                  borderRadius: "4px",
                  cursor: isPending ? "wait" : "pointer",
                  textAlign: "left",
                }}
              >
                <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                  {m.organisationName || m.organisationId}
                </span>
                {active && (
                  <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2.5" aria-hidden="true">
                    <path d="M3 8.5l3 3 7-7" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                )}
              </button>
            )
          })}
          {error && (
            <div
              role="alert"
              style={{
                marginTop: "4px",
                padding: "4px 10px",
                fontSize: "10.5px",
                color: "#ff8099",
              }}
            >
              {error}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Modify AppShellClient to take memberships + activeOrganisationId props and render OrgBadge**

Read `src/components/AppShellClient.tsx:1-16` and `:260-282` to confirm the current shape, then:

1. Update the props type:

```tsx
type AppShellClientProps = {
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  userName: string
  userId: string
  orgName: string
  activeOrganisationId: string
  memberships: import("./AppShell").MembershipOption[]
  children: ReactNode
}
```

2. Update the destructure on the component signature (around line 134):

```tsx
export function AppShellClient({
  role,
  userName,
  orgName,
  activeOrganisationId,
  memberships,
  children,
}: AppShellClientProps) {
```

3. Add the import at the top of the file (right after the `AppShellSignOut` import):

```tsx
import { OrgBadge } from "./OrgBadge"
```

4. Replace the static org chip (the inner `<div>` at lines ~263–281, which currently renders `{orgName || "—"}`) with `<OrgBadge orgName={orgName} activeOrganisationId={activeOrganisationId} memberships={memberships} />`.

- [ ] **Step 3: Confirm tsc**

Run: `npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l`
Expected: back to baseline.

- [ ] **Step 4: Run the full test suite — confirm nothing regressed**

Run: `npm test -- --run 2>&1 | tail -5`
Expected: baseline + 10. All passing.

- [ ] **Step 5: Visual smoke test (manual)**

Start the dev server (`npm run dev`) and confirm in a browser:
- A single-membership user: badge has no chevron, no interaction.
- A multi-membership user (use a seeded fixture or `npx prisma studio` to add a second Membership row for your test user): chevron appears, dropdown opens, current org has a tick, selecting another org → spinner cursor briefly → page refreshes with new orgName in the badge.
- Hard-refresh after switching: still shows the new active org. (Confirms the cookie was set, not just local state.)

If the dropdown doesn't appear for a multi-member user, check the network tab for `/api/auth/memberships` — wait, this UI path uses the AppShell server fetch, not the API. The API exists for future client-driven refresh flows but the dropdown does not depend on it.

- [ ] **Step 6: Commit (bundled with Task 4's changes)**

```bash
git add src/components/AppShell.tsx src/components/AppShellClient.tsx src/components/OrgBadge.tsx
git commit -m "feat(auth): PM4 — org badge dropdown with router-refresh switch"
```

---

## Task 6: Documentation updates

**Why:** Rule 10 — docs must reflect new routes, ADRs, and code shape.

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/structure.md`
- Modify: `docs/code.md`
- Modify: `docs/dataflow.md`
- Modify: `docs/decisions.md`

- [ ] **Step 1: Add the routes to `docs/architecture.md`**

Find the "API Routes" section and add under `/api/auth`:
- `GET /api/auth/memberships` — caller's memberships (PM4)
- `POST /api/auth/switch-org` — re-issue JWT with new active org (PM4)

- [ ] **Step 2: Add files to `docs/structure.md`**

Append entries under the appropriate sections:
- `src/app/api/auth/memberships/route.ts` — GET, caller-only
- `src/app/api/auth/switch-org/route.ts` — POST, membership-gated JWT re-issue
- `src/lib/ratelimit-switch-org.ts` — sliding-window limiter (10/min, 60/hr per user)
- `src/components/OrgBadge.tsx` — sidebar dropdown for org switching

- [ ] **Step 3: Document the switch-org invariants in `docs/code.md`**

Append a short section:

> **PM4 — switch-org re-issue invariants**
> When `POST /api/auth/switch-org` re-encodes the JWT it keeps `sub` (userId), `sessionVersion`, and the cookie name (Secure vs not based on proto). It updates `activeOrganisationId`, `iat`, `exp` (to `now + SESSION_MAX_AGE`, matching login's 30-day horizon), and generates a fresh `jti`. The session lifetime is NOT prolonged beyond a fresh login window — switching is treated as activity. `role`, `teamId`, `isLineManager` are NEVER in the JWT.

- [ ] **Step 4: Document the API shapes in `docs/dataflow.md`**

Append:

> **GET /api/auth/memberships** — derives `userId` from session, returns `{ memberships: [{ organisationId, organisationName, role, teamId }] }`. No query parameters honored.
>
> **POST /api/auth/switch-org** — body `{ organisationId: string }`. On Membership hit: 200 `{ ok: true, activeOrganisationId }` + `Set-Cookie: authjs.session-token=...`. On miss: 403. Rate-limited 10/min, 60/hr per `ctx.userId`.

- [ ] **Step 5: Add PM4 ADR to `docs/decisions.md`**

Append:

> **ADR-PM4 — Active org is mutable mid-session via JWT re-issue, not DB state**
>
> The active organisation is encoded in the JWT only — there is no `User.activeOrganisationId` column. Switching orgs re-issues the JWT (same `sub`, same `sessionVersion`, new `activeOrganisationId`). This means:
> 1. There is no race between updating a DB column and the JWT going stale — the cookie IS the active org.
> 2. The membership check in `withAuthScoped` is still the proof on every request; the JWT is a hint.
> 3. Multiple browser tabs each have their own active org cookie — switching in one tab does not move the others until they `router.refresh()`. This is acceptable for a security-first design (each tab is its own session window).
> 4. The CSRF gate applies to `POST /api/auth/switch-org` (it is NOT in `CSRF_BYPASS_PREFIXES`), so cross-site form posts cannot move the active org.

- [ ] **Step 6: Commit docs**

```bash
git add docs/architecture.md docs/structure.md docs/code.md docs/dataflow.md docs/decisions.md
git commit -m "docs(auth): PM4 — record switch-org + memberships routes and ADR"
```

---

## Task 7: Final verification

- [ ] **Step 1: tsc baseline unchanged**

Run: `npx tsc --noEmit 2>&1 | grep -E "error TS" | wc -l`
Expected: matches the pre-flight number exactly.

- [ ] **Step 2: Full suite green, +10 tests**

Run: `npm test -- --run 2>&1 | tail -5`
Expected: total = pre-flight + 10. 0 failures.

- [ ] **Step 3: Grep for accidental bypass entries**

Run: `grep -n "switch-org" src/middleware.ts`
Expected: NO output — switch-org should not be referenced anywhere in middleware.

Run: `grep -rn "role.*JWT\|teamId.*JWT" src/app/api/auth/switch-org/route.ts`
Expected: NO output — role/teamId must never appear in the JWT payload.

- [ ] **Step 4: Confirm conventional-commit log shape**

Run: `git log --oneline -10`
Expected: PM4 commits use `feat(auth):` / `docs(auth):` prefixes, no `Co-Authored-By` trailer.

- [ ] **Step 5: Ship-readiness summary**

Write a one-paragraph summary to the user covering:
- Tests added (count) and any tsc movement.
- Forge tests passing (3: tampered JWT from PM3 still passes; new PM4 cross-org switch returns 403; non-membership enumeration test).
- The CSRF gate path (verified, not bypassed).
- Whether a manual second-membership fixture was used for the UI smoke test.

---

## Self-review notes

**Spec coverage:**
- `GET /api/auth/memberships` — Task 1.
- `POST /api/auth/switch-org` — Task 3.
- 200 on member, 403 on non-member — switch-org test cases + forge test.
- 400 malformed, 401 no session — switch-org test cases.
- Same JWT semantics (sub/iat/exp/sessionVersion preserved, no lifetime extension) — Task 3 implementation + decode-and-assert test.
- Rate limit, fail-open on Upstash error — Task 2.
- CSRF gate stays in front — Task 3 step 6 verifies.
- Caller-only enumeration ("user A cannot list user B's memberships") — Task 1 test 3.
- Badge dropdown affordance only when >1 membership — OrgBadge `multi` check in Task 5.
- `router.refresh()` after 200, NEVER optimistic — Task 5 `selectOrg` only refreshes on `res.ok`.
- Re-issued JWT carries `activeOrganisationId` only (no role/team) — Task 3 decode-assert test + final-verification grep.
- Conventional commits, no co-author line — verified in Task 7.
- Branch `afthab/axis-pulse` — assumed; current branch.

**Type consistency:** `MembershipOption` is defined once in `AppShell.tsx`, imported by `OrgBadge.tsx` and `AppShellClient.tsx`. Role union is identical to `AuthContext["role"]`. The API response shape `{ organisationId, organisationName, role, teamId }` matches `MembershipOption` field-for-field.

**Placeholder scan:** None — every step has the actual code or command.
