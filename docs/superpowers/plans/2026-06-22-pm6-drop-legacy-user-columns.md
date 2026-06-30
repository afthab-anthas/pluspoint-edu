# PM6 — Drop Legacy User Columns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drop the four legacy `User` columns (`role`, `teamId`, `isLineManager`, `organisationId`) that were maintained as a PM3–PM5 dual-write fallback; `Membership` is now the sole authoritative source for all role/team/org data.

**Architecture:** Pre-flight grep runs first (BLOCKING). Any reads or writes of the 4 columns *outside* signup are cleaned up before the schema change. Signup and invite/accept dual-writes are then removed. The Prisma migration is generated with `--create-only` so the SQL can be reviewed before the local DB is touched. After local migration, a Playwright runtime exercise confirms zero 500s with the columns physically absent. No prod migration happens until the user explicitly approves after reviewing this task output.

**Tech Stack:** Next.js 14 App Router · Prisma 7 · PostgreSQL · TypeScript 5 · Vitest · Playwright (runtime exercise only)

---

## STOP POINT

**After Task 1 only** — user reviews:
1. Pre-flight grep output and straggler fix diffs
2. Generated migration SQL (drops only — any other change = abort)
3. Local runtime exercise result (zero 500s on all exercised routes)

No prod migration, no prod snapshot until explicit user approval.

---

## Known Stragglers (pre-flight grep will surface these)

Based on static analysis, the following remain active after the pre-PM6 audit:

| File | Column | Type |
|---|---|---|
| `src/app/api/admin/members/route.ts:34` | `User.teamId` | select read |
| `src/app/api/admin/members/[id]/route.ts:23,30,93,100` | `User.teamId`, `User.team` | select read (×2 in findUnique + tx update) |
| `src/app/api/admin/members/[id]/activate/route.ts:23,26` | `User.teamId`, `User.team` | select read |
| `src/app/api/admin/members/[id]/deactivate/route.ts:23,26` | `User.teamId`, `User.team` | select read |
| `src/app/api/projects/[id]/members/route.ts:41` | `User.role` | select read |
| `src/app/api/invite/accept/route.ts:73–83` | `role`, `teamId`, `isLineManager`, `organisationId` | User.create write |
| `src/app/api/auth/signup/route.ts:38,68–77,116–125` | `role`, `teamId`, `organisationId` | User.create/update writes |

These are ALL expected and fixable within PM6. If the grep surfaces anything NOT on this list → STOP immediately and report before proceeding.

---

## File Map

| File | Change |
|---|---|
| `prisma/schema.prisma` | Remove `role`, `teamId`, `isLineManager`, `organisationId` from User model; remove `Organisation.users` and `Team.members` back-relations |
| `prisma/migrations/20260622XXXXXX_drop_user_legacy_cols/migration.sql` | Generated with `--create-only` — shown to user before applying |
| `src/app/api/auth/signup/route.ts` | Remove dual-writes: delete legacy fields from User.create (×2) and delete entire User.update for existing-user invitation path |
| `src/app/api/invite/accept/route.ts` | Remove dual-writes: delete `role`, `teamId`, `isLineManager`, `organisationId` from User.create in new-user branch |
| `src/app/api/admin/members/route.ts` | Remove `teamId: true` from nested User select |
| `src/app/api/admin/members/[id]/route.ts` | Remove `teamId: true` and `team:{}` from User selects (×2); add `team:{}` to Membership-level include; update response to add explicit `teamId` and `team` |
| `src/app/api/admin/members/[id]/activate/route.ts` | Remove `teamId: true` and `team:{}` from User select; add `team:{}` to Membership include; add `team` to response |
| `src/app/api/admin/members/[id]/deactivate/route.ts` | Same as activate |
| `src/app/api/projects/[id]/members/route.ts` | Remove `role: true` from User select; add post-query Membership lookup to preserve API shape |
| `src/tests/signup.test.ts` | Update user.create assertions to not include legacy fields; update existing-user test to assert user.update NOT called |
| `src/tests/invite-accept.test.ts` | Update user.create assertion to not include legacy fields |
| `docs/architecture.md` | Update User model table — remove 4 columns |
| `docs/dataflow.md` | Update User schema section |
| `docs/decisions.md` | Add ADR-PM6 |
| `docs/glossary.md` | Remove "legacy User columns" note |
| `docs/risk.md` | Close risk items related to dual-write columns |

---

## Task 1: Pre-flight + straggler cleanup + schema + migration SQL + local runtime exercise

> **⛔ FULL STOP after this task. User reviews SQL, straggler diff, and runtime exercise output.**

### Step 1.1 — Run the pre-flight grep (BLOCKING)

Run each grep and capture output:

```powershell
# Grep 1: any write of the 4 columns to User (create/update)
npx ts-node -e "" 2>$null; rg --type ts `
  "role:|teamId:|isLineManager:|organisationId:" `
  src/app/api/auth/signup/route.ts `
  src/app/api/invite/accept/route.ts
