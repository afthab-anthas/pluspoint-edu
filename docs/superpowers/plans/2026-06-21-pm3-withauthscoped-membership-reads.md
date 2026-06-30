# PM3 — withAuthScoped Reads + Verifies Membership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the tenancy boundary from "trust the JWT's org claim" to "verify a Membership row exists on every authenticated request." After PM3, the JWT's `activeOrganisationId` is only a *hint* — the proof that the user belongs to that org is the indexed `Membership(userId, organisationId)` row read inside `withAuthScoped`.

**Architecture:** The JWT becomes role-less. Login picks the user's first Membership (createdAt ASC) and stamps its `organisationId` into a new `activeOrganisationId` token claim. `withAuthScoped` decodes the JWT, then performs a single composite-indexed `findUnique` on `Membership` keyed by `(userId, activeOrganisationId)`. No row → return `null` → 401. The row's `role`/`teamId`/`isLineManager` populate `AuthContext`. Old `User.organisationId/role/teamId/isLineManager` columns stay on disk (written by signup) so PM6 has a clean drop later; **no code reads them after PM3**. Ingest routes and other Bearer-token paths are untouched (they never used `withAuthScoped`). The four forge tests are the deliverable — they prove the boundary holds against tampered JWTs, forged headers, cross-org access, and mid-session revocation.

**Tech Stack:** Next.js 14 App Router · NextAuth v5 (JWT) · `@auth/core/jwt` (raw encode for tests) · Prisma 7 + PostgreSQL · TypeScript 5 · Vitest 4.

---

## Scope & Constraints (from spec)

**Hard rules:**
- The anti-forge guard lives **ONLY** in `withAuthScoped`. No other handler may read `activeOrganisationId` from the JWT and skip the membership lookup. Three existing direct-`session.user.*` callsites (`github/connect`, `layout.tsx`, `install/page.tsx`) must be moved onto `withAuthScoped`.
- The ~158 `ctx.role === "..."` call-sites in route handlers must **not** change. `AuthContext` keeps its existing shape; `ctx.role/teamId/isLineManager` just come from a different source now.
- Ingest paths (`/api/ingest/event`, `/api/ingest/time`) do **not** use `withAuthScoped` — confirmed by `grep`, not by assumption. They must remain unaffected.
- Membership lookup = one indexed read per request (the `@@unique([userId, organisationId])` composite index from PM2). No N+1.
- `sessionVersion` drift gate behaviour is **unchanged**.
- Old `User.organisationId/role/teamId/isLineManager` columns: keep being written on signup (fallback until PM6). Login does **not** read them anymore.
- `tsc` baseline unchanged. Full Vitest suite green.

**Standing rules:**
- Branch: `afthab/axis-pulse` (already checked out).
- Conventional commit, no `Co-Authored-By` trailer.
- Re-read files before `Edit` / `str_replace`.
- TDD: forge tests fail first, then code change makes them pass.

---

## File Structure

**Created:**
- `src/tests/pm3-forge-tampered-jwt.test.ts` — forge test (c): JWT tampered to claim membership in an org the user is NOT a member of → `withAuthScoped` returns `null` → routes return 401.
- `src/tests/pm3-forge-header-ignored.test.ts` — forge test (b): `X-Active-Org` header is never read; ctx remains bound to the JWT's `activeOrganisationId` + verified Membership.
- `src/tests/pm3-forge-cross-org-resource.test.ts` — forge test (a): user with memberships in A and B, active=B, requests an A resource → 404 (resource is scoped out by `organisationId` filter).
- `src/tests/pm3-forge-revoked-mid-session.test.ts` — forge test (d): membership deleted mid-session and `sessionVersion` bumped → next request returns 401.

**Modified:**
- `src/types/next-auth.d.ts` — rename `Session["user"].organisationId` → `activeOrganisationId`; drop `role`, `teamId`, `isLineManager` from `Session["user"]` and from the `JWT` interface. `sessionVersion` stays.
- `src/lib/auth.ts` — session callback writes only `activeOrganisationId` + `sessionVersion` + `id` onto `session.user`. No role/team/isLineManager copy.
- `src/lib/withAuthScoped.ts` — read `activeOrganisationId` (rename), then `db.membership.findUnique({ where: { userId_organisationId: { userId, organisationId: activeOrganisationId } } })`. If `null`, return `null`. Populate `ctx.role/teamId/isLineManager/organisationId` from the row. `sessionVersion` drift gate unchanged.
- `src/app/api/auth/login/route.ts` — after credential verify, `db.membership.findFirst({ where: { userId }, orderBy: { createdAt: "asc" }})`. If none, return 401 (`reason: "no_membership"`). Encode JWT with `activeOrganisationId` only (no `role`/`teamId`/`isLineManager`).
- `src/app/api/auth/signup/route.ts` — wrap user creation + first Membership creation in a single `db.$transaction(...)`. Self-signup → `Membership(role: "MANAGER")`. Invitation path → `Membership(role: invitation.role, teamId: invitation.teamId)`. Keep writing the old `User.organisationId/role/teamId/isLineManager` columns (fallback for PM6 rollback).
- `src/app/api/github/connect/route.ts` — replace `const role = session.user.role` with `const ctx = await withAuthScoped(); if (!ctx) return 401; if (ctx.role !== "MANAGER" && ctx.role !== "LINE_MANAGER") return 403`.
- `src/app/layout.tsx` — replace `session.user.organisationId` with `withAuthScoped` lookup (or, since this is a server component reading the active org for a ceiling banner, swap to `session.user.activeOrganisationId` — the banner is non-authoritative; the cost-ceiling write paths already use `withAuthScoped`).
- `src/app/install/page.tsx` — replace `session.user.role` with `ctx.role` via `withAuthScoped()`.
- `src/tests/multi-tenant-fuzz.test.ts` — add a two-membership fixture (`u-multi` member of `org-test` and `org-other`, active=`org-test`); add an `it()` exercising forge case (a) against `GET /api/projects/:id` for a project in `org-other` (expect 404).
- `src/tests/login.test.ts` — extend fixtures: every `mockDb.user.findUnique` mock must be paired with a `mockDb.membership.findFirst` mock; new case "401 when user exists but has no Membership row".
- `src/tests/signup.test.ts` — assert `db.$transaction` is called; assert it contains both a `user.create` and a `membership.create`.
- `docs/architecture.md` — update Authentication section: JWT no longer carries role/team; describe the per-request Membership verification.
- `docs/decisions.md` — new ADR: "PM3 — Tenancy boundary at withAuthScoped via Membership lookup". Records why JWT is no longer trusted alone, the four forge tests, and what's still deferred to PM4–PM6.
- `docs/dataflow.md` — update the auth section to describe the Membership read on every authenticated request.
- `docs/code.md` — update the `withAuthScoped()` and `AuthContext` reference to note that role/teamId/isLineManager are now sourced from `Membership`, not from the JWT.
- `docs/risk.md` — close P-multi-tenancy partial-mitigation note: JWT-trust is no longer a risk; record the residual risk that ingest paths still rely on AgentToken org-binding (out of PM3 scope).
- `docs/glossary.md` — update the `Membership` entry from "read-shadow (PM2)" to "tenancy proof (PM3)".
- `docs/structure.md` — list new forge test files.

