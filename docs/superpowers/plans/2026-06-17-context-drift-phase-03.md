# Context Drift Analyser — Phase 3: AI Drift Analysis

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Given a `ContextDriftAssessment` record with `changedFiles` already populated (Phase 2 output), read the project's context docs from GitHub, read the changed code from GitHub, call the AI via `getAutoCallModel()`, parse the structured findings, and store `findings`, `findingsCount`, `aiTokensUsed`, and `costUSD` on the assessment record.

**Architecture:** Single new lib file `src/lib/drift/analyse-ai.ts` containing (a) the pure `parseDriftFindings` parser, (b) GitHub file readers (`readDocFile`, `readChangedCodeFiles`), (c) prompt builder, and (d) the `analyseAiDrift` orchestrator. The orchestrator re-resolves the context source from GitHub to get the doc file list, reads each doc and each changed code file, calls the AI with `maxOutputTokens: 4096` and `maxRetries: 0`, then updates the assessment record.

**Tech Stack:** Vitest · `getAutoCallModel()` from `src/lib/ai-provider.ts` · `generateText` from `ai` · `authedGithubFetch` from `src/lib/repo-context/client.ts` · `resolveContextSource` from `src/lib/repo-context/drift-github.ts` · `redact()` from `src/lib/redact.ts` · `isCeilingExceeded` from `src/lib/cost-ceiling.ts` · Prisma `db.contextDriftAssessment.update`

---

## Pre-read before touching any code

Read these in full before editing:
- `src/lib/drift/assess-diff.ts` — Phase 2 output: the record shape, fields stored
- `src/lib/repo-context/drift-github.ts` — `resolveContextSource`, `getLatestCommitSha`
- `src/lib/narrate.ts` lines 307–318 — exact `getAutoCallModel()` + `generateText` call pattern to mirror
- `src/lib/redact.ts` — `redact()` signature and the 1500-char truncation limit
- `src/lib/cost-ceiling.ts` — `INPUT_COST_PER_TOKEN`, `OUTPUT_COST_PER_TOKEN`, `isCeilingExceeded`
- `prisma/schema.prisma` lines 599–627 — `ContextDriftAssessment` model fields

## File structure

| Path | Action | Purpose |
|------|--------|---------|
| `src/lib/drift/analyse-ai.ts` | **Create** | All Phase 3 logic: types, parser, readers, prompt builder, orchestrator |
| `src/tests/drift-analyse-ai.test.ts` | **Create** | Unit tests for every exported function |

No existing files are modified in this phase.

---

## Task 1: `parseDriftFindings` — defensive JSON parser + hallucination guard

**Files:**
- Create: `src/lib/drift/analyse-ai.ts`
- Create: `src/tests/drift-analyse-ai.test.ts`

This is a pure function (no IO). Write it and its tests first.

- [ ] **Step 1.1: Write the failing test**

Create `src/tests/drift-analyse-ai.test.ts`:

