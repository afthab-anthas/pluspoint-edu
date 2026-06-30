# Fix Readiness Scan: Use GitHub Repo Instead of process.cwd() Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the readiness scan route so it scans the project's connected GitHub repo (via tarball download) instead of blindly scanning `process.cwd()`, and returns an honest 422 for projects with no repo linked.

**Architecture:** The Phase 9b code-health scan already has proven plumbing for org-scoped GitHub tarball download (`downloadRepoTarball`), extraction (`extractTarball`), concurrency control (`tryAcquireScanSlot` / `releaseScanSlot`), and cross-tenant installation guard in `src/lib/code-health.ts`. The readiness scan (`src/app/api/projects/[id]/readiness/scan/route.ts`) currently calls `process.cwd()` for all projects regardless of repo linkage. The fix replaces `process.cwd()` with: (1) 422 for null-repo projects, (2) tarball-fetch → extract → scan extracted dir → hard-delete tmpDir for linked-repo projects.

**Tech Stack:** Next.js 14 App Router, Prisma 7, `@/lib/code-health` (reused tarball plumbing), `@/lib/readiness` (`scanMarkersInDir`, `readCoveragePct`), Vitest, TypeScript 5.

---

## Decision: Local Fallback Removed Entirely

`process.cwd()` is **removed** — no local fallback. A project with `githubRepoFullName === null` gets a 422 `{ error: "no repo linked" }`. The axis-pulse self-project can be linked to the actual Pulse GitHub repo if readiness scanning is needed. This is simpler, safer, and provably correct.

---

## File Map

| Action | File | Change |
|--------|------|--------|
| **Modify** | `src/app/api/projects/[id]/readiness/scan/route.ts` | Replace `process.cwd()` bug with tarball path; add 422 for no-repo; add cross-tenant install guard; add concurrency + temp-dir lifecycle |
| **Modify** | `src/tests/readiness-api.test.ts` | Add mocks for `node:fs`, `@/lib/code-health`, `db.githubInstallation`; update happy-path project fixture to include `githubRepoFullName`; add tests for no-repo 422, tarball-scan path, cross-tenant 403, concurrency 503 |

---

## Task 1: Fix Route + Update Tests

**Files:**
- Modify: `src/app/api/projects/[id]/readiness/scan/route.ts`
- Modify: `src/tests/readiness-api.test.ts`

---

### Step 1: Write the failing tests

Add the following test cases to `src/tests/readiness-api.test.ts`. The tests cover: (a) no-repo project returns 422, (b) repo project scans the extracted dir (NOT cwd), (c) cross-tenant installation guard returns 403, (d) concurrency cap returns 503.

**Complete new file content for `src/tests/readiness-api.test.ts`:**

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest"

// ─── Mocks ────────────────────────────────────────────────────────────────────

vi.mock("node:fs", () => ({
  createWriteStream: vi.fn(),
  mkdirSync: vi.fn(),
  readdirSync: vi.fn().mockReturnValue([]),
  rmSync: vi.fn(),
  statSync: vi.fn(),
}))

vi.mock("node:os", () => ({
  tmpdir: vi.fn().mockReturnValue("/tmp"),
}))

vi.mock("node:path", async (importOriginal) => {
  const actual = await importOriginal<typeof import("node:path")>()
  return { ...actual }
})

vi.mock("@/lib/db", () => ({
  db: {
    project: { findUnique: vi.fn() },
    readinessSnapshot: {
      findMany: vi.fn(),
      create: vi.fn(),
    },
    githubInstallation: { findUnique: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/ratelimit-readiness", () => ({
  checkReadinessScanRateLimit: vi.fn().mockResolvedValue({ allowed: true }),
}))

vi.mock("@/lib/readiness", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/readiness")>()
  return {
    ...actual,
    scanMarkersInDir: vi.fn(),
    readCoveragePct: vi.fn(),
  }
})

vi.mock("@/lib/code-health", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/code-health")>()
  return {
    ...actual,
    downloadRepoTarball: vi.fn().mockResolvedValue(undefined),
    extractTarball: vi.fn(),
    tryAcquireScanSlot: vi.fn().mockReturnValue(true),
    releaseScanSlot: vi.fn(),
  }
})

// ─── Imports ──────────────────────────────────────────────────────────────────

