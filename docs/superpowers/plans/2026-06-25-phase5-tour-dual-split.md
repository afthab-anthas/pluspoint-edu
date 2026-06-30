# Phase 5 Tour — Dual Tour Split + Bug Fixes + Educational Steps

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three live bugs in the onboarding tour, split it into a first-run tour and a feature re-take tour, and add three educational spotlight steps (Code Health, Feature Progress, Repo Context).

**Architecture:** Add `availableIn` / `skipIf` properties to `TourStep`, a new `element-appears` advance mode, and a `pulse-tour-kind` cookie; `filterSteps` now takes `(steps, role, tourMode, ctx)`; `RetakeTourButton` writes `feature` cookie before navigating; `TourProvider` reads the cookie and gates steps accordingly. 21 total step definitions: 9 first-run-only, 2 feature-only, 10 shared (both tours). The three new educational steps require new `data-tour` attributes on six components.

**Tech Stack:** Next.js 14 App Router · TypeScript 5 · Vitest · `src/lib/tour/` engine · browser cookies

---

## File structure

| Status | Path | Change |
|--------|------|--------|
| Modify | `src/lib/tour/types.ts` | Add `element-appears` advance mode; `availableIn`, `skipIf` on `TourStep` |
| Modify | `src/lib/tour/filter.ts` | New signature `(steps, role, tourMode, ctx)` |
| Modify | `src/lib/tour/cookie.ts` | Add `readTourKindCookie / writeTourKindCookie / clearTourKindCookie` |
| Modify | `src/lib/tour/steps.ts` | Full rewrite: 21 steps, all tagged |
| Modify | `src/components/tour/TourProvider.tsx` | Read tourKind cookie; handle `element-appears` in poll; clear kind on exit |
| Modify | `src/app/profile/_components/RetakeTourButton.tsx` | Write `feature` kind cookie before push |
| Modify | `src/app/teams/_components/NewTeamDialog.tsx` | Add `data-tour="teams-modal"` to card div; remove `data-tour="teams-create-btn"` |
| Modify | `src/app/teams/[id]/_components/NewProjectDialog.tsx` | Add `data-tour="project-create-modal"` to form card div |
| Modify | `src/app/projects/[id]/_components/CodeHealthRing.tsx` | Add `data-tour="project-code-health"` to both `<section>` return paths |
| Modify | `src/app/projects/[id]/_components/FeatureAreaTrack.tsx` | Add `data-tour="project-feature-progress"` to both `<div>` return paths |
| Modify | `src/app/projects/[id]/_components/RepoContextPanel.tsx` | Add `data-tour="project-repo-context"` to all four `<div>` return paths |
| Modify | `src/components/AppShellClient.tsx` | Wrap Teams `<NavLink>` in `<div data-tour="nav-teams">` |
| Modify | `src/tests/tour-steps.test.ts` | Full rewrite for 21 steps and new filterSteps signature |
| Modify | `src/tests/tour-engine.test.ts` | Update filterSteps calls to 4-arg; add tourKind cookie tests |
| Modify | `src/app/profile/page.tsx` | Update onboarding tour card description |

---

## New data-tour values introduced

| Value | Element | File |
|-------|---------|------|
| `teams-modal` | Inner card `<div>` of NewTeamDialog (line 72) | `NewTeamDialog.tsx` |
| `project-create-modal` | Inner card `<div>` of project create form (line 251) | `NewProjectDialog.tsx` |
| `project-code-health` | `<section>` wrapper in CodeHealthRing (lines 175, 216) | `CodeHealthRing.tsx` |
| `project-feature-progress` | `<div>` wrapper in FeatureAreaTrack (lines 74, 93) | `FeatureAreaTrack.tsx` |
| `project-repo-context` | Outermost `<div>` in RepoContextPanel (lines 97, 130, 167, 357) | `RepoContextPanel.tsx` |
| `nav-teams` | Wrapping `<div>` around Teams NavLink | `AppShellClient.tsx` |

## Step count after this plan

| Tour mode | Role | Steps |
|-----------|------|-------|
| first-run | MANAGER (hasTeam=false, hasProject=false) | 19 |
| first-run | LINE_MANAGER (hasTeam=true, hasProject=false) | 16 |
| feature | MANAGER | 12 |
| feature | LINE_MANAGER | 12 |
| either | MEMBER | 0 |

---

## Task 1: Extend TourStep types

**Files:**
- Modify: `src/lib/tour/types.ts`

- [ ] **Step 1.1: Open `src/lib/tour/types.ts` and make the following edits — no test needed, TypeScript enforces correctness at compile time**

Replace the `TourAdvanceMode` type and `TourStep` type with:

```ts
export type TourRole = "MANAGER" | "LINE_MANAGER" | "MEMBER"

export type TourAdvanceMode =
  | { on: "click" }
  | { on: "navigate"; to: string }
  | { on: "next" }
  | { on: "element-appears"; selector: string }

export type TourRenderContext = {
  role: TourRole
  hasTeam: boolean
  hasProject: boolean
}

export type TourStep = {
  id: string
  route: string
  routePrefix?: string
  selector: string
  instruction: string
  buttonText?: string
  pollTimeout?: number
  advance: TourAdvanceMode
  roles?: TourRole[]
  availableIn?: "first-run" | "feature"   // absent = shown in both tours
  skipIf?: (ctx: TourRenderContext) => boolean
  stub?: {
    condition: (ctx: TourRenderContext) => boolean
    instruction: string
  }
}

export type EngineStatus = "inactive" | "waiting" | "polling" | "active" | "missing"

export type EngineState = {
  status: EngineStatus
  stepIdx: number
  targetRect: DOMRect | null
}

export type EngineAction =
  | { type: "INIT"; stepIdx: number; isOnRoute: boolean }
  | { type: "ARRIVED_ON_ROUTE" }
  | { type: "TARGET_FOUND"; rect: DOMRect }
  | { type: "TARGET_MISSING" }
  | { type: "TARGET_MOVED"; rect: DOMRect }
  | { type: "ADVANCE"; nextIdx: number; isOnRoute: boolean }
  | { type: "EXIT" }
```

- [ ] **Step 1.2: Verify TypeScript is happy**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: zero errors (or only pre-existing errors in `src/tests/` — none in `src/lib/` or `src/components/`).

- [ ] **Step 1.3: Commit**

```bash
git add src/lib/tour/types.ts
git commit -m "feat(tour): extend TourStep with availableIn, skipIf, and element-appears advance mode"
```

---

## Task 2: Write failing tests for new filterSteps signature and step inventory

**Files:**
- Modify: `src/tests/tour-steps.test.ts` (full rewrite)
- Modify: `src/tests/tour-engine.test.ts` (update existing filterSteps calls; add tourKind cookie tests)

- [ ] **Step 2.1: Rewrite `src/tests/tour-steps.test.ts` entirely**