```

```powershell
# Grep 2: any SELECT of the 4 columns from User in Prisma includes
rg --type ts -n `
  "teamId:\s*true|isLineManager:\s*true" `
  src/app src/lib src/components
```

```powershell
# Grep 3: any User.role select
rg --type ts -n `
  '"role":\s*true|role:\s*true' `
  src/app/api/projects src/app/api/admin/members
```

Expected findings (all from the Known Stragglers table above). If anything else appears → **STOP immediately, report, do not proceed**.

- [ ] **Step 1.1: Run all three greps. Confirm only known stragglers appear.**

---

### Step 1.2 — Fix straggler reads: `admin/members/route.ts`

**File:** `src/app/api/admin/members/route.ts`

Remove `teamId: true` from the User `select` inside the `include` (line 34). The response already overwrites `teamId` from `m.teamId` (Membership) and `team` from `m.team` (Membership).

```ts
// BEFORE (lines 28–44):
include: {
  user: {
    select: {
      id: true,
      email: true,
      name: true,
      teamId: true,   // ← REMOVE THIS LINE
      isActive: true,
      failedLogins: true,
      lockedUntil: true,
      tenantKey: true,
      createdAt: true,
      updatedAt: true,
    },
  },
  team: { select: { id: true, name: true } },  // ← Membership.team — KEEP
},

// AFTER:
include: {
  user: {
    select: {
      id: true,
      email: true,
      name: true,
      isActive: true,
      failedLogins: true,
      lockedUntil: true,
      tenantKey: true,
      createdAt: true,
      updatedAt: true,
    },
  },
  team: { select: { id: true, name: true } },
},
```

The response map (lines 54–60) is unchanged — `m.teamId` and `m.team` come from Membership and continue to work:
```ts
const users = validMemberships.map((m) => ({
  ...m.user,
  teamId: m.teamId,       // Membership.teamId — still correct
  team: m.team,           // Membership.team — still correct
  role: m.role,
  isLineManager: m.isLineManager,
}))
```

- [ ] **Step 1.2: Apply the change. Verify tsc reports no new errors in this file.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "admin.members.route"
```

---

### Step 1.3 — Fix straggler reads: `admin/members/[id]/route.ts`

**File:** `src/app/api/admin/members/[id]/route.ts`

Two places use `user.select: { teamId, team }` — the initial `findUnique` and the `tx.membership.update` inside the transaction. Replace both. Also add `team` at the **Membership level** (not inside user), and update the response.

**A. Replace the `findUnique` include (lines 15–34):**

```ts
// BEFORE:
const membership = await db.membership.findUnique({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  include: {
    user: {
      select: {
        id: true,
        email: true,
        name: true,
        teamId: true,                               // ← REMOVE
        isActive: true,
        failedLogins: true,
        lockedUntil: true,
        tenantKey: true,
        createdAt: true,
        updatedAt: true,
        team: { select: { id: true, name: true } }, // ← REMOVE (User.team)
      },
    },
  },
})

// AFTER:
const membership = await db.membership.findUnique({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  include: {
    user: {
      select: {
        id: true,
        email: true,
        name: true,
        isActive: true,
        failedLogins: true,
        lockedUntil: true,
        tenantKey: true,
        createdAt: true,
        updatedAt: true,
      },
    },
    team: { select: { id: true, name: true } },     // ← ADD: Membership.team
  },
})
```

**B. Replace the `tx.membership.update` include (lines 84–104, inside the transaction):**

```ts
// BEFORE:
txMembership = await tx.membership.update({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  data: { teamId: teamId.trim() || null },
  include: {
    user: {
      select: {
        id: true,
        email: true,
        name: true,
        teamId: true,                               // ← REMOVE
        isActive: true,
        failedLogins: true,
        lockedUntil: true,
        tenantKey: true,
        createdAt: true,
        updatedAt: true,
        team: { select: { id: true, name: true } }, // ← REMOVE (User.team)
      },
    },
  },
})

// AFTER:
txMembership = await tx.membership.update({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  data: { teamId: teamId.trim() || null },
  include: {
    user: {
      select: {
        id: true,
        email: true,
        name: true,
        isActive: true,
        failedLogins: true,
        lockedUntil: true,
        tenantKey: true,
        createdAt: true,
        updatedAt: true,
      },
    },
    team: { select: { id: true, name: true } },     // ← ADD: Membership.team
  },
})
```

**C. Update the response (lines 143–147) to restore `teamId` and `team` from Membership:**

```ts
// BEFORE:
return NextResponse.json({
  ...updatedMembership.user,
  role: updatedMembership.role,
  isLineManager: updatedMembership.isLineManager,
})

// AFTER:
return NextResponse.json({
  ...updatedMembership.user,
  role: updatedMembership.role,
  isLineManager: updatedMembership.isLineManager,
  teamId: updatedMembership.teamId,
  team: updatedMembership.team ?? null,
})
```

