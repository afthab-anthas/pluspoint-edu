# ColorBends WebGL Background — Design Spec
**Date**: 2026-06-23  
**Scope**: `src/app/_landing/` only  
**Branch**: `afthab/axis-pulse`

---

## Goal

Add a full-page, fixed WebGL animated background to the landing page: slow-drifting diagonal teal color bands (aurora/ribbon wash) that provide ambient atmospheric depth behind all content. The effect must whisper — barely visible, not dominant.

---

## Effect Parameters

| Parameter | Value |
|---|---|
| Color | `#0ee29e` (teal accent) |
| Band style | Diagonal ribbons (~30° axis), multiple overlapping sine layers |
| Speed | ~0.2× time (slow drift) |
| Frequency | Low — wide spacing between bands |
| Band width | Narrow (thin ribbons, lots of dark between) |
| Noise | Subtle fBm noise to break up perfect regularity |
| Intensity | 0.72 (whisper-level; peak alpha ≈ 0.07–0.10 for teal on black) |
| Top fade | Linear attenuation: `1.0 - uv.y * 0.4` (stronger at top) |
| Background clear | `#0a0e0d` (matching landing page dark) |

---

## Architecture

### New file: `src/app/_landing/ColorBendsCanvas.tsx`

- `"use client"` React component
- `useRef` for canvas element
- `useEffect` on mount:
  - Acquire WebGL context with `{ alpha: true }` (transparent canvas, so CSS bg fallback shows through)
  - Compile vertex shader (full-screen quad: clip-space triangle strip)
  - Compile fragment shader (band effect, uniform `u_time` float)
  - Start RAF loop — increment `u_time` each frame, draw
  - `matchMedia('(prefers-reduced-motion: reduce)')` listener — if true, freeze `u_time` (static snapshot) and `visibility:hidden`
  - Return cleanup: cancel RAF, delete GL program
- On WebGL context failure: catch the null return, set `contextFailed = true`, render nothing (CSS fallback handles the background)
- Canvas CSS: `position: fixed; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 0`

### Fragment Shader

Three sine-wave band layers on a diagonal axis, each drifting at different rates:
```glsl
float diag = uv.x * 0.6 + uv.y * 0.8;          // diagonal axis
float b1 = sin(diag * 3.0  + u_time * 0.20);
float b2 = sin(diag * 5.5  + u_time * 0.13 + 1.4);
float b3 = sin(diag * 2.0  - u_time * 0.09 + 2.9);
float band = (b1 * 0.5 + b2 * 0.25 + b3 * 0.25 + 1.0) * 0.5;  // [0..1]
float narrow = pow(band, 8.0);                   // sharpens into thin ribbons
float topFade = 1.0 - uv.y * 0.4;               // attenuate toward top
float alpha = narrow * topFade * 0.72 * 0.12;   // whisper level
gl_FragColor = vec4(teal * alpha, alpha);        // premultiplied
```

---

## Layering Fix (Three-Part)

This is the root cause of the previous invisible render.

| Layer | Element | How |
|---|---|---|
| Bottom (fallback) | Root div background | Root div **keeps** `background: #0a0e0d` — this is the WebGL failure fallback; it is always painted |
| Canvas | `ColorBendsCanvas` | `position:fixed; inset:0; z-index:1; pointer-events:none; alpha:true` — renders above the root bg |
| Content | All page content | Wrapped in `<div style={{ position:"relative", zIndex:2 }}>` — stacks above canvas |

**Root div**: background stays `#0a0e0d` (NOT removed). The fix is that we no longer rely on it to show through — the canvas draws on top of it. The canvas uses `alpha:true` (transparent GL context) so where no band is drawn, the root div's dark color shows through.

**Why this is cleaner than a separate z:-1 fallback div**: `position:fixed; z-index:-1` risks going behind the `body` background paint layer in some browsers. The root div background has no such risk — it paints at document level, always below any positioned z-index:1 child.

**WebGL failure path**: if `getContext('webgl')` returns null, `ColorBendsCanvas` returns `null` (renders nothing). The root div's dark background is the only visible element — the page is a plain dark page, never white.

---

## Modifications to Existing Files

### `src/app/_landing/LandingPage.tsx`
1. Import `ColorBendsCanvas`
2. Root div: keep `background: "#0a0e0d"` — it is the fallback (no change to this line)
3. Add `<ColorBendsCanvas />` as first child
4. Wrap remaining children (`<DotPattern>`, `<LandingNav />` through `<LandingFooter />`) in `<div style={{ position:"relative", zIndex:2 }}>`

### `src/app/_landing/landing.css`
No structural changes needed. Add `@media (prefers-reduced-motion: reduce)` rule to hide canvas.

---

## Verification Checklist

- [ ] `npm run build` exits 0 (tsc clean + Next.js production build)
- [ ] Playwright screenshot: teal bands visible behind content
- [ ] Explicit log: "canvas mounts, WebGL context acquired, bands visible in screenshot"
- [ ] Click test: "Get started" CTA, "Sign in" nav link, and hero prev/pause/next controls all respond (pointer-events:none verified working)
- [ ] Graceful degradation: `.landing-bg-fallback` div present in DOM regardless of WebGL state
- [ ] prefers-reduced-motion: bands frozen (time not advancing) and canvas hidden

---

## Constraints

- No new npm dependencies (raw WebGL, no library)
- Only files changed: `src/app/_landing/ColorBendsCanvas.tsx` (new), `src/app/_landing/LandingPage.tsx`, `src/app/_landing/landing.css`
- All existing landing content/copy/honesty intact
- No co-author line in git commit
- Push to `afthab/axis-pulse` only, no PR
