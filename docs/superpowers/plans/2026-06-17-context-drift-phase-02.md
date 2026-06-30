# Context Drift Phase 2 — Model + Diff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `ContextDriftAssessment` Prisma model and a `compareCommits` GitHub diff function, then wire them together in an `assessDiff` service that writes changedFiles/baselineSha/headSha to an assessment record, with first-class handling for BASELINE_INVALID (force-pushed SHA) and truncated diffs.

**Architecture:** Three layers: (1) Prisma schema — new enums + model, purely additive; (2) GitHub diff helper — `compareCommits` added to the existing `drift-github.ts` alongside Phase 1 functions; (3) service `assess-diff.ts` — looks up project (org-scoped), resolves context source, calls compareCommits, creates the assessment record. Phase 4 will wire the triggers and the full PENDING→RUNNING→COMPLETE cycle onto the model built here; this phase creates records directly in their final state.

**Tech Stack:** Prisma 7 + PostgreSQL · TypeScript strict + noUncheckedIndexedAccess · Vitest · `authedGithubFetch` from `src/lib/repo-context/client.ts`

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `prisma/schema.prisma` | Add `DriftAssessmentStatus`, `DriftTriggerSource`, `DriftRiskLevel` enums + `ContextDriftAssessment` model + back-relations on Organisation and Project |
| Modify | `src/lib/repo-context/drift-github.ts` | Add `CompareOutcome` type union + `compareCommits` function |
| Modify | `src/tests/drift-github.test.ts` | Extend with `compareCommits` tests (ok, baseline_invalid, truncated, error) |
| Create | `src/lib/drift/assess-diff.ts` | `assessDiff` service: org-scoped project lookup → context source resolution → compareCommits → create assessment record |
| Create | `src/tests/drift-assess-diff.test.ts` | Unit tests for all assessDiff paths (success, baseline_invalid, missing baseline, github not configured) |

---

## Task 1: Prisma Schema — ContextDriftAssessment model + migration

**Files:**
- Modify: `prisma/schema.prisma`

- [ ] **Step 1.1: Confirm the model does not exist yet**

Run:
```powershell
Select-String -Pattern "ContextDriftAssessment" prisma/schema.prisma
```
Expected: no output (model does not exist).

- [ ] **Step 1.2: Add three new enums after the `GithubInstallationStatus` enum (line 560)**

In `prisma/schema.prisma`, after:
```
enum GithubInstallationStatus {
  ACTIVE
  INVALID
}
```

Insert:
```prisma
enum DriftAssessmentStatus {
  PENDING
  RUNNING
  COMPLETE
  ERROR
}

enum DriftTriggerSource {
  ON_DEMAND
  VOLUME
  MAJOR_EVENT
  SYSTEM
}

enum DriftRiskLevel {
  GREEN
  AMBER
  RED
}
```

- [ ] **Step 1.3: Add `driftAssessments` back-relation to the Organisation model**

In `prisma/schema.prisma`, in the `Organisation` model after:
```
  codeHealthSnapshots  CodeHealthSnapshot[]
```
Add:
```prisma
  driftAssessments     ContextDriftAssessment[]
```

- [ ] **Step 1.4: Add `driftAssessments` back-relation to the Project model**

In `prisma/schema.prisma`, in the `Project` model after:
```
  securityScanRuns    SecurityScanRun[]
```
Add:
```prisma
  driftAssessments    ContextDriftAssessment[]
```

- [ ] **Step 1.5: Add the full `ContextDriftAssessment` model at the end of the file**

Append to `prisma/schema.prisma`:
```prisma
model ContextDriftAssessment {
  id             String                @id @default(cuid())
  projectId      String
  organisationId String
  tenantKey      String?
  status         DriftAssessmentStatus @default(PENDING)
  triggeredBy    DriftTriggerSource
  contextSource  String
  baselineSha    String?
  headSha        String?
  changedFiles   String[]
  diffTruncated  Boolean               @default(false)
  healthScore    Int?
  riskLevel      DriftRiskLevel?
  findings       Json?
  findingsCount  Int?
  aiTokensUsed   Int?
  costUSD        Float?
  error          String?
  assessedAt     DateTime              @default(now())
  createdAt      DateTime              @default(now())
  updatedAt      DateTime              @updatedAt

  organisation Organisation @relation(fields: [organisationId], references: [id])
  project      Project      @relation(fields: [projectId], references: [id], onDelete: Cascade)

  @@index([projectId, assessedAt])
  @@index([organisationId])
}
```

