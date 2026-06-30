# Aurora Boost + DotField — Design Spec
**Date**: 2026-06-23  
**Scope**: `src/app/_landing/` only  
**Branch**: `afthab/axis-pulse`

---

## Changes

### 1. ColorBendsCanvas — bolder aurora
- `pow(band, 2.0)` (was 3.0) — wider soft sweep
- Multiplier: `0.72 × 0.55 = 0.396` peak alpha (~101/255) — was `0.72 × 0.30`

### 2. DotFieldCanvas — new cursor-reactive dot grid
- Canvas-2D, zero deps, `"use client"`
- `position:fixed; inset:0; z-index:2; pointer-events:none`
- Grid: spacing 14px, base radius 1.5px, `rgba(14,226,158,0.06)` resting
- Cursor: `window.addEventListener('mousemove')` — never intercepts events
- Within 500px: bulge = `67 × (1 - dist/500)²` radially away from cursor
- Within 160px: glow alpha rises to 0.65, radius rises to 3.0
- RAF + dirty flag: only redraws on cursor movement
- `prefers-reduced-motion`: draw once (static grid), no RAF, no mouse listener

### 3. Layering update (LandingPage.tsx)
- Root div: `background:#0a0e0d` (unchanged — WebGL failure fallback)
- `<ColorBendsCanvas />` z:1
- `<DotFieldCanvas />` z:2
- Content wrapper: `zIndex:3` (was 2)
- Remove existing `DotPattern` SVG (replaced by DotField)

### 4. Readability scrims
- Hero copy column: `background:rgba(10,14,13,0.25); borderRadius:10px` on the right-side wrapper in HeroClient.tsx
- Proof strip: `background:rgba(10,14,13,0.25)` added to `.proof-strip` in landing.css

---

## Verification
1. Screenshot showing both layers visible (aurora sweep + faint dot texture)
2. Cursor reactivity: Playwright `browser_mouse_move`, pixel-diff before/after confirms glow pixels changed
3. CTA/Sign in/hero controls click through (pointer-events:none on both canvases)
4. Reduced-motion: static resting grid + frozen aurora
5. Dark fallback: root div retains `background:#0a0e0d`
6. `npm run build` exits 0