import { GET } from "@/app/api/projects/[id]/readiness/route"
import { POST } from "@/app/api/projects/[id]/readiness/scan/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { checkReadinessScanRateLimit } from "@/lib/ratelimit-readiness"
import { scanMarkersInDir, readCoveragePct } from "@/lib/readiness"
import { downloadRepoTarball, extractTarball, tryAcquireScanSlot, releaseScanSlot } from "@/lib/code-health"
import { rmSync, mkdirSync } from "node:fs"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockDb = vi.mocked(db)
const mockAuth = vi.mocked(withAuthScoped)
const mockScan = vi.mocked(scanMarkersInDir)
const mockCoverage = vi.mocked(readCoveragePct)
const mockRateLimit = vi.mocked(checkReadinessScanRateLimit)
const mockDownloadTarball = vi.mocked(downloadRepoTarball)
const mockExtractTarball = vi.mocked(extractTarball)
const mockTryAcquire = vi.mocked(tryAcquireScanSlot)
const mockRelease = vi.mocked(releaseScanSlot)
const mockRmSync = vi.mocked(rmSync)
const mockMkdirSync = vi.mocked(mkdirSync)

const managerCtx: AuthContext = {
  userId: "u1",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  organisationId: "org1",
  sessionVersion: 1,
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const lineManagerCtx: AuthContext = {
  userId: "u2",
  role: "LINE_MANAGER",
  teamId: "t1",
  isLineManager: true,
  organisationId: "org1",
  sessionVersion: 1,
  scope: { allTeams: false, canSeeTimeData: true, viewedTeamIds: ["t1"] },
}

const memberCtx: AuthContext = {
  userId: "u3",
  role: "MEMBER",
  teamId: "t1",
  isLineManager: false,
  organisationId: "org1",
  sessionVersion: 1,
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["t1"] },
}

// Project with no GitHub repo linked
const mockProjectNoRepo = {
  id: "p1",
  teamId: "t1",
  githubRepoFullName: null,
  githubInstallationId: null,
}

// Project with GitHub repo linked
const mockProjectWithRepo = {
  id: "p1",
  teamId: "t1",
  githubRepoFullName: "owner/repo",
  githubInstallationId: "inst1",
}

function makeReq() {
  return new Request("http://localhost/api/projects/p1/readiness")
}

function makeScanReq() {
  return new Request("http://localhost/api/projects/p1/readiness/scan", { method: "POST" })
}

// ─── GET /api/projects/[id]/readiness ─────────────────────────────────────────

describe("GET /api/projects/[id]/readiness", () => {
  beforeEach(() => vi.clearAllMocks())

  it("returns 401 when unauthenticated", async () => {
    mockAuth.mockResolvedValueOnce(null)
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(401)
  })

  it("returns 404 when project not found", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(null)
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(404)
  })

  it("returns 403 when MEMBER on a different team's project", async () => {
    mockAuth.mockResolvedValueOnce({ ...memberCtx, teamId: "t2" })
    mockDb.project.findUnique.mockResolvedValueOnce({ id: "p1", teamId: "t1" })
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(403)
  })

  it("returns signal with zeros and insufficient forecast when no snapshots", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectNoRepo)
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([])
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.markerCount).toBe(0)
    expect(body.coveragePct).toBeNull()
    expect(body.lastScannedAt).toBeNull()
    expect(body.forecast.kind).toBe("insufficient")
    expect(body.snapshotCount).toBe(0)
  })

  it("returns correct signal when snapshots exist", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectNoRepo)
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([
      {
        markerCount: 10,
        markerDetail: { todo: 5, fixme: 3, skippedTests: 2, stubbed: 0 },
        coveragePct: 75.5,
        scannedAt: new Date("2026-06-14T10:00:00Z"),
      },
    ])
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.markerCount).toBe(10)
    expect(body.coveragePct).toBeCloseTo(75.5)
    expect(body.lastScannedAt).toBe("2026-06-14T10:00:00.000Z")
    expect(body.markerDetail.todo).toBe(5)
  })

  it("MANAGER sees project in a different team", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce({ id: "p1", teamId: "other-team" })
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([])
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
  })

  it("LINE_MANAGER sees own-team project", async () => {
    mockAuth.mockResolvedValueOnce(lineManagerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectNoRepo)
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([])
    const res = await GET(makeReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
  })
})

// ─── POST /api/projects/[id]/readiness/scan ───────────────────────────────────