- [ ] **Step 1.6: Validate schema syntax**

Run:
```powershell
npx prisma validate
```
Expected: `The schema at prisma/schema.prisma is valid`

- [ ] **Step 1.7: Generate and apply the migration**

Run:
```powershell
npx prisma migrate dev --name add_context_drift_assessment
```
Expected output includes:
```
✔ Generated Prisma Client (Prisma 7.x) to ./src/generated/prisma
The following migration(s) have been applied:

migrations/
  └─ YYYYMMDDHHMMSS_add_context_drift_assessment/
    └─ migration.sql
```

- [ ] **Step 1.8: Inspect the generated SQL — confirm no destructive ops**

Run:
```powershell
Get-Content (Get-ChildItem prisma/migrations -Filter "*add_context_drift_assessment*" -Recurse).FullName
```
Expected: Only `CREATE TYPE` statements for the three enums and a `CREATE TABLE "ContextDriftAssessment"` statement. No `DROP`, no `ALTER COLUMN ... NOT NULL` without default. Example of expected SQL shape:
```sql
CREATE TYPE "DriftAssessmentStatus" AS ENUM ('PENDING', 'RUNNING', 'COMPLETE', 'ERROR');
CREATE TYPE "DriftTriggerSource" AS ENUM ('ON_DEMAND', 'VOLUME', 'MAJOR_EVENT', 'SYSTEM');
CREATE TYPE "DriftRiskLevel" AS ENUM ('GREEN', 'AMBER', 'RED');

CREATE TABLE "ContextDriftAssessment" (
    "id" TEXT NOT NULL,
    "projectId" TEXT NOT NULL,
    "organisationId" TEXT NOT NULL,
    "tenantKey" TEXT,
    "status" "DriftAssessmentStatus" NOT NULL DEFAULT 'PENDING',
    "triggeredBy" "DriftTriggerSource" NOT NULL,
    "contextSource" TEXT NOT NULL,
    "baselineSha" TEXT,
    "headSha" TEXT,
    "changedFiles" TEXT[] NOT NULL DEFAULT ARRAY[]::TEXT[],
    "diffTruncated" BOOLEAN NOT NULL DEFAULT false,
    "healthScore" INTEGER,
    "riskLevel" "DriftRiskLevel",
    "findings" JSONB,
    "findingsCount" INTEGER,
    "aiTokensUsed" INTEGER,
    "costUSD" DOUBLE PRECISION,
    "error" TEXT,
    "assessedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,
    CONSTRAINT "ContextDriftAssessment_pkey" PRIMARY KEY ("id")
);

-- (FK + index statements follow)
```

- [ ] **Step 1.9: TypeScript compile — zero errors**

Run:
```powershell
npx tsc --noEmit
```
Expected: exits 0, no output. (All errors should be in `src/tests/` only, per the known TSC baseline — verify `src/` itself is clean.)

If tsc reports errors outside `src/tests/`, fix them before continuing.

- [ ] **Step 1.10: Full test suite — green**

Run:
```powershell
npm test
```
Expected: all existing tests pass. The migration is additive — no existing query is broken. Show the test run output.

- [ ] **Step 1.11: Commit**

```powershell
git add prisma/schema.prisma prisma/migrations
git commit -m "feat(drift): add ContextDriftAssessment model and enums (Phase 2 Ref-2xx)"
```

**STOP HERE and report the `npm test` output before proceeding to Task 2.**

---

## Task 2: compareCommits in drift-github.ts

**Files:**
- Modify: `src/lib/repo-context/drift-github.ts`
- Modify: `src/tests/drift-github.test.ts`

- [ ] **Step 2.1: Write the failing tests first**

Add to the END of `src/tests/drift-github.test.ts`:

```typescript
// import compareCommits at the top of the existing imports block:
// import { branchExists, getRecursiveTree, getLatestCommitSha, resolveContextSource, compareCommits } from "@/lib/repo-context/drift-github"

describe("compareCommits", () => {
  it("returns ok with changedFiles on a successful 200 compare", async () => {
    mockFetch.mockResolvedValueOnce(
      fakeResp(200, {
        files: [
          { filename: "src/lib/auth.ts", status: "modified" },
          { filename: "docs/architecture.md", status: "modified" },
        ],
        commits: [{ sha: "abc123" }],
      })
    )
    const result = await compareCommits("inst-1", "org/repo", "base-sha", "head-sha")
    expect(result.status).toBe("ok")
    if (result.status !== "ok") return
    expect(result.changedFiles).toEqual(["src/lib/auth.ts", "docs/architecture.md"])
    expect(result.diffTruncated).toBe(false)
  })

  it("returns baseline_invalid on 404 — the BASELINE_INVALID path (force-push/rebase case)", async () => {
    mockFetch.mockResolvedValueOnce(fakeResp(404, { message: "Not Found" }))
    const result = await compareCommits("inst-1", "org/repo", "stale-sha-deadbeef", "head-sha")
    expect(result.status).toBe("baseline_invalid")
  })

  it("sets diffTruncated when GitHub returns exactly 300 files", async () => {
    const files = Array.from({ length: 300 }, (_, i) => ({ filename: `src/file-${i}.ts` }))
    mockFetch.mockResolvedValueOnce(fakeResp(200, { files, commits: [] }))
    const result = await compareCommits("inst-1", "org/repo", "base-sha", "head-sha")
    expect(result.status).toBe("ok")
    if (result.status !== "ok") return
    expect(result.diffTruncated).toBe(true)
    expect(result.changedFiles).toHaveLength(300)
  })

  it("returns ok with empty changedFiles when GitHub omits the files array", async () => {
    mockFetch.mockResolvedValueOnce(fakeResp(200, { commits: [] }))
    const result = await compareCommits("inst-1", "org/repo", "base-sha", "head-sha")
    expect(result.status).toBe("ok")
    if (result.status !== "ok") return
    expect(result.changedFiles).toEqual([])
    expect(result.diffTruncated).toBe(false)
  })

  it("returns error on non-200 non-404 response", async () => {
    mockFetch.mockResolvedValueOnce(fakeResp(500, { message: "Server Error" }))
    const result = await compareCommits("inst-1", "org/repo", "base-sha", "head-sha")
    expect(result.status).toBe("error")
    if (result.status !== "error") return
    expect(result.message).toContain("500")
  })

  it("constructs the correct compare URL with base...head notation", async () => {
    mockFetch.mockResolvedValueOnce(fakeResp(200, { files: [], commits: [] }))
    await compareCommits("inst-1", "org/repo", "base-abc", "head-xyz")
    expect(mockFetch).toHaveBeenCalledWith(
      "https://api.github.com/repos/org/repo/compare/base-abc...head-xyz",
      "inst-1"
    )
  })
})
```

Also update the import line at the top of `drift-github.test.ts` to include `compareCommits`:
```typescript
import {
  branchExists,
  getRecursiveTree,
  getLatestCommitSha,
  resolveContextSource,
  compareCommits,
} from "@/lib/repo-context/drift-github"
```

- [ ] **Step 2.2: Run the new tests to verify they fail**

Run:
```powershell
npm test -- --reporter=verbose drift-github
```
Expected: the new `compareCommits` tests fail with `compareCommits is not a function` or similar. Existing tests still pass.

- [ ] **Step 2.3: Implement compareCommits in drift-github.ts**

Add the following types and function to the END of `src/lib/repo-context/drift-github.ts`:

```typescript
export interface CompareResult {
  status: "ok"
  changedFiles: string[]
  diffTruncated: boolean
}

export interface CompareBaselineInvalidResult {
  status: "baseline_invalid"
}

export interface CompareErrorResult {
  status: "error"
  message: string
}

export type CompareOutcome = CompareResult | CompareBaselineInvalidResult | CompareErrorResult

export async function compareCommits(
  installationId: string,
  fullName: string,
  baseSha: string,
  headSha: string
): Promise<CompareOutcome> {
  const resp = await authedGithubFetch(
    `https://api.github.com/repos/${fullName}/compare/${baseSha}...${headSha}`,
    installationId
  )

  if (resp.status === 404) {
    return { status: "baseline_invalid" }
  }

  if (!resp.ok) {
    return { status: "error", message: `GitHub compare failed: ${resp.status}` }
  }

  const data = (await resp.json()) as {
    files?: Array<{ filename: string }>
  }

  const files = data.files ?? []
  const changedFiles = files.map((f) => f.filename)
  // GitHub's compare API caps files at 300; reaching the cap means the diff may be partial.
  const diffTruncated = files.length >= 300

  return { status: "ok", changedFiles, diffTruncated }
}
```

- [ ] **Step 2.4: Run the compareCommits tests to verify they pass**

Run:
```powershell
npm test -- --reporter=verbose drift-github
```
Expected: all tests in the `compareCommits` describe block pass. All previously-passing tests still pass.

- [ ] **Step 2.5: Full suite check**

Run:
```powershell
npm test
```
Expected: all tests green.

- [ ] **Step 2.6: Commit**

```powershell
git add src/lib/repo-context/drift-github.ts src/tests/drift-github.test.ts
git commit -m "feat(drift): add compareCommits with BASELINE_INVALID and truncation paths (Phase 2 Ref-2xx)"
```

---

## Task 3: assessDiff service

**Files:**
- Create: `src/lib/drift/assess-diff.ts`
- Create: `src/tests/drift-assess-diff.test.ts`

- [ ] **Step 3.1: Write the failing tests**

Create `src/tests/drift-assess-diff.test.ts`:

```typescript
// src/tests/drift-assess-diff.test.ts
import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    project: { findUnique: vi.fn() },
    contextDriftAssessment: { create: vi.fn() },
  },
}))

vi.mock("@/lib/repo-context/drift-github", () => ({
  resolveContextSource: vi.fn(),
  compareCommits: vi.fn(),
}))

import { db } from "@/lib/db"
import { resolveContextSource, compareCommits } from "@/lib/repo-context/drift-github"
import { assessDiff } from "@/lib/drift/assess-diff"

const mockDb = vi.mocked(db)
const mockResolve = vi.mocked(resolveContextSource)
const mockCompare = vi.mocked(compareCommits)

const baseProject = {
  id: "proj-1",
  organisationId: "org-1",
  teamId: "team-1",
  githubRepoFullName: "org/savesentra",
  githubInstallationId: "inst-1",
  repoMetadata: { defaultBranch: "main" },
  contextBranchBaselineSha: "2a9d958dabc123",
  tenantKey: null,
}

const fakeAssessment = {
  id: "assess-1",
  projectId: "proj-1",
  organisationId: "org-1",
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe("assessDiff — happy path", () => {
  it("creates an assessment record with COMPLETE status and returns changedFiles", async () => {
    mockDb.project.findUnique.mockResolvedValue(baseProject as never)
    mockResolve.mockResolvedValue({
      source: "context_builds",
      files: [],
      headSha: "head-sha-abc",
    })
    mockCompare.mockResolvedValue({
      status: "ok",
      changedFiles: ["src/auth.ts", "docs/architecture.md"],
      diffTruncated: false,
    })
    mockDb.contextDriftAssessment.create.mockResolvedValue(fakeAssessment as never)

    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")

    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.assessmentId).toBe("assess-1")
    expect(result.changedFiles).toEqual(["src/auth.ts", "docs/architecture.md"])
    expect(result.diffTruncated).toBe(false)
    expect(result.headSha).toBe("head-sha-abc")

    expect(mockDb.contextDriftAssessment.create).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          projectId: "proj-1",
          organisationId: "org-1",
          status: "COMPLETE",
          triggeredBy: "ON_DEMAND",
          contextSource: "context_builds",
          baselineSha: "2a9d958dabc123",
          headSha: "head-sha-abc",
          changedFiles: ["src/auth.ts", "docs/architecture.md"],
          diffTruncated: false,
          error: null,
        }),
      })
    )
  })

  it("sets diffTruncated: true on the record when compare returns a truncated diff", async () => {
    mockDb.project.findUnique.mockResolvedValue(baseProject as never)
    mockResolve.mockResolvedValue({
      source: "context_builds",
      files: [],
      headSha: "head-sha-xyz",
    })
    mockCompare.mockResolvedValue({
      status: "ok",
      changedFiles: Array.from({ length: 300 }, (_, i) => `src/file-${i}.ts`),
      diffTruncated: true,
    })
    mockDb.contextDriftAssessment.create.mockResolvedValue(fakeAssessment as never)

    const result = await assessDiff("proj-1", "org-1", "MAJOR_EVENT")
    expect(result.ok).toBe(true)
    if (!result.ok) return
    expect(result.diffTruncated).toBe(true)

    expect(mockDb.contextDriftAssessment.create).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({ diffTruncated: true }),
      })
    )
  })
})