- [ ] **Step 1.3: Apply all three changes. Verify tsc.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "admin.members.\[id\].route"
```

---

### Step 1.4 — Fix straggler reads: `activate/route.ts` and `deactivate/route.ts`

Both files have the same pattern: `teamId: true` and `team: {}` inside the User select of a Membership `findUnique`. The response spreads `membership.user` and then overrides `teamId` from `membership.teamId` — but `team` currently comes from `...membership.user` (User.team). After the fix, it must come from `membership.team` (Membership level).

**File: `src/app/api/admin/members/[id]/activate/route.ts`**

```ts
// BEFORE (lines 18–30):
const membership = await db.membership.findUnique({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  include: {
    user: {
      select: {
        id: true, email: true, name: true, teamId: true,   // ← remove teamId
        isActive: true, failedLogins: true, lockedUntil: true,
        tenantKey: true, createdAt: true, updatedAt: true,
        team: { select: { id: true, name: true } },         // ← remove User.team
      },
    },
  },
})

// AFTER:
const membership = await db.membership.findUnique({
  where: { userId_organisationId: { userId: params.id, organisationId: ctx.organisationId } },
  include: {
    user: {
      select: {
        id: true, email: true, name: true,
        isActive: true, failedLogins: true, lockedUntil: true,
        tenantKey: true, createdAt: true, updatedAt: true,
      },
    },
    team: { select: { id: true, name: true } },              // ← ADD: Membership.team
  },
})
```

```ts
// BEFORE response (lines 45–51):
return NextResponse.json({
  ...membership.user,
  isActive: true,
  role: membership.role,
  isLineManager: membership.isLineManager,
  teamId: membership.teamId,
})

// AFTER:
return NextResponse.json({
  ...membership.user,
  isActive: true,
  role: membership.role,
  isLineManager: membership.isLineManager,
  teamId: membership.teamId,
  team: membership.team ?? null,   // ← ADD
})
```

**File: `src/app/api/admin/members/[id]/deactivate/route.ts`** — same changes:

```ts
// BEFORE (lines 18–30):
include: {
  user: {
    select: {
      id: true, email: true, name: true, teamId: true,   // ← remove teamId
      isActive: true, failedLogins: true, lockedUntil: true,
      tenantKey: true, createdAt: true, updatedAt: true,
      team: { select: { id: true, name: true } },         // ← remove User.team
    },
  },
},

// AFTER:
include: {
  user: {
    select: {
      id: true, email: true, name: true,
      isActive: true, failedLogins: true, lockedUntil: true,
      tenantKey: true, createdAt: true, updatedAt: true,
    },
  },
  team: { select: { id: true, name: true } },              // ← ADD: Membership.team
},
```

```ts
// BEFORE response (lines 48–54):
return NextResponse.json({
  ...membership.user,
  isActive: false,
  role: membership.role,
  isLineManager: membership.isLineManager,
  teamId: membership.teamId,
})

// AFTER:
return NextResponse.json({
  ...membership.user,
  isActive: false,
  role: membership.role,
  isLineManager: membership.isLineManager,
  teamId: membership.teamId,
  team: membership.team ?? null,   // ← ADD
})
```

- [ ] **Step 1.4: Apply both file changes. Verify tsc.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "activate|deactivate"
```

---

### Step 1.5 — Fix straggler read: `projects/[id]/members/route.ts`

**File:** `src/app/api/projects/[id]/members/route.ts`

Remove `role: true` from the nested User `select` inside `projectMember.findMany`. Add a post-query Membership lookup to preserve the `role` field in the response (the UI displays org-level role badges on project members).

```ts
// BEFORE (lines 34–44):
const members = await db.projectMember.findMany({
  where: { projectId: params.id },
  orderBy: { joinedAt: "asc" },
  select: {
    userId: true,
    joinedAt: true,
    user: {
      select: { id: true, name: true, email: true, role: true },  // ← remove role
    },
  },
})

return NextResponse.json(members)

// AFTER:
const members = await db.projectMember.findMany({
  where: { projectId: params.id },
  orderBy: { joinedAt: "asc" },
  select: {
    userId: true,
    joinedAt: true,
    user: {
      select: { id: true, name: true, email: true },
    },
  },
})

// Fetch org roles for all project members in one query (preserves API shape).
const memberUserIds = members.map((m) => m.userId)
const memberships = await db.membership.findMany({
  where: {
    userId: { in: memberUserIds },
    organisationId: ctx.organisationId,
    status: "ACTIVE",
  },
  select: { userId: true, role: true },
})
const roleByUserId: Record<string, string> = {}
for (const m of memberships) roleByUserId[m.userId] = m.role

return NextResponse.json(
  members.map((m) => ({
    ...m,
    user: { ...m.user, role: roleByUserId[m.userId] ?? null },
  }))
)
```

