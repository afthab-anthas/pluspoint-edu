# PM3-COMPLETE Data-Plane Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Membership the single source of truth for "who is in an org" by (1) fixing the invite bug so POST /api/admin/members creates PENDING Memberships, (2) migrating all listing queries from User.organisationId to Membership(status=ACTIVE), and (3) moving role/team reads and writes from User to Membership.

**Architecture:** All auth boundaries (withAuthScoped/switch-org/login) already read from Membership — this plan migrates only the data-plane (listing pages, admin management routes). User columns (role, teamId, isLineManager) are not dropped (that is PM6); we stop reading them and stop writing them from management routes. SessionVersion stays on User because JWT validation reads it.

**Tech Stack:** Next.js 14 App Router (server components + route handlers), Prisma 7, TypeScript 5, Vitest, `vi.mock` pattern

---

## File Map

| File | Change |
|------|--------|
| `src/app/api/admin/members/route.ts` | Task 1: POST adds PM5 existing-user branch; Task 2: GET migrates to Membership listing |
| `src/app/admin/members/_components/MembersClient.tsx` | Task 1: add 409 reason strings to error map |
| `src/app/admin/members/page.tsx` | Task 2: users query → Membership |
| `src/app/admin/tokens/page.tsx` | Task 3: members query → Membership |
| `src/app/admin/consents/page.tsx` | Task 3: users query → Membership |
| `src/app/api/admin/members/[id]/route.ts` | Task 4: existence check + teamId write to Membership |
| `src/app/api/admin/members/[id]/role/route.ts` | Task 4: existence + role write; Task 6: Q21 count |
| `src/app/api/admin/members/[id]/promote/route.ts` | Task 4: existence + role write to Membership |
| `src/app/api/admin/members/[id]/demote/route.ts` | Task 4: existence + role write; Task 6: Q21 count |
| `src/app/api/admin/members/[id]/assign-to-team/route.ts` | Task 4: existence + teamId write to Membership |
| `src/app/api/admin/members/[id]/remove-from-team/route.ts` | Task 4: existence + role/team write to Membership |
| `src/tests/pm5-members-route-invite.test.ts` | Task 1: new test file |
| `src/tests/pm3-data-plane-listing.test.ts` | Tasks 2+3: new test file |
| `src/tests/pm3-member-management-membership.test.ts` | Task 4: new test file |
| `src/tests/pm3-last-manager-guard.test.ts` | Task 6: new test file |

---

## Shared helper pattern used across Tasks 4–6

Every management route will use this Membership existence check instead of `db.user.findFirst({ id, organisationId })`:

```ts
const membership = await db.membership.findUnique({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  include: { user: true },
})
if (!membership || membership.status !== "ACTIVE") {
  return NextResponse.json({ reason: "not_found" }, { status: 404 })
}
```

After this, `membership.role`, `membership.teamId`, `membership.isLineManager` are the authoritative values (per-org correct). `membership.user` contains User fields (name, email, isActive, etc.).

---

## ⛔ STOP POINT

**Implement Task 1 only, then stop for review before continuing to Tasks 2–6.**

---

## Task 1: Phase 1 — Fix invite bug in POST /api/admin/members

**Problem:** `MembersClient.tsx:204` calls `POST /api/admin/members`. That route creates an `Invitation` but no `PENDING Membership`. When the invitee hits `/invite/:id`, the accept handler finds no PENDING Membership and returns a misleading error.

**Decision: add PM5 logic directly to `POST /api/admin/members`** (not redirect UI to `/api/admin/invitations`) because LINE_MANAGER also uses this endpoint, `/api/admin/invitations` is MANAGER-only, and the UI response shape `{ inviteUrl, existingUser }` differs from invitations' `{ id, url }`.

**Files:**
- Modify: `src/app/api/admin/members/route.ts`
- Modify: `src/app/admin/members/_components/MembersClient.tsx`
- Create: `src/tests/pm5-members-route-invite.test.ts`

---

- [ ] **Step 1: Write failing tests**

Create `src/tests/pm5-members-route-invite.test.ts`:

