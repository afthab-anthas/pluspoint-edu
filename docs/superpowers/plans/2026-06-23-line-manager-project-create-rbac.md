# Phase R — LINE_MANAGER Project-Creation RBAC Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow LINE_MANAGERs to create projects on their own team (scope-verified against the Membership row), eliminate the silent-fail inconsistency, and prove the boundary with 5 forge tests.

**Architecture:** Two guards in `POST /api/projects`: (1) an early MEMBER-only 403 replacing the blanket non-MANAGER 403; (2) a scoped check after body parsing that returns 403 if a LINE_MANAGER's requested `teamId` differs from `ctx.teamId` (which comes from the Membership DB row via `withAuthScoped`, not the JWT). The UI button in `TeamDetailClient` is tightened with an explicit `callerTeamId` prop to remove reliance on the page-level redirect for correctness.

**Tech Stack:** Next.js 14 App Router · TypeScript 5 · Vitest · Prisma (mocked in tests)

---

> **STOP POINT**: After Task 1 is complete and green, STOP and wait for user review before proceeding to Task 2.

---

## File Map

| Action | File | What changes |
|--------|------|--------------|
| Modify | `src/app/api/projects/route.ts` | Replace blanket `role !== "MANAGER"` guard with MEMBER-early + LM-scoped two-step check |
| Create | `src/tests/pr-projects-create-rbac.test.ts` | 5 forge tests proving all RBAC boundaries |
| Modify | `src/app/teams/[id]/_components/TeamDetailClient.tsx` | Add `callerTeamId` prop; tighten button condition in `ProjectsTab` (both occurrences) |
| Modify | `src/app/teams/[id]/page.tsx` | Pass `callerTeamId={ctx.teamId}` to `TeamDetailClient` |
| Modify | `docs/decisions.md` | Add `ADR-phase-r` |
| Modify | `docs/architecture.md` | Add `POST /api/projects` row to API table; update RBAC capabilities row for LINE_MANAGER |

---

## Task 1: API Guard + Forge Tests

**Files:**
- Modify: `src/app/api/projects/route.ts` lines 28–30 and after line 55
- Create: `src/tests/pr-projects-create-rbac.test.ts`

---

- [ ] **Step 1: Write the forge test file (all 5 cases)**

Create `src/tests/pr-projects-create-rbac.test.ts` with this exact content:

