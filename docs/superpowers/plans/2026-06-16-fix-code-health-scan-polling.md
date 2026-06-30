# Code Health Ring — Fix Scan Polling Bug

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a Code Health scan result appear on the FIRST click by replacing the broken in-memory `scanInProgress` exit condition with trigger-time-based polling against the DB.

**Architecture:** Extract the async polling loop into a standalone testable utility (`src/lib/code-health-poller.ts`). The utility polls `GET /api/projects/[id]/code-health` and only resolves when a snapshot whose `scannedAt` is strictly greater than the trigger timestamp (captured client-side at click time) arrives — or on `scanError`, or on 10-minute timeout. The `CodeHealthRing` component captures `triggerTime = new Date().toISOString()` before the POST, sets `scanning = true` immediately (client-side feedback), then delegates to the utility. The GET endpoint is unchanged; its `scanInProgress` field is now informational only.

**Tech Stack:** TypeScript, React (useState/useRef/useEffect), Vitest (fake timers), Next.js App Router

**Feature folder:** `features/project-redesign/fix-code-health-scan-polling`

---

## The Broken Exit Condition (quote before touching)

`src/app/projects/[id]/_components/CodeHealthRing.tsx` line 101:

```typescript
if (!data.scanInProgress && !data.scanError) return
```

This exits silently when `scanInProgress` is false — which the GET handler always reports because its in-memory `_scanningProjects` Set is a **different module instance** from the POST handler's Set. The poll exits on its first check (5 s after click), before any snapshot exists in the DB.

---

## File Structure

| Action | Path | Responsibility |
|--------|------|---------------|
| **Create** | `src/lib/code-health-poller.ts` | Pure async polling utility; owns `CodeHealthSnapshot` type, `PollOptions` type, and `pollForNewerSnapshot` function |
| **Modify** | `src/app/projects/[id]/_components/CodeHealthRing.tsx` | Import poller + `CodeHealthSnapshot`; delete `pollUntilDone` + local types; update `runScan` and `useEffect` |
| **Create** | `src/tests/code-health-poller.test.ts` | 5 unit tests covering: newer snapshot resolves, stale snapshot does not resolve, `scanError` exits with message, `shouldAbort` stops loop, and the regression: `scanInProgress=false` alone does NOT exit |
| **Note only** | `src/lib/code-health.ts` | `_scanningProjects` Set remains (GET endpoint still reads it); no logic changes needed |
| **Note only** | `src/app/api/projects/[id]/code-health/route.ts` | GET handler still returns `scanInProgress`; field is now informational only — client ignores it |

---

## Known Accepted Limit (document in lessons-learned, not fixed here)

Client-side `scanning = true` state is set at click time (React state). A full page refresh during a running scan loses this state — the ring reverts to showing its last snapshot until the new one lands. Surviving a refresh would require persisting scan state in Redis; that is deliberately out of scope here.

---

## Task 1: Core Fix — Poller Utility + Component Update

**Files:**
- Create: `src/lib/code-health-poller.ts`
- Modify: `src/app/projects/[id]/_components/CodeHealthRing.tsx`
- Create: `src/tests/code-health-poller.test.ts`

---

- [ ] **Step 1.1 — Write the failing tests**

Create `src/tests/code-health-poller.test.ts` with the following content. All 5 tests will fail because `@/lib/code-health-poller` does not exist yet.