describe("POST /api/projects/[id]/readiness/scan", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockScan.mockResolvedValue({ todo: 2, fixme: 1, skippedTests: 0, stubbed: 0, testFileCount: 5 })
    mockCoverage.mockResolvedValue(null)
    mockRateLimit.mockResolvedValue({ allowed: true })
    mockTryAcquire.mockReturnValue(true)
    mockDownloadTarball.mockResolvedValue(undefined)
    mockExtractTarball.mockReturnValue(undefined)
    mockDb.githubInstallation.findUnique.mockResolvedValue({ installationId: "inst1" })
  })

  it("returns 401 when unauthenticated", async () => {
    mockAuth.mockResolvedValueOnce(null)
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(401)
  })

  it("returns 404 when project not found", async () => {
    mockAuth.mockResolvedValueOnce(lineManagerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(null)
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(404)
  })

  it("returns 403 when MEMBER attempts a scan", async () => {
    mockAuth.mockResolvedValueOnce(memberCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(403)
  })

  it("returns 429 when rate-limited", async () => {
    mockAuth.mockResolvedValueOnce(lineManagerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    mockRateLimit.mockResolvedValueOnce({ allowed: false })
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(429)
  })

  it("returns 422 when project has no repo linked", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectNoRepo)
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(422)
    const body = await res.json()
    expect(body.error).toMatch(/no repo linked/i)
  })

  it("returns 403 when installation cross-tenant guard fails", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    mockDb.githubInstallation.findUnique.mockResolvedValueOnce(null) // cross-tenant: not found
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(403)
  })

  it("returns 503 when concurrency cap exceeded", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    mockDb.githubInstallation.findUnique.mockResolvedValueOnce({ installationId: "inst1" })
    mockTryAcquire.mockReturnValueOnce(false)
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(503)
  })

  it("scans extracted repo dir — NOT process.cwd() — and stores snapshot (LINE_MANAGER)", async () => {
    mockAuth.mockResolvedValueOnce(lineManagerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    mockDb.githubInstallation.findUnique.mockResolvedValueOnce({ installationId: "inst1" })
    mockScan.mockResolvedValueOnce({ todo: 3, fixme: 0, skippedTests: 1, stubbed: 0, testFileCount: 12 })
    mockCoverage.mockResolvedValueOnce(null)

    const created = {
      id: "snap1",
      markerCount: 4,
      markerDetail: { todo: 3, fixme: 0, skippedTests: 1, stubbed: 0, testFileCount: 12 },
      coveragePct: null,
      scannedAt: new Date("2026-06-16T10:00:00Z"),
    }
    mockDb.readinessSnapshot.create.mockResolvedValueOnce(created)
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([created])

    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
    const body = await res.json()
    expect(body.markerCount).toBe(4)
    expect(mockDb.readinessSnapshot.create).toHaveBeenCalledOnce()

    // scanMarkersInDir must be called with an extracted tmp path, NOT process.cwd()
    const scannedPath = mockScan.mock.calls[0]?.[0] ?? ""
    expect(scannedPath).not.toBe(process.cwd())
    expect(scannedPath).toContain("src") // extracted srcDir contains "src" segment

    // downloadRepoTarball called with correct repo and installationId
    expect(mockDownloadTarball).toHaveBeenCalledWith(
      expect.objectContaining({
        repoFullName: "owner/repo",
        installationId: "inst1",
      })
    )

    // temp dir must be cleaned up
    expect(mockRmSync).toHaveBeenCalledWith(
      expect.stringContaining("pulse-readiness-"),
      expect.objectContaining({ recursive: true, force: true })
    )

    // slot must be released
    expect(mockRelease).toHaveBeenCalledOnce()
  })

  it("scans extracted repo dir — MANAGER on cross-team project", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce({ ...mockProjectWithRepo, teamId: "other" })
    mockDb.githubInstallation.findUnique.mockResolvedValueOnce({ installationId: "inst1" })
    mockScan.mockResolvedValueOnce({ todo: 1, fixme: 0, skippedTests: 0, stubbed: 0, testFileCount: 0 })
    mockCoverage.mockResolvedValueOnce(null)

    const created = {
      id: "snap2",
      markerCount: 1,
      markerDetail: { todo: 1, fixme: 0, skippedTests: 0, stubbed: 0, testFileCount: 0 },
      coveragePct: null,
      scannedAt: new Date("2026-06-16T11:00:00Z"),
    }
    mockDb.readinessSnapshot.create.mockResolvedValueOnce(created)
    mockDb.readinessSnapshot.findMany.mockResolvedValueOnce([created])

    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(200)
  })

  it("returns 403 when LINE_MANAGER scans a different team's project", async () => {
    mockAuth.mockResolvedValueOnce(lineManagerCtx) // teamId: t1
    mockDb.project.findUnique.mockResolvedValueOnce({ ...mockProjectWithRepo, teamId: "t2" })
    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    expect(res.status).toBe(403)
  })

  it("releases slot and deletes tmpDir even when downloadRepoTarball throws", async () => {
    mockAuth.mockResolvedValueOnce(managerCtx)
    mockDb.project.findUnique.mockResolvedValueOnce(mockProjectWithRepo)
    mockDb.githubInstallation.findUnique.mockResolvedValueOnce({ installationId: "inst1" })
    mockDownloadTarball.mockRejectedValueOnce(new Error("network error"))

    const res = await POST(makeScanReq(), { params: { id: "p1" } })
    // Route should propagate as 500
    expect(res.status).toBe(500)
    expect(mockRmSync).toHaveBeenCalledWith(
      expect.stringContaining("pulse-readiness-"),
      expect.objectContaining({ recursive: true, force: true })
    )
    expect(mockRelease).toHaveBeenCalledOnce()
  })
})
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
npx vitest run src/tests/readiness-api.test.ts --reporter=verbose 2>&1 | tail -60
```

Expected: Multiple FAIL lines because:
- The test file itself imports from `@/lib/code-health` which the route doesn't import yet
- Tests expecting 422 will see 200 (current code scans cwd for null-repo projects)
- Tests expecting cross-tenant 403 will not fire (no githubInstallation check)
- Tests expecting slot/tmpDir cleanup will see those mocks never called

---

- [ ] **Step 3: Implement the fix in the scan route**

Replace the entire content of `src/app/api/projects/[id]/readiness/scan/route.ts` with:

```typescript
import { NextResponse } from "next/server"
import { mkdirSync, rmSync } from "node:fs"
import { tmpdir } from "node:os"
import { join } from "node:path"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import {
  buildReadinessSignal,
  scanMarkersInDir,
  readCoveragePct,
  sumMarkerDetail,
} from "@/lib/readiness"
import {
  downloadRepoTarball,
  extractTarball,
  tryAcquireScanSlot,
  releaseScanSlot,
} from "@/lib/code-health"
import { checkReadinessScanRateLimit } from "@/lib/ratelimit-readiness"
import type { MarkerDetail } from "@/lib/readiness"