```ts
import { describe, it, expect } from "vitest"
import { TOUR_STEPS } from "@/lib/tour/steps"
import { filterSteps } from "@/lib/tour/filter"
import type { TourRenderContext } from "@/lib/tour/types"

// ── Shared renderCtx fixtures ─────────────────────────────────────────────────
const mgr: TourRenderContext = { role: "MANAGER", hasTeam: false, hasProject: false }
const mgrWithData: TourRenderContext = { role: "MANAGER", hasTeam: true, hasProject: true }
const lm: TourRenderContext = { role: "LINE_MANAGER", hasTeam: true, hasProject: false }
const lmWithProject: TourRenderContext = { role: "LINE_MANAGER", hasTeam: true, hasProject: true }

// ── Step inventory ────────────────────────────────────────────────────────────

describe("TOUR_STEPS definitions", () => {
  it("contains exactly 21 steps", () => {
    expect(TOUR_STEPS).toHaveLength(21)
  })

  it("all steps have non-empty id, instruction, selector, and route", () => {
    for (const step of TOUR_STEPS) {
      expect(step.id, `step ${step.id} missing id`).toBeTruthy()
      expect(step.instruction, `step ${step.id} missing instruction`).toBeTruthy()
      expect(step.selector, `step ${step.id} missing selector`).toBeTruthy()
      expect(step.route, `step ${step.id} missing route`).toBeTruthy()
    }
  })

  it("no two steps share the same id", () => {
    const ids = TOUR_STEPS.map((s) => s.id)
    expect(new Set(ids).size).toBe(ids.length)
  })

  it("sentinel selectors are used for welcome, feature-welcome, and done", () => {
    const welcome = TOUR_STEPS.find((s) => s.id === "welcome")!
    const featureWelcome = TOUR_STEPS.find((s) => s.id === "feature-welcome")!
    const done = TOUR_STEPS.find((s) => s.id === "done")!
    expect(welcome.selector).toBe("[data-tour='__welcome__']")
    expect(featureWelcome.selector).toBe("[data-tour='__feature_welcome__']")
    expect(done.selector).toBe("[data-tour='__done__']")
  })

  it("welcome has buttonText 'Start'", () => {
    const step = TOUR_STEPS.find((s) => s.id === "welcome")!
    expect(step.buttonText).toBe("Start")
  })

  it("feature-welcome has buttonText \"Let's go\"", () => {
    const step = TOUR_STEPS.find((s) => s.id === "feature-welcome")!
    expect(step.buttonText).toBe("Let's go")
  })

  it("done has buttonText 'Go to dashboard'", () => {
    const step = TOUR_STEPS.find((s) => s.id === "done")!
    expect(step.buttonText).toBe("Go to dashboard")
  })
})

// ── availableIn tagging ───────────────────────────────────────────────────────

describe("availableIn tagging", () => {
  it("first-run-only steps are correctly tagged", () => {
    const firstRunIds = [
      "welcome", "dashboard-cta", "teams-new-team", "teams-modal",
      "project-new-project", "project-create-modal",
      "project-token-copy", "project-token-saved", "project-open",
    ]
    for (const id of firstRunIds) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.availableIn, `${id} should be first-run`).toBe("first-run")
    }
  })

  it("feature-only steps are correctly tagged", () => {
    const step1 = TOUR_STEPS.find((s) => s.id === "feature-welcome")!
    const step2 = TOUR_STEPS.find((s) => s.id === "feature-nav-project")!
    expect(step1.availableIn).toBe("feature")
    expect(step2.availableIn).toBe("feature")
  })

  it("shared steps have no availableIn property", () => {
    const sharedIds = [
      "nav-prompts", "project-verdict", "project-exceptions", "project-spend-ring",
      "project-code-health", "project-feature-progress", "project-repo-context",
      "nav-cost-dashboard", "nav-install", "done",
    ]
    for (const id of sharedIds) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.availableIn, `${id} should have no availableIn`).toBeUndefined()
    }
  })
})

// ── skipIf conditions ─────────────────────────────────────────────────────────

describe("skipIf conditions", () => {
  it("dashboard-cta, teams-new-team, teams-modal are skipped when hasTeam=true", () => {
    for (const id of ["dashboard-cta", "teams-new-team", "teams-modal"]) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.skipIf, `${id} must have skipIf`).toBeDefined()
      expect(step.skipIf!({ role: "MANAGER", hasTeam: true, hasProject: false })).toBe(true)
      expect(step.skipIf!({ role: "MANAGER", hasTeam: false, hasProject: false })).toBe(false)
    }
  })

  it("project-create steps are skipped when hasProject=true", () => {
    const ids = ["project-new-project", "project-create-modal", "project-token-copy", "project-token-saved"]
    for (const id of ids) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.skipIf, `${id} must have skipIf`).toBeDefined()
      expect(step.skipIf!({ role: "MANAGER", hasTeam: true, hasProject: true })).toBe(true)
      expect(step.skipIf!({ role: "MANAGER", hasTeam: true, hasProject: false })).toBe(false)
    }
  })

  it("project-open, nav steps, and shared project steps have no skipIf", () => {
    const noSkipIds = [
      "project-open", "nav-prompts", "project-verdict", "project-exceptions",
      "project-spend-ring", "project-code-health", "project-feature-progress",
      "project-repo-context", "nav-cost-dashboard", "nav-install", "done",
    ]
    for (const id of noSkipIds) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.skipIf, `${id} should not have skipIf`).toBeUndefined()
    }
  })
})

// ── Role filtering — first-run mode ──────────────────────────────────────────

describe("MANAGER — first-run tour", () => {
  it("sees 19 steps (no skipIf triggered)", () => {
    expect(filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr)).toHaveLength(19)
  })

  it("step sequence begins: welcome, dashboard-cta, teams-new-team, teams-modal, project-new-project", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr)
    expect(steps[0]!.id).toBe("welcome")
    expect(steps[1]!.id).toBe("dashboard-cta")
    expect(steps[2]!.id).toBe("teams-new-team")
    expect(steps[3]!.id).toBe("teams-modal")
    expect(steps[4]!.id).toBe("project-new-project")
  })

  it("step sequence continues: project-create-modal, project-token-copy, project-token-saved, project-open", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr)
    expect(steps[5]!.id).toBe("project-create-modal")
    expect(steps[6]!.id).toBe("project-token-copy")
    expect(steps[7]!.id).toBe("project-token-saved")
    expect(steps[8]!.id).toBe("project-open")
  })

  it("shared steps follow: nav-prompts, project-verdict, ..., nav-cost-dashboard, nav-install, done", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr)
    expect(steps[9]!.id).toBe("nav-prompts")
    expect(steps[10]!.id).toBe("project-verdict")
    expect(steps[13]!.id).toBe("project-code-health")
    expect(steps[14]!.id).toBe("project-feature-progress")
    expect(steps[15]!.id).toBe("project-repo-context")
    expect(steps[16]!.id).toBe("nav-cost-dashboard")
    expect(steps[17]!.id).toBe("nav-install")
    expect(steps[18]!.id).toBe("done")
  })

  it("does NOT see feature-welcome or feature-nav-project", () => {
    const ids = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr).map((s) => s.id)
    expect(ids).not.toContain("feature-welcome")
    expect(ids).not.toContain("feature-nav-project")
  })

  it("skipIf removes creation steps when MANAGER already has team+project", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgrWithData)
    const ids = steps.map((s) => s.id)
    expect(ids).not.toContain("dashboard-cta")
    expect(ids).not.toContain("teams-new-team")
    expect(ids).not.toContain("teams-modal")
    expect(ids).not.toContain("project-new-project")
    expect(ids).not.toContain("project-create-modal")
    expect(ids).not.toContain("project-token-copy")
    expect(ids).not.toContain("project-token-saved")
    // welcome and project-open survive (no skipIf)
    expect(ids).toContain("welcome")
    expect(ids).toContain("project-open")
  })
})

describe("LINE_MANAGER — first-run tour", () => {
  it("sees 16 steps (no skipIf triggered)", () => {
    expect(filterSteps(TOUR_STEPS, "LINE_MANAGER", "first-run", lm)).toHaveLength(16)
  })

  it("does not see dashboard-cta, teams-new-team, or teams-modal (MANAGER-only)", () => {
    const ids = filterSteps(TOUR_STEPS, "LINE_MANAGER", "first-run", lm).map((s) => s.id)
    expect(ids).not.toContain("dashboard-cta")
    expect(ids).not.toContain("teams-new-team")
    expect(ids).not.toContain("teams-modal")
  })

  it("step sequence begins: welcome, project-new-project, project-create-modal", () => {
    const steps = filterSteps(TOUR_STEPS, "LINE_MANAGER", "first-run", lm)
    expect(steps[0]!.id).toBe("welcome")
    expect(steps[1]!.id).toBe("project-new-project")
    expect(steps[2]!.id).toBe("project-create-modal")
  })
})

// ── Role filtering — feature mode ─────────────────────────────────────────────

describe("MANAGER — feature tour", () => {
  it("sees 12 steps", () => {
    expect(filterSteps(TOUR_STEPS, "MANAGER", "feature", mgr)).toHaveLength(12)
  })

  it("step sequence begins: feature-welcome, feature-nav-project, nav-prompts", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "feature", mgr)
    expect(steps[0]!.id).toBe("feature-welcome")
    expect(steps[1]!.id).toBe("feature-nav-project")
    expect(steps[2]!.id).toBe("nav-prompts")
  })

  it("does NOT see any first-run-only creation steps", () => {
    const ids = filterSteps(TOUR_STEPS, "MANAGER", "feature", mgr).map((s) => s.id)
    const creation = ["welcome", "dashboard-cta", "teams-new-team", "teams-modal",
      "project-new-project", "project-create-modal", "project-token-copy",
      "project-token-saved", "project-open"]
    for (const id of creation) {
      expect(ids, `should not contain ${id} in feature tour`).not.toContain(id)
    }
  })

  it("includes all three new educational steps", () => {
    const ids = filterSteps(TOUR_STEPS, "MANAGER", "feature", mgr).map((s) => s.id)
    expect(ids).toContain("project-code-health")
    expect(ids).toContain("project-feature-progress")
    expect(ids).toContain("project-repo-context")
  })

  it("ends: nav-cost-dashboard, nav-install, done", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "feature", mgr)
    const last3 = steps.slice(-3).map((s) => s.id)
    expect(last3).toEqual(["nav-cost-dashboard", "nav-install", "done"])
  })
})

describe("LINE_MANAGER — feature tour", () => {
  it("sees 12 steps", () => {
    expect(filterSteps(TOUR_STEPS, "LINE_MANAGER", "feature", lm)).toHaveLength(12)
  })
})

describe("MEMBER — any tour mode", () => {
  it("sees 0 steps in first-run mode", () => {
    expect(filterSteps(TOUR_STEPS, "MEMBER", "first-run", { role: "MEMBER", hasTeam: false, hasProject: false })).toHaveLength(0)
  })
  it("sees 0 steps in feature mode", () => {
    expect(filterSteps(TOUR_STEPS, "MEMBER", "feature", { role: "MEMBER", hasTeam: false, hasProject: false })).toHaveLength(0)
  })
})

// ── New step advance modes ────────────────────────────────────────────────────

describe("teams-modal step", () => {
  it("spotlights the whole dialog via navigate advance to /teams/", () => {
    const step = TOUR_STEPS.find((s) => s.id === "teams-modal")!
    expect(step.advance.on).toBe("navigate")
    const nav = step.advance as { on: "navigate"; to: string }
    expect(nav.to).toBe("/teams/")
    expect(nav.to.endsWith("/")).toBe(true)
  })

  it("selector targets the dialog card element", () => {
    const step = TOUR_STEPS.find((s) => s.id === "teams-modal")!
    expect(step.selector).toBe("[data-tour='teams-modal']")
  })
})

describe("project-create-modal step", () => {
  it("uses element-appears advance mode pointing to project-token-copy", () => {
    const step = TOUR_STEPS.find((s) => s.id === "project-create-modal")!
    expect(step.advance.on).toBe("element-appears")
    const adv = step.advance as { on: "element-appears"; selector: string }
    expect(adv.selector).toBe("[data-tour='project-token-copy']")
  })

  it("has a 120-second poll timeout for the user to fill the form", () => {
    const step = TOUR_STEPS.find((s) => s.id === "project-create-modal")!
    expect(step.pollTimeout).toBe(120_000)
  })
})

describe("feature-nav-project step", () => {
  it("spotlights nav-teams and uses navigate advance to /projects/", () => {
    const step = TOUR_STEPS.find((s) => s.id === "feature-nav-project")!
    expect(step.selector).toBe("[data-tour='nav-teams']")
    expect(step.advance.on).toBe("navigate")
    const nav = step.advance as { on: "navigate"; to: string }
    expect(nav.to).toBe("/projects/")
  })

  it("is visible on any page (routePrefix '/')", () => {
    const step = TOUR_STEPS.find((s) => s.id === "feature-nav-project")!
    expect(step.routePrefix).toBe("/")
  })
})

// ── New educational steps ─────────────────────────────────────────────────────

describe("new educational steps", () => {
  it("project-code-health is on /projects/ prefix with next advance", () => {
    const step = TOUR_STEPS.find((s) => s.id === "project-code-health")!
    expect(step.routePrefix).toBe("/projects/")
    expect(step.advance.on).toBe("next")
    expect(step.selector).toBe("[data-tour='project-code-health']")
  })

  it("project-feature-progress is on /projects/ prefix with next advance", () => {
    const step = TOUR_STEPS.find((s) => s.id === "project-feature-progress")!
    expect(step.routePrefix).toBe("/projects/")
    expect(step.advance.on).toBe("next")
  })

  it("project-repo-context is on /projects/ prefix with next advance", () => {
    const step = TOUR_STEPS.find((s) => s.id === "project-repo-context")!
    expect(step.routePrefix).toBe("/projects/")
    expect(step.advance.on).toBe("next")
  })

  it("educational steps appear after project-spend-ring and before nav-cost-dashboard in both tours", () => {
    const steps = filterSteps(TOUR_STEPS, "MANAGER", "first-run", mgr)
    const ids = steps.map((s) => s.id)
    const spendIdx = ids.indexOf("project-spend-ring")
    const healthIdx = ids.indexOf("project-code-health")
    const featureIdx = ids.indexOf("project-feature-progress")
    const repoIdx = ids.indexOf("project-repo-context")
    const costIdx = ids.indexOf("nav-cost-dashboard")
    expect(spendIdx).toBeLessThan(healthIdx)
    expect(healthIdx).toBeLessThan(featureIdx)
    expect(featureIdx).toBeLessThan(repoIdx)
    expect(repoIdx).toBeLessThan(costIdx)
  })
})

// ── teams-create-btn is GONE ──────────────────────────────────────────────────

describe("removed steps", () => {
  it("teams-create-btn no longer exists (replaced by teams-modal)", () => {
    const step = TOUR_STEPS.find((s) => s.id === "teams-create-btn")
    expect(step).toBeUndefined()
  })
})

// ── Stub conditions ───────────────────────────────────────────────────────────

describe("stub conditions", () => {
  const lmNoProject: TourRenderContext = { role: "LINE_MANAGER", hasTeam: true, hasProject: false }
  const lmWithProj: TourRenderContext = { role: "LINE_MANAGER", hasTeam: true, hasProject: true }
  const manager: TourRenderContext = { role: "MANAGER", hasTeam: true, hasProject: true }

  const STUBBED_STEP_IDS = ["project-open", "project-verdict", "project-exceptions", "project-spend-ring"]

  for (const id of STUBBED_STEP_IDS) {
    describe(`step: ${id}`, () => {
      it("stub fires for LINE_MANAGER without a project", () => {
        const step = TOUR_STEPS.find((s) => s.id === id)!
        expect(step.stub!.condition(lmNoProject)).toBe(true)
      })
      it("stub does NOT fire for LINE_MANAGER with a project", () => {
        const step = TOUR_STEPS.find((s) => s.id === id)!
        expect(step.stub!.condition(lmWithProj)).toBe(false)
      })
      it("stub does NOT fire for MANAGER", () => {
        const step = TOUR_STEPS.find((s) => s.id === id)!
        expect(step.stub!.condition(manager)).toBe(false)
      })
    })
  }

  it("new educational steps (code-health, feature-progress, repo-context) have no stub", () => {
    for (const id of ["project-code-health", "project-feature-progress", "project-repo-context"]) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.stub).toBeUndefined()
    }
  })
})

// ── routePrefix coverage ──────────────────────────────────────────────────────

describe("routePrefix", () => {
  it("/teams/ steps", () => {
    const ids = [
      "project-new-project", "project-create-modal",
      "project-token-copy", "project-token-saved", "project-open", "teams-modal",
    ]
    for (const id of ids) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.routePrefix, `${id} routePrefix`).toBe("/teams/")
    }
  })

  it("/projects/ steps", () => {
    const ids = [
      "project-verdict", "project-exceptions", "project-spend-ring",
      "project-code-health", "project-feature-progress", "project-repo-context",
    ]
    for (const id of ids) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.routePrefix, `${id} routePrefix`).toBe("/projects/")
    }
  })

  it("sidebar steps and feature-nav-project have routePrefix '/'", () => {
    const ids = ["nav-prompts", "nav-cost-dashboard", "nav-install", "feature-nav-project"]
    for (const id of ids) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.routePrefix, `${id} routePrefix`).toBe("/")
    }
  })

  it("static-route steps have no routePrefix", () => {
    const ids = [
      "welcome", "feature-welcome", "dashboard-cta",
      "teams-new-team", "done",
    ]
    for (const id of ids) {
      const step = TOUR_STEPS.find((s) => s.id === id)!
      expect(step.routePrefix, `${id} should have no routePrefix`).toBeUndefined()
    }
  })
})
```