```typescript
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"
import { pollForNewerSnapshot } from "@/lib/code-health-poller"
import type { CodeHealthSnapshot } from "@/lib/code-health-poller"

const mockFetch = vi.fn()

beforeEach(() => {
  vi.stubGlobal("fetch", mockFetch)
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function makeSnapshot(scannedAt: string): CodeHealthSnapshot {
  return {
    reliabilityRating: "A",
    securityRating: "A",
    maintainabilityRating: "A",
    qualityGate: "PASS",
    coveragePct: 80,
    duplicationPct: 2,
    sonarProjectKey: "test-key",
    scannedAt,
  }
}

function makeOkResponse(data: unknown): Response {
  return { ok: true, json: () => Promise.resolve(data) } as unknown as Response
}

describe("pollForNewerSnapshot", () => {
  it("resolves with snapshot when scannedAt is strictly newer than triggerTime", async () => {
    const triggerTime = "2026-06-16T10:00:00.000Z"
    const fresh = makeSnapshot("2026-06-16T10:05:00.000Z")
    mockFetch
      .mockResolvedValueOnce(makeOkResponse({ snapshot: null, scanError: null, scanInProgress: true }))
      .mockResolvedValueOnce(makeOkResponse({ snapshot: fresh, scanError: null, scanInProgress: false }))

    const onSnapshot = vi.fn()
    const onError = vi.fn()
    const pollPromise = pollForNewerSnapshot({
      projectId: "p1",
      triggerTime,
      onSnapshot,
      onError,
      shouldAbort: () => false,
      pollIntervalMs: 100,
    })
    await vi.advanceTimersByTimeAsync(250)
    await pollPromise

    expect(onSnapshot).toHaveBeenCalledWith(fresh)
    expect(onError).not.toHaveBeenCalled()
  })

  it("does NOT resolve when snapshot scannedAt is older than triggerTime (stale guard)", async () => {
    const triggerTime = "2026-06-16T10:00:00.000Z"
    const stale = makeSnapshot("2026-06-16T09:00:00.000Z")
    mockFetch.mockResolvedValue(
      makeOkResponse({ snapshot: stale, scanError: null, scanInProgress: false })
    )

    const onSnapshot = vi.fn()
    const onError = vi.fn()
    const pollPromise = pollForNewerSnapshot({
      projectId: "p1",
      triggerTime,
      onSnapshot,
      onError,
      shouldAbort: () => false,
      pollIntervalMs: 100,
      timeoutMs: 350,
    })
    await vi.advanceTimersByTimeAsync(500)
    await pollPromise

    expect(onSnapshot).not.toHaveBeenCalled()
    expect(onError).toHaveBeenCalledWith("Scan timed out — check SonarQube or try again.")
  })

  it("calls onError and stops when scanError is returned", async () => {
    const triggerTime = "2026-06-16T10:00:00.000Z"
    mockFetch.mockResolvedValueOnce(
      makeOkResponse({ snapshot: null, scanError: "Docker failed", scanInProgress: false })
    )

    const onSnapshot = vi.fn()
    const onError = vi.fn()
    const pollPromise = pollForNewerSnapshot({
      projectId: "p1",
      triggerTime,
      onSnapshot,
      onError,
      shouldAbort: () => false,
      pollIntervalMs: 100,
    })
    await vi.advanceTimersByTimeAsync(150)
    await pollPromise

    expect(onSnapshot).not.toHaveBeenCalled()
    expect(onError).toHaveBeenCalledWith("Scan failed: Docker failed")
  })

  it("stops cleanly when shouldAbort returns true mid-poll", async () => {
    const triggerTime = "2026-06-16T10:00:00.000Z"
    let aborted = false
    mockFetch.mockResolvedValue(
      makeOkResponse({ snapshot: null, scanError: null, scanInProgress: true })
    )

    const onSnapshot = vi.fn()
    const onError = vi.fn()
    const pollPromise = pollForNewerSnapshot({
      projectId: "p1",
      triggerTime,
      onSnapshot,
      onError,
      shouldAbort: () => aborted,
      pollIntervalMs: 100,
    })
    // Abort fires at t=150ms — between iteration 1 sleep (t=100) and iteration 2 sleep (t=200)
    setTimeout(() => { aborted = true }, 150)
    await vi.advanceTimersByTimeAsync(300)
    await pollPromise

    expect(onSnapshot).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
  })

  it("REGRESSION: does not exit when scanInProgress=false but no snapshot yet (the original bug)", async () => {
    // Old code: if (!data.scanInProgress && !data.scanError) return  ← exited here, UI stayed blank
    // New code: only exits on newer snapshot, scanError, or timeout
    const triggerTime = "2026-06-16T10:00:00.000Z"
    const fresh = makeSnapshot("2026-06-16T10:05:00.000Z")
    mockFetch
      // First poll: no snapshot, scanInProgress=false — this was the broken exit point
      .mockResolvedValueOnce(makeOkResponse({ snapshot: null, scanError: null, scanInProgress: false }))
      // Second poll: fresh snapshot arrives
      .mockResolvedValueOnce(makeOkResponse({ snapshot: fresh, scanError: null, scanInProgress: false }))

    const onSnapshot = vi.fn()
    const onError = vi.fn()
    const pollPromise = pollForNewerSnapshot({
      projectId: "p1",
      triggerTime,
      onSnapshot,
      onError,
      shouldAbort: () => false,
      pollIntervalMs: 100,
    })
    await vi.advanceTimersByTimeAsync(250)
    await pollPromise

    expect(onSnapshot).toHaveBeenCalledWith(fresh)
    expect(onError).not.toHaveBeenCalled()
  })
})
```

