# Drift Phase 3 — Fix Scope and Hallucination Guard

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three proven bugs in Phase 3 AI drift analysis: (1) extend the hallucination guard to verify `evidenceCodeExcerpt` against actual shown code, (2) narrow docs sent to the AI to only diff-relevant ones instead of all 11, (3) pull code referenced by those docs so doc-side contradictions against unchanged code are catchable.

**Architecture:** All changes are in `src/lib/drift/analyse-ai.ts` and its test. No schema changes, no new files, no new packages. `parseDriftFindings` gains an optional 4th arg for code-content verification. `filterRelevantDocPaths` and `extractReferencedCodePaths` are new pure functions exported for unit testing. `buildDriftPrompt` gains a `changed: boolean` field on each entry to label CHANGED vs REFERENCED items in the prompt. `analyseAiDrift` orchestrates the new narrow-scope flow.

**Tech Stack:** TypeScript strict + noUncheckedIndexedAccess · Vitest · `authedGithubFetch` for GitHub Contents API · existing `readDocFile` / `readChangedCodeFiles` helpers

---

## File Map

| File | What changes |
|---|---|
| `src/lib/drift/analyse-ai.ts` | New helpers `isCodeExcerptInShownCode`, `filterRelevantDocPaths`, `extractReferencedCodePaths`; updated `parseDriftFindings` (4th arg), `buildDriftPrompt` (`changed` flag), `analyseAiDrift` (narrow scope + code-content map) |
| `src/tests/drift-analyse-ai.test.ts` | New test cases for each new helper + guard extension; existing `analyseAiDrift` tests updated to align with new mock counts |

---

## Budget Constants (set in analyse-ai.ts, quote in commit)

```typescript
const MAX_RELEVANT_DOCS = 5           // docs actually shown to AI
const MAX_DOC_FETCHES   = 12          // docs fetched to check relevance (GitHub calls)
const MAX_REFERENCED_CODE_FILES = 5   // extra code files pulled because docs describe them
// Total GitHub calls upper bound: MAX_DOC_FETCHES + changed_count + MAX_REFERENCED_CODE_FILES
// For SAVESENTRA (11 docs, 2 changed, 5 referenced) = 11 + 2 + 5 = 18 < 20 budget
```

---

## Task 1: Extend `parseDriftFindings` hallucination guard — verify `evidenceCodeExcerpt`

**Problem:** A finding can carry `evidenceCodeExcerpt: "sqlite3.connect('savesentra.db')"` quoting code that was never in the prompt. The guard checks only the `doc` field. This task adds a content-level check: if `evidenceCodeExcerpt` is present AND a code-contents map is supplied, the excerpt must be a substring of at least one shown file's content, else the finding is dropped.

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts:91-153`
- Modify: `src/tests/drift-analyse-ai.test.ts` (append to the `parseDriftFindings` describe block)

- [ ] **Step 1: Write four failing tests**

Append to the `describe("parseDriftFindings", ...)` block in `src/tests/drift-analyse-ai.test.ts` — AFTER the existing `it("omits optional fields...")` test at line 229, BEFORE the closing `})` of the describe:

```typescript
  it("drops finding where evidenceCodeExcerpt is absent from all shown code (hallucination guard)", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database",
        doc: "docs/architecture.md",
        description: "docs claim SQLite; code is CSV-only",
        evidenceDocExcerpt: "saves to savesentra.db",
        evidenceCodeExcerpt: "sqlite3.connect('savesentra.db')",
        severity: "HIGH",
        confidence: 0.95,
      },
    ])
    const codeContents = new Map<string, string>([
      ["rpi_server.py", "import csv\ncsv.writer(open('backup.csv', 'w'))"],
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS, codeContents)).toHaveLength(0)
  })

  it("keeps finding where evidenceCodeExcerpt IS found in shown code", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database",
        doc: "docs/architecture.md",
        description: "docs claim SQLite; code is CSV-only",
        evidenceDocExcerpt: "saves to savesentra.db",
        evidenceCodeExcerpt: "csv.writer(open('backup.csv', 'w'))",
        severity: "HIGH",
        confidence: 0.95,
      },
    ])
    const codeContents = new Map<string, string>([
      ["rpi_server.py", "import csv\ncsv.writer(open('backup.csv', 'w'))"],
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS, codeContents)).toHaveLength(1)
  })

  it("keeps finding with no evidenceCodeExcerpt when code contents are provided", () => {
    const raw = JSON.stringify([
      {
        type: "COVERAGE",
        area: "redaction",
        doc: "all docs",
        description: "lib/redact.mjs not covered by any doc",
        severity: "MEDIUM",
        confidence: 0.8,
      },
    ])
    const codeContents = new Map<string, string>([
      ["lib/redact.mjs", "export function redact(text: string) { return text }"],
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS, codeContents)).toHaveLength(1)
  })

  it("skips code-excerpt check when shownCodeContents is not provided (backward compat)", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database",
        doc: "docs/architecture.md",
        description: "mismatch",
        evidenceDocExcerpt: "saves to savesentra.db",
        evidenceCodeExcerpt: "sqlite3.connect('savesentra.db')",
        severity: "HIGH",
        confidence: 0.95,
      },
    ])
    // fourth arg omitted — no content map → guard skipped
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)).toHaveLength(1)
  })
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: the first two new tests FAIL (`toHaveLength(0)` gets 1, `toHaveLength(1)` gets 0 or 1 depending on current logic — both fail because the current guard does not check code content). The other two may or may not pass depending on current state. The point is the "drops" test fails.