describe("assessDiff — BASELINE_INVALID path", () => {
  it("creates an ERROR record with error=baseline_invalid and does NOT auto-reset the baseline", async () => {
    mockDb.project.findUnique.mockResolvedValue(baseProject as never)
    mockResolve.mockResolvedValue({
      source: "context_builds",
      files: [],
      headSha: "head-sha-abc",
    })
    // GitHub 404 on compare — baseSha is stale (force-pushed)
    mockCompare.mockResolvedValue({ status: "baseline_invalid" })
    mockDb.contextDriftAssessment.create.mockResolvedValue({
      ...fakeAssessment,
      error: "baseline_invalid",
    } as never)

    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")

    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("baseline_invalid")
    expect(result.assessmentId).toBe("assess-1")

    // CRITICAL: baseline must NOT be reset (db.project.update must NOT be called)
    expect(mockDb.project).not.toHaveProperty("update")

    expect(mockDb.contextDriftAssessment.create).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          status: "ERROR",
          error: "baseline_invalid",
          baselineSha: "2a9d958dabc123",
          headSha: "head-sha-abc",
          changedFiles: [],
        }),
      })
    )
  })
})

describe("assessDiff — guard clauses", () => {
  it("returns project_not_found when project does not exist", async () => {
    mockDb.project.findUnique.mockResolvedValue(null as never)
    const result = await assessDiff("proj-missing", "org-1", "ON_DEMAND")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("project_not_found")
  })

  it("returns wrong_org when project belongs to a different org", async () => {
    mockDb.project.findUnique.mockResolvedValue({
      ...baseProject,
      organisationId: "org-other",
    } as never)
    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("wrong_org")
  })

  it("returns github_not_configured when githubRepoFullName is null", async () => {
    mockDb.project.findUnique.mockResolvedValue({
      ...baseProject,
      githubRepoFullName: null,
    } as never)
    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("github_not_configured")
  })

  it("returns baseline_not_set when contextBranchBaselineSha is null", async () => {
    mockDb.project.findUnique.mockResolvedValue({
      ...baseProject,
      contextBranchBaselineSha: null,
    } as never)
    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("baseline_not_set")
  })

  it("returns no_context_source when resolveContextSource returns none", async () => {
    mockDb.project.findUnique.mockResolvedValue(baseProject as never)
    mockResolve.mockResolvedValue({ source: "none", files: [], headSha: null })
    const result = await assessDiff("proj-1", "org-1", "ON_DEMAND")
    expect(result.ok).toBe(false)
    if (result.ok) return
    expect(result.error).toBe("no_context_source")
  })

  it("inherits organisationId from the project record — never from the caller", async () => {
    mockDb.project.findUnique.mockResolvedValue(baseProject as never)
    mockResolve.mockResolvedValue({
      source: "context_builds",
      files: [],
      headSha: "head-sha",
    })
    mockCompare.mockResolvedValue({ status: "ok", changedFiles: [], diffTruncated: false })
    mockDb.contextDriftAssessment.create.mockResolvedValue(fakeAssessment as never)

    await assessDiff("proj-1", "org-1", "SYSTEM")

    const call = mockDb.contextDriftAssessment.create.mock.calls[0]
    // organisationId must come from project.organisationId, not the function argument
    expect(call?.[0].data.organisationId).toBe(baseProject.organisationId)
  })
})
```

- [ ] **Step 3.2: Run the tests to confirm they fail**

Run:
```powershell
npm test -- --reporter=verbose drift-assess-diff
```
Expected: all tests fail with `Cannot find module '@/lib/drift/assess-diff'`.

- [ ] **Step 3.3: Create the assessDiff service**

Create `src/lib/drift/assess-diff.ts`:

```typescript
import { db } from "@/lib/db"
import {
  compareCommits,
  resolveContextSource,
  type ContextSource,
} from "@/lib/repo-context/drift-github"

