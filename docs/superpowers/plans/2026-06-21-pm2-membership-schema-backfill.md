# PM2 — Membership Schema + Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Membership` table that mirrors each `User`'s current `(organisationId, role, teamId, isLineManager)` into one row per user, atomically inside a single Prisma migration. No production code reads from it yet — old `User.*` columns remain the fallback for PM3.

**Architecture:** Additive-only schema change. New `Membership` model with `@@unique([userId, organisationId])`, cascade FKs to `User` and `Organisation`, SetNull FK to `Team`. Backfill runs in the same migration as the schema change (SQL `INSERT INTO ... SELECT FROM "User"`) so the table is never observed empty. All existing reads still go through `User.organisationId/role/teamId/isLineManager`. No code touches `withAuthScoped`, auth, JWT, or any route.

**Tech Stack:** Prisma 7 · PostgreSQL · TypeScript 5 · Vitest 4 · `@prisma/adapter-pg` · `pg` (raw SQL for backfill-assertion test).

---

## Scope & Constraints (from spec)

**Hard rules:**
- DO NOT modify `src/lib/withAuthScoped.ts`, `src/lib/auth.ts`, `src/lib/session.ts`, `src/app/api/auth/*`, or any read path.
- DO NOT drop or modify the existing `User.organisationId`, `User.role`, `User.teamId`, `User.isLineManager` columns.
- `@@unique([userId, organisationId])` is mandatory.
- `prisma migrate diff` must show ONLY: new `Membership` table + indexes + FKs + backfill INSERT. Any other change = abort.
- Backfill assertion: `User.count() === Membership.count()` AND every `Membership`'s `(organisationId, role, teamId, isLineManager)` exactly matches its source `User`'s columns.
- Cascade test: deleting a `User` removes their `Membership` rows.
- `tsc` baseline unchanged.
- Full Vitest suite green.

**Live-DB safety:**
- Migration must be purely additive (new table + backfill). Confirmed: no `ALTER`/`DROP` on existing tables.
- Generated migration SQL must be reviewed before any `prisma migrate deploy` against prod.

**Standing rules:**
- Branch: `afthab/axis-pulse` (already checked out; never `main`).
- Commit: `[feat](schema): add Membership table + backfill` — no `Co-Authored-By` trailer.
- Re-read files before `str_replace`/`Edit`.
- TDD for the assertion tests.

---

## File Structure

**Created:**
- `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql` — table + indexes + FKs + backfill INSERT, in that order.
- `src/tests/membership-backfill.test.ts` — real-DB assertion test (count + field equality). Guarded by `DATABASE_URL`; skips if absent.
- `src/tests/membership-cascade.test.ts` — real-DB cascade test (delete User → Membership rows disappear). Guarded by `DATABASE_URL`; skips if absent.

**Modified:**
- `prisma/schema.prisma` — add `Membership` model (after `User`/`Team`/`Organisation`); add back-relation fields `memberships Membership[]` on `User`, `Organisation`, and `Team`.
- `docs/architecture.md` — add `Membership` to Database Models section.
- `docs/dataflow.md` — add `Membership` field-by-field section noting it is read-shadow (not yet read by code).
- `docs/structure.md` — list new migration + test files.
- `docs/decisions.md` — new ADR: "PM2 — Membership table as read-shadow for multi-org migration".
- `docs/glossary.md` — new term: `Membership` (one row per `(user, organisation)` pair; read-shadow in PM2).

**NOT touched:** `src/lib/withAuthScoped.ts`, `src/lib/auth.ts`, `src/lib/session.ts`, `src/app/api/auth/*`, any route under `src/app/api/`, `src/lib/db.ts`, NextAuth callbacks.

---

## Stop Point

**STOP after Task 1** for user review BEFORE Tasks 2–5. Task 1 produces: schema model + working migration + passing backfill-assertion test against the local DB. Show the user the generated `migration.sql` and the test output, then wait.

---

### Task 1: Schema model + backfill migration + assertion test

**Files:**
- Create: `src/tests/membership-backfill.test.ts`
- Modify: `prisma/schema.prisma`
- Create: `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql`

- [ ] **Step 1: Write the failing assertion test**

This test connects to the real local DB via `DATABASE_URL` (skipped if absent). It uses `PrismaClient` directly because `src/lib/db.ts`'s singleton is fine to reuse but we want explicit lifecycle for the test.