- [ ] **Step 1.2 — Run tests to verify they fail**

```
npm test -- src/tests/code-health-poller.test.ts
```

Expected: **5 FAIL** — `Cannot find module '@/lib/code-health-poller'`

---

- [ ] **Step 1.3 — Create `src/lib/code-health-poller.ts`**

```typescript
export type CodeHealthSnapshot = {
  reliabilityRating: string
  securityRating: string
  maintainabilityRating: string
  qualityGate: string
  coveragePct: number | null
  duplicationPct: number | null
  sonarProjectKey: string
  scannedAt: string
}

type PollResponse = {
  snapshot: CodeHealthSnapshot | null
  scanError: string | null
  // scanInProgress is returned by the API but is NOT checked here —
  // it relies on an in-memory Set that is unreliable across module instances
  // and in multi-process deployments. Polling terminates on snapshot arrival,
  // scanError, or timeout only.
}

export type PollOptions = {
  projectId: string
  triggerTime: string        // ISO string captured at click time; poll resolves only on scannedAt > triggerTime
  onSnapshot: (s: CodeHealthSnapshot) => void
  onError: (msg: string) => void
  shouldAbort: () => boolean // called before each sleep and after; return true to stop without error
  pollIntervalMs?: number    // default 5000
  timeoutMs?: number         // default 10 * 60 * 1000
}

export async function pollForNewerSnapshot(opts: PollOptions): Promise<void> {
  const {
    projectId,
    triggerTime,
    onSnapshot,
    onError,
    shouldAbort,
    pollIntervalMs = 5_000,
    timeoutMs = 10 * 60 * 1000,
  } = opts

  const startedAt = Date.now()
  while (Date.now() - startedAt < timeoutMs) {
    if (shouldAbort()) return
    await new Promise<void>((resolve) => setTimeout(resolve, pollIntervalMs))
    if (shouldAbort()) return
    try {
      const res = await fetch(`/api/projects/${projectId}/code-health`)
      if (!res.ok) continue
      const data = (await res.json()) as PollResponse
      if (data.scanError) { onError(`Scan failed: ${data.scanError}`); return }
      if (data.snapshot && data.snapshot.scannedAt > triggerTime) {
        onSnapshot(data.snapshot)
        return
      }
    } catch {
      // network error — continue polling
    }
  }
  onError("Scan timed out — check SonarQube or try again.")
}
```

- [ ] **Step 1.4 — Run tests again to verify all 5 pass**

```
npm test -- src/tests/code-health-poller.test.ts
```

Expected: **5 PASS**

---

- [ ] **Step 1.5 — Update `CodeHealthRing.tsx`**

Open `src/app/projects/[id]/_components/CodeHealthRing.tsx`.

**Change 1 of 5 — Replace local type + add import**

Find (lines 1–14):
```typescript
"use client"

import { useState, useRef, useEffect } from "react"

type CodeHealthSnapshot = {
  reliabilityRating: string
  securityRating: string
  maintainabilityRating: string
  qualityGate: string
  coveragePct: number | null
  duplicationPct: number | null
  sonarProjectKey: string
  scannedAt: string
}
```

Replace with:
```typescript
"use client"

import { useState, useRef, useEffect } from "react"
import { pollForNewerSnapshot } from "@/lib/code-health-poller"
import type { CodeHealthSnapshot } from "@/lib/code-health-poller"
```

**Change 2 of 5 — Remove `PollResponse` type**

Find (line 78):
```typescript
type PollResponse = { snapshot: CodeHealthSnapshot | null; scanError: string | null; scanInProgress: boolean }
```

Replace with _(empty — delete the line entirely)_:
```typescript

```

**Change 3 of 5 — Remove `pollUntilDone` function**