```typescript
// src/tests/drift-analyse-ai.test.ts
import { describe, it, expect } from "vitest"
import { parseDriftFindings } from "@/lib/drift/analyse-ai"

const DOC_PATHS = new Set(["docs/architecture.md", "docs/dataflow.md"])
const CODE_PATHS = new Set(["lib/redact.mjs", "rpi_server.py"])

describe("parseDriftFindings", () => {
  it("parses a valid array of findings", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database backup",
        doc: "docs/architecture.md",
        description: "docs claim SQLite backup; code is CSV-only",
        evidenceDocExcerpt: "saves to savesentra.db",
        evidenceCodeExcerpt: "csv.writer(open('backup.csv', 'w'))",
        severity: "HIGH",
        confidence: 0.95,
      },
    ])
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
    expect(results[0]?.type).toBe("ACCURACY")
    expect(results[0]?.severity).toBe("HIGH")
    expect(results[0]?.evidenceDocExcerpt).toBe("saves to savesentra.db")
  })

  it("strips markdown code fences before parsing", () => {
    const raw =
      "```json\n" +
      JSON.stringify([
        {
          type: "COVERAGE",
          area: "redaction module",
          doc: "lib/redact.mjs",
          description: "lib/redact.mjs has no doc coverage",
          severity: "MEDIUM",
          confidence: 0.8,
        },
      ]) +
      "\n```"
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
    expect(results[0]?.type).toBe("COVERAGE")
  })

  it("returns empty array for malformed JSON", () => {
    expect(parseDriftFindings("not valid json {{{", DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("returns empty array when JSON is not an array", () => {
    expect(parseDriftFindings('{"type":"ACCURACY"}', DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("returns empty array for empty string", () => {
    expect(parseDriftFindings("", DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("returns empty array for empty findings array", () => {
    expect(parseDriftFindings("[]", DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("drops findings missing required fields", () => {
    const raw = JSON.stringify([
      { type: "ACCURACY", area: "auth" }, // missing doc, description, severity, confidence
      {
        type: "COVERAGE",
        area: "redaction module",
        doc: "lib/redact.mjs",
        description: "lib/redact.mjs not documented in any doc",
        severity: "MEDIUM",
        confidence: 0.8,
      },
    ])
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
    expect(results[0]?.area).toBe("redaction module")
  })

  it("drops findings with invalid type value", () => {
    const raw = JSON.stringify([
      {
        type: "SOMETHING_ELSE",
        area: "auth",
        doc: "docs/architecture.md",
        description: "mismatch",
        severity: "HIGH",
        confidence: 0.9,
      },
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("drops findings with invalid severity value", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "auth",
        doc: "docs/architecture.md",
        description: "mismatch",
        severity: "CRITICAL", // invalid
        confidence: 0.9,
      },
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("drops findings where doc field references a file not shown to the AI (hallucination guard)", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "security",
        doc: "external/unseen_doc.md", // not in shownDocPaths or shownCodePaths
        description: "Something was wrong",
        severity: "HIGH",
        confidence: 0.9,
      },
    ])
    expect(parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)).toEqual([])
  })

  it("accepts 'all docs' as a valid doc reference for COVERAGE findings", () => {
    const raw = JSON.stringify([
      {
        type: "COVERAGE",
        area: "redaction",
        doc: "all docs",
        description: "lib/redact.mjs not mentioned in any doc",
        severity: "MEDIUM",
        confidence: 0.85,
      },
    ])
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
  })

  it("caps findings at 10 even if AI returns more", () => {
    const manyFindings = Array.from({ length: 15 }, (_, i) => ({
      type: "COVERAGE",
      area: `area-${i}`,
      doc: "docs/architecture.md",
      description: `Finding ${i}`,
      severity: "LOW",
      confidence: 0.5,
    }))
    const results = parseDriftFindings(JSON.stringify(manyFindings), DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(10)
  })

  it("omits optional fields when AI does not include them", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database",
        doc: "docs/dataflow.md",
        description: "schema mismatch",
        // no evidenceDocExcerpt, no evidenceCodeExcerpt
        severity: "LOW",
        confidence: 0.6,
      },
    ])
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
    expect(results[0]?.evidenceDocExcerpt).toBeUndefined()
    expect(results[0]?.evidenceCodeExcerpt).toBeUndefined()
  })

  it("matches doc field partially — 'architecture.md' matches 'docs/architecture.md'", () => {
    const raw = JSON.stringify([
      {
        type: "ACCURACY",
        area: "auth",
        doc: "architecture.md", // basename only
        description: "AI uses Groq but docs say Claude",
        severity: "HIGH",
        confidence: 0.9,
      },
    ])
    const results = parseDriftFindings(raw, DOC_PATHS, CODE_PATHS)
    expect(results).toHaveLength(1)
  })
})
```

- [ ] **Step 1.2: Run test to confirm it fails**

```
npm test -- drift-analyse-ai
```

Expected: FAIL — `Cannot find module '@/lib/drift/analyse-ai'`

- [ ] **Step 1.3: Implement `parseDriftFindings` in `src/lib/drift/analyse-ai.ts`**

Create the file:

```typescript
// src/lib/drift/analyse-ai.ts

// ── Types ──────────────────────────────────────────────────────────────────

export type DriftFindingType = "ACCURACY" | "COVERAGE"
export type DriftFindingSeverity = "LOW" | "MEDIUM" | "HIGH"

export interface DriftFinding {
  type: DriftFindingType
  area: string
  doc: string
  description: string
  evidenceDocExcerpt?: string
  evidenceCodeExcerpt?: string
  severity: DriftFindingSeverity
  confidence: number
}

export type AnalyseAiSuccess = {
  ok: true
  findings: DriftFinding[]
  aiTokensUsed: number
  costUSD: number
}

export type AnalyseAiFailure = {
  ok: false
  error:
    | "assessment_not_found"
    | "wrong_org"
    | "project_not_found"
    | "no_context_source"
    | "ceiling_exceeded"
    | "ai_error"
  message?: string
}

export type AnalyseAiResult = AnalyseAiSuccess | AnalyseAiFailure

// ── Constants ──────────────────────────────────────────────────────────────

const MAX_FINDINGS = 10
/** ~100 KB per doc file; matches spec DOC_SIZE_CAP */
export const DOC_SIZE_CAP = 102_400
/** Total bytes across all changed code files fed to the AI */
const CODE_TOTAL_CAP = 204_800
/** Extensions never worth reading as text */
const BINARY_EXTENSIONS = new Set([
  ".png", ".jpg", ".jpeg", ".gif", ".svg", ".ico",
  ".woff", ".woff2", ".ttf", ".eot",
  ".zip", ".gz", ".tar",
  ".pdf", ".mp4", ".mp3",
])
/** Lock / generated files — too large, zero signal for drift */
const SKIP_FILENAMES = new Set([
  "package-lock.json", "yarn.lock", "pnpm-lock.yaml",
  "bun.lockb", "Cargo.lock", "poetry.lock",
])

// ── parseDriftFindings ─────────────────────────────────────────────────────

function isValidFinding(x: unknown): x is DriftFinding {
  if (typeof x !== "object" || x === null) return false
  const f = x as Record<string, unknown>
  if (f["type"] !== "ACCURACY" && f["type"] !== "COVERAGE") return false
  if (typeof f["area"] !== "string" || f["area"].trim().length === 0) return false
  if (typeof f["doc"] !== "string" || f["doc"].trim().length === 0) return false
  if (typeof f["description"] !== "string" || f["description"].trim().length === 0) return false
  if (f["severity"] !== "LOW" && f["severity"] !== "MEDIUM" && f["severity"] !== "HIGH") return false
  if (typeof f["confidence"] !== "number" || f["confidence"] < 0 || f["confidence"] > 1) return false
  if (f["evidenceDocExcerpt"] !== undefined && typeof f["evidenceDocExcerpt"] !== "string") return false
  if (f["evidenceCodeExcerpt"] !== undefined && typeof f["evidenceCodeExcerpt"] !== "string") return false
  return true
}

/**
 * Returns true when the AI's `doc` field can be traced back to a file we
 * actually showed to the model, preventing hallucinated citations.
 * Case-insensitive; accepts partial matches in either direction so that
 * "architecture.md" matches "docs/architecture.md" and vice versa.
 * The special value "all docs" is always accepted (used for COVERAGE findings).
 */
function isDocInShownSet(
  docField: string,
  shownDocPaths: ReadonlySet<string>,
  shownCodePaths: ReadonlySet<string>
): boolean {
  const lower = docField.toLowerCase().trim()
  if (lower === "all docs" || lower === "all") return true
  for (const p of shownDocPaths) {
    const pL = p.toLowerCase()
    if (lower.includes(pL) || pL.includes(lower)) return true
  }
  for (const p of shownCodePaths) {
    const pL = p.toLowerCase()
    if (lower.includes(pL) || pL.includes(lower)) return true
  }
  return false
}

/**
 * Parses the raw AI text into a validated, hallucination-guarded array of
 * DriftFinding objects. Never throws — malformed input returns [].
 *
 * @param rawText      Raw text from the AI (may include markdown code fences)
 * @param shownDocPaths  Paths of doc files that were included in the prompt
 * @param shownCodePaths Paths of code files that were included in the prompt
 */
export function parseDriftFindings(
  rawText: string,
  shownDocPaths: ReadonlySet<string>,
  shownCodePaths: ReadonlySet<string>
): DriftFinding[] {
  const candidate = rawText
    .replace(/^```(?:json)?\s*/i, "")
    .replace(/\s*```\s*$/, "")
    .trim()

  if (candidate.length === 0) return []

  let parsed: unknown
  try {
    parsed = JSON.parse(candidate)
  } catch {
    return []
  }

  if (!Array.isArray(parsed)) return []

  const findings: DriftFinding[] = []
  for (const item of parsed) {
    if (!isValidFinding(item)) continue
    if (!isDocInShownSet(item.doc, shownDocPaths, shownCodePaths)) continue
    const finding: DriftFinding = {
      type: item.type,
      area: item.area,
      doc: item.doc,
      description: item.description,
      severity: item.severity,
      confidence: item.confidence,
    }
    if (typeof item.evidenceDocExcerpt === "string") finding.evidenceDocExcerpt = item.evidenceDocExcerpt
    if (typeof item.evidenceCodeExcerpt === "string") finding.evidenceCodeExcerpt = item.evidenceCodeExcerpt
    findings.push(finding)
    if (findings.length >= MAX_FINDINGS) break
  }

  return findings
}

// ── Placeholders for Tasks 2–5 (stubs exported so tsc stays green) ─────────

export { DOC_SIZE_CAP as _DOC_SIZE_CAP_INTERNAL }
```

- [ ] **Step 1.4: Run tests to confirm they pass**

```
npm test -- drift-analyse-ai
```

Expected: all 12 tests PASS.

- [ ] **Step 1.5: Confirm TypeScript is clean**

```
npx tsc --noEmit
```

Expected: exit 0 (no new errors in `src/`).

- [ ] **Step 1.6: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "feat(drift): add parseDriftFindings with hallucination guard (Phase 3 Ref-3xx)"
```

---

## Task 2: `redactLargeText` + `readDocFile` — fetch a doc from GitHub

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts`
- Modify: `src/tests/drift-analyse-ai.test.ts`

- [ ] **Step 2.1: Write the failing tests**

Add to `src/tests/drift-analyse-ai.test.ts` (before the closing brace):

```typescript
// ── readDocFile tests ───────────────────────────────────────────────────────

vi.mock("@/lib/repo-context/client", () => ({
  authedGithubFetch: vi.fn(),
}))

import { authedGithubFetch } from "@/lib/repo-context/client"
import { readDocFile } from "@/lib/drift/analyse-ai"

const mockFetch = vi.mocked(authedGithubFetch)

describe("readDocFile", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("returns decoded content for a valid base64 GitHub response", async () => {
    const raw = "# Architecture\nThis system uses SQLite for backup."
    const b64 = Buffer.from(raw).toString("base64")
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ content: b64, encoding: "base64" }),
    } as Response)

    const result = await readDocFile("inst-1", "org/repo", "docs/architecture.md", "main")
    expect(result).not.toBeNull()
    expect(result?.content).toContain("Architecture")
    expect(result?.truncated).toBe(false)
  })

  it("returns null when GitHub returns non-200", async () => {
    mockFetch.mockResolvedValue({ ok: false, status: 404 } as Response)
    const result = await readDocFile("inst-1", "org/repo", "docs/architecture.md", "main")
    expect(result).toBeNull()
  })

  it("marks truncated=true when content exceeds DOC_SIZE_CAP", async () => {
    const bigText = "x".repeat(200_000)
    const b64 = Buffer.from(bigText).toString("base64")
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ content: b64, encoding: "base64" }),
    } as Response)

    const result = await readDocFile("inst-1", "org/repo", "docs/big.md", "main")
    expect(result?.truncated).toBe(true)
    expect(result?.content.length).toBeLessThanOrEqual(102_400)
  })
})
```

- [ ] **Step 2.2: Run test — confirm FAIL**

```
npm test -- drift-analyse-ai
```

Expected: FAIL — `readDocFile is not exported from '@/lib/drift/analyse-ai'`

- [ ] **Step 2.3: Implement `redactLargeText` + `readDocFile`**

Add to `src/lib/drift/analyse-ai.ts` (after the parseDriftFindings function, before the placeholder stubs):

```typescript
import { redact } from "@/lib/redact"
import { authedGithubFetch } from "@/lib/repo-context/client"

/**
 * Applies redact() across the full text in 1500-char chunks so secrets are
 * caught regardless of position, without hitting redact()'s own 1500-char cap.
 */
function redactLargeText(input: string): string {
  const CHUNK = 1_500
  let out = ""
  for (let i = 0; i < input.length; i += CHUNK) {
    out += redact(input.slice(i, i + CHUNK)).text
  }
  return out
}

/**
 * Reads one file from GitHub Contents API and returns its text content,
 * redacted and capped at DOC_SIZE_CAP bytes. Returns null if the file
 * cannot be fetched (404, permissions error, etc.).
 */
export async function readDocFile(
  installationId: string,
  fullName: string,
  filePath: string,
  ref: string
): Promise<{ content: string; truncated: boolean } | null> {
  const resp = await authedGithubFetch(
    `https://api.github.com/repos/${fullName}/contents/${encodeURIComponent(filePath)}?ref=${ref}`,
    installationId
  )
  if (!resp.ok) return null

  const data = (await resp.json()) as { content?: string; encoding?: string }
  let raw = ""
  if (data.encoding === "base64" && data.content) {
    raw = Buffer.from(data.content.replace(/\n/g, ""), "base64").toString("utf-8")
  } else if (typeof data.content === "string") {
    raw = data.content
  }

  const truncated = raw.length > DOC_SIZE_CAP
  const capped = raw.slice(0, DOC_SIZE_CAP)
  const cleaned = redactLargeText(capped)

  return { content: cleaned, truncated }
}
```

Also add at the top of the file (imports section):
```typescript
import { redact } from "@/lib/redact"
import { authedGithubFetch } from "@/lib/repo-context/client"
```

- [ ] **Step 2.4: Run tests — confirm PASS**

```
npm test -- drift-analyse-ai
```

Expected: all tests PASS.

- [ ] **Step 2.5: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "feat(drift): add readDocFile with chunked redaction (Phase 3 Ref-3xx)"
```

---

## Task 3: `readChangedCodeFiles` — fetch changed source files from GitHub

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts`
- Modify: `src/tests/drift-analyse-ai.test.ts`

- [ ] **Step 3.1: Write failing tests**

Add to `src/tests/drift-analyse-ai.test.ts`:

```typescript
import { readChangedCodeFiles } from "@/lib/drift/analyse-ai"

describe("readChangedCodeFiles", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("fetches only non-doc, non-binary, non-lock files", async () => {
    const raw = "def backup(): pass"
    const b64 = Buffer.from(raw).toString("base64")
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ content: b64, encoding: "base64" }),
    } as Response)

    const changedFiles = [
      "rpi_server.py",
      "docs/architecture.md",   // should be skipped — is a doc
      "package-lock.json",       // should be skipped — is a lock file
      "logo.png",                // should be skipped — is binary
    ]

    const result = await readChangedCodeFiles("inst-1", "org/repo", changedFiles, "head-sha")
    // only rpi_server.py should have been read
    expect(result).toHaveLength(1)
    expect(result[0]?.path).toBe("rpi_server.py")
    expect(result[0]?.content).toContain("backup")
  })

  it("skips files where GitHub returns non-200", async () => {
    mockFetch.mockResolvedValue({ ok: false, status: 404 } as Response)
    const result = await readChangedCodeFiles("inst-1", "org/repo", ["lib/redact.mjs"], "head-sha")
    expect(result).toHaveLength(0)
  })
})
```

- [ ] **Step 3.2: Run test — confirm FAIL**

```
npm test -- drift-analyse-ai
```

Expected: FAIL — `readChangedCodeFiles is not exported`

- [ ] **Step 3.3: Implement `readChangedCodeFiles`**

Add to `src/lib/drift/analyse-ai.ts`:

```typescript
/**
 * Reads the text content of changed source files from GitHub.
 * Skips: doc files (.md), binary extensions, lock files, and files that 404.
 * Caps total bytes across all files at CODE_TOTAL_CAP.
 */