Create `src/tests/membership-backfill.test.ts`:

```typescript
// src/tests/membership-backfill.test.ts
// PM2 backfill assertion — must pass post-migration against a local DB.
// Verifies: User.count() === Membership.count() AND each Membership's
// (organisationId, role, teamId, isLineManager) exactly matches its User.
// Skipped when DATABASE_URL is absent (CI without DB).

import { describe, it, expect, beforeAll, afterAll } from "vitest"
import { Pool } from "pg"
import { PrismaPg } from "@prisma/adapter-pg"
import { PrismaClient } from "@/generated/prisma"

const dbUrl = process.env.DATABASE_URL
const describeIfDb = dbUrl ? describe : describe.skip

describeIfDb("PM2 Membership backfill", () => {
  let prisma: PrismaClient
  let pool: Pool

  beforeAll(() => {
    pool = new Pool({ connectionString: dbUrl })
    const adapter = new PrismaPg(pool)
    prisma = new PrismaClient({ adapter })
  })

  afterAll(async () => {
    await prisma.$disconnect()
    await pool.end()
  })

  it("Membership.count() === User.count()", async () => {
    const [userCount, membershipCount] = await Promise.all([
      prisma.user.count(),
      prisma.membership.count(),
    ])
    expect(membershipCount).toBe(userCount)
    expect(userCount).toBeGreaterThan(0) // sanity: local DB has data
  })

  it("every Membership exactly matches its source User columns", async () => {
    // Raw SQL: join User to Membership on userId, find any row where
    // any of (organisationId, role, teamId, isLineManager) differ.
    const mismatches = await prisma.$queryRaw<Array<{
      userId: string
      u_org: string
      m_org: string
      u_role: string
      m_role: string
      u_team: string | null
      m_team: string | null
      u_lm: boolean
      m_lm: boolean
    }>>`
      SELECT
        u.id AS "userId",
        u."organisationId" AS u_org,
        m."organisationId" AS m_org,
        u.role::text AS u_role,
        m.role::text AS m_role,
        u."teamId" AS u_team,
        m."teamId" AS m_team,
        u."isLineManager" AS u_lm,
        m."isLineManager" AS m_lm
      FROM "User" u
      JOIN "Membership" m ON m."userId" = u.id
      WHERE u."organisationId" <> m."organisationId"
         OR u.role <> m.role
         OR u."teamId" IS DISTINCT FROM m."teamId"
         OR u."isLineManager" <> m."isLineManager"
    `
    expect(mismatches).toEqual([])
  })

  it("every User has exactly one Membership for their current organisation", async () => {
    // Catches the case where a User has zero or multiple Memberships in
    // their current org after backfill. The composite unique should make
    // duplicates impossible, but assert explicitly.
    const orphans = await prisma.$queryRaw<Array<{ userId: string; count: bigint }>>`
      SELECT u.id AS "userId", COUNT(m.*) AS count
      FROM "User" u
      LEFT JOIN "Membership" m
        ON m."userId" = u.id AND m."organisationId" = u."organisationId"
      GROUP BY u.id
      HAVING COUNT(m.*) <> 1
    `
    expect(orphans).toEqual([])
  })
})
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `npm test -- src/tests/membership-backfill.test.ts`

Expected: FAIL with one of:
- `prisma.membership is not a function` (Membership not in Prisma client yet), OR
- TypeScript error: `Property 'membership' does not exist on type 'PrismaClient'`.

This is the "red" state. If the test skips (because `DATABASE_URL` is unset), set it temporarily:
```bash
export DATABASE_URL="$(grep '^DATABASE_URL=' .env | cut -d= -f2- | tr -d '"')"
```

- [ ] **Step 3: Add Membership to `prisma/schema.prisma`**

Insert this model **after** the existing `Team` model and **before** `Project`. Also add the three back-relation fields on `User`, `Organisation`, and `Team`.

Add to `Organisation` (after the existing relation list, before `@@index`):
```prisma
  memberships          Membership[]
```

Add to `User` (after `userToken UserToken?`):
```prisma
  memberships         Membership[]
```

Add to `Team` (after `invitations Invitation[]`):
```prisma
  memberships     Membership[]