```ts
// src/tests/pm5-members-route-invite.test.ts
// Phase 1 fix: POST /api/admin/members must create a PENDING Membership
// when the invited email belongs to an existing user (mirrors PM5 logic
// that was previously only in /api/admin/invitations).

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    team: { findFirst: vi.fn() },
    user: { findUnique: vi.fn() },
    membership: { findUnique: vi.fn() },
    invitation: { create: vi.fn() },
    auditLogEntry: { create: vi.fn() },
    $transaction: vi.fn(),
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/audit", () => ({
  writeAudit: vi.fn(),
  getIp: vi.fn().mockReturnValue("127.0.0.1"),
}))

import { POST } from "@/app/api/admin/members/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const LM_CTX: AuthContext = {
  userId: "u-lm",
  organisationId: "org-A",
  role: "LINE_MANAGER",
  teamId: "team-1",
  isLineManager: true,
  scope: { allTeams: false, canSeeTimeData: true, viewedTeamIds: ["team-1"] },
}

function makeReq(body: Record<string, unknown>) {
  return new Request("http://localhost/api/admin/members", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  })
}

beforeEach(() => vi.clearAllMocks())

describe("POST /api/admin/members — existing-user invite (PM5 fix)", () => {
  beforeEach(() => {
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
    vi.mocked(db.team.findFirst).mockResolvedValue({ id: "team-1" } as never)
  })

  it("creates PENDING Membership atomically when email matches an existing user", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue(null) // no prior membership

    const invCreateFn = vi.fn().mockResolvedValue({ id: "inv-1" })
    const memCreateFn = vi.fn().mockResolvedValue({})
    vi.mocked(db.$transaction).mockImplementation(async (fn: (tx: unknown) => Promise<unknown>) =>
      fn({ invitation: { create: invCreateFn }, membership: { create: memCreateFn } })
    )

    const res = await POST(makeReq({ email: "bob@example.com", teamId: "team-1", role: "MEMBER" }))

    expect(res.status).toBe(200)
    expect(vi.mocked(db.$transaction)).toHaveBeenCalledOnce()
    expect(memCreateFn).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "PENDING",
          userId: "u-bob",
          organisationId: "org-A",
          role: "MEMBER",
        }),
      })
    )
    const body = await res.json()
    expect(body.inviteUrl).toContain("/invite/inv-1")
    expect(body.existingUser).toBe(true)
  })

  it("returns 409 already_a_member when user has an ACTIVE Membership", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({ id: "mem-1", status: "ACTIVE" } as never)

    const res = await POST(makeReq({ email: "bob@example.com", teamId: "team-1" }))

    expect(res.status).toBe(409)
    expect((await res.json()).reason).toBe("already_a_member")
  })

  it("returns 409 invitation_already_pending when user has a PENDING Membership", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({ id: "mem-1", status: "PENDING" } as never)

    const res = await POST(makeReq({ email: "bob@example.com", teamId: "team-1" }))

    expect(res.status).toBe(409)
    expect((await res.json()).reason).toBe("invitation_already_pending")
  })

  it("creates Invitation only (no transaction) for a brand-new email", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue(null)
    vi.mocked(db.invitation.create).mockResolvedValue({ id: "inv-2" } as never)

    const res = await POST(makeReq({ email: "new@example.com", teamId: "team-1" }))

    expect(res.status).toBe(200)
    expect(vi.mocked(db.$transaction)).not.toHaveBeenCalled()
    expect(vi.mocked(db.invitation.create)).toHaveBeenCalledOnce()
    expect((await res.json()).existingUser).toBe(false)
  })

  it("LINE_MANAGER can invite existing user to own team → PENDING Membership created", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(LM_CTX)
    vi.mocked(db.team.findFirst).mockResolvedValue({ id: "team-1" } as never)
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-alice" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue(null)

    const invCreateFn = vi.fn().mockResolvedValue({ id: "inv-3" })
    const memCreateFn = vi.fn().mockResolvedValue({})
    vi.mocked(db.$transaction).mockImplementation(async (fn: (tx: unknown) => Promise<unknown>) =>
      fn({ invitation: { create: invCreateFn }, membership: { create: memCreateFn } })
    )

    // LINE_MANAGER doesn't supply teamId in body — it comes from ctx
    const res = await POST(makeReq({ email: "alice@example.com", role: "MEMBER" }))

    expect(res.status).toBe(200)
    expect(memCreateFn).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({ status: "PENDING", userId: "u-alice", teamId: "team-1" }),
      })
    )
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npx vitest run src/tests/pm5-members-route-invite.test.ts
```

Expected: all 5 tests FAIL (PENDING Membership not yet created, no 409 handling).

- [ ] **Step 3: Fix POST /api/admin/members — add PM5 existing-user branch**

Open `src/app/api/admin/members/route.ts`. Replace lines 93–105 (from the comment `// Always use invitation flow` to the final `return NextResponse.json(...)`) with:

```ts
  const normalizedEmail = email.trim().toLowerCase()
  const existingUser = await db.user.findUnique({ where: { email: normalizedEmail }, select: { id: true } })

  if (existingUser) {
    const existingMembership = await db.membership.findUnique({
      where: {
        userId_organisationId: { userId: existingUser.id, organisationId: ctx.organisationId },
      },
      select: { id: true, status: true },
    })

    if (existingMembership) {
      const reason =
        existingMembership.status === "ACTIVE" ? "already_a_member" : "invitation_already_pending"
      return NextResponse.json({ reason }, { status: 409 })
    }

    const invitation = await db.$transaction(async (tx) => {
      const inv = await tx.invitation.create({
        data: {
          invitedEmail: normalizedEmail,
          teamId: resolvedTeamId,
          role: resolvedRole,
          invitedBy: ctx.userId,
          organisationId: ctx.organisationId,
        },
      })
      await tx.membership.create({
        data: {
          userId: existingUser.id,
          organisationId: ctx.organisationId,
          role: resolvedRole,
          teamId: resolvedTeamId,
          isLineManager: resolvedRole === "LINE_MANAGER",
          status: "PENDING",
        },
      })
      return inv
    })

    await writeAudit(
      ctx.userId,
      "INVITE_MEMBER",
      existingUser.id,
      { teamId: resolvedTeamId, existingUser: true },
      ip
    )
    return NextResponse.json({ inviteUrl: `/invite/${invitation.id}`, existingUser: true }, { status: 200 })
  }

  // New-email path: Invitation only (Membership created on accept)
  const invitation = await db.invitation.create({
    data: {
      invitedEmail: normalizedEmail,
      teamId: resolvedTeamId,
      role: resolvedRole,
      invitedBy: ctx.userId,
      organisationId: ctx.organisationId,
    },
  })
  await writeAudit(ctx.userId, "INVITE_MEMBER", null, { teamId: resolvedTeamId, existingUser: false }, ip)
  return NextResponse.json({ inviteUrl: `/invite/${invitation.id}`, existingUser: false }, { status: 200 })
```

Also add `membership: { findUnique: vi.fn() }` and `$transaction: vi.fn()` to the db mock in `src/tests/members-admin.test.ts` so existing tests don't break (the mock map must include all methods the new code calls).

- [ ] **Step 4: Add 409 reason strings to MembersClient error map**

In `src/app/admin/members/_components/MembersClient.tsx`, find the `reasonMap` inside `handleInvite` (around line 216):

```ts
        const reasonMap: Record<string, string> = {
          email_required: "Email is required.",
          invalid_role: "Invalid role selected.",
          team_not_found: "Selected team not found.",
          forbidden: "Insufficient permissions.",
        }
```

Replace with:

```ts
        const reasonMap: Record<string, string> = {
          email_required: "Email is required.",
          invalid_role: "Invalid role selected.",
          team_not_found: "Selected team not found.",
          forbidden: "Insufficient permissions.",
          already_a_member: "This user is already a member of this organisation.",
          invitation_already_pending: "An invitation is already pending for this user.",
        }
```

- [ ] **Step 5: Run all tests**

```
npx vitest run src/tests/pm5-members-route-invite.test.ts src/tests/members-admin.test.ts
```