```typescript
// src/tests/pr-projects-create-rbac.test.ts
// Phase R forge tests — LINE_MANAGER scoped project creation.
//
// PROOF CONTRACT:
//   Disable the early MEMBER check and forge-4 passes for the wrong reason.
//   Revert to "role !== MANAGER → 403" and forge-1 fails (LM can't create own team).
//   Remove the LM scoped check and forge-2 fails (LM reaches DB on other team).
//   Both new guards are required.

import { describe, it, expect, vi, beforeEach, beforeAll } from "vitest"

vi.mock("@/lib/db", () => ({
  db: {
    team: { findFirst: vi.fn() },
    project: { create: vi.fn() },
  },
}))

vi.mock("@/lib/withAuthScoped", () => ({
  withAuthScoped: vi.fn(),
}))

vi.mock("@/lib/token", () => ({
  generateRawToken: vi.fn().mockReturnValue("raw-test-token-32bytes12345678"),
  hashToken: vi.fn().mockResolvedValue("$2b$12$hashed"),
  tokenPreview: vi.fn().mockReturnValue("8678"),
}))

vi.mock("@/lib/audit", () => ({
  writeAudit: vi.fn().mockResolvedValue(undefined),
  getIp: vi.fn().mockReturnValue("1.2.3.4"),
}))

vi.mock("@/lib/repo-context", () => ({
  linkRepoAndRefresh: vi.fn().mockResolvedValue(undefined),
}))

import { POST } from "@/app/api/projects/route"
import { db } from "@/lib/db"
import { withAuthScoped } from "@/lib/withAuthScoped"
import type { AuthContext } from "@/lib/withAuthScoped"

const mockAuth = vi.mocked(withAuthScoped)
const mockDb = vi.mocked(db)

const MANAGER_CTX: AuthContext = {
  userId: "u-manager",
  role: "MANAGER",
  teamId: null,
  isLineManager: false,
  organisationId: "org-A",
  scope: { allTeams: true, canSeeTimeData: true, viewedTeamIds: [] },
}

const LM_TEAM1_CTX: AuthContext = {
  userId: "u-lm",
  role: "LINE_MANAGER",
  teamId: "team-1",
  isLineManager: true,
  organisationId: "org-A",
  scope: { allTeams: false, canSeeTimeData: true, viewedTeamIds: ["team-1"] },
}

const MEMBER_CTX: AuthContext = {
  userId: "u-member",
  role: "MEMBER",
  teamId: "team-1",
  isLineManager: false,
  organisationId: "org-A",
  scope: { allTeams: false, canSeeTimeData: false, viewedTeamIds: ["team-1"] },
}

const TEAM_1_ROW = {
  id: "team-1",
  name: "Engineering",
  organisationId: "org-A",
  description: null,
  timeTrackingEnabled: false,
  aiUsesTime: false,
  tenantKey: null,
  createdAt: new Date(),
  updatedAt: new Date(),
}

const NEW_PROJECT = {
  id: "proj-new",
  name: "Alpha",
  teamId: "team-1",
  organisationId: "org-A",
  description: null,
  clientName: null,
  status: "ACTIVE" as const,
  agentTokenHash: "$2b$12$hashed",
  agentTokenPreview: "8678",
  tokenLastRotated: new Date(),
  githubRepoFullName: null,
  githubInstallationId: null,
  tenantKey: null,
  createdAt: new Date(),
  updatedAt: new Date(),
}

beforeAll(() => {
  process.env.NEXTAUTH_SECRET = "test-secret-for-phase-r"
})

function makePostRequest(body: Record<string, unknown>) {
  return new Request("http://localhost:3000/api/projects", {
    method: "POST",
    headers: { "Content-Type": "application/json", "x-forwarded-for": "1.2.3.4" },
    body: JSON.stringify(body),
  })
}

describe("Phase R forge — POST /api/projects RBAC", () => {
  beforeEach(() => vi.clearAllMocks())

  // forge-1: LINE_MANAGER creates on OWN team → 201
  // Proof: old guard "role !== MANAGER → 403" makes this test fail.
  it("forge-1: LINE_MANAGER on own team gets 201", async () => {
    mockAuth.mockResolvedValueOnce(LM_TEAM1_CTX)
    mockDb.team.findFirst.mockResolvedValueOnce(TEAM_1_ROW as never)
    mockDb.project.create.mockResolvedValueOnce(NEW_PROJECT as never)

    const res = await POST(makePostRequest({ name: "Alpha", teamId: "team-1" }))

    expect(res.status).toBe(201)
    const body = await res.json()
    expect(body.id).toBe("proj-new")
    // Guard allowed through: project.create was called.
    expect(mockDb.project.create).toHaveBeenCalledOnce()
  })

  // forge-2: LINE_MANAGER creates on ANOTHER team → 403, DB never queried
  // Proof: remove the LM scoped check and db.team.findFirst IS called (test fails on the
  // not.toHaveBeenCalled assertion), revealing the boundary was open.
  it("forge-2: LINE_MANAGER on other team gets 403 — DB never queried", async () => {
    mockAuth.mockResolvedValueOnce(LM_TEAM1_CTX) // teamId = "team-1"

    const res = await POST(makePostRequest({ name: "Alpha", teamId: "team-2" }))

    expect(res.status).toBe(403)
    const body = await res.json()
    expect(body.reason).toBe("forbidden")
    // Scoped guard fired before any DB call.
    expect(mockDb.team.findFirst).not.toHaveBeenCalled()
    expect(mockDb.project.create).not.toHaveBeenCalled()
  })

  // forge-3: MANAGER creates on any team → 201 (pre-existing behaviour unchanged)
  it("forge-3: MANAGER creates on any team and gets 201", async () => {
    mockAuth.mockResolvedValueOnce(MANAGER_CTX)
    mockDb.team.findFirst.mockResolvedValueOnce(
      { ...TEAM_1_ROW, id: "team-2", name: "Nexus" } as never
    )
    mockDb.project.create.mockResolvedValueOnce(
      { ...NEW_PROJECT, teamId: "team-2" } as never
    )

    const res = await POST(makePostRequest({ name: "Nexus Alpha", teamId: "team-2" }))

    expect(res.status).toBe(201)
    expect(mockDb.project.create).toHaveBeenCalledOnce()
  })

  // forge-4: MEMBER → 403, DB never queried (pre-existing behaviour unchanged)
  it("forge-4: MEMBER gets 403 — DB never queried", async () => {
    mockAuth.mockResolvedValueOnce(MEMBER_CTX)

    const res = await POST(makePostRequest({ name: "Alpha", teamId: "team-1" }))

    expect(res.status).toBe(403)
    const body = await res.json()
    expect(body.reason).toBe("forbidden")
    expect(mockDb.team.findFirst).not.toHaveBeenCalled()
  })

  // forge-5: LM passes own-team check but team not in their org → 422
  // Proves the existing DB-level org tenancy check is intact under the new guard.
  it("forge-5: LM own teamId that resolves null in their org gets 422 (tenancy intact)", async () => {
    const LM_FOREIGN_TEAM: AuthContext = {
      ...LM_TEAM1_CTX,
      teamId: "team-foreign",
    }
    mockAuth.mockResolvedValueOnce(LM_FOREIGN_TEAM)
    // team-foreign passes the own-team check but does not belong to org-A.
    mockDb.team.findFirst.mockResolvedValueOnce(null)

    const res = await POST(makePostRequest({ name: "Alpha", teamId: "team-foreign" }))

    expect(res.status).toBe(422)
    const body = await res.json()
    expect(body.reason).toBe("team_not_found")
    // Own-team check passed (teamIds matched) → DB was reached → org guard fired.
    expect(mockDb.team.findFirst).toHaveBeenCalledWith({
      where: { id: "team-foreign", organisationId: "org-A" },
    })
    expect(mockDb.project.create).not.toHaveBeenCalled()
  })
})
```

