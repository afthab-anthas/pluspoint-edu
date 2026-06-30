# PM5 — Pending Membership (Explicit Accept) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a MANAGER invites an email that already belongs to an existing user, create a PENDING `Membership` row that grants zero access until the invitee explicitly accepts.

**Architecture:** Add a `MembershipStatus` enum (`PENDING | ACTIVE`, default `ACTIVE`) to the `Membership` model. At invite-time, if the email matches an existing user, create a `PENDING` Membership atomically with the Invitation. The `withAuthScoped` guard, the `switch-org` lookup, the login membership resolution, and the memberships-list API all add a `status = ACTIVE` filter — a `PENDING` row is indistinguishable from a missing row at every auth boundary. Accept flips `PENDING → ACTIVE` and bumps `User.sessionVersion` (forces re-login so the new org appears on next login). Decline deletes the Membership and Invitation (idempotent). The new-user branch is untouched (new user's first Membership is always created `ACTIVE`).

**Tech Stack:** Next.js 14 App Router · Prisma 7 · PostgreSQL · NextAuth v5 JWT · Vitest (forge tests)

---

## STOP POINT

**After Task 1 only** — show the generated migration SQL for user review before proceeding to any code changes.

---

## File Map

| File | Change |
|---|---|
| `prisma/schema.prisma` | Add `MembershipStatus` enum + `status` field + index |
| `prisma/migrations/20260622000001_membership_status/migration.sql` | Generated — show to user before continuing |
| `src/lib/withAuthScoped.ts` | Add `status: true` to select + `status !== "ACTIVE"` guard |
| `src/app/api/auth/switch-org/route.ts` | Add `status: true` to select + `status !== "ACTIVE"` guard |
| `src/app/api/auth/login/route.ts` | Add `status: "ACTIVE"` to `findFirst` where clause |
| `src/app/api/auth/memberships/route.ts` | Add `status: "ACTIVE"` to `findMany` where clause |
| `src/app/api/admin/invitations/route.ts` | Check email against existing user; create PENDING Membership atomically |
| `src/app/api/invite/accept/route.ts` | Existing-user branch: flip `PENDING → ACTIVE`, bump `sessionVersion` |
| `src/app/api/invite/decline/route.ts` | **New** public POST — delete PENDING Membership + Invitation (idempotent) |
| `src/app/api/auth/pending-invitations/route.ts` | **New** GET — list caller's PENDING memberships for the notification surface |
| `src/app/invite/[id]/_components/InviteAcceptClient.tsx` | Update existing-user copy; add Decline button |
| `src/components/OrgBadge.tsx` | Accept `pendingCount` prop; show amber dot badge when > 0 |
| `src/components/AppShell.tsx` | Fetch pending-invitations count; pass to OrgBadge |
| `src/tests/pm5-forge-pending-no-access.test.ts` | **New** forge test — PENDING membership → withAuthScoped returns null |
| `src/tests/pm5-forge-pending-no-switch.test.ts` | **New** forge test — PENDING membership → switch-org returns 403 |

---

## Task 1: Schema — `MembershipStatus` enum + `status` column + migration

> **STOP after this task. Show the generated SQL to the user before proceeding.**

**Files:**
- Modify: `prisma/schema.prisma` (lines 141–161 — Membership model)
- Create: `prisma/migrations/20260622000001_membership_status/migration.sql` (generated)

- [ ] **Step 1.1: Add enum to schema.prisma**

In `prisma/schema.prisma`, after the `GithubInstallationStatus` enum (around line 583) and before the `DriftAssessmentStatus` enum, add:

```prisma
enum MembershipStatus {
  PENDING
  ACTIVE
}
```

- [ ] **Step 1.2: Add `status` field to the Membership model**

In `prisma/schema.prisma`, the Membership model currently ends at line 161. Add the `status` field and a new index:

```prisma
model Membership {
  id             String           @id @default(cuid())
  userId         String
  organisationId String
  role           Role             @default(MEMBER)
  teamId         String?
  isLineManager  Boolean          @default(false)
  status         MembershipStatus @default(ACTIVE)
  createdAt      DateTime         @default(now())
  updatedAt      DateTime         @updatedAt

  user         User         @relation(fields: [userId],         references: [id], onDelete: Cascade)
  organisation Organisation @relation(fields: [organisationId], references: [id], onDelete: Cascade)
  team         Team?        @relation(fields: [teamId],         references: [id], onDelete: SetNull)

  @@unique([userId, organisationId])
  @@index([userId])
  @@index([organisationId])
  @@index([userId, status])
}
```

The `@@index([userId, status])` is new — it covers the `GET /api/auth/pending-invitations` query which does `findMany({ where: { userId, status: "PENDING" } })`.

- [ ] **Step 1.3: Run the migration**

```bash
npx prisma migrate dev --name membership_status
```

- [ ] **Step 1.4: Read and show the generated SQL**

```bash
cat prisma/migrations/$(ls -t prisma/migrations | head -1)/migration.sql
```

**Expected SQL (additive-only — verify before proceeding):**

```sql
-- CreateEnum
CREATE TYPE "MembershipStatus" AS ENUM ('PENDING', 'ACTIVE');

-- AlterTable
ALTER TABLE "Membership" ADD COLUMN "status" "MembershipStatus" NOT NULL DEFAULT 'ACTIVE';

-- CreateIndex
CREATE INDEX "Membership_userId_status_idx" ON "Membership"("userId", "status");
```

**Safety check:** confirm:
- No `DROP` statements
- No `NOT NULL` without `DEFAULT`
- All existing rows will receive `DEFAULT 'ACTIVE'` — no data loss

- [ ] **Step 1.5: Verify Prisma client regenerated**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: output same as before (zero errors in `src/`, existing test errors in `src/tests/` only).

- [ ] **Step 1.6: Commit (schema only — no app code yet)**

```bash
git add prisma/schema.prisma prisma/migrations/
git commit -m "chore(schema): add MembershipStatus enum + status column (PENDING|ACTIVE, default ACTIVE)"
```

---

## ⛔ STOP HERE — User reviews the migration SQL before Task 2 begins

---

## Task 2: Security guards — filter `status = ACTIVE` everywhere

**Files:**
- Modify: `src/lib/withAuthScoped.ts`
- Modify: `src/app/api/auth/switch-org/route.ts`
- Modify: `src/app/api/auth/login/route.ts`
- Modify: `src/app/api/auth/memberships/route.ts`

### 2a — `withAuthScoped.ts`

- [ ] **Step 2a.1: Add `status` to the select, add guard after findUnique**

Current code at line 52–61:
```ts
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
```

Replace with:
```ts
const membership = await db.membership.findUnique({
  where: {
    userId_organisationId: {
      userId: session.user.id,
      organisationId: activeOrganisationId,
    },
  },
  select: { organisationId: true, role: true, teamId: true, isLineManager: true, status: true },
})
if (!membership || membership.status !== "ACTIVE") return null
```

- [ ] **Step 2a.2: Run existing PM3 forge tests — must all still pass**

```bash
npx vitest run src/tests/pm3-forge-tampered-jwt.test.ts src/tests/pm3-forge-header-ignored.test.ts src/tests/pm3-forge-revoked-mid-session.test.ts src/tests/pm3-forge-cross-org-resource.test.ts
```

Expected: all pass (the mocked `membership.findUnique` returns `null` in those tests, so the new status check is never reached — guard behaviour is unchanged).

### 2b — `switch-org/route.ts`

- [ ] **Step 2b.1: Add `status` to the select, add guard after findUnique**

Current code at line 53–59:
```ts
const membership = await db.membership.findUnique({
  where: {
    userId_organisationId: { userId: ctx.userId, organisationId: targetOrgId },
  },
  select: { organisationId: true },
})
if (!membership) {
  return NextResponse.json({ error: "Forbidden" }, { status: 403 })
}
```

Replace with:
```ts
const membership = await db.membership.findUnique({
  where: {
    userId_organisationId: { userId: ctx.userId, organisationId: targetOrgId },
  },
  select: { organisationId: true, status: true },
})
if (!membership || membership.status !== "ACTIVE") {
  return NextResponse.json({ error: "Forbidden" }, { status: 403 })
}
```

- [ ] **Step 2b.2: Run PM4 forge test**

```bash
npx vitest run src/tests/pm4-forge-switch-cross-org.test.ts
```

Expected: passes (mock returns `null` → 403 path, unchanged).

### 2c — `login/route.ts`

- [ ] **Step 2c.1: Add `status: "ACTIVE"` filter to the membership findFirst**

Current code at line 108–112:
```ts
const membership = await db.membership.findFirst({
  where: { userId: user.id },
  orderBy: { createdAt: "asc" },
  select: { organisationId: true },
})
```

Replace with:
```ts
const membership = await db.membership.findFirst({
  where: { userId: user.id, status: "ACTIVE" },
  orderBy: { createdAt: "asc" },
  select: { organisationId: true },
})
```

This ensures login only picks an ACTIVE membership as the default active org. A user who has only PENDING memberships (all orgs declined/not yet accepted) cannot log in — same opaque 401 as no membership at all. This is the correct behaviour: they have no active home org.

### 2d — `memberships/route.ts`

- [ ] **Step 2d.1: Add `status: "ACTIVE"` filter to findMany**

Current code at line 14–23:
```ts
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
```

Replace with:
```ts
const rows = await db.membership.findMany({
  where: { userId: ctx.userId, status: "ACTIVE" },
  select: {
    organisationId: true,
    role: true,
    teamId: true,
    organisation: { select: { name: true } },
  },
  orderBy: { createdAt: "asc" },
})
```

- [ ] **Step 2d.2: Run full suite to confirm no regressions**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: same pass count as before Task 2 (zero new failures).

- [ ] **Step 2d.3: Commit**

```bash
git add src/lib/withAuthScoped.ts src/app/api/auth/switch-org/route.ts src/app/api/auth/login/route.ts src/app/api/auth/memberships/route.ts
git commit -m "fix(auth): filter status=ACTIVE in all Membership lookups (PM5 guard prerequisite)"
```

---

## Task 3: Invitation creation — PENDING Membership for existing users

**Files:**
- Modify: `src/app/api/admin/invitations/route.ts`

The MANAGER POSTs to `/api/admin/invitations`. Currently it just creates an `Invitation` row. In PM5, if the invited email matches an existing `User`, we additionally create a `PENDING` `Membership` atomically. If a Membership (ACTIVE or PENDING) already exists for that user+org, return 409.

- [ ] **Step 3.1: Write the failing test**

Create `src/tests/pm5-invite-creates-pending-membership.test.ts`:

```ts
// src/tests/pm5-invite-creates-pending-membership.test.ts
// PM5: when a MANAGER invites an email belonging to an existing user,
// POST /api/admin/invitations must create a PENDING Membership atomically.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    team: { findUnique: vi.fn() },
    user: { findUnique: vi.fn() },
    membership: { findUnique: vi.fn(), create: vi.fn() },
    invitation: { create: vi.fn() },
    $transaction: vi.fn(),
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

import { POST } from "@/app/api/admin/invitations/route"
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

describe("PM5 — invite existing user creates PENDING Membership", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(withAuthScoped).mockResolvedValue(MANAGER_CTX)
    vi.mocked(db.team.findUnique).mockResolvedValue({ id: "team-1" } as never)
  })

  it("creates PENDING Membership atomically when email matches existing user", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-existing" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue(null) // no prior membership
    vi.mocked(db.$transaction).mockImplementation(async (fn: (tx: unknown) => Promise<unknown>) => {
      return fn({
        invitation: { create: vi.fn().mockResolvedValue({ id: "inv-1" }) },
        membership: { create: vi.fn() },
      })
    })

    const res = await POST(
      new Request("http://localhost/api/admin/invitations", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: "bob@example.com", teamId: "team-1", role: "MEMBER" }),
      })
    )

    expect(res.status).toBe(201)
    // Transaction was used (atomic)
    expect(vi.mocked(db.$transaction)).toHaveBeenCalledOnce()
  })

  it("returns 409 when existing user already has a Membership in this org", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-existing" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({
      id: "mem-1",
      status: "ACTIVE",
    } as never)

    const res = await POST(
      new Request("http://localhost/api/admin/invitations", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: "bob@example.com", teamId: "team-1", role: "MEMBER" }),
      })
    )

    expect(res.status).toBe(409)
  })

  it("creates Invitation only (no Membership) when email is a brand-new address", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue(null) // not an existing user
    vi.mocked(db.invitation.create).mockResolvedValue({ id: "inv-2" } as never)

    const res = await POST(
      new Request("http://localhost/api/admin/invitations", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: "newperson@example.com", teamId: "team-1", role: "MEMBER" }),
      })
    )

    expect(res.status).toBe(201)
    // No transaction for new-email path
    expect(vi.mocked(db.$transaction)).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 3.2: Run test — expect failure**

```bash
npx vitest run src/tests/pm5-invite-creates-pending-membership.test.ts
```

Expected: FAIL (route doesn't do user lookup or create PENDING Membership yet).

- [ ] **Step 3.3: Implement the change in `src/app/api/admin/invitations/route.ts`**

Replace the entire file:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"

const ALLOWED_INVITE_ROLES = ["MEMBER", "LINE_MANAGER"] as const
type InviteRole = (typeof ALLOWED_INVITE_ROLES)[number]

function isInviteRole(value: unknown): value is InviteRole {
  return ALLOWED_INVITE_ROLES.includes(value as InviteRole)
}

export async function POST(request: Request) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const { email, teamId, role } = body as Record<string, unknown>

  if (!email || typeof email !== "string" || !email.trim()) {
    return NextResponse.json({ reason: "email_required" }, { status: 422 })
  }
  if (!teamId || typeof teamId !== "string") {
    return NextResponse.json({ reason: "team_required" }, { status: 422 })
  }

  const resolvedRole: InviteRole = role === undefined ? "MEMBER" : (role as InviteRole)
  if (!isInviteRole(resolvedRole)) {
    return NextResponse.json({ reason: "invalid_role" }, { status: 422 })
  }

  const team = await db.team.findUnique({
    where: { id: teamId, organisationId: ctx.organisationId },
    select: { id: true },
  })
  if (!team) return NextResponse.json({ reason: "team_not_found" }, { status: 404 })

  const normalizedEmail = email.trim().toLowerCase()

  // PM5: check if this email belongs to an existing user.
  const existingUser = await db.user.findUnique({
    where: { email: normalizedEmail },
    select: { id: true },
  })

  const baseUrl = process.env.NEXTAUTH_URL ?? "http://localhost:3000"

  if (existingUser) {
    // Existing user — guard against duplicate Membership (ACTIVE or PENDING).
    const existingMembership = await db.membership.findUnique({
      where: {
        userId_organisationId: {
          userId: existingUser.id,
          organisationId: ctx.organisationId,
        },
      },
      select: { id: true, status: true },
    })

    if (existingMembership) {
      const reason =
        existingMembership.status === "ACTIVE"
          ? "already_a_member"
          : "invitation_already_pending"
      return NextResponse.json({ reason }, { status: 409 })
    }

    // Atomically create Invitation + PENDING Membership.
    const invitation = await db.$transaction(async (tx) => {
      const inv = await tx.invitation.create({
        data: {
          invitedEmail: normalizedEmail,
          teamId,
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
          teamId,
          isLineManager: resolvedRole === "LINE_MANAGER",
          status: "PENDING",
        },
      })
      return inv
    })

    return NextResponse.json(
      { id: invitation.id, url: `${baseUrl}/invite/${invitation.id}` },
      { status: 201 }
    )
  }

  // New-user path: create Invitation only (Membership is created on accept).
  const invitation = await db.invitation.create({
    data: {
      invitedEmail: normalizedEmail,
      teamId,
      role: resolvedRole,
      invitedBy: ctx.userId,
      organisationId: ctx.organisationId,
    },
  })

  return NextResponse.json(
    { id: invitation.id, url: `${baseUrl}/invite/${invitation.id}` },
    { status: 201 }
  )
}
```

- [ ] **Step 3.4: Run test — expect pass**

```bash
npx vitest run src/tests/pm5-invite-creates-pending-membership.test.ts
```

Expected: all 3 tests pass.

- [ ] **Step 3.5: Run full suite**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: no new failures.

- [ ] **Step 3.6: Commit**

```bash
git add src/app/api/admin/invitations/route.ts src/tests/pm5-invite-creates-pending-membership.test.ts
git commit -m "feat(invitations): create PENDING Membership for existing-user invites (PM5)"
```

---

## Task 4: Accept flow — flip `PENDING → ACTIVE`

**Files:**
- Modify: `src/app/api/invite/accept/route.ts`

The existing-user branch in `/api/invite/accept` currently updates `User` columns (a pre-PM3 pattern) but does not touch `Membership`. In PM5, it must:
1. Find the PENDING Membership by `(existingUser.id, invitation.organisationId)`
2. Flip `status → ACTIVE`
3. Bump `User.sessionVersion` (forces re-login; next login picks up the new active Membership)
4. Mark the Invitation as ACCEPTED
5. Auto-add to team projects

The new-user branch also currently skips creating a `Membership` row (a pre-PM3 gap). PM5 fixes it by creating an ACTIVE Membership atomically with the user.

- [ ] **Step 4.1: Write the failing test**

Create `src/tests/pm5-accept-flips-pending.test.ts`:

```ts
// src/tests/pm5-accept-flips-pending.test.ts
// PM5: POST /api/invite/accept for an existing user must find the PENDING
// Membership, flip it to ACTIVE, and bump sessionVersion. It must NOT
// update User.organisationId (the old PM2-era migration pattern).

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    invitation: { findUnique: vi.fn(), update: vi.fn() },
    user: { findUnique: vi.fn(), update: vi.fn() },
    membership: { findUnique: vi.fn(), update: vi.fn(), create: vi.fn() },
    project: { findMany: vi.fn().mockResolvedValue([]) },
    projectMember: { upsert: vi.fn() },
    $transaction: vi.fn(),
  },
}))

vi.mock("@/lib/audit", () => ({
  writeAudit: vi.fn(),
  getIp: vi.fn().mockReturnValue("127.0.0.1"),
}))

import { POST } from "@/app/api/invite/accept/route"
import { db } from "@/lib/db"

const INVITATION = {
  id: "inv-1",
  invitedEmail: "bob@example.com",
  organisationId: "org-A",
  teamId: "team-1",
  role: "MEMBER" as const,
  status: "PENDING" as const,
}

describe("PM5 — accept flips PENDING Membership to ACTIVE", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(db.invitation.findUnique).mockResolvedValue(INVITATION as never)
    vi.mocked(db.$transaction).mockImplementation(async (fn: (tx: unknown) => Promise<unknown>) => fn(db))
  })

  it("existing user: flips PENDING membership to ACTIVE and bumps sessionVersion", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({
      id: "mem-1",
      status: "PENDING",
    } as never)
    vi.mocked(db.membership.update).mockResolvedValue({} as never)
    vi.mocked(db.user.update).mockResolvedValue({} as never)
    vi.mocked(db.invitation.update).mockResolvedValue({} as never)

    const res = await POST(
      new Request("http://localhost/api/invite/accept", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ invitationId: "inv-1" }),
      })
    )

    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.linked).toBe(true)

    // Membership flipped to ACTIVE
    expect(vi.mocked(db.membership.update)).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { userId_organisationId: { userId: "u-bob", organisationId: "org-A" } },
        data: expect.objectContaining({ status: "ACTIVE" }),
      })
    )

    // sessionVersion bumped
    expect(vi.mocked(db.user.update)).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({ sessionVersion: { increment: 1 } }),
      })
    )

    // User.organisationId must NOT be changed (no migration pattern)
    const userUpdateCall = vi.mocked(db.user.update).mock.calls[0]
    expect(userUpdateCall[0].data).not.toHaveProperty("organisationId")
  })

  it("existing user with no PENDING membership returns 409 (already active or invalid invite)", async () => {
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue(null) // no pending membership

    const res = await POST(
      new Request("http://localhost/api/invite/accept", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ invitationId: "inv-1" }),
      })
    )

    expect(res.status).toBe(409)
  })
})
```

- [ ] **Step 4.2: Run test — expect failure**

```bash
npx vitest run src/tests/pm5-accept-flips-pending.test.ts
```

Expected: FAIL (existing-user branch updates User columns, not Membership).

- [ ] **Step 4.3: Implement the changes in `src/app/api/invite/accept/route.ts`**

Replace the entire file:

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { writeAudit, getIp } from "@/lib/audit"
import { validatePassword, hashPassword } from "@/lib/password"

export async function POST(request: Request) {
  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const { invitationId, name, password } = body as Record<string, unknown>

  if (!invitationId || typeof invitationId !== "string") {
    return NextResponse.json({ reason: "invitation_id_required" }, { status: 422 })
  }

  const invitation = await db.invitation.findUnique({ where: { id: invitationId } })
  if (!invitation) return NextResponse.json({ reason: "invitation_not_found" }, { status: 404 })
  if (invitation.status !== "PENDING") {
    return NextResponse.json({ reason: "invitation_already_used" }, { status: 409 })
  }

  const existing = await db.user.findUnique({ where: { email: invitation.invitedEmail } })

  if (existing) {
    // PM5: existing-user branch — find the PENDING Membership and flip it to ACTIVE.
    // NEVER update User.organisationId (the old PM2-era migration pattern).
    // Bumping sessionVersion forces re-login so the next login picks the new
    // ACTIVE Membership as the default active org.
    const pendingMembership = await db.membership.findUnique({
      where: {
        userId_organisationId: {
          userId: existing.id,
          organisationId: invitation.organisationId,
        },
      },
      select: { id: true, status: true },
    })

    if (!pendingMembership || pendingMembership.status !== "PENDING") {
      // No PENDING membership found — invitation may be stale or already accepted.
      return NextResponse.json({ reason: "no_pending_membership" }, { status: 409 })
    }

    await db.$transaction(async (tx) => {
      await tx.membership.update({
        where: {
          userId_organisationId: {
            userId: existing.id,
            organisationId: invitation.organisationId,
          },
        },
        data: {
          status: "ACTIVE",
          role: invitation.role,
          teamId: invitation.teamId ?? null,
          isLineManager: invitation.role === "LINE_MANAGER",
        },
      })
      await tx.user.update({
        where: { id: existing.id },
        data: { sessionVersion: { increment: 1 } },
      })
      await tx.invitation.update({
        where: { id: invitationId },
        data: { status: "ACCEPTED" },
      })
    })

    if (invitation.teamId) await autoAddToTeamProjects(existing.id, invitation.teamId)
    await writeAudit(
      existing.id,
      "INVITE_ACCEPTED",
      invitationId,
      { role: invitation.role, teamId: invitation.teamId ?? null },
      getIp(request),
      invitation.organisationId
    )
    return NextResponse.json({ ok: true, linked: true }, { status: 200 })
  }

  // New user: require name + password to create the account.
  if (!name || typeof name !== "string" || !name.trim()) {
    return NextResponse.json({ reason: "name_required" }, { status: 422 })
  }
  if (!password || typeof password !== "string") {
    return NextResponse.json({ reason: "password_required" }, { status: 422 })
  }

  const validation = validatePassword(password)
  if (!validation.ok) {
    return NextResponse.json({ reason: validation.reason }, { status: 422 })
  }

  const passwordHash = await hashPassword(password)

  // PM5: create User + ACTIVE Membership atomically (fixes pre-PM3 gap where
  // this route created a User without a Membership, breaking login).
  const newUserId = await db.$transaction(async (tx) => {
    const newUser = await tx.user.create({
      data: {
        email: invitation.invitedEmail,
        name: name.trim(),
        passwordHash,
        role: invitation.role,
        teamId: invitation.teamId ?? null,
        isLineManager: invitation.role === "LINE_MANAGER",
        organisationId: invitation.organisationId,
      },
    })
    await tx.membership.create({
      data: {
        userId: newUser.id,
        organisationId: invitation.organisationId,
        role: invitation.role,
        teamId: invitation.teamId ?? null,
        isLineManager: invitation.role === "LINE_MANAGER",
        status: "ACTIVE",
      },
    })
    await tx.invitation.update({
      where: { id: invitationId },
      data: { status: "ACCEPTED" },
    })
    return newUser.id
  })

  if (invitation.teamId) await autoAddToTeamProjects(newUserId, invitation.teamId)
  await writeAudit(
    newUserId,
    "INVITE_ACCEPTED",
    invitationId,
    { role: invitation.role, teamId: invitation.teamId ?? null },
    getIp(request),
    invitation.organisationId
  )

  return NextResponse.json({ ok: true }, { status: 201 })
}

async function autoAddToTeamProjects(userId: string, teamId: string): Promise<void> {
  const projects = await db.project.findMany({
    where: { teamId },
    select: { id: true },
  })
  for (const project of projects) {
    await db.projectMember.upsert({
      where: { projectId_userId: { projectId: project.id, userId } },
      create: { projectId: project.id, userId },
      update: {},
    })
  }
}
```

- [ ] **Step 4.4: Run test — expect pass**

```bash
npx vitest run src/tests/pm5-accept-flips-pending.test.ts
```

Expected: all tests pass.

- [ ] **Step 4.5: Run full suite**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: no new failures.

- [ ] **Step 4.6: Commit**

```bash
git add src/app/api/invite/accept/route.ts src/tests/pm5-accept-flips-pending.test.ts
git commit -m "feat(invite): accept flips PENDING Membership to ACTIVE, bumps sessionVersion (PM5)"
```

---

## Task 5: Decline flow — new public endpoint

**Files:**
- Create: `src/app/api/invite/decline/route.ts`

Public route (no session required — same trust model as `/api/invite/accept`: whoever has the invitation link URL can act on it). Covered by the existing `/api/invite` CSRF bypass prefix in middleware.

- [ ] **Step 5.1: Write the failing test**

Create `src/tests/pm5-decline-pending.test.ts`:

```ts
// src/tests/pm5-decline-pending.test.ts
// PM5: POST /api/invite/decline must delete the PENDING Membership and the
// Invitation. Must be idempotent — if already gone, returns 200.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    invitation: { findUnique: vi.fn(), delete: vi.fn() },
    user: { findUnique: vi.fn() },
    membership: { findUnique: vi.fn(), delete: vi.fn() },
    $transaction: vi.fn(),
  },
}))