**NOT touched:** `src/app/api/ingest/event/route.ts`, `src/app/api/ingest/time/route.ts`, anything under `src/app/api/github/installations/*` (already uses `withAuthScoped`), `src/lib/db.ts`, Prisma schema, any migration. No new lib modules.

---

## Stop Point

**STOP after Task 1** for user review BEFORE Tasks 2–7. Task 1 delivers: JWT shape change + `withAuthScoped` Membership lookup + login route update + **one forge test passing** (the tampered-JWT case (c), which is the most direct proof of the boundary). Show the user the failing-then-passing test output, then wait.

---

## Task 1: Core boundary change + first forge test (tampered JWT)

**Files:**
- Modify: `src/types/next-auth.d.ts`
- Modify: `src/lib/auth.ts`
- Modify: `src/lib/withAuthScoped.ts`
- Modify: `src/app/api/auth/login/route.ts`
- Create: `src/tests/pm3-forge-tampered-jwt.test.ts`

This is the security-critical change. We do it strictly TDD: write the forge test first against the *old* code (it will show that the old behaviour was "JWT-trusting"), then change the boundary so the test passes for the right reason.

- [ ] **Step 1: Write the forge test FIRST (red)**

Create `src/tests/pm3-forge-tampered-jwt.test.ts`:

```typescript
// src/tests/pm3-forge-tampered-jwt.test.ts
// PM3 FORGE TEST (c) — JWT tampered to claim membership in an org the user is
// NOT a member of. withAuthScoped must return null (→ caller returns 401).
// This is the proof that the JWT claim is a hint, not a credential.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    user: { findUnique: vi.fn() },
    membership: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/auth", () => ({
  auth: vi.fn(),
}))

import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { auth } from "@/lib/auth"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(auth)

describe("PM3 forge test (c) — tampered JWT claiming non-member org", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("withAuthScoped returns null when JWT claims org C and no Membership row exists", async () => {
    // Attacker JWT: claims activeOrganisationId="org-C" but user has no Membership(u1, org-C)
    mockAuth.mockResolvedValue({
      user: {
        id: "u1",
        activeOrganisationId: "org-C",
        sessionVersion: 0,
      },
    } as unknown as Awaited<ReturnType<typeof auth>>)

    // Membership lookup returns null — the user is NOT a member of org-C
    mockDb.membership.findUnique.mockResolvedValue(null)

    // sessionVersion drift gate must still pass (so we know the null came from membership)
    mockDb.user.findUnique.mockResolvedValue({ sessionVersion: 0 } as never)

    const ctx = await withAuthScoped()

    expect(ctx).toBeNull()
    expect(mockDb.membership.findUnique).toHaveBeenCalledWith({
      where: { userId_organisationId: { userId: "u1", organisationId: "org-C" } },
    })
  })

  it("withAuthScoped returns a valid AuthContext when Membership row exists", async () => {
    mockAuth.mockResolvedValue({
      user: {
        id: "u1",
        activeOrganisationId: "org-A",
        sessionVersion: 0,
      },
    } as unknown as Awaited<ReturnType<typeof auth>>)

    mockDb.membership.findUnique.mockResolvedValue({
      id: "mem-1",
      userId: "u1",
      organisationId: "org-A",
      role: "MANAGER",
      teamId: null,
      isLineManager: false,
      createdAt: new Date(),
      updatedAt: new Date(),
    } as never)

    mockDb.user.findUnique.mockResolvedValue({ sessionVersion: 0 } as never)

    const ctx = await withAuthScoped()

    expect(ctx).not.toBeNull()
    expect(ctx!.userId).toBe("u1")
    expect(ctx!.organisationId).toBe("org-A")
    expect(ctx!.role).toBe("MANAGER")
  })
})
```

- [ ] **Step 2: Run the test — confirm it FAILS (red)**

Run: `npx vitest run src/tests/pm3-forge-tampered-jwt.test.ts`

Expected: both tests fail. The first fails because the current `withAuthScoped` does not call `db.membership.findUnique` at all (it trusts `session.user.organisationId` directly and returns a non-null ctx). The second fails for the same reason — the assertion that membership is consulted is unmet.

This failure is the proof that the old code "trusted the JWT claim" — exactly the gap PM3 closes.

- [ ] **Step 3: Update `src/types/next-auth.d.ts` — rename + drop fields**

Replace the file with:

```typescript
import type { DefaultSession } from "next-auth"

declare module "next-auth" {
  interface Session {
    user: DefaultSession["user"] & {
      id: string
      // PM3: organisationId renamed to activeOrganisationId to make it
      // semantically a *hint* — the membership lookup in withAuthScoped is
      // the actual proof that the user belongs to this org.
      activeOrganisationId: string
      sessionVersion: number
    }
  }
}

declare module "next-auth/jwt" {
  interface JWT {
    // PM3: role/teamId/isLineManager removed — looked up from Membership instead.
    activeOrganisationId?: string
    sessionVersion?: number
  }
}
```