- [ ] **Step 2.2: Run the tests — expect failures because steps.ts and filter.ts haven't been updated yet**

```bash
npm test -- --reporter=verbose src/tests/tour-steps.test.ts 2>&1 | tail -30
```

Expected: many failures referencing missing steps and wrong `filterSteps` arity. That is correct — failures confirm the tests are actually checking the right things.

- [ ] **Step 2.3: Update filterSteps tests in `src/tests/tour-engine.test.ts`**

In `src/tests/tour-engine.test.ts`, find the `describe("filterSteps", ...)` block (lines 33–75) and update every `filterSteps(...)` call to pass the two new required args. The inline step objects in those tests have no `availableIn` or `skipIf`, so tourMode/ctx don't affect results.

Replace the entire `describe("filterSteps", ...)` block with:

```ts
describe("filterSteps", () => {
  const ctx = { role: "MEMBER" as const, hasTeam: false, hasProject: false }

  it("returns all steps when roles is absent", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "a", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
      { id: "b", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
    ]
    expect(filterSteps(steps, "MEMBER", "first-run", ctx)).toHaveLength(2)
  })

  it("filters out steps not matching role", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "mgr", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["MANAGER" as const] },
      { id: "all", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
    ]
    const result = filterSteps(steps, "MEMBER", "first-run", ctx)
    expect(result).toHaveLength(1)
    expect(result[0]!.id).toBe("all")
  })

  it("includes step when role is in roles array", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "lm", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["LINE_MANAGER" as const, "MANAGER" as const] },
    ]
    expect(filterSteps(steps, "LINE_MANAGER", "first-run", ctx)).toHaveLength(1)
    expect(filterSteps(steps, "MEMBER", "first-run", ctx)).toHaveLength(0)
  })

  it("returns empty array when no steps match role", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "mgr", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["MANAGER" as const] },
    ]
    expect(filterSteps(steps, "MEMBER", "first-run", ctx)).toHaveLength(0)
  })

  it("handles empty steps array", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    expect(filterSteps([], "MANAGER", "first-run", ctx)).toHaveLength(0)
  })
})
```

