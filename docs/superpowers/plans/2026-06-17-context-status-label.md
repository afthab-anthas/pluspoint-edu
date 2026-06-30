# Context Status Label Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a minimal two-fact status label to the Context Analyser per-project detail page showing the resolved context source and baseline state.

**Architecture:** Extract a pure `deriveDisplaySource` helper into `src/lib/drift/context-status.ts` (testable in node env), compute it server-side in `page.tsx` using `latestComplete.contextSource` as the single source of truth (same data the assessment pipeline wrote), and pass it as a new prop to `DriftDetailClient`, which renders it as a compact `ContextStatusLabel` row between the h1 and the risk-badge row.

**Tech Stack:** TypeScript 5 · Next.js 14 App Router (server + client components) · Vitest (node env) · existing design system (teal `#0ee29e`, `var(--text-muted)`, `var(--text-secondary)`, IBM Plex Mono)

---

## File Map

| Action | Path | Responsibility |
|--------|------|---------------|
| Create | `src/lib/drift/context-status.ts` | `DisplayContextSource` type · `deriveDisplaySource()` · `contextSourceLabel()` |
| Create | `src/tests/drift-context-status.test.ts` | Unit tests for the two helpers |
| Modify | `src/app/context-analyser/[id]/page.tsx` | Import helper, derive prop, pass `resolvedContextSource` to client |
| Modify | `src/app/context-analyser/[id]/_components/DriftDetailClient.tsx` | Accept new prop, add `ContextStatusLabel` sub-component, insert in header |

---

## Task 1: Pure helper + unit tests (TDD)

**Files:**
- Create: `src/lib/drift/context-status.ts`
- Create: `src/tests/drift-context-status.test.ts`

### Why a separate utility?

`DriftDetailClient.tsx` is `"use client"` and `page.tsx` is a Next.js server component — neither is easily imported by Vitest in node mode. Extracting the two-line derivation logic into a plain `.ts` file makes it importable and directly testable without mocking React or Next.js.

---

- [ ] **Step 1: Write the failing tests**

Create `src/tests/drift-context-status.test.ts`:

```typescript
// src/tests/drift-context-status.test.ts
import { describe, it, expect } from "vitest"
import {
  deriveDisplaySource,
  contextSourceLabel,
  type DisplayContextSource,
} from "@/lib/drift/context-status"

describe("deriveDisplaySource", () => {
  it("returns context_builds when last complete assessment used context_builds", () => {
    expect(deriveDisplaySource("context_builds", true)).toBe("context_builds")
  })

  it("returns docs_on_default when last complete assessment used docs_on_default", () => {
    expect(deriveDisplaySource("docs_on_default", true)).toBe("docs_on_default")
  })

  it("returns none when last complete assessment source was none", () => {
    expect(deriveDisplaySource("none", true)).toBe("none")
  })

  it("returns none when no GitHub repo is linked, regardless of prior assessment", () => {
    expect(deriveDisplaySource(null, false)).toBe("none")
    expect(deriveDisplaySource("context_builds", false)).toBe("none")
  })

  it("returns unknown when GitHub is linked but no complete assessment has run", () => {
    expect(deriveDisplaySource(null, true)).toBe("unknown")
  })

  it("returns unknown when source is undefined and GitHub is linked", () => {
    expect(deriveDisplaySource(undefined, true)).toBe("unknown")
  })
})

describe("contextSourceLabel", () => {
  it("labels context_builds as 'context_builds branch'", () => {
    expect(contextSourceLabel("context_builds")).toBe("context_builds branch")
  })

  it("labels docs_on_default as '/docs folder'", () => {
    expect(contextSourceLabel("docs_on_default")).toBe("/docs folder")
  })

  it("labels none as 'none'", () => {
    expect(contextSourceLabel("none")).toBe("none")
  })

  it("labels unknown as 'not yet assessed'", () => {
    expect(contextSourceLabel("unknown")).toBe("not yet assessed")
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npx vitest run src/tests/drift-context-status.test.ts
```

Expected: FAIL — `Cannot find module '@/lib/drift/context-status'`

