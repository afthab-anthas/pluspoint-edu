# Context Drift Analyser — Phase 5 UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Context Analyser UI — a new top-level nav section + pages backed by two new API routes — that displays the drift data produced by Phases 1–4 and lets MANAGER/LINE_MANAGER users trigger assessments, poll for results, and see findings with evidence.

**Architecture:** Two new API routes extend the existing `/drift/` route family (GET single assessment for polling; POST baseline stub). Three server pages + one client component form the UI. The nav entry is added to AppShellClient for MANAGER + LINE_MANAGER only. All DB reads use the strict org-scoped pattern (`{ id, organisationId }` on project, `{ id, projectId, organisationId }` on assessment).

**Tech Stack:** Next.js 14 App Router (server + client components), TypeScript 5, Prisma 7, `withAuthScoped`, existing design tokens (`#0ee29e` mint, dark flat cards, IBM Plex Mono + Plus Jakarta Sans)

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/app/api/projects/[id]/drift/[assessmentId]/route.ts` | **CREATE** | GET single assessment with full findings; calls `resetStaleRunningAssessments` |
| `src/app/api/projects/[id]/drift/baseline/route.ts` | **CREATE** | POST baseline stub — 202 + `{ stubbed: true }`; Phase 6 fills logic |
| `src/tests/drift-assessment-get.test.ts` | **CREATE** | 8 tests: 401/403/404 guards, cross-org leak, cross-project-within-org leak, LM out-of-team, LM own-team, stale-reset called |
| `src/tests/drift-baseline-route.test.ts` | **CREATE** | 4 tests: 401/403-MEMBER/404-project/202-success |
| `src/app/context-analyser/page.tsx` | **CREATE** | Server: project list with latest drift badge (GREEN/AMBER/RED/NONE); RBAC gate |
| `src/app/context-analyser/[id]/page.tsx` | **CREATE** | Server: project detail — latest assessment, per-doc breakdown, no-context state |
| `src/app/context-analyser/[id]/_components/DriftDetailClient.tsx` | **CREATE** | Client: Run button, PENDING→RUNNING→COMPLETE polling, findings list, Marlin escalation |
| `src/components/AppShellClient.tsx` | **MODIFY** | Add "Context Analyser" nav item (MANAGER + LINE_MANAGER only); add pageTitle entry |

---

## Task 1: GET /drift/[assessmentId] route + full leak test suite

**Files:**
- Create: `src/app/api/projects/[id]/drift/[assessmentId]/route.ts`
- Create: `src/tests/drift-assessment-get.test.ts`

### Why this is Task 1
This is the polling endpoint the whole UI depends on. It is also the most security-sensitive new surface: it must scope to `{ id, projectId, organisationId }` (three-way) to block both cross-org and cross-project-within-same-org reads. Proving the leak tests green before touching the UI ensures the security invariant is locked in.

- [ ] **Step 1: Write the failing tests**

Create `src/tests/drift-assessment-get.test.ts`:

```typescript
// src/tests/drift-assessment-get.test.ts
import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/withAuthScoped", () => ({ withAuthScoped: vi.fn() }))
vi.mock("@/lib/db", () => ({
  db: {
    project: { findUnique: vi.fn() },
    contextDriftAssessment: { findFirst: vi.fn() },
  },
}))
vi.mock("@/lib/drift/orchestrate", () => ({
  resetStaleRunningAssessments: vi.fn(),
}))

import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { resetStaleRunningAssessments } from "@/lib/drift/orchestrate"
import { GET } from "@/app/api/projects/[id]/drift/[assessmentId]/route"

const mockAuth = vi.mocked(withAuthScoped)
const mockDb = vi.mocked(db)
const mockStaleReset = vi.mocked(resetStaleRunningAssessments)

const params = { id: "proj-1", assessmentId: "assess-1" }

const managerCtx = {
  userId: "user-1",
  organisationId: "org-1",
  role: "MANAGER" as const,
  teamId: null,
  isLineManager: false,
  scope: { canSeeTimeData: true },
}

const lmCtx = {
  ...managerCtx,
  role: "LINE_MANAGER" as const,
  teamId: "team-1",
  isLineManager: true,
}

const memberCtx = { ...managerCtx, role: "MEMBER" as const, teamId: "team-1" }

const project = { id: "proj-1", teamId: "team-1" }

const completedAssessment = {
  id: "assess-1",
  status: "COMPLETE",
  riskLevel: "RED",
  findingsCount: 2,
  triggeredBy: "ON_DEMAND",
  contextSource: "context_builds",
  diffTruncated: false,
  findings: [
    {
      type: "ACCURACY",
      area: "AI provider",
      doc: "docs/decisions.md",
      description: "docs say claude-sonnet-4-6 but code uses Groq llama-3.3-70b",
      severity: "HIGH",
      confidence: 0.95,
      evidenceDocExcerpt: "All Claude calls use claude-sonnet-4-6",
      evidenceCodeExcerpt: "getAutoCallModel()",
    },
  ],
  changedFiles: ["src/lib/narrate.ts"],
  baselineSha: "abc123",
  headSha: "def456",
  createdAt: new Date(),
  assessedAt: new Date(),
  error: null,
  aiTokensUsed: 1200,
  costUSD: 0.004,
}

function makeRequest() {
  return new Request("http://localhost/api/projects/proj-1/drift/assess-1")
}

beforeEach(() => {
  vi.clearAllMocks()
  mockStaleReset.mockResolvedValue(0)
})