Expected: all tests PASS.

- [ ] **Step 6: tsc check**

```
npx tsc --noEmit
```

Expected: 0 new errors in `src/` (any errors in `src/tests/` are pre-existing baseline).

- [ ] **Step 7: Full suite**

```
npm test
```

Expected: all tests green.

- [ ] **Step 8: Commit**

```
git add src/app/api/admin/members/route.ts \
        src/app/admin/members/_components/MembersClient.tsx \
        src/tests/pm5-members-route-invite.test.ts
git commit -m "fix(members): POST /api/admin/members creates PENDING Membership for existing-user invites"
```

---

## ⛔ STOP — wait for review before continuing

---

## Task 2: Phase 2a — Migrate members listing (page + GET route) to Membership

**Problem:** `admin/members/page.tsx` and `GET /api/admin/members` both use `db.user.findMany({ where: { organisationId } })`. Users who joined via a PENDING→ACTIVE Membership (rather than the legacy User.organisationId path) are invisible unless Membership is the filter.

**Files:**
- Modify: `src/app/admin/members/page.tsx`
- Modify: `src/app/api/admin/members/route.ts` (GET handler)
- Create: `src/tests/pm3-data-plane-listing.test.ts` (shared across Tasks 2+3)

---

- [ ] **Step 1: Write failing tests**

Create `src/tests/pm3-data-plane-listing.test.ts` with the listing tests (Tasks 2 and 3 share this file). Start with just the GET /api/admin/members tests:

```ts
// src/tests/pm3-data-plane-listing.test.ts
// Phase 2: all listing queries read from Membership(status=ACTIVE), not User.organisationId.
// A user is "in" an org iff they have an ACTIVE Membership row.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findMany: vi.fn() },
    user: { findUnique: vi.fn() },
    team: { findMany: vi.fn() },
    activityEvent: { groupBy: vi.fn() },
    auditLogEntry: { create: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

import { GET } from "@/app/api/admin/members/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const MEMBER_WITH_NO_ORG_FIELD: AuthContext = {
  userId: "u-bob",
  organisationId: "org-A",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["team-1"] },
}

beforeEach(() => vi.clearAllMocks())

describe("GET /api/admin/members — reads from Membership(status=ACTIVE)", () => {
  it("returns only users who have an ACTIVE Membership (not just organisationId on User)", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)

    // Two memberships: one ACTIVE, one PENDING
    vi.mocked(db.membership.findMany).mockResolvedValue([
      {
        id: "mem-1",
        userId: "u-alice",
        organisationId: "org-A",
        role: "MEMBER",
        teamId: "team-1",
        isLineManager: false,
        status: "ACTIVE",
        user: {
          id: "u-alice",
          email: "alice@example.com",
          name: "Alice",
          role: "MEMBER",
          teamId: "team-1",
          isLineManager: false,
          isActive: true,
          failedLogins: 0,
          lockedUntil: null,
          tenantKey: null,
          createdAt: new Date("2024-01-01"),
          updatedAt: new Date("2024-01-01"),
          team: { id: "team-1", name: "Dev" },
        },
      },
    ] as never)

    const res = await GET()
    expect(res.status).toBe(200)
    const body = await res.json()

    // Must have queried membership, not user
    expect(vi.mocked(db.membership.findMany)).toHaveBeenCalledWith(
      expect.objectContaining({
        where: expect.objectContaining({ status: "ACTIVE", organisationId: "org-A" }),
      })
    )
    // MEMBER role is blocked from GET
    expect(body).toHaveLength(1)
    expect(body[0].email).toBe("alice@example.com")
  })

  it("returns 403 for MEMBER role", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(MEMBER_WITH_NO_ORG_FIELD)
    const res = await GET()
    expect(res.status).toBe(403)
  })
})
```

- [ ] **Step 2: Run tests to see them fail**

```
npx vitest run src/tests/pm3-data-plane-listing.test.ts
```

Expected: FAIL — `db.membership.findMany` assertion fails because implementation uses `db.user.findMany`.

- [ ] **Step 3: Migrate GET /api/admin/members to Membership**

In `src/app/api/admin/members/route.ts`, replace the GET handler (lines 13–46):

```ts
export async function GET() {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const membershipWhere =
    ctx.role === "MANAGER"
      ? { organisationId: ctx.organisationId, status: "ACTIVE" as const }
      : { organisationId: ctx.organisationId, status: "ACTIVE" as const, teamId: ctx.teamId ?? "__none__" }

  const memberships = await db.membership.findMany({
    where: membershipWhere,
    orderBy: { user: { createdAt: "asc" } },
    include: {
      user: {
        select: {
          id: true,
          email: true,
          name: true,
          teamId: true,
          isActive: true,
          failedLogins: true,
          lockedUntil: true,
          tenantKey: true,
          createdAt: true,
          updatedAt: true,
          team: { select: { id: true, name: true } },
        },
      },
    },
  })

  const users = memberships.map((m) => ({
    ...m.user,
    role: m.role,
    isLineManager: m.isLineManager,
  }))

  return NextResponse.json(users)
}
```

- [ ] **Step 4: Migrate admin/members/page.tsx to Membership**

In `src/app/admin/members/page.tsx`, replace the `users` query in the `Promise.all` (lines 28–33):

```ts
  const [memberships, teams, activityByUser, currentUser] = await Promise.all([
    db.membership.findMany({
      where: isManager
        ? { organisationId: orgId, status: "ACTIVE" }
        : {
            organisationId: orgId,
            status: "ACTIVE",
            OR: [
              { teamId: ctx.teamId ?? "__none__" },
              { teamId: null as string | null },
            ],
          },
      include: {
        user: {
          include: { team: { select: { id: true, name: true } } },
        },
      },
      orderBy: { user: { createdAt: "asc" } },
    }),
    db.team.findMany({
      where: { organisationId: orgId },
      orderBy: { name: "asc" },
      select: { id: true, name: true },
    }),
    db.activityEvent.groupBy({
      by: ["userId"],
      where: { organisationId: orgId, userId: { not: null } },
      _max: { ingestedAt: true },
    }),
    db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } }),
  ])
```