```

Then add the new model:
```prisma
// PM2 — read-shadow for multi-org migration. One row per (user, organisation).
// Backfilled from User columns; no production code reads from it yet.
// User.organisationId/role/teamId/isLineManager remain the source of truth in PM2.
model Membership {
  id             String   @id @default(cuid())
  userId         String
  organisationId String
  role           Role     @default(MEMBER)
  teamId         String?
  isLineManager  Boolean  @default(false)
  createdAt      DateTime @default(now())
  updatedAt      DateTime @updatedAt

  user         User         @relation(fields: [userId],         references: [id], onDelete: Cascade)
  organisation Organisation @relation(fields: [organisationId], references: [id], onDelete: Cascade)
  team         Team?        @relation(fields: [teamId],         references: [id], onDelete: SetNull)

  @@unique([userId, organisationId])
  @@index([userId])
  @@index([organisationId])
}
```

- [ ] **Step 4: Generate the migration via `prisma migrate dev`**

Run:
```bash
npx prisma migrate dev --name add_membership_table --create-only
```

This generates `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql` but does NOT apply it yet. Inspect the generated file — it should contain ONLY:
- `CREATE TABLE "Membership" (...)` with all listed columns
- `CREATE UNIQUE INDEX "Membership_userId_organisationId_key"`
- `CREATE INDEX "Membership_userId_idx"`
- `CREATE INDEX "Membership_organisationId_idx"`
- Three `ADD CONSTRAINT ... FOREIGN KEY ...` blocks (User Cascade, Organisation Cascade, Team SetNull)

If the file contains ANY other statement (ALTER on User/Team/Organisation/Project, DROP, etc.), STOP and show the user.

- [ ] **Step 5: Append backfill SQL to the migration**

Append this block to the end of the generated `migration.sql`:

```sql
-- ─── PM2 backfill: one Membership per existing User ─────────────────────
-- Copies each User's current (organisationId, role, teamId, isLineManager)
-- into a Membership row. ID = 'mb_' || u.id — guaranteed unique because
-- u.id is unique and there is exactly one Membership per User at backfill.
-- Atomic with the schema change above; assertion test in
-- src/tests/membership-backfill.test.ts verifies count + field parity.
INSERT INTO "Membership"
  ("id", "userId", "organisationId", "role", "teamId", "isLineManager", "createdAt", "updatedAt")
SELECT
  'mb_' || u.id,
  u.id,
  u."organisationId",
  u.role,
  u."teamId",
  u."isLineManager",
  COALESCE(u."createdAt", CURRENT_TIMESTAMP),
  CURRENT_TIMESTAMP
FROM "User" u;
```

- [ ] **Step 6: Apply the migration locally**

Run:
```bash
npx prisma migrate dev
```

Expected:
- Applies the new migration.
- Regenerates Prisma client (now includes `prisma.membership`).
- No `prisma migrate diff` drift.

If `npx prisma migrate dev` reports drift or wants to rename anything, STOP — the schema and migration are out of sync; fix before continuing.

- [ ] **Step 7: Verify the migration diff shows no unexpected changes**

Run (Prisma 7 syntax — the v6 `--to-schema-datamodel` flag was removed):
```bash
npx prisma migrate diff --from-config-datasource --to-schema prisma/schema.prisma --exit-code
```

Expected: exit code 0 (no diff). If non-zero, the schema and migrations disagree — fix before continuing.

- [ ] **Step 8: Run the assertion test and verify it passes**

Run:
```bash
npm test -- src/tests/membership-backfill.test.ts
```

Expected: 3 tests pass:
- `Membership.count() === User.count()`
- `every Membership exactly matches its source User columns`
- `every User has exactly one Membership for their current organisation`

- [ ] **Step 9: Verify tsc baseline unchanged**

Run:
```bash
npx tsc --noEmit 2>&1 | tee /tmp/tsc.out | tail -5
```

Expected: same error count as the PM1 baseline (zero errors in `src/`, baseline-drift error count in `src/tests/`). If new errors appear in `src/`, fix before continuing.

- [ ] **Step 10: STOP and show the user**

Output for review:
1. The generated `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql` (full contents).
2. The vitest output for `membership-backfill.test.ts` (all green).
3. The `prisma migrate diff` confirmation (exit 0).
4. The `tsc --noEmit` tail (baseline unchanged).

Do NOT commit. Do NOT proceed to Task 2. Wait for user approval.

---

### Task 2: Cascade test

**Files:**
- Create: `src/tests/membership-cascade.test.ts`

- [ ] **Step 1: Write the failing cascade test**

Create `src/tests/membership-cascade.test.ts`:

```typescript
// src/tests/membership-cascade.test.ts
// PM2 cascade — deleting a User must remove their Membership rows.
// Uses a transaction that always rolls back so the test never mutates
// real data. Skipped when DATABASE_URL is absent.