---

- [ ] **Step 2: Run the tests to confirm they fail for the right reasons**

```bash
npx vitest run src/tests/pr-projects-create-rbac.test.ts
```

Expected output (before the fix):
- `forge-1` — FAIL (expects 201, gets 403 — old blanket guard)
- `forge-2` — PASS (gets 403, but for the old blanket reason — the `not.toHaveBeenCalled` assertions pass because the blanket guard fires first)
- `forge-3` — FAIL (expects 201, gets 403 — blanket guard blocks MANAGER too? No — MANAGER passes the old `role !== "MANAGER"` check. So forge-3 should PASS with the old code.)
- `forge-4` — PASS (gets 403)
- `forge-5` — FAIL (never reaches DB since old guard fires for LM; `team.findFirst` not called; test expects it to be called)

Minimum expected failures: **forge-1** and **forge-5** must fail. If any other test fails unexpectedly, read the error before continuing.

---

- [ ] **Step 3: Apply the minimal API guard change**

Open `src/app/api/projects/route.ts`.

**Replace lines 28–30** (the current blanket check):

```typescript
  if (ctx.role !== "MANAGER") {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }
```

**With** (MEMBER-only early check):

```typescript
  if (ctx.role === "MEMBER") {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }
```

**Then insert** the LINE_MANAGER scoped check immediately after the `teamId` validation block (after line 55, which reads `return NextResponse.json({ reason: "team_id_required" }, { status: 422 })`).

The block to insert goes between the `teamId` validation and the `db.team.findFirst` call:

```typescript
  // LINE_MANAGER scoped: only their Membership team (ctx.teamId is DB-verified, not JWT).
  if (ctx.role === "LINE_MANAGER" && ctx.teamId !== teamId) {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }
```

After the change, the POST handler's top section should look like this in full (lines 25–62 of the modified file):