import { POST } from "@/app/api/invite/decline/route"
import { db } from "@/lib/db"

const INVITATION = {
  id: "inv-1",
  invitedEmail: "bob@example.com",
  organisationId: "org-A",
  teamId: "team-1",
  role: "MEMBER" as const,
  status: "PENDING" as const,
}

describe("PM5 — decline removes PENDING Membership + Invitation", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(db.$transaction).mockImplementation(async (fn: (tx: unknown) => Promise<unknown>) => fn(db))
  })

  it("deletes PENDING Membership and Invitation when both exist", async () => {
    vi.mocked(db.invitation.findUnique).mockResolvedValue(INVITATION as never)
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({ id: "mem-1", status: "PENDING" } as never)
    vi.mocked(db.membership.delete).mockResolvedValue({} as never)
    vi.mocked(db.invitation.delete).mockResolvedValue({} as never)

    const res = await POST(
      new Request("http://localhost/api/invite/decline", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ invitationId: "inv-1" }),
      })
    )

    expect(res.status).toBe(200)
    expect(vi.mocked(db.membership.delete)).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { userId_organisationId: { userId: "u-bob", organisationId: "org-A" } },
      })
    )
    expect(vi.mocked(db.invitation.delete)).toHaveBeenCalledWith(
      expect.objectContaining({ where: { id: "inv-1" } })
    )
  })

  it("returns 200 when invitation not found (already declined — idempotent)", async () => {
    vi.mocked(db.invitation.findUnique).mockResolvedValue(null)

    const res = await POST(
      new Request("http://localhost/api/invite/decline", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ invitationId: "inv-gone" }),
      })
    )

    expect(res.status).toBe(200)
    expect(vi.mocked(db.membership.delete)).not.toHaveBeenCalled()
  })

  it("does not delete an ACTIVE Membership when invitee already accepted elsewhere", async () => {
    vi.mocked(db.invitation.findUnique).mockResolvedValue(INVITATION as never)
    vi.mocked(db.user.findUnique).mockResolvedValue({ id: "u-bob" } as never)
    vi.mocked(db.membership.findUnique).mockResolvedValue({ id: "mem-1", status: "ACTIVE" } as never)
    vi.mocked(db.invitation.delete).mockResolvedValue({} as never)

    const res = await POST(
      new Request("http://localhost/api/invite/decline", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ invitationId: "inv-1" }),
      })
    )

    expect(res.status).toBe(200)
    // Membership was ACTIVE — must not delete it
    expect(vi.mocked(db.membership.delete)).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 5.2: Run test — expect failure (route doesn't exist)**

```bash
npx vitest run src/tests/pm5-decline-pending.test.ts
```

Expected: FAIL (module not found).

- [ ] **Step 5.3: Create `src/app/api/invite/decline/route.ts`**

```ts
import { NextResponse } from "next/server"
import { db } from "@/lib/db"

// PM5: decline a pending invitation. Public (no session) — same trust model as
// /api/invite/accept. Idempotent: if the invitation or user is not found, returns 200.
// Only deletes a Membership if it is PENDING — never touches an ACTIVE one.
export async function POST(request: Request) {
  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const { invitationId } = body as Record<string, unknown>
  if (!invitationId || typeof invitationId !== "string") {
    return NextResponse.json({ reason: "invitation_id_required" }, { status: 422 })
  }

  const invitation = await db.invitation.findUnique({ where: { id: invitationId } })
  if (!invitation) {
    return NextResponse.json({ ok: true }, { status: 200 })
  }

  const user = await db.user.findUnique({
    where: { email: invitation.invitedEmail },
    select: { id: true },
  })

  await db.$transaction(async (tx) => {
    if (user) {
      const membership = await tx.membership.findUnique({
        where: {
          userId_organisationId: {
            userId: user.id,
            organisationId: invitation.organisationId,
          },
        },
        select: { id: true, status: true },
      })
      // Only delete a PENDING Membership — never touch an ACTIVE one.
      if (membership && membership.status === "PENDING") {
        await tx.membership.delete({
          where: {
            userId_organisationId: {
              userId: user.id,
              organisationId: invitation.organisationId,
            },
          },
        })
      }
    }
    await tx.invitation.delete({ where: { id: invitationId } })
  })

  return NextResponse.json({ ok: true }, { status: 200 })
}
```

- [ ] **Step 5.4: Run test — expect pass**

```bash
npx vitest run src/tests/pm5-decline-pending.test.ts
```

Expected: all 3 tests pass.

- [ ] **Step 5.5: Run full suite**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: no new failures.

- [ ] **Step 5.6: Commit**

```bash
git add src/app/api/invite/decline/route.ts src/tests/pm5-decline-pending.test.ts
git commit -m "feat(invite): add POST /api/invite/decline — idempotent PENDING cleanup (PM5)"
```

---

## Task 6: Pending invitations API

**Files:**
- Create: `src/app/api/auth/pending-invitations/route.ts`

Session-authenticated GET endpoint. Lists the caller's PENDING Memberships, joined with Organisation name and the corresponding Invitation ID (so the client can call accept/decline).

- [ ] **Step 6.1: Write the failing test**

Create `src/tests/pm5-pending-invitations-api.test.ts`:

```ts
// src/tests/pm5-pending-invitations-api.test.ts
// PM5: GET /api/auth/pending-invitations returns only PENDING memberships
// for the authenticated caller. Callers cannot enumerate other users.

import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findMany: vi.fn() },
    user: { findUnique: vi.fn() },
    invitation: { findMany: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

import { GET } from "@/app/api/auth/pending-invitations/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const CTX: AuthContext = {
  userId: "u-bob",
  organisationId: "org-A",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["team-1"] },
}

describe("PM5 — GET /api/auth/pending-invitations", () => {
  beforeEach(() => vi.clearAllMocks())

  it("returns 401 when unauthenticated", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(null)
    const res = await GET()
    expect(res.status).toBe(401)
  })

  it("returns empty array when caller has no PENDING memberships", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(CTX)
    vi.mocked(db.membership.findMany).mockResolvedValue([])
    vi.mocked(db.user.findUnique).mockResolvedValue({ email: "bob@example.com" } as never)
    vi.mocked(db.invitation.findMany).mockResolvedValue([])

    const res = await GET()
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.pendingInvitations).toEqual([])
  })

  it("returns pending invitations with invitationId for accept/decline", async () => {
    vi.mocked(withAuthScoped).mockResolvedValue(CTX)
    vi.mocked(db.membership.findMany).mockResolvedValue([
      {
        id: "mem-2",
        organisationId: "org-B",
        role: "MEMBER",
        teamId: null,
        createdAt: new Date("2026-06-22T10:00:00Z"),
        organisation: { name: "Org Beta" },
      },
    ] as never)
    vi.mocked(db.user.findUnique).mockResolvedValue({ email: "bob@example.com" } as never)
    vi.mocked(db.invitation.findMany).mockResolvedValue([
      { id: "inv-2", organisationId: "org-B" },
    ] as never)

    const res = await GET()
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.pendingInvitations).toHaveLength(1)
    expect(body.pendingInvitations[0]).toMatchObject({
      membershipId: "mem-2",
      organisationId: "org-B",
      organisationName: "Org Beta",
      role: "MEMBER",
      invitationId: "inv-2",
    })
  })
})
```

- [ ] **Step 6.2: Run test — expect failure**

```bash
npx vitest run src/tests/pm5-pending-invitations-api.test.ts
```

Expected: FAIL (route doesn't exist).

- [ ] **Step 6.3: Create `src/app/api/auth/pending-invitations/route.ts`**

```ts
import { NextResponse } from "next/server"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"

// PM5: list the caller's PENDING memberships (invitations awaiting explicit accept).
// Session-authenticated; userId is always sourced from the verified session.
// ACTIVE memberships are NOT included — use GET /api/auth/memberships for those.
export async function GET() {
  const ctx = await withAuthScoped()
  if (!ctx) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  }

  const pendingMemberships = await db.membership.findMany({
    where: { userId: ctx.userId, status: "PENDING" },
    select: {
      id: true,
      organisationId: true,
      role: true,
      teamId: true,
      createdAt: true,
      organisation: { select: { name: true } },
    },
    orderBy: { createdAt: "asc" },
  })

  if (pendingMemberships.length === 0) {
    return NextResponse.json({ pendingInvitations: [] }, { status: 200 })
  }

  // Resolve the corresponding Invitation IDs so the client can call accept/decline.
  const user = await db.user.findUnique({
    where: { id: ctx.userId },
    select: { email: true },
  })

  const orgIds = pendingMemberships.map((m) => m.organisationId)
  const invitations = user
    ? await db.invitation.findMany({
        where: {
          invitedEmail: user.email,
          status: "PENDING",
          organisationId: { in: orgIds },
        },
        select: { id: true, organisationId: true },
      })
    : []

  const inviteByOrg = Object.fromEntries(invitations.map((i) => [i.organisationId, i.id]))

  const pendingInvitations = pendingMemberships.map((m) => ({
    membershipId: m.id,
    organisationId: m.organisationId,
    organisationName: m.organisation?.name ?? "",
    role: m.role,
    teamId: m.teamId,
    invitationId: inviteByOrg[m.organisationId] ?? null,
    createdAt: m.createdAt,
  }))

  return NextResponse.json({ pendingInvitations }, { status: 200 })
}
```

- [ ] **Step 6.4: Run test — expect pass**

```bash
npx vitest run src/tests/pm5-pending-invitations-api.test.ts
```

Expected: all 3 tests pass.

- [ ] **Step 6.5: Run full suite**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: no new failures.

- [ ] **Step 6.6: Commit**

```bash
git add src/app/api/auth/pending-invitations/route.ts src/tests/pm5-pending-invitations-api.test.ts
git commit -m "feat(auth): add GET /api/auth/pending-invitations for PENDING membership list (PM5)"
```

---

## Task 7: UI updates — invite page + OrgBadge notification

**Files:**
- Modify: `src/app/invite/[id]/_components/InviteAcceptClient.tsx`
- Modify: `src/components/AppShell.tsx`
- Modify: `src/components/OrgBadge.tsx`

### 7a — InviteAcceptClient

For existing users, the current text says "Your previous organisation access will be revoked." — this is no longer true in PM5 (existing org stays active; a second PENDING membership is created). Update the copy and add a Decline button.

- [ ] **Step 7a.1: Update `InviteAcceptClient.tsx`**

Find the existing-user description block (currently around line 108–123):

```tsx
{isExistingUser ? (
  <>
    Your account will move to{" "}
    <strong style={{ color: "#9ca3af" }}>{orgName}</strong> as a{" "}
    <strong style={{ color: "#9ca3af" }}>{roleLabel}</strong>
    {teamName ? <>{" "}on team <strong style={{ color: "#9ca3af" }}>{teamName}</strong></> : ""}.
    Your previous organisation access will be revoked.
  </>
) : (
```

Replace with:

```tsx
{isExistingUser ? (
  <>
    Join <strong style={{ color: "#9ca3af" }}>{orgName}</strong> as a{" "}
    <strong style={{ color: "#9ca3af" }}>{roleLabel}</strong>
    {teamName ? <>{" "}on team <strong style={{ color: "#9ca3af" }}>{teamName}</strong></> : ""}.
    Your existing organisation access stays active — you can switch between organisations after accepting.
  </>
) : (
```

Find the "Switching account" label and existing submit button (around line 128–190). After the submit button, add a Decline button for existing users:

```tsx
{isExistingUser && (
  <button
    type="button"
    disabled={loading}
    onClick={handleDecline}
    style={{ ...btnSecondary, marginTop: "0.5rem", opacity: loading ? 0.7 : 1 }}
  >
    {loading ? "Declining…" : "Decline invitation"}
  </button>
)}
```

Add the `handleDecline` function alongside `handleSubmit`:

```tsx
async function handleDecline(e: React.MouseEvent) {
  e.preventDefault()
  setError(null)
  setLoading(true)
  try {
    const res = await fetch("/api/invite/decline", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ invitationId }),
    })
    if (!res.ok) {
      setError("Could not decline. Please try again.")
      return
    }
    router.push("/login?declined=1")
  } catch {
    setError("Network error. Please try again.")
  } finally {
    setLoading(false)
  }
}
```

Add `btnSecondary` style near the `btnPrimary` style:

```tsx
const btnSecondary: React.CSSProperties = {
  width: "100%",
  background: "transparent",
  color: "var(--text-muted)",
  border: "1px solid rgba(255,255,255,0.1)",
  borderRadius: 9,
  padding: "0.72rem",
  fontSize: "0.88rem",
  fontWeight: 600,
  cursor: "pointer",
  fontFamily: "inherit",
}
```

- [ ] **Step 7a.2: Compile check**

```bash
npx tsc --noEmit 2>&1 | grep "src/app/invite"
```

Expected: no errors in the invite directory.

### 7b — AppShell + OrgBadge

- [ ] **Step 7b.1: Read `src/components/AppShell.tsx` to find where memberships are fetched**

```bash
grep -n "memberships\|MembershipOption\|OrgBadge" src/components/AppShell.tsx | head -30
```

- [ ] **Step 7b.2: Update `AppShell.tsx` to also fetch pending invitation count**

In `AppShell.tsx`, alongside the existing memberships fetch, add a fetch for pending invitations count. Find the section that fetches `memberships` and add in parallel:

```ts
const [memberships, pendingCount] = await Promise.all([
  db.membership.findMany({
    where: { userId: ctx.userId, status: "ACTIVE" },
    select: {
      organisationId: true,
      role: true,
      teamId: true,
      organisation: { select: { name: true } },
    },
    orderBy: { createdAt: "asc" },
  }),
  db.membership.count({
    where: { userId: ctx.userId, status: "PENDING" },
  }),
])
```

Pass `pendingCount` to the client component that renders `OrgBadge` (via `AppShellClient`).

- [ ] **Step 7b.3: Update `OrgBadge.tsx` to accept `pendingCount` and show a dot badge**

Add `pendingCount?: number` to `OrgBadgeProps`:

```ts
type OrgBadgeProps = {
  orgName: string
  activeOrganisationId: string
  memberships: MembershipOption[]
  pendingCount?: number
}
```

In the single-membership chip render, wrap `orgName` to add a badge dot when `pendingCount > 0`:

```tsx
<span style={{ position: "relative", display: "inline-block" }}>
  {orgName || "—"}
  {(pendingCount ?? 0) > 0 && (
    <span
      aria-label={`${pendingCount} pending invitation${pendingCount === 1 ? "" : "s"}`}
      style={{
        position: "absolute",
        top: -3,
        right: -6,
        width: 6,
        height: 6,
        borderRadius: "50%",
        background: "#f59e0b",
        display: "inline-block",
      }}
    />
  )}
</span>
```

Apply the same badge in the multi-membership button render.

- [ ] **Step 7b.4: Compile check**

```bash
npx tsc --noEmit 2>&1 | grep "src/components"
```

Expected: no errors.

- [ ] **Step 7b.5: Run full suite**

```bash
npm test -- --reporter=verbose 2>&1 | tail -20
```

Expected: no new failures.

- [ ] **Step 7b.6: Commit**

```bash
git add src/app/invite/[id]/_components/InviteAcceptClient.tsx src/components/AppShell.tsx src/components/OrgBadge.tsx
git commit -m "feat(ui): PM5 invite page — decline button + updated copy; OrgBadge pending dot"
```

---

## Task 8: Forge tests — PM5 security boundary

**Files:**
- Create: `src/tests/pm5-forge-pending-no-access.test.ts`
- Create: `src/tests/pm5-forge-pending-no-switch.test.ts`

These tests prove the hard invariant: **a PENDING Membership is indistinguishable from no Membership at every auth boundary.** Each test is written so that removing the `status !== "ACTIVE"` guard makes the test fail.

### 8a — `withAuthScoped` forge test

- [ ] **Step 8a.1: Create `src/tests/pm5-forge-pending-no-access.test.ts`**

```ts
// src/tests/pm5-forge-pending-no-access.test.ts
// PM5 FORGE TEST — user has a PENDING Membership in org-A. Their JWT claims
// activeOrganisationId="org-A". withAuthScoped must return null → caller gets
// 401. This test fails (ctx is non-null) if the status !== "ACTIVE" guard is
// removed from withAuthScoped.

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

import { withAuthScoped, invalidateSessionVersionCache } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { auth } from "@/lib/auth"

describe("PM5 forge — PENDING membership grants no access via withAuthScoped", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    invalidateSessionVersionCache("u-bob")
  })

  it("withAuthScoped returns null when membership status is PENDING", async () => {
    vi.mocked(auth).mockResolvedValue({
      user: {
        id: "u-bob",
        activeOrganisationId: "org-A",
        sessionVersion: 0,
      },
    } as unknown as Awaited<ReturnType<typeof auth>>)

    // sessionVersion gate must pass so we know null came from the status check
    vi.mocked(db.user.findUnique).mockResolvedValue({ sessionVersion: 0 } as never)

    // Membership exists but is PENDING
    vi.mocked(db.membership.findUnique).mockResolvedValue({
      organisationId: "org-A",
      role: "MEMBER",
      teamId: null,
      isLineManager: false,
      status: "PENDING",
    } as never)

    const ctx = await withAuthScoped()

    // The guard MUST return null — a PENDING Membership is not a credential.
    // If this assertion fails, it means withAuthScoped is not checking status.
    expect(ctx).toBeNull()
  })

  it("withAuthScoped returns a valid ctx when membership status is ACTIVE", async () => {
    vi.mocked(auth).mockResolvedValue({
      user: {
        id: "u-bob",
        activeOrganisationId: "org-A",
        sessionVersion: 0,
      },
    } as unknown as Awaited<ReturnType<typeof auth>>)

    vi.mocked(db.user.findUnique).mockResolvedValue({ sessionVersion: 0 } as never)

    vi.mocked(db.membership.findUnique).mockResolvedValue({
      organisationId: "org-A",
      role: "MEMBER",
      teamId: null,
      isLineManager: false,
      status: "ACTIVE",
    } as never)

    const ctx = await withAuthScoped()

    expect(ctx).not.toBeNull()
    expect(ctx!.organisationId).toBe("org-A")
  })
})
```

- [ ] **Step 8a.2: Run test**

```bash
npx vitest run src/tests/pm5-forge-pending-no-access.test.ts
```

Expected: both pass.

### 8b — `switch-org` forge test

- [ ] **Step 8b.1: Create `src/tests/pm5-forge-pending-no-switch.test.ts`**

```ts
// src/tests/pm5-forge-pending-no-switch.test.ts
// PM5 FORGE TEST — user has a PENDING Membership in org-B. They POST
// /api/auth/switch-org with { organisationId: "org-B" }. The route must
// return 403 + no Set-Cookie. This test fails if the status !== "ACTIVE"
// guard is removed from the switch-org membership lookup.

import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    membership: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/auth", () => ({
  auth: vi.fn(),
}))

vi.mock("@/lib/ratelimit-switch-org", () => ({
  checkSwitchOrgRateLimit: vi.fn().mockResolvedValue({ allowed: true }),
}))

import { POST } from "@/app/api/auth/switch-org/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { auth } from "@/lib/auth"
import type { AuthContext } from "@/lib/withAuthScoped"

const BOB_ACTIVE_A: AuthContext = {
  userId: "u-bob",
  organisationId: "org-A",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["team-1"] },
}

const ORIGINAL_SECRET = process.env.NEXTAUTH_SECRET

describe("PM5 forge — switch-org to PENDING org is forbidden", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    process.env.NEXTAUTH_SECRET = "test-secret-pm5"
    vi.mocked(withAuthScoped).mockResolvedValue(BOB_ACTIVE_A)
    vi.mocked(auth).mockResolvedValue({
      user: {
        id: "u-bob",
        name: "Bob",
        email: "bob@example.com",
        activeOrganisationId: "org-A",
        sessionVersion: 0,
      },
    } as never)
  })

  afterEach(() => {
    process.env.NEXTAUTH_SECRET = ORIGINAL_SECRET
  })

  it("returns 403 and no Set-Cookie when target org Membership is PENDING", async () => {
    // Membership exists but is PENDING — must not be switched to
    vi.mocked(db.membership.findUnique).mockResolvedValue({
      organisationId: "org-B",
      status: "PENDING",
    } as never)

    const res = await POST(
      new Request("http://localhost/api/auth/switch-org", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ organisationId: "org-B" }),
      })
    )

    expect(res.status).toBe(403)
    expect(res.headers.get("set-cookie")).toBeNull()
  })

  it("returns 200 + Set-Cookie when target org Membership is ACTIVE", async () => {
    vi.mocked(db.membership.findUnique).mockResolvedValue({
      organisationId: "org-B",
      status: "ACTIVE",
    } as never)

    const res = await POST(
      new Request("http://localhost/api/auth/switch-org", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ organisationId: "org-B" }),
      })
    )

    expect(res.status).toBe(200)
    expect(res.headers.get("set-cookie")).not.toBeNull()
  })
})
```

- [ ] **Step 8b.2: Run test**

```bash
npx vitest run src/tests/pm5-forge-pending-no-switch.test.ts
```

Expected: both pass.

- [ ] **Step 8b.3: Run all PM forge tests together**

```bash
npx vitest run src/tests/pm3-forge-tampered-jwt.test.ts src/tests/pm3-forge-header-ignored.test.ts src/tests/pm3-forge-revoked-mid-session.test.ts src/tests/pm3-forge-cross-org-resource.test.ts src/tests/pm4-forge-switch-cross-org.test.ts src/tests/pm5-forge-pending-no-access.test.ts src/tests/pm5-forge-pending-no-switch.test.ts
```

Expected: all pass.

- [ ] **Step 8b.4: Commit**

```bash
git add src/tests/pm5-forge-pending-no-access.test.ts src/tests/pm5-forge-pending-no-switch.test.ts
git commit -m "test(auth): PM5 forge tests — PENDING membership grants no access or switch (PM5)"
```

---

## Task 9: Full suite + docs

**Files:**
- `docs/architecture.md` — Membership model, new API routes
- `docs/dataflow.md` — Membership schema (status field), login flow, PM5 auth flow
- `docs/code.md` — withAuthScoped status check
- `docs/decisions.md` — new ADR for explicit-accept model
- `docs/glossary.md` — "Pending Membership" definition
- `docs/structure.md` — new route files
- `docs/risk.md` — PENDING-can-never-grant invariant note

- [ ] **Step 9.1: Run full test suite and confirm baseline**

```bash
npm test 2>&1 | tail -5
```

Expected: `X passed` with same or higher count than PM4 baseline (1778).

- [ ] **Step 9.2: TypeScript compile check**

```bash
npx tsc --noEmit 2>&1 | grep -v "src/tests/" | head -20
```

Expected: zero errors outside `src/tests/`.

- [ ] **Step 9.3: Update docs — architecture.md**

In the Membership model table, add the `status` field row:
```
| `status` | `MembershipStatus @default(ACTIVE)` | PENDING until explicit accept; ACTIVE rows are the only valid auth credential. `withAuthScoped`, `switch-org`, `login`, and `memberships` all filter `status = ACTIVE`. |
```

Add new API routes to the Auth section:
```
| `GET` | `/api/auth/pending-invitations` | Session | Lists the caller's PENDING memberships (invites awaiting accept). `{ pendingInvitations: [{ membershipId, organisationId, organisationName, role, teamId, invitationId, createdAt }] }`. |
| `POST` | `/api/invite/decline` | public | Deletes a PENDING Membership + its Invitation. Idempotent — returns 200 if already gone. Never deletes an ACTIVE Membership. |
```

- [ ] **Step 9.4: Update docs — dataflow.md**

In the Membership schema section, add `status` field. Update the "Auth request flow (PM3)" section to note that step 4 now also checks `status = ACTIVE`:

```
4. `db.membership.findUnique(...)` + check `membership.status === "ACTIVE"`. PENDING rows → null → 401.
```

Update the login flow step 7 to add `status: "ACTIVE"` to the `findFirst` where.

- [ ] **Step 9.5: Update docs — decisions.md**

Add ADR-PM5:

```markdown
### ADR-PM5 — Explicit accept for multi-org invitations (PENDING Membership)

**Date:** 2026-06-22  
**Status:** Accepted

**Decision:** When a MANAGER invites an email that matches an existing user, create a `Membership` with `status = PENDING` atomically with the `Invitation`. A PENDING Membership grants zero access — it is invisible to `withAuthScoped`, `switch-org`, `login`, and `memberships`. The invitee explicitly accepts (flips to `ACTIVE`, bumps `sessionVersion`) or declines (deletes the Membership and Invitation) via the `/invite/[id]` page.

**Rationale:** Auto-granting access on invitation creation would violate the principle that access is opt-in. A manager sending an invitation email should not immediately give a second org's ctx to the invitee — the invitee must choose to accept.

**Invariant:** `withAuthScoped` and `switch-org` return null/403 for any Membership where `status !== "ACTIVE"`. Two PM5 forge tests pin this invariant and fail if the status filter is removed.

**New-user path unchanged:** Invitations to brand-new email addresses still result in an ACTIVE Membership on accept (first membership is always ACTIVE — they have no existing org to protect).
```

- [ ] **Step 9.6: Update docs — glossary.md**

Add:
```
**Pending Membership**: A `Membership` row with `status = PENDING`. Created when a MANAGER invites an existing user to a second organisation. Grants no access — `withAuthScoped` returns null for a PENDING (userId, organisationId) pair. Becomes ACTIVE only after the invitee explicitly accepts via `/invite/[id]`.
```

- [ ] **Step 9.7: Update docs — structure.md**

Add new files:
```
src/app/api/invite/decline/route.ts — POST: delete PENDING Membership + Invitation (idempotent; public)
src/app/api/auth/pending-invitations/route.ts — GET: list caller's PENDING memberships for notification surface
src/tests/pm5-forge-pending-no-access.test.ts — PM5 forge: PENDING membership → withAuthScoped null
src/tests/pm5-forge-pending-no-switch.test.ts — PM5 forge: PENDING membership → switch-org 403
src/tests/pm5-invite-creates-pending-membership.test.ts — unit: inviting existing user creates PENDING row
src/tests/pm5-accept-flips-pending.test.ts — unit: accept flips PENDING → ACTIVE
src/tests/pm5-decline-pending.test.ts — unit: decline deletes PENDING row (idempotent)
src/tests/pm5-pending-invitations-api.test.ts — unit: GET /api/auth/pending-invitations
```

- [ ] **Step 9.8: Final commit**

```bash
git add docs/
git commit -m "docs: PM5 — pending membership, explicit accept, 2 forge tests, 6 unit tests"
```

---

## Self-Review: Spec Coverage Check

| Spec requirement | Task |
|---|---|
| Membership status enum PENDING\|ACTIVE, existing rows ACTIVE | Task 1 |
| Migration is additive only — show SQL first | Task 1.4 |
| Inviting existing user creates PENDING Membership, not ACTIVE | Task 3 |
| New-user branch unchanged (ACTIVE on accept) | Task 4 (new-user branch kept, Membership creation fixed) |
| PENDING Membership does NOT grant access via withAuthScoped | Task 2a + Task 8a forge |
| PENDING Membership cannot be switched to via switch-org | Task 2b + Task 8b forge |
| Login only picks ACTIVE memberships | Task 2c |
| memberships API only returns ACTIVE | Task 2d |
| Accept: flip PENDING → ACTIVE + bump sessionVersion | Task 4 |
| Decline: delete PENDING Membership + Invitation, idempotent | Task 5 |
| Pending invites exposed separately (not in memberships) | Task 6 |
| Forge test: PENDING → 403/401, fails if guard removed | Task 8 |
| Auto-grant forbidden: CREATING PENDING does not grant ctx | Task 8a (status=PENDING → null) |
| Double-accept idempotent | Task 4 (409 on no PENDING membership) |
| Decline-twice safe | Task 5 (findUnique null → early 200) |
| docs updated | Task 9 |
| tsc baseline unchanged | Task 2d.2, Task 9.2 |
| Full suite green | Task 9.1 |