import { describe, it, expect, beforeAll, afterAll } from "vitest"
import { Pool } from "pg"
import { PrismaPg } from "@prisma/adapter-pg"
import { PrismaClient } from "@/generated/prisma"

const dbUrl = process.env.DATABASE_URL
const describeIfDb = dbUrl ? describe : describe.skip

describeIfDb("PM2 Membership cascade", () => {
  let prisma: PrismaClient
  let pool: Pool

  beforeAll(() => {
    pool = new Pool({ connectionString: dbUrl })
    const adapter = new PrismaPg(pool)
    prisma = new PrismaClient({ adapter })
  })

  afterAll(async () => {
    await prisma.$disconnect()
    await pool.end()
  })

  it("deleting a User removes their Membership rows (rolls back)", async () => {
    // Use an existing org so we don't have to create one.
    const org = await prisma.organisation.findFirst({ select: { id: true } })
    expect(org).not.toBeNull()
    const orgId = org!.id

    // Run inside a transaction that we throw out of, so no real data is mutated.
    let cascadeWorked = false
    await prisma.$transaction(async (tx) => {
      const suffix = Math.random().toString(36).slice(2, 10)
      const user = await tx.user.create({
        data: {
          email: `pm2-cascade-${suffix}@test.local`,
          name: "PM2 Cascade Test",
          passwordHash: "$2b$12$disabled.disabled.disabled.disabled.disabled.disabled.",
          role: "MEMBER",
          organisationId: orgId,
        },
      })

      // Backfill created a Membership; verify it.
      // But wait — backfill ran at migration time only. New users created after
      // the migration do NOT auto-get a Membership in PM2 (that's PM3's job).
      // For this test we create the Membership explicitly to mirror what PM3
      // will do, then verify cascade on User delete.
      await tx.membership.create({
        data: { userId: user.id, organisationId: orgId, role: "MEMBER" },
      })

      const beforeCount = await tx.membership.count({ where: { userId: user.id } })
      expect(beforeCount).toBe(1)

      await tx.user.delete({ where: { id: user.id } })

      const afterCount = await tx.membership.count({ where: { userId: user.id } })
      expect(afterCount).toBe(0)
      cascadeWorked = true

      // Force rollback so the test leaves no trace.
      throw new Error("ROLLBACK_PM2_TEST")
    }).catch((err) => {
      if (err instanceof Error && err.message === "ROLLBACK_PM2_TEST") return
      throw err
    })

    expect(cascadeWorked).toBe(true)
  })
})
```

- [ ] **Step 2: Run the cascade test and verify it passes**

Run:
```bash
npm test -- src/tests/membership-cascade.test.ts
```

Expected: 1 test passes. The Membership table has `ON DELETE CASCADE` on `User`, so deleting the User wipes its Membership rows. The transaction rollback means no real data is touched.

- [ ] **Step 3: Run the full suite to catch regressions**

Run:
```bash
npm test
```

Expected: all existing tests pass (plus the two new ones). No new failures. Match or exceed the prior green count (last known: 1544+).

- [ ] **Step 4: Verify tsc still clean in `src/`**

Run:
```bash
npx tsc --noEmit 2>&1 | grep -c '^src/' || echo "0 errors in src/"
```

Expected: 0 errors in `src/`. Errors in `src/tests/` may remain at the baseline.

---

### Task 3: Live-DB safety review

**Files:**
- Read: `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql`

- [ ] **Step 1: Confirm the migration is additive-only**

Read the generated migration. Manually verify each statement matches one of:
- `CREATE TABLE "Membership" ...`
- `CREATE UNIQUE INDEX "Membership_..."`
- `CREATE INDEX "Membership_..."`
- `ALTER TABLE "Membership" ADD CONSTRAINT "Membership_..._fkey" FOREIGN KEY ...`
- `INSERT INTO "Membership" ... SELECT ... FROM "User"`

If ANY statement touches an existing table (`ALTER TABLE "User"`, `ALTER TABLE "Team"`, etc.) or contains `DROP`, STOP and flag to user.

- [ ] **Step 2: Confirm no locks on hot tables**

The backfill `INSERT INTO "Membership" SELECT FROM "User"` takes a brief `ACCESS SHARE` lock on `User` (read-only), which is compatible with concurrent `SELECT`, `INSERT`, `UPDATE`, `DELETE` on `User`. Confirm no other statement in the migration takes an `ACCESS EXCLUSIVE` lock on `User`, `Team`, or `Organisation`.

Expected: no `ALTER TABLE "User"`, `ALTER TABLE "Team"`, `ALTER TABLE "Organisation"`. Pure additive.

- [ ] **Step 3: Show the full SQL to the user**

Output the entire `migration.sql` for the user to review before any `prisma migrate deploy` against prod. Note: the user has explicitly asked to review the SQL before any prod apply.

---

### Task 4: Docs updates

**Files:**
- Modify: `docs/architecture.md` (Database Models section)
- Modify: `docs/dataflow.md` (add Membership field-by-field section)
- Modify: `docs/structure.md` (list new migration + test files)
- Modify: `docs/decisions.md` (new ADR for PM2)
- Modify: `docs/glossary.md` (add `Membership` term)

- [ ] **Step 1: Update `docs/architecture.md`**

Locate the Database Models section. Add a `Membership` entry alongside the other models. Use the same format as adjacent entries (model name, columns, indexes, FKs, one-line purpose). Note: "Read-shadow only in PM2 — no production code reads from this table yet. PM3 will switch reads from `User.*` to `Membership.*`."

- [ ] **Step 2: Update `docs/dataflow.md`**

Add a `Membership` field-by-field section. List each field with its type and meaning. Add a note explaining the PM2 invariant: "`Membership.count() === User.count()` and each row's `(organisationId, role, teamId, isLineManager)` mirrors its source `User`'s columns. Maintained by migration backfill only; runtime writes are deferred to PM3."

- [ ] **Step 3: Update `docs/structure.md`**

Add the new files:
- `prisma/migrations/<TIMESTAMP>_add_membership_table/migration.sql` — Membership table + backfill from User.
- `src/tests/membership-backfill.test.ts` — backfill assertion (count + field equality).
- `src/tests/membership-cascade.test.ts` — User-delete cascade verification.

- [ ] **Step 4: Update `docs/decisions.md`**

Append an ADR. Use the existing ADR numbering scheme — read the last ADR number first and increment by 1. Title: "PM2 — Membership table as read-shadow for multi-org migration".

Body (paraphrase, don't copy verbatim into the file — let the writer adapt to existing ADR style):
- **Context:** PM1 routed all 12 pages through `withAuthScoped`. The next step toward multi-org membership per user is a `Membership` table. Doing the cutover in one step would require touching auth, JWT, every route, and the migration simultaneously — high risk.
- **Decision:** Split the change. PM2 introduces the table + backfill as a pure read-shadow. PM3 (separate) switches reads. PM4+ enables write paths.
- **Consequences:** Old `User.organisationId/role/teamId/isLineManager` columns remain authoritative through PM2. Storage cost: one row per user (negligible). New invariant: `Membership.count() === User.count()` post-PM2 migration; enforced by assertion test.
- **Reversibility:** Drop table — safe, no production code reads from it.

- [ ] **Step 5: Update `docs/glossary.md`**

Add a `Membership` entry:

> **Membership** — One row per `(user, organisation)` pair. Introduced in PM2 as a read-shadow of `User.organisationId/role/teamId/isLineManager`. In PM2, only the migration backfill writes to this table; runtime reads still go to `User.*`. PM3 will switch reads to `Membership.*` and PM4+ will enable runtime writes for multi-org membership.

---

### Task 5: Final verification + commit

- [ ] **Step 1: Run the full test suite**

Run:
```bash
npm test
```

Expected: all tests green. Note the new total; should be prior baseline + 4 (3 backfill + 1 cascade).

- [ ] **Step 2: Run tsc**

Run:
```bash
npx tsc --noEmit
```

Expected: zero errors in `src/`. Errors in `src/tests/` at the same baseline as PM1 (per memory `feedback_tsc_baseline_drift`).

- [ ] **Step 3: Verify `prisma migrate diff` is clean**

Run:
```bash
npx prisma migrate diff --from-config-datasource --to-schema prisma/schema.prisma --exit-code
```

Expected: exit code 0.

- [ ] **Step 4: Stage and commit**

Use the project's conventional commit style (no `Co-Authored-By`, per memory `feedback_commit_style`).

```bash
git add prisma/schema.prisma \
        prisma/migrations \
        src/tests/membership-backfill.test.ts \
        src/tests/membership-cascade.test.ts \
        docs/architecture.md \
        docs/dataflow.md \
        docs/structure.md \
        docs/decisions.md \
        docs/glossary.md