Find (lines 88–107):
```typescript
  async function pollUntilDone(baseScannedAt: string | null) {
    const startedAt = Date.now()
    while (Date.now() - startedAt < POLL_TIMEOUT_MS) {
      if (abortRef.current) return
      await new Promise<void>((resolve) => setTimeout(resolve, POLL_INTERVAL_MS))
      if (abortRef.current) return
      try {
        const pollRes = await fetch(`/api/projects/${projectId}/code-health`)
        if (!pollRes.ok) continue
        const data = await pollRes.json() as PollResponse

        if (data.scanError) { setErrorMsg(`Scan failed: ${data.scanError}`); return }
        if (data.snapshot && data.snapshot.scannedAt !== baseScannedAt) { setSnapshot(data.snapshot); return }
        if (!data.scanInProgress && !data.scanError) return
      } catch {
        // Network error during poll — continue
      }
    }
    setErrorMsg("Scan timed out — check SonarQube or try again.")
  }
```

Replace with _(empty — delete entirely)_:
```typescript

```

**Change 4 of 5 — Update `useEffect`**

Find (lines 109–115):
```typescript
  useEffect(() => {
    if (!initialScanInProgress) return
    abortRef.current = false
    setScanning(true)
    pollUntilDone(initialSnapshot?.scannedAt ?? null).finally(() => setScanning(false))
    return () => { abortRef.current = true }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
```

Replace with:
```typescript
  useEffect(() => {
    if (!initialScanInProgress) return
    abortRef.current = false
    setScanning(true)
    // Use the last-known snapshot's time as baseline; if no prior snapshot, use epoch (any snapshot qualifies)
    const triggerTime = initialSnapshot?.scannedAt ?? new Date(0).toISOString()
    pollForNewerSnapshot({
      projectId,
      triggerTime,
      onSnapshot: setSnapshot,
      onError: setErrorMsg,
      shouldAbort: () => abortRef.current,
    }).finally(() => setScanning(false))
    return () => { abortRef.current = true }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  // KNOWN LIMIT: scanning=true state is client-side only. A page refresh mid-scan loses it;
  // the ring shows its last result until the fresh snapshot lands. Surviving a refresh
  // would require Redis persistence — deliberately out of scope.
```

**Change 5 of 5 — Update `runScan`**

Find (lines 117–133):
```typescript
  async function runScan() {
    setScanning(true)
    setErrorMsg(null)
    abortRef.current = false

    try {
      const res = await fetch(`/api/projects/${projectId}/code-health/scan`, { method: "POST" })
      if (res.status === 429) { setErrorMsg("Rate-limited — wait 2 min between scans."); return }
      if (res.status === 503) { setErrorMsg("SonarQube not configured on this server."); return }
      if (res.status !== 202) { setErrorMsg("Scan failed."); return }
      await pollUntilDone(snapshot?.scannedAt ?? null)
    } catch {
      setErrorMsg("Network error during scan.")
    } finally {
      setScanning(false)
    }
  }
```

Replace with:
```typescript
  async function runScan() {
    const triggerTime = new Date().toISOString()  // captured before POST so any later snapshot qualifies
    setScanning(true)
    setErrorMsg(null)
    abortRef.current = false

    try {
      const res = await fetch(`/api/projects/${projectId}/code-health/scan`, { method: "POST" })
      if (res.status === 429) { setErrorMsg("Rate-limited — wait 2 min between scans."); return }
      if (res.status === 503) { setErrorMsg("SonarQube not configured on this server."); return }
      if (res.status !== 202) { setErrorMsg("Scan failed."); return }
      await pollForNewerSnapshot({
        projectId,
        triggerTime,
        onSnapshot: setSnapshot,
        onError: setErrorMsg,
        shouldAbort: () => abortRef.current,
      })
    } catch {
      setErrorMsg("Network error during scan.")
    } finally {
      setScanning(false)
    }
  }
```

- [ ] **Step 1.6 — Check POLL_INTERVAL_MS and POLL_TIMEOUT_MS are still referenced**

The constants `POLL_INTERVAL_MS` and `POLL_TIMEOUT_MS` were only used inside `pollUntilDone`, which is now deleted. They are no longer referenced in the component. Delete them to avoid a TypeScript `no-unused-vars` error.

Find (lines 23–24 in the component):
```typescript
const POLL_INTERVAL_MS = 5_000
const POLL_TIMEOUT_MS = 10 * 60 * 1000
```