describe("GET /api/projects/[id]/drift/[assessmentId]", () => {
  it("returns 401 when unauthenticated", async () => {
    mockAuth.mockResolvedValue(null)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(401)
  })

  it("returns 403 for MEMBER role — nav section must also be hidden at UI layer", async () => {
    mockAuth.mockResolvedValue(memberCtx as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(403)
  })

  it("cross-org leak blocked: org-2 manager cannot read org-1 assessment (project lookup returns null)", async () => {
    // Attack: org-2 MANAGER knows assessment ID from org-1. They hit the route.
    // Route does findUnique({ id: "proj-1", organisationId: "org-2" }) → null → 404.
    const orgBCtx = { ...managerCtx, organisationId: "org-2" }
    mockAuth.mockResolvedValue(orgBCtx as never)
    mockDb.project.findUnique.mockResolvedValue(null as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(404)
    expect(mockDb.contextDriftAssessment.findFirst).not.toHaveBeenCalled()
  })

  it("LINE_MANAGER out-of-team blocked: LM of team-2 cannot read team-1 project assessment", async () => {
    const lmTeam2 = { ...lmCtx, teamId: "team-2" }
    mockAuth.mockResolvedValue(lmTeam2 as never)
    // project.teamId = "team-1", lm.teamId = "team-2" → mismatch → 403
    mockDb.project.findUnique.mockResolvedValue(project as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(403)
    expect(mockDb.contextDriftAssessment.findFirst).not.toHaveBeenCalled()
  })

  it("cross-project-within-org blocked: assessment scoped to different project returns 404", async () => {
    // Attack: MANAGER of org-1 knows assessment assess-1 belongs to proj-2 in same org.
    // They call GET /projects/proj-1/drift/assess-1.
    // Route does findFirst({ id: "assess-1", projectId: "proj-1", organisationId: "org-1" }) → null.
    mockAuth.mockResolvedValue(managerCtx as never)
    mockDb.project.findUnique.mockResolvedValue(project as never)
    // findFirst returns null because projectId mismatch
    mockDb.contextDriftAssessment.findFirst.mockResolvedValue(null as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(404)
  })

  it("calls resetStaleRunningAssessments before reading assessment (Phase 4 stale recovery)", async () => {
    mockAuth.mockResolvedValue(managerCtx as never)
    mockDb.project.findUnique.mockResolvedValue(project as never)
    mockDb.contextDriftAssessment.findFirst.mockResolvedValue(completedAssessment as never)
    await GET(makeRequest(), { params })
    expect(mockStaleReset).toHaveBeenCalledOnce()
  })

  it("returns 200 with full findings for MANAGER on COMPLETE assessment", async () => {
    mockAuth.mockResolvedValue(managerCtx as never)
    mockDb.project.findUnique.mockResolvedValue(project as never)
    mockDb.contextDriftAssessment.findFirst.mockResolvedValue(completedAssessment as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(200)
    const body = await res.json() as { assessment: { id: string; status: string; findings: unknown[] } }
    expect(body.assessment.id).toBe("assess-1")
    expect(body.assessment.status).toBe("COMPLETE")
    expect(Array.isArray(body.assessment.findings)).toBe(true)
  })

  it("LINE_MANAGER own-team: returns 200 (access permitted)", async () => {
    mockAuth.mockResolvedValue(lmCtx as never) // teamId: "team-1"
    mockDb.project.findUnique.mockResolvedValue(project as never) // teamId: "team-1"
    mockDb.contextDriftAssessment.findFirst.mockResolvedValue(completedAssessment as never)
    const res = await GET(makeRequest(), { params })
    expect(res.status).toBe(200)
  })
})
```

- [ ] **Step 2: Run the tests to confirm they all FAIL (route doesn't exist yet)**

```
npx vitest run src/tests/drift-assessment-get.test.ts
```

Expected: all 8 tests fail with import error or "cannot find module".

- [ ] **Step 3: Implement the route**

Create `src/app/api/projects/[id]/drift/[assessmentId]/route.ts`:

```typescript
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { resetStaleRunningAssessments } from "@/lib/drift/orchestrate"

export const runtime = "nodejs"

export async function GET(
  _req: Request,
  { params }: { params: { id: string; assessmentId: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  // STRICT pattern: include organisationId so cross-org lookup is impossible
  const project = await db.project.findUnique({
    where: { id: params.id, organisationId: ctx.organisationId },
    select: { id: true, teamId: true },
  })
  if (!project) return NextResponse.json({ reason: "not_found" }, { status: 404 })

  if (ctx.role === "LINE_MANAGER" && project.teamId !== ctx.teamId) {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }

  // Call Phase 4 stale-recovery before reading (so a stuck RUNNING shows as ERROR)
  await resetStaleRunningAssessments()

  // Triple-scoped: id + projectId + organisationId blocks cross-project reads within same org
  const assessment = await db.contextDriftAssessment.findFirst({
    where: {
      id: params.assessmentId,
      projectId: params.id,
      organisationId: ctx.organisationId,
    },
    select: {
      id: true,
      status: true,
      riskLevel: true,
      findingsCount: true,
      triggeredBy: true,
      contextSource: true,
      diffTruncated: true,
      findings: true,
      changedFiles: true,
      baselineSha: true,
      headSha: true,
      createdAt: true,
      assessedAt: true,
      error: true,
      aiTokensUsed: true,
      costUSD: true,
    },
  })

  if (!assessment) return NextResponse.json({ reason: "not_found" }, { status: 404 })

  return NextResponse.json({ assessment })
}
```

- [ ] **Step 4: Run the tests to confirm all 8 pass**

```
npx vitest run src/tests/drift-assessment-get.test.ts
```

Expected: 8/8 PASS.

- [ ] **Step 5: Run the full test suite to confirm no regressions**

```
npm test
```

Expected: all existing tests green, new 8 added.

- [ ] **Step 6: Commit**

```
git add src/app/api/projects/[id]/drift/[assessmentId]/route.ts src/tests/drift-assessment-get.test.ts
git commit -m "feat(drift): GET /drift/[assessmentId] with triple-scoped org+project guard + leak tests — Phase 5 Ref-5xx"
```

---

## Task 2: POST /drift/baseline stub route + tests

**Files:**
- Create: `src/app/api/projects/[id]/drift/baseline/route.ts`
- Create: `src/tests/drift-baseline-route.test.ts`

**Note:** Phase 6 fills the actual reset logic. This stub proves the RBAC works and returns `{ stubbed: true }` so the UI button is wired and testable today.

- [ ] **Step 1: Write failing tests**

Create `src/tests/drift-baseline-route.test.ts`:

```typescript
// src/tests/drift-baseline-route.test.ts
import { describe, it, expect, vi, beforeEach } from "vitest"

vi.mock("@/lib/withAuthScoped", () => ({ withAuthScoped: vi.fn() }))
vi.mock("@/lib/db", () => ({
  db: { project: { findUnique: vi.fn() } },
}))

import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { POST } from "@/app/api/projects/[id]/drift/baseline/route"

const mockAuth = vi.mocked(withAuthScoped)
const mockDb = vi.mocked(db)

const params = { id: "proj-1" }

const managerCtx = {
  userId: "user-1",
  organisationId: "org-1",
  role: "MANAGER" as const,
  teamId: null,
  isLineManager: false,
  scope: { canSeeTimeData: true },
}

const memberCtx = { ...managerCtx, role: "MEMBER" as const, teamId: "team-1" }

const project = { id: "proj-1", teamId: "team-1" }

function makeRequest() {
  return new Request("http://localhost/api/projects/proj-1/drift/baseline", { method: "POST" })
}

beforeEach(() => vi.clearAllMocks())

describe("POST /api/projects/[id]/drift/baseline", () => {
  it("returns 401 when unauthenticated", async () => {
    mockAuth.mockResolvedValue(null)
    const res = await POST(makeRequest(), { params })
    expect(res.status).toBe(401)
  })

  it("returns 403 for MEMBER role", async () => {
    mockAuth.mockResolvedValue(memberCtx as never)
    const res = await POST(makeRequest(), { params })
    expect(res.status).toBe(403)
  })

  it("returns 404 when project not in org (strict lookup)", async () => {
    mockAuth.mockResolvedValue(managerCtx as never)
    mockDb.project.findUnique.mockResolvedValue(null as never)
    const res = await POST(makeRequest(), { params })
    expect(res.status).toBe(404)
  })

  it("returns 202 with stubbed flag for MANAGER (Phase 6 will add full reset logic)", async () => {
    mockAuth.mockResolvedValue(managerCtx as never)
    mockDb.project.findUnique.mockResolvedValue(project as never)
    const res = await POST(makeRequest(), { params })
    expect(res.status).toBe(202)
    const body = await res.json() as { stubbed: boolean }
    expect(body.stubbed).toBe(true)
  })
})
```

- [ ] **Step 2: Run to confirm failure**

```
npx vitest run src/tests/drift-baseline-route.test.ts
```

- [ ] **Step 3: Implement the stub route**

Create `src/app/api/projects/[id]/drift/baseline/route.ts`:

```typescript
import { NextResponse } from "next/server"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"

export const runtime = "nodejs"

// Phase 5 stub — Phase 6 will add: read latest context_builds SHA, update
// Project.contextBranchBaselineSha, and reset any PENDING/RUNNING assessments.
export async function POST(
  _req: Request,
  { params }: { params: { id: string } }
) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") return NextResponse.json({ reason: "forbidden" }, { status: 403 })

  const project = await db.project.findUnique({
    where: { id: params.id, organisationId: ctx.organisationId },
    select: { id: true, teamId: true },
  })
  if (!project) return NextResponse.json({ reason: "not_found" }, { status: 404 })

  if (ctx.role === "LINE_MANAGER" && project.teamId !== ctx.teamId) {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }

  return NextResponse.json({ stubbed: true }, { status: 202 })
}
```

- [ ] **Step 4: Run tests — confirm 4/4 pass**

```
npx vitest run src/tests/drift-baseline-route.test.ts
```

- [ ] **Step 5: Run full suite**

```
npm test
```

- [ ] **Step 6: Commit**

```
git add src/app/api/projects/[id]/drift/baseline/route.ts src/tests/drift-baseline-route.test.ts
git commit -m "feat(drift): POST /drift/baseline stub with RBAC guard — Phase 5 Ref-5xx"
```

---

## Task 3: Context Analyser project list page + nav entry

**Files:**
- Create: `src/app/context-analyser/page.tsx`
- Modify: `src/components/AppShellClient.tsx`

The project list page is a server component. It fetches all projects scoped by role (MANAGER = org-wide, LM = team-scoped), then for each project fetches the latest `ContextDriftAssessment` (latest by `createdAt desc`, take 1). It renders project rows with a risk badge (GREEN/AMBER/RED/NONE) and a link to the detail page.

MEMBER is redirected to `/dashboard` — the guard lives in the page itself, matching the pattern in `dashboard/page.tsx`.

- [ ] **Step 1: Add "Context Analyser" nav item to AppShellClient**

Read `src/components/AppShellClient.tsx` lines 284–322 (the nav items block). After the `<SectionLabel label="Main" />` block and before the Admin sections, insert a new "Intelligence" section visible to MANAGER + LINE_MANAGER:

```tsx
{/* Context Analyser — MANAGER + LINE_MANAGER only */}
{(role === "MANAGER" || role === "LINE_MANAGER") && (
  <>
    <SectionLabel label="Intelligence" />
    <NavLink href="/context-analyser" label="Context Analyser" icon={<ScanIcon />} />
  </>
)}
```

Add `ScanIcon` near the other icon definitions (search for `function GridIcon`):

```tsx
function ScanIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
      <path d="M1 4V2.5A1.5 1.5 0 0 1 2.5 1H4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
      <path d="M12 1h1.5A1.5 1.5 0 0 1 15 2.5V4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
      <path d="M15 12v1.5A1.5 1.5 0 0 1 13.5 15H12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
      <path d="M4 15H2.5A1.5 1.5 0 0 1 1 13.5V12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/>
      <rect x="5" y="5" width="6" height="6" rx="1" stroke="currentColor" strokeWidth="1.5"/>
    </svg>
  )
}
```

Also add the pageTitle entry in the `pageTitle` derivation block:

```typescript
if (pathname === "/context-analyser") return "Context Analyser"
if (pathname.startsWith("/context-analyser/")) return "Context Analyser"
```

- [ ] **Step 2: Create the project list page**

Create `src/app/context-analyser/page.tsx`:

```tsx
import { redirect } from "next/navigation"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { AppShell } from "@/components/AppShell"
import Link from "next/link"

export default async function ContextAnalyserPage() {
  const ctx = await withAuthScoped()
  if (!ctx) redirect("/login")
  if (ctx.role === "MEMBER") redirect("/dashboard")

  const where =
    ctx.role === "MANAGER"
      ? { organisationId: ctx.organisationId }
      : { teamId: ctx.teamId ?? "__none__", organisationId: ctx.organisationId }

  const projects = await db.project.findMany({
    where,
    select: {
      id: true,
      name: true,
      githubRepoFullName: true,
      driftAssessments: {
        orderBy: { createdAt: "desc" },
        take: 1,
        select: {
          id: true,
          status: true,
          riskLevel: true,
          findingsCount: true,
          assessedAt: true,
        },
      },
    },
    orderBy: { name: "asc" },
  })

  const user = await db.user.findUnique({
    where: { id: ctx.userId },
    select: { name: true },
  })
  const userName = user?.name ?? "User"

  return (
    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
      <div style={{ padding: "1.4rem 1.5rem 2rem", maxWidth: 900 }}>
        <div style={{ marginBottom: "1.5rem" }}>
          <h1
            style={{
              fontSize: "1.25rem",
              fontWeight: 800,
              color: "var(--text-primary)",
              margin: 0,
              letterSpacing: "-0.025em",
            }}
          >
            Context Analyser
          </h1>
          <p style={{ marginTop: "0.35rem", fontSize: "13px", color: "var(--text-muted)", margin: "0.35rem 0 0" }}>
            Checks whether each project's code has drifted from its context documentation.
          </p>
        </div>

        {projects.length === 0 && (
          <div
            style={{
              background: "var(--surface-card)",
              border: "1px solid var(--border)",
              borderRadius: 10,
              padding: "2rem",
              textAlign: "center",
              color: "var(--text-muted)",
              fontSize: "13px",
            }}
          >
            No projects found.
          </div>
        )}

        <div style={{ display: "flex", flexDirection: "column", gap: "0.6rem" }}>
          {projects.map((project) => {
            const latest = project.driftAssessments[0] ?? null
            return (
              <Link
                key={project.id}
                href={`/context-analyser/${project.id}`}
                style={{ textDecoration: "none" }}
              >
                <div
                  style={{
                    background: "var(--surface-card)",
                    border: "1px solid var(--border)",
                    borderRadius: 10,
                    padding: "0.85rem 1.1rem",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "space-between",
                    cursor: "pointer",
                    transition: "border-color 0.15s",
                  }}
                  onMouseEnter={undefined}
                >
                  <div>
                    <div
                      style={{
                        fontSize: "14px",
                        fontWeight: 700,
                        color: "var(--text-primary)",
                        letterSpacing: "-0.01em",
                      }}
                    >
                      {project.name}
                    </div>
                    {project.githubRepoFullName && (
                      <div
                        style={{
                          fontSize: "11.5px",
                          color: "var(--text-muted)",
                          marginTop: "2px",
                          fontFamily: "var(--font-mono, monospace)",
                        }}
                      >
                        {project.githubRepoFullName}
                      </div>
                    )}
                  </div>
                  <RiskBadge risk={latest?.riskLevel ?? null} status={latest?.status ?? null} findingsCount={latest?.findingsCount ?? null} />
                </div>
              </Link>
            )
          })}
        </div>
      </div>
    </AppShell>
  )
}

function RiskBadge({
  risk,
  status,
  findingsCount,
}: {
  risk: string | null
  status: string | null
  findingsCount: number | null
}) {
  if (!status) {
    return (
      <span
        style={{
          fontSize: "11px",
          fontWeight: 600,
          color: "var(--text-muted)",
          background: "var(--surface-subtle)",
          border: "1px solid var(--border)",
          borderRadius: 20,
          padding: "2px 9px",
        }}
      >
        No assessment
      </span>
    )
  }

  if (status === "PENDING" || status === "RUNNING") {
    return (
      <span
        style={{
          fontSize: "11px",
          fontWeight: 600,
          color: "#f59e0b",
          background: "rgba(245,158,11,0.08)",
          border: "1px solid rgba(245,158,11,0.2)",
          borderRadius: 20,
          padding: "2px 9px",
        }}
      >
        Running…
      </span>
    )
  }

  if (status === "ERROR") {
    return (
      <span
        style={{
          fontSize: "11px",
          fontWeight: 600,
          color: "#f87171",
          background: "rgba(248,113,113,0.08)",
          border: "1px solid rgba(248,113,113,0.2)",
          borderRadius: 20,
          padding: "2px 9px",
        }}
      >
        Error
      </span>
    )
  }

  const config: Record<string, { color: string; bg: string; border: string; label: string }> = {
    GREEN: {
      color: "#0ee29e",
      bg: "rgba(14,226,158,0.08)",
      border: "rgba(14,226,158,0.2)",
      label: "Green",
    },
    AMBER: {
      color: "#f59e0b",
      bg: "rgba(245,158,11,0.08)",
      border: "rgba(245,158,11,0.2)",
      label: "Amber",
    },
    RED: {
      color: "#f87171",
      bg: "rgba(248,113,113,0.08)",
      border: "rgba(248,113,113,0.2)",
      label: "Red",
    },
  }

  const c = (risk && config[risk]) ? config[risk]! : config["GREEN"]!
  const count = findingsCount ?? 0

  return (
    <span
      style={{
        fontSize: "11px",
        fontWeight: 600,
        color: c.color,
        background: c.bg,
        border: `1px solid ${c.border}`,
        borderRadius: 20,
        padding: "2px 9px",
        display: "inline-flex",
        alignItems: "center",
        gap: "4px",
      }}
    >
      <span
        style={{
          width: 6,
          height: 6,
          borderRadius: "50%",
          background: c.color,
          display: "inline-block",
          flexShrink: 0,
        }}
      />
      {c.label}
      {count > 0 && ` · ${count} finding${count !== 1 ? "s" : ""}`}
    </span>
  )
}
```

- [ ] **Step 3: Run tsc to confirm no type errors**

```
npx tsc --noEmit 2>&1 | grep -v "src/tests"
```

Expected: 0 errors in src/ (test files may have existing baseline errors).

- [ ] **Step 4: Run full test suite**

```
npm test
```

- [ ] **Step 5: Commit**

```
git add src/app/context-analyser/page.tsx src/components/AppShellClient.tsx
git commit -m "feat(drift): Context Analyser project list page + nav entry — Phase 5 Ref-5xx"
```

---

## Task 4: Per-project drift detail page (server component)

**Files:**
- Create: `src/app/context-analyser/[id]/page.tsx`

This server page loads:
1. The project (org-scoped + team-scoped for LM)
2. The latest 5 assessments (list preview)
3. Full findings from the most recent COMPLETE assessment

It renders: risk badge + header, per-doc breakdown (server-computed from findings), DriftDetailClient (Run button + polling, passed initial data), findings list, no-context state.

- [ ] **Step 1: Create the detail page**

Create `src/app/context-analyser/[id]/page.tsx`:

```tsx
import { redirect, notFound } from "next/navigation"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { AppShell } from "@/components/AppShell"
import Link from "next/link"
import { DriftDetailClient } from "./_components/DriftDetailClient"
import type { DriftFinding } from "@/lib/drift/analyse-ai"

export default async function ContextAnalyserDetailPage({
  params,
}: {
  params: { id: string }
}) {
  const ctx = await withAuthScoped()
  if (!ctx) redirect("/login")
  if (ctx.role === "MEMBER") redirect("/dashboard")

  // STRICT lookup — organisationId required
  const project = await db.project.findUnique({
    where: { id: params.id, organisationId: ctx.organisationId },
    select: {
      id: true,
      name: true,
      teamId: true,
      githubRepoFullName: true,
      contextBranchBaselineSha: true,
    },
  })
  if (!project) notFound()

  if (ctx.role === "LINE_MANAGER" && project.teamId !== ctx.teamId) {
    redirect("/context-analyser")
  }

  const user = await db.user.findUnique({
    where: { id: ctx.userId },
    select: { name: true },
  })
  const userName = user?.name ?? "User"

  const assessments = await db.contextDriftAssessment.findMany({
    where: { projectId: params.id, organisationId: ctx.organisationId },
    orderBy: { createdAt: "desc" },
    take: 5,
    select: {
      id: true,
      status: true,
      riskLevel: true,
      findingsCount: true,
      triggeredBy: true,
      contextSource: true,
      diffTruncated: true,
      findings: true,
      createdAt: true,
      assessedAt: true,
      error: true,
    },
  })

  const latestComplete = assessments.find((a) => a.status === "COMPLETE") ?? null
  const latestAny = assessments[0] ?? null
  const findings: DriftFinding[] = latestComplete
    ? (latestComplete.findings as DriftFinding[] | null) ?? []
    : []

  // Per-doc breakdown — group findings by doc
  const docBreakdown = buildDocBreakdown(findings)

  const noContextBuild =
    latestComplete?.contextSource === "none" ||
    (!latestComplete && !project.githubRepoFullName)

  return (
    <AppShell role={ctx.role} userName={userName} userId={ctx.userId}>
      <div style={{ padding: "1.4rem 1.5rem 2rem", maxWidth: 860 }}>
        {/* Breadcrumb */}
        <div style={{ fontSize: "12px", color: "var(--text-muted)", marginBottom: "1rem" }}>
          <Link href="/context-analyser" style={{ color: "var(--text-muted)", textDecoration: "none" }}>
            Context Analyser
          </Link>
          {" / "}
          <span style={{ color: "var(--text-primary)" }}>{project.name}</span>
        </div>

        <DriftDetailClient
          projectId={params.id}
          projectName={project.name}
          latestAssessment={latestAny
            ? {
                id: latestAny.id,
                status: latestAny.status as string,
                riskLevel: (latestAny.riskLevel ?? null) as string | null,
                findingsCount: latestAny.findingsCount ?? 0,
                contextSource: latestAny.contextSource,
                diffTruncated: latestAny.diffTruncated,
                error: latestAny.error ?? null,
              }
            : null}
          findings={findings}
          docBreakdown={docBreakdown}
          noContextBuild={noContextBuild}
          hasBaselineSha={!!project.contextBranchBaselineSha}
        />
      </div>
    </AppShell>
  )
}

// ── Helpers ──────────────────────────────────────────────────────────────────

interface DocSummary {
  doc: string
  findings: DriftFinding[]
  highCount: number
  mediumCount: number
  lowCount: number
}

function buildDocBreakdown(findings: DriftFinding[]): DocSummary[] {
  const map = new Map<string, DriftFinding[]>()
  for (const f of findings) {
    const existing = map.get(f.doc) ?? []
    existing.push(f)
    map.set(f.doc, existing)
  }
  return Array.from(map.entries()).map(([doc, fs]) => ({
    doc,
    findings: fs,
    highCount: fs.filter((f) => f.severity === "HIGH").length,
    mediumCount: fs.filter((f) => f.severity === "MEDIUM").length,
    lowCount: fs.filter((f) => f.severity === "LOW").length,
  }))
}
```

- [ ] **Step 2: Run tsc**

```
npx tsc --noEmit 2>&1 | grep -v "src/tests"
```

- [ ] **Step 3: Commit**

```
git add src/app/context-analyser/[id]/page.tsx
git commit -m "feat(drift): Context Analyser per-project server page — Phase 5 Ref-5xx"
```

---

## Task 5: DriftDetailClient — Run button + polling + findings list + states

**Files:**
- Create: `src/app/context-analyser/[id]/_components/DriftDetailClient.tsx`

This is the full interactive client component. It receives initial data from the server page and handles:
1. **Run button** → POST /api/projects/[id]/drift → get assessmentId → start polling
2. **Polling** → GET /api/projects/[id]/drift/[assessmentId] every 3s → stops on COMPLETE/ERROR/timeout (120s)
3. **Risk display** — GREEN/AMBER/RED badge with counts
4. **Findings list** — type, doc, area, severity, description, evidence excerpts (collapsible)
5. **Per-doc breakdown** — summary of findings per doc
6. **No-context-build state** — honest explainer (G6)
7. **Marlin escalation** — shown when riskLevel is RED (AC8)
8. **Mark Context Refreshed button** → POST /api/projects/[id]/drift/baseline (Phase 6 stub)

- [ ] **Step 1: Create DriftDetailClient.tsx**

Create `src/app/context-analyser/[id]/_components/DriftDetailClient.tsx`:

```tsx
"use client"

import { useState, useEffect, useCallback, useRef } from "react"
import type { DriftFinding } from "@/lib/drift/analyse-ai"

// ── Types ─────────────────────────────────────────────────────────────────────

interface AssessmentSummary {
  id: string
  status: string
  riskLevel: string | null
  findingsCount: number
  contextSource: string
  diffTruncated: boolean
  error: string | null
}

interface DocSummary {
  doc: string
  findings: DriftFinding[]
  highCount: number
  mediumCount: number
  lowCount: number
}

interface DriftDetailClientProps {
  projectId: string
  projectName: string
  latestAssessment: AssessmentSummary | null
  findings: DriftFinding[]
  docBreakdown: DocSummary[]
  noContextBuild: boolean
  hasBaselineSha: boolean
}

// ── Poll timing ───────────────────────────────────────────────────────────────

const POLL_INTERVAL_MS = 3_000
const POLL_TIMEOUT_MS = 120_000

// ── Component ─────────────────────────────────────────────────────────────────

export function DriftDetailClient({
  projectId,
  projectName,
  latestAssessment: initialAssessment,
  findings: initialFindings,
  docBreakdown: initialDocBreakdown,
  noContextBuild,
  hasBaselineSha,
}: DriftDetailClientProps) {
  const [assessment, setAssessment] = useState<AssessmentSummary | null>(initialAssessment)
  const [findings, setFindings] = useState<DriftFinding[]>(initialFindings)
  const [docBreakdown, setDocBreakdown] = useState<DocSummary[]>(initialDocBreakdown)
  const [running, setRunning] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [expandedFindings, setExpandedFindings] = useState<Set<number>>(new Set())
  const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null)
  const pollStart = useRef<number>(0)

  const stopPolling = useCallback(() => {
    if (pollTimer.current) {
      clearInterval(pollTimer.current)
      pollTimer.current = null
    }
  }, [])

  const pollAssessment = useCallback(
    async (assessmentId: string) => {
      if (Date.now() - pollStart.current > POLL_TIMEOUT_MS) {
        stopPolling()
        setRunning(false)
        setError("Assessment timed out after 2 minutes. Check back later.")
        return
      }

      try {
        const res = await fetch(`/api/projects/${projectId}/drift/${assessmentId}`)
        if (!res.ok) {
          stopPolling()
          setRunning(false)
          return
        }
        const data = (await res.json()) as { assessment: AssessmentSummary & { findings?: DriftFinding[] } }
        const a = data.assessment
        setAssessment(a)

        if (a.status === "COMPLETE") {
          stopPolling()
          setRunning(false)
          const newFindings = (a.findings as DriftFinding[] | undefined) ?? []
          setFindings(newFindings)
          setDocBreakdown(buildDocBreakdown(newFindings))
        } else if (a.status === "ERROR") {
          stopPolling()
          setRunning(false)
          setError(a.error ?? "Assessment failed")
        }
      } catch {
        // network error — keep polling
      }
    },
    [projectId, stopPolling]
  )

  useEffect(() => {
    return () => stopPolling()
  }, [stopPolling])

  const handleRun = async () => {
    setRunning(true)
    setError(null)

    try {
      const res = await fetch(`/api/projects/${projectId}/drift`, { method: "POST" })
      const body = (await res.json()) as {
        assessmentId?: string
        reason?: string
        retryAfterMs?: number
        status?: string
      }

      if (res.status === 202 && body.assessmentId) {
        setAssessment({ id: body.assessmentId, status: "PENDING", riskLevel: null, findingsCount: 0, contextSource: "unknown", diffTruncated: false, error: null })
        pollStart.current = Date.now()
        pollTimer.current = setInterval(() => {
          void pollAssessment(body.assessmentId!)
        }, POLL_INTERVAL_MS)
        return
      }

      if (res.status === 200 && body.reason === "no_changes") {
        setRunning(false)
        setError("No changes detected since last assessment — context is up to date.")
        return
      }

      if (res.status === 429) {
        setRunning(false)
        const mins = body.retryAfterMs ? Math.ceil(body.retryAfterMs / 60_000) : 30
        setError(`Rate limited — try again in ${mins} minute${mins !== 1 ? "s" : ""}.`)
        return
      }

      setRunning(false)
      setError(body.reason ?? "Failed to trigger assessment")
    } catch {
      setRunning(false)
      setError("Network error — could not start assessment")
    }
  }

  const handleMarkRefreshed = async () => {
    await fetch(`/api/projects/${projectId}/drift/baseline`, { method: "POST" })
  }

  const toggleFinding = (i: number) => {
    setExpandedFindings((prev) => {
      const next = new Set(prev)
      if (next.has(i)) next.delete(i)
      else next.add(i)
      return next
    })
  }

  const isInFlight = assessment?.status === "PENDING" || assessment?.status === "RUNNING" || running
  const riskLevel = assessment?.status === "COMPLETE" ? (assessment.riskLevel ?? "GREEN") : null

  return (
    <div>
      {/* Header row */}
      <div
        style={{
          display: "flex",
          alignItems: "flex-start",
          justifyContent: "space-between",
          flexWrap: "wrap",
          gap: "0.75rem",
          marginBottom: "1.25rem",
        }}
      >
        <div>
          <h1
            style={{
              fontSize: "1.3rem",
              fontWeight: 800,
              color: "var(--text-primary)",
              margin: 0,
              letterSpacing: "-0.025em",
            }}
          >
            {projectName}
          </h1>
          <div style={{ marginTop: "0.35rem", display: "flex", alignItems: "center", gap: "0.5rem" }}>
            {riskLevel && <RiskBadgeLarge riskLevel={riskLevel} findingsCount={findings.length} />}
            {assessment?.contextSource && assessment.contextSource !== "unknown" && (
              <span style={{ fontSize: "11.5px", color: "var(--text-muted)" }}>
                Source: {assessment.contextSource === "context_builds" ? "context_builds branch" : assessment.contextSource === "docs_on_default" ? "/docs on main" : "none"}
              </span>
            )}
            {assessment?.diffTruncated && (
              <span style={{ fontSize: "11px", color: "#f59e0b" }}>· diff truncated (300+ files)</span>
            )}
          </div>
        </div>

        <div style={{ display: "flex", gap: "0.5rem", flexWrap: "wrap" }}>
          <button
            onClick={() => void handleRun()}
            disabled={isInFlight || !hasBaselineSha}
            title={!hasBaselineSha ? "No baseline SHA — run an initial assessment first to capture the baseline" : undefined}
            style={{
              background: isInFlight ? "var(--surface-subtle)" : "#0ee29e",
              color: isInFlight ? "var(--text-muted)" : "#0a1a12",
              border: "none",
              borderRadius: 7,
              padding: "0.45rem 1rem",
              fontSize: "12.5px",
              fontWeight: 700,
              cursor: isInFlight || !hasBaselineSha ? "not-allowed" : "pointer",
              transition: "background 0.15s",
              opacity: !hasBaselineSha ? 0.5 : 1,
            }}
          >
            {isInFlight ? statusLabel(assessment?.status ?? "PENDING") : "Run assessment"}
          </button>

          <button
            onClick={() => void handleMarkRefreshed()}
            style={{
              background: "var(--surface-subtle)",
              color: "var(--text-secondary)",
              border: "1px solid var(--border)",
              borderRadius: 7,
              padding: "0.45rem 0.85rem",
              fontSize: "12px",
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Mark context refreshed
          </button>
        </div>
      </div>

      {/* Error banner */}
      {error && (
        <div
          style={{
            background: "rgba(248,113,113,0.08)",
            border: "1px solid rgba(248,113,113,0.2)",
            borderRadius: 8,
            padding: "0.65rem 1rem",
            fontSize: "12.5px",
            color: "#f87171",
            marginBottom: "1rem",
          }}
        >
          {error}
        </div>
      )}

      {/* No-context-build state (G6) */}
      {noContextBuild && (
        <NoContextBuildState />
      )}

      {/* Marlin escalation (AC8) */}
      {riskLevel === "RED" && !noContextBuild && (
        <MarlinEscalation findings={findings} />
      )}

      {/* Per-doc breakdown */}
      {docBreakdown.length > 0 && (
        <DocBreakdown docBreakdown={docBreakdown} />
      )}

      {/* Findings list */}
      {findings.length > 0 && (
        <FindingsList
          findings={findings}
          expandedFindings={expandedFindings}
          onToggle={toggleFinding}
        />
      )}

      {/* Empty state — COMPLETE + zero findings */}
      {assessment?.status === "COMPLETE" && findings.length === 0 && !noContextBuild && (
        <div
          style={{
            background: "rgba(14,226,158,0.04)",
            border: "1px solid rgba(14,226,158,0.15)",
            borderRadius: 10,
            padding: "1.5rem",
            textAlign: "center",
          }}
        >
          <div style={{ fontSize: "1.5rem", marginBottom: "0.4rem" }}>✓</div>
          <div style={{ fontSize: "13px", fontWeight: 600, color: "#0ee29e" }}>
            No drift detected
          </div>
          <div style={{ fontSize: "12px", color: "var(--text-muted)", marginTop: "0.3rem" }}>
            All context docs match the current code.
          </div>
        </div>
      )}

      {/* No assessment yet */}
      {!assessment && !noContextBuild && (
        <div
          style={{
            background: "var(--surface-card)",
            border: "1px solid var(--border)",
            borderRadius: 10,
            padding: "1.5rem",
            textAlign: "center",
            color: "var(--text-muted)",
            fontSize: "13px",
          }}
        >
          No assessment yet. Click <strong>Run assessment</strong> to check for context drift.
        </div>
      )}
    </div>
  )
}

// ── Sub-components ────────────────────────────────────────────────────────────

function statusLabel(status: string): string {
  if (status === "PENDING") return "Queued…"
  if (status === "RUNNING") return "Analysing…"
  return "Running…"
}

function RiskBadgeLarge({ riskLevel, findingsCount }: { riskLevel: string; findingsCount: number }) {
  const config: Record<string, { color: string; bg: string; border: string; label: string }> = {
    GREEN: { color: "#0ee29e", bg: "rgba(14,226,158,0.08)", border: "rgba(14,226,158,0.2)", label: "GREEN" },
    AMBER: { color: "#f59e0b", bg: "rgba(245,158,11,0.08)", border: "rgba(245,158,11,0.2)", label: "AMBER" },
    RED: { color: "#f87171", bg: "rgba(248,113,113,0.08)", border: "rgba(248,113,113,0.2)", label: "RED" },
  }
  const c = config[riskLevel] ?? config["GREEN"]!
  return (
    <span
      style={{
        fontSize: "12px",
        fontWeight: 700,
        color: c.color,
        background: c.bg,
        border: `1px solid ${c.border}`,
        borderRadius: 20,
        padding: "3px 12px",
        letterSpacing: "0.04em",
        display: "inline-flex",
        alignItems: "center",
        gap: "5px",
      }}
    >
      <span style={{ width: 7, height: 7, borderRadius: "50%", background: c.color, display: "inline-block" }} />
      {c.label}
      {findingsCount > 0 && ` · ${findingsCount} finding${findingsCount !== 1 ? "s" : ""}`}
    </span>
  )
}

function NoContextBuildState() {
  return (
    <div
      style={{
        background: "var(--surface-card)",
        border: "1px solid var(--border)",
        borderRadius: 10,
        padding: "1.5rem",
        marginBottom: "1rem",
      }}
    >
      <div style={{ fontSize: "13.5px", fontWeight: 700, color: "var(--text-primary)", marginBottom: "0.5rem" }}>
        No context build found
      </div>
      <p style={{ fontSize: "12.5px", color: "var(--text-muted)", margin: 0, lineHeight: 1.6 }}>
        Drift monitoring requires context documentation — either a <code style={{ fontFamily: "var(--font-mono)", fontSize: "11.5px", background: "var(--surface-subtle)", padding: "1px 5px", borderRadius: 4 }}>context_builds</code> branch (generated by Marlin) or a <code style={{ fontFamily: "var(--font-mono)", fontSize: "11.5px", background: "var(--surface-subtle)", padding: "1px 5px", borderRadius: 4 }}>/docs</code> folder on the default branch. Without context documentation, AI agents working on this project have no map of the system. This is a significant risk — any AI-assisted work builds on an undocumented understanding of the codebase.
      </p>
      <p style={{ fontSize: "12.5px", color: "var(--text-muted)", margin: "0.75rem 0 0", lineHeight: 1.6 }}>
        Run a <strong>Marlin context build</strong> to generate documentation and enable drift monitoring.
      </p>
    </div>
  )
}

function MarlinEscalation({ findings }: { findings: DriftFinding[] }) {
  const areas = [...new Set(findings.filter((f) => f.severity === "HIGH" || f.severity === "MEDIUM").map((f) => f.area))].slice(0, 4)
  return (
    <div
      style={{
        background: "rgba(248,113,113,0.06)",
        border: "1px solid rgba(248,113,113,0.25)",
        borderRadius: 10,
        padding: "1rem 1.1rem",
        marginBottom: "1.25rem",
      }}
    >
      <div style={{ fontSize: "13px", fontWeight: 700, color: "#f87171", marginBottom: "0.4rem" }}>
        Context drift detected — rerun your context build
      </div>
      <p style={{ fontSize: "12.5px", color: "var(--text-muted)", margin: 0, lineHeight: 1.6 }}>
        Significant drift was found in: <strong style={{ color: "var(--text-secondary)" }}>{areas.join(", ") || "multiple areas"}</strong>. Before continuing AI-assisted development on this project, rerun your context build with <strong>Marlin</strong> so agents work from an accurate map of the system.
      </p>
    </div>
  )
}

function DocBreakdown({ docBreakdown }: { docBreakdown: DocSummary[] }) {
  return (
    <div style={{ marginBottom: "1.25rem" }}>
      <div style={{ fontSize: "12px", fontWeight: 700, color: "var(--text-muted)", textTransform: "uppercase", letterSpacing: "0.07em", marginBottom: "0.5rem" }}>
        Per-doc breakdown
      </div>
      <div style={{ display: "flex", flexWrap: "wrap", gap: "0.5rem" }}>
        {docBreakdown.map(({ doc, highCount, mediumCount, lowCount }) => (
          <div
            key={doc}
            style={{
              background: "var(--surface-card)",
              border: highCount > 0 ? "1px solid rgba(248,113,113,0.3)" : "1px solid var(--border)",
              borderRadius: 8,
              padding: "0.5rem 0.75rem",
              fontSize: "12px",
              minWidth: 160,
            }}
          >
            <div style={{ fontWeight: 600, color: "var(--text-primary)", marginBottom: "0.25rem", fontFamily: "var(--font-mono, monospace)", fontSize: "11.5px" }}>
              {doc}
            </div>
            <div style={{ display: "flex", gap: "0.4rem" }}>
              {highCount > 0 && <SeverityChip count={highCount} severity="HIGH" />}
              {mediumCount > 0 && <SeverityChip count={mediumCount} severity="MEDIUM" />}
              {lowCount > 0 && <SeverityChip count={lowCount} severity="LOW" />}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

interface DocSummaryInterface {
  doc: string
  findings: DriftFinding[]
  highCount: number
  mediumCount: number
  lowCount: number
}

function SeverityChip({ count, severity }: { count: number; severity: "HIGH" | "MEDIUM" | "LOW" }) {
  const color = severity === "HIGH" ? "#f87171" : severity === "MEDIUM" ? "#f59e0b" : "var(--text-muted)"
  return (
    <span style={{ fontSize: "10.5px", fontWeight: 600, color }}>
      {count} {severity.charAt(0) + severity.slice(1).toLowerCase()}
    </span>
  )
}

function FindingsList({
  findings,
  expandedFindings,
  onToggle,
}: {
  findings: DriftFinding[]
  expandedFindings: Set<number>
  onToggle: (i: number) => void
}) {
  return (
    <div>
      <div style={{ fontSize: "12px", fontWeight: 700, color: "var(--text-muted)", textTransform: "uppercase", letterSpacing: "0.07em", marginBottom: "0.6rem" }}>
        Findings ({findings.length})
      </div>
      <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem" }}>
        {findings.map((f, i) => (
          <FindingCard
            key={i}
            finding={f}
            index={i}
            expanded={expandedFindings.has(i)}
            onToggle={onToggle}
          />
        ))}
      </div>
    </div>
  )
}

function FindingCard({
  finding,
  index,
  expanded,
  onToggle,
}: {
  finding: DriftFinding
  index: number
  expanded: boolean
  onToggle: (i: number) => void
}) {
  const severityColor =
    finding.severity === "HIGH" ? "#f87171" : finding.severity === "MEDIUM" ? "#f59e0b" : "var(--text-muted)"
  const typeColor = finding.type === "ACCURACY" ? "#8b93d4" : "#0ee29e"

  return (
    <div
      style={{
        background: "var(--surface-card)",
        border: `1px solid ${finding.severity === "HIGH" ? "rgba(248,113,113,0.2)" : "var(--border)"}`,
        borderRadius: 9,
        overflow: "hidden",
      }}
    >
      <button
        onClick={() => onToggle(index)}
        style={{
          width: "100%",
          background: "transparent",
          border: "none",
          cursor: "pointer",
          padding: "0.75rem 1rem",
          display: "flex",
          alignItems: "flex-start",
          gap: "0.75rem",
          textAlign: "left",
        }}
      >
        {/* Type badge */}
        <span
          style={{
            fontSize: "10px",
            fontWeight: 700,
            color: typeColor,
            background: `${typeColor}14`,
            border: `1px solid ${typeColor}33`,
            borderRadius: 5,
            padding: "2px 6px",
            letterSpacing: "0.05em",
            flexShrink: 0,
            marginTop: "1px",
          }}
        >
          {finding.type}
        </span>

        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: "flex", alignItems: "center", gap: "0.5rem", flexWrap: "wrap" }}>
            <span style={{ fontSize: "13px", fontWeight: 600, color: "var(--text-primary)" }}>
              {finding.area}
            </span>
            <span
              style={{
                fontSize: "10.5px",
                fontWeight: 600,
                color: severityColor,
                textTransform: "uppercase",
                letterSpacing: "0.05em",
              }}
            >
              {finding.severity}
            </span>
          </div>
          <div style={{ fontSize: "12px", color: "var(--text-muted)", marginTop: "2px", fontFamily: "var(--font-mono, monospace)" }}>
            {finding.doc}
          </div>
          <div style={{ fontSize: "12.5px", color: "var(--text-secondary)", marginTop: "4px", lineHeight: 1.5 }}>
            {finding.description}
          </div>
        </div>

        <span style={{ color: "var(--text-muted)", fontSize: "10px", flexShrink: 0, marginTop: "4px" }}>
          {expanded ? "▲" : "▼"}
        </span>
      </button>

      {expanded && (finding.evidenceDocExcerpt || finding.evidenceCodeExcerpt) && (
        <div
          style={{
            borderTop: "1px solid var(--border)",
            padding: "0.75rem 1rem",
            display: "flex",
            flexDirection: "column",
            gap: "0.6rem",
          }}
        >
          {finding.evidenceDocExcerpt && (
            <EvidenceBlock label="Doc claim" content={finding.evidenceDocExcerpt} color="#8b93d4" />
          )}
          {finding.evidenceCodeExcerpt && (
            <EvidenceBlock label="Code evidence" content={finding.evidenceCodeExcerpt} color="#0ee29e" />
          )}
        </div>
      )}
    </div>
  )
}

function EvidenceBlock({ label, content, color }: { label: string; content: string; color: string }) {
  return (
    <div>
      <div style={{ fontSize: "10.5px", fontWeight: 700, color, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: "4px" }}>
        {label}
      </div>
      <pre
        style={{
          background: "var(--surface-subtle)",
          border: "1px solid var(--border)",
          borderRadius: 6,
          padding: "0.5rem 0.75rem",
          fontSize: "11.5px",
          fontFamily: "var(--font-mono, monospace)",
          color: "var(--text-secondary)",
          margin: 0,
          overflowX: "auto",
          whiteSpace: "pre-wrap",
          wordBreak: "break-word",
          lineHeight: 1.55,
        }}
      >
        {content}
      </pre>
    </div>
  )
}

// ── Helpers (mirrors server-side buildDocBreakdown) ──────────────────────────

function buildDocBreakdown(findings: DriftFinding[]): DocSummaryInterface[] {
  const map = new Map<string, DriftFinding[]>()
  for (const f of findings) {
    const existing = map.get(f.doc) ?? []
    existing.push(f)
    map.set(f.doc, existing)
  }
  return Array.from(map.entries()).map(([doc, fs]) => ({
    doc,
    findings: fs,
    highCount: fs.filter((f) => f.severity === "HIGH").length,
    mediumCount: fs.filter((f) => f.severity === "MEDIUM").length,
    lowCount: fs.filter((f) => f.severity === "LOW").length,
  }))
}
```

- [ ] **Step 2: Run tsc**

```
npx tsc --noEmit 2>&1 | grep -v "src/tests"
```

Expected: 0 errors in src/.

- [ ] **Step 3: Run full test suite**

```
npm test
```

- [ ] **Step 4: Commit**

```
git add src/app/context-analyser/[id]/_components/DriftDetailClient.tsx
git commit -m "feat(drift): DriftDetailClient — Run button, polling, findings list, states — Phase 5 Ref-5xx"
```

---

## Self-Review Checklist

1. **AC7** (UI reflects PENDING→RUNNING→COMPLETE): polling in `DriftDetailClient` ✓
2. **AC8** (Marlin escalation on RED): `MarlinEscalation` shown when `riskLevel === "RED"` ✓
3. **AC10** (RBAC + org-scoped): all routes use strict `{ id, organisationId }` project lookup; MEMBER → 403; LM cross-team → 403 ✓
4. **G6** (no-context state): `NoContextBuildState` shown when `contextSource === "none"` or no GitHub link ✓
5. **No 0–100 score**: only GREEN/AMBER/RED + finding count ✓
6. **Triple-scoped assessment read**: `{ id, projectId, organisationId }` ✓
7. **`resetStaleRunningAssessments` called on GET**: ✓ (Phase 4 built function; Phase 5 invokes it)
8. **Per-doc breakdown**: `buildDocBreakdown` + `DocBreakdown` component ✓
9. **Evidence excerpts**: collapsible `EvidenceBlock` in each `FindingCard` ✓
10. **Strict export-route pattern** (no leak): uses `{ id: params.id, organisationId: ctx.organisationId }` — NOT the leaky exec-summary pattern that omits `organisationId` ✓