git commit -m "[feat](schema): add Membership table + backfill

PM2 — introduces Membership as a read-shadow of User columns.
One row per (user, organisation), backfilled atomically inside the
migration from User.organisationId/role/teamId/isLineManager.

No production code reads from Membership yet — old User columns
remain the fallback. PM3 will switch reads.

Tests:
- membership-backfill.test.ts: count + field parity assertion
- membership-cascade.test.ts: User delete removes Memberships

Migration is additive-only (new table + indexes + FKs + backfill).
prisma migrate diff confirms no other schema changes."
```

- [ ] **Step 5: Do NOT push or apply to prod**

The user has explicitly asked to review the generated SQL before any prod apply. Stop after the local commit. Surface the migration file path for the user to inspect.

---

## Self-Review

**Spec coverage:**
- ✅ New Prisma model `Membership` with required fields → Task 1 Step 3.
- ✅ `@@unique([userId, organisationId])` + indexes on userId, organisationId, composite → Task 1 Step 3.
- ✅ FKs to User + Organisation with onDelete: Cascade → Task 1 Step 3.
- ✅ Backfill inside the migration SQL (atomic) → Task 1 Step 5.
- ✅ Does NOT touch withAuthScoped, login, signup, JWT, read paths → enforced in "NOT touched" file list.
- ✅ Does NOT drop User.organisationId/role/teamId/isLineManager → no statement in migration touches User.
- ✅ Composite unique mandatory → Task 1 Step 3.
- ✅ `prisma migrate diff` shows only additive → Task 1 Step 7 + Task 3 Step 1.
- ✅ Backfill assertion (count + field parity) → Task 1 Step 1 (3 tests).
- ✅ Cascade test → Task 2.
- ✅ TDD for assertion tests → red-green-refactor in Task 1 (Steps 1, 2, then 3–8).
- ✅ tsc baseline unchanged → Task 1 Step 9 + Task 5 Step 2.
- ✅ Full suite green → Task 2 Step 3 + Task 5 Step 1.
- ✅ Live-DB safety: additive, no destructive ops → Task 3.
- ✅ Show generated SQL before prod → Task 3 Step 3 + Task 5 Step 5.
- ✅ Branch afthab/axis-pulse → already current; commit doesn't switch branch.
- ✅ Conventional commit `[feat](schema): ...` no co-author → Task 5 Step 4.
- ✅ Re-read before edit → applies during execution; not a plan step.
- ✅ STOP after Task 1 → explicit stop point.

**Placeholder scan:** Searched for "TBD", "TODO", "implement later", "add appropriate", "similar to Task". None found. All code blocks are complete; all commands have expected outputs.

**Type consistency:** `Membership` model fields and method calls (`prisma.membership.count()`, `prisma.membership.create()`, `tx.membership.count()`) match the schema. `Role` enum reused (not redefined). `organisationId` field name matches existing tables. `isLineManager` boolean matches `User.isLineManager`.

**Open follow-ups (PM3 territory, NOT this plan):**
- Switch read paths in `withAuthScoped` and any other auth code to read `(role, teamId, isLineManager)` from `Membership` instead of `User.*`.
- Add runtime write hooks: on user creation, on role change, on team assignment.
- Eventually drop `User.organisationId`, `User.role`, `User.teamId`, `User.isLineManager`.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-21-pm2-membership-schema-backfill.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent for Task 1, you review the SQL + test output, then we decide together whether to continue to Tasks 2–5.

**2. Inline Execution** — I execute Task 1 in this session and stop. Same review point.

**Which approach?**