```typescript
export async function POST(request: Request) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role === "MEMBER") {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ error: "Invalid JSON" }, { status: 400 })
  }

  const {
    name,
    teamId,
    description,
    clientName,
    status,
    githubRepoFullName,
    githubInstallationId,
    generateSnapshotNow,
  } = body as Record<string, unknown>

  if (!name || typeof name !== "string" || !name.trim()) {
    return NextResponse.json({ reason: "name_required" }, { status: 422 })
  }
  if (!teamId || typeof teamId !== "string" || !teamId.trim()) {
    return NextResponse.json({ reason: "team_id_required" }, { status: 422 })
  }

  // LINE_MANAGER scoped: only their Membership team (ctx.teamId is DB-verified, not JWT).
  if (ctx.role === "LINE_MANAGER" && ctx.teamId !== teamId) {
    return NextResponse.json({ reason: "forbidden" }, { status: 403 })
  }

  const team = await db.team.findFirst({ where: { id: teamId, organisationId: ctx.organisationId } })
  if (!team) {
    return NextResponse.json({ reason: "team_not_found" }, { status: 422 })
  }
  // ... rest of handler unchanged
```

---

- [ ] **Step 4: Run the forge tests to confirm all 5 pass**

```bash
npx vitest run src/tests/pr-projects-create-rbac.test.ts
```

Expected: **5/5 PASS**. If any test fails, read the error — do not proceed until all 5 are green.

---

- [ ] **Step 5: Run the full test suite**

```bash
npm test
```

Expected: all pre-existing tests still pass. Zero new failures. If existing tests fail, investigate before proceeding.

---

- [ ] **Step 6: Commit Task 1**

Stage and commit:

```bash
git add src/app/api/projects/route.ts src/tests/pr-projects-create-rbac.test.ts
git commit -m "fix(projects): allow LINE_MANAGER to create projects on own team (scoped RBAC)"
```

---

> **STOP — await user review before Task 2.**

---

## Task 2: UI Alignment + Docs

**Files:**
- Modify: `src/app/teams/[id]/_components/TeamDetailClient.tsx`
- Modify: `src/app/teams/[id]/page.tsx`
- Modify: `docs/decisions.md`
- Modify: `docs/architecture.md`

---

- [ ] **Step 1: Add `callerTeamId` to `TeamDetailClientProps` and pass it through `ProjectsTab`**

Open `src/app/teams/[id]/_components/TeamDetailClient.tsx`.

**1a. Update the `TeamDetailClientProps` type** (around line 27). Add `callerTeamId: string | null` as the last field before the closing brace:

Find:
```typescript
type TeamDetailClientProps = {
  teamId: string
  teamName: string
  teamDescription: string | null
  timeTrackingEnabled: boolean
  aiUsesTime: boolean
  members: Member[]
  projects: Project[]
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  userId: string
  githubInstallations: { installationId: string; accountName: string }[]
}
```

Replace with:
```typescript
type TeamDetailClientProps = {
  teamId: string
  teamName: string
  teamDescription: string | null
  timeTrackingEnabled: boolean
  aiUsesTime: boolean
  members: Member[]
  projects: Project[]
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  userId: string
  callerTeamId: string | null
  githubInstallations: { installationId: string; accountName: string }[]
}
```

**1b. Update `ProjectsTab`'s props type** (around line 409). Add `callerTeamId` to the inline props type:

Find:
```typescript
function ProjectsTab({
  projects,
  teamId,
  role,
  githubInstallations,
}: {
  projects: Project[]
  teamId: string
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  githubInstallations: { installationId: string; accountName: string }[]
}) {
```

Replace with:
```typescript
function ProjectsTab({
  projects,
  teamId,
  role,
  callerTeamId,
  githubInstallations,
}: {
  projects: Project[]
  teamId: string
  role: "MANAGER" | "LINE_MANAGER" | "MEMBER"
  callerTeamId: string | null
  githubInstallations: { installationId: string; accountName: string }[]
}) {
```

**1c. Add the `canCreateProject` constant** inside `ProjectsTab`, immediately after the `filteredProjects` line (around line 423):

Find:
```typescript
  const filteredProjects = projectSearch.trim()
    ? projects.filter((p) => p.name.toLowerCase().includes(projectSearch.toLowerCase()))
    : projects
```

Replace with:
```typescript
  const filteredProjects = projectSearch.trim()
    ? projects.filter((p) => p.name.toLowerCase().includes(projectSearch.toLowerCase()))
    : projects
  const canCreateProject = role === "MANAGER" || (role === "LINE_MANAGER" && callerTeamId === teamId)
```

**1d. Update the EmptyState action** (around line 429):