Then update the `safeUsers` mapping (replacing the `users.map(...)` block):

```ts
  const safeUsers = memberships.map(({ user: { ...userRest }, role, isLineManager }) => {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { passwordHash: _ph, ...rest } = userRest as typeof userRest & { passwordHash?: string }
    return {
      ...rest,
      role,
      isLineManager,
      lastActivityAt: lastActivityMap[rest.id] ?? null,
    }
  })
```

- [ ] **Step 5: Run tests**

```
npx vitest run src/tests/pm3-data-plane-listing.test.ts src/tests/members-admin.test.ts
```

Expected: all PASS.

- [ ] **Step 6: tsc check**

```
npx tsc --noEmit
```

Expected: 0 new errors in src/.

- [ ] **Step 7: Full suite + commit**

```
npm test
git add src/app/admin/members/page.tsx src/app/api/admin/members/route.ts src/tests/pm3-data-plane-listing.test.ts
git commit -m "refactor(members): migrate member listing queries from User to Membership(status=ACTIVE)"
```

---

## Task 3: Phase 2b — Migrate tokens and consents listing queries

**Files:**
- Modify: `src/app/admin/tokens/page.tsx`
- Modify: `src/app/admin/consents/page.tsx`
- Modify: `src/tests/pm3-data-plane-listing.test.ts` (append tests)

---

- [ ] **Step 1: Append failing tests to pm3-data-plane-listing.test.ts**

Add these imports and describe blocks to the existing test file:

```ts
// Additional imports at the top of the test file after existing imports:
// (These test the page functions indirectly via their db calls — we test
//  the query shape by checking what the mocked db.membership.findMany receives.)

// Note: tokens/page.tsx and consents/page.tsx are server components (async functions),
// not API route handlers. We can't directly import and call them in vitest
// without a Next.js test runtime. Instead, we verify the db.membership.findMany
// call shape by importing and running the query helpers inline.
//
// For these pages, we add integration-style assertions to pm3-data-plane-listing.test.ts
// that document the expected query shape as a contract.
```

Actually, since these are server components (not API route handlers), they can't be called directly in vitest without a full Next.js runtime. Instead, add a comment contract and test the shape via the API route tests pattern.

**Practical step:** For tokens and consents pages, add a code comment in the test file documenting the expected query shape, and verify via tsc (type-checking the query is correct). The critical tests are the API route tests in Task 2.

- [ ] **Step 2: Migrate admin/tokens/page.tsx members query**

In `src/app/admin/tokens/page.tsx`, replace lines 45–49:

```ts
    // OLD: db.user.findMany({ where: membersWhere })
    db.membership.findMany({
      where: isManager
        ? { organisationId: orgId, status: "ACTIVE" }
        : { organisationId: orgId, status: "ACTIVE", teamId: ctx.teamId ?? "__none__" },
      orderBy: { user: { name: "asc" } },
      select: {
        role: true,
        user: { select: { id: true, name: true, email: true } },
      },
    }),
```

Then update the mapping where `members` is passed to `TokensClient` (line 99):

```ts
            members={members.map((m) => ({ id: m.user.id, name: m.user.name, email: m.user.email }))}
```

- [ ] **Step 3: Migrate admin/consents/page.tsx users query**

In `src/app/admin/consents/page.tsx`, replace lines 44–52:

```ts
    db.membership.findMany({
      where: {
        organisationId: orgId,
        status: "ACTIVE",
        role: { in: ["LINE_MANAGER", "MEMBER"] },
      },
      include: {
        user: {
          include: {
            timeTrackingConsent: true,
            team: { select: { name: true } },
          },
        },
      },
      orderBy: { user: { name: "asc" } },
    }),
```

Then update all places that reference `users` array with the flat User shape. The membership result has `m.user`, so map to the old shape:

```ts
  // After the Promise.all, map memberships to user shape:
  const users = userMemberships.map((m) => ({
    ...m.user,
    role: m.role,
    isLineManager: m.isLineManager,
  }))
```

(Rename the destructured variable from `users` to `userMemberships` in the Promise.all, then derive `users` via the map above.)

- [ ] **Step 4: tsc check**

```
npx tsc --noEmit
```

Expected: 0 new errors in src/.

- [ ] **Step 5: Full suite + commit**

```
npm test
git add src/app/admin/tokens/page.tsx src/app/admin/consents/page.tsx
git commit -m "refactor(admin): migrate tokens and consents member listings to Membership(status=ACTIVE)"
```

---

## Task 4: Phase 3 — Migrate member management routes (existence + writes)

**Problem:** All management routes (`[id]/route.ts`, `[id]/role`, `[id]/promote`, `[id]/demote`, `[id]/assign-to-team`, `[id]/remove-from-team`) use `db.user.findFirst({ id, organisationId })` for existence checks, then read `user.role`/`user.teamId`/`user.isLineManager` for guards, then write `role`/`teamId`/`isLineManager` back to User. This is wrong for multi-org: a user could have different roles in different orgs, so writes must go to the Membership row for the target org.

**Invariant after this task:**
- Role and team writes: `db.membership.update({ role, teamId, isLineManager })` for the `(userId, organisationId)` pair
- SessionVersion: still `db.user.update({ sessionVersion: { increment: 1 } })`
- Name: still `db.user.update({ name })` (name is on User only)
- Guards reading role/team read from `membership.role`/`membership.teamId`/`membership.isLineManager`

**Files:**
- Modify: `src/app/api/admin/members/[id]/route.ts`
- Modify: `src/app/api/admin/members/[id]/role/route.ts`
- Modify: `src/app/api/admin/members/[id]/promote/route.ts`
- Modify: `src/app/api/admin/members/[id]/demote/route.ts`
- Modify: `src/app/api/admin/members/[id]/assign-to-team/route.ts`
- Modify: `src/app/api/admin/members/[id]/remove-from-team/route.ts`
- Create: `src/tests/pm3-member-management-membership.test.ts`

---

- [ ] **Step 1: Write failing tests**