export async function readChangedCodeFiles(
  installationId: string,
  fullName: string,
  changedFiles: readonly string[],
  headSha: string
): Promise<Array<{ path: string; content: string; truncated: boolean }>> {
  const results: Array<{ path: string; content: string; truncated: boolean }> = []
  let totalBytes = 0

  for (const filePath of changedFiles) {
    if (totalBytes >= CODE_TOTAL_CAP) break

    const ext = filePath.slice(filePath.lastIndexOf(".")).toLowerCase()
    const basename = filePath.slice(filePath.lastIndexOf("/") + 1)
    if (ext === ".md") continue
    if (BINARY_EXTENSIONS.has(ext)) continue
    if (SKIP_FILENAMES.has(basename)) continue

    const fetched = await readDocFile(installationId, fullName, filePath, headSha)
    if (!fetched) continue

    const remaining = CODE_TOTAL_CAP - totalBytes
    const content = fetched.content.slice(0, remaining)
    const truncated = fetched.truncated || content.length < fetched.content.length
    totalBytes += content.length
    results.push({ path: filePath, content, truncated })
  }

  return results
}
```

- [ ] **Step 3.4: Run tests — confirm PASS**

```
npm test -- drift-analyse-ai
```

- [ ] **Step 3.5: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "feat(drift): add readChangedCodeFiles with skip filters (Phase 3 Ref-3xx)"
```