- [ ] **Step 4: Update `src/lib/auth.ts` — session callback emits new shape only**

Replace the session callback in `src/lib/auth.ts`:

```typescript
import NextAuth from "next-auth"
import type { Session } from "next-auth"
import type { JWT } from "next-auth/jwt"

export const { handlers, auth, signIn, signOut } = NextAuth({
  trustHost: true,
  providers: [],
  session: { strategy: "jwt" },
  pages: {
    signIn: "/login",
  },
  callbacks: {
    jwt({ token }: { token: JWT }) {
      return token
    },
    session({ session, token }: { session: Session; token: JWT }) {
      if (token.sub) session.user.id = token.sub
      session.user.activeOrganisationId = (token.activeOrganisationId as string) ?? ""
      session.user.sessionVersion = (token.sessionVersion as number) ?? 0
      return session
    },
  },
})
```

- [ ] **Step 5: Update `src/lib/withAuthScoped.ts` — membership lookup IS the proof**

Replace the body of `withAuthScoped` (keep `getDbSessionVersion`, `invalidateSessionVersionCache`, `AuthScope`, `AuthContext`, `orgWhere`, `teamWhere` exactly as-is):

```typescript
import { auth } from "@/lib/auth"
import { db } from "@/lib/db"

const _sessionVersionCache = new Map<string, { version: number; cachedAt: number }>()
const SESSION_VERSION_TTL_MS = 30_000

async function getDbSessionVersion(userId: string): Promise<number> {
  const cached = _sessionVersionCache.get(userId)
  if (cached && Date.now() - cached.cachedAt < SESSION_VERSION_TTL_MS) {
    return cached.version
  }
  const user = await db.user.findUnique({ where: { id: userId }, select: { sessionVersion: true } })
  const version = user?.sessionVersion ?? 0
  _sessionVersionCache.set(userId, { version, cachedAt: Date.now() })
  return version
}

export function invalidateSessionVersionCache(userId: string) {
  _sessionVersionCache.delete(userId)
}

export type AuthScope = {
  allTeams: boolean
  canSeeTimeData: boolean
  viewedTeamIds: string[]
}

export type AuthContext = {
  userId: string
  organisationId: string
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  teamId: string | null
  isLineManager: boolean
  scope: AuthScope
}

export async function withAuthScoped(): Promise<AuthContext | null> {
  const session = await auth()
  if (!session) return null

  const activeOrganisationId = session.user.activeOrganisationId ?? ""
  if (!activeOrganisationId) return null

  // sessionVersion drift check — unchanged from PM1
  const jwtVersion = session.user.sessionVersion ?? 0
  const dbVersion = await getDbSessionVersion(session.user.id)
  if (jwtVersion !== dbVersion) return null

  // PM3 anti-forge guard — the membership lookup IS the proof. A tampered
  // JWT claiming activeOrganisationId="org-C" for a user who is NOT a member
  // of org-C resolves to null here and the caller returns 401.
  const membership = await db.membership.findUnique({
    where: {
      userId_organisationId: {
        userId: session.user.id,
        organisationId: activeOrganisationId,
      },
    },
    select: { organisationId: true, role: true, teamId: true, isLineManager: true },
  })
  if (!membership) return null

  const role = membership.role as AuthContext["role"]
  const teamId = membership.teamId ?? null

  const scope: AuthScope = {
    allTeams: role === "MANAGER",
    canSeeTimeData: role !== "MEMBER",
    viewedTeamIds: role === "MANAGER" ? [] : (teamId ? [teamId] : []),
  }

  return {
    userId: session.user.id,
    organisationId: membership.organisationId,
    role,
    teamId,
    isLineManager: membership.isLineManager,
    scope,
  }
}

export function orgWhere(ctx: AuthContext) {
  return { organisationId: ctx.organisationId }
}

export function teamWhere(ctx: AuthContext) {
  if (ctx.role === "MANAGER") return orgWhere(ctx)
  if (ctx.role === "LINE_MANAGER") return { teamId: ctx.teamId, organisationId: ctx.organisationId }
  return { id: "__none__" }
}
```

- [ ] **Step 6: Update `src/app/api/auth/login/route.ts` — JWT carries activeOrganisationId only**

Re-read the file first (it was last updated in PM1/PM2). Then replace the credential-verified branch from "Valid credentials — reset lockout state" through the end of `POST` with:

```typescript
  // Valid credentials — reset lockout state
  await db.user.update({
    where: { id: user.id },
    data: { failedLogins: 0, lockedUntil: null },
  })

  // PM3: pick the user's active organisation by looking up their first
  // Membership row (createdAt ASC). Post-PM2 backfill every user has exactly
  // one Membership. If none exists, refuse the login — there is no org to
  // scope this session to.
  const membership = await db.membership.findFirst({
    where: { userId: user.id },
    orderBy: { createdAt: "asc" },
    select: { organisationId: true },
  })
  if (!membership) {
    recordRequest("/api/auth/login", "POST", 401, Date.now() - startMs)
    return NextResponse.json({ error: "Invalid credentials" }, { status: 401 })
  }

  const cookieName = getCookieName(request)

  const token = await encode({
    token: {
      sub: user.id,
      name: user.name,
      email: user.email,
      activeOrganisationId: membership.organisationId,
      sessionVersion: user.sessionVersion,
      iat: Math.floor(Date.now() / 1000),
      exp: Math.floor(Date.now() / 1000) + SESSION_MAX_AGE,
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
  if (isSecureCookie) {
    cookieParts.push("Secure")
  }
  const setCookieHeader = cookieParts.join("; ")

  const response = NextResponse.json({ ok: true }, { status: 200 })
  response.headers.set("set-cookie", setCookieHeader)
  recordRequest("/api/auth/login", "POST", 200, Date.now() - startMs)
  return response
}
```