Find:
```typescript
        action={role !== "MEMBER" ? <NewProjectDialog teamId={teamId} githubInstallations={githubInstallations} /> : undefined}
```

Replace with:
```typescript
        action={canCreateProject ? <NewProjectDialog teamId={teamId} githubInstallations={githubInstallations} /> : undefined}
```

**1e. Update the header button** (around line 437):

Find:
```typescript
        {role !== "MEMBER" && <NewProjectDialog teamId={teamId} githubInstallations={githubInstallations} />}
```

Replace with:
```typescript
        {canCreateProject && <NewProjectDialog teamId={teamId} githubInstallations={githubInstallations} />}
```

**1f. Update `TeamDetailClient` function signature** (around line 706). Add `callerTeamId` to the destructured props:

Find:
```typescript
export function TeamDetailClient({
  teamId,
  teamName,
  teamDescription,
  timeTrackingEnabled,
  aiUsesTime,
  members,
  projects,
  role,
  githubInstallations,
}: TeamDetailClientProps) {
```

Replace with:
```typescript
export function TeamDetailClient({
  teamId,
  teamName,
  teamDescription,
  timeTrackingEnabled,
  aiUsesTime,
  members,
  projects,
  role,
  callerTeamId,
  githubInstallations,
}: TeamDetailClientProps) {
```

**1g. Pass `callerTeamId` to `ProjectsTab`** (around line 846):

Find:
```typescript
        {activeTab === "projects" && (
          <ProjectsTab
            projects={projects}
            teamId={teamId}
            role={role}
            githubInstallations={githubInstallations}
          />
        )}
```

Replace with:
```typescript
        {activeTab === "projects" && (
          <ProjectsTab
            projects={projects}
            teamId={teamId}
            role={role}
            callerTeamId={callerTeamId}
            githubInstallations={githubInstallations}
          />
        )}
```

---

- [ ] **Step 2: Pass `callerTeamId` from the page server component**

Open `src/app/teams/[id]/page.tsx`.

Find the `TeamDetailClient` JSX block (around line 134):

```typescript
          <TeamDetailClient
            teamId={team.id}
            teamName={team.name}
            teamDescription={team.description ?? null}
            timeTrackingEnabled={team.timeTrackingEnabled}
            aiUsesTime={team.aiUsesTime}
            members={membersList}
            projects={projects}
            role={ctx.role}
            userId={ctx.userId}
            githubInstallations={githubInstallations}
          />
```

Replace with:

```typescript
          <TeamDetailClient
            teamId={team.id}
            teamName={team.name}
            teamDescription={team.description ?? null}
            timeTrackingEnabled={team.timeTrackingEnabled}
            aiUsesTime={team.aiUsesTime}
            members={membersList}
            projects={projects}
            role={ctx.role}
            userId={ctx.userId}
            callerTeamId={ctx.teamId}
            githubInstallations={githubInstallations}
          />
```

---

- [ ] **Step 3: Add `ADR-phase-r` to `docs/decisions.md`**

Append the following block at the end of `docs/decisions.md`:

```markdown

---

## ADR-phase-r: LINE_MANAGER Scoped Project Creation (Phase R)

**Status**: Accepted

**Context**: `POST /api/projects` blocked all non-MANAGER roles with a blanket 403. `TeamDetailClient` showed the "New Project" button to `role !== "MEMBER"` callers, meaning LINE_MANAGERs saw the button but received a silent 403 when they clicked it. The button was correct in intent (LINE_MANAGERs manage their own teams) but the API lagged.

**Decision**: LINE_MANAGERs may create projects scoped to the team recorded on their `Membership` row. The API uses a two-step check:
1. Early MEMBER block (unchanged semantics for MEMBER role).
2. After body parsing: `ctx.role === "LINE_MANAGER" && ctx.teamId !== teamId → 403`. `ctx.teamId` is DB-verified by `withAuthScoped()` — it is not read from the JWT, so it cannot be forged.

MANAGERs retain unrestricted creation across all teams. The existing `db.team.findFirst({ where: { id: teamId, organisationId: ctx.organisationId } })` DB check provides the cross-org tenancy boundary as before.

The `ProjectsTab` UI button condition is tightened to `role === "MANAGER" || (role === "LINE_MANAGER" && callerTeamId === teamId)` using a new `callerTeamId` prop (from `ctx.teamId` in the server component), removing any implicit reliance on the page-level redirect for correctness.

**Proof**: 5 forge tests in `src/tests/pr-projects-create-rbac.test.ts`:
- forge-1: LM own team → 201 (fails if old blanket guard is restored)
- forge-2: LM other team → 403, DB not queried (fails if scoped check is removed)
- forge-3: MANAGER any team → 201
- forge-4: MEMBER → 403
- forge-5: LM own teamId, team not in org → 422 (proves org tenancy intact)

**Consequences**: LINE_MANAGERs gain project creation on their own team. No other route's RBAC is touched. No new risk introduced.
```