type TriggerSource = "ON_DEMAND" | "VOLUME" | "MAJOR_EVENT" | "SYSTEM"

type AssessDiffSuccess = {
  ok: true
  assessmentId: string
  changedFiles: string[]
  diffTruncated: boolean
  headSha: string
}

type AssessDiffFailure = {
  ok: false
  error:
    | "project_not_found"
    | "wrong_org"
    | "github_not_configured"
    | "baseline_not_set"
    | "no_context_source"
    | "baseline_invalid"
    | "compare_failed"
  assessmentId?: string
}

export type AssessDiffResult = AssessDiffSuccess | AssessDiffFailure

export async function assessDiff(
  projectId: string,
  organisationId: string,
  triggeredBy: TriggerSource
): Promise<AssessDiffResult> {
  const project = await db.project.findUnique({
    where: { id: projectId },
    select: {
      id: true,
      organisationId: true,
      githubRepoFullName: true,
      githubInstallationId: true,
      repoMetadata: true,
      contextBranchBaselineSha: true,
      tenantKey: true,
    },
  })

  if (!project) return { ok: false, error: "project_not_found" }
  if (project.organisationId !== organisationId) return { ok: false, error: "wrong_org" }
  if (!project.githubRepoFullName || !project.githubInstallationId) {
    return { ok: false, error: "github_not_configured" }
  }

  const baselineSha = project.contextBranchBaselineSha
  if (!baselineSha) return { ok: false, error: "baseline_not_set" }

  const meta = project.repoMetadata as { defaultBranch?: string } | null
  const defaultBranch = meta?.defaultBranch ?? "main"

  const resolved = await resolveContextSource(
    project.githubInstallationId,
    project.githubRepoFullName,
    defaultBranch
  )

  if (resolved.source === "none" || !resolved.headSha) {
    return { ok: false, error: "no_context_source" }
  }

  const headSha = resolved.headSha
  const contextSource: ContextSource = resolved.source

  const outcome = await compareCommits(
    project.githubInstallationId,
    project.githubRepoFullName,
    baselineSha,
    headSha
  )

  if (outcome.status === "baseline_invalid") {
    // BASELINE_INVALID: store ERROR record — do NOT auto-reset the baseline.
    // The UI will surface "mark refreshed" to allow manual reset.
    const record = await db.contextDriftAssessment.create({
      data: {
        projectId,
        organisationId: project.organisationId,
        tenantKey: project.tenantKey ?? null,
        status: "ERROR",
        triggeredBy,
        contextSource,
        baselineSha,
        headSha,
        changedFiles: [],
        diffTruncated: false,
        error: "baseline_invalid",
      },
    })
    return { ok: false, error: "baseline_invalid", assessmentId: record.id }
  }

  if (outcome.status === "error") {
    return { ok: false, error: "compare_failed" }
  }

  const record = await db.contextDriftAssessment.create({
    data: {
      projectId,
      organisationId: project.organisationId,
      tenantKey: project.tenantKey ?? null,
      status: "COMPLETE",
      triggeredBy,
      contextSource,
      baselineSha,
      headSha,
      changedFiles: outcome.changedFiles,
      diffTruncated: outcome.diffTruncated,
      error: null,
    },
  })

  return {
    ok: true,
    assessmentId: record.id,
    changedFiles: outcome.changedFiles,
    diffTruncated: outcome.diffTruncated,
    headSha,
  }
}
```

- [ ] **Step 3.4: Run the assessDiff tests to verify they pass**

Run:
```powershell
npm test -- --reporter=verbose drift-assess-diff
```
Expected: all tests green.

- [ ] **Step 3.5: Full suite check**

Run:
```powershell
npm test
```
Expected: all tests green.

- [ ] **Step 3.6: TypeScript compile check**

Run:
```powershell
npx tsc --noEmit
```
Expected: exits 0. If errors appear in `src/lib/drift/assess-diff.ts`, fix them before proceeding.

- [ ] **Step 3.7: Commit**

```powershell
git add src/lib/drift/assess-diff.ts src/tests/drift-assess-diff.test.ts
git commit -m "feat(drift): add assessDiff service with BASELINE_INVALID and org-scoped writes (Phase 2 Ref-2xx)"
```

---

## Proving Target Notes (Manual Verification — after all tasks complete)

These are integration verification steps to run after the unit tests pass. They require real GitHub API access (the existing installation token from `GITHUB_APP_ID` + `GITHUB_APP_PRIVATE_KEY`).

**Real compare diff against SAVESENTRA:**
- Project has `contextBranchBaselineSha = "2a9d958d..."` (set by Phase 1 captureBaseline)
- Call `assessDiff(savesentraProjectId, orgId, "ON_DEMAND")` in a Next.js API route (to be built in Phase 5)
- Verify the returned `changedFiles` matches the GitHub compare diff visible at `github.com/org/savesentra/compare/2a9d958d...HEAD`
- Verify a `ContextDriftAssessment` row is created in DB with `status = "COMPLETE"` and the correct `changedFiles` array

**BASELINE_INVALID proof:**
- Temporarily set `Project.contextBranchBaselineSha = "0000000000000000000000000000000000000000"` (nonexistent SHA)
- Call `assessDiff(savesentraProjectId, orgId, "ON_DEMAND")`
- Verify: returns `{ ok: false, error: "baseline_invalid", assessmentId: "..." }`
- Verify: DB has a `ContextDriftAssessment` row with `status = "ERROR"`, `error = "baseline_invalid"`
- Verify: `Project.contextBranchBaselineSha` is still `"000..."` (not auto-reset)
- Verify: no generic exception thrown — only the explicit `baseline_invalid` path

---

## Self-Review

**Spec coverage:**
- [x] `ContextDriftAssessment` model with all spec-listed fields + `diffTruncated` (Task 1)
- [x] `DriftAssessmentStatus` enum (`PENDING|RUNNING|COMPLETE|ERROR`) (Task 1)
- [x] `DriftTriggerSource` enum (`ON_DEMAND|VOLUME|MAJOR_EVENT|SYSTEM`) (Task 1)
- [x] `DriftRiskLevel` enum (`GREEN|AMBER|RED`) (Task 1)
- [x] `compareCommits` function with GitHub Compare API (Task 2)
- [x] BASELINE_INVALID first-class path — 404 → `baseline_invalid` status, no auto-reset (Tasks 2 + 3)
- [x] Diff truncation flag — 300 file cap → `diffTruncated: true` (Tasks 2 + 3)
- [x] Assessment record written with `changedFiles/baselineSha/headSha` (Task 3)
- [x] Org-scoped: `organisationId` inherited from project, not from caller (Task 3)
- [x] Baseline NOT auto-reset on BASELINE_INVALID — test asserts `db.project.update` not called (Task 3)

**Not built in Phase 2 (deferred per spec):**
- AI call, health score, risk level computation (Phase 3/4)
- PENDING→RUNNING→COMPLETE status cycle (Phase 4)
- Volume and major-event triggers (Phase 4)
- API routes (Phase 5)
- UI (Phase 5)

**Type consistency check:**
- `TriggerSource` in `assess-diff.ts` matches `DriftTriggerSource` enum values exactly
- `contextSource` typed as `ContextSource` from `drift-github.ts` — consistent across Tasks 2 and 3
- `CompareOutcome` discriminated union uses `status` discriminant — tests check `result.status !== "ok"` before narrowing