- [ ] **Step 1.5: Apply the change. Verify tsc.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "projects.\[id\].members"
```

---

### Step 1.6 — Remove dual-writes: `invite/accept/route.ts` (new-user branch)

**File:** `src/app/api/invite/accept/route.ts`

In the new-user branch (lines 72–84), remove `role`, `teamId`, `isLineManager`, and `organisationId` from the `tx.user.create` call. The Membership `tx.membership.create` that follows already carries all org/role/team data.

```ts
// BEFORE (lines 72–83):
const newUser = await db.$transaction(async (tx) => {
  const user = await tx.user.create({
    data: {
      email: invitation.invitedEmail,
      name: name.trim(),
      passwordHash,
      role: invitation.role,                         // ← REMOVE
      teamId: invitation.teamId ?? null,             // ← REMOVE
      isLineManager: invitation.role === "LINE_MANAGER", // ← REMOVE
      organisationId: invitation.organisationId,     // ← REMOVE
    },
  })
  // ...tx.membership.create / tx.invitation.update unchanged
})

// AFTER:
const newUser = await db.$transaction(async (tx) => {
  const user = await tx.user.create({
    data: {
      email: invitation.invitedEmail,
      name: name.trim(),
      passwordHash,
    },
  })
  // ...tx.membership.create / tx.invitation.update unchanged
})
```

- [ ] **Step 1.6: Apply the change. Verify tsc.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "invite.accept.route"
```

---

### Step 1.7 — Remove dual-writes: `auth/signup/route.ts` (three places)

**File:** `src/app/api/auth/signup/route.ts`

**Place A — existing-user + invitation path (lines 33–57):** Remove the entire `tx.user.update` call. Only `tx.membership.update` and `tx.invitation.update` are needed.

```ts
// BEFORE:
await db.$transaction(async (tx) => {
  await tx.user.update({           // ← REMOVE THIS BLOCK ENTIRELY
    where: { id: existing.id },
    data: { teamId: invitation.teamId },
  })
  await tx.membership.update({
    where: {
      userId_organisationId: {
        userId: existing.id,
        organisationId: invitation.organisationId,
      },
    },
    data: { teamId: invitation.teamId, role: invitation.role },
  })
  await tx.invitation.update({
    where: { id: invitation.id },
    data: { status: "ACCEPTED" },
  })
})

// AFTER:
await db.$transaction(async (tx) => {
  await tx.membership.update({
    where: {
      userId_organisationId: {
        userId: existing.id,
        organisationId: invitation.organisationId,
      },
    },
    data: { teamId: invitation.teamId, role: invitation.role },
  })
  await tx.invitation.update({
    where: { id: invitation.id },
    data: { status: "ACCEPTED" },
  })
})
```

**Place B — new user + invitation path (lines 63–91):** Remove `role`, `teamId`, `organisationId` from `tx.user.create`.

```ts
// BEFORE:
const created = await tx.user.create({
  data: {
    email,
    name,
    passwordHash,
    role: invitation.role,                // ← REMOVE
    teamId: invitation.teamId,            // ← REMOVE
    organisationId: invitation.organisationId, // ← REMOVE
  },
})

// AFTER:
const created = await tx.user.create({
  data: {
    email,
    name,
    passwordHash,
  },
})
```

**Place C — self-signup path (lines 115–126):** Remove `role`, `teamId`, `organisationId` from `tx.user.create`.

```ts
// BEFORE:
const created = await tx.user.create({
  data: {
    email,
    name,
    passwordHash,
    role: "MANAGER",       // ← REMOVE
    teamId: newTeamId,     // ← REMOVE
    organisationId: org.id, // ← REMOVE
  },
})

// AFTER:
const created = await tx.user.create({
  data: {
    email,
    name,
    passwordHash,
  },
})
```

- [ ] **Step 1.7: Apply all three changes. Verify tsc.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "signup.route"
```

---

### Step 1.8 — Update test fixtures: `signup.test.ts`

**File:** `src/tests/signup.test.ts`

Four assertions need updating — all because `user.create` and `user.update` no longer carry legacy fields.

**A. Test "returns 201 and creates user with MANAGER role when no invitation" (line 91)**

```ts
// BEFORE (line 104–106):
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({ data: expect.objectContaining({ role: "MANAGER" }) })
)

// AFTER (assert only safe fields remain):
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({ data: expect.objectContaining({ email: "new@x.io", name: "New" }) })
)
// Legacy fields must NOT be set on User.create:
const createCall = vi.mocked(mockDb.user.create).mock.calls[0][0]
expect(createCall.data).not.toHaveProperty("role")
expect(createCall.data).not.toHaveProperty("organisationId")
```

**B. Test "PM3: creates Membership row in same transaction as the User (self-signup)" (line 109)**

```ts
// BEFORE (lines 122–124):
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({ data: expect.objectContaining({ role: "MANAGER", organisationId: "org-new" }) })
)

