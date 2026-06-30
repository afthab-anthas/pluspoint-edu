# Member Page Section Stagger — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the page-level section stagger entrance to the members page by adding `className="stagger"` to the existing outer wrapper div in `MembersClient.tsx` — zero new CSS.

**Architecture:** The `.stagger` utility in `globals.css` (lines 262–285) applies `fadeSlideIn` with nth-child delays to every direct child of a `.stagger` container. The `MembersClient` return already has a single outer `<div>` whose four direct children are exactly the sections to cascade: invite card → search bar → legend → table. Adding the class to that div is the entire change. All portals and modals are siblings of the stagger div (not children), so stacking contexts created during animation do not trap them.

**Tech Stack:** Next.js 14 App Router · TypeScript · CSS (globals.css `.stagger` utility, already present)

---

## File Structure

| Action | File | Change |
|---|---|---|
| Modify | `src/app/admin/members/_components/MembersClient.tsx:342` | Add `className="stagger"` to the outer wrapper `<div>` |

No new files. No new CSS. No migration. No new tests (there is no React Testing Library in this codebase; the behavior is visual-only and verified by tsc + existing test suite regression check + visual observation).

---

## Stacking-Context Safety Analysis

Before touching the code, confirm the modal architecture:

```
return (
  <>
    <div className="stagger">     ← stagger wrapper (lines 342–507)
      child 1: invite card
      child 2: search bar
      child 3: legend
      child 4: table
    </div>

    {/* createPortal(... document.body) */}  ← siblings, not children
    team dropdown portal
    role dropdown portal

    roleConfirm modal (position:fixed, z-index:10000)  ← sibling

    <MemberDetailCard ... />  ← sibling (uses its own portal)
  </>
)
```

**Why this is safe:**
- `createPortal(... document.body)` renders at document root — parent stacking contexts cannot trap portaled elements.
- The role confirm modal and `MemberDetailCard` are siblings of the stagger `<div>`, not descendants; their `position: fixed` z-indexes are unaffected.
- `animation-fill-mode: both` resolves to `opacity: 1; transform: none` after ~560ms — these values do not establish a stacking context, so the temporary stacking context from animation is short-lived.

---

## Task 1: Add section stagger to members page

**Files:**
- Modify: `src/app/admin/members/_components/MembersClient.tsx:342`

### Step 1: Confirm current state of line 342

Read the file to find the exact string to replace. The target line is:

```tsx
      <div>
```

This is the outer wrapper opened at the start of the return body. It wraps all four section children. Verify it has no existing `className` attribute before editing.

Run:
```bash
grep -n 'className' /c/Users/AfthabAnthas/repos/axis-pulse/src/app/admin/members/_components/MembersClient.tsx | head -20
```

Expected: line 342 should NOT appear (it currently has no className).

- [ ] **Step 2: Apply the change**

In `src/app/admin/members/_components/MembersClient.tsx`, find the exact outer wrapper `<div>` at line 342. The surrounding context is:

```tsx
  return (
    <>
      <div>
        {/* ── Invite form ── */}
        {(isManager || isLineManager) && (
```

Replace with:

```tsx
  return (
    <>
      <div className="stagger">
        {/* ── Invite form ── */}
        {(isManager || isLineManager) && (
```

That is the complete change — one word added.

- [ ] **Step 3: Confirm the reduced-motion guard covers this change**

The `.stagger` guard is at `globals.css:278–285`:

```css
@media (prefers-reduced-motion: reduce) {
  .stagger > * { animation: none !important; }
  ...
}
```

Because we're using `className="stagger"`, every child's animation is covered by this rule. No additional CSS needed.

- [ ] **Step 4: Run TypeScript check**

```bash
cd /c/Users/AfthabAnthas/repos/axis-pulse && npx tsc --noEmit 2>&1 | tail -5
```

Expected: exit 0, any errors are pre-existing in `src/tests/` only (known baseline; zero errors in `src/app/` or `src/lib/`).

- [ ] **Step 5: Run the full test suite**

```bash
cd /c/Users/AfthabAnthas/repos/axis-pulse && npm test 2>&1 | tail -20
```

Expected: same pass count as before (1515 tests passing). No new failures. A className addition to a React component does not affect any API or unit tests.

- [ ] **Step 6: Commit**

```bash
git add src/app/admin/members/_components/MembersClient.tsx
git commit -m "feat(members): add section stagger entrance via .stagger wrapper"
```

---

## Proving Target

Load `/admin/members` in the browser:

1. **Cascade visible** — the invite card fades+slides in at 0ms, search bar at 40ms, legend at 80ms, table at 120ms (each 240ms duration). The sections visually cascade like the KPI row on the cost dashboard.

2. **Role-badge/edit modal still works** — click a role badge → dropdown opens, make a selection → confirm modal opens, buttons are clickable. This verifies the stagger did not swallow clicks.

3. **Member detail card still works** — click a member row → `MemberDetailCard` panel opens, close button works, window toggle works. This verifies the portal is unaffected.

4. **Reduced-motion** — in DevTools → Rendering → Emulate CSS `prefers-reduced-motion: reduce`, reload. All four sections appear instantly with no animation. The cascade is fully suppressed.