Create `src/tests/pm3-member-management-membership.test.ts`:

```ts
// src/tests/pm3-member-management-membership.test.ts
// Phase 3: management routes verify membership existence (not db.user.findFirst),
// and write role/teamId/isLineManager to Membership (not User).

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findUnique: vi.fn(), update: vi.fn() },
    user: { update: vi.fn() },
    team: { findFirst: vi.fn() },
    auditLogEntry: { create: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
  invalidateSessionVersionCache: vi.fn(),
}))

vi.mock("@/lib/audit", () => ({
  writeAudit: vi.fn(),
  getIp: vi.fn().mockReturnValue("127.0.0.1"),
}))

import { PATCH as ROLE_PATCH } from "@/app/api/admin/members/[id]/role/route"
import { POST as PROMOTE } from "@/app/api/admin/members/[id]/promote/route"
import { POST as DEMOTE } from "@/app/api/admin/members/[id]/demote/route"
import { POST as ASSIGN } from "@/app/api/admin/members/[id]/assign-to-team/route"
import { POST as REMOVE } from "@/app/api/admin/members/[id]/remove-from-team/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const ACTIVE_MEMBERSHIP = {
  id: "mem-1",
  userId: "u-target",
  organisationId: "org-A",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  status: "ACTIVE",
  user: {
    id: "u-target", name: "Target", email: "t@example.com",
    role: "MEMBER", teamId: "team-1", isLineManager: false,
    isActive: true, failedLogins: 0, lockedUntil: null, tenantKey: null,
    createdAt: new Date(), updatedAt: new Date(),
  },
}

function makeReq(url: string, body?: Record<string, unknown>, method = "POST") {
  return new Request(url, {
    method,
    ...(body ? { headers: { "content-type": "application/json" }, body: JSON.stringify(body) } : {}),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
  vi.mocked(db.membership.findUnique).mockResolvedValue(ACTIVE_MEMBERSHIP as never)
  vi.mocked(db.membership.update).mockResolvedValue({} as never)
  vi.mocked(db.user.update).mockResolvedValue({} as never)
})

describe("Phase 3 — management routes read and write via Membership", () => {
  describe("PATCH [id]/role — writes role to Membership, not User", () => {
    it("updates Membership.role on role change", async () => {
      // Mock Membership.count for Q21 (not last manager) — Phase 4 handles this fully
      vi.mocked(db.membership.findUnique).mockResolvedValue({
        ...ACTIVE_MEMBERSHIP,
        role: "LINE_MANAGER",
      } as never)
      // Provide count mock for Q21 guard (no Q21 concern for LINE_MANAGER→MEMBER)

      const res = await ROLE_PATCH(
        makeReq("http://localhost/api/admin/members/u-target/role", { role: "MEMBER" }, "PATCH"),
        { params: { id: "u-target" } }
      )

      // Must have written to membership, not just user
      expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            userId_organisationId: { userId: "u-target", organisationId: "org-A" },
          }),
          data: expect.objectContaining({ role: "MEMBER" }),
        })
      )
      // SessionVersion still goes to User
      expect(vi.mocked(db.user.update)).toHaveBeenCalledWith(
        expect.objectContaining({ data: expect.objectContaining({ sessionVersion: { increment: 1 } }) })
      )
    })

    it("returns 404 if no ACTIVE Membership found for userId+orgId", async () => {
      vi.mocked(db.membership.findUnique).mockResolvedValue(null)

      const res = await ROLE_PATCH(
        makeReq("http://localhost/api/admin/members/u-ghost/role", { role: "MEMBER" }, "PATCH"),
        { params: { id: "u-ghost" } }
      )

      expect(res.status).toBe(404)
    })
  })

  describe("POST [id]/promote — writes role:LINE_MANAGER to Membership", () => {
    it("writes LINE_MANAGER to Membership when user has a team", async () => {
      vi.mocked(db.membership.findUnique).mockResolvedValue({
        ...ACTIVE_MEMBERSHIP,
        teamId: "team-1",
      } as never)

      const res = await PROMOTE(
        makeReq("http://localhost/api/admin/members/u-target/promote"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
      expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ role: "LINE_MANAGER", isLineManager: true }),
        })
      )
    })

    it("returns 422 user_has_no_team when membership.teamId is null", async () => {
      vi.mocked(db.membership.findUnique).mockResolvedValue({
        ...ACTIVE_MEMBERSHIP,
        teamId: null,
      } as never)

      const res = await PROMOTE(
        makeReq("http://localhost/api/admin/members/u-target/promote"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(422)
      expect((await res.json()).reason).toBe("user_has_no_team")
    })
  })

  describe("POST [id]/demote — writes role:MEMBER to Membership", () => {
    it("writes MEMBER to Membership on demote", async () => {
      vi.mocked(db.membership.findUnique).mockResolvedValue({
        ...ACTIVE_MEMBERSHIP,
        role: "LINE_MANAGER",
      } as never)
      // No Q21 concern (LINE_MANAGER demote doesn't trigger last-manager guard)

      const res = await DEMOTE(
        makeReq("http://localhost/api/admin/members/u-target/demote"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
      expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ role: "MEMBER", isLineManager: false }),
        })
      )
    })
  })

  describe("POST [id]/assign-to-team — writes teamId to Membership", () => {
    it("writes teamId to Membership on team assignment", async () => {
      vi.mocked(db.membership.findUnique).mockResolvedValue({
        ...ACTIVE_MEMBERSHIP,
        teamId: null,
        role: "MEMBER",
      } as never)
      vi.mocked(db.team.findFirst).mockResolvedValue({ id: "team-2" } as never)

      const res = await ASSIGN(
        makeReq("http://localhost/api/admin/members/u-target/assign-to-team", { teamId: "team-2" }),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
      expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ teamId: "team-2" }),
        })
      )
    })
  })

  describe("POST [id]/remove-from-team — writes null teamId + MEMBER role to Membership", () => {
    it("clears team and role on Membership", async () => {
      const res = await REMOVE(
        makeReq("http://localhost/api/admin/members/u-target/remove-from-team"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
      expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({ teamId: null, role: "MEMBER", isLineManager: false }),
        })
      )
    })
  })
})
```