Note: the `no-membership` case returns the same opaque `"Invalid credentials"` body as a bad password — never reveal that the account exists but lacks a membership.

- [ ] **Step 7: Run the forge test — confirm it PASSES (green)**

Run: `npx vitest run src/tests/pm3-forge-tampered-jwt.test.ts`

Expected: both tests pass. The first asserts that `withAuthScoped` returns `null` when `db.membership.findUnique` returns `null` for a tampered org claim. The second asserts the happy path still produces a complete `AuthContext` populated from the membership row.

- [ ] **Step 8: Run the type-checker — baseline must be unchanged**

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | wc -l`

Expected: same as the pre-Task-1 count (record the pre-edit number first if not already known; the project memory note "TSC Baseline Drift" tracks this). The shape change to `Session["user"]` is a breaking field-rename across `src/` — anywhere that still reads `session.user.organisationId` directly will surface as a tsc error. If any do appear, they are dealt with in Task 3 (the 3 known non-route callsites). For Task 1, the expectation is that the only new tsc errors are at the three known sites: `src/app/api/github/connect/route.ts`, `src/app/layout.tsx`, `src/app/install/page.tsx`. Record their count.

If the count is higher than 3 new sites, grep for the leak: `grep -rn "session.user.organisationId\|session.user.role\b\|session.user.teamId\|session.user.isLineManager" src/` (excluding the files we edited). Address before continuing.

- [ ] **Step 9: Run a sanity slice of the existing suite — login + multi-tenant fuzz**

Run: `npx vitest run src/tests/login.test.ts src/tests/multi-tenant-fuzz.test.ts`

Some failures are expected:
- `login.test.ts` will fail because the route now calls `db.membership.findFirst`, which the existing test mock doesn't stub. **Note the failure mode in the commit message; the fix lives in Task 6 (test fixture updates).**
- `multi-tenant-fuzz.test.ts` should still pass because it mocks `withAuthScoped` directly, bypassing the membership lookup.

Both observations are expected and prove the change is isolated to the boundary. Do not "fix" `login.test.ts` here — Task 6 batches all fixture updates.

- [ ] **Step 10: Commit — boundary change + first forge test**

```bash
git add src/types/next-auth.d.ts src/lib/auth.ts src/lib/withAuthScoped.ts src/app/api/auth/login/route.ts src/tests/pm3-forge-tampered-jwt.test.ts
git commit -m "[feat](auth): PM3 — withAuthScoped verifies Membership row per request

JWT becomes role-less; the activeOrganisationId claim is a hint, not proof.
Per-request membership lookup is the tenancy boundary. Forge test (c) —
tampered JWT claiming a non-member org — passes."
```

- [ ] **Step 11: STOP for user review**

Show the user the diff for the four modified files, the new forge test, and the test output. Do NOT proceed to Task 2 until the user confirms.

---

## Task 2: Signup creates User + Membership atomically

**Files:**
- Modify: `src/app/api/auth/signup/route.ts`
- Modify: `src/tests/signup.test.ts`

- [ ] **Step 1: Re-read `src/app/api/auth/signup/route.ts`**

Use the Read tool. The current shape: invitation branch creates a `User` with `organisationId/role/teamId` inline; self-signup branch creates `Organisation` → `User` → optional `Team` → optional `Project`. Neither branch creates a `Membership` row.

- [ ] **Step 2: Write the failing test (red)**

Add to `src/tests/signup.test.ts` (in the existing self-signup describe block):

```typescript
it("creates Membership row in the same transaction as the User", async () => {
  // Arrange: no invitation, no existing user
  mockDb.invitation.findFirst.mockResolvedValue(null)
  mockDb.user.findUnique.mockResolvedValue(null)
  mockDb.organisation.create.mockResolvedValue({ id: "org-new", name: "Test Org" } as never)
  mockDb.user.create.mockResolvedValue({ id: "u-new", organisationId: "org-new" } as never)

  // The route should call $transaction with a callback that runs user.create
  // AND membership.create against the same Prisma client.
  const tx = vi.fn().mockImplementation(async (cb) => cb(mockDb))
  mockDb.$transaction = tx as never

  const res = await POST(makeRequest({
    email: "new@x.io", name: "New", password: "validPass1!",
    orgName: "Test Org",
  }))

  expect(res.status).toBe(201)
  expect(mockDb.$transaction).toHaveBeenCalledTimes(1)
  expect(mockDb.membership.create).toHaveBeenCalledWith({
    data: expect.objectContaining({
      userId: "u-new",
      organisationId: "org-new",
      role: "MANAGER",
      teamId: null,
      isLineManager: false,
    }),
  })
})
```

Add `membership: { create: vi.fn() }` to the top-of-file `vi.mock("@/lib/db", ...)` block alongside the existing model mocks. Also add `organisation: { create: vi.fn() }` and `invitation: { findFirst: vi.fn(), update: vi.fn() }` if not already present.

- [ ] **Step 3: Run — confirm failure**

Run: `npx vitest run src/tests/signup.test.ts -t "creates Membership"`

Expected: FAIL. The current route does not create a Membership.

- [ ] **Step 4: Update the signup route — wrap in $transaction**

Re-read the file first. Then in the self-signup branch, replace the `user.create` (and the optional team/project steps that follow it) with a `$transaction` that creates the user AND the membership. Keep writing `User.organisationId/role/teamId/isLineManager` as before (the PM6 fallback contract):

```typescript
    const org = await db.organisation.create({
      data: { id: `org_${crypto.randomBytes(8).toString("hex")}`, name: resolvedOrgName },
    })

    let newTeamId: string | null = null
    if (typeof teamName === "string" && teamName.trim()) {
      const team = await db.team.create({
        data: { name: teamName.trim(), organisationId: org.id },
      })
      newTeamId = team.id
    }

    const newUser = await db.$transaction(async (tx) => {
      const created = await tx.user.create({
        data: {
          email, name, passwordHash,
          role: "MANAGER",
          teamId: newTeamId,
          organisationId: org.id, // PM6-fallback column — keep writing
        },
      })
      await tx.membership.create({
        data: {
          userId: created.id,
          organisationId: org.id,
          role: "MANAGER",
          teamId: newTeamId,
          isLineManager: false,
        },
      })
      return created
    })

    if (typeof projectName === "string" && projectName.trim() && newTeamId) {
      const project = await db.project.create({
        data: { name: projectName.trim(), teamId: newTeamId, organisationId: org.id },
      })
      await db.projectMember.create({ data: { projectId: project.id, userId: newUser.id } })
    }
