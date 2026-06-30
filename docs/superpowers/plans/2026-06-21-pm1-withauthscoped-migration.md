# PM1 — Single-Chokepoint Migration (withAuthScoped) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route every authenticated server page through `withAuthScoped()` instead of raw `auth()`, so `withAuthScoped` becomes the sole tenancy verifier. No functional change visible to the user.

**Architecture:** Pure call-site swap. Each page reads `ctx.organisationId / .role / .teamId / .isLineManager / .userId` from `withAuthScoped()` instead of `session.user.*`. Where pages currently read `session.user.name` (which `AuthContext` does not expose), fetch it from the DB — the same pattern dashboard.page.tsx already uses. Prisma queries remain byte-identical except for the source of the org id; the only behavior change is that pages no longer silently render with `organisationId=""` when JWT is broken — they redirect, consistent with the rest of the codebase.

**Tech Stack:** Next.js 14 App Router server components · `src/lib/withAuthScoped.ts` · `src/lib/db` · existing AppShell.

---

## Scope (12 pages — exactly)

Dashboard is **already migrated** (verify-only). The other 11 pages need the swap.

| # | Page | Status | Auth check today | Post-migration check |
|---|---|---|---|---|
| 0 | `src/app/dashboard/page.tsx` | ✅ already uses `withAuthScoped` | `if (!ctx) redirect("/login")` | unchanged (verify only) |
| 1 | `src/app/prompts/page.tsx` | needs swap | `if (!session) redirect("/login")` | `if (!ctx) redirect("/login")` |
| 2 | `src/app/admin/budget/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role !== "MANAGER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role !== "MANAGER") redirect("/dashboard")` |
| 3 | `src/app/admin/cost-dashboard/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role === "MEMBER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")` |
| 4 | `src/app/admin/members/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role === "MEMBER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")` |
| 5 | `src/app/admin/tokens/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role === "MEMBER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")` |
| 6 | `src/app/admin/audit/page.tsx` | needs swap | `if (!session \|\| role !== "MANAGER") redirect("/dashboard")` ⚠ unauth → /dashboard, not /login | `if (!ctx || ctx.role !== "MANAGER") redirect("/dashboard")` — preserve oddity |
| 7 | `src/app/admin/github/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role === "MEMBER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")` |
| 8 | `src/app/admin/consents/page.tsx` | needs swap | `if (!session) redirect("/login")` then non-MANAGER **renders 403 AppShell, does NOT redirect** | `if (!ctx) redirect("/login")` then keep the 403 render branch verbatim |
| 9 | `src/app/admin/productive-rules/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role === "MEMBER") redirect("/dashboard")` | `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")` |
| 10 | `src/app/teams/page.tsx` | needs swap | `if (!session) redirect("/login")` | `if (!ctx) redirect("/login")` |
| 11 | `src/app/teams/[id]/page.tsx` | needs swap | `if (!session) redirect("/login"); if (role !== "MANAGER" && teamId !== params.id) redirect("/teams")` | `if (!ctx) redirect("/login"); if (ctx.role !== "MANAGER" && ctx.teamId !== params.id) redirect("/teams")` |

### Notes on behaviour preservation

1. **`session.user.name` is not on `AuthContext`.** Five pages already DB-fetch it (`dashboard`, `teams`, `teams/[id]`); six pages read `session.user.name ?? "User"` from the JWT. After migration, those six switch to the DB-fetch pattern used by dashboard:
   ```ts
   const user = await db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })
   const userName = user?.name ?? "User"
   ```
   The user-visible string is the same; the only difference is that DB-fetched name reflects a fresh rename one request earlier than JWT-cached name. Documented in plan; no test depends on JWT-name lag.

2. **`session.user.id` → `ctx.userId`.** 1:1, no edge case.

3. **`session.user.organisationId ?? "__none__"` fallback.** Today this prevents accidental cross-tenant reads when JWT lacks org id. After migration, `withAuthScoped()` returns `null` for empty org id (line 45 of `src/lib/withAuthScoped.ts`), so the page redirects before the query runs. Net behaviour: equivalent or stricter (no silent empty-org render). **Action:** keep variable name `orgId = ctx.organisationId` to minimise diff; Prisma SQL is identical because both produce a non-empty string.