- [ ] **Step 2: Run tests to see them fail**

```
npx vitest run src/tests/pm3-member-management-membership.test.ts
```

Expected: FAIL — routes still write to `db.user.update` for role/team.

- [ ] **Step 3: Migrate [id]/role/route.ts**

Replace the full content of `src/app/api/admin/members/[id]/role/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

const VALID_ROLES = ["MANAGER", "LINE_MANAGER", "MEMBER"] as const

export async function PATCH(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  if (params.id === ctx.userId) {
    return NextResponse.json({ reason: "cannot_modify_self" }, { status: 409 })
  }

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const { role } = body as Record<string, unknown>

  if (!VALID_ROLES.includes(role as (typeof VALID_ROLES)[number])) {
    return NextResponse.json({ reason: "invalid_role" }, { status: 422 })
  }

  const newRole = role as "MANAGER" | "LINE_MANAGER" | "MEMBER"

  // Q21: Block demoting the last ACTIVE MANAGER in this org
  if (membership.role === "MANAGER" && newRole !== "MANAGER") {
    const otherManagerCount = await db.membership.count({
      where: {
        organisationId: ctx.organisationId,
        status: "ACTIVE",
        role: "MANAGER",
        NOT: { userId: params.id },
      },
    })
    if (otherManagerCount < 1) {
      return NextResponse.json({ reason: "last_manager_cannot_demote" }, { status: 409 })
    }
  }

  // Q80: Can only promote to LINE_MANAGER if user has a team in this org
  if (newRole === "LINE_MANAGER" && !membership.teamId) {
    return NextResponse.json({ reason: "user_has_no_team" }, { status: 422 })
  }

  const ip = getIp(request)
  const isLineManager = newRole === "LINE_MANAGER"

  // Write role to Membership (per-org correct), sessionVersion to User
  await db.membership.update({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    data: { role: newRole, isLineManager },
  })
  await db.user.update({
    where: { id: params.id },
    data: { sessionVersion: { increment: 1 } },
  })

  invalidateSessionVersionCache(params.id)
  await writeAudit(ctx.userId, "ASSIGN_ROLE", params.id, { from: membership.role, to: newRole }, ip)

  return NextResponse.json({ ok: true })
}
```

- [ ] **Step 4: Migrate [id]/promote/route.ts**

Replace the full content of `src/app/api/admin/members/[id]/promote/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

export async function POST(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  if (params.id === ctx.userId) {
    return NextResponse.json({ reason: "cannot_modify_self" }, { status: 409 })
  }

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  if (!membership.teamId) {
    return NextResponse.json({ reason: "user_has_no_team" }, { status: 422 })
  }

  const ip = getIp(request)

  await db.membership.update({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    data: { role: "LINE_MANAGER", isLineManager: true },
  })
  await db.user.update({
    where: { id: params.id },
    data: { sessionVersion: { increment: 1 } },
  })

  invalidateSessionVersionCache(params.id)
  await writeAudit(ctx.userId, "PROMOTE_LINE_MANAGER", params.id, { teamId: membership.teamId }, ip)

  return NextResponse.json({ ok: true })
}
```

- [ ] **Step 5: Migrate [id]/demote/route.ts**

Replace the full content of `src/app/api/admin/members/[id]/demote/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

export async function POST(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  if (params.id === ctx.userId) {
    return NextResponse.json({ reason: "cannot_modify_self" }, { status: 409 })
  }

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  // Block demoting the last ACTIVE MANAGER (Q21)
  if (membership.role === "MANAGER") {
    const otherManagerCount = await db.membership.count({
      where: {
        organisationId: ctx.organisationId,
        status: "ACTIVE",
        role: "MANAGER",
        NOT: { userId: params.id },
      },
    })
    if (otherManagerCount < 1) {
      return NextResponse.json({ reason: "last_manager_cannot_demote" }, { status: 409 })
    }
  }

  const ip = getIp(request)

  await db.membership.update({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    data: { role: "MEMBER", isLineManager: false },
  })
  await db.user.update({
    where: { id: params.id },
    data: { sessionVersion: { increment: 1 } },
  })

  invalidateSessionVersionCache(params.id)
  await writeAudit(ctx.userId, "DEMOTE_LINE_MANAGER", params.id, { teamId: membership.teamId }, ip)

  return NextResponse.json({ ok: true })
}
```

- [ ] **Step 6: Migrate [id]/assign-to-team/route.ts**

Replace the full content of `src/app/api/admin/members/[id]/assign-to-team/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

export async function POST(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  if (membership.role === "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  let targetTeamId: string

  if (ctx.role === "LINE_MANAGER") {
    if (membership.teamId !== null) return NextResponse.json({ reason: "already_on_team" }, { status: 409 })
    if (!ctx.teamId) return NextResponse.json({ reason: "no_team" }, { status: 400 })
    targetTeamId = ctx.teamId
  } else {
    let body: unknown
    try {
      body = await request.json()
    } catch {
      return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
    }
    const { teamId } = body as Record<string, unknown>
    if (typeof teamId !== "string" || !teamId.trim()) {
      return NextResponse.json({ reason: "team_required" }, { status: 422 })
    }
    targetTeamId = teamId.trim()
  }

  const team = await db.team.findFirst({ where: { id: targetTeamId, organisationId: ctx.organisationId } })
  if (!team) return NextResponse.json({ reason: "team_not_found" }, { status: 404 })

  const ip = getIp(request)

  await db.membership.update({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    data: { teamId: targetTeamId },
  })
  await db.user.update({
    where: { id: params.id },
    data: { sessionVersion: { increment: 1 } },
  })

  invalidateSessionVersionCache(params.id)
  await writeAudit(ctx.userId, "ASSIGN_TO_TEAM", params.id, { teamId: targetTeamId }, ip, ctx.organisationId)

  return NextResponse.json({ ok: true })
}
```

- [ ] **Step 7: Migrate [id]/remove-from-team/route.ts**