```

And do the same wrap for the invitation branch:

```typescript
  if (invitation) {
    await db.$transaction(async (tx) => {
      const created = await tx.user.create({
        data: {
          email, name, passwordHash,
          role: invitation.role,
          teamId: invitation.teamId,
          organisationId: invitation.organisationId, // PM6-fallback column
        },
      })
      await tx.membership.create({
        data: {
          userId: created.id,
          organisationId: invitation.organisationId,
          role: invitation.role,
          teamId: invitation.teamId,
          isLineManager: false,
        },
      })
      await tx.invitation.update({
        where: { id: invitation.id },
        data: { status: "ACCEPTED" },
      })
    })
  } else { /* self-signup branch above */ }
```

Note: the `Team` is created OUTSIDE the transaction because `Membership.teamId` references it via `SetNull` and Prisma will not let the transaction reorder these without extra ceremony. The user/membership/invitation-update are the atomic unit.

- [ ] **Step 5: Run the test — confirm green**

Run: `npx vitest run src/tests/signup.test.ts`

Expected: all signup tests pass, including the new "creates Membership row in the same transaction" case.

- [ ] **Step 6: Commit**

```bash
git add src/app/api/auth/signup/route.ts src/tests/signup.test.ts
git commit -m "[feat](auth): PM3 — signup creates User + Membership atomically

Self-signup: Membership(role=MANAGER). Invitation path: Membership inherits
invite's role+team. Old User.organisationId/role/teamId still written for
PM6 fallback."
```

---

## Task 3: Patch the four non-route session.user.* callsites

**Files:**
- Modify: `src/app/api/github/connect/route.ts`
- Modify: `src/app/layout.tsx`
- Modify: `src/app/install/page.tsx`
- Modify: `src/components/AppShell.tsx`

After Task 1, `tsc --noEmit` flags these four sites because they read `session.user.role` / `session.user.organisationId` directly. Each is dealt with by routing through `withAuthScoped` (the spec rule: "anti-forge guard ONLY in withAuthScoped"). `AppShell.tsx` is a server component that loads the active org's name for the sidebar header — it should read `ctx.organisationId` after `withAuthScoped()` returns, and render with `org?.name ?? ""` on null.

- [ ] **Step 1: Patch `src/app/api/github/connect/route.ts`**

Re-read the file. Replace lines 5–12 with:

```typescript
import { NextResponse } from "next/server"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { randomBytes } from "crypto"

export async function GET() {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })

  if (ctx.role !== "MANAGER" && ctx.role !== "LINE_MANAGER") {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }
```

- [ ] **Step 2: Patch `src/app/layout.tsx`**

Re-read lines 25–45. The layout reads `session.user.organisationId` non-authoritatively to decide whether to show a ceiling banner. We replace it with a `withAuthScoped` lookup, but tolerate a `null` (banner just stays hidden — the layout must never crash):

```typescript
import { withAuthScoped } from "@/lib/withAuthScoped"

  let showCeilingBanner = false
  try {
    const ctx = await withAuthScoped()
    if (ctx) {
      const ceiling = getMonthlyCeilingUSD()
      if (ceiling !== null) {
        const spend = await getMonthlySpendUSD(ctx.organisationId)
        showCeilingBanner = spend >= ceiling
      }
    }
  } catch {
    // Never crash the layout
  }
```

Remove the now-unused `auth` import if no other line in `layout.tsx` uses it; otherwise leave it. Grep first: `grep -n "auth()" src/app/layout.tsx`.

- [ ] **Step 3: Patch `src/app/install/page.tsx`**

Re-read the file. Replace the role read with `withAuthScoped`:

```typescript
import { withAuthScoped } from "@/lib/withAuthScoped"