4. **Session-version drift.** `withAuthScoped()` re-checks JWT vs DB `sessionVersion` and returns null on mismatch. Today these pages do not check drift on render — they show stale content until the next route hits. After migration, they redirect on drift. Aligns with chokepoint goal; **no test** asserts the lag behaviour.

5. **Where reads of `session.user.role` appear inline (e.g. `session.user.role === "MANAGER"` in JSX), substitute with `ctx.role`.** Same value, different binding.

6. **`session.user.teamId ?? "__none__"`** literal sentinel reads (used in `where: { teamId: session.user.teamId ?? "__none__", ... }`) become `ctx.teamId ?? "__none__"`. The sentinel stays because `ctx.teamId` is `string | null`.

7. **`session.user.isLineManager`** is read by exactly **zero** of the 12 pages today (grep confirmed). The user's spec mentions it for completeness; no replacement needed.

---

## File Structure

Every change is to a single `page.tsx`. No new files. No changes to `withAuthScoped.ts`, schema, or middleware.

Per page, the diff is bounded to:
- Imports: replace `import { auth } from "@/lib/auth"` with `import { withAuthScoped } from "@/lib/withAuthScoped"`.
- Auth block: `await auth()` → `await withAuthScoped()`; `session` → `ctx`; rename downstream reads.
- For the six pages that read `session.user.name` from the JWT: add the dashboard-style `db.user.findUnique({ select: { name: true } })` call (or fold into an existing `Promise.all`).

---

## Verification commands (used in every task)

```bash
# After each page change:
npx tsc --noEmit                                       # baseline: 0 errors in src/ (src/tests/ drift is known)
grep -r "session.user.organisationId" src/app          # expect zero hits in page.tsx files at the end
git diff <file> | grep -E "^[+-]\s*where:"             # confirm no Prisma where-clause changed
```

Per-task: `npm run test -- --testPathPattern=<page>` if any test names that page. Full suite at the end only.

---

### Task 1: Migrate `src/app/prompts/page.tsx` (smallest, simplest — STOP here for review)

**Why this first:** 64 lines, single `if (!session) redirect("/login")` guard, no team scoping, no `isLineManager`. Touches every value the user listed (`organisationId`, `role`) and the DB-fetched-name pattern. Tests the migration recipe end-to-end before scaling to the other 10.

**Files:**
- Modify: `src/app/prompts/page.tsx` (entire file is 64 lines — full content shown below)

- [ ] **Step 1: Read the current file to confirm exact contents before edit**

Run: open `src/app/prompts/page.tsx` and confirm lines 1–18 match the diff base shown below. Never trust cached line numbers.

- [ ] **Step 2: Apply the swap**

Use `Edit` (not `Write`) to make a targeted edit. The diff is:

```diff
-import { auth } from "@/lib/auth"
+import { withAuthScoped } from "@/lib/withAuthScoped"
 import { redirect } from "next/navigation"
 import { db } from "@/lib/db"
 import { AppShell } from "@/components/AppShell"
 import { PromptsClient } from "./_components/PromptsClient"

 export default async function PromptsPage() {
-  const session = await auth()
-  if (!session) redirect("/login")
+  const ctx = await withAuthScoped()
+  if (!ctx) redirect("/login")

-  const orgId = session.user.organisationId ?? "__none__"
-  const prompts = await db.prompt.findMany({
-    where: { organisationId: orgId },
-    orderBy: { pNumber: "asc" },
-  })
-  const canEdit = session.user.role !== "MEMBER"
-  const userName = session.user.name ?? "User"
+  const orgId = ctx.organisationId
+  const [prompts, user] = await Promise.all([
+    db.prompt.findMany({
+      where: { organisationId: orgId },
+      orderBy: { pNumber: "asc" },
+    }),
+    db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } }),
+  ])
+  const canEdit = ctx.role !== "MEMBER"
+  const userName = user?.name ?? "User"

   return (
-    <AppShell role={session.user.role} userName={userName} userId={session.user.id}>
+    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
```

Concrete Edit calls (two of them — the import swap and the body swap):

Edit 1 — import:
- `old_string`: `import { auth } from "@/lib/auth"`
- `new_string`: `import { withAuthScoped } from "@/lib/withAuthScoped"`

Edit 2 — body. To keep `old_string` unique, replace the entire block from `const session = await auth()` through `<AppShell role={session.user.role} userName={userName} userId={session.user.id}>` in one Edit. Read the file again first if uncertain — never paste from this plan blindly.