- [ ] **Step 3: Write the minimal implementation**

Create `src/lib/drift/context-status.ts`:

```typescript
import type { ContextSource } from "@/lib/repo-context/drift-github"

export type DisplayContextSource = ContextSource | "unknown"

/**
 * Derives the context source to display from the last *complete* assessment's
 * stored contextSource field — the same value resolveContextSource() wrote —
 * so the label always agrees with reality.
 *
 * Falls back to "none" when no GitHub repo is linked (source can't exist).
 * Falls back to "unknown" when GitHub is linked but no complete assessment yet.
 */
export function deriveDisplaySource(
  lastCompleteContextSource: string | null | undefined,
  hasGithubRepo: boolean
): DisplayContextSource {
  if (!hasGithubRepo) return "none"
  if (lastCompleteContextSource === "context_builds") return "context_builds"
  if (lastCompleteContextSource === "docs_on_default") return "docs_on_default"
  if (lastCompleteContextSource === "none") return "none"
  return "unknown"
}

/** Human-readable label for a DisplayContextSource. */
export function contextSourceLabel(source: DisplayContextSource): string {
  if (source === "context_builds") return "context_builds branch"
  if (source === "docs_on_default") return "/docs folder"
  if (source === "none") return "none"
  return "not yet assessed"
}
```

- [ ] **Step 4: Run tests to verify they pass**

```
npx vitest run src/tests/drift-context-status.test.ts
```

Expected: all 10 tests PASS

- [ ] **Step 5: Run full test suite to confirm no regressions**

```
npm test
```

Expected: all tests pass (existing count + 10 new)

- [ ] **Step 6: Commit**

```
git add src/lib/drift/context-status.ts src/tests/drift-context-status.test.ts
git commit -m "feat(drift): deriveDisplaySource + contextSourceLabel helpers — phase-05b"
```

---

## Task 2: Server page passes `resolvedContextSource`

**Files:**
- Modify: `src/app/context-analyser/[id]/page.tsx` lines 1–121

- [ ] **Step 1: Add import**

In `page.tsx`, add to the import block (after the existing imports):

```typescript
import { deriveDisplaySource, type DisplayContextSource } from "@/lib/drift/context-status"
```

- [ ] **Step 2: Derive `resolvedContextSource` after `noContextBuild` is computed (line 71)**

After:
```typescript
const noContextBuild =
    latestComplete?.contextSource === "none" ||
    (!project.githubRepoFullName)
```

Add:
```typescript
const resolvedContextSource: DisplayContextSource = deriveDisplaySource(
  latestComplete?.contextSource,
  !!project.githubRepoFullName
)
```

- [ ] **Step 3: Pass the new prop to `DriftDetailClient`**

In the `<DriftDetailClient ... />` call, add after `hasBaselineSha={!!project.contextBranchBaselineSha}`:

```tsx
resolvedContextSource={resolvedContextSource}
```

- [ ] **Step 4: Type-check**

```
npx tsc --noEmit
```

Expected: error — `resolvedContextSource` not in `DriftDetailClientProps` yet (Task 3 closes this)

---

## Task 3: `DriftDetailClient` renders `ContextStatusLabel`

**Files:**
- Modify: `src/app/context-analyser/[id]/_components/DriftDetailClient.tsx`

- [ ] **Step 1: Add import**

At the top of `DriftDetailClient.tsx`, add:

```typescript
import { contextSourceLabel, type DisplayContextSource } from "@/lib/drift/context-status"
```

- [ ] **Step 2: Add `resolvedContextSource` to `DriftDetailClientProps`**

In the `DriftDetailClientProps` interface, add after `hasBaselineSha: boolean`:

```typescript
resolvedContextSource: DisplayContextSource
```

- [ ] **Step 3: Destructure the new prop**

In the function signature, add `resolvedContextSource` alongside `hasBaselineSha`:

```typescript
export function DriftDetailClient({
  projectId,
  projectName,
  latestAssessment: initialAssessment,
  findings: initialFindings,
  docBreakdown: initialDocBreakdown,
  noContextBuild,
  hasBaselineSha,
  resolvedContextSource,
}: DriftDetailClientProps) {
```