---

- [ ] **Step 4: Update `docs/architecture.md` — add `POST /api/projects` row and update RBAC capabilities**

**4a.** In `docs/architecture.md`, find the projects API table. The table currently has these rows around line 556:

```
| `GET` | `/api/projects` | Session | List projects scoped to org/team |
| `GET` | `/api/projects/[id]` | Session | Get project (strips `agentTokenHash`) |
```

Insert a new row between them:

```
| `GET` | `/api/projects` | Session | List projects scoped to org/team |
| `POST` | `/api/projects` | Session (MANAGER or LINE_MANAGER of own team) | Create project; LINE_MANAGER scoped to `ctx.teamId` (Membership-verified); returns 201 with one-time `agentToken`; audited |
| `GET` | `/api/projects/[id]` | Session | Get project (strips `agentTokenHash`) |
```

**4b.** In the RBAC capabilities table (around line 159–161), update the LINE_MANAGER row's "Can do" column to mention project creation:

Find:
```
| `LINE_MANAGER` | One team | Own team's projects, own team members' time | Per-team: assign members, rotate that team's project tokens, exec summaries for own team's projects, team-scoped rules |
```

Replace with:
```
| `LINE_MANAGER` | One team | Own team's projects, own team members' time | Per-team: create/edit projects on own team, assign members, rotate that team's project tokens, exec summaries for own team's projects, team-scoped rules |
```

---

- [ ] **Step 5: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: 0 errors in `src/` (test-file errors in `src/tests/` are pre-existing and acceptable). If new errors appear in `src/`, fix them before continuing.

---

- [ ] **Step 6: Run the full test suite**

```bash
npm test
```

Expected: all tests pass (same count as before Task 2, since no new tests were added in Task 2).

---

- [ ] **Step 7: Run production build**

```bash
npm run build
```

Expected: build succeeds with 0 ESLint errors and 0 TypeScript errors. If it fails, read the error output carefully — ESLint `no-unused-vars` on renamed/removed props is the most likely failure mode.

---

- [ ] **Step 8: Commit Task 2**

```bash
git add src/app/teams/[id]/_components/TeamDetailClient.tsx \
        src/app/teams/[id]/page.tsx \
        docs/decisions.md \
        docs/architecture.md
git commit -m "fix(teams): tighten New Project button to LINE_MANAGER own-team scope + docs"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] API allows LINE_MANAGER on own team → forge-1 (Task 1)
- [x] API blocks LINE_MANAGER on other team → forge-2 (Task 1)
- [x] MANAGER unchanged → forge-3 (Task 1)
- [x] MEMBER unchanged → forge-4 (Task 1)
- [x] Cross-org tenancy intact → forge-5 (Task 1)
- [x] `withAuthScoped` not modified (only `route.ts` changed)
- [x] No other route's RBAC altered
- [x] UI button condition explicit (callerTeamId prop) → Task 2 steps 1–2
- [x] ADR documented → Task 2 step 3
- [x] architecture.md updated → Task 2 step 4
- [x] tsc clean → Task 2 step 5
- [x] Full suite green → Tasks 1 step 5 + Task 2 step 6
- [x] npm run build → Task 2 step 7
- [x] No PR opened (push only to `afthab/axis-pulse`)

**Placeholder scan:** none — all steps contain exact code or exact commands.

**Type consistency:**
- `callerTeamId: string | null` is used consistently in `TeamDetailClientProps`, `TeamDetailClient` signature, `ProjectsTab` props, and `page.tsx` call site.
- `ctx.teamId` is `string | null` in `AuthContext` — matches.