- [ ] **Step 3: Verify Prisma query is byte-identical**

Run: `git diff src/app/prompts/page.tsx | grep -E "^[+-]\s*(where|orderBy)"`
Expected: no `where:` or `orderBy:` line in the diff (only the variable feeding `organisationId` changes; the literal stays). If a `where:` or `orderBy:` line appears in the diff, the migration regressed the query — revert and retry.

- [ ] **Step 4: Type-check**

Run: `npx tsc --noEmit 2>&1 | grep -c "src/app/prompts/page.tsx"`
Expected: `0`

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | grep -v "src/tests/" | wc -l`
Expected: `0` (same as baseline — src/tests/ drift is known and unrelated).

- [ ] **Step 5: Run any prompts-page-specific tests**

Run: `npm test -- --run prompts 2>&1 | tail -20`
Expected: PASS (or "no tests found" — both acceptable; the page has no dedicated render test today).

- [ ] **Step 6: Sanity-check the rendered page locally (optional but recommended)**

If dev server is running, hit `http://localhost:3000/prompts` while signed in. Expect the same prompt list and the same username chip in the header. No layout shift.

- [ ] **Step 7: Commit and STOP for user review**

```bash
git add src/app/prompts/page.tsx
git commit -m "refactor(auth): route prompts page through withAuthScoped"
```

**HARD STOP.** Do not begin Task 2 until the user reviews and approves the prompts diff. The whole point of stopping after Task 1 is to confirm the recipe is right before applying it 10 more times.

---

### Task 2: Migrate `src/app/admin/budget/page.tsx`

**Files:** Modify `src/app/admin/budget/page.tsx`

- [ ] **Step 1: Read file, confirm lines 1–47 still match the version this plan was written against.**

- [ ] **Step 2: Apply the swap.**

```diff
-import { auth } from "@/lib/auth"
+import { withAuthScoped } from "@/lib/withAuthScoped"
 import { redirect } from "next/navigation"
 ...
-  const session = await auth()
-  if (!session) redirect("/login")
-  if (session.user.role !== "MANAGER") redirect("/dashboard")
+  const ctx = await withAuthScoped()
+  if (!ctx) redirect("/login")
+  if (ctx.role !== "MANAGER") redirect("/dashboard")

-  const orgId = session.user.organisationId ?? "__none__"
+  const orgId = ctx.organisationId
   ...
-  const userName = session.user.name ?? "User"
+  const user = await db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })
+  const userName = user?.name ?? "User"
   ...
-    <AppShell role={session.user.role} userName={userName} userId={session.user.id}>
+    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
```

Note: the new `db.user.findUnique` can fold into the existing `Promise.all` on lines 28–35 for efficiency. Acceptable to leave as a separate await — the diff is smaller. **Pick "fold into Promise.all" for parity with prompts.**

- [ ] **Step 3:** Verify Prisma queries unchanged (`git diff` should show no `where:` / `select:` / `orderBy:` lines except for the new `db.user.findUnique`).

- [ ] **Step 4:** `npx tsc --noEmit` — baseline holds.

- [ ] **Step 5:** Commit: `refactor(auth): route admin/budget through withAuthScoped`.

---

### Task 3: Migrate `src/app/admin/cost-dashboard/page.tsx`

**Files:** Modify `src/app/admin/cost-dashboard/page.tsx`

Same recipe as Task 2. Specifics:

- Auth guard: `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")`
- `orgId` is passed to five lib calls inside `Promise.all` on lines 92–98 — DO NOT touch the `Promise.all` itself, only the source of `orgId`.
- Add `db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })` into that `Promise.all` and destructure `user` alongside the existing five results.
- `session.user.role` is read inline on line 80 (`if (session.user.role === "MEMBER")`) and on line 114 (`<AppShell role={session.user.role}`) — replace both with `ctx.role`.

- [ ] Step 1–5: same shape as Task 2. Commit: `refactor(auth): route admin/cost-dashboard through withAuthScoped`.

---

### Task 4: Migrate `src/app/admin/members/page.tsx`