export async function POST(
  _req: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })

  const project = await db.project.findUnique({
    where: { id: params.id, organisationId: ctx.organisationId },
    select: {
      id: true,
      teamId: true,
      githubRepoFullName: true,
      githubInstallationId: true,
    },
  })
  if (!project) return NextResponse.json({ error: "Not found" }, { status: 404 })

  if (ctx.role === "MEMBER") {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 })
  }
  if (ctx.role !== "MANAGER" && project.teamId !== ctx.teamId) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 })
  }

  const { allowed } = await checkReadinessScanRateLimit(params.id)
  if (!allowed) return NextResponse.json({ error: "Too many requests" }, { status: 429 })

  // Honest empty state: no repo linked means nothing to scan
  if (!project.githubRepoFullName || !project.githubInstallationId) {
    return NextResponse.json(
      { error: "No repo linked — link a GitHub repo to this project to enable readiness scanning" },
      { status: 422 }
    )
  }

  // Cross-tenant guard: verify the installation belongs to the same org
  const installation = await db.githubInstallation.findUnique({
    where: {
      organisationId_installationId: {
        organisationId: ctx.organisationId,
        installationId: project.githubInstallationId,
      },
    },
    select: { installationId: true },
  })
  if (!installation) {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 })
  }

  if (!tryAcquireScanSlot()) {
    return NextResponse.json({ error: "Scan capacity exceeded" }, { status: 503 })
  }

  const tmpDir = join(tmpdir(), `pulse-readiness-${params.id}-${Date.now()}`)
  const tarballPath = join(tmpDir, "repo.tar.gz")
  const srcDir = join(tmpDir, "src")

  mkdirSync(tmpDir, { recursive: true })

  try {
    await downloadRepoTarball({
      repoFullName: project.githubRepoFullName,
      ref: "HEAD",
      installationId: project.githubInstallationId,
      tarballPath,
    })

    extractTarball(tarballPath, srcDir)

    const [markerDetail, coveragePct] = await Promise.all([
      scanMarkersInDir(srcDir),
      readCoveragePct(srcDir),
    ])
    const markerCount = sumMarkerDetail(markerDetail)

    await db.readinessSnapshot.create({
      data: {
        projectId: params.id,
        organisationId: ctx.organisationId,
        markerCount,
        markerDetail,
        coveragePct,
      },
    })

    const snapshots = await db.readinessSnapshot.findMany({
      where: { projectId: params.id, organisationId: ctx.organisationId },
      orderBy: { scannedAt: "desc" },
      take: 90,
      select: { markerCount: true, markerDetail: true, coveragePct: true, scannedAt: true },
    })

    const latest = snapshots[0] ?? null

    const signal = buildReadinessSignal(
      latest
        ? {
            markerCount: latest.markerCount,
            markerDetail: latest.markerDetail as MarkerDetail,
            coveragePct: latest.coveragePct,
            scannedAt: latest.scannedAt,
          }
        : null,
      snapshots.map((s) => ({ markerCount: s.markerCount, scannedAt: s.scannedAt }))
    )

    return NextResponse.json({
      ...signal,
      lastScannedAt: signal.lastScannedAt?.toISOString() ?? null,
    })
  } catch (err) {
    return NextResponse.json(
      { error: "Scan failed", detail: (err as Error).message },
      { status: 500 }
    )
  } finally {
    releaseScanSlot()
    rmSync(tmpDir, { recursive: true, force: true })
  }
}
```

---

- [ ] **Step 4: Run TypeScript check**

```bash
npx tsc --noEmit 2>&1 | grep "src/app/api/projects/\[id\]/readiness" | head -20
```

Expected: 0 errors for the modified route file.

---

- [ ] **Step 5: Run the tests to verify they pass**

```bash
npx vitest run src/tests/readiness-api.test.ts --reporter=verbose 2>&1 | tail -60
```

Expected: All tests PASS (including the new ones).

---

- [ ] **Step 6: Run full test suite**

```bash
npm test 2>&1 | tail -20
```

Expected: All existing tests still green; total count same or higher.

---

- [ ] **Step 7: Commit**

```bash
git add src/app/api/projects/\[id\]/readiness/scan/route.ts src/tests/readiness-api.test.ts
git commit -m "fix(readiness-scan): scan project's GitHub repo tarball instead of process.cwd()"
```

---

## Self-Review Against Spec

| Requirement | Covered by |
|-------------|-----------|
| Bug: stop using `process.cwd()` | Route replacement in Step 3 |
| Reuse Phase 9b tarball plumbing | `downloadRepoTarball`, `extractTarball`, `tryAcquireScanSlot`, `releaseScanSlot` imported from `@/lib/code-health` |
| Org-scoped token resolution | `downloadRepoTarball` calls `authedGithubFetch(url, installationId)` internally (existing, proven) |
| Cross-tenant installation guard | `db.githubInstallation.findUnique` with `organisationId_installationId` compound key |
| Honest empty state for null-repo | 422 response with descriptive error message |
| Temp dir hard-deleted in finally | `rmSync(tmpDir, { recursive: true, force: true })` in `finally` block |
| Slot released in finally | `releaseScanSlot()` in `finally` block |
| No repo contents in logs | No logging of tarball path or extracted content |
| Concurrency cap | `tryAcquireScanSlot()` → 503 if full |
| Tests: no-repo 422 | ✓ |
| Tests: cross-tenant 403 | ✓ |
| Tests: concurrency 503 | ✓ |
| Tests: scan path is NOT cwd | ✓ (asserts `scannedPath !== process.cwd()`) |
| Tests: tmpDir deleted even on error | ✓ (downloadRepoTarball throws → 500 + rmSync called) |
| Tests: RBAC (MEMBER 403, LM wrong team 403) | ✓ |
| Tests: 401/404/429 | ✓ |

**Placeholder scan:** No TBD/TODO/placeholder patterns found in this plan.

**Type consistency check:** `MarkerDetail` type imported from `@/lib/readiness` used consistently. `downloadRepoTarball` signature is `{ repoFullName, ref, installationId, tarballPath }` — matches what's called in the route. `extractTarball(tarballPath, srcDir)` matches the signature in `code-health.ts`.