- [ ] **Step 3: Add `isCodeExcerptInShownCode` helper and update `parseDriftFindings`**

In `src/lib/drift/analyse-ai.ts`, add the helper immediately after the `isDocInShownSet` function (after line 107):

```typescript
/**
 * Returns true when the excerpt text appears (case-insensitive substring) in
 * at least one shown code file's content. An empty excerpt always passes.
 * If the contents map is empty the check is skipped (backward-compat).
 */
function isCodeExcerptInShownCode(
  excerpt: string,
  shownCodeContents: ReadonlyMap<string, string>
): boolean {
  const needle = excerpt.trim().toLowerCase()
  if (needle.length === 0) return true
  for (const content of shownCodeContents.values()) {
    if (content.toLowerCase().includes(needle)) return true
  }
  return false
}
```

Update `parseDriftFindings` signature (line 113) to accept an optional fourth argument:

```typescript
export function parseDriftFindings(
  rawText: string,
  shownDocPaths: ReadonlySet<string>,
  shownCodePaths: ReadonlySet<string>,
  shownCodeContents?: ReadonlyMap<string, string>
): DriftFinding[] {
```

In the findings accumulation loop (currently around line 136), add the code-excerpt check immediately after the `isDocInShownSet` check:

```typescript
    if (!isDocInShownSet(item.doc, shownDocPaths, shownCodePaths)) continue
    // Drop findings whose code evidence does not appear in the shown code.
    if (
      typeof item.evidenceCodeExcerpt === "string" &&
      shownCodeContents !== undefined &&
      shownCodeContents.size > 0 &&
      !isCodeExcerptInShownCode(item.evidenceCodeExcerpt, shownCodeContents)
    ) continue
```

- [ ] **Step 4: Update `analyseAiDrift` to build and pass `shownCodeContents`**

In `src/lib/drift/analyse-ai.ts`, around line 389 where `shownDocPaths` and `shownCodePaths` are built:

```typescript
  // 7. Build prompt — sets to be passed to hallucination guard
  const shownDocPaths = new Set(docs.map((d) => d.path))
  const shownCodePaths = new Set(code.map((c) => c.path))
  const shownCodeContents = new Map(code.map((c) => [c.path, c.content]))
  const { system, user } = buildDriftPrompt(docs, code, assessment.diffTruncated)
```

And update the `parseDriftFindings` call (around line 419):

```typescript
  // 9. Parse findings — hallucination guard applied inside
  const findings = parseDriftFindings(rawText, shownDocPaths, shownCodePaths, shownCodeContents)
```

- [ ] **Step 5: Run all tests — expect green**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected output: all tests in `drift-analyse-ai.test.ts` pass, including the four new ones.

- [ ] **Step 6: Run full suite + tsc**

```
npx tsc --noEmit
npm test
```

Expected: 0 type errors, all existing tests still pass.