---

## Task 4: `buildDriftPrompt` — strict anti-false-positive prompt

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts`
- Modify: `src/tests/drift-analyse-ai.test.ts`

- [ ] **Step 4.1: Write failing tests**

Add to `src/tests/drift-analyse-ai.test.ts`:

```typescript
import { buildDriftPrompt } from "@/lib/drift/analyse-ai"

describe("buildDriftPrompt", () => {
  it("includes doc content and code content in user prompt", () => {
    const docs = [{ path: "docs/architecture.md", content: "The system uses SQLite.", truncated: false }]
    const code = [{ path: "rpi_server.py", content: "csv.writer(open('backup.csv'))", truncated: false }]
    const { user } = buildDriftPrompt(docs, code, false)
    expect(user).toContain("docs/architecture.md")
    expect(user).toContain("The system uses SQLite.")
    expect(user).toContain("rpi_server.py")
    expect(user).toContain("csv.writer")
  })

  it("includes truncation notice when diff was truncated", () => {
    const { user } = buildDriftPrompt([], [], true)
    expect(user).toMatch(/truncated|partial/i)
  })

  it("system prompt forbids cosmetic findings", () => {
    const { system } = buildDriftPrompt([], [], false)
    expect(system).toMatch(/cosmetic|style|format|version number/i)
  })

  it("system prompt requires both doc quote and code quote in each finding", () => {
    const { system } = buildDriftPrompt([], [], false)
    expect(system).toMatch(/evidenceDocExcerpt/i)
    expect(system).toMatch(/evidenceCodeExcerpt/i)
  })
})
```

- [ ] **Step 4.2: Run test — confirm FAIL**

```
npm test -- drift-analyse-ai
```

- [ ] **Step 4.3: Implement `buildDriftPrompt`**

Add to `src/lib/drift/analyse-ai.ts`:

```typescript
const DRIFT_SYSTEM_PROMPT = `You are a technical documentation auditor. Your ONLY job is to find SPECIFIC, VERIFIABLE disagreements between a project's documentation and its actual source code.

You will receive CONTEXT DOCS (documentation files) and CHANGED CODE (source files changed since the docs were last updated).

## Your task

Identify ONLY:
- **ACCURACY** findings: a doc explicitly states X, but the code clearly and demonstrably shows Y — a direct contradiction
- **COVERAGE** findings: the code contains a significant feature, module, or pattern that is entirely absent from ALL provided docs

## Rules you MUST follow (violations will cause your response to be discarded)

1. Flag ONLY specific contradictions. Each finding must cite the EXACT doc sentence that contradicts the EXACT code statement.
2. IGNORE completely: version number differences, file path renames without semantic change, formatting or whitespace, comment-only changes, coding style differences.
3. IGNORE: TODOs, partially-complete features, minor implementation details that docs intentionally omit.
4. IGNORE: anything that is not a substantive technical disagreement about how the system works.
5. Do NOT flag a COVERAGE gap unless the feature is genuinely significant AND absent from ALL docs shown to you. Utility helpers, small constants, and internal details are not worth flagging.
6. Each ACCURACY finding must include both evidenceDocExcerpt (exact doc quote) and evidenceCodeExcerpt (exact code snippet).
7. Confidence must reflect how certain you are that this is a real contradiction, not a cosmetic difference.
8. Return at most 10 findings, ranked by severity. If you find none, return [].
9. Return ONLY a raw JSON array. No explanation, no markdown fences, no preamble.

## Output format (each element)

{
  "type": "ACCURACY" | "COVERAGE",
  "area": "<short name for the area, e.g. 'database backup', 'authentication provider'>",
  "doc": "<the doc filename where the claim appears, or 'all docs' for COVERAGE>",
  "description": "<what the doc claims vs what the code shows — be specific>",
  "evidenceDocExcerpt": "<exact quote from the doc>",
  "evidenceCodeExcerpt": "<exact code snippet that contradicts or is absent from docs>",
  "severity": "LOW" | "MEDIUM" | "HIGH",
  "confidence": <0.0–1.0>
}`