Also add tourKind cookie tests at the END of `src/tests/tour-engine.test.ts`:

```ts
// ── tourKind cookie utilities ─────────────────────────────────────────────────

describe("tour kind cookie utilities", () => {
  beforeEach(() => {
    document.cookie = "pulse-tour-kind=; path=/; max-age=0"
  })

  it("readTourKindCookie returns 'first-run' when cookie absent", async () => {
    const { readTourKindCookie } = await import("@/lib/tour/cookie")
    expect(readTourKindCookie()).toBe("first-run")
  })

  it("writeTourKindCookie + readTourKindCookie round-trips 'feature'", async () => {
    const { readTourKindCookie, writeTourKindCookie } = await import("@/lib/tour/cookie")
    writeTourKindCookie("feature")
    expect(readTourKindCookie()).toBe("feature")
  })

  it("writeTourKindCookie + readTourKindCookie round-trips 'first-run'", async () => {
    const { readTourKindCookie, writeTourKindCookie } = await import("@/lib/tour/cookie")
    writeTourKindCookie("first-run")
    expect(readTourKindCookie()).toBe("first-run")
  })

  it("clearTourKindCookie resets readTourKindCookie to 'first-run'", async () => {
    const { readTourKindCookie, writeTourKindCookie, clearTourKindCookie } = await import("@/lib/tour/cookie")
    writeTourKindCookie("feature")
    clearTourKindCookie()
    expect(readTourKindCookie()).toBe("first-run")
  })

  it("unknown cookie value falls back to 'first-run'", async () => {
    document.cookie = "pulse-tour-kind=garbage; path=/"
    const { readTourKindCookie } = await import("@/lib/tour/cookie")
    expect(readTourKindCookie()).toBe("first-run")
  })
})
```

- [ ] **Step 2.4: Commit failing tests**

```bash
git add src/tests/tour-steps.test.ts src/tests/tour-engine.test.ts
git commit -m "test(tour): write failing tests for dual-tour split, element-appears, 21 steps"
```

---

## Task 3: Update filterSteps to new 4-argument signature

**Files:**
- Modify: `src/lib/tour/filter.ts`

- [ ] **Step 3.1: Replace `src/lib/tour/filter.ts` entirely**

```ts
import type { TourRole, TourRenderContext, TourStep } from "./types"

export function filterSteps(
  steps: TourStep[],
  role: TourRole,
  tourMode: "first-run" | "feature",
  ctx: TourRenderContext,
): TourStep[] {
  return steps.filter((s) => {
    if (s.roles && !s.roles.includes(role)) return false
    if (s.availableIn === "first-run" && tourMode !== "first-run") return false
    if (s.availableIn === "feature" && tourMode !== "feature") return false
    if (s.skipIf?.(ctx)) return false
    return true
  })
}
```

- [ ] **Step 3.2: Run only the engine filterSteps tests (they should now pass; steps tests still fail)**

```bash
npm test -- --reporter=verbose src/tests/tour-engine.test.ts 2>&1 | grep -A2 "filterSteps"
```

Expected: filterSteps tests PASS. tourKind cookie tests FAIL (cookie.ts not updated yet).

---

## Task 4: Rewrite steps.ts with 21 steps

**Files:**
- Modify: `src/lib/tour/steps.ts`

- [ ] **Step 4.1: Replace `src/lib/tour/steps.ts` entirely**