**Files:** Modify `src/app/admin/members/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")`
- `isManager` → use `ctx.role === "MANAGER"` instead of `session.user.role === "MANAGER"`.
- `session.user.teamId ?? "__none__"` (line 23) → `ctx.teamId ?? "__none__"`. Sentinel preserved verbatim.
- `Promise.all` on lines 28–44 stays untouched; add `db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })` as a 4th entry to fold the name fetch in.
- `session.user.role ?? "MEMBER"` on line 95 → `ctx.role` (no fallback needed — `ctx.role` is non-nullable).
- `session.user.teamId ?? null` on line 97 → `ctx.teamId` (already `string | null`).

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/members through withAuthScoped`.

---

### Task 5: Migrate `src/app/admin/tokens/page.tsx`

**Files:** Modify `src/app/admin/tokens/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")`
- `isManager = ctx.role === "MANAGER"`.
- Both `projectsWhere` and `membersWhere` use `session.user.teamId ?? "__none__"` → `ctx.teamId ?? "__none__"`.
- Two `db` calls (projects + members) — fold `db.user.findUnique` for name into a new `Promise.all([projects, members, user])` or just await it after. **Pick the parallel `Promise.all` for performance parity.**

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/tokens through withAuthScoped`.

---

### Task 6: Migrate `src/app/admin/audit/page.tsx`

**⚠ Atypical null behaviour:** Today the page redirects unauthenticated users to `/dashboard`, not `/login`:

```ts
if (!session || session.user.role !== "MANAGER") redirect("/dashboard")
```

**This must be preserved verbatim.** The post-migration line is:

```ts
if (!ctx || ctx.role !== "MANAGER") redirect("/dashboard")
```

Specifics:

- `orgId = ctx.organisationId` (no fallback — was `?? "__none__"`; null-safety now comes from the `!ctx` guard above).
- Fold `db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })` into the existing `Promise.all([entries, total])` on lines 42–51 — becomes a 3-tuple destructure.
- `<AppShell role={session.user.role}` → `<AppShell role={ctx.role}`.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/audit through withAuthScoped`.

---

### Task 7: Migrate `src/app/admin/github/page.tsx`

**Files:** Modify `src/app/admin/github/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")`.
- `const role = ctx.role`.
- Single `db.githubInstallation.findMany` — add `db.user.findUnique` as a parallel call.
- `session.user.name ?? "User"` on line 49 → DB fetch as above.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/github through withAuthScoped`.

---

### Task 8: Migrate `src/app/admin/consents/page.tsx`

**⚠ Atypical null behaviour:** Non-MANAGER users see an in-shell **403 panel** rather than a redirect (lines 14–39).

**Preserve this verbatim.** The post-migration top of the function looks like:

```ts
const ctx = await withAuthScoped()
if (!ctx) redirect("/login")

if (ctx.role !== "MANAGER") {
  // fetch name for the 403 shell to keep the header chip filled
  const user = await db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } })
  const userName = user?.name ?? "User"
  return (
    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
      ...403 panel JSX unchanged...
    </AppShell>
  )
}
```

This adds a tiny `db.user.findUnique` call for the 403-branch path so the AppShell header isn't blank — same approach as the success branch (which already needs the name). If the 403 branch should keep using a static "User" string to match prior behaviour exactly, drop the fetch and hard-code `userName = "User"`. **Default decision: do the fetch, for visual consistency.** Flag in the commit message.

Specifics for the success branch:

- `orgId = ctx.organisationId`.
- `db.user.findMany` query unchanged.
- Add `db.user.findUnique` for name (can run in `Promise.all` with the user-list query).
- `<AppShell role={session.user.role}` → `<AppShell role={ctx.role}`.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/consents through withAuthScoped`.

---

### Task 9: Migrate `src/app/admin/productive-rules/page.tsx`

