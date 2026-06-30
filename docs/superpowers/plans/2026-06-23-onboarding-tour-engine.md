# Onboarding Tour Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a cookie-persisted, cross-route onboarding tour engine that spotlights real UI elements, advances on real user actions, supports role-filtered steps, and is mounted in `AppShellClient` — rendering nothing when inactive (`hasOnboarded=true`).

**Architecture:** `TourProvider` (a client component) is mounted inside `AppShellClient`, receiving `hasOnboarded` and `role`. On first render with `hasOnboarded=false`, it reads the `pulse-tour-step` cookie to resume at the saved step. A `useReducer` state machine transitions through `inactive → waiting → polling → active → missing` states. An `setInterval` poll finds the target DOM element; once found, the overlay (4-sided dim divs + SVG spotlight ring) and tooltip card are rendered as portals. Advance detection is per-mode: click listener, `usePathname` watch, or "Next" button. Skip / Esc / completion call `PATCH /api/me/onboarding` and clear the cookie.

**Tech Stack:** React 18 hooks, `useReducer`, `document.cookie`, `setInterval` polling, `createPortal`, Next.js `usePathname`, no new npm dependencies.

---

## How Each Hard Problem Is Solved

### 1. Cross-route Persistence + Resume
`pulse-tour-step` is a non-httpOnly, same-origin, 24-hour cookie written via `document.cookie`. On every `TourProvider` mount (which happens on every full-page load since `AppShellClient` is a client component that React re-hydrates), the `useEffect([])` reads the cookie and calls `dispatch({ type: "INIT", stepIdx, isOnRoute })`. Within a session, React preserves `TourProvider` state across soft navigations because `AppShellClient` stays mounted in the layout tree. The cookie is the fallback for hard reloads.

### 2. Target-Not-Present Handling
`setInterval` at 200 ms polls `document.querySelector(step.selector)`. If the element appears, transitions to `active`. If 3 seconds elapse without finding the element, transitions to `missing` — the tooltip renders without a spotlight and always shows the Skip button, so the user is never trapped. The overlay is not rendered in `missing` state.

### 3. Real-Click Advance
When `status === "active"` and `step.advance.on === "click"`, a `useEffect` attaches an `{ once: true }` click listener to the target element. When fired, it dispatches `ADVANCE`. For `{ on: "navigate"; to }` steps, a `useEffect` watching `usePathname()` dispatches `ADVANCE` when pathname matches `step.advance.to`. For `{ on: "next" }` steps, the "Next →" button in `TourTooltip` calls `onNext` directly.

### 4. Role-Based Step Filtering
`filterSteps(steps, role)` (a pure function) filters the master step list by `step.roles` (if absent, step shows to all roles). The filtered list is computed once from `TOUR_STEPS` and `role` at the top of `TourProvider`. Steps can also carry `stub?: { condition, instruction }` — when the condition returns false for the current context, the tooltip shows the stub instruction instead. The step model interface supports this; Phase 3 will supply real conditions.

### 5. Never Trap
The overlay is 4 separate `position: fixed` divs (top/bottom/left/right) with `background: rgba(0,0,0,0.65)`. They surround but do not cover the spotlight target. The spotlight `div` renders at z-index 9999 with `border: 2px solid #0ee29e; border-radius: 8px; pointer-events: none`. The tooltip is at z-index 10000 with `tabIndex={-1}` and `role="dialog"`. "Skip tour" is always visible. Esc calls `skip()`. In `missing` state the overlay is skipped entirely — just the tooltip with Skip. `@media (prefers-reduced-motion: reduce)` removes the ring's pulse animation.

### 6. Accessibility
`useEffect` fires `tooltipRef.current?.focus()` via `requestAnimationFrame` on every step activation and on `missing`. The tooltip has `role="dialog"` and `aria-label="Tour step N of M"`. Keyboard: Esc = skip (global `keydown` listener active whenever status ≠ inactive). Tab is not trapped by default; a lightweight focus-trap could be added in Phase 3 if needed.

---

## Step Model Interface

```typescript
// src/lib/tour/types.ts
export type TourRole = "MANAGER" | "LINE_MANAGER" | "MEMBER"

export type TourAdvanceMode =
  | { on: "click" }
  | { on: "navigate"; to: string }
  | { on: "next" }

export type TourRenderContext = {
  role: TourRole
  hasTeam: boolean
  hasProject: boolean
}

export type TourStep = {
  id: string
  route: string                       // pathname this step lives on, e.g. "/dashboard"
  selector: string                    // CSS selector for the target, e.g. "[data-tour='nav-teams']"
  instruction: string                 // one sentence shown in tooltip body
  advance: TourAdvanceMode
  roles?: TourRole[]                  // if absent, shown to all roles
  stub?: {
    condition: (ctx: TourRenderContext) => boolean
    instruction: string               // shown instead when condition is false
  }
}

// Engine state
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

---

## File Map

**Create:**
- `src/lib/tour/types.ts` — all types above
- `src/lib/tour/cookie.ts` — `readTourCookie()`, `writeTourCookie(id)`, `clearTourCookie()`
- `src/lib/tour/filter.ts` — `filterSteps(steps, role): TourStep[]`
- `src/lib/tour/reducer.ts` — `tourReducer(state, action): EngineState`
- `src/lib/tour/steps.ts` — 3 placeholder steps proving cross-route persistence
- `src/components/tour/TourContext.ts` — `TourContext` (React context)
- `src/components/tour/TourOverlay.tsx` — 4-sided dim + SVG spotlight ring
- `src/components/tour/TourTooltip.tsx` — tooltip card (step counter, instruction, Skip/Next)
- `src/components/tour/TourProvider.tsx` — full engine: reducer, polling, advance, a11y, PATCH
- `src/tests/tour-engine.test.ts` — Vitest unit tests for cookie, filter, reducer

**Modify:**
- `src/components/AppShellClient.tsx` — add `TourProvider` wrapper + 3 `data-tour` attributes for placeholder targets
- `src/app/globals.css` — add `@keyframes tour-ring-pulse` for spotlight ring animation

**Docs (Task 5):**
- `docs/structure.md` — new tour lib + component entries
- `docs/architecture.md` — Onboarding Tour Engine section
- `docs/decisions.md` — ADR: cookie vs localStorage vs DB for step persistence
- `docs/code.md` — tour module exports

---

## Task 1: Tour Engine Core

Everything in `src/lib/tour/`, `src/components/tour/`, and the test file. The goal is a working engine with placeholder steps that runs in isolation.

**Files:**
- Create: `src/lib/tour/types.ts`
- Create: `src/lib/tour/cookie.ts`
- Create: `src/lib/tour/filter.ts`
- Create: `src/lib/tour/reducer.ts`
- Create: `src/lib/tour/steps.ts`
- Create: `src/components/tour/TourContext.ts`
- Create: `src/components/tour/TourOverlay.tsx`
- Create: `src/components/tour/TourTooltip.tsx`
- Create: `src/components/tour/TourProvider.tsx`
- Create: `src/tests/tour-engine.test.ts`

---

- [ ] **Step 1.1: Write failing tests for cookie utilities**

Create `src/tests/tour-engine.test.ts`:

```typescript
// src/tests/tour-engine.test.ts
import { describe, it, expect, beforeEach, vi, afterEach } from "vitest"