Replace with _(empty — delete both lines)_:
```typescript

```

Note: `pollForNewerSnapshot` uses its own internal defaults (5 000 ms / 10 min) that match these values exactly. No behaviour change.

- [ ] **Step 1.7 — Run the poller tests again (still 5 pass)**

```
npm test -- src/tests/code-health-poller.test.ts
```

Expected: **5 PASS** (no regressions from component edit)

- [ ] **Step 1.8 — Commit Task 1**

```
git add src/lib/code-health-poller.ts src/tests/code-health-poller.test.ts src/app/projects/[id]/_components/CodeHealthRing.tsx
git commit -m "fix(code-health-ring): poll until newer snapshot instead of gating on unreliable scanInProgress"
```

---

## Task 2: Full Suite + Type Check + Production Build

**Files:** No new changes — verification only.

- [ ] **Step 2.1 — TypeScript compile check**

```
npx tsc --noEmit
```

Expected: 0 errors in `src/` (existing `src/tests/` baseline errors are pre-existing and acceptable per TSC Baseline Drift memory note).

- [ ] **Step 2.2 — Full test suite**

```
npm test
```

Expected: all existing tests pass; 5 new poller tests pass.

- [ ] **Step 2.3 — Production build**

```
npm run build
```

Expected: build completes with no type or lint errors.

- [ ] **Step 2.4 — Manual verification proving target**

Start the production server:

```
npm start
```

Navigate to a project that has a linked GitHub repo and a prior Code Health scan. Click **Run Scan**:

1. Ring shows **"Scanning…"** immediately (no second click needed).
2. After the scan completes (~2–5 min depending on repo size), the ring transitions to the fresh grade without any additional user action.
3. Confirm the result timestamp matches this scan, not the prior one.

Repeat on a **brand-new project with no prior scan**: same behaviour — ring shows "Scanning…" then the fresh result.

Confirm the **stale-guard**: open browser DevTools, intercept the GET poll response and return a snapshot with a `scannedAt` from two days ago. Confirm the ring does NOT update and keeps polling.

- [ ] **Step 2.5 — Commit Task 2**

```
git add -p  # nothing to stage if no source changes
git commit -m "chore(code-health-ring): verify tsc + full suite + production build after polling fix"
```

---

## Self-Review

### Spec Coverage

| Requirement | Task |
|---|---|
| Client shows "Scanning…" from click (client-side state) | Task 1 Step 1.5 Change 5 — `triggerTime` captured + `setScanning(true)` before POST |
| Poll until snapshot NEWER than trigger time | Task 1 — `data.snapshot.scannedAt > triggerTime` in poller |
| Stale prior snapshot does NOT satisfy the poll | Task 1 Test 2 (`stale guard`) |
| Exit on `scanError` | Task 1 Test 3 |
| Exit on 10-min timeout with honest message | Task 1 — `timeoutMs` default 10 min, `onError("Scan timed out…")` |
| `abortRef` / unmount guard preserved | Task 1 Test 4 + `shouldAbort: () => abortRef.current` |
| Remove broken `scanInProgress` exit gate | Task 1 Test 5 (regression) + Change 3 deletes `pollUntilDone` |
| `_scanningProjects` dead reliance noted, not silently left | File Structure table + comment in poller |
| Known limit (refresh loses scanning state) documented | Task 1 Step 1.5 Change 4 comment |
| `npm test` green | Task 2 Step 2.2 |
| `tsc --noEmit` green | Task 2 Step 2.1 |
| Prove in production build | Task 2 Steps 2.3–2.4 |

### Placeholder Scan

No TBDs, no "add appropriate" language, no references to undefined types. All code blocks are complete.

### Type Consistency

- `CodeHealthSnapshot` defined once in `code-health-poller.ts`, exported, imported in component — consistent.
- `PollOptions.triggerTime` is `string` throughout.
- `onSnapshot: (s: CodeHealthSnapshot) => void` matches `setSnapshot` (React `Dispatch<SetStateAction<CodeHealthSnapshot | null>>` accepts `CodeHealthSnapshot`).
- `POLL_INTERVAL_MS` / `POLL_TIMEOUT_MS` removed from component, replaced by poller defaults (5 000 / 600 000 ms) — values identical.