Replace the full content of `src/app/api/admin/members/[id]/remove-from-team/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

export async function POST(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  if (params.id === ctx.userId) {
    return NextResponse.json({ reason: "cannot_modify_self" }, { status: 409 })
  }

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  if (!membership.teamId) return NextResponse.json({ reason: "no_team" }, { status: 409 })

  if (ctx.role === "LINE_MANAGER" && membership.teamId !== ctx.teamId) {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }

  if (membership.role === "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const ip = getIp(request)
  const previousTeamId = membership.teamId

  await db.membership.update({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    data: { teamId: null, isLineManager: false, role: "MEMBER" },
  })
  await db.user.update({
    where: { id: params.id },
    data: { sessionVersion: { increment: 1 } },
  })

  invalidateSessionVersionCache(params.id)
  await writeAudit(ctx.userId, "REMOVE_FROM_TEAM", params.id, { from: previousTeamId }, ip, ctx.organisationId)

  return NextResponse.json({ ok: true })
}
```

- [ ] **Step 8: Migrate [id]/route.ts (PATCH — name + team update)**

Replace the full content of `src/app/api/admin/members/[id]/route.ts`:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { writeAudit, getIp } from "@/lib/audit"

export async function PATCH(
  request: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const membership = await db.membership.findUnique({
    where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
    include: { user: true },
  })
  if (!membership || membership.status !== "ACTIVE") {
    return NextResponse.json({ reason: "not_found" }, { status: 404 })
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const { teamId, name } = body as Record<string, unknown>
  const ip = getIp(request)

  const newTeamId = typeof teamId === "string" ? teamId.trim() : undefined

  // Q27: Block team reassign if user is a LINE_MANAGER in this org — demote first
  if (typeof newTeamId === "string" && membership.teamId !== (newTeamId || null)) {
    if (membership.isLineManager) {
      return NextResponse.json({ reason: "demote_line_manager_first" }, { status: 409 })
    }
  }

  if (typeof newTeamId === "string" && newTeamId) {
    const teamExists = await db.team.findFirst({
      where: { id: newTeamId, organisationId: ctx.organisationId },
      select: { id: true },
    })
    if (!teamExists) {
      return NextResponse.json({ reason: "team_not_found" }, { status: 422 })
    }
  }

  const oldTeamId = membership.teamId
  const teamIsChanging = typeof teamId === "string" && (teamId.trim() || null) !== oldTeamId

  // Name stays on User; teamId goes to Membership
  if (typeof name === "string" && name.trim()) {
    await db.user.update({ where: { id: params.id }, data: { name: name.trim() } })
  }
  if (typeof teamId === "string") {
    await db.membership.update({
      where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
      data: { teamId: teamId.trim() || null },
    })
  }

  // Q28: Cascade-delete stale ProjectMember rows for old team's projects on team change
  if (teamIsChanging && oldTeamId) {
    const oldTeamProjects = await db.project.findMany({
      where: { teamId: oldTeamId },
      select: { id: true },
    })
    const oldProjectIds = oldTeamProjects.map((p) => p.id)
    if (oldProjectIds.length > 0) {
      await db.projectMember.deleteMany({
        where: { userId: params.id, projectId: { in: oldProjectIds } },
      })
    }
  }

  if (typeof teamId === "string") {
    const action = teamId.trim() ? "ASSIGN_TO_TEAM" : "REMOVE_FROM_TEAM"
    const meta = teamId.trim() ? { teamId: teamId.trim() } : { from: oldTeamId }
    await writeAudit(ctx.userId, action, params.id, meta, ip, ctx.organisationId)
  }

  return NextResponse.json({ ok: true })
}
```

Note: This route now needs `db.project.findMany` and `db.projectMember.deleteMany` — ensure the db mock in `src/tests/audit-actions.test.ts` includes those (they likely already exist there).

- [ ] **Step 9: Run tests**

```
npx vitest run src/tests/pm3-member-management-membership.test.ts
```

Expected: all PASS.

- [ ] **Step 10: tsc check**

```
npx tsc --noEmit
```

Expected: 0 new errors in src/.

- [ ] **Step 11: Full suite**

```
npm test
```

Expected: all tests green.

- [ ] **Step 12: Commit**

```
git add src/app/api/admin/members/[id]/route.ts \
        src/app/api/admin/members/[id]/role/route.ts \
        src/app/api/admin/members/[id]/promote/route.ts \
        src/app/api/admin/members/[id]/demote/route.ts \
        src/app/api/admin/members/[id]/assign-to-team/route.ts \
        src/app/api/admin/members/[id]/remove-from-team/route.ts \
        src/tests/pm3-member-management-membership.test.ts
git commit -m "refactor(members): migrate member management routes to read/write via Membership"
```

---

## Task 5: (Included in Task 4)

Tasks 4 and 5 were combined — Phase 3a (existence checks) and Phase 3b (role/team writes) are done together per route in Task 4 above, since splitting would leave routes in a transient half-migrated state.

---

## Task 6: Phase 4 — Last-manager guard (the dangerous count)

**Problem:** The Q21 guard ("don't demote the last manager") currently counts `db.user.count({ role: "MANAGER", isActive: true })`. After Phase 3, the authoritative role source is Membership. Counting over User.role would return stale data if a user's role was changed in a different org (the roles diverged). This guard must count `db.membership.count({ status: "ACTIVE", role: "MANAGER" })`.

**Proof requirement:** A test that mocks `db.membership.count` must fail if the implementation accidentally uses `db.user.count` (because `db.user.count` would be unmocked and throw).

**Files:**
- Modify: `src/app/api/admin/members/[id]/role/route.ts` (already done in Task 4 — Q21 migrated)
- Modify: `src/app/api/admin/members/[id]/demote/route.ts` (already done in Task 4 — Q21 migrated)
- Create: `src/tests/pm3-last-manager-guard.test.ts`

Note: The Q21 migration in `role/route.ts` and `demote/route.ts` was included in Task 4 (see the full replacements above). Task 6 adds the dedicated forge-style tests to PROVE correctness.

---

- [ ] **Step 1: Write forge tests for the last-manager guard**

Create `src/tests/pm3-last-manager-guard.test.ts`:

```ts
// src/tests/pm3-last-manager-guard.test.ts
// Phase 4 forge test: the last-ACTIVE-MANAGER guard counts Membership(status=ACTIVE, role=MANAGER).
// This test will FAIL if the implementation uses db.user.count instead — because db.user is
// not mocked here, so any call to it would throw "db.user.count is not a function".

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    // membership is the ONLY count source — if code calls db.user.count, it will throw
    membership: {
      findUnique: vi.fn(),
      update: vi.fn(),
      count: vi.fn(),
    },
    user: {
      update: vi.fn(),
      // intentionally NO count — proves the implementation doesn't call db.user.count
    },
    auditLogEntry: { create: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
  invalidateSessionVersionCache: vi.fn(),
}))

