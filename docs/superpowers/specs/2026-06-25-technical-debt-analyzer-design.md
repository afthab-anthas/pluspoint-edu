# Technical Debt Analyzer — Design Spec

**Date:** 2026-06-25  
**Status:** Approved  
**Branch:** `afthab/axis-pulse`

---

## Overview

A new feature under the "Debt Analyzer" nav item (Intelligence section, alongside Context Analyser). For a project with a connected GitHub repo, a manager runs a Technical Debt Scan that:

1. Downloads the repo tarball via the GitHub App
2. Runs **Graphify** (structural graph) and **SonarQube** (code-quality issues) in parallel on the extracted source
3. Deletes the extracted source immediately after — code never permanently lives on the server
4. Synthesises both outputs with the existing AI layer into a structured **P1–P4 technical debt report**

**Conceptual separation from Context Drift:**
- Context Drift asks: *"Does the code contradict the context documentation?"* (doc-vs-code)
- Technical Debt asks: *"Does the code itself have structural, quality, or security problems?"* (code-vs-standards)

Same repo, orthogonal questions. Both live in the Intelligence nav section.

---

## Privacy Contract

Code is on disk only during the scan:  
`tarball download → extract to tmpdir → engines run (read-only) → delete tmpdir`

Only graph artifacts (graph.json + GRAPH_REPORT.md) are persisted, stored as JSONB/text in the `TechnicalDebtScan` record. Findings store file paths and line numbers, no code excerpts. This matches the established pattern in `src/lib/security-scan.ts` and `src/lib/code-health.ts`.

---

## Evidence Bar

Findings include:
- **Structural (Graphify source):** file paths + graph metrics (centrality score, community ID, edge count) — no line numbers
- **Code quality (SonarQube source):** file paths + line numbers + rule message — no code excerpts
- **Security (existing SecurityFinding rows):** file paths + line numbers + tool + severity — no code excerpts

No finding ever contains a code excerpt. This maintains the privacy promise while providing file+line precision from SonarQube and SecurityScan sources.

---

## Architecture

### New lib module: `src/lib/debt-scan/`

Four files, mirroring `src/lib/drift/`:

#### `orchestrate.ts`
- `triggerDebtScan(projectId, organisationId, userId)` — creates `TechnicalDebtScan` PENDING, fires `runDebtScanPipeline()` as `void (async () => {...})()`, returns scan record
- `runDebtScanPipeline(scanId, projectId, ctx)` — state machine: PENDING → RUNNING → COMPLETE/ERROR, updates `currentStep` at each phase
- Stale-RUNNING recovery: any scan stuck in RUNNING > 15 minutes marked ERROR (same pattern as `src/lib/drift/orchestrate.ts`)
- Per-project duplicate guard: check for existing PENDING/RUNNING scan before creating a new one

#### `engines.ts`
- `downloadAndExtract(project)` — downloads GitHub tarball via `authedGithubFetch()` (same as `src/lib/code-health.ts` CENTRAL path), extracts to `tmpdir`
- `runParallelEngines(tmpdir, project)` — executes both engines concurrently via `Promise.all`
- `runGraphify(tmpdir, graphOutDir)` — invokes the Graphify CLI using `child_process.execFile` (not exec/execSync — uses execFile so path arguments are passed as an array, not a shell string, preventing injection), reads `graph.json` + `GRAPH_REPORT.md`, returns parsed metrics
- `runSonarScanner(tmpdir, sonarProjectKey)` — calls existing `src/lib/code-health.ts` scanner + new `fetchSonarIssues()` call to `/api/issues/search`
- `deleteTmpdir(tmpdir, graphOutDir)` — called in `finally` block after engines regardless of outcome
- Partial-result resilience: if one engine throws, the other's results are preserved for synthesis

#### `synthesise.ts`
- `synthesiseDebtReport(graphResult, sonarResult, securityFindings, repoSnapshot)` — builds P1–P4 AI prompt
- Stack-aware prompt: reads `project.repoSnapshot.frameworks` (already stored, detected by `src/lib/repo-context/snapshot.ts`) to adapt the prompt per detected stack
- Calls `getAutoCallModel()` (existing Groq/OpenRouter helper)
- Checks `isCeilingExceeded()` from `src/lib/cost-ceiling.ts` before proceeding
- Returns parsed `TechDebtFinding[]`

#### `compute-score.ts`
- `computeDebtScore(findings)` — maps P1/P2/P3/P4 counts to a 0–100 `debtScore`
  - Start at 100, subtract: P1 (Critical) −20, P2 (High) −10, P3 (Medium) −4, P4 (Low) −1
  - Floor at 0 (a project with 5 P1 findings scores 0, not negative)

---

## SonarQube Changes

`src/lib/code-health.ts` gains two new functions:

1. **`ensureSonarProject(projectKey, projectName)`** — calls `POST /api/projects/create` using `SONAR_ADMIN_TOKEN`, handles "already exists" gracefully. Called at scan start so each GitHub repo has its own SonarQube project key for scoped issue retrieval. Project key derived from project ID (e.g. `axis-pulse-project-<projectId>`).

2. **`fetchSonarIssues(projectKey)`** — calls `/api/issues/search?componentKeys=<key>&types=CODE_SMELL,BUG,VULNERABILITY&severities=BLOCKER,CRITICAL,MAJOR&ps=500`, returns `{ file, line, message, severity, ruleId }[]`.

---

## Python / Graphify Deployment

**Decision:** Same container — Python added to the existing Coolify app.

The Dockerfile (or Nixpacks config) gains:
```
python3, python3-pip
pip install graphifyy
```

Tree-sitter native extensions compile on `pip install`.

**Pre-build verification required (blocking prerequisite):** Before writing any Dockerfile changes, verify:
1. Whether `graphifyy` supports a `--no-llm` flag for structural-only output (no LLM key needed)
2. If LLM is required, what provider format it expects (OpenAI API? Anthropic? Groq-compatible?)
3. Whether Pulse's `GROQ_API_KEY` / `OPENROUTER_API_KEY` are compatible, or whether a new `GRAPHIFY_LLM_KEY` env var is needed

This must be resolved against the actual `graphifyy` CLI before Phase C begins.

**Security note:** Graphify invocation uses `child_process.execFile` (not exec/execSync) to prevent command injection. All path arguments are passed as a separate array, not interpolated into a shell command string.

---

## Data Model

### New Prisma model: `TechnicalDebtScan`

```prisma
model TechnicalDebtScan {
  id             String              @id @default(cuid())
  projectId      String
  organisationId String
  status         TechDebtScanStatus
  currentStep    TechDebtStep?

  // Engine A — Graphify structural graph
  graphJson      Json?
  graphReportMd  String?
  godNodeCount   Int?
  communityCount Int?
  graphEdgeCount Int?

  // Engine B — SonarQube
  sonarQualityGate           String?
  sonarMaintainabilityRating String?
  sonarReliabilityRating     String?
  sonarSecurityRating        String?
  sonarTechDebtMinutes       Int?
  sonarIssueCount            Int?

  // AI synthesis
  findings      Json?
  findingsCount Int?
  criticalCount Int?
  highCount     Int?
  debtScore     Int?

  // Tracking
  aiTokensUsed Int?
  costUSD      Float?
  error        String?
  scannedAt    DateTime  @default(now())
  createdAt    DateTime  @default(now())
  updatedAt    DateTime  @updatedAt

  project      Project      @relation(fields: [projectId], references: [id], onDelete: Cascade)
  organisation Organisation @relation(fields: [organisationId], references: [id], onDelete: Cascade)

  @@index([projectId, createdAt(sort: Desc)])
  @@index([organisationId])
}

enum TechDebtScanStatus {
  PENDING
  RUNNING
  COMPLETE
  ERROR
}

enum TechDebtStep {
  DOWNLOADING
  EXTRACTING
  ENGINES
  SYNTHESISING
  DONE
}
```

### `TechDebtFinding` shape (JSON array in `findings` field)

```typescript
interface TechDebtFinding {
  priority:    "P1" | "P2" | "P3" | "P4"
  category:    "STRUCTURAL" | "CODE_QUALITY" | "SECURITY" | "DEPENDENCY" | "TEST_COVERAGE"
  title:       string
  description: string
  evidence: {
    source:  "GRAPH" | "SONAR" | "SECURITY_SCAN"
    files:   string[]      // always present — file paths
    lines:   number[]      // present for SONAR and SECURITY_SCAN sources
    metric:  string | null // e.g. "centrality: 0.87", "cognitive complexity: 23"
  }
  recommendation: string
}
```

---

## API Routes