```ts
import type { TourStep } from "./types"

export const TOUR_STEPS: TourStep[] = [

  // ── FIRST-RUN ONLY ───────────────────────────────────────────────────────────

  {
    id: "welcome",
    route: "/dashboard",
    selector: "[data-tour='__welcome__']",
    instruction: "Pulse connects Claude Code sessions to a shared dashboard. This takes about 5 minutes.",
    buttonText: "Start",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
  },

  {
    id: "dashboard-cta",
    route: "/dashboard",
    selector: "[data-tour='dashboard-cta']",
    instruction: "Create a team to get started.",
    advance: { on: "click" },
    roles: ["MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasTeam,
  },

  {
    id: "teams-new-team",
    route: "/teams",
    selector: "[data-tour='teams-new-team']",
    instruction: "Open the New Team dialog.",
    advance: { on: "click" },
    roles: ["MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasTeam,
  },

  // Bug 1 fix: spotlight the whole dialog card (not the Create button),
  // advance when the team is actually created (URL navigates to /teams/[id]).
  {
    id: "teams-modal",
    route: "/teams",
    selector: "[data-tour='teams-modal']",
    instruction: "Name your team and click Create.",
    advance: { on: "navigate", to: "/teams/" },
    pollTimeout: 30_000,
    roles: ["MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasTeam,
  },

  {
    id: "project-new-project",
    route: "/teams",
    routePrefix: "/teams/",
    selector: "[data-tour='project-new-project']",
    instruction: "Add a project — each project maps to one repo.",
    advance: { on: "click" },
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasProject,
  },

  // Bug 1 fix: spotlight the whole project form dialog,
  // advance when the token modal appears (element-appears) — project was created.
  // Bug 2 fix: project-token-copy step now only activates after this advance fires,
  // so it can never appear before the project is actually created.
  {
    id: "project-create-modal",
    route: "/teams",
    routePrefix: "/teams/",
    selector: "[data-tour='project-create-modal']",
    instruction: "Name your project and click Create. Each project maps to one code repository.",
    advance: { on: "element-appears", selector: "[data-tour='project-token-copy']" },
    pollTimeout: 120_000,
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasProject,
  },

  {
    id: "project-token-copy",
    route: "/teams",
    routePrefix: "/teams/",
    selector: "[data-tour='project-token-copy']",
    instruction: "Copy this token now — it connects your repo to Pulse and is shown only once.",
    advance: { on: "click" },
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasProject,
  },

  {
    id: "project-token-saved",
    route: "/teams",
    routePrefix: "/teams/",
    selector: "[data-tour='project-token-saved']",
    instruction: "Click here to confirm you have saved the token — the dialog will close.",
    advance: { on: "click" },
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
    skipIf: (ctx) => ctx.hasProject,
  },

  {
    id: "project-open",
    route: "/teams",
    routePrefix: "/teams/",
    selector: "[data-tour='project-open']",
    instruction: "Open your project to see its dashboard.",
    advance: { on: "navigate", to: "/projects/" },
    pollTimeout: 30_000,
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "first-run",
    stub: {
      condition: (ctx) => ctx.role === "LINE_MANAGER" && !ctx.hasProject,
      instruction: "Once a project exists on your team, click it here to open its dashboard.",
    },
  },

  // ── FEATURE TOUR ONLY ────────────────────────────────────────────────────────

  // Bug 3 fix: retaking users get the feature tour (written by RetakeTourButton).
  // The feature tour skips all creation steps and starts with educational spotlights.
  {
    id: "feature-welcome",
    route: "/dashboard",
    selector: "[data-tour='__feature_welcome__']",
    instruction: "You're on the features tour. We'll walk through the key panels — skip any step with Esc.",
    buttonText: "Let's go",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "feature",
  },

  {
    id: "feature-nav-project",
    route: "/",
    routePrefix: "/",
    selector: "[data-tour='nav-teams']",
    instruction: "Open a project to explore its analytics — click Teams in the sidebar, then pick a project.",
    advance: { on: "navigate", to: "/projects/" },
    pollTimeout: 60_000,
    roles: ["MANAGER", "LINE_MANAGER"],
    availableIn: "feature",
  },

  // ── SHARED (both tours) ──────────────────────────────────────────────────────

  {
    id: "nav-prompts",
    route: "/",
    routePrefix: "/",
    selector: "[data-tour='nav-prompts']",
    instruction: "Your prompt library — Pulse matches prompts to activity to classify time as productive.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  {
    id: "project-verdict",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-verdict']",
    instruction: "On Track, Needs Attention, or At Risk — populated after the first Claude Code session.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
    stub: {
      condition: (ctx) => ctx.role === "LINE_MANAGER" && !ctx.hasProject,
      instruction: "Once a project exists on your team, its status will appear here.",
    },
  },

  {
    id: "project-exceptions",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-exceptions']",
    instruction: "Exceptions and security findings surface here after the first ingest.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
    stub: {
      condition: (ctx) => ctx.role === "LINE_MANAGER" && !ctx.hasProject,
      instruction: "Exceptions and findings will appear here once your team has a project.",
    },
  },

  {
    id: "project-spend-ring",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-spend-ring']",
    instruction: "Real Claude Code spend to the dollar — updates after each narrated session.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
    stub: {
      condition: (ctx) => ctx.role === "LINE_MANAGER" && !ctx.hasProject,
      instruction: "Spend tracking will appear here once your team has a project running.",
    },
  },

  // Educational step — new
  {
    id: "project-code-health",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-code-health']",
    instruction: "Code Health grades your repo on coverage, duplication, and complexity — A to F per signal. Grades appear once a Claude Code session has run.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  // Educational step — new
  {
    id: "project-feature-progress",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-feature-progress']",
    instruction: "Feature Progress maps active work areas to their maturity phase — building, stabilising, or maturing — based on recent commit patterns.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  // Educational step — new
  {
    id: "project-repo-context",
    route: "/projects",
    routePrefix: "/projects/",
    selector: "[data-tour='project-repo-context']",
    instruction: "Repo Context links a GitHub repository so Axis Pulse can detect your stack and enrich AI summaries with real code context. Connect via the GitHub App.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  {
    id: "nav-cost-dashboard",
    route: "/",
    routePrefix: "/",
    selector: "[data-tour='nav-cost-dashboard']",
    instruction: "Track every developer's AI spend across all projects in one place.",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  {
    id: "nav-install",
    route: "/",
    routePrefix: "/",
    selector: "[data-tour='nav-install']",
    instruction: "Last step: install the Pulse hook in your repo on your own machine.",
    advance: { on: "click" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

  {
    id: "done",
    route: "/install",
    selector: "[data-tour='__done__']",
    instruction: "Once a Claude Code session runs in your hooked repo, your first narrated story appears here.",
    buttonText: "Go to dashboard",
    advance: { on: "next" },
    roles: ["MANAGER", "LINE_MANAGER"],
  },

]
```

- [ ] **Step 4.2: Run tour-steps tests — expect near-full pass (index assertions may need adjustment)**

```bash
npm test -- --reporter=verbose src/tests/tour-steps.test.ts 2>&1 | tail -40
```

Expected: most tests pass. If any index-based assertions fail, log the actual step IDs for that filtered set and verify against the expected sequence above.

- [ ] **Step 4.3: Run the full suite**

```bash
npm test 2>&1 | tail -20
```

Expected: tour-steps.test.ts and tour-engine.test.ts filterSteps tests all PASS. tourKind cookie tests FAIL (cookie.ts still needs updating).

- [ ] **Step 4.4: Commit**

```bash
git add src/lib/tour/filter.ts src/lib/tour/steps.ts
git commit -m "feat(tour): rewrite 21-step inventory with dual-tour tags, skipIf, element-appears"
```

---

## Task 5: Add tourKind cookie functions to cookie.ts

**Files:**
- Modify: `src/lib/tour/cookie.ts`

- [ ] **Step 5.1: Append tourKind functions to `src/lib/tour/cookie.ts`**

The existing file already has `COOKIE_NAME = "pulse-tour-step"` and `MAX_AGE = 86_400`. Append below the existing exports:

```ts
// ── Tour kind cookie (first-run vs feature) ───────────────────────────────────

const TOUR_KIND_COOKIE_NAME = "pulse-tour-kind"

export function readTourKindCookie(): "first-run" | "feature" {
  if (typeof document === "undefined") return "first-run"
  const pair = document.cookie
    .split(";")
    .find((c) => c.trim().startsWith(TOUR_KIND_COOKIE_NAME + "="))
  if (!pair) return "first-run"
  const val = pair.split("=").slice(1).join("=").trim()
  return decodeURIComponent(val) === "feature" ? "feature" : "first-run"
}

export function writeTourKindCookie(kind: "first-run" | "feature"): void {
  if (typeof document === "undefined") return
  document.cookie = `${TOUR_KIND_COOKIE_NAME}=${encodeURIComponent(kind)}; path=/; max-age=${MAX_AGE}; SameSite=Lax`
}

export function clearTourKindCookie(): void {
  if (typeof document === "undefined") return
  document.cookie = `${TOUR_KIND_COOKIE_NAME}=; path=/; max-age=0; SameSite=Lax`
}
```

- [ ] **Step 5.2: Run the engine tests — tourKind cookie tests should now pass**

```bash
npm test -- --reporter=verbose src/tests/tour-engine.test.ts 2>&1 | grep -A3 "tour kind cookie"
```

Expected: all 5 tourKind cookie tests PASS.

- [ ] **Step 5.3: Commit**

```bash
git add src/lib/tour/cookie.ts
git commit -m "feat(tour): add tourKind cookie for first-run vs feature tour mode"
```

---

## Task 6: Update TourProvider to read tourKind and handle element-appears

**Files:**
- Modify: `src/components/tour/TourProvider.tsx`

- [ ] **Step 6.1: Update the import line at the top of TourProvider.tsx**

Old:
```ts
import { clearTourCookie, readTourCookie, writeTourCookie } from "@/lib/tour/cookie"
```

New:
```ts
import {
  clearTourCookie, clearTourKindCookie,
  readTourCookie, readTourKindCookie,
  writeTourCookie,
} from "@/lib/tour/cookie"
```

- [ ] **Step 6.2: Update the filterSteps call (line 47)**

Old:
```ts
const steps = filterSteps(TOUR_STEPS, role)
```

New (reads tourKind cookie at render time — TourProvider is "use client", document is always available):
```ts
const tourKind = readTourKindCookie()
const steps = filterSteps(TOUR_STEPS, role, tourKind, renderCtx)
```

- [ ] **Step 6.3: Update `activateStep` to handle element-appears early-exit**

Inside `activateStep`, directly after the existing `if (step.advance.on === "navigate")` block (which ends around line 113), add:

```ts
// element-appears: if the appear-target is already in the DOM when the step
// activates (e.g., resume after refresh), skip forward immediately.
if (step.advance.on === "element-appears") {
  const appearEl = document.querySelector(
    (step.advance as { on: "element-appears"; selector: string }).selector
  )
  if (appearEl) return activateStep(idx + 1)
}
```

- [ ] **Step 6.4: Update `beginPoll` to check element-appears on each tick**

Inside `beginPoll`, in the `else` branch (when `el` is null — selector not found), add the element-appears check **before** the existing timeout check:

Current `else` branch:
```ts
} else {
  const timeout = step.pollTimeout ?? POLL_TIMEOUT_MS
  if (Date.now() - pollStartRef.current > timeout) {
    stopPolling()
    dispatch({ type: "TARGET_MISSING" })
    focusTooltip()
  }
}
```

Replace with:
```ts
} else {
  // element-appears: auto-advance when the signal element appears in the DOM
  if (step.advance.on === "element-appears") {
    const appearSelector = (step.advance as { on: "element-appears"; selector: string }).selector
    const appearEl = document.querySelector(appearSelector)
    if (appearEl) {
      stopPolling()
      advance(idx)
      return
    }
  }
  const timeout = step.pollTimeout ?? POLL_TIMEOUT_MS
  if (Date.now() - pollStartRef.current > timeout) {
    stopPolling()
    dispatch({ type: "TARGET_MISSING" })
    focusTooltip()
  }
}
```

- [ ] **Step 6.5: Update `doExit` to clear the tourKind cookie**

Old:
```ts
async function doExit() {
  stopPolling()
  clearTourCookie()
  dispatch({ type: "EXIT" })
  await patchOnboarded()
}
```

New:
```ts
async function doExit() {
  stopPolling()
  clearTourCookie()
  clearTourKindCookie()
  dispatch({ type: "EXIT" })
  await patchOnboarded()
}
```

- [ ] **Step 6.6: Run the full test suite**

```bash
npm test 2>&1 | tail -10
```

Expected: all tests pass.

- [ ] **Step 6.7: TypeScript check**

```bash
npx tsc --noEmit 2>&1 | grep -v "src/tests/" | head -20
```

Expected: zero errors outside `src/tests/`.

- [ ] **Step 6.8: Commit**

```bash
git add src/components/tour/TourProvider.tsx
git commit -m "feat(tour): read tourKind cookie and handle element-appears advance in TourProvider"
```

---

## Task 7: Add data-tour attributes to six components

**Files:**
- Modify: `src/app/teams/_components/NewTeamDialog.tsx`
- Modify: `src/app/teams/[id]/_components/NewProjectDialog.tsx`
- Modify: `src/app/projects/[id]/_components/CodeHealthRing.tsx`
- Modify: `src/app/projects/[id]/_components/FeatureAreaTrack.tsx`
- Modify: `src/app/projects/[id]/_components/RepoContextPanel.tsx`
- Modify: `src/components/AppShellClient.tsx`

### 7a — NewTeamDialog.tsx

The dialog renders two states: `!open` (button only) and `open` (modal). The inner card `<div>` (line 72) is the element that encloses the form and Create button — spotlighting it lets the user interact with the entire form freely.

- [ ] **Step 7a.1: Add `data-tour="teams-modal"` to the inner card div**

In `src/app/teams/_components/NewTeamDialog.tsx`, find line 72:
```tsx
      <div
        style={{
          background: "var(--background-card)",
          border: "1px solid var(--border-mid)",
          borderRadius: "14px",
          padding: "32px",
          width: "420px",
          maxWidth: "90vw",
          boxShadow: "var(--card-shadow)",
        }}
      >
```
Add `data-tour="teams-modal"`:
```tsx
      <div
        data-tour="teams-modal"
        style={{
          background: "var(--background-card)",
          border: "1px solid var(--border-mid)",
          borderRadius: "14px",
          padding: "32px",
          width: "420px",
          maxWidth: "90vw",
          boxShadow: "var(--card-shadow)",
        }}
      >
```

- [ ] **Step 7a.2: Remove the now-obsolete `data-tour="teams-create-btn"` from the Create button**

Find line 151-152:
```tsx
            <button
              data-tour="teams-create-btn"
              type="submit"
```
Change to:
```tsx
            <button
              type="submit"
```

### 7b — NewProjectDialog.tsx

The dialog has three states: `!open` (button), `tokenReveal` (token modal), and `open` (form modal). Only the form modal needs the new attribute. The inner form card `<div>` is at line 251.

- [ ] **Step 7b.1: Add `data-tour="project-create-modal"` to the form card div**

In `src/app/teams/[id]/_components/NewProjectDialog.tsx`, find line 251:
```tsx
      <div
        style={{
          background: "#111318",
          border: "1px solid var(--border)",
          borderRadius: "14px",
          padding: "32px",
          width: "440px",
          maxWidth: "90vw",
        }}
      >
```
Add `data-tour="project-create-modal"`:
```tsx
      <div
        data-tour="project-create-modal"
        style={{
          background: "#111318",
          border: "1px solid var(--border)",
          borderRadius: "14px",
          padding: "32px",
          width: "440px",
          maxWidth: "90vw",
        }}
      >
```