vi.mock("@/lib/audit", () => ({
  writeAudit: vi.fn(),
  getIp: vi.fn().mockReturnValue("127.0.0.1"),
}))

import { PATCH as ROLE_PATCH } from "@/app/api/admin/members/[id]/role/route"
import { POST as DEMOTE } from "@/app/api/admin/members/[id]/demote/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const MANAGER_MEMBERSHIP = {
  id: "mem-1",
  userId: "u-target",
  organisationId: "org-A",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  status: "ACTIVE",
  user: {
    id: "u-target", name: "Target", email: "t@example.com",
    role: "MANAGER", teamId: null, isLineManager: false,
    isActive: true, failedLogins: 0, lockedUntil: null, tenantKey: null,
    createdAt: new Date(), updatedAt: new Date(),
  },
}

function makeReq(url: string, body?: Record<string, unknown>, method = "POST") {
  return new Request(url, {
    method,
    ...(body ? { headers: { "content-type": "application/json" }, body: JSON.stringify(body) } : {}),
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
  vi.mocked(db.membership.findUnique).mockResolvedValue(MANAGER_MEMBERSHIP as never)
  vi.mocked(db.membership.update).mockResolvedValue({} as never)
  vi.mocked(db.user.update).mockResolvedValue({} as never)
})

describe("Phase 4 — last-ACTIVE-MANAGER guard counts Membership, not User", () => {
  describe("PATCH [id]/role — Q21 guard", () => {
    it("blocks demoting the last ACTIVE MANAGER (membership.count returns 0)", async () => {
      vi.mocked(db.membership.count).mockResolvedValue(0) // 0 other active managers

      const res = await ROLE_PATCH(
        makeReq("http://localhost/api/admin/members/u-target/role", { role: "MEMBER" }, "PATCH"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(409)
      expect((await res.json()).reason).toBe("last_manager_cannot_demote")
      // Verify the count query targeted Membership with status=ACTIVE
      expect(vi.mocked(db.membership.count)).toHaveBeenCalledWith(
        expect.objectContaining({
          where: expect.objectContaining({
            organisationId: "org-A",
            status: "ACTIVE",
            role: "MANAGER",
          }),
        })
      )
    })

    it("allows demoting a MANAGER when another ACTIVE MANAGER exists (count returns 1)", async () => {
      vi.mocked(db.membership.count).mockResolvedValue(1) // 1 other active manager

      const res = await ROLE_PATCH(
        makeReq("http://localhost/api/admin/members/u-target/role", { role: "MEMBER" }, "PATCH"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
    })
  })

  describe("POST [id]/demote — Q21 guard", () => {
    it("blocks demoting the last ACTIVE MANAGER", async () => {
      vi.mocked(db.membership.count).mockResolvedValue(0)

      const res = await DEMOTE(
        makeReq("http://localhost/api/admin/members/u-target/demote"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(409)
      expect((await res.json()).reason).toBe("last_manager_cannot_demote")
    })

    it("allows demoting when another ACTIVE MANAGER exists", async () => {
      vi.mocked(db.membership.count).mockResolvedValue(1)

      const res = await DEMOTE(
        makeReq("http://localhost/api/admin/members/u-target/demote"),
        { params: { id: "u-target" } }
      )

      expect(res.status).toBe(200)
    })
  })
})
```

- [ ] **Step 2: Run forge tests**

```
npx vitest run src/tests/pm3-last-manager-guard.test.ts
```

Expected: PASS (Q21 migration was already done in Task 4). If any test fails, it means Task 4's Q21 migration is incorrect — fix it.

- [ ] **Step 3: Re-run existing PM5 forge tests to confirm no regressions**

```
npx vitest run src/tests/pm5-forge-pending-no-access.test.ts src/tests/pm5-forge-pending-no-switch.test.ts src/tests/pm4-forge-switch-cross-org.test.ts src/tests/pm3-forge-cross-org-resource.test.ts
```

Expected: all PASS.

- [ ] **Step 4: Full suite**

```
npm test
```

Expected: all tests green.

- [ ] **Step 5: Commit**

```
git add src/tests/pm3-last-manager-guard.test.ts
git commit -m "test(members): forge tests for last-ACTIVE-MANAGER guard over Membership count (Phase 4)"
```

---

## Definition of Done

All 4 phases are complete when:

- [ ] `npm test` exits 0
- [ ] `npx tsc --noEmit` exits 0 (no new errors in `src/`)
- [ ] Inviting an existing user via the Members page creates a PENDING Membership
- [ ] Accepting a PENDING invite flips it to ACTIVE
- [ ] The "invitation_already_used" misleading error no longer appears
- [ ] All listing pages (members, tokens, consents) filter by `Membership(status=ACTIVE)`
- [ ] Role and team writes go to `Membership`, not `User`
- [ ] Last-manager guard counts `Membership(status=ACTIVE, role=MANAGER)` — proved by forge test
- [ ] All existing PM3/4/5 forge tests still green

## Docs to update after Task 1 review

- `docs/decisions.md` — add ADR noting POST /api/admin/members is the canonical invite path (not /api/admin/invitations which is now redundant for existing users)
- `docs/architecture.md` — update member listing routes section to note Membership-based filtering
- `docs/dataflow.md` — update member management flow to show Membership as write target