**Files:** Modify `src/app/admin/productive-rules/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login"); if (ctx.role === "MEMBER") redirect("/dashboard")`.
- `const role = ctx.role; const teamId = ctx.teamId`.
- `orgId = ctx.organisationId`.
- Branching on role (MANAGER vs LINE_MANAGER) unchanged — both branches now read `ctx.teamId` not `session.user.teamId`.
- Fold `db.user.findUnique` for name into the Promise.all on lines 26–36 (MANAGER branch) and lines 39–51 (LINE_MANAGER branch). Easier: await it separately after the branch — keeps the diff small.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route admin/productive-rules through withAuthScoped`.

---

### Task 10: Migrate `src/app/teams/page.tsx`

**Files:** Modify `src/app/teams/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login")`.
- `needsConsentModal(session.user.id, session.user.teamId ?? null)` → `needsConsentModal(ctx.userId, ctx.teamId)`.
- `session.user.role === "MANAGER"` (both occurrences — line 18 query branching and line 80 JSX) → `ctx.role === "MANAGER"`.
- The page already DB-fetches `user.name` on lines 30–34 — keep that call exactly as-is, just change `where: { id: session.user.id }` → `where: { id: ctx.userId }`.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route teams index through withAuthScoped`.

---

### Task 11: Migrate `src/app/teams/[id]/page.tsx`

**Files:** Modify `src/app/teams/[id]/page.tsx`

Specifics:

- Auth guard: `if (!ctx) redirect("/login")`.
- Role/team scope check: `if (ctx.role !== "MANAGER" && ctx.teamId !== params.id) redirect("/teams")`.
- `needsConsentModal(ctx.userId, ctx.teamId)`.
- `Promise.all` on lines 25–55 stays — only swap `session.user.id` → `ctx.userId` inside the inline `db.user.findUnique` (4th element), and `orgId` source.
- `<AppShell role={session.user.role}` → `<AppShell role={ctx.role}`.
- `userId={session.user.id}` (line 145) → `userId={ctx.userId}`.

- [ ] Step 1–5: same shape. Commit: `refactor(auth): route teams/[id] through withAuthScoped`.

---

### Task 12: Final verification

**No file changes — verification only.**

- [ ] **Step 1: Confirm zero hits of the chokepoint-bypass pattern in pages**

Run: `grep -rn "session.user.organisationId" src/app --include="page.tsx"`
Expected: no output.

Run: `grep -rn "await auth()" src/app --include="page.tsx"`
Expected: only `src/app/install/page.tsx` remains (out-of-scope).

- [ ] **Step 2: Type-check baseline holds**

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | grep -v "src/tests/" | wc -l`
Expected: `0`.

Run: `npx tsc --noEmit 2>&1 | grep "error TS" | wc -l`
Expected: same number as baseline before the migration (whatever `src/tests/` drift exists today — should not grow).

- [ ] **Step 3: Test suite green**

Run: `npm test`
Expected: same green count as pre-migration. If any failure, it must trace to a behaviour intentionally changed in this plan (none expected).

- [ ] **Step 4: Production build passes**

Run: `npm run build`
Expected: build success, no new ESLint/type errors.

- [ ] **Step 5: Manual smoke (recommended)**

Sign in as MANAGER, MEMBER, and (if seeded) LINE_MANAGER. Visit each of the 12 pages. Confirm:
- MANAGER sees data on all 12 pages.
- MEMBER is redirected from admin/* and budget; sees dashboard, prompts, teams (own team only), teams/[id] (own team only).
- Unauthenticated browser is redirected to /login on all 11 (admin/audit redirects to /dashboard — preserved).
- AppShell header name matches the signed-in user on every page.

- [ ] **Step 6: No additional commit needed.** The 11 per-page commits are the full record of the change.

---

## Self-Review (completed by author)

**Spec coverage**: Each of the 12 pages in the user's scope has an explicit task (Task 0 is verify-only for dashboard; Tasks 1–11 migrate; Task 12 verifies the whole). Per-page null-handling is documented in the Scope table. The four trap conditions called out by the user are addressed:
1. Prisma queries identical — verification step in every task greps for `where:`/`orderBy:`/`select:` lines in the diff.
2. No changes to JWT/schema/withAuthScoped — confirmed: zero edits outside `page.tsx` files.
3. Silent empty-org renders → redirects — section "Notes on behaviour preservation" #3 explains and accepts this as the intended stricter behaviour.
4. tsc baseline — every task has a tsc step; final task checks the global count.

**Placeholder scan**: No "TBD" / "handle edge cases" / "similar to Task N without showing code" remain. Tasks 3–11 share the recipe from Task 2 but call out their specific delta (auth guard wording, sentinel preservation, fold-into-Promise.all decisions).

**Type consistency**: Variable names match across tasks (`ctx`, `orgId`, `userName`, `user`). `withAuthScoped` field names match `src/lib/withAuthScoped.ts:28–35` exactly (`userId`, `organisationId`, `role`, `teamId`, `isLineManager`, `scope`).