// AFTER:
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({ data: expect.objectContaining({ email: "new@x.io", name: "New" }) })
)
// The Membership.create call still carries org/role:
expect(mockDb.membership.create).toHaveBeenCalledWith({
  data: expect.objectContaining({
    userId: "u-new",
    organisationId: "org-new",
    role: "MANAGER",
    teamId: null,
    isLineManager: false,
  }),
})
```

**C. Test "returns 201 and creates user with invited role when PENDING invitation exists" (line 149)**

```ts
// BEFORE (lines 158–162):
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({
    data: expect.objectContaining({ role: "MEMBER", teamId: "team-1" }),
  })
)

// AFTER:
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({
    data: expect.objectContaining({ email: "new@x.io", passwordHash: "$2b$12$mockedhash" }),
  })
)
// Legacy fields must NOT be set:
const createData = vi.mocked(mockDb.user.create).mock.calls[0][0].data
expect(createData).not.toHaveProperty("role")
expect(createData).not.toHaveProperty("teamId")
```

**D. Test "links existing user to invited team..." (line 190)**

```ts
// BEFORE (lines 194, 203–205):
vi.mocked(mockDb.user.update).mockResolvedValueOnce({ id: "existing-id" } as never)  // ← REMOVE mock setup
// ...
expect(mockDb.user.update).toHaveBeenCalledWith(
  expect.objectContaining({ where: { id: "existing-id" }, data: { teamId: "team-2" } })
)

// AFTER: remove the user.update mock setup; assert it is NOT called:
// (remove line 194 — no need to mock user.update)
expect(mockDb.user.update).not.toHaveBeenCalled()
// Membership.update is still called (still authoritative):
expect(mockDb.membership.update).toHaveBeenCalledWith({
  where: {
    userId_organisationId: {
      userId: "existing-id",
      organisationId: "org-existing",
    },
  },
  data: { teamId: "team-2", role: "LINE_MANAGER" },
})
```

- [ ] **Step 1.8: Apply all four test updates.**

```powershell
npx vitest run src/tests/signup.test.ts
```

Expected: all tests pass.

---

### Step 1.9 — Update test fixtures: `invite-accept.test.ts`

**File:** `src/tests/invite-accept.test.ts`

Test "creates new user and returns 201" (line 109) asserts legacy fields on `user.create`.

```ts
// BEFORE (lines 123–132):
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({
    data: expect.objectContaining({
      email: "new@example.com",
      role: "MEMBER",           // ← REMOVE
      teamId: "team-1",         // ← REMOVE
      organisationId: "org-1",  // ← REMOVE
    }),
  })
)

// AFTER:
expect(mockDb.user.create).toHaveBeenCalledWith(
  expect.objectContaining({
    data: expect.objectContaining({
      email: "new@example.com",
      name: "Alice",
    }),
  })
)
// Confirm legacy fields absent:
const createData = vi.mocked(mockDb.user.create).mock.calls[0][0].data
expect(createData).not.toHaveProperty("role")
expect(createData).not.toHaveProperty("teamId")
expect(createData).not.toHaveProperty("organisationId")
```

- [ ] **Step 1.9: Apply the change.**

```powershell
npx vitest run src/tests/invite-accept.test.ts
```

Expected: all tests pass.

---

### Step 1.10 — Re-run pre-flight grep to confirm zero legacy reads/writes

After all fixes, the grep must return no results for legacy User column access outside migration code.

```powershell
# Must return ZERO results (only migration SQL files are allowed to mention these):
rg --type ts -n "teamId:\s*true|isLineManager:\s*true" src/app src/lib src/components
rg --type ts -n '"role":\s*true|role:\s*true' src/app/api/projects src/app/api/admin/members
rg --type ts -n "organisationId: (org|invitation|ctx)" src/app/api/auth/signup/route.ts
rg --type ts -n "role: (invitation|\"MANAGER\"|\"MEMBER\")" src/app/api/auth/signup/route.ts
rg --type ts -n "role: invitation" src/app/api/invite/accept/route.ts
```

- [ ] **Step 1.10: Run all five greps. Every grep must return ZERO results. If any results appear → fix before continuing.**

---

### Step 1.11 — Update Prisma schema: remove 4 columns from User

**File:** `prisma/schema.prisma`

**A. User model** — remove `role`, `teamId`, `isLineManager`, `organisationId`, both FK relations, and three indexes:

```prisma
// BEFORE:
model User {
  id             String    @id @default(cuid())
  email          String    @unique
  name           String
  passwordHash   String
  role           Role      @default(MEMBER)     // ← REMOVE
  teamId         String?                        // ← REMOVE
  isLineManager  Boolean   @default(false)      // ← REMOVE
  isActive        Boolean   @default(true)
  failedLogins    Int       @default(0)
  lockedUntil     DateTime?
  sessionVersion  Int       @default(0)
  bio             String?
  jobTitle        String?
  location        String?
  tenantKey       String?
  organisationId  String                        // ← REMOVE
  createdAt      DateTime  @default(now())
  updatedAt      DateTime  @updatedAt

  organisation        Organisation         @relation(fields: [organisationId], references: [id])  // ← REMOVE
  team                Team?                @relation(fields: [teamId], references: [id])          // ← REMOVE
  audit               AuditLogEntry[]
  events              ActivityEvent[]
  projectMembers      ProjectMember[]
  timeTrackingConsent TimeTrackingConsent?
  dailyTime           MemberDailyTime[]
  createdPrompts      Prompt[]
  productiveRules     ProductiveAppRule[]
  generatedSummaries  ExecutiveSummary[]   @relation("GeneratedSummaries")
  sentInvitations     Invitation[]         @relation("SentInvitations")
  devAgentTokens      AgentToken[]         @relation("DevTokens")
  createdAgentTokens  AgentToken[]         @relation("CreatedTokens")
  userToken           UserToken?
  memberships         Membership[]

  @@index([teamId])            // ← REMOVE
  @@index([role])              // ← REMOVE
  @@index([organisationId])    // ← REMOVE
}