export function buildDriftPrompt(
  docs: ReadonlyArray<{ path: string; content: string; truncated: boolean }>,
  code: ReadonlyArray<{ path: string; content: string; truncated: boolean }>,
  diffTruncated: boolean
): { system: string; user: string } {
  const docsSection = docs.length === 0
    ? "(No context documentation found)"
    : docs
        .map(({ path, content, truncated }) =>
          `=== DOC: ${path}${truncated ? " [TRUNCATED at 100KB]" : ""} ===\n${content}`
        )
        .join("\n\n")

  const codeSection = code.length === 0
    ? "(No changed code files to analyse)"
    : code
        .map(({ path, content, truncated }) =>
          `=== CODE: ${path}${truncated ? " [TRUNCATED]" : ""} ===\n${content}`
        )
        .join("\n\n")

  const truncationNotice = diffTruncated
    ? "\n⚠️  NOTE: The diff was truncated — only a partial set of changed files is shown. Focus only on the files provided.\n"
    : ""

  const user =
    `${truncationNotice}` +
    `## CONTEXT DOCS (documentation that describes how the system works)\n\n${docsSection}\n\n` +
    `## CHANGED CODE (source files that changed since the docs were last updated)\n\n${codeSection}\n\n` +
    `Analyse for ACCURACY and COVERAGE drift. Return the JSON array only.`

  return { system: DRIFT_SYSTEM_PROMPT, user }
}
```

- [ ] **Step 4.4: Run tests — confirm PASS**

```
npm test -- drift-analyse-ai
```

- [ ] **Step 4.5: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "feat(drift): add buildDriftPrompt with strict anti-FP system prompt (Phase 3 Ref-3xx)"
```

---

## Task 5: `analyseAiDrift` — orchestrator (DB + GitHub + AI + store)

**Files:**
- Modify: `src/lib/drift/analyse-ai.ts` (add all remaining imports + `analyseAiDrift`)
- Modify: `src/tests/drift-analyse-ai.test.ts`

Add the `generateText` + `db` imports at the top of `analyse-ai.ts`:

```typescript
import { generateText } from "ai"
import { db } from "@/lib/db"
import { getAutoCallModel } from "@/lib/ai-provider"
import { resolveContextSource } from "@/lib/repo-context/drift-github"
import { isCeilingExceeded, INPUT_COST_PER_TOKEN, OUTPUT_COST_PER_TOKEN } from "@/lib/cost-ceiling"
```

- [ ] **Step 5.1: Write failing tests**

Add to `src/tests/drift-analyse-ai.test.ts`:

```typescript
vi.mock("@/lib/db", () => ({
  db: {
    contextDriftAssessment: { findUnique: vi.fn(), update: vi.fn() },
    project: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/ai-provider", () => ({
  getAutoCallModel: vi.fn(() => ({ model: "mock-model", modelId: "mock-id" })),
}))

vi.mock("ai", () => ({
  generateText: vi.fn(),
}))

vi.mock("@/lib/repo-context/drift-github", () => ({
  resolveContextSource: vi.fn(),
}))

vi.mock("@/lib/cost-ceiling", () => ({
  isCeilingExceeded: vi.fn(() => Promise.resolve(false)),
  INPUT_COST_PER_TOKEN: 3e-6,
  OUTPUT_COST_PER_TOKEN: 15e-6,
}))

import { db } from "@/lib/db"
import { generateText } from "ai"
import { resolveContextSource } from "@/lib/repo-context/drift-github"
import { analyseAiDrift } from "@/lib/drift/analyse-ai"

const mockDb = vi.mocked(db)
const mockGenerate = vi.mocked(generateText)
const mockResolve = vi.mocked(resolveContextSource)

const fakeAssessment = {
  id: "assess-1",
  projectId: "proj-1",
  organisationId: "org-1",
  contextSource: "docs_on_default",
  headSha: "head-abc",
  changedFiles: ["lib/redact.mjs", "rpi_server.py"],
  diffTruncated: false,
}

const fakeProject = {
  id: "proj-1",
  organisationId: "org-1",
  githubRepoFullName: "org/savesentra",
  githubInstallationId: "inst-1",
  repoMetadata: { defaultBranch: "main" },
  tenantKey: null,
}

describe("analyseAiDrift", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it("stores findings on the assessment record and returns ok:true", async () => {
    mockDb.contextDriftAssessment.findUnique.mockResolvedValue(fakeAssessment as never)
    mockDb.project.findUnique.mockResolvedValue(fakeProject as never)
    mockResolve.mockResolvedValue({
      source: "docs_on_default",
      files: [{ path: "docs/architecture.md", sha: "sha1", size: 1000 }],
      headSha: "head-abc",
    })

    // doc fetch returns architecture content
    mockFetch
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          content: Buffer.from("## Architecture\nThe system uses SQLite for backup.").toString("base64"),
          encoding: "base64",
        }),
      } as Response)
      // code fetch for lib/redact.mjs
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          content: Buffer.from("// redact.mjs — no doc coverage").toString("base64"),
          encoding: "base64",
        }),
      } as Response)
      // code fetch for rpi_server.py
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          content: Buffer.from("import csv; csv.writer(open('backup.csv', 'w'))").toString("base64"),
          encoding: "base64",
        }),
      } as Response)

    const findingsJson = JSON.stringify([
      {
        type: "ACCURACY",
        area: "database backup",
        doc: "docs/architecture.md",
        description: "docs claim SQLite; code is CSV-only",
        evidenceDocExcerpt: "uses SQLite for backup",
        evidenceCodeExcerpt: "csv.writer(open('backup.csv', 'w'))",
        severity: "HIGH",
        confidence: 0.95,
      },
    ])
    mockGenerate.mockResolvedValue({
      text: findingsJson,
      usage: { promptTokens: 500, completionTokens: 200 },
    } as never)

    mockDb.contextDriftAssessment.update.mockResolvedValue({} as never)

    const result = await analyseAiDrift("assess-1", "org-1")
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.findings).toHaveLength(1)
    expect(result.findings[0]?.type).toBe("ACCURACY")
    expect(mockDb.contextDriftAssessment.update).toHaveBeenCalledWith(
      expect.objectContaining({
        where: { id: "assess-1", organisationId: "org-1" },
        data: expect.objectContaining({
          findings: expect.any(Array),
          findingsCount: 1,
        }),
      })
    )
  })

  it("returns ceiling_exceeded when cost ceiling is hit", async () => {
    const { isCeilingExceeded: mockCeiling } = await import("@/lib/cost-ceiling")
    vi.mocked(mockCeiling).mockResolvedValueOnce(true)
    mockDb.contextDriftAssessment.findUnique.mockResolvedValue(fakeAssessment as never)
    mockDb.project.findUnique.mockResolvedValue(fakeProject as never)

    const result = await analyseAiDrift("assess-1", "org-1")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("ceiling_exceeded")
    expect(mockGenerate).not.toHaveBeenCalled()
  })

  it("returns assessment_not_found when record missing", async () => {
    mockDb.contextDriftAssessment.findUnique.mockResolvedValue(null)
    const result = await analyseAiDrift("no-such-id", "org-1")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("assessment_not_found")
  })

  it("returns wrong_org when organisationId mismatches", async () => {
    mockDb.contextDriftAssessment.findUnique.mockResolvedValue({
      ...fakeAssessment,
      organisationId: "other-org",
    } as never)
    const result = await analyseAiDrift("assess-1", "org-1")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("wrong_org")
  })
})
```

- [ ] **Step 5.2: Run test — confirm FAIL**

```
npm test -- drift-analyse-ai
```

- [ ] **Step 5.3: Implement `analyseAiDrift`**