- [ ] **Step 7: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "fix(drift): extend hallucination guard to verify evidenceCodeExcerpt against shown code"
```

---

## Task 2: Add `filterRelevantDocPaths` — narrow doc scope to diff-relevant docs only

**Problem:** `analyseAiDrift` currently sends ALL docs to the AI. This floods the prompt with irrelevant content, drowning out the actual change. We need to filter the fetched doc set down to docs that (1) appear in the diff themselves or (2) textually reference a changed file's basename. Cap at `MAX_RELEVANT_DOCS = 5`.

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts` (new exported function + constants)
- Modify: `src/tests/drift-analyse-ai.test.ts` (new describe block)

- [ ] **Step 1: Write failing tests for `filterRelevantDocPaths`**

Add a new `describe("filterRelevantDocPaths", ...)` block to the test file (after the `parseDriftFindings` describe block, before the `readDocFile` block):

```typescript
import {
  parseDriftFindings,
  readDocFile,
  readChangedCodeFiles,
  buildDriftPrompt,
  analyseAiDrift,
  filterRelevantDocPaths,   // ← add to import
} from "@/lib/drift/analyse-ai"

// ... (later in file) ...

describe("filterRelevantDocPaths", () => {
  it("includes a doc that itself changed in the diff", () => {
    const docs = [
      { path: "docs/architecture.md", content: "General architecture overview." },
      { path: "docs/dataflow.md",     content: "Data pipeline description." },
    ]
    const result = filterRelevantDocPaths(["docs/architecture.md", "lib/redact.mjs"], docs)
    expect(result).toContain("docs/architecture.md")
  })

  it("includes a doc whose content references a changed file's basename", () => {
    const docs = [
      { path: "docs/architecture.md", content: "The server logic lives in rpi_server.py and reads CSV files." },
      { path: "docs/dataflow.md",     content: "Pipeline overview with no specific file references." },
    ]
    // rpi_server.py changed; architecture.md mentions "rpi_server.py"
    const result = filterRelevantDocPaths(["rpi_server.py"], docs)
    expect(result).toContain("docs/architecture.md")
    expect(result).not.toContain("docs/dataflow.md")
  })

  it("excludes docs that neither changed nor reference any changed file", () => {
    const docs = [
      { path: "docs/glossary.md", content: "Glossary of terms. No file references." },
    ]
    const result = filterRelevantDocPaths(["lib/redact.mjs"], docs)
    expect(result).toHaveLength(0)
  })

  it("caps result at maxDocs even when more are relevant", () => {
    const docs = Array.from({ length: 10 }, (_, i) => ({
      path: `docs/doc${i}.md`,
      content: "lib/redact.mjs is described here",
    }))
    const result = filterRelevantDocPaths(["lib/redact.mjs"], docs, 3)
    expect(result).toHaveLength(3)
  })

  it("returns empty array when no docs are relevant", () => {
    const docs = [{ path: "docs/unrelated.md", content: "nothing relevant" }]
    const result = filterRelevantDocPaths(["src/auth.ts"], docs)
    expect(result).toHaveLength(0)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: 5 new tests fail with "filterRelevantDocPaths is not a function" (not yet exported).

- [ ] **Step 3: Implement `filterRelevantDocPaths`**

Add after the `SKIP_FILENAMES` const block in `src/lib/drift/analyse-ai.ts`:

```typescript
export const MAX_RELEVANT_DOCS          = 5
export const MAX_DOC_FETCHES            = 12
export const MAX_REFERENCED_CODE_FILES  = 5

/**
 * Filters fetched docs to those relevant to the current diff.
 * Relevant = (1) the doc path itself appears in changedFiles, OR
 *            (2) the doc's text content references a changed file's basename.
 * Caps at maxDocs (default MAX_RELEVANT_DOCS) to limit AI prompt size.
 */