// AFTER:
model User {
  id             String    @id @default(cuid())
  email          String    @unique
  name           String
  passwordHash   String
  isActive        Boolean   @default(true)
  failedLogins    Int       @default(0)
  lockedUntil     DateTime?
  sessionVersion  Int       @default(0)
  bio             String?
  jobTitle        String?
  location        String?
  tenantKey       String?
  createdAt      DateTime  @default(now())
  updatedAt      DateTime  @updatedAt

  audit               AuditLogEntry[]
  events              ActivityEvent[]
  projectMembers      ProjectMember[]
  timeTrackingConsent TimeTrackingConsent?
  dailyTime           MemberDailyTime[]
  createdPrompts      Prompt[]
  productiveRules     ProductiveAppRule[]
  generatedSummaries  ExecutiveSummary[]   @relation("GeneratedSummaries")
  sentInvitations     Invitation[]         @relation("SentInvitations")
  devAgentTokens      AgentToken[]         @relation("DevTokens")
  createdAgentTokens  AgentToken[]         @relation("CreatedTokens")
  userToken           UserToken?
  memberships         Membership[]
}
```

**B. Organisation model** — remove the `users User[]` back-relation (line 57 of current schema):

```prisma
// BEFORE:
model Organisation {
  ...
  users                User[]       // ← REMOVE
  teams                Team[]
  ...
}

// AFTER:
model Organisation {
  ...
  teams                Team[]
  ...
}
```

**C. Team model** — remove the `members User[]` back-relation (line 131 of current schema):

```prisma
// BEFORE:
model Team {
  ...
  members         User[]           // ← REMOVE
  projects        Project[]
  ...
}

// AFTER:
model Team {
  ...
  projects        Project[]
  ...
}
```

- [ ] **Step 1.11: Apply all three schema changes.**

```powershell
npx tsc --noEmit 2>&1 | Select-String "schema.prisma" | head -5
```

Expected: Prisma may show type errors before client regeneration — that's normal, proceed to next step.

---

### Step 1.12 — Generate the migration SQL (--create-only, DO NOT APPLY YET)

```powershell
npx prisma migrate dev --name drop_user_legacy_cols --create-only
```

Expected output: `Prisma Migrate created the following migration without applying it: prisma/migrations/YYYYMMDDHHMMSS_drop_user_legacy_cols/migration.sql`

- [ ] **Step 1.12: Run the command. Note the generated migration folder name.**

---

### Step 1.13 — Read and verify the generated SQL

```powershell
$migDir = (Get-ChildItem prisma/migrations | Sort-Object LastWriteTime | Select-Object -Last 1).Name
Get-Content "prisma/migrations/$migDir/migration.sql"
```

**Expected SQL (ONLY drops — any INSERT, CREATE TABLE, ADD COLUMN, or modification to any table other than User = abort):**

```sql
-- DropForeignKey
ALTER TABLE "User" DROP CONSTRAINT "User_organisationId_fkey";

-- DropForeignKey
ALTER TABLE "User" DROP CONSTRAINT "User_teamId_fkey";

-- DropIndex
DROP INDEX "User_organisationId_idx";

-- DropIndex
DROP INDEX "User_role_idx";

-- DropIndex
DROP INDEX "User_teamId_idx";