### 7c — CodeHealthRing.tsx

The component has two `<section>` return paths: empty state (line 175) and populated state (line 216). Both need the attribute so the tour step spotlights the card regardless of whether a snapshot exists.

- [ ] **Step 7c.1: Add `data-tour="project-code-health"` to both section elements**

Find line 175:
```tsx
    return (
      <section style={sectionStyle}>
```
Change to:
```tsx
    return (
      <section data-tour="project-code-health" style={sectionStyle}>
```

Find line 216:
```tsx
  return (
    <section style={sectionStyle}>
```
Change to:
```tsx
  return (
    <section data-tour="project-code-health" style={sectionStyle}>
```

### 7d — FeatureAreaTrack.tsx

Two `<div style={cardStyle}>` return paths: empty state (line 74) and populated state (line 93).

- [ ] **Step 7d.1: Add `data-tour="project-feature-progress"` to both div elements**

Find line 74:
```tsx
      <div style={cardStyle}>
```
(The one inside `if (rows.length === 0)`)  
Change to:
```tsx
      <div data-tour="project-feature-progress" style={cardStyle}>
```

Find line 93:
```tsx
  return (
    <div style={cardStyle}>
```
Change to:
```tsx
  return (
    <div data-tour="project-feature-progress" style={cardStyle}>
```

### 7e — RepoContextPanel.tsx

Four distinct return paths. Add `data-tour="project-repo-context"` to the outermost `<div>` of each:

- [ ] **Step 7e.1: Line 97 (no repo, can't refresh)**

```tsx
        <div
          style={{
            background: "var(--background-card)",
            border: "1px solid var(--border)",
            borderRadius: "var(--radius-lg)",
            padding: "16px 20px",
            marginBottom: "1.25rem",
            display: "flex",
            alignItems: "center",
            gap: "10px",
          }}
        >
```
Change to:
```tsx
        <div
          data-tour="project-repo-context"
          style={{
            background: "var(--background-card)",
            border: "1px solid var(--border)",
            borderRadius: "var(--radius-lg)",
            padding: "16px 20px",
            marginBottom: "1.25rem",
            display: "flex",
            alignItems: "center",
            gap: "10px",
          }}
        >
```

- [ ] **Step 7e.2: Line 130 (no installations / UNAVAILABLE)**

```tsx
        <div
          style={{
            background: "var(--background-card)",
            border: "1px solid var(--border)",
            borderRadius: "var(--radius-lg)",
            padding: "16px 20px",
            marginBottom: "1.25rem",
          }}
        >
          <div style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "4px" }}>
```
Change the outer div to add `data-tour="project-repo-context"`.

- [ ] **Step 7e.3: Line 167 (no repo, can link)**

```tsx
    return (
      <div
        style={{
          background: "var(--background-card)",
          border: "1px solid var(--border)",
          borderRadius: "var(--radius-lg)",
          padding: "16px 20px",
          marginBottom: "1.25rem",
        }}
      >
```
Change to include `data-tour="project-repo-context"`.

- [ ] **Step 7e.4: Line 357 (linked / main state)**

```tsx
  return (
    <div
      style={{
        background: "var(--background-card)",
        border: "1px solid var(--border)",
        borderRadius: "var(--radius-lg)",
        padding: "16px 20px",
        marginBottom: "1.25rem",
      }}
    >
```
Change to include `data-tour="project-repo-context"`.

### 7f — AppShellClient.tsx

The Teams `<NavLink>` is currently unwrapped (line 291). Wrap it so the tour can spotlight it.

- [ ] **Step 7f.1: Wrap Teams NavLink with `data-tour="nav-teams"`**

Find line 291:
```tsx
          <NavLink href="/teams" label="Teams" icon={<UsersIcon />} />
```
Replace with:
```tsx
          <div data-tour="nav-teams">
            <NavLink href="/teams" label="Teams" icon={<UsersIcon />} />
          </div>
```

- [ ] **Step 7g: TypeScript check after all attribute additions**

```bash
npx tsc --noEmit 2>&1 | grep -v "src/tests/" | head -10
```

Expected: zero errors.

- [ ] **Step 7h: Commit all component data-tour changes**

```bash
git add src/app/teams/_components/NewTeamDialog.tsx
git add src/app/teams/[id]/_components/NewProjectDialog.tsx
git add src/app/projects/[id]/_components/CodeHealthRing.tsx
git add src/app/projects/[id]/_components/FeatureAreaTrack.tsx
git add src/app/projects/[id]/_components/RepoContextPanel.tsx
git add src/components/AppShellClient.tsx
git commit -m "feat(tour): add data-tour attributes for modal spotlights and three educational steps"
```

---

## Task 8: Update RetakeTourButton and profile page description

**Files:**
- Modify: `src/app/profile/_components/RetakeTourButton.tsx`
- Modify: `src/app/profile/page.tsx`

- [ ] **Step 8.1: Update `RetakeTourButton.tsx` to write the feature kind cookie**

Replace the current `RetakeTourButton.tsx` with:

```tsx
"use client"
import { useState } from "react"
import { useRouter } from "next/navigation"
import { clearTourCookie, writeTourKindCookie } from "@/lib/tour/cookie"

export function RetakeTourButton() {
  const router = useRouter()
  const [loading, setLoading] = useState(false)

  async function handleRetake() {
    setLoading(true)
    try {
      await fetch("/api/me/onboarding", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ hasOnboarded: false }),
      })
      clearTourCookie()
      writeTourKindCookie("feature")
      router.push("/dashboard")
    } catch {
      setLoading(false)
    }
  }

  return (
    <button
      onClick={() => void handleRetake()}
      disabled={loading}
      style={{
        width: "100%",
        padding: "9px 14px",
        background: "var(--background-chip)",
        border: "1px solid var(--border-mid)",
        borderRadius: "8px",
        fontSize: "13px",
        fontWeight: 600,
        color: loading ? "var(--text-muted)" : "var(--text-secondary)",
        cursor: loading ? "not-allowed" : "pointer",
        fontFamily: "var(--font-sans)",
        textAlign: "left",
      }}
    >
      {loading ? "Restarting…" : "Take the tour again →"}
    </button>
  )
}
```

- [ ] **Step 8.2: Update the onboarding tour card description in `src/app/profile/page.tsx`**

Find line 406:
```tsx
                    Walks you through creating a team, project, and installing the Pulse hook.
```
Replace with:
```tsx
                    Explore Axis Pulse's key features — spotlights Code Health, Repo Context, Feature Progress, and more.
```

- [ ] **Step 8.3: Run full test suite**

```bash
npm test 2>&1 | tail -10
```

Expected: all tests pass (no new failures).

- [ ] **Step 8.4: Commit**

```bash
git add src/app/profile/_components/RetakeTourButton.tsx src/app/profile/page.tsx
git commit -m "feat(tour): retake writes feature kind cookie; update profile description"
```

---

## Task 9: Build, tsc, and full test verification

- [ ] **Step 9.1: Run the full test suite**

```bash
npm test 2>&1 | tail -15
```

Expected: all tests pass. Note the new total (was 1941, should be ~1941 + new tests added in Task 2).

- [ ] **Step 9.2: TypeScript check (no errors outside tests)**

```bash
npx tsc --noEmit 2>&1 | grep -v "src/tests/" | head -20
```

Expected: zero lines (zero errors outside test files).

- [ ] **Step 9.3: Production build**

```bash
npm run build 2>&1 | tail -20
```

Expected: successful build, zero ESLint errors. If ESLint flags unused variables introduced by this work, fix them before proceeding.

- [ ] **Step 9.4: Commit build confirmation (no file changes, just a marker commit if needed)**

If the build required any fix, commit the fix:
```bash
git add <fixed files>
git commit -m "fix(tour): resolve ESLint errors found during production build"
```

---

## Task 10: Visual walk-through gate — BOTH tours

This task is the release gate. Tests verify logic; this verifies UX. Run against the local development server with real database data.

**Pre-conditions:**
- Development server is running (`npm run dev`)
- You have a fresh MANAGER account with `hasOnboarded = false` (or can reset via DB)
- You have a separate MANAGER account with existing team + project (for feature tour)

### Walk-through A: First-run tour (new user, MANAGER)

- [ ] **Step 10.1:** Log in as MANAGER with `hasOnboarded = false`. Confirm the welcome tooltip appears centred on `/dashboard` with "Start" button.
- [ ] **Step 10.2:** Click "Start". Confirm the dashboard-cta link is spotlit ("Create a team to get started"). Confirm the NAME INPUT is NOT blocked by any overlay.
- [ ] **Step 10.3:** Click the CTA → navigate to `/teams`. Confirm "New Team" button is spotlit. Click it → dialog opens.
- [ ] **Step 10.4:** Confirm the ENTIRE modal card is spotlit (not just the Create button). Type a team name in the input. Confirm typing works without any issue — the input is INSIDE the spotlight, fully accessible.
- [ ] **Step 10.5:** Click Create. Confirm the tour advances (navigate to `/teams/[id]`) and the next tooltip appears for the "New Project" button.
- [ ] **Step 10.6:** Click "New Project" → project form opens. Confirm the ENTIRE form card is spotlit. Type a project name. Confirm typing is accessible.
- [ ] **Step 10.7:** Click Create. Confirm the tour does NOT advance until the project is created. Once created, confirm the token modal appears AND the "Copy token" step activates immediately — not before.
- [ ] **Step 10.8:** Click "Copy token". Confirm advance to "I have saved this token". Click it. Confirm advance to project-open step.
- [ ] **Step 10.9:** Click the project link. Confirm navigation to `/projects/[id]` and nav-prompts step activates (sidebar spotlit).
- [ ] **Step 10.10:** Step through project-verdict, project-exceptions, project-spend-ring (click Next each time).
- [ ] **Step 10.11:** Confirm project-code-health spotlight appears on the Code Health ring card. Instruction mentions "A to F per signal" and "once a session has run". Click Next.
- [ ] **Step 10.12:** Confirm project-feature-progress spotlight appears on the Feature Progress Track card. Click Next.
- [ ] **Step 10.13:** Confirm project-repo-context spotlight appears on the Repo Context panel. Click Next.
- [ ] **Step 10.14:** Confirm nav-cost-dashboard is spotlit. Click Next. Confirm nav-install spotlit. Click it → navigate to `/install`.
- [ ] **Step 10.15:** Confirm done step tooltip appears centred on `/install`. Click "Go to dashboard". Confirm `hasOnboarded = true` (tour doesn't restart on next visit).

### Walk-through B: Feature tour (existing user, retake from profile)

- [ ] **Step 10.16:** Log in as MANAGER with existing team and project (and `hasOnboarded = true`).
- [ ] **Step 10.17:** Navigate to `/profile`. Click "Take the tour again →".
- [ ] **Step 10.18:** Confirm the feature-welcome tooltip appears centred on `/dashboard` with "Let's go" button. Confirm it does NOT show "Start" (that's the first-run welcome). Click "Let's go".
- [ ] **Step 10.19:** Confirm the feature-nav-project step: Teams sidebar link is spotlit. Tooltip reads "Open a project to explore its analytics — click Teams in the sidebar, then pick a project."
- [ ] **Step 10.20:** Click Teams → navigate to `/teams`. Click a team → navigate to `/teams/[id]`. Click a project → navigate to `/projects/[id]`. Confirm the tour advances automatically (navigate-advance fires).
- [ ] **Step 10.21:** Confirm nav-prompts is spotlit on the project page (sidebar still visible). Click Next.
- [ ] **Step 10.22:** Step through project-verdict, project-exceptions, project-spend-ring.
- [ ] **Step 10.23:** Confirm project-code-health, project-feature-progress, project-repo-context steps appear and can be stepped through with Next.
- [ ] **Step 10.24:** Complete nav-cost-dashboard → nav-install → done. Confirm tour completes cleanly.
- [ ] **Step 10.25:** Confirm the feature tour NEVER shows dashboard-cta, teams-new-team, teams-modal, project-create-modal, or project-token-copy steps at any point.

### Walk-through C: Esc / skip behaviour

- [ ] **Step 10.26:** Start a fresh first-run tour. At any step, press Esc. Confirm the tour exits cleanly and `hasOnboarded = true` (tour doesn't restart on reload).

### Walk-through D: Edge case — user already has data during first-run

- [ ] **Step 10.27:** Reset `hasOnboarded = false` for a user who ALREADY has a team and project. Do NOT write the feature kind cookie (no retake button pressed). Reload `/dashboard`.
- [ ] **Step 10.28:** Confirm the welcome step appears. Confirm dashboard-cta, teams-new-team, teams-modal, project-new-project, project-create-modal, project-token-copy, project-token-saved are all skipped (skipIf fires). Confirm project-open appears on `/teams/[id]` and the remaining steps complete normally.

- [ ] **Step 10.29: Final commit (post walk-through)**

After all 28 walk-through checks pass:

```bash
git push origin afthab/axis-pulse
```

---

## Task 11: Update docs

- [ ] **Step 11.1: Update `docs/architecture.md`**

In the Tour Engine section, update the step count from 15 to 21. Add a row to the component table for `TourProvider`: note it now reads `pulse-tour-kind` cookie to select first-run vs feature step set.

- [ ] **Step 11.2: Update `docs/structure.md`**

No new files added, but note that `data-tour` attributes are now on six additional components: `CodeHealthRing`, `FeatureAreaTrack`, `RepoContextPanel` (all return paths), the Teams NavLink wrapper in `AppShellClient`, the inner cards in `NewTeamDialog` and `NewProjectDialog`.

- [ ] **Step 11.3: Update `docs/code.md`**

Add to the tour lib section:
- `filterSteps(steps, role, tourMode, ctx)` — new signature
- `readTourKindCookie()`, `writeTourKindCookie(kind)`, `clearTourKindCookie()` — new cookie helpers
- `TourAdvanceMode` now includes `element-appears` variant

- [ ] **Step 11.4: Update `docs/decisions.md`**

Add ADR for the dual-tour split: why two tours instead of a single adaptive tour; why `element-appears` advance mode instead of a timeout/navigate approach; why `skipIf` was chosen over a separate pre-computed step list.

- [ ] **Step 11.5: Update `docs/glossary.md`**

Add:
- **first-run tour**: tour mode for users with `hasOnboarded=false` who have never set up Axis Pulse; includes team/project creation steps
- **feature tour**: tour mode activated by "Take the tour again" from /profile; educational steps only, skips all creation flows
- **element-appears**: a `TourAdvanceMode` that auto-advances a step when a separate DOM element appears, used to detect project creation without navigation

- [ ] **Step 11.6: Update `docs/risk.md`**

Note that the tourKind cookie is client-side only (no server validation). The worst a tampered cookie does is show the feature tour to a new user — a UX inconvenience, not a security risk. No new security risk added.

- [ ] **Step 11.7: Commit docs**

```bash
git add docs/
git commit -m "docs(tour): update 7 docs for dual-tour split, element-appears, 21-step inventory"
git push origin afthab/axis-pulse
```