All under `src/app/api/projects/[id]/debt-scan/`:

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/api/projects/[id]/debt-scan` | POST | MANAGER or LINE_MANAGER (in-team) | Trigger scan — rate-limited 1/30min/project |
| `/api/projects/[id]/debt-scan` | GET | MANAGER or LINE_MANAGER | List latest 10 scans |
| `/api/projects/[id]/debt-scan/[scanId]` | GET | MANAGER or LINE_MANAGER | Poll single scan (status + currentStep + findings) |

Rate limiter: `src/lib/ratelimit-debt-scan.ts` — 1 per 30 minutes per project (sliding window, Upstash, fail-open).

These are session-authenticated routes — CSRF gate applies automatically, no middleware changes needed.

---

## UI

**Nav:** New "Debt Analyzer" entry in the Intelligence nav section (`src/components/AppShellClient.tsx`), alongside Context Analyser. MANAGER + LINE_MANAGER only (MEMBER redirected).

**Routes:**
- `/debt-analyzer` — list view: all org projects with latest debt scan status + debtScore badge
- `/debt-analyzer/[id]` — detail view: scan history, findings breakdown, "Run Debt Scan" button

**Detail view states:**

| State | UI |
|---|---|
| No scan yet | "No debt scan run yet" + Run button |
| PENDING/RUNNING | Step-status progress showing `currentStep` label + elapsed time. Polls every 5 seconds. Timeout at 15 minutes. |
| COMPLETE | debtScore badge, P1/P2/P3/P4 pill counts, expandable findings list grouped by priority |
| ERROR | Error message + Retry button |

**Findings card:** Priority pill (P1=red, P2=amber, P3=yellow, P4=grey), category badge, title, description, evidence section (file paths as text refs, line numbers where available, metric string), recommendation.

---

## Error Handling

| Failure | Behaviour |
|---|---|
| Graphify fails | Log error, continue with SonarQube-only synthesis, note Graphify unavailable in findings |
| SonarQube fails | Log error, continue with Graphify-only synthesis |
| Both engines fail | Mark ERROR immediately, no AI synthesis call |
| Tarball download fails | Mark ERROR with specific message |
| AI synthesis fails (cost ceiling) | Mark ERROR, preserve raw engine data in scan record (graphJson, sonarIssueCount) |
| Tmpdir cleanup fails | Log warning only — never surfaces to user |
| Stale RUNNING > 15 min | Recovery job marks ERROR (same pattern as `src/lib/drift/orchestrate.ts`) |

---

## Security Considerations

- Graphify invoked via `child_process.execFile` (array args, not shell string) — prevents command injection on path arguments
- GitHub App token used only within `authedGithubFetch()` — hostname allowlist enforced
- Session-authenticated routes — CSRF gate applies automatically
- No raw tokens logged — existing Pino redact list covers auth headers
- Redaction not required for AI-generated findings (not user-generated text)
- `isCeilingExceeded()` checked before every AI call (Rule 8 convention)

---

## Testing

`src/tests/debt-scan.test.ts`:
- `computeDebtScore()` — P1–P4 count mapping
- `parseGraphMetrics()` — graph.json → godNodeCount, communityCount, edgeCount
- `buildDebtPrompt()` — stub graph report + sonar issues → prompt contains framework hint, P1–P4 taxonomy, no raw code

`src/tests/debt-scan-api.test.ts`:
- POST: MANAGER 202, LINE_MANAGER in-team 202, LINE_MANAGER out-of-team 403, MEMBER 403, unauthenticated 401
- POST: 2nd scan within 30 min → 429
- GET poll: PENDING returns status, COMPLETE returns findings, ERROR returns error message
- Cross-tenant: org B project inaccessible to org A session (organisationId guard)
- Graphify subprocess mocked via `vi.mock('child_process')`
- SonarQube API calls mocked

---

## Phase Sizing

| Phase | Scope | Size |
|---|---|---|
| **A** | Data model + migration, orchestrate.ts state machine, API routes, rate limiter, basic UI (engines stubbed) | S |
| **B** | SonarQube issues API (`fetchSonarIssues`) + per-project provisioning (`ensureSonarProject`) | S |
| **C** | Graphify integration — verify LLM key first, update Dockerfile, engines.ts `runGraphify()`, graph artifact storage | M |
| **D** | AI synthesis — stack-aware prompt, `synthesise.ts`, P1–P4 parsing, debtScore | M |
| **E** | UI — step-status progress, findings card component, list view + detail view, nav entry | M |
| **F** | Tests — route tests, unit tests, RBAC matrix | S |

**Blocking prerequisite before Phase C:** Verify `graphifyy` LLM key requirements. Do not write Dockerfile changes until this is settled.

---

## Open Decisions (all resolved)

| Question | Decision |
|---|---|
| Evidence bar | File path + line number only. No code excerpts. Privacy promise holds. |
| Graphify timing | Full vision from the start — both engines in initial build |
| Python deployment | Same container — add Python + graphifyy to Coolify app |
| Job duration UX | Step-status field (`currentStep`), client polls every 5s, 15-minute timeout |
| Nav placement | New "Debt Analyzer" nav entry alongside Context Analyser |
| Engine architecture | Parallel execution (Graphify + SonarQube concurrently after extraction) |

---

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Graphify LLM key incompatible with Groq/OpenRouter | High | Verify before Dockerfile write; fallback to `--no-llm` if supported |
| Job duration exceeds 15 min for very large monorepos | Medium | Hard-cut tarball at a size limit; surface as scan-too-large error |
| graphJson JSONB bloats Postgres for large repos | Low-Medium | Cap graph size at 10MB; object storage reference in a future phase |
| Python in container increases build time/image size | Low | Acceptable trade-off for same-container simplicity |
| SonarQube CE unavailable during scan window | Low | Existing `waitForSonarCe()` timeout handles; scan marks ERROR |
| Per-project Sonar provisioning fails (invalid admin token) | Medium | Explicit error message; `SONAR_ADMIN_TOKEN` must be set before CENTRAL path |