export default async function InstallPage() {
  const ctx = await withAuthScoped()
  if (!ctx) redirect("/login")

  const user = await db.user.findUnique({
    where: { id: ctx.userId },
    select: { name: true },
  })
  const userName = user?.name ?? "User"

  const existingToken = await db.userToken.findUnique({
    where:  { userId: ctx.userId },
    select: { tokenPreview: true },
  })

  return (
    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
```

(The `name` was previously read straight off the JWT; now it comes from the DB. Acceptable — the install page is rarely loaded.)

- [ ] **Step 4: Run tsc — should be back to baseline**

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | wc -l`

Expected: same number as before Task 1.

- [ ] **Step 5: Commit**

```bash
git add src/app/api/github/connect/route.ts src/app/layout.tsx src/app/install/page.tsx
git commit -m "[refactor](auth): PM3 — route last 3 session.user.* sites through withAuthScoped

github/connect, layout ceiling banner, install page now use ctx.role/
organisationId derived from the per-request Membership lookup. No code path
outside withAuthScoped trusts the JWT's activeOrganisationId."
```

---

## Task 4: Remaining forge tests (a, b, d) + two-membership fixture in fuzz suite

**Files:**
- Create: `src/tests/pm3-forge-cross-org-resource.test.ts`
- Create: `src/tests/pm3-forge-header-ignored.test.ts`
- Create: `src/tests/pm3-forge-revoked-mid-session.test.ts`
- Modify: `src/tests/multi-tenant-fuzz.test.ts`

- [ ] **Step 1: Forge test (a) — cross-org resource**

Create `src/tests/pm3-forge-cross-org-resource.test.ts`:

```typescript
// PM3 FORGE TEST (a) — user is a member of org-A AND org-B, active=org-B,
// requests a project that lives in org-A. The org-scoped query in the route
// filters by ctx.organisationId (= "org-B"), so the org-A project is invisible
// → 404. This is the membership boundary doing its job at the data layer.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    project: { findUnique: vi.fn(), findFirst: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/withAuthScoped")>()
  return { ...actual, withAuthScoped: vi.fn() }
})

import { GET as getProject } from "@/app/api/projects/[id]/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(withAuthScoped)

const ACTIVE_B_CTX: AuthContext = {
  userId: "u-multi",
  organisationId: "org-B", // active org
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

describe("PM3 forge test (a) — cross-org resource is invisible to non-active membership", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuth.mockResolvedValue(ACTIVE_B_CTX)
    // The route's findUnique with organisationId: "org-B" returns null because
    // the project belongs to org-A. Prisma does NOT return rows that fail the
    // where clause.
    mockDb.project.findUnique.mockResolvedValue(null)
  })

  it("returns 404 when requesting an org-A project while active in org-B", async () => {
    const res = await getProject(
      new Request("http://localhost/api/projects/proj-in-org-A"),
      { params: { id: "proj-in-org-A" } }
    )
    expect(res.status).toBe(404)
    // Crucial: the where clause MUST scope by organisationId
    expect(mockDb.project.findUnique).toHaveBeenCalledWith(
      expect.objectContaining({
        where: expect.objectContaining({ organisationId: "org-B" }),
      })
    )
  })
})
```

Confirm the assertion shape by re-reading `src/app/api/projects/[id]/route.ts` — adjust the matcher to whatever the handler actually constructs (e.g. `where: { id, organisationId }` vs `where: { id_organisationId: {...} }`).

Run: `npx vitest run src/tests/pm3-forge-cross-org-resource.test.ts` → expect PASS.

- [ ] **Step 2: Forge test (b) — X-Active-Org header is ignored**

Create `src/tests/pm3-forge-header-ignored.test.ts`:

```typescript
// PM3 FORGE TEST (b) — defensive. We never plan to read X-Active-Org from
// request headers; the active org comes from the signed JWT only. This test
// is the trip-wire: if anyone wires up such a header in the future, the test
// suite will flag the regression because grep over src/ must show zero hits.

import { describe, it, expect } from "vitest"
import { readFileSync, readdirSync, statSync } from "node:fs"
import path from "node:path"

function walk(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === ".next" || entry === "generated") continue
    const full = path.join(dir, entry)
    const stat = statSync(full)
    if (stat.isDirectory()) out.push(...walk(full))
    else if (full.endsWith(".ts") || full.endsWith(".tsx")) out.push(full)
  }
  return out
}

describe("PM3 forge test (b) — no code reads an X-Active-Org / x-active-org header", () => {
  it("grep over src/ yields zero matches for the forbidden header name", () => {
    const srcDir = path.resolve(__dirname, "..")
    const offenders: string[] = []
    for (const file of walk(srcDir)) {
      if (file.endsWith("pm3-forge-header-ignored.test.ts")) continue
      const txt = readFileSync(file, "utf8")
      if (/x-active-org/i.test(txt)) offenders.push(file)
    }
    expect(offenders).toEqual([])
  })
})
```

Run it → expect PASS.

- [ ] **Step 3: Forge test (d) — revoked mid-session**

Create `src/tests/pm3-forge-revoked-mid-session.test.ts`:

```typescript
// PM3 FORGE TEST (d) — user is removed from their active org mid-session.
// The admin path that removes them MUST bump User.sessionVersion. On the
// next request: either (i) the sessionVersion drift gate fires (jwt < db)
// and withAuthScoped returns null, or (ii) the Membership row no longer
// exists and withAuthScoped returns null. Either path → 401.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    user: { findUnique: vi.fn() },
    membership: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/auth", () => ({ auth: vi.fn() }))

import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { auth } from "@/lib/auth"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(auth)

describe("PM3 forge test (d) — mid-session revocation returns null", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    invalidateSessionVersionCache("u1")
  })

  it("returns null when sessionVersion has been bumped server-side", async () => {
    mockAuth.mockResolvedValue({
      user: { id: "u1", activeOrganisationId: "org-A", sessionVersion: 0 },
    } as never)
    mockDb.user.findUnique.mockResolvedValue({ sessionVersion: 1 } as never) // bumped
    // Membership still exists, but the drift gate fires first
    mockDb.membership.findUnique.mockResolvedValue({} as never)

    expect(await withAuthScoped()).toBeNull()
  })

  it("returns null when Membership row has been deleted", async () => {
    mockAuth.mockResolvedValue({
      user: { id: "u1", activeOrganisationId: "org-A", sessionVersion: 0 },
    } as never)
    mockDb.user.findUnique.mockResolvedValue({ sessionVersion: 0 } as never) // not bumped
    mockDb.membership.findUnique.mockResolvedValue(null) // membership gone

    expect(await withAuthScoped()).toBeNull()
  })
})
```

Run → expect PASS.

- [ ] **Step 4: Add the two-membership fixture to `multi-tenant-fuzz.test.ts`**

Re-read `src/tests/multi-tenant-fuzz.test.ts` (the existing fixtures live near the top). After the existing `MEMBER_TEAM1_CTX`, add:

```typescript
// PM3: user with memberships in both org-test (active) and org-other.
// Used to exercise cross-org access against fuzz routes.
const MULTI_ORG_ACTIVE_TEST_CTX: AuthContext = {
  userId: "u-multi",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  organisationId: "org-test", // active — the org-other membership is invisible to ctx
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}
```

Then add a new `describe` block at the end of the file:

```typescript
describe("Fuzz Group 4 — PM3 cross-org resource access", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAuth.mockResolvedValue(MULTI_ORG_ACTIVE_TEST_CTX)
  })

  it("fuzz-pm3-01: GET /api/projects/:id for an org-other project → 404 when active=org-test", async () => {
    // ctx.organisationId === "org-test" → the where clause excludes the org-other project
    mockDb.project.findUnique.mockResolvedValue(null)
    const res = await getProject(
      req("http://localhost/api/projects/proj-in-org-other"),
      { params: { id: "proj-in-org-other" } }
    )
    expect(res.status).toBe(404)
  })
})
```

- [ ] **Step 5: Run all PM3 + fuzz tests**

Run: `npx vitest run src/tests/pm3-*.test.ts src/tests/multi-tenant-fuzz.test.ts`

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/tests/pm3-forge-*.test.ts src/tests/multi-tenant-fuzz.test.ts
git commit -m "[test](auth): PM3 — add forge tests (a/b/d) and two-membership fuzz case

Proves the boundary holds against cross-org resource access, forged
X-Active-Org headers (greppable trip-wire), and mid-session revocation."
```

---

## Task 5: Update remaining test fixtures (login + signup) for new login shape

**Files:**
- Modify: `src/tests/login.test.ts`

The login route now calls `db.membership.findFirst`. Existing login tests don't mock it.

- [ ] **Step 1: Re-read `src/tests/login.test.ts`**

- [ ] **Step 2: Add `membership` to the db mock and wire stubs**

In the top-of-file `vi.mock("@/lib/db", ...)`, add `membership: { findFirst: vi.fn() }` alongside `user`.

For every test case where a login is expected to succeed (e.g. the "returns 200 and sets session cookie on valid credentials" case), add:

```typescript
mockDb.membership.findFirst.mockResolvedValue({ organisationId: "org-test" } as never)
```

immediately before the `POST(...)` call.

- [ ] **Step 3: Add a new test — "401 when user exists but has no Membership"**

```typescript
it("returns 401 when user exists but has no Membership", async () => {
  mockDb.user.findUnique.mockResolvedValue(ACTIVE_USER as never)
  mockVerify.mockResolvedValue(true)
  mockDb.user.update.mockResolvedValue(ACTIVE_USER as never)
  mockDb.membership.findFirst.mockResolvedValue(null)

  const res = await POST(makeRequest({ email: "active@x.io", password: "x" }))
  expect(res.status).toBe(401)
  const body = await res.json()
  // Opaque error — never reveal that the membership row is missing
  expect(body.error).toBe("Invalid credentials")
})
```

- [ ] **Step 4: Run login tests**

Run: `npx vitest run src/tests/login.test.ts`

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/tests/login.test.ts
git commit -m "[test](auth): PM3 — wire Membership mock into login tests

Covers the new no-membership 401 path and stubs membership.findFirst in the
success cases."
```

---

## Task 6: Full-suite verification + ingest sanity-check

**Files:** none modified — verification step.

- [ ] **Step 1: Confirm ingest paths are still unaffected by re-reading their handlers**

Run: `grep -n "withAuthScoped\|session.user\|auth()" src/app/api/ingest/event/route.ts src/app/api/ingest/time/route.ts`

Expected: zero matches. These use AgentToken + HMAC, never the user session. PM3 should not have touched them. If anything appears, abort and investigate.

- [ ] **Step 2: Run the entire Vitest suite**

Run: `npm test`

Expected: all green. If anything fails, the most likely cause is an additional test file that mocked the old `next-auth.d.ts` JWT shape. Grep for it: `grep -rn "session.user.role\|session.user.organisationId\|session.user.teamId\|session.user.isLineManager" src/tests/` and patch each mock to use `activeOrganisationId` (and to provide `mockDb.membership.findUnique`).

- [ ] **Step 3: Run tsc**

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | wc -l`

Expected: matches the pre-PM3 baseline recorded at the start of Task 1.

- [ ] **Step 4: Manual smoke against the local dev server (optional but recommended)**

If desired, run `npm run dev`, log in, hit `/api/projects`, and confirm the response. The cookie should still be set; the dashboard should still load.

- [ ] **Step 5: Commit (verification-only — no code changes; skip if no fixtures changed)**

If Step 2 required patching extra test fixtures, commit them:

```bash
git add src/tests/<patched-files>
git commit -m "[test](auth): PM3 — patch remaining mocks for new JWT shape

Replaces session.user.organisationId/role/teamId/isLineManager mocks with
activeOrganisationId + membership.findUnique stubs."
```

---

## Task 7: Docs updates

**Files:**
- Modify: `docs/architecture.md`, `docs/decisions.md`, `docs/dataflow.md`, `docs/code.md`, `docs/risk.md`, `docs/glossary.md`, `docs/structure.md`

Per CLAUDE.md Rule 10, no PM3 task is done until docs reflect it.

- [ ] **Step 1: `docs/architecture.md` — Authentication section**

Find the Authentication section. Add or update a paragraph:

> **PM3 boundary (multi-org migration):** The JWT carries `sub`, `activeOrganisationId`, and `sessionVersion` only. The user's role, team, and line-manager flag are **not** in the JWT — they are looked up from the `Membership` table on every authenticated request by `withAuthScoped()`. The `Membership(userId, organisationId)` composite unique index serves both as the de-dup constraint and the proof: if no row matches the JWT's `activeOrganisationId`, the request is rejected (`null` ctx → 401). The `User.organisationId/role/teamId/isLineManager` columns are still written on signup as a PM6 rollback fallback but no production read path consults them.

- [ ] **Step 2: `docs/decisions.md` — new ADR**

Append:

```markdown
## ADR-PM3 — Tenancy boundary at withAuthScoped via Membership lookup

**Date:** 2026-06-21
**Status:** Accepted (PM3 shipped)

**Context:** Before PM3, the JWT carried `organisationId` as a trusted claim.
A user whose membership was revoked could continue to access org data until
their JWT expired (or sessionVersion was bumped). Cross-org access in a
future multi-org world would have been a JWT-tampering attack: change the
claim, the server would believe it.

**Decision:** The tenancy boundary moves into `withAuthScoped()`. The JWT
now carries `activeOrganisationId` only as a hint. On every authenticated
request, `withAuthScoped` performs a single `findUnique` against
`Membership(userId, organisationId)` (composite unique index from PM2).
Missing row → null ctx → 401. The role, team, and line-manager flag come
from the Membership row, not the JWT.

**Forge tests** (the proof, all four passing):
- (a) Member of A+B, active=B, request an A resource → 404 (org-scoped query excludes it).
- (b) `X-Active-Org` header — never read; grep trip-wire test asserts zero hits.
- (c) JWT tampered to claim membership in C (user not a member) → null ctx → 401.
- (d) Membership deleted mid-session OR sessionVersion bumped → null ctx → 401.

**Consequences:**
- One extra indexed read per authenticated request (the `@@unique` composite). Acceptable.
- `User.organisationId/role/teamId/isLineManager` columns kept as PM6 fallback; no read path consults them.
- Signup now wraps `User.create` + `Membership.create` in `$transaction` to keep the two rows consistent.
- The ~158 `ctx.role === "..."` sites are untouched; `AuthContext` shape is unchanged.

**Out of scope (PM3):** UI to switch active org (PM4), org-list endpoint (PM4), removing the legacy `User.*` columns (PM6), changing the ingest path's AgentToken→org binding (separate stream).
```

- [ ] **Step 3: `docs/dataflow.md` — auth flow update**

Update the "Auth request flow" section (or add it if missing). Include the membership lookup as a labelled step between "JWT decode" and "route handler runs".

- [ ] **Step 4: `docs/code.md` — withAuthScoped signature**

Update the `withAuthScoped()` reference to note: `ctx.role`, `ctx.teamId`, `ctx.isLineManager`, `ctx.organisationId` are now sourced from the `Membership` row, not the JWT. The function still returns `AuthContext | null`; `null` means 401.

- [ ] **Step 5: `docs/risk.md` — close partial-mitigation note**

Find the multi-tenancy risk entry. Update its mitigation status: "PM3 closes the JWT-trust gap. Residual: AgentToken-bound ingest paths still embed org context at token issue time — addressed separately."

- [ ] **Step 6: `docs/glossary.md` — promote Membership**

Update the `Membership` entry: "One row per `(user, organisation)` pair. **PM3:** the tenancy proof — `withAuthScoped` verifies this row exists on every authenticated request. The JWT's `activeOrganisationId` is a hint; the row is the credential."

- [ ] **Step 7: `docs/structure.md` — list new test files**

Add under `src/tests/`:
- `pm3-forge-tampered-jwt.test.ts` — forge test (c)
- `pm3-forge-cross-org-resource.test.ts` — forge test (a)
- `pm3-forge-header-ignored.test.ts` — forge test (b)
- `pm3-forge-revoked-mid-session.test.ts` — forge test (d)

- [ ] **Step 8: Commit docs**

```bash
git add docs/
git commit -m "[docs](auth): PM3 — record Membership-as-tenancy-boundary

ADR-PM3, glossary promotion, architecture+dataflow updates, structure
listing for the four forge tests."
```

---

## Self-Review

**Spec coverage check** — every requirement in the PM3 spec mapped to a task:

| Spec requirement | Task |
|---|---|
| JWT: `organisationId` → `activeOrganisationId`; remove `role`/`teamId`/`isLineManager` | Task 1 (Step 3, Step 4, Step 6) |
| Login: pick first Membership by createdAt ASC, stamp `activeOrganisationId` | Task 1 (Step 6) |
| Login does not put role/team in JWT | Task 1 (Step 6) |
| Signup: User + Membership in one transaction | Task 2 |
| Signup: self → MANAGER membership; invitation → role/team from invite | Task 2 (Step 4) |
| Signup: keep writing old `User.*` columns (PM6 fallback) | Task 2 (Step 4) |
| `withAuthScoped`: look up (userId, activeOrganisationId) → Membership; null → null | Task 1 (Step 5) |
| `withAuthScoped`: `ctx.role/teamId/isLineManager` come FROM Membership | Task 1 (Step 5) |
| `sessionVersion` drift gate unchanged | Task 1 (Step 5) |
| Forge test (a) — cross-org resource → 404 | Task 4 (Step 1) + Task 4 (Step 4 fuzz case) |
| Forge test (b) — `X-Active-Org` header ignored | Task 4 (Step 2) |
| Forge test (c) — tampered JWT → null | Task 1 (Step 1, Step 7) |
| Forge test (d) — mid-session revocation → 401 | Task 4 (Step 3) |
| Two-membership fixture in `multi-tenant-fuzz.test.ts` | Task 4 (Step 4) |
| Existing isolation tests still green | Task 6 (Step 2) |
| Anti-forge guard ONLY in `withAuthScoped` (no other handler reads JWT claim) | Task 3 |
| ~158 `ctx.role` sites untouched | (no task — verified by `git diff --stat`) |
| Ingest paths unaffected | Task 6 (Step 1) |
| Single indexed read per request | Task 1 (Step 5 — `findUnique` on composite unique) |
| tsc baseline unchanged | Task 1 (Step 8), Task 6 (Step 3) |
| Full suite green | Task 6 (Step 2) |

**Placeholder scan** — no "TBD", "add appropriate error handling", or "fill in details" in any task body. Every code block is complete and copy-pasteable.

**Type consistency** — `AuthContext` shape is identical to current (`userId`, `organisationId`, `role`, `teamId`, `isLineManager`, `scope`). JWT/Session shape change: `organisationId` → `activeOrganisationId` (consistently applied across `next-auth.d.ts`, `auth.ts` session callback, login route `encode()`, and `withAuthScoped` read). `Membership` model fields (`userId`, `organisationId`, `role`, `teamId`, `isLineManager`) used identically across signup, withAuthScoped, login, and test fixtures.

**Stop-point delivers proof** — Task 1 ends with the tampered-JWT forge test passing, which is the most direct demonstration that the boundary holds. The user-review checkpoint is exactly at the point where a reviewer can see the JWT being decoded, the membership being looked up, and a forged-org-claim request being rejected. ✓

---

## Execution Handoff

Plan saved to `docs/superpowers/plans/2026-06-21-pm3-withauthscoped-membership-reads.md`.

Per the user's instruction, the agent should execute **Task 1 only** in this session, then stop for review.