- [ ] **Step 4: Insert `ContextStatusLabel` into the header `<div>` below the `<h1>`**

In the header section, after the `<h1>` close tag and before the existing sub-row `<div style={{ marginTop: "0.4rem" ...`:

```tsx
<ContextStatusLabel source={resolvedContextSource} hasBaseline={hasBaselineSha} />
```

- [ ] **Step 5: Add the `ContextStatusLabel` sub-component**

After the existing `SectionLabel` function at the bottom of the file, add:

```tsx
function ContextStatusLabel({
  source,
  hasBaseline,
}: {
  source: DisplayContextSource
  hasBaseline: boolean
}) {
  const sourceText = contextSourceLabel(source)
  const baselineColor = hasBaseline ? "#0ee29e" : "var(--text-secondary)"
  const baselineText = hasBaseline ? "set" : "not set yet"

  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "0.3rem",
        marginTop: "0.35rem",
        marginBottom: "0.1rem",
        fontSize: "11.5px",
        lineHeight: 1.4,
        flexWrap: "wrap",
      }}
    >
      <span style={{ color: "var(--text-muted)" }}>Context source:</span>
      <span
        style={{
          fontFamily: "var(--font-mono, monospace)",
          fontSize: "11px",
          color: source === "none" || source === "unknown"
            ? "var(--text-muted)"
            : "var(--text-secondary)",
        }}
      >
        {sourceText}
      </span>
      {source !== "none" && source !== "unknown" && (
        <>
          <span style={{ color: "var(--text-muted)", opacity: 0.45, fontSize: "10px" }}>·</span>
          <span style={{ color: "var(--text-muted)" }}>Baseline:</span>
          <span
            style={{
              fontFamily: "var(--font-mono, monospace)",
              fontSize: "11px",
              color: baselineColor,
            }}
          >
            {baselineText}
          </span>
        </>
      )}
    </div>
  )
}
```

**Design rationale:** "none" and "unknown" don't show the Baseline fact because there's nothing meaningful to say — you can't have a useful baseline on a project with no context source. The separator dot and baseline appear only when a real source was found.

- [ ] **Step 6: Type-check**

```
npx tsc --noEmit
```

Expected: exit 0

- [ ] **Step 7: Run full test suite**

```
npm test
```

Expected: all tests pass

- [ ] **Step 8: Commit**

```
git add src/app/context-analyser/[id]/page.tsx src/app/context-analyser/[id]/_components/DriftDetailClient.tsx
git commit -m "feat(drift): context status label in project detail header — phase-05b"
```

---

## Proving targets check

| Scenario | `resolvedContextSource` | `hasBaselineSha` | Label renders |
|---|---|---|---|
| Marlin Test Repo (context_builds, baseline set) | `"context_builds"` | `true` | `Context source: context_builds branch · Baseline: set` |
| No-context project (no GitHub / source=none) | `"none"` | `false` | `Context source: none` (no baseline fact) |
| Has docs, no baseline | `"docs_on_default"` | `false` | `Context source: /docs folder · Baseline: not set yet` |
| GitHub linked, never assessed | `"unknown"` | `false` | `Context source: not yet assessed` (no baseline fact) |

---

## Self-review

**Spec coverage:**
- ✅ Two facts shown: context source + baseline
- ✅ Source from `latestComplete.contextSource` — same data `resolveContextSource()` wrote; no re-resolution
- ✅ `"none"` + no-context explainer coexist without duplication (label is compact; `NoContextBuildState` is the fuller explainer; they're in different parts of the render tree)
- ✅ Button greyed when `!hasBaselineSha` already in `RunButton` — label explains WHY via "Baseline: not set yet"
- ✅ Display only — no changes to baseline logic, assessment logic, or resolution logic
- ✅ Minimal: two facts, one row, no SHA/timestamp/doc-count

**No placeholders:** All code blocks are complete.

**Type consistency:** `DisplayContextSource` is defined in Task 1, imported and used identically in Tasks 2 and 3.