-- AlterTable
ALTER TABLE "User" DROP COLUMN "isLineManager",
DROP COLUMN "organisationId",
DROP COLUMN "role",
DROP COLUMN "teamId";
```

**Safety checklist:**
- [ ] No `CREATE`, `INSERT`, `UPDATE`, or `NOT NULL` without `DEFAULT`
- [ ] No changes to tables other than `User`
- [ ] Exactly 4 columns dropped: `isLineManager`, `organisationId`, `role`, `teamId`
- [ ] Exactly 3 indexes dropped: `User_organisationId_idx`, `User_role_idx`, `User_teamId_idx`
- [ ] Exactly 2 FKs dropped: `User_organisationId_fkey`, `User_teamId_fkey`

If the SQL matches → continue. If it contains anything else → **STOP and report**.

- [ ] **Step 1.13: Read the SQL. Confirm every item in the checklist.**

---

### Step 1.14 — Apply migration to LOCAL DB and regenerate Prisma client

```powershell
npx prisma migrate dev
```

This applies the pending migration created in step 1.12 and regenerates the Prisma client with the dropped columns removed from types.

- [ ] **Step 1.14: Run the command.**

Expected: `The following migration(s) have been applied: YYYYMMDDHHMMSS_drop_user_legacy_cols`

---

### Step 1.15 — Full TypeScript compile check

```powershell
npx tsc --noEmit 2>&1 | Where-Object { $_ -notmatch "src.tests." }
```

Expected: **zero errors** outside `src/tests/`. Any error here means a forgotten legacy read — fix it before continuing.

- [ ] **Step 1.15: Run tsc. Fix any errors found.**

---

### Step 1.16 — Full test suite

```powershell
npm test 2>&1 | Select-Object -Last 10
```

Expected: same pass count as before Task 1 (1852 baseline). All PM3/PM4/PM5 forge tests must pass. Run specifically:

```powershell
npx vitest run `
  src/tests/pm3-forge-tampered-jwt.test.ts `
  src/tests/pm3-forge-header-ignored.test.ts `
  src/tests/pm3-forge-revoked-mid-session.test.ts `
  src/tests/pm3-forge-cross-org-resource.test.ts `
  src/tests/pm4-forge-switch-cross-org.test.ts `
  src/tests/pm5-forge-pending-no-access.test.ts `
  src/tests/pm5-forge-pending-no-switch.test.ts
```

Expected: all 7 forge tests pass.

- [ ] **Step 1.16: Run both commands. All tests pass.**

---

### Step 1.17 — Local runtime exercise (Playwright)

With the columns **physically absent from the local DB**, exercise every path that could have read them. Use the `visual-test` skill (`/visual-test`) to drive a Playwright session against the running local dev server. Do NOT use hardcoded credentials — read them from the local `.env` file.

**Routes to exercise and what to verify:**

| Route | Action | Pass condition |
|---|---|---|
| `/login` | Sign in with valid credentials | Lands on dashboard, no 500 |
| `/` (dashboard) | View dashboard | Activity feed loads, no 500 |
| `/admin/members` | Load members list | Member cards render with team/role shown, no 500 |
| `/admin/members/[id]` | Open a member card | Member detail loads with teamId/role from Membership, no 500 |
| `/admin/teams/[id]` | View a team page | Team members shown, no 500 |
| Switch org (OrgBadge) | Click OrgBadge → switch to second org | JWT updated, dashboard reloads for new org, no 500 |
| `POST /api/ingest/time` | Trigger a pulse-time.mjs call (or use curl with a valid UserToken) | 200 response, no 500 |
| `/admin/productive-rules` | Load productive rules admin page | Rules render, no 500 |
| `/install` | Load the machine install page | Script shown, no 500 |

Start the dev server:
```powershell
npm run dev
```

Then launch the Playwright visual test:
```powershell
# Use /visual-test skill — drives browser against http://localhost:3000
```

**After the exercise:** delete any screenshots or temporary Playwright artifacts before committing.

- [ ] **Step 1.17: Run the Playwright exercise. Confirm every route above returns HTTP 200 with no console errors or Next.js error overlays.**

---

### Step 1.18 — Commit straggler fixes + schema + migration

```powershell
git add `
  src/app/api/admin/members/route.ts `
  "src/app/api/admin/members/[id]/route.ts" `
  "src/app/api/admin/members/[id]/activate/route.ts" `
  "src/app/api/admin/members/[id]/deactivate/route.ts" `
  "src/app/api/projects/[id]/members/route.ts" `
  src/app/api/invite/accept/route.ts `
  src/app/api/auth/signup/route.ts `
  src/tests/signup.test.ts `
  src/tests/invite-accept.test.ts `
  prisma/schema.prisma `
  prisma/migrations/
git commit -m "feat(pm6): drop legacy User columns (role/teamId/isLineManager/organisationId)"
```

- [ ] **Step 1.18: Commit.**

---

## ⛔ STOP HERE

**User reviews before any prod action:**

1. **Pre-flight grep output** — only known stragglers found, all fixed
2. **Migration SQL** (from step 1.13) — only DROP statements, no surprises
3. **tsc** — zero errors outside tests
4. **Full suite** — 1852+ tests passing, all 7 forge tests green
5. **Runtime exercise** — every exercised route returned 200, no 500s

**Prod migration checklist (user runs these, NOT the plan):**

```
1. Take a DB snapshot in Coolify BEFORE proceeding.
   Verify: can list snapshots and confirm the latest is timestamped NOW.
   Restore path: Coolify → Database → Backups → Restore.