export function filterRelevantDocPaths(
  changedFiles: readonly string[],
  allDocs: ReadonlyArray<{ path: string; content: string }>,
  maxDocs: number = MAX_RELEVANT_DOCS
): string[] {
  const changedSet = new Set(changedFiles.map((f) => f.toLowerCase()))
  const baseNames = changedFiles.map((f) => {
    const idx = f.lastIndexOf("/")
    return f.slice(idx + 1).toLowerCase()
  })

  const relevant: string[] = []
  for (const doc of allDocs) {
    if (relevant.length >= maxDocs) break
    const docPathLower = doc.path.toLowerCase()

    if (changedSet.has(docPathLower)) {
      relevant.push(doc.path)
      continue
    }

    const contentLower = doc.content.toLowerCase()
    for (const base of baseNames) {
      if (base.length > 0 && contentLower.includes(base)) {
        relevant.push(doc.path)
        break
      }
    }
  }

  return relevant
}
```

- [ ] **Step 4: Run tests and verify green**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: all 5 new `filterRelevantDocPaths` tests pass plus all prior tests still pass.

- [ ] **Step 5: Wire `filterRelevantDocPaths` into `analyseAiDrift`**

Replace the doc-reading loop in `analyseAiDrift` (currently lines 378-383) with a two-pass approach: fetch all docs (up to `MAX_DOC_FETCHES`), then filter to relevant, then build the prompt from the filtered subset only:

```typescript
  // 5. Fetch doc content (up to MAX_DOC_FETCHES), then narrow to diff-relevant docs.
  const docFilesToFetch = resolved.files
    .filter((f) => f.path.endsWith(".md"))
    .slice(0, MAX_DOC_FETCHES)

  const allFetchedDocs: Array<{ path: string; content: string; truncated: boolean }> = []
  for (const docFile of docFilesToFetch) {
    const fetched = await readDocFile(installationId, fullName, docFile.path, ref)
    if (fetched) allFetchedDocs.push({ path: docFile.path, ...fetched })
  }

  const relevantDocPaths = new Set(
    filterRelevantDocPaths(
      assessment.changedFiles,
      allFetchedDocs.map((d) => ({ path: d.path, content: d.content }))
    )
  )
  const docs = allFetchedDocs.filter((d) => relevantDocPaths.has(d.path))
```

- [ ] **Step 6: Run full suite + tsc**

```
npx tsc --noEmit
npm test
```

Expected: 0 errors; all tests pass. (Existing `analyseAiDrift` tests mock `resolveContextSource` to return only 1 doc, so the narrow-scope path is exercised trivially — they still pass.)

- [ ] **Step 7: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "fix(drift): filter docs to diff-relevant subset, cap at MAX_RELEVANT_DOCS"
```

---

## Task 3: Add `extractReferencedCodePaths` + fetch referenced code so doc-side drift is catchable