// ---- cookie ----
describe("tour cookie utilities", () => {
  beforeEach(() => {
    // Clear all cookies
    Object.defineProperty(document, "cookie", {
      writable: true,
      value: "",
    })
  })

  it("readTourCookie returns null when cookie absent", async () => {
    const { readTourCookie } = await import("@/lib/tour/cookie")
    expect(readTourCookie()).toBeNull()
  })

  it("writeTourCookie sets and readTourCookie reads the step id", async () => {
    const { readTourCookie, writeTourCookie } = await import("@/lib/tour/cookie")
    writeTourCookie("welcome")
    expect(readTourCookie()).toBe("welcome")
  })

  it("clearTourCookie removes the cookie", async () => {
    const { readTourCookie, writeTourCookie, clearTourCookie } = await import("@/lib/tour/cookie")
    writeTourCookie("nav-teams")
    clearTourCookie()
    expect(readTourCookie()).toBeNull()
  })
})

// ---- filterSteps ----
describe("filterSteps", () => {
  it("returns all steps when roles is absent", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "a", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
      { id: "b", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
    ]
    expect(filterSteps(steps, "MEMBER")).toHaveLength(2)
  })

  it("filters out steps not matching role", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "mgr", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["MANAGER" as const] },
      { id: "all", route: "/", selector: "", instruction: "", advance: { on: "next" as const } },
    ]
    const result = filterSteps(steps, "MEMBER")
    expect(result).toHaveLength(1)
    expect(result[0]!.id).toBe("all")
  })

  it("includes step when role is in roles array", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "lm", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["LINE_MANAGER" as const, "MANAGER" as const] },
    ]
    expect(filterSteps(steps, "LINE_MANAGER")).toHaveLength(1)
    expect(filterSteps(steps, "MEMBER")).toHaveLength(0)
  })
})

// ---- tourReducer ----
describe("tourReducer", () => {
  it("INIT with isOnRoute=true → polling", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const initial = { status: "inactive" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(initial, { type: "INIT", stepIdx: 0, isOnRoute: true })
    expect(next.status).toBe("polling")
    expect(next.stepIdx).toBe(0)
    expect(next.targetRect).toBeNull()
  })

  it("INIT with isOnRoute=false → waiting", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const initial = { status: "inactive" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(initial, { type: "INIT", stepIdx: 2, isOnRoute: false })
    expect(next.status).toBe("waiting")
    expect(next.stepIdx).toBe(2)
  })

  it("TARGET_FOUND → active with rect", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const rect = { top: 10, left: 20, width: 100, height: 50 } as DOMRect
    const state = { status: "polling" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "TARGET_FOUND", rect })
    expect(next.status).toBe("active")
    expect(next.targetRect).toBe(rect)
  })

  it("TARGET_MISSING → missing", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "polling" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "TARGET_MISSING" })
    expect(next.status).toBe("missing")
    expect(next.targetRect).toBeNull()
  })

  it("ADVANCE with isOnRoute=true → polling at next step", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "active" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "ADVANCE", nextIdx: 1, isOnRoute: true })
    expect(next.status).toBe("polling")
    expect(next.stepIdx).toBe(1)
  })

  it("ADVANCE with isOnRoute=false → waiting at next step", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "active" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "ADVANCE", nextIdx: 1, isOnRoute: false })
    expect(next.status).toBe("waiting")
    expect(next.stepIdx).toBe(1)
  })

  it("EXIT → inactive", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "active" as const, stepIdx: 2, targetRect: null }
    const next = tourReducer(state, { type: "EXIT" })
    expect(next.status).toBe("inactive")
    expect(next.stepIdx).toBe(0)
  })

  it("ARRIVED_ON_ROUTE when waiting → polling", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "waiting" as const, stepIdx: 1, targetRect: null }
    const next = tourReducer(state, { type: "ARRIVED_ON_ROUTE" })
    expect(next.status).toBe("polling")
  })

  it("TARGET_MOVED → updates targetRect, status stays active", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const rect = { top: 50, left: 80, width: 200, height: 40 } as DOMRect
    const state = { status: "active" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "TARGET_MOVED", rect })
    expect(next.status).toBe("active")
    expect(next.targetRect).toBe(rect)
  })
})
```

- [ ] **Step 1.2: Run tests to confirm they fail**

```
npm test -- tour-engine
```
Expected: FAIL — modules not found.

---

- [ ] **Step 1.3: Create `src/lib/tour/types.ts`**

```typescript
// src/lib/tour/types.ts
export type TourRole = "MANAGER" | "LINE_MANAGER" | "MEMBER"

export type TourAdvanceMode =
  | { on: "click" }
  | { on: "navigate"; to: string }
  | { on: "next" }

export type TourRenderContext = {
  role: TourRole
  hasTeam: boolean
  hasProject: boolean
}