Add to `src/lib/drift/analyse-ai.ts`:

```typescript
/**
 * AI drift analysis orchestrator — Phase 3 entry point.
 *
 * Loads the assessment record (org-scoped), reads the context docs and
 * changed code from GitHub, calls the AI, parses findings, and stores
 * findings + token usage on the assessment record.
 *
 * Status cycle management is Phase 4; this function is callable at any
 * assessment status and always writes the result unconditionally.
 */
export async function analyseAiDrift(
  assessmentId: string,
  organisationId: string
): Promise<AnalyseAiResult> {
  // 1. Load assessment (org-scoped read)
  const assessment = await db.contextDriftAssessment.findUnique({
    where: { id: assessmentId },
    select: {
      id: true,
      organisationId: true,
      projectId: true,
      contextSource: true,
      headSha: true,
      changedFiles: true,
      diffTruncated: true,
    },
  })

  if (!assessment) return { ok: false, error: "assessment_not_found" }
  if (assessment.organisationId !== organisationId) return { ok: false, error: "wrong_org" }

  // 2. Load project for GitHub credentials
  const project = await db.project.findUnique({
    where: { id: assessment.projectId },
    select: {
      id: true,
      organisationId: true,
      githubRepoFullName: true,
      githubInstallationId: true,
      repoMetadata: true,
      tenantKey: true,
    },
  })

  if (!project || !project.githubRepoFullName || !project.githubInstallationId) {
    return { ok: false, error: "project_not_found" }
  }

  const installationId = project.githubInstallationId
  const fullName = project.githubRepoFullName
  const meta = project.repoMetadata as { defaultBranch?: string } | null
  const defaultBranch = meta?.defaultBranch ?? "main"
  const ref = assessment.headSha ?? defaultBranch

  // 3. Check cost ceiling before making any AI call
  if (await isCeilingExceeded(organisationId)) {
    return { ok: false, error: "ceiling_exceeded", message: "Monthly AI cost ceiling exceeded" }
  }

  // 4. Re-resolve context source to get the doc file list
  const resolved = await resolveContextSource(installationId, fullName, defaultBranch)
  if (resolved.source === "none") {
    return { ok: false, error: "no_context_source" }
  }

  // 5. Read doc files from GitHub (filter to .md only)
  const docFiles = resolved.files.filter((f) => f.path.endsWith(".md"))
  const docs: Array<{ path: string; content: string; truncated: boolean }> = []
  for (const docFile of docFiles) {
    const fetched = await readDocFile(installationId, fullName, docFile.path, ref)
    if (fetched) docs.push({ path: docFile.path, ...fetched })
  }

  // 6. Read changed code files
  const code = await readChangedCodeFiles(
    installationId,
    fullName,
    assessment.changedFiles,
    ref
  )

  // 7. Build prompt
  const shownDocPaths = new Set(docs.map((d) => d.path))
  const shownCodePaths = new Set(code.map((c) => c.path))
  const { system, user } = buildDriftPrompt(docs, code, assessment.diffTruncated)

  // 8. Call AI (mirrors narrate.ts pattern exactly, maxOutputTokens raised for this call)
  const { model, modelId } = getAutoCallModel()

  let rawText: string
  let inputTokens: number
  let outputTokens: number
  try {
    const { text, usage } = await generateText({
      model,
      system,
      messages: [{ role: "user", content: user }],
      maxOutputTokens: 4096,
      maxRetries: 0, // OpenRouter handles cross-model failover; SDK must not also retry
    })
    rawText = text
    inputTokens = usage.promptTokens ?? 0
    outputTokens = usage.completionTokens ?? 0
  } catch (err) {
    return {
      ok: false,
      error: "ai_error",
      message: err instanceof Error ? err.message : String(err),
    }
  }

  // 9. Parse findings defensively
  const findings = parseDriftFindings(rawText, shownDocPaths, shownCodePaths)

  // 10. Compute cost estimate (matches cost-ceiling.ts convention; approximate for free-tier models)
  const costUSD = inputTokens * INPUT_COST_PER_TOKEN + outputTokens * OUTPUT_COST_PER_TOKEN
  const aiTokensUsed = inputTokens + outputTokens

  // 11. Store findings on the assessment record (org-scoped write)
  await db.contextDriftAssessment.update({
    where: { id: assessmentId, organisationId },
    data: {
      findings: findings as unknown as import("@/generated/prisma").Prisma.InputJsonValue,
      findingsCount: findings.length,
      aiTokensUsed,
      costUSD,
      updatedAt: new Date(),
    },
  })

  return { ok: true, findings, aiTokensUsed, costUSD }
}
```

Also add the full import block at the top of `analyse-ai.ts`:

```typescript
import { generateText } from "ai"
import { db } from "@/lib/db"
import { getAutoCallModel } from "@/lib/ai-provider"
import { resolveContextSource } from "@/lib/repo-context/drift-github"
import { isCeilingExceeded, INPUT_COST_PER_TOKEN, OUTPUT_COST_PER_TOKEN } from "@/lib/cost-ceiling"
```

- [ ] **Step 5.4: Run all tests**

```
npm test -- drift-analyse-ai
```

Expected: all tests PASS.

- [ ] **Step 5.5: Run full test suite**

```
npm test
```

Expected: all existing tests remain green.

- [ ] **Step 5.6: TypeScript check**

```
npx tsc --noEmit
```

Expected: exit 0.

- [ ] **Step 5.7: Commit**

```
git add src/lib/drift/analyse-ai.ts src/tests/drift-analyse-ai.test.ts
git commit -m "feat(drift): add analyseAiDrift orchestrator with AI call + findings store (Phase 3 Ref-3xx)"
```