**Problem:** Only CHANGED code files are shown to the AI. When a doc is edited to contradict UNCHANGED code (e.g., `architecture.md` now claims SQLite, but `rpi_server.py` is CSV-only and didn't change), the contradiction is invisible. Fix: after filtering relevant docs, scan their content for explicit code file path references and fetch those files, even though they aren't in the diff.

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts` (new exported function + orchestrator update)
- Modify: `src/tests/drift-analyse-ai.test.ts` (new describe block)

- [ ] **Step 1: Write failing tests for `extractReferencedCodePaths`**

Add a new describe block after the `filterRelevantDocPaths` block:

```typescript
import {
  parseDriftFindings,
  readDocFile,
  readChangedCodeFiles,
  buildDriftPrompt,
  analyseAiDrift,
  filterRelevantDocPaths,
  extractReferencedCodePaths,  // ← add to import
} from "@/lib/drift/analyse-ai"

// ... describe block ...

describe("extractReferencedCodePaths", () => {
  it("extracts a .py file reference from doc content", () => {
    const paths = extractReferencedCodePaths(
      ["The main entry point is rpi_server.py and reads data."],
      new Set(),
    )
    expect(paths).toContain("rpi_server.py")
  })

  it("extracts a path-prefixed reference like lib/redact.mjs", () => {
    const paths = extractReferencedCodePaths(
      ["Redaction is handled by lib/redact.mjs in the pipeline."],
      new Set(),
    )
    expect(paths).toContain("lib/redact.mjs")
  })

  it("skips paths already in alreadyFetchedPaths", () => {
    const already = new Set(["rpi_server.py"])
    const paths = extractReferencedCodePaths(
      ["See rpi_server.py and lib/redact.mjs for details."],
      already,
    )
    expect(paths).not.toContain("rpi_server.py")
    expect(paths).toContain("lib/redact.mjs")
  })

  it("caps result at maxFiles", () => {
    const docContent =
      "Files: a.py b.py c.ts d.ts e.mjs f.go g.py h.rs i.ts j.py all matter here."
    const paths = extractReferencedCodePaths([docContent], new Set(), 3)
    expect(paths.length).toBeLessThanOrEqual(3)
  })

  it("returns empty array when no code file references found", () => {
    const paths = extractReferencedCodePaths(["Only prose. No file refs here."], new Set())
    expect(paths).toHaveLength(0)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: 5 new tests fail with "extractReferencedCodePaths is not a function".

- [ ] **Step 3: Implement `extractReferencedCodePaths`**

Add after `filterRelevantDocPaths` in `src/lib/drift/analyse-ai.ts`:

```typescript
// Matches bare filenames or path-prefixed names with known source extensions.
// Uses a word-boundary-like approach: preceded/followed by whitespace, quote, backtick, or line edge.
const CODE_FILE_REF_RE =
  /(?:^|[\s`'"(,])([a-zA-Z0-9_\-./]+\.(?:ts|tsx|js|mjs|cjs|py|go|rb|rs|java|sh|bash))(?=[\s`'")\n,.]|$)/gm

/**
 * Scans doc content strings for explicit code file path references (files
 * with known source extensions). Returns unique paths not already in
 * alreadyFetchedPaths, capped at maxFiles.
 */
export function extractReferencedCodePaths(
  docContents: readonly string[],
  alreadyFetchedPaths: ReadonlySet<string>,
  maxFiles: number = MAX_REFERENCED_CODE_FILES
): string[] {
  const found = new Set<string>()

  for (const content of docContents) {
    if (found.size >= maxFiles) break
    for (const match of content.matchAll(CODE_FILE_REF_RE)) {
      const p = match[1]
      if (p !== undefined && !alreadyFetchedPaths.has(p) && !found.has(p)) {
        found.add(p)
        if (found.size >= maxFiles) break
      }
    }
  }

  return Array.from(found)
}
```

- [ ] **Step 4: Run tests to verify green**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: 5 new `extractReferencedCodePaths` tests pass, all prior tests still pass.

- [ ] **Step 5: Wire referenced-code fetching into `analyseAiDrift`**

After the changed-code reading step in `analyseAiDrift` (after step 6 "Read changed code files"), insert:

```typescript
  // 6b. Fetch code files referenced by the in-scope docs but not in the diff.
  //     This makes doc-side drift catchable: if a doc was edited to claim X about
  //     an unchanged file, we fetch that file so the AI can spot the contradiction.
  const alreadyFetched = new Set(code.map((c) => c.path))
  const referencedPaths = extractReferencedCodePaths(
    docs.map((d) => d.content),
    alreadyFetched
  )
  const referencedCode = await readChangedCodeFiles(
    installationId,
    fullName,
    referencedPaths,
    ref
  )
  const allCode = [...code, ...referencedCode]
```

Then update all subsequent uses of `code` to `allCode`:

```typescript
  const shownDocPaths = new Set(docs.map((d) => d.path))
  const shownCodePaths = new Set(allCode.map((c) => c.path))
  const shownCodeContents = new Map(allCode.map((c) => [c.path, c.content]))
  const { system, user } = buildDriftPrompt(docs, allCode, assessment.diffTruncated)
```

- [ ] **Step 6: Run full suite + tsc**

```
npx tsc --noEmit
npm test
```

Expected: 0 errors, all tests pass.

- [ ] **Step 7: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "fix(drift): fetch code files referenced by in-scope docs so doc-side drift is catchable"
```

---

## Task 4: Update `buildDriftPrompt` to label CHANGED vs REFERENCED items

**Problem:** The AI cannot tell which docs/code files are from the actual diff vs pulled as context. When the AI sees 5 files it naturally returns the "loudest" finding (a pre-existing issue) instead of focusing on what changed. Labelling each item forces the AI's attention to the diff.

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts` (update `buildDriftPrompt` signature + prompt template + `analyseAiDrift` callers)
- Modify: `src/tests/drift-analyse-ai.test.ts` (update `buildDriftPrompt` tests)

- [ ] **Step 1: Write failing tests**

Update the `describe("buildDriftPrompt", ...)` block to add two new tests (existing tests that don't pass `changed` will need to be updated to include the field):

```typescript
  it("labels changed docs with CHANGED and context docs with CONTEXT", () => {
    const docs = [
      { path: "docs/architecture.md", content: "claims SQLite", truncated: false, changed: true },
      { path: "docs/dataflow.md",     content: "pipeline overview", truncated: false, changed: false },
    ]
    const { user } = buildDriftPrompt(docs, [], false)
    expect(user).toMatch(/CHANGED.*docs\/architecture\.md/i)
    expect(user).toMatch(/CONTEXT.*docs\/dataflow\.md/i)
  })

  it("labels changed code with CHANGED and referenced code with REFERENCED", () => {
    const code = [
      { path: "lib/redact.mjs", content: "export fn redact", truncated: false, changed: true },
      { path: "rpi_server.py",  content: "csv.writer", truncated: false, changed: false },
    ]
    const { user } = buildDriftPrompt([], code, false)
    expect(user).toMatch(/CHANGED.*lib\/redact\.mjs/i)
    expect(user).toMatch(/REFERENCED.*rpi_server\.py/i)
  })
```

Also update the two existing `buildDriftPrompt` tests that pass doc/code arrays to add the `changed` field to avoid TypeScript errors:

```typescript
  it("includes doc content and code content in user prompt", () => {
    const docs = [{ path: "docs/architecture.md", content: "The system uses SQLite.", truncated: false, changed: true }]
    const code = [{ path: "rpi_server.py", content: "csv.writer(open('backup.csv'))", truncated: false, changed: false }]
    // ... rest unchanged
  })

  it("marks truncated docs with a notice", () => {
    const docs = [{ path: "docs/architecture.md", content: "...", truncated: true, changed: true }]
    // ...
  })
```

- [ ] **Step 2: Run tests to verify they fail**

```
npm test -- --testPathPattern=drift-analyse-ai
```

Expected: the two new `buildDriftPrompt` tests fail (label keywords not present); existing tests may fail with TypeScript if `changed` field is required.

- [ ] **Step 3: Update `buildDriftPrompt` signature and prompt template**

In `src/lib/drift/analyse-ai.ts`, update the function signature at line ~276:

```typescript
export function buildDriftPrompt(
  docs: ReadonlyArray<{ path: string; content: string; truncated: boolean; changed: boolean }>,
  code: ReadonlyArray<{ path: string; content: string; truncated: boolean; changed: boolean }>,
  diffTruncated: boolean
): { system: string; user: string } {
```

Update the docs map section:

```typescript
  const docsSection =
    docs.length === 0
      ? "(No context documentation found)"
      : docs
          .map(({ path, content, truncated, changed }) => {
            const label = changed ? "CHANGED DOC" : "CONTEXT DOC"
            const truncMark = truncated ? " [TRUNCATED at 100KB]" : ""
            return `=== ${label}: ${path}${truncMark} ===\n${content}`
          })
          .join("\n\n")
```

Update the code map section:

```typescript
  const codeSection =
    code.length === 0
      ? "(No code files to analyse)"
      : code
          .map(({ path, content, truncated, changed }) => {
            const label = changed ? "CHANGED CODE" : "REFERENCED CODE"
            const truncMark = truncated ? " [TRUNCATED]" : ""
            return `=== ${label}: ${path}${truncMark} ===\n${content}`
          })
          .join("\n\n")
```

- [ ] **Step 4: Update `analyseAiDrift` callers to add `changed` flag**

In `analyseAiDrift`, update the `allFetchedDocs` filtering:

```typescript
  // Attach changed flag to each doc
  const changedSet = new Set(assessment.changedFiles.map((f: string) => f.toLowerCase()))
  const docs = allFetchedDocs
    .filter((d) => relevantDocPaths.has(d.path))
    .map((d) => ({ ...d, changed: changedSet.has(d.path.toLowerCase()) }))
```

Update the `code` array built by `readChangedCodeFiles`:

```typescript
  const changedCodeRaw = await readChangedCodeFiles(installationId, fullName, assessment.changedFiles, ref)
  const code = changedCodeRaw.map((c) => ({ ...c, changed: true as const }))
```

Update the `referencedCode` array:

```typescript
  const referencedCode = (await readChangedCodeFiles(installationId, fullName, referencedPaths, ref))
    .map((c) => ({ ...c, changed: false as const }))
```

- [ ] **Step 5: Run full suite + tsc**

```
npx tsc --noEmit
npm test
```

Expected: 0 errors, all tests pass.

- [ ] **Step 6: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "fix(drift): label CHANGED vs REFERENCED docs and code in AI prompt so model focuses on diff"
```

---

## Task 5: Update docs

- [ ] **Step 1: Update `docs/decisions.md` with new ADR**

Append to `docs/decisions.md`:

```markdown
## ADR-drift-phase3-scope-fix (2026-06-17)

**Context:** Phase 3 drift analysis sent all ~11 docs (~84K tokens) on every run, causing the AI to return the loudest pre-existing finding rather than analysing the diff. Two additional bugs: (1) referenced code not fetched so doc-side contradictions against unchanged code were invisible; (2) hallucination guard only checked the `doc` field, not the code evidence.

**Decision:**
1. Fetch all doc files to check relevance, but show only diff-relevant docs to the AI (cap: `MAX_RELEVANT_DOCS=5`). Relevance = doc changed in diff OR doc content references a changed file's basename.
2. Extract explicit code-file-path references from in-scope docs and fetch those files (cap: `MAX_REFERENCED_CODE_FILES=5`), so doc-side contradictions against unchanged code are catchable.
3. Extend `parseDriftFindings` to drop any finding whose `evidenceCodeExcerpt` is absent from the actual shown code content (case-insensitive substring match).
4. Label each doc/code block in the prompt as CHANGED or REFERENCED so the model focuses on what actually changed.

**Constraints:** Total GitHub calls per assessment ≤ `MAX_DOC_FETCHES(12) + changedCount + MAX_REFERENCED_CODE_FILES(5)` ≈ 18-20. Token budget target: well under 60K input tokens vs prior 84K+.
```

- [ ] **Step 2: Update `docs/risk.md` — close P-drift-hallucination item**

Add a resolved note to the drift section:

```markdown
- **P-drift-hallucination (RESOLVED 2026-06-17):** Phase 3 hallucination guard now verifies `evidenceCodeExcerpt` against shown code content. Resolved by `fix(drift): extend hallucination guard` commit.
```

- [ ] **Step 3: Run full suite one final time**

```
npx tsc --noEmit
npm test
```

Expected: 0 errors, all tests green.

- [ ] **Step 4: Commit**

```
git add docs/decisions.md docs/risk.md
git commit -m "docs(drift): ADR for Phase 3 scope fix and hallucination guard extension"
```

---

## Self-Review

**Spec coverage check:**
- (a) Narrow docs to diff-relevant → Tasks 2 + wiring in Task 3/4 ✓
- (b) Pull referenced code → Task 3 ✓
- (c) Verify evidenceCodeExcerpt → Task 1 ✓
- Budget guard (MAX_RELEVANT_DOCS, MAX_DOC_FETCHES, MAX_REFERENCED_CODE_FILES) → Task 2 ✓
- Prompt marks CHANGED vs REFERENCED → Task 4 ✓
- PROVING TARGET (AC4 re-test: catch ACCURACY + COVERAGE, no hallucinated evidence) → addressed by (b)+(c) ✓
- No model/chain changes → maintained throughout ✓
- Docs update → Task 5 ✓

**Placeholder scan:** None found. All code blocks contain actual runnable code.

**Type consistency check:**
- `changed: boolean` field added in Task 4 to the array items — used consistently in `buildDriftPrompt` signature and both array maps in `analyseAiDrift`.
- `filterRelevantDocPaths` returns `string[]` and is called with `.map(d => ({ path, content }))` from `allFetchedDocs` — types align.
- `extractReferencedCodePaths` takes `ReadonlySet<string>` for the already-fetched set — built with `new Set(code.map(c => c.path))` which is `Set<string>` — assignable ✓.
- `parseDriftFindings` 4th arg is `ReadonlyMap<string, string> | undefined` (optional) — built with `new Map(allCode.map(c => [c.path, c.content]))` — type `Map<string, string>` — assignable ✓.