export type TourStep = {
  id: string
  route: string
  selector: string
  instruction: string
  advance: TourAdvanceMode
  roles?: TourRole[]
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

---

- [ ] **Step 1.4: Create `src/lib/tour/cookie.ts`**

The cookie is non-httpOnly (JS-readable), first-party, SameSite=Lax, 24-hour max-age. No secure flag needed — the CSRF-protected session handles security; this cookie stores only a step ID string.

```typescript
// src/lib/tour/cookie.ts
const COOKIE_NAME = "pulse-tour-step"
const MAX_AGE = 86_400 // 24 hours

export function readTourCookie(): string | null {
  if (typeof document === "undefined") return null
  const pair = document.cookie
    .split(";")
    .find((c) => c.trim().startsWith(COOKIE_NAME + "="))
  if (!pair) return null
  const val = pair.split("=")[1]?.trim() ?? ""
  const decoded = decodeURIComponent(val)
  return decoded || null
}

export function writeTourCookie(stepId: string): void {
  if (typeof document === "undefined") return
  document.cookie = `${COOKIE_NAME}=${encodeURIComponent(stepId)}; path=/; max-age=${MAX_AGE}; SameSite=Lax`
}

export function clearTourCookie(): void {
  if (typeof document === "undefined") return
  document.cookie = `${COOKIE_NAME}=; path=/; max-age=0; SameSite=Lax`
}
```

---

- [ ] **Step 1.5: Create `src/lib/tour/filter.ts`**

```typescript
// src/lib/tour/filter.ts
import type { TourRole, TourStep } from "./types"

export function filterSteps(steps: TourStep[], role: TourRole): TourStep[] {
  return steps.filter((s) => !s.roles || s.roles.includes(role))
}
```

---

- [ ] **Step 1.6: Create `src/lib/tour/reducer.ts`**

```typescript
// src/lib/tour/reducer.ts
import type { EngineAction, EngineState } from "./types"

const INITIAL: EngineState = { status: "inactive", stepIdx: 0, targetRect: null }

export function tourReducer(state: EngineState, action: EngineAction): EngineState {
  switch (action.type) {
    case "INIT":
      return {
        status: action.isOnRoute ? "polling" : "waiting",
        stepIdx: action.stepIdx,
        targetRect: null,
      }
    case "ARRIVED_ON_ROUTE":
      if (state.status !== "waiting") return state
      return { ...state, status: "polling" }
    case "TARGET_FOUND":
      return { ...state, status: "active", targetRect: action.rect }
    case "TARGET_MISSING":
      return { ...state, status: "missing", targetRect: null }
    case "TARGET_MOVED":
      if (state.status !== "active") return state
      return { ...state, targetRect: action.rect }
    case "ADVANCE":
      return {
        status: action.isOnRoute ? "polling" : "waiting",
        stepIdx: action.nextIdx,
        targetRect: null,
      }
    case "EXIT":
      return { ...INITIAL }
    default:
      return state
  }
}
```

---

- [ ] **Step 1.7: Run tests again — cookie, filter, reducer should pass**

```
npm test -- tour-engine
```
Expected: all 12 tests PASS (cookie 3, filterSteps 3, tourReducer 9).

Note: the cookie tests mock `document.cookie` with `Object.defineProperty`. In JSDOM (Vitest's default environment for unit tests without explicit config), `document.cookie` is a read-write property. The tests use `writable: true` to override it. However, JSDOM's `document.cookie` does have a setter — if the tests fail with "Cannot redefine", remove the `beforeEach` `Object.defineProperty` and instead rely on JSDOM's built-in cookie handling (set via `document.cookie = "..."` and clear via `document.cookie = "name=; max-age=0"`).

If JSDOM cookie handling works end-to-end, replace the `beforeEach` with:
```typescript
beforeEach(() => {
  // Clear cookies by expiring each one found
  document.cookie.split(";").forEach((c) => {
    const name = c.split("=")[0]?.trim()
    if (name) document.cookie = `${name}=; max-age=0; path=/`
  })
})
```

---

- [ ] **Step 1.8: Create placeholder steps `src/lib/tour/steps.ts`**

These 3 steps prove: informational "next" advance on `/dashboard`, click advance on `/dashboard`, and cross-route resume on `/teams`. The selectors target `data-tour` attributes that will be added to `AppShellClient` in Task 2.

```typescript
// src/lib/tour/steps.ts
import type { TourStep } from "./types"

export const TOUR_STEPS: TourStep[] = [
  {
    id: "welcome",
    route: "/dashboard",
    selector: "[data-tour='brand-name']",
    instruction: "Welcome to Axis Pulse — your AI-powered project intelligence dashboard.",
    advance: { on: "next" },
  },
  {
    id: "nav-teams",
    route: "/dashboard",
    selector: "[data-tour='nav-teams']",
    instruction: "Click Teams to explore your team workspaces.",
    advance: { on: "click" },
  },
  {
    id: "teams-landing",
    route: "/teams",
    selector: "[data-tour='main-content']",
    instruction: "You made it to Teams! Your tour progress was saved across the navigation.",
    advance: { on: "next" },
  },
]
```

---

- [ ] **Step 1.9: Create `src/components/tour/TourContext.ts`**

```typescript
// src/components/tour/TourContext.ts
import { createContext, useContext } from "react"

type TourContextValue = {
  skip: () => Promise<void>
}

export const TourContext = createContext<TourContextValue>({
  skip: async () => {},
})

export function useTour(): TourContextValue {
  return useContext(TourContext)
}
```

---

- [ ] **Step 1.10: Create `src/components/tour/TourOverlay.tsx`**

4-sided dim divs. When `targetRect` is null (step is `missing`), renders nothing (no overlay — user can interact freely). The spotlight ring has a `tour-ring-pulse` animation that is disabled under `prefers-reduced-motion`.

```tsx
// src/components/tour/TourOverlay.tsx
"use client"
import { useEffect, useState } from "react"

type Props = {
  targetRect: DOMRect | null
  padding?: number
}

const DIM = "rgba(0,0,0,0.65)"

export function TourOverlay({ targetRect, padding = 8 }: Props) {
  const [vpW, setVpW] = useState(() =>
    typeof window !== "undefined" ? window.innerWidth : 0
  )
  const [vpH, setVpH] = useState(() =>
    typeof window !== "undefined" ? window.innerHeight : 0
  )
  const [reducedMotion, setReducedMotion] = useState(false)

  useEffect(() => {
    function handleResize() {
      setVpW(window.innerWidth)
      setVpH(window.innerHeight)
    }
    handleResize()
    window.addEventListener("resize", handleResize)
    const mq = window.matchMedia("(prefers-reduced-motion: reduce)")
    setReducedMotion(mq.matches)
    const mqHandler = (e: MediaQueryListEvent) => setReducedMotion(e.matches)
    mq.addEventListener("change", mqHandler)
    return () => {
      window.removeEventListener("resize", handleResize)
      mq.removeEventListener("change", mqHandler)
    }
  }, [])

  if (!targetRect || vpW === 0) return null

  const sx = Math.max(0, targetRect.left - padding)
  const sy = Math.max(0, targetRect.top - padding)
  const sw = targetRect.width + padding * 2
  const sh = targetRect.height + padding * 2

  const base: React.CSSProperties = {
    position: "fixed",
    background: DIM,
    zIndex: 9998,
    pointerEvents: "all",
  }

  return (
    <>
      {/* Top panel */}
      <div style={{ ...base, top: 0, left: 0, right: 0, height: sy }} />
      {/* Bottom panel */}
      <div style={{ ...base, top: sy + sh, left: 0, right: 0, bottom: 0 }} />
      {/* Left panel */}
      <div style={{ ...base, top: sy, left: 0, width: sx, height: sh }} />
      {/* Right panel */}
      <div style={{ ...base, top: sy, left: sx + sw, right: 0, height: sh }} />
      {/* Spotlight ring */}
      <div
        style={{
          position: "fixed",
          top: sy,
          left: sx,
          width: sw,
          height: sh,
          zIndex: 9999,
          border: "2px solid #0ee29e",
          borderRadius: "8px",
          pointerEvents: "none",
          boxSizing: "border-box",
          animation: reducedMotion ? undefined : "tour-ring-pulse 2s ease-in-out infinite",
        }}
        aria-hidden="true"
      />
    </>
  )
}
```

Note: `vpH` is computed but not used in the 4-panel layout (overflow is clipped by `fixed` positioning). Keep the state for completeness and potential future use.

---

- [ ] **Step 1.11: Add `@keyframes tour-ring-pulse` to `src/app/globals.css`**

Append at end of file:

```css
/* Tour spotlight ring pulse — disabled by prefers-reduced-motion */
@keyframes tour-ring-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(14, 226, 158, 0.4); }
  50%       { box-shadow: 0 0 0 5px rgba(14, 226, 158, 0.1); }
}
```

---

- [ ] **Step 1.12: Create `src/components/tour/TourTooltip.tsx`**

Tooltip positions itself below the target (or above if near viewport bottom). Centers horizontally to the target, clamped to viewport. When `targetRect` is null (missing state), centres in viewport.

```tsx
// src/components/tour/TourTooltip.tsx
"use client"
import type { RefObject } from "react"
import type { TourStep } from "@/lib/tour/types"

const TOOLTIP_W = 320
const TOOLTIP_H_EST = 130 // estimated height for placement

type Props = {
  step: TourStep
  stepNumber: number
  totalSteps: number
  targetRect: DOMRect | null
  onNext?: () => void
  onSkip: () => void
  tooltipRef: RefObject<HTMLDivElement | null>
}

function computePosition(targetRect: DOMRect | null): { top: number; left: number } {
  if (typeof window === "undefined") return { top: 0, left: 0 }
  const vpW = window.innerWidth
  const vpH = window.innerHeight

  if (!targetRect) {
    return {
      top: vpH / 2 - TOOLTIP_H_EST / 2,
      left: vpW / 2 - TOOLTIP_W / 2,
    }
  }

  const GAP = 12
  let top = targetRect.bottom + GAP
  if (top + TOOLTIP_H_EST > vpH - 16) {
    top = targetRect.top - TOOLTIP_H_EST - GAP
  }
  top = Math.max(16, top)

  let left = targetRect.left
  if (left + TOOLTIP_W > vpW - 16) left = vpW - TOOLTIP_W - 16
  left = Math.max(16, left)

  return { top, left }
}

export function TourTooltip({
  step,
  stepNumber,
  totalSteps,
  targetRect,
  onNext,
  onSkip,
  tooltipRef,
}: Props) {
  const { top, left } = computePosition(targetRect)
  const showNext = step.advance.on === "next"
  const showHint = step.advance.on === "click"

  return (
    <div
      ref={tooltipRef}
      tabIndex={-1}
      role="dialog"
      aria-label={`Tour step ${stepNumber} of ${totalSteps}`}
      style={{
        position: "fixed",
        top,
        left,
        width: TOOLTIP_W,
        background: "#0d1117",
        border: "1px solid rgba(14,226,158,0.25)",
        borderRadius: "10px",
        padding: "14px 16px 12px",
        zIndex: 10000,
        boxShadow: "0 4px 24px rgba(0,0,0,0.7), 0 0 0 1px rgba(14,226,158,0.08)",
        outline: "none",
      }}
    >
      {/* Step counter — IBM Plex Mono */}
      <div
        style={{
          fontFamily: "var(--font-mono, monospace)",
          fontSize: "10px",
          fontWeight: 600,
          color: "#0ee29e",
          letterSpacing: "0.1em",
          marginBottom: "8px",
          textTransform: "uppercase",
        }}
      >
        Step {stepNumber} / {totalSteps}
      </div>

      {/* Instruction — Plus Jakarta Sans */}
      <p
        style={{
          margin: "0 0 12px 0",
          fontSize: "13.5px",
          lineHeight: 1.55,
          color: "rgba(237,240,250,0.9)",
          fontFamily: "var(--font-sans, sans-serif)",
        }}
      >
        {step.instruction}
      </p>

      {/* Actions row */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <button
          onClick={onSkip}
          style={{
            background: "transparent",
            border: "none",
            padding: 0,
            fontSize: "11.5px",
            color: "rgba(237,240,250,0.38)",
            cursor: "pointer",
            fontFamily: "inherit",
          }}
        >
          Skip tour
        </button>

        {showNext && onNext && (
          <button
            onClick={onNext}
            style={{
              background: "#0ee29e",
              color: "#060810",
              border: "none",
              borderRadius: "6px",
              padding: "5px 14px",
              fontSize: "12.5px",
              fontWeight: 700,
              cursor: "pointer",
              fontFamily: "inherit",
              letterSpacing: "0.01em",
            }}
          >
            Next →
          </button>
        )}

        {showHint && (
          <span
            style={{
              fontSize: "11px",
              color: "rgba(14,226,158,0.6)",
              fontFamily: "var(--font-mono, monospace)",
            }}
          >
            click to continue
          </span>
        )}
      </div>
    </div>
  )
}
```

---

- [ ] **Step 1.13: Create `src/components/tour/TourProvider.tsx`**

This is the engine. Mount point: inside `AppShellClient` wrapping `children`. It is purely additive — when `hasOnboarded=true`, it renders `children` unchanged.

```tsx
// src/components/tour/TourProvider.tsx
"use client"
import { useCallback, useEffect, useReducer, useRef } from "react"
import { createPortal } from "react-dom"
import { usePathname } from "next/navigation"
import type { ReactNode } from "react"
import type { TourRole, TourStep } from "@/lib/tour/types"
import { tourReducer } from "@/lib/tour/reducer"
import { clearTourCookie, readTourCookie, writeTourCookie } from "@/lib/tour/cookie"
import { filterSteps } from "@/lib/tour/filter"
import { TOUR_STEPS } from "@/lib/tour/steps"
import { TourContext } from "./TourContext"
import { TourOverlay } from "./TourOverlay"
import { TourTooltip } from "./TourTooltip"

const POLL_MS = 200
const POLL_TIMEOUT_MS = 3_000

async function patchOnboarded(): Promise<void> {
  try {
    await fetch("/api/me/onboarding", {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ hasOnboarded: true }),
    })
  } catch {
    // fail-open: cookie is already cleared, so tour won't re-show
  }
}

export function TourProvider({
  hasOnboarded,
  role,
  children,
}: {
  hasOnboarded: boolean
  role: TourRole
  children: ReactNode
}) {
  const pathname = usePathname()
  const steps = filterSteps(TOUR_STEPS, role)

  const [state, dispatch] = useReducer(tourReducer, {
    status: "inactive",
    stepIdx: 0,
    targetRect: null,
  })

  // Refs for values needed inside intervals (avoid stale closures)
  const pathnameRef = useRef(pathname)
  const stepsRef = useRef(steps)
  const stateRef = useRef(state)
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const pollStartRef = useRef(0)
  const tooltipRef = useRef<HTMLDivElement | null>(null)
  const mountedRef = useRef(false)

  useEffect(() => { pathnameRef.current = pathname }, [pathname])
  useEffect(() => { stepsRef.current = steps }, [steps])
  useEffect(() => { stateRef.current = state }, [state])

  function stopPolling() {
    if (pollTimerRef.current) {
      clearInterval(pollTimerRef.current)
      pollTimerRef.current = null
    }
  }

  function focusTooltip() {
    requestAnimationFrame(() => tooltipRef.current?.focus())
  }

  function beginPoll(idx: number) {
    stopPolling()
    pollStartRef.current = Date.now()
    pollTimerRef.current = setInterval(() => {
      const step = stepsRef.current[idx]
      if (!step) { stopPolling(); return }
      const el = document.querySelector(step.selector)
      if (el) {
        stopPolling()
        dispatch({ type: "TARGET_FOUND", rect: el.getBoundingClientRect() })
        focusTooltip()
      } else if (Date.now() - pollStartRef.current > POLL_TIMEOUT_MS) {
        stopPolling()
        dispatch({ type: "TARGET_MISSING" })
        focusTooltip()
      }
    }, POLL_MS)
  }

  function activateStep(idx: number) {
    const step = stepsRef.current[idx]
    if (!step) return
    writeTourCookie(step.id)
    const isOnRoute = step.route === pathnameRef.current
    dispatch({ type: "INIT", stepIdx: idx, isOnRoute })
    if (isOnRoute) beginPoll(idx)
  }

  const advance = useCallback((fromIdx: number) => {
    const nextIdx = fromIdx + 1
    if (nextIdx >= stepsRef.current.length) {
      void doExit(true)
      return
    }
    const nextStep = stepsRef.current[nextIdx]
    if (!nextStep) return
    writeTourCookie(nextStep.id)
    const isOnRoute = nextStep.route === pathnameRef.current
    dispatch({ type: "ADVANCE", nextIdx, isOnRoute })
    if (isOnRoute) beginPoll(nextIdx)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function doExit(complete: boolean) {
    stopPolling()
    clearTourCookie()
    dispatch({ type: "EXIT" })
    if (complete) await patchOnboarded()
    else await patchOnboarded() // skip also marks onboarded
  }

  const skip = useCallback(async () => {
    await doExit(false)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // MOUNT: initialise tour
  useEffect(() => {
    if (mountedRef.current) return
    mountedRef.current = true
    if (hasOnboarded || steps.length === 0) return

    const savedId = readTourCookie()
    if (savedId) {
      const idx = steps.findIndex((s) => s.id === savedId)
      activateStep(idx >= 0 ? idx : 0)
    } else {
      activateStep(0)
    }

    return () => stopPolling()
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // ROUTE CHANGE: begin polling when we arrive on the step's route
  useEffect(() => {
    if (state.status !== "waiting") return
    const step = steps[state.stepIdx]
    if (!step) return
    if (step.route === pathname) {
      dispatch({ type: "ARRIVED_ON_ROUTE" })
      beginPoll(state.stepIdx)
    }
  }, [pathname]) // eslint-disable-line react-hooks/exhaustive-deps

  // CLICK ADVANCE: attach once listener when active with "click" mode
  useEffect(() => {
    if (state.status !== "active") return
    const step = steps[state.stepIdx]
    if (!step || step.advance.on !== "click") return
    const el = document.querySelector(step.selector)
    if (!el) return
    const handler = () => advance(state.stepIdx)
    el.addEventListener("click", handler, { once: true })
    return () => el.removeEventListener("click", handler)
  }, [state.status, state.stepIdx, advance])

  // RESIZE / SCROLL: re-measure target rect
  useEffect(() => {
    if (state.status !== "active") return
    const step = steps[state.stepIdx]
    if (!step) return
    function updateRect() {
      const el = document.querySelector(step!.selector)
      if (el) dispatch({ type: "TARGET_MOVED", rect: el.getBoundingClientRect() })
    }
    window.addEventListener("resize", updateRect)
    window.addEventListener("scroll", updateRect, { capture: true })
    return () => {
      window.removeEventListener("resize", updateRect)
      window.removeEventListener("scroll", updateRect, { capture: true })
    }
  }, [state.status, state.stepIdx])

  // KEYBOARD: Esc = skip
  useEffect(() => {
    if (state.status === "inactive") return
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") void skip()
    }
    document.addEventListener("keydown", onKeyDown)
    return () => document.removeEventListener("keydown", onKeyDown)
  }, [state.status, skip])

  const step = steps[state.stepIdx]
  const isVisible = state.status !== "inactive" && step != null

  return (
    <TourContext.Provider value={{ skip }}>
      {children}
      {isVisible &&
        typeof document !== "undefined" &&
        createPortal(
          <>
            <TourOverlay
              targetRect={state.status === "active" ? state.targetRect : null}
            />
            <TourTooltip
              step={step}
              stepNumber={state.stepIdx + 1}
              totalSteps={steps.length}
              targetRect={state.status === "active" ? state.targetRect : null}
              onNext={step.advance.on === "next" ? () => advance(state.stepIdx) : undefined}
              onSkip={skip}
              tooltipRef={tooltipRef}
            />
          </>,
          document.body
        )}
    </TourContext.Provider>
  )
}
```

Key decisions:
- `createPortal(..., document.body)` renders overlay + tooltip outside the `AppShellClient` layout tree, so z-index stacking works correctly regardless of layout transforms.
- `mountedRef.current` guard prevents double-init in React Strict Mode (two mount/unmount cycles in development).
- `patchOnboarded()` is called for both skip and complete — once the tour is done or dismissed, `hasOnboarded` is set to true so the tour doesn't re-appear on next login.

---

- [ ] **Step 1.14: Run full tests**

```
npm test -- tour-engine
```
Expected: 12 tests PASS. (No new tests for the components — those are validated visually in Task 2 wiring.)

---

- [ ] **Step 1.15: Commit Task 1**

```bash
git add src/lib/tour/ src/components/tour/ src/tests/tour-engine.test.ts src/app/globals.css
git commit -m "feat(tour): add onboarding tour engine core (TourProvider, overlay, tooltip, reducer, cookie, filter)"
```

---

## Task 2: Wire into AppShellClient

Mount `TourProvider` in `AppShellClient` and add 3 `data-tour` attributes for the placeholder steps to target.

**Files:**
- Modify: `src/components/AppShellClient.tsx`

---

- [ ] **Step 2.1: Modify `src/components/AppShellClient.tsx`**

Three changes:

**a. Import TourProvider**
```typescript
import { TourProvider } from "@/components/tour/TourProvider"
```

**b. Remove `void hasOnboarded` placeholder and wrap the return JSX**

The return statement's `<TourContext.Provider value={{ skip }}>{children}` wrapping in `TourProvider.tsx` means we need to wrap the `AppShellClient` render. The cleanest way is: wrap the outermost `<div>` in `<TourProvider hasOnboarded={hasOnboarded} role={role}>`.

Change the return from:
```tsx
return (
  <div style={{ display: "flex", ... }}>
    ...
  </div>
)
```
To:
```tsx
return (
  <TourProvider hasOnboarded={hasOnboarded} role={role}>
    <div style={{ display: "flex", ... }}>
      ...
    </div>
  </TourProvider>
)
```

Also remove the `void hasOnboarded` line at the top of the function body.

**c. Add `data-tour` attributes to 3 placeholder targets:**

1. Brand Link (`axis pulse` text) — add `data-tour="brand-name"`:
   Find: `<Link href="/dashboard" style={{ ... }} aria-label="Go to dashboard">`
   Add: `data-tour="brand-name"` to the Link element.

2. Teams `NavLink` — the NavLink component renders an `<a>`. We cannot pass `data-tour` through the custom NavLink component without modifying it. Instead, wrap the Teams NavLink in a `<div data-tour="nav-teams">`:
   Find: `<NavLink href="/teams" label="Teams" icon={<UsersIcon />} />`
   Wrap as: `<div data-tour="nav-teams"><NavLink href="/teams" label="Teams" icon={<UsersIcon />} /></div>`

3. Main content `<main>` element — add `data-tour="main-content"`:
   Find: `<main style={{ flex: 1, overflowY: "auto", minWidth: 0 }}>`
   Add: `data-tour="main-content"` to the `<main>` element.

---

- [ ] **Step 2.2: Run tsc**

```
npx tsc --noEmit
```
Expected: 0 errors in `src/` (errors in `src/tests/` are pre-existing).

---

- [ ] **Step 2.3: Run full test suite**

```
npm test
```
Expected: all existing tests pass + 12 tour-engine tests pass. No regressions.

---

- [ ] **Step 2.4: Commit Task 2**

```bash
git add src/components/AppShellClient.tsx
git commit -m "feat(tour): wire TourProvider into AppShellClient with placeholder data-tour targets"
```

---

## Task 3: Unit Tests Expansion

Add tests for edge cases not covered in Step 1.1.

**Files:**
- Modify: `src/tests/tour-engine.test.ts`

---

- [ ] **Step 3.1: Add edge-case tests for filterSteps and reducer**

Add to the existing test file:

```typescript
// additional filterSteps tests
describe("filterSteps — edge cases", () => {
  it("returns empty array when no steps match role", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    const steps = [
      { id: "mgr", route: "/", selector: "", instruction: "", advance: { on: "next" as const }, roles: ["MANAGER" as const] },
    ]
    expect(filterSteps(steps, "MEMBER")).toHaveLength(0)
  })

  it("handles empty steps array", async () => {
    const { filterSteps } = await import("@/lib/tour/filter")
    expect(filterSteps([], "MANAGER")).toHaveLength(0)
  })
})

// additional reducer tests
describe("tourReducer — edge cases", () => {
  it("ignores TARGET_FOUND when status is not polling", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const rect = { top: 0, left: 0, width: 10, height: 10 } as DOMRect
    const state = { status: "inactive" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "TARGET_FOUND", rect })
    // Should transition — TARGET_FOUND doesn't check current status
    // (polling timer fires even after navigation edge cases, guard is in the timer callback)
    expect(next.status).toBe("active")
  })

  it("ARRIVED_ON_ROUTE ignored when not waiting", async () => {
    const { tourReducer } = await import("@/lib/tour/reducer")
    const state = { status: "active" as const, stepIdx: 0, targetRect: null }
    const next = tourReducer(state, { type: "ARRIVED_ON_ROUTE" })
    expect(next.status).toBe("active") // unchanged
  })
})

// TOUR_STEPS structure
describe("TOUR_STEPS placeholder steps", () => {
  it("all steps have required fields", async () => {
    const { TOUR_STEPS } = await import("@/lib/tour/steps")
    for (const step of TOUR_STEPS) {
      expect(typeof step.id).toBe("string")
      expect(typeof step.route).toBe("string")
      expect(typeof step.selector).toBe("string")
      expect(typeof step.instruction).toBe("string")
      expect(step.advance).toBeDefined()
    }
  })

  it("step ids are unique", async () => {
    const { TOUR_STEPS } = await import("@/lib/tour/steps")
    const ids = TOUR_STEPS.map((s) => s.id)
    expect(new Set(ids).size).toBe(ids.length)
  })
})
```

- [ ] **Step 3.2: Run tests**

```
npm test -- tour-engine
```
Expected: all tests PASS (original 12 + new ~8 = ~20 tests).

---

- [ ] **Step 3.3: Commit Task 3**

```bash
git add src/tests/tour-engine.test.ts
git commit -m "test(tour): expand tour-engine unit tests — edge cases, step structure invariants"
```

---

## Task 4: Compile + Build Verification

- [ ] **Step 4.1: TypeScript**

```
npx tsc --noEmit
```
Expected: 0 errors in `src/` (ignore pre-existing `src/tests/` errors).

- [ ] **Step 4.2: Full test suite**

```
npm test
```
Expected: all tests pass (no regressions, all tour-engine tests green).

- [ ] **Step 4.3: Production build**

```
npm run build
```
Expected: exits 0. No unused-var ESLint errors. If `vpH` in `TourOverlay.tsx` triggers `no-unused-vars`, remove the state or suppress:
```typescript
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const [vpH, setVpH] = useState(...)
```
Or simplify — just remove `vpH` tracking since it's not used.

- [ ] **Step 4.4: Push**

```
git push origin afthab/axis-pulse
```

---

## Task 5: Docs Update

- [ ] **Step 5.1: Update `docs/structure.md`**

Add to `src/lib/` section:
```markdown
| [`tour/types.ts`](src/lib/tour/types.ts) | `TourRole`, `TourStep`, `TourAdvanceMode`, `EngineState`, `EngineAction` — all types for the onboarding tour engine |
| [`tour/cookie.ts`](src/lib/tour/cookie.ts) | `readTourCookie()`, `writeTourCookie(id)`, `clearTourCookie()` — first-party cookie management for tour step persistence |
| [`tour/filter.ts`](src/lib/tour/filter.ts) | `filterSteps(steps, role)` — role-based step filtering |
| [`tour/reducer.ts`](src/lib/tour/reducer.ts) | `tourReducer(state, action)` — pure state machine for tour engine transitions |
| [`tour/steps.ts`](src/lib/tour/steps.ts) | `TOUR_STEPS` — master step list; Phase 1 contains 3 placeholder steps; Phase 3 replaces with real step content |
```

Add to `src/components/` section:
```markdown
| [`tour/TourContext.ts`](src/components/tour/TourContext.ts) | `TourContext`, `useTour()` — React context for tour engine |
| [`tour/TourOverlay.tsx`](src/components/tour/TourOverlay.tsx) | 4-sided dim overlay with spotlight cutout and teal ring |
| [`tour/TourTooltip.tsx`](src/components/tour/TourTooltip.tsx) | Tooltip card: step counter (IBM Plex Mono), instruction, Skip / Next buttons |
| [`tour/TourProvider.tsx`](src/components/tour/TourProvider.tsx) | Full tour engine: `useReducer` state machine, DOM polling, advance detection, `PATCH /api/me/onboarding` on complete/skip, portal rendering |
```

Add to `src/tests/`:
```markdown
| `tour-engine.test.ts` | Cookie utilities, `filterSteps`, `tourReducer` — all pure lib layer |
```

- [ ] **Step 5.2: Update `docs/architecture.md`**

Add a new section "Onboarding Tour Engine" (after the Multi-tenancy section):

```markdown
## Onboarding Tour Engine

A first-time onboarding tour for new users. The engine mounts in `AppShellClient` and activates when `User.hasOnboarded = false`. On completion or skip, it calls `PATCH /api/me/onboarding` to set `hasOnboarded = true`.

### Persistence
Step position is stored in the `pulse-tour-step` first-party cookie (24-hour expiry, SameSite=Lax, JS-readable). This allows the tour to resume across full-page reloads and cross-route navigations without a DB write per step.

### State Machine (`src/lib/tour/reducer.ts`)
```
inactive → [INIT] → waiting (step on different route)
                  → polling (step on current route)
polling → [TARGET_FOUND] → active
        → [TARGET_MISSING] → missing (timeout after 3 s)
waiting → [ARRIVED_ON_ROUTE] → polling
active → [ADVANCE] → polling | waiting (next step)
       → [EXIT] → inactive
missing → [EXIT] → inactive
```

### Advance Modes
- `{ on: "click" }` — `addEventListener("click", handler, { once: true })` on the target element
- `{ on: "navigate"; to }` — `usePathname()` change watcher in `TourProvider`
- `{ on: "next" }` — "Next →" button in `TourTooltip`

### Role Filtering
`filterSteps(steps, role)` applied at provider mount. Steps with no `roles` field are shown to all roles. Steps with `stub` can render alternative copy when a condition is false (used for LINE_MANAGER steps that depend on project existence).

### Overlay
4 `position: fixed` divs surround the spotlight area with `background: rgba(0,0,0,0.65)`. The spotlight itself is a fifth `position: fixed` div with a `border: 2px solid #0ee29e` ring and `pointer-events: none` — clicks pass through to the real target element.
```

- [ ] **Step 5.3: Update `docs/decisions.md`**

Add ADR:

```markdown
### ADR-tour-cookie: Cookie-based tour step persistence (not localStorage, not DB column)

**Decision:** Store the current tour step ID in a first-party `pulse-tour-step` cookie (SameSite=Lax, 24h, non-httpOnly).

**Rationale:**
- **Not localStorage:** localStorage is per-origin but not per-session; a shared machine would have one user's tour state interfere with another's. Cookies can be scoped per-session if needed (remove max-age).
- **Not a DB column per step:** A DB write on every step transition adds latency and 20+ DB writes for a full tour. `User.hasOnboarded` (written once on complete/skip) already covers the durable state. Transient step progress doesn't need DB durability — if the cookie is lost, the tour restarts from step 0, which is acceptable.
- **Cookie:** Survives hard reloads and SSR-to-client hydration. Is readable from `document.cookie` on the client. Not accessible to the server (non-httpOnly is a client-side concern only — the server never needs to read step progress). 24-hour expiry is a reasonable session boundary for a one-time tour.
```

- [ ] **Step 5.4: Update `docs/code.md`**

Add to the lib modules section:

```markdown
| `src/lib/tour/cookie.ts` | `readTourCookie(): string | null` · `writeTourCookie(stepId: string): void` · `clearTourCookie(): void` |
| `src/lib/tour/filter.ts` | `filterSteps(steps: TourStep[], role: TourRole): TourStep[]` |
| `src/lib/tour/reducer.ts` | `tourReducer(state: EngineState, action: EngineAction): EngineState` |
| `src/lib/tour/steps.ts` | `TOUR_STEPS: TourStep[]` |
```

- [ ] **Step 5.5: Commit docs**

```bash
git add docs/
git commit -m "docs(tour): add tour engine to architecture, structure, code, and decisions docs"
```

- [ ] **Step 5.6: Push**

```bash
git push origin afthab/axis-pulse
```

---

## Self-Review

**Spec coverage check:**

| Requirement | Covered by |
|---|---|
| pulse-tour-step cookie (not localStorage, not DB per step) | Step 1.4, ADR in Step 5.3 |
| TourProvider in AppShellClient | Step 2.1 |
| Cookie read on mount → resume at correct step | Step 1.13 MOUNT effect |
| Target-not-present → poll 200ms / 3s timeout → missing state | Step 1.13 `beginPoll` |
| Real-click advance | Step 1.13 CLICK ADVANCE effect |
| Navigate advance | Step 1.13 ROUTE CHANGE effect |
| Next-button advance | Step 1.12 TourTooltip, Step 1.13 advance callback |
| Role-based filtering | Step 1.5, Step 1.13 `filterSteps(TOUR_STEPS, role)` |
| Stub instruction support (interface) | Step 1.3 TourStep.stub type |
| Dim overlay ~65% | Step 1.10 TourOverlay rgba(0,0,0,0.65) |
| Spotlight cutout with teal ring | Step 1.10 spotlight div |
| Tooltip (IBM Plex Mono counter, Jakarta Sans body) | Step 1.12 TourTooltip CSS vars |
| Skip tour always visible | Step 1.12 Skip button |
| Esc exits | Step 1.13 keyboard effect |
| Both skip+complete call PATCH /api/me/onboarding | Step 1.13 `doExit` |
| Cookie cleared on skip/complete | Step 1.13 `clearTourCookie()` in `doExit` |
| prefers-reduced-motion disables pulse animation | Step 1.10 TourOverlay, Step 1.11 globals.css |
| Focus moves to tooltip each step | Step 1.13 `focusTooltip()` |
| role="dialog" aria-label | Step 1.12 TourTooltip |
| Zero impact when inactive (hasOnboarded=true) | TourProvider early-returns after MOUNT effect no-op; children rendered unchanged |
| Placeholder steps prove engine works | Step 1.8 TOUR_STEPS (3 steps across 2 routes) |
| No new npm deps | Confirmed — uses React built-ins only |

**Placeholder scan:** No TBDs, no "handle X" without code, no "similar to Task N" references. Every step shows the full code.

**Type consistency:** `TourStep`, `EngineState`, `EngineAction` defined in `types.ts` and used consistently in `reducer.ts`, `filter.ts`, `TourProvider.tsx`, `TourTooltip.tsx`, `TourOverlay.tsx`.

**One issue found and fixed:** `TourOverlay` initialises `vpH` state but doesn't use it in the layout. Either remove it or keep it for future use. The plan notes this in Step 1.10 and Step 4.3 with a fix option. The `vpW` state IS used (checked before rendering). `vpH` is safe to remove to avoid the lint error.

Fix: remove `vpH` from `TourOverlay.tsx` entirely. Replace the `useState` initializer with only `vpW`.