---

## Task 6: Integration prove — SAVESENTRA (AC4)

**No new files — use a one-off Node script via `npx tsx`.**

Proving target: SAVESENTRA project, baseline=`836495d`, head=`fff8fb5`. Expected: COVERAGE (lib/redact.mjs) + ACCURACY (SQLite claim in docs).

- [ ] **Step 6.1: Find the SAVESENTRA project ID and set the baseline**

```bash
# In the running app's database (via psql or Prisma Studio):
# Find project:
npx prisma studio
# OR:
npx tsx -e "
  const { db } = await import('./src/lib/db.ts')
  const p = await db.project.findFirst({ where: { githubRepoFullName: { contains: 'savesentra' } }, select: { id: true, name: true, contextBranchBaselineSha: true } })
  console.log(p)
"
```

- [ ] **Step 6.2: Set baseline SHA and run the prove**

Create a temporary script `scripts/prove-drift-phase3.ts`:

```typescript
import { db } from "@/lib/db"
import { assessDiff } from "@/lib/drift/assess-diff"
import { analyseAiDrift } from "@/lib/drift/analyse-ai"

const PROJECT_GITHUB_NAME = "savesentra"  // adjust to match actual fullName
const ORG_ID = "your-org-id"              // replace with real org ID from DB
const BASELINE_SHA = "836495d"
const HEAD_SHA = "fff8fb5"

async function run() {
  // 1. Find the project
  const project = await db.project.findFirst({
    where: { githubRepoFullName: { contains: PROJECT_GITHUB_NAME } },
    select: { id: true, organisationId: true, githubRepoFullName: true },
  })
  if (!project) { console.error("Project not found"); process.exit(1) }
  console.log("Project:", project)

  // 2. Set baseline SHA
  await db.project.update({
    where: { id: project.id },
    data: { contextBranchBaselineSha: BASELINE_SHA },
  })
  console.log("Baseline set to", BASELINE_SHA)

  // 3. Run Phase 2 (assessDiff) to create the assessment with changedFiles
  const diffResult = await assessDiff(project.id, project.organisationId, "ON_DEMAND")
  console.log("Phase 2 result:", JSON.stringify(diffResult, null, 2))
  if (!diffResult.ok) { console.error("assessDiff failed:", diffResult.error); process.exit(1) }

  // 4. Run Phase 3 (analyseAiDrift)
  const aiResult = await analyseAiDrift(diffResult.assessmentId, project.organisationId)
  console.log("\n=== Phase 3 AI result ===")
  console.log(JSON.stringify(aiResult, null, 2))

  // 5. Fetch the stored record to show what was persisted
  const stored = await db.contextDriftAssessment.findUnique({
    where: { id: diffResult.assessmentId },
    select: { findings: true, findingsCount: true, aiTokensUsed: true, costUSD: true, status: true },
  })
  console.log("\n=== Stored assessment ===")
  console.log(JSON.stringify(stored, null, 2))

  await db.$disconnect()
}

run().catch(console.error)
```

Run it:

```bash
npx tsx scripts/prove-drift-phase3.ts
```

- [ ] **Step 6.3: Verify the two true-positives appear**

Expected in the findings array:
1. **COVERAGE**: something referencing `lib/redact.mjs` being absent from the docs
2. **ACCURACY**: something referencing docs claiming SQLite and code being CSV-only

If neither appears: review the raw AI text (add `console.log(rawText)` to `analyseAiDrift` temporarily) and tune the prompt.

- [ ] **Step 6.4: Confirm no noise flood**

The findings list must not exceed 10 items. Other changed files that have no genuine doc contradiction should produce zero findings or at most 1–2 defensible others.

- [ ] **Step 6.5: Clean up and commit**

```
git add scripts/prove-drift-phase3.ts
git commit -m "chore(drift): add Phase 3 prove script for SAVESENTRA AC4 (Phase 3 Ref-3xx)"
```

---

## Self-review

**Spec coverage:**
- ✅ Ref-3xx AC4: AI call + structured findings (Tasks 1–5)
- ✅ `getAutoCallModel()` — exact pattern from narrate.ts, `maxOutputTokens: 4096`, `maxRetries: 0`
- ✅ Strict anti-FP prompt — cosmetic/version/path-rename exclusions explicitly stated
- ✅ Finding structure matches spec E.1 exactly
- ✅ Max 10 findings enforced in `parseDriftFindings`
- ✅ Holistic doc read — all .md files from resolved context source
- ✅ Redaction — `redactLargeText` chunks through `redact()` to catch secrets in large files
- ✅ Doc size cap 100KB — enforced in `readDocFile`
- ✅ Parse safely — malformed/partial → `[]`, never a crash
- ✅ Hallucination guard — findings with unrecognized doc field are dropped
- ✅ Store findings, findingsCount, aiTokensUsed, costUSD — org-scoped `update`
- ✅ NOT in this phase: score/risk, triggers, async cycle, UI — none of these are implemented
- ✅ Proving target: Task 6 script runs against SAVESENTRA with known true-positives

**Type consistency check:**
- `DriftFinding.type` → `"ACCURACY" | "COVERAGE"` — used identically in parser, prompt, and test assertions
- `AnalyseAiResult` union — `ok: true` branch has `findings: DriftFinding[]`, matches test assertions
- `parseDriftFindings(rawText, shownDocPaths, shownCodePaths)` — sets match between Task 1 definition and Task 5 usage
- `buildDriftPrompt(docs, code, diffTruncated)` — param names match between Task 4 and Task 5

**No placeholders:** all code shown, exact test assertions, exact git commands.