2. Push the commit: git push origin afthab/axis-pulse

3. Trigger Coolify redeploy (webhook or manual).
   Coolify runs: prisma migrate deploy → then the new binary.

4. Smoke-test prod: login, dashboard, members list, switch org.
```

---

## Task 2: Docs update

**Files:**
- `docs/architecture.md` — User model section
- `docs/dataflow.md` — User schema table
- `docs/decisions.md` — new ADR-PM6
- `docs/glossary.md` — remove "legacy User columns" note
- `docs/risk.md` — close risk items P-dual-write

- [ ] **Step 2.1: Update `docs/architecture.md`**

In the User model table, remove the four columns and their descriptions. Add a note:

```markdown
> **PM6 (2026-06-22):** `role`, `teamId`, `isLineManager`, and `organisationId` were dropped from `User`. All role/team/org data is now exclusively in `Membership`.
```

- [ ] **Step 2.2: Update `docs/dataflow.md`**

In the User schema section, remove the four column rows. Update the signup ingest pipeline diagram to show User.create with only `{ email, name, passwordHash }`.

- [ ] **Step 2.3: Add ADR-PM6 to `docs/decisions.md`**

```markdown
### ADR-PM6 — Drop legacy User columns (`role`, `teamId`, `isLineManager`, `organisationId`)

**Date:** 2026-06-22
**Status:** Accepted

**Decision:** Removed the four dual-write fallback columns from `User`. `Membership` is now the sole authoritative source for all role, team, and org data. The `User` table retains only identity and authentication fields: `email`, `name`, `passwordHash`, `isActive`, `failedLogins`, `lockedUntil`, `sessionVersion`, `bio`, `jobTitle`, `location`, `tenantKey`.

**Rationale:** The dual-write was introduced in PM3 as a rollback safety net while `withAuthScoped` was migrated from `User` reads to `Membership` reads. After PM3–PM5 completed the data-plane migration and all 16 stragglers were eliminated, the columns became dead weight with no readers outside the write paths themselves. Keeping them creates a false impression that `User.role` is meaningful.

**Irreversibility:** Column drop cannot be undone without restoring from snapshot. A DB snapshot was taken before prod migration (see snapshot timestamp in Coolify).

**Runtime verification:** A Playwright exercise with the columns physically absent on the local DB confirmed zero 500s across login, dashboard, members list, member card, team page, switch-org, ingest/time, productive-rules, and install pages.
```

- [ ] **Step 2.4: Update `docs/glossary.md`**

Remove or update any definition that references the legacy User columns. Add/update:

```markdown
**User (model):** Contains only identity and auth fields: `email`, `name`, `passwordHash`, `isActive`, `failedLogins`, `lockedUntil`, `sessionVersion`, `bio`, `jobTitle`, `location`, `tenantKey`. As of PM6, `User` carries **no** org, role, or team data — all of that lives in `Membership`.
```

- [ ] **Step 2.5: Update `docs/risk.md`**

Close any open risk items related to dual-write columns. Add a note:

```markdown
> **PM6 (2026-06-22):** Legacy `User.role/teamId/isLineManager/organisationId` columns dropped. Dual-write risk eliminated. DB snapshot taken before prod migration.
```

- [ ] **Step 2.6: Commit docs**

```powershell
git add docs/
git commit -m "docs(pm6): remove legacy User columns from architecture, dataflow, decisions, glossary, risk"
```

- [ ] **Step 2.7: Push**

```powershell
git push origin afthab/axis-pulse
```

---

## Self-Review: Spec Coverage Check

| Spec requirement | Task/Step |
|---|---|
| Pre-flight grep — blocking | Step 1.1 |
| Stop if any read/write outside signup found | Step 1.1 — plan has explicit STOP condition |
| Stop writing User.organisationId/role/teamId/isLineManager in signup | Step 1.7 |
| Remove User.teamId update in existing-user invitation path | Step 1.7A |
| Remove User.create dual-writes in invite/accept | Step 1.6 |
| Fix straggler reads in admin/members routes | Steps 1.2–1.4 |
| Fix straggler role read in projects/members | Step 1.5 |
| Generate migration with --create-only, show SQL before apply | Steps 1.12–1.13 |
| Migration SQL = ONLY drops | Step 1.13 safety checklist |
| Apply migration to LOCAL DB first | Step 1.14 |
| tsc clean after migration | Step 1.15 |
| Full suite green (including PM3/4/5 forge tests) | Step 1.16 |
| Local runtime exercise with columns physically absent | Step 1.17 |
| STOP for user review before prod | ⛔ after Step 1.18 |
| Prod snapshot instructions | ⛔ block — user's checklist |
| Update test fixtures for signup/invite-accept | Steps 1.8–1.9 |
| Docs updated (all 5 affected docs) | Task 2 |
| Commit with no Co-Authored-By | Steps 1.18, 2.6 |
| No PR opened | Per standing rule in CLAUDE.md |
