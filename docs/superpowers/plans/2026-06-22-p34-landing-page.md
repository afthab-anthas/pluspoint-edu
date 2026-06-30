# P34 — Landing Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ PAUSE AFTER TASK 1 for human review before continuing to Task 2.**

**Goal:** Replace the "coming soon" placeholder at `/` with the real 7-section marketing landing page, built entirely in inline styles + CSS vars, NO Tailwind, using the 11 existing demo screenshots as real content (video placeholder for hero, screenshots for feature sections).

**Architecture:** Server-component page shell (`_landing/LandingPage.tsx`) composes all sections. The hero step machine is the only client component (`HeroClient.tsx`). Screenshots live in `public/demo/`. A single `landing.css` covers animations, hover states, and media queries that can't be expressed in inline `style` props.

**Tech Stack:** Next.js 14 App Router, TypeScript, `next/image`, CSS vars from `globals.css`, Plus Jakarta Sans (`--font-sans`), IBM Plex Mono (`--font-mono`)

---

## Files

| Action | Path |
|--------|------|
| Create dir | `public/demo/` |
| Copy 8 PNGs | `public/demo/screen*.png` (from repo-root `demo-screen*.png`) |
| Create | `src/app/_landing/landing.css` |
| Create | `src/app/_landing/LandingPage.tsx` |
| Create | `src/app/_landing/HeroClient.tsx` |
| Create | `src/app/_landing/ProofStrip.tsx` |
| Create | `src/app/_landing/FeatureDeepDives.tsx` |
| Create | `src/app/_landing/HowItWorks.tsx` |
| Create | `src/app/_landing/WhoItsFor.tsx` |
| Create | `src/app/_landing/CtaBand.tsx` |
| Create | `src/app/_landing/LandingFooter.tsx` |
| Modify | `src/app/page.tsx` |

**Screenshot mapping** (repo-root → `public/demo/`):

| Source filename | Dest filename | Used by |
|---|---|---|
| `demo-screen1-dashboard.png` | `screen1-dashboard.png` | Hero step 1 |
| `demo-screen2-marlin-detail.png` | `screen2-marlin-detail.png` | Hero step 2, Feature: AI narration |
| `demo-screen2-marlin-lower.png` | `screen2-marlin-lower.png` | Hero step 3, Feature: Honest completion |
| `demo-screen3-cost-dashboard.png` | `screen3-cost-dashboard.png` | Hero step 5, Feature: Spend |
| `demo-screen4-nexus-detail.png` | `screen4-nexus-detail.png` | Hero step 4 |
| `demo-screen4-context-analyser.png` | `screen4-context-analyser.png` | Feature: Context drift |
| `demo-screen2-marlin-bottom.png` | `screen2-marlin-bottom.png` | Reserve / feature use |
| `demo-screen5-exec-summary.png` | `screen5-exec-summary.png` | Reserve |

**Placeholder note:** Feature section 5 ("Privacy by construction") has no screenshot that shows the on-device classification flow. It will render a labeled placeholder box until a dedicated screenshot is provided.

---

## Task 1: Image setup + CSS + page shell + Hero section

> **This is the review checkpoint.** Implement this full task, then `tsc --noEmit` and `npm run build` before stopping.

**Files:**
- Create: `public/demo/` (8 PNGs)
- Create: `src/app/_landing/landing.css`
- Create: `src/app/_landing/HeroClient.tsx`
- Create: `src/app/_landing/LandingPage.tsx`
- Modify: `src/app/page.tsx`

---

- [ ] **Step 1: Copy screenshots to `public/demo/`**

Run in PowerShell:

```powershell
New-Item -ItemType Directory -Force "public\demo"
$maps = @(
  @{ Src = "demo-screen1-dashboard.png";        Dst = "public\demo\screen1-dashboard.png" }
  @{ Src = "demo-screen2-marlin-detail.png";    Dst = "public\demo\screen2-marlin-detail.png" }
  @{ Src = "demo-screen2-marlin-lower.png";     Dst = "public\demo\screen2-marlin-lower.png" }
  @{ Src = "demo-screen2-marlin-bottom.png";    Dst = "public\demo\screen2-marlin-bottom.png" }
  @{ Src = "demo-screen3-cost-dashboard.png";   Dst = "public\demo\screen3-cost-dashboard.png" }
  @{ Src = "demo-screen4-nexus-detail.png";     Dst = "public\demo\screen4-nexus-detail.png" }
  @{ Src = "demo-screen4-context-analyser.png"; Dst = "public\demo\screen4-context-analyser.png" }
  @{ Src = "demo-screen5-exec-summary.png";     Dst = "public\demo\screen5-exec-summary.png" }
)
foreach ($m in $maps) { Copy-Item $m.Src $m.Dst -Force }
Write-Host "Copied $($maps.Count) screenshots to public/demo/"
```

Expected: `Copied 8 screenshots to public/demo/`

---

- [ ] **Step 2: Create `src/app/_landing/landing.css`**

```css
/* =============================================================
   LANDING PAGE — landing.css
   Imported by LandingPage.tsx. CSS vars from globals.css apply.
   NO Tailwind. Inline styles in JSX; this file covers hover,
   animation, media queries, and pseudo-elements only.
============================================================= */

/* ── NAV ──────────────────────────────────────────────────── */
.l-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 32px;
  height: 58px;
  border-bottom: 1px solid rgba(255,255,255,0.055);
  position: sticky;
  top: 0;
  z-index: 100;
  background: rgba(10,14,13,0.92);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.l-logo {
  font-family: var(--font-mono, monospace);
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  letter-spacing: 0.03em;
  display: flex;
  gap: 0;
}
.l-logo-axis  { color: rgba(237,240,250,0.75); }
.l-logo-pulse { color: #0ee29e; }

.l-nav-links { display: flex; align-items: center; gap: 4px; }

.l-nav-link {
  font-size: 14px;
  color: rgba(237,240,250,0.45);
  text-decoration: none;
  padding: 6px 12px;
  border-radius: 6px;
  transition: color 0.15s;
}
.l-nav-link:hover { color: rgba(237,240,250,0.85); }

.l-nav-cta {
  font-size: 14px;
  font-weight: 600;
  color: #07080c;
  background: #0ee29e;
  text-decoration: none;
  padding: 7px 18px;
  border-radius: 7px;
  transition: opacity 0.15s;
  margin-left: 4px;
}
.l-nav-cta:hover { opacity: 0.88; }

/* ── HERO ─────────────────────────────────────────────────── */
.hero-layout {
  display: flex;
  gap: 52px;
  align-items: flex-start;
}

/* Browser frame */
.hero-browser-frame {
  background: #0d0f18;
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 28px 80px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.04) inset;
}

.hero-browser-topbar {
  height: 40px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  background: rgba(255,255,255,0.032);
  border-bottom: 1px solid rgba(255,255,255,0.055);
}

.hero-traffic-lights { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }

.tl { width: 12px; height: 12px; border-radius: 50%; display: block; }
.tl-red    { background: #ff5f56; }
.tl-yellow { background: #febc2e; }
.tl-green  { background: #28c840; }

.hero-url-pill {
  flex: 1;
  text-align: center;
  font-family: var(--font-mono, monospace);
  font-size: 11px;
  color: rgba(255,255,255,0.38);
  background: rgba(0,0,0,0.22);
  border-radius: 4px;
  padding: 3px 10px;
  max-width: 300px;
  margin: 0 auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hero-live-badge {
  display: flex;
  align-items: center;
  gap: 5px;
  font-family: var(--font-mono, monospace);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.05em;
  color: #3ddc84;
  flex-shrink: 0;
}

.hero-live-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #3ddc84;
  animation: live-blink 2s ease-in-out infinite;
}

@keyframes live-blink {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.3; }
}

/* Progress bar */
.hero-progress-track {
  height: 2px;
  background: rgba(14,226,158,0.1);
  position: relative;
  overflow: hidden;
}

.hero-progress-fill {
  position: absolute;
  left: 0; top: 0; height: 100%;
  width: 100%;
  background: #0ee29e;
  transform-origin: left;
  animation: hero-progress-advance linear both;
}

@keyframes hero-progress-advance {
  from { transform: scaleX(0); }
  to   { transform: scaleX(1); }
}

/* Copy column transitions */
.hero-copy-content {
  animation: copy-slide-in 280ms cubic-bezier(0.23, 1, 0.32, 1) both;
}

@keyframes copy-slide-in {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: none; }
}

/* Screen transition */
.hero-screen-wrap {
  animation: screen-fade-in 300ms ease-out both;
}

@keyframes screen-fade-in {
  from { opacity: 0; }
  to   { opacity: 1; }
}

/* Eyebrow */
.hero-eyebrow {
  font-family: var(--font-mono, monospace);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.1em;
  color: #0ee29e;
  text-transform: uppercase;
  margin: 0 0 14px;
}

/* Bullet list */
.hero-bullets {
  list-style: none;
  padding: 0;
  margin: 0 0 28px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.hero-bullets li {
  font-size: 14px;
  color: rgba(237,240,250,0.72);
  display: flex;
  align-items: center;
  gap: 10px;
}

.hero-bullet-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(14,226,158,0.1);
  border: 1px solid rgba(14,226,158,0.3);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  color: #0ee29e;
  font-weight: 700;
}

/* Controls */
.hero-ctrl-btn {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 6px;
  width: 32px;
  height: 32px;
  color: rgba(255,255,255,0.45);
  font-size: 13px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.hero-ctrl-btn:hover {
  border-color: rgba(14,226,158,0.35);
  color: #edf0fa;
  background: rgba(14,226,158,0.06);
}
.hero-ctrl-btn:focus-visible {
  outline: 2px solid rgba(14,226,158,0.55);
  outline-offset: 2px;
}

.hero-step-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgba(255,255,255,0.18);
  border: none;
  cursor: pointer;
  transition: background 0.2s, transform 0.15s;
  padding: 0;
  flex-shrink: 0;
}
.hero-step-dot.active {
  background: #0ee29e;
  transform: scale(1.3);
}
.hero-step-dot:focus-visible {
  outline: 2px solid rgba(14,226,158,0.55);
  outline-offset: 3px;
}

/* CTA buttons */
.hero-cta-primary {
  display: inline-flex;
  align-items: center;
  padding: 11px 24px;
  border-radius: 8px;
  background: #0ee29e;
  color: #07080c;
  font-weight: 700;
  font-size: 15px;
  text-decoration: none;
  transition: opacity 0.15s, transform 0.12s;
}
.hero-cta-primary:hover  { opacity: 0.88; transform: translateY(-1px); }
.hero-cta-primary:active { transform: translateY(0) scale(0.98); }

.hero-cta-secondary {
  display: inline-flex;
  align-items: center;
  padding: 11px 22px;
  border-radius: 8px;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.12);
  color: rgba(255,255,255,0.6);
  font-weight: 500;
  font-size: 15px;
  text-decoration: none;
  transition: border-color 0.15s, color 0.15s;
}
.hero-cta-secondary:hover {
  border-color: rgba(255,255,255,0.25);
  color: #edf0fa;
}

/* ── PROOF STRIP ──────────────────────────────────────────── */
.proof-strip {
  border-top: 1px solid rgba(255,255,255,0.055);
  border-bottom: 1px solid rgba(255,255,255,0.055);
  padding: 28px 32px;
}

.proof-strip-inner {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  justify-content: space-around;
  align-items: center;
  flex-wrap: wrap;
  gap: 24px;
}

/* ── FEATURE DEEP-DIVES ───────────────────────────────────── */
.feature-row {
  display: flex;
  gap: 72px;
  align-items: center;
  margin-bottom: 96px;
}
.feature-row.reversed { flex-direction: row-reverse; }
.feature-row:last-child { margin-bottom: 0; }

.feature-screenshot {
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.08);
  box-shadow: 0 16px 60px rgba(0,0,0,0.4);
}

.feature-placeholder {
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  border: 1px dashed rgba(255,255,255,0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(14,226,158,0.02);
}

/* ── HOW IT WORKS ─────────────────────────────────────────── */
.how-steps {
  display: flex;
  position: relative;
}

.how-steps::before {
  content: '';
  position: absolute;
  top: 28px;
  left: calc(33.33% - 8px);
  right: calc(33.33% - 8px);
  height: 1px;
  background: linear-gradient(90deg,
    rgba(14,226,158,0.4),
    rgba(14,226,158,0.1),
    rgba(14,226,158,0.4)
  );
}

/* ── WHO IT'S FOR ─────────────────────────────────────────── */
.who-card {
  background: #0d0f18;
  border: 1px solid rgba(255,255,255,0.065);
  border-radius: 12px;
  padding: 28px 32px;
  transition: border-color 0.2s;
}
.who-card:hover { border-color: rgba(14,226,158,0.15); }

/* ── CTA BAND ─────────────────────────────────────────────── */
.cta-band-btn {
  display: inline-flex;
  align-items: center;
  padding: 14px 32px;
  border-radius: 9px;
  background: #0ee29e;
  color: #07080c;
  font-weight: 700;
  font-size: 16px;
  text-decoration: none;
  transition: opacity 0.15s, transform 0.12s;
}
.cta-band-btn:hover  { opacity: 0.88; transform: translateY(-1px); }
.cta-band-btn:active { transform: translateY(0) scale(0.98); }

/* ── FOOTER ───────────────────────────────────────────────── */
.l-footer-link {
  font-size: 13px;
  color: rgba(237,240,250,0.32);
  text-decoration: none;
  transition: color 0.15s;
}
.l-footer-link:hover { color: rgba(237,240,250,0.65); }

/* ── MOBILE ───────────────────────────────────────────────── */
@media (max-width: 860px) {
  .l-nav { padding: 0 20px; }

  .hero-layout {
    flex-direction: column;
    gap: 32px;
  }

  .feature-row,
  .feature-row.reversed {
    flex-direction: column;
    gap: 32px;
    margin-bottom: 64px;
  }

  .how-steps {
    flex-direction: column;
    gap: 36px;
  }
  .how-steps::before { display: none; }

  .proof-strip-inner {
    gap: 32px;
  }
}

@media (max-width: 640px) {
  .l-nav-links .l-nav-link { display: none; }
  .proof-strip-inner { flex-direction: column; gap: 20px; }
}

/* ── REDUCED MOTION ───────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
  .hero-progress-fill { animation: none !important; }
  .hero-live-dot      { animation: none !important; }
  .hero-copy-content  { animation: none !important; }
  .hero-screen-wrap   { animation: none !important; }

  .hero-cta-primary,
  .hero-cta-secondary,
  .hero-ctrl-btn,
  .hero-step-dot,
  .cta-band-btn,
  .who-card {
    transition: none !important;
  }
  .hero-cta-primary:hover,
  .cta-band-btn:hover {
    transform: none !important;
  }
  .hero-cta-primary:active,
  .cta-band-btn:active {
    transform: none !important;
  }
}
```

---

- [ ] **Step 3: Create `src/app/_landing/HeroClient.tsx`**

```typescript
"use client"

import { useState, useEffect, useCallback, useRef } from "react"
import Image from "next/image"

const STEP_DURATION = 4200

const STEPS = [
  {
    pulseLabel: "PULSE · DASHBOARD",
    routeSuffix: "/dashboard",
    headline: "See every project",
    body: "Live overview of every AI-augmented project your team is running — AI headlines, sparklines, and who's active right now.",
    bullets: ["Live Overview stats", "Project cards with AI headline", "Active-now feed"],
    screenshot: "/demo/screen1-dashboard.png",
    alt: "Axis Pulse dashboard showing project cards with 7-day sparklines and activity feed",
  },
  {
    pulseLabel: "PULSE · PROJECT",
    routeSuffix: "/projects/marlin-api",
    headline: "Read the story",
    body: "Every project has an AI-narrated story of what the team actually did — no manual standup needed.",
    bullets: ["Verdict banner", "AI narration", "Risk + stage"],
    screenshot: "/demo/screen2-marlin-detail.png",
    alt: "Project detail page showing verdict banner and AI narration panel with highlights",
  },
  {
    pulseLabel: "PULSE · METRICS",
    routeSuffix: "/projects/marlin-api",
    headline: "Measure honestly",
    body: "Four factual cells — markers, coverage, scan state, forecast — with no fake aggregate percentage.",
    bullets: ["Completion Status", "Dev Spend", "Code Health"],
    screenshot: "/demo/screen2-marlin-lower.png",
    alt: "Project metrics band showing completion widget, spend ring, and code health grade",
  },
  {
    pulseLabel: "PULSE · EXCEPTIONS",
    routeSuffix: "/context-analyser",
    headline: "Catch the risks",
    body: "Exception flags, security scan findings, and context drift alerts surfaced without noise.",
    bullets: ["Needs a look", "Security findings", "Who's working"],
    screenshot: "/demo/screen4-nexus-detail.png",
    alt: "Project detail showing needs-a-look exceptions panel and who is working strip",
  },
  {
    pulseLabel: "PULSE · SPEND",
    routeSuffix: "/admin/cost-dashboard",
    headline: "Track the cost",
    body: "Per-developer Claude Code spend broken down to the dollar, per turn, per model.",
    bullets: ["Today's team spend", "14-day trend", "Per-developer table"],
    screenshot: "/demo/screen3-cost-dashboard.png",
    alt: "AI cost dashboard showing today's team spend, 14-day bar chart, and per-developer breakdown",
  },
] as const

const BASE_URL = "pulse.axisconsultancy.tech"

export default function HeroClient() {
  const [step, setStep] = useState(0)
  const [playing, setPlaying] = useState(true)
  const [reducedMotion, setReducedMotion] = useState(false)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  useEffect(() => {
    const mq = window.matchMedia("(prefers-reduced-motion: reduce)")
    if (mq.matches) {
      setReducedMotion(true)
      setPlaying(false)
    }
    const handler = (e: MediaQueryListEvent) => {
      setReducedMotion(e.matches)
      if (e.matches) setPlaying(false)
    }
    mq.addEventListener("change", handler)
    return () => mq.removeEventListener("change", handler)
  }, [])

  const clearTimer = useCallback(() => {
    if (intervalRef.current !== null) {
      clearInterval(intervalRef.current)
      intervalRef.current = null
    }
  }, [])

  const startTimer = useCallback(() => {
    clearTimer()
    intervalRef.current = setInterval(() => {
      setStep(s => (s + 1) % STEPS.length)
    }, STEP_DURATION)
  }, [clearTimer])

  useEffect(() => {
    if (playing && !reducedMotion) {
      startTimer()
    } else {
      clearTimer()
    }
    return clearTimer
  }, [playing, reducedMotion, startTimer, clearTimer])

  const goTo = (idx: number) => {
    setStep(idx)
    if (playing && !reducedMotion) startTimer()
  }

  const prev = () => goTo((step - 1 + STEPS.length) % STEPS.length)
  const next = () => goTo((step + 1) % STEPS.length)

  const current = STEPS[step]

  return (
    <div className="hero-layout">
      {/* ── LEFT: browser frame (≈58% width) ── */}
      <div style={{ flex: "0 0 58%", minWidth: 0 }}>
        <div className="hero-browser-frame">
          {/* Top bar: traffic lights + URL pill + LIVE badge */}
          <div className="hero-browser-topbar">
            <div className="hero-traffic-lights" aria-hidden="true">
              <span className="tl tl-red" />
              <span className="tl tl-yellow" />
              <span className="tl tl-green" />
            </div>
            <div className="hero-url-pill" aria-live="polite" aria-label="Current screen URL">
              {BASE_URL}{current.routeSuffix}
            </div>
            <div className="hero-live-badge" aria-hidden="true">
              <span className="hero-live-dot" />
              LIVE
            </div>
          </div>

          {/* Progress bar — only when playing and motion allowed */}
          {!reducedMotion && playing && (
            <div className="hero-progress-track" role="progressbar" aria-hidden="true">
              <div
                key={`progress-${step}`}
                className="hero-progress-fill"
                style={{ animationDuration: `${STEP_DURATION}ms` }}
              />
            </div>
          )}

          {/* Screenshot area
              VIDEO PLACEHOLDER — when the walkthrough recording is ready:
              1. Replace the <div>/<Image> block below with:
                    <video ref={videoRef} src="/demo/walkthrough.mp4" muted autoPlay playsInline
                      poster={current.screenshot} style={{ width: "100%", display: "block" }}
                      onTimeUpdate={handleTimeUpdate} />
              2. Remove the interval-based autoplay (startTimer/clearTimer) and
                 instead sync steps via onTimeUpdate at fixed timestamps.
              3. Keep the progress bar, controls, and copy column as-is — they work with either driver.
          */}
          <div
            key={`screen-${step}`}
            className="hero-screen-wrap"
            style={{ position: "relative", aspectRatio: "16 / 9", background: "#0a0e0d" }}
          >
            <Image
              src={current.screenshot}
              alt={current.alt}
              fill
              style={{ objectFit: "cover", objectPosition: "top" }}
              priority={step === 0}
              sizes="(max-width: 860px) 100vw, 58vw"
            />
          </div>
        </div>
      </div>

      {/* ── RIGHT: copy column (flex: 1) ── */}
      <div style={{ flex: 1, minWidth: "240px", paddingTop: "8px" }}>
        <div key={`copy-${step}`} className="hero-copy-content">
          <p className="hero-eyebrow">{current.pulseLabel}</p>

          <h1
            style={{
              fontSize: "clamp(28px, 3.2vw, 46px)",
              fontWeight: 800,
              letterSpacing: "-0.04em",
              lineHeight: 1.05,
              color: "#edf0fa",
              margin: "0 0 16px",
            }}
          >
            {current.headline}
          </h1>

          <p
            style={{
              fontSize: "16px",
              lineHeight: 1.65,
              color: "rgba(237,240,250,0.58)",
              margin: "0 0 22px",
            }}
          >
            {current.body}
          </p>

          <ul className="hero-bullets" aria-label="Key capabilities">
            {current.bullets.map(b => (
              <li key={b}>
                <span className="hero-bullet-dot" aria-hidden="true">✓</span>
                {b}
              </li>
            ))}
          </ul>
        </div>

        {/* Controls */}
        <div
          style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "28px" }}
          role="group"
          aria-label="Walkthrough controls"
        >
          <button onClick={prev} className="hero-ctrl-btn" aria-label="Previous step">
            ←
          </button>
          {!reducedMotion && (
            <button
              onClick={() => setPlaying(p => !p)}
              className="hero-ctrl-btn"
              aria-label={playing ? "Pause autoplay" : "Resume autoplay"}
            >
              {playing ? "⏸" : "▶"}
            </button>
          )}
          <button onClick={next} className="hero-ctrl-btn" aria-label="Next step">
            →
          </button>

          <div
            style={{ display: "flex", gap: "6px", marginLeft: "8px" }}
            role="tablist"
            aria-label="Walkthrough steps"
          >
            {STEPS.map((s, i) => (
              <button
                key={i}
                onClick={() => goTo(i)}
                className={`hero-step-dot${i === step ? " active" : ""}`}
                aria-label={`Step ${i + 1}: ${s.headline}`}
                aria-selected={i === step}
                role="tab"
              />
            ))}
          </div>
        </div>

        {/* Primary CTAs */}
        <div style={{ display: "flex", gap: "12px", flexWrap: "wrap" }}>
          <a href="/signup" className="hero-cta-primary">
            Get started
          </a>
          <a href="/login" className="hero-cta-secondary">
            Sign in
          </a>
        </div>
      </div>
    </div>
  )
}
```

---

- [ ] **Step 4: Create `src/app/_landing/LandingPage.tsx`**

```typescript
import "./landing.css"
import HeroClient from "./HeroClient"

function LandingNav() {
  return (
    <nav className="l-nav" aria-label="Site navigation">
      <a href="/" className="l-logo" aria-label="Axis Pulse home">
        <span className="l-logo-axis">axis</span>
        <span className="l-logo-pulse">pulse</span>
      </a>
      <div className="l-nav-links">
        <a href="#features" className="l-nav-link">Features</a>
        <a href="#how-it-works" className="l-nav-link">How it works</a>
        <a href="/login" className="l-nav-link">Sign in</a>
        <a href="/signup" className="l-nav-cta">Get started</a>
      </div>
    </nav>
  )
}

export default function LandingPage() {
  return (
    <div style={{ background: "#0a0e0d", minHeight: "100vh", color: "#edf0fa" }}>
      <LandingNav />
      <main>
        {/* ── HERO ── */}
        <section
          aria-label="Product walkthrough"
          style={{ padding: "72px 32px 96px", maxWidth: "1360px", margin: "0 auto" }}
        >
          <HeroClient />
        </section>

        {/* Remaining sections added in Tasks 2–4 */}
      </main>

      {/* Footer added in Task 4 */}
    </div>
  )
}
```

---

- [ ] **Step 5: Modify `src/app/page.tsx` — replace the placeholder component**

Replace the entire file with:

```typescript
import { auth } from "@/lib/auth"
import { redirect } from "next/navigation"
import LandingPage from "./_landing/LandingPage"

export default async function HomePage() {
  const session = await auth()
  if (session) redirect("/dashboard")
  return <LandingPage />
}
```

---

- [ ] **Step 6: Run `tsc --noEmit`**

```powershell
npx tsc --noEmit
```

Expected: 0 errors. If Image component errors appear, confirm `next/image` is imported (not `next/dist/...`).

---

- [ ] **Step 7: Run `npm run build`**

```powershell
npm run build
```

Expected: Build succeeds. Verify the output includes `○ /` (static) and no ESLint warnings on the new files.

If an ESLint `no-unused-vars` error appears for `reducedMotion` or similar, fix the reference before continuing.

---

- [ ] **Step 8: Commit**

```powershell
git add public/demo/ src/app/_landing/ src/app/page.tsx
git commit -m "feat(landing): page shell + hero walkthrough with 5-step browser frame sync"
```

> **⚠️ STOP HERE — wait for human review before Task 2.**

---

## Task 2: Proof Strip + Feature Deep-Dives

**Files:**
- Create: `src/app/_landing/ProofStrip.tsx`
- Create: `src/app/_landing/FeatureDeepDives.tsx`
- Modify: `src/app/_landing/LandingPage.tsx`

---

- [ ] **Step 1: Create `src/app/_landing/ProofStrip.tsx`**

```typescript
const PROOFS = [
  { number: "1,773",          label: "test cases" },
  { number: "$0",             label: "AI cost when idle" },
  { number: "On-device",      label: "classification" },
  { number: "3",              label: "security scanners" },
]

export default function ProofStrip() {
  return (
    <div className="proof-strip">
      <div className="proof-strip-inner">
        {PROOFS.map(p => (
          <div key={p.label} style={{ textAlign: "center" }}>
            <span
              style={{
                fontFamily: "var(--font-mono, monospace)",
                fontSize: "22px",
                fontWeight: 600,
                color: "#0ee29e",
                display: "block",
              }}
            >
              {p.number}
            </span>
            <span
              style={{
                fontSize: "12px",
                color: "rgba(237,240,250,0.4)",
                display: "block",
                marginTop: "3px",
                letterSpacing: "0.02em",
              }}
            >
              {p.label}
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}
```

---

- [ ] **Step 2: Create `src/app/_landing/FeatureDeepDives.tsx`**

```typescript
import Image from "next/image"

type Feature = {
  number: string
  title: string
  body: string
  detail: string
  screenshot: string | null
  screenshotAlt: string
  placeholderLabel?: string
}

const FEATURES: Feature[] = [
  {
    number: "01",
    title: "Spend to the dollar",
    body: "Every Claude Code Stop hook fires a transcript read that extracts per-turn token counts by model and stores the pre-computed USD figure. Six Claude model tiers. No approximations.",
    detail: "Source: src/lib/dev-cost.ts · ActivityEvent.claudeSpendUSD · /admin/cost-dashboard",
    screenshot: "/demo/screen3-cost-dashboard.png",
    screenshotAlt: "Cost dashboard showing today's AI spend, 14-day trend chart, and per-developer USD breakdown",
  },
  {
    number: "02",
    title: "AI-narrated stories",
    body: "Narration fires only when the SHA-256 hash of the current input differs from the last Intelligence row. A project with no new meaningful activity generates zero AI calls and zero cost.",
    detail: "Delta-gated via inputHash · 90-second cooldown · OpenRouter free chain → Groq fallback",
    screenshot: "/demo/screen2-marlin-detail.png",
    screenshotAlt: "Project detail showing verdict banner, AI narration paragraph, and sentence-level highlights",
  },
  {
    number: "03",
    title: "Context drift detection",
    body: "When code diverges from the repo context snapshot, Axis Pulse fetches the GitHub Compare diff and runs AI analysis against the stored context. Risk levels are GREEN / AMBER / RED with structured findings.",
    detail: "Volume trigger at 50 events or on demand · src/lib/drift/ · ContextDriftAssessment model",
    screenshot: "/demo/screen4-context-analyser.png",
    screenshotAlt: "Context analyser page showing projects with GREEN, AMBER, and RED drift risk chips",
  },
  {
    number: "04",
    title: "Honest completion",
    body: "The Completion Status Widget shows four factual cells: marker count (TODO / FIXME / skipped tests / stubs), code coverage %, security scan state, and an OLS linear regression forecast of days to zero markers. No aggregated percentage is ever computed.",
    detail: "ADR-readiness-001: aggregated % explicitly rejected · src/components/CompletionStatusWidget",
    screenshot: "/demo/screen2-marlin-lower.png",
    screenshotAlt: "Completion widget showing 4 honest cells: markers, coverage percentage, scan state, and OLS forecast",
  },
  {
    number: "05",
    title: "Privacy by construction",
    body: "The ActivityWatch agent runs on the developer's machine. Only three integers (worked / productive / unproductive seconds) and optionally top-app names leave the device. No screenshots. No process lists. Classification happens locally before transmission.",
    detail: "src/lib/productive-rules.ts · ADR-013 · Consent version required before topApps are sent",
    screenshot: null,
    screenshotAlt: "",
    placeholderLabel: "screenshot of on-device classification / consent flow\n— to be added",
  },
]

function FeatureRow({ feature, reversed }: { feature: Feature; reversed: boolean }) {
  const textCol = (
    <div style={{ flex: 1, minWidth: 0 }}>
      <p
        style={{
          fontFamily: "var(--font-mono, monospace)",
          fontSize: "11px",
          fontWeight: 500,
          letterSpacing: "0.1em",
          color: "rgba(14,226,158,0.45)",
          textTransform: "uppercase",
          margin: "0 0 12px",
        }}
      >
        {feature.number} /
      </p>
      <h3
        style={{
          fontSize: "clamp(20px, 2.2vw, 30px)",
          fontWeight: 800,
          letterSpacing: "-0.03em",
          color: "#edf0fa",
          margin: "0 0 14px",
          lineHeight: 1.15,
        }}
      >
        {feature.title}
      </h3>
      <p
        style={{
          fontSize: "15px",
          lineHeight: 1.7,
          color: "rgba(237,240,250,0.55)",
          margin: "0 0 16px",
        }}
      >
        {feature.body}
      </p>
      <p
        style={{
          fontFamily: "var(--font-mono, monospace)",
          fontSize: "12px",
          lineHeight: 1.6,
          color: "rgba(237,240,250,0.3)",
          borderLeft: "2px solid rgba(14,226,158,0.18)",
          paddingLeft: "12px",
          margin: 0,
        }}
      >
        {feature.detail}
      </p>
    </div>
  )

  const imageCol = (
    <div style={{ flex: 1, minWidth: 0 }}>
      {feature.screenshot ? (
        <div
          className="feature-screenshot"
          style={{ position: "relative", aspectRatio: "16 / 9" }}
        >
          <Image
            src={feature.screenshot}
            alt={feature.screenshotAlt}
            fill
            style={{ objectFit: "cover", objectPosition: "top" }}
            sizes="(max-width: 860px) 100vw, 50vw"
          />
        </div>
      ) : (
        <div className="feature-placeholder">
          <span
            style={{
              fontFamily: "var(--font-mono, monospace)",
              fontSize: "12px",
              color: "rgba(255,255,255,0.18)",
              textAlign: "center",
              padding: "16px",
              lineHeight: 1.6,
              whiteSpace: "pre-line",
            }}
          >
            {feature.placeholderLabel}
          </span>
        </div>
      )}
    </div>
  )

  return (
    <div className={`feature-row${reversed ? " reversed" : ""}`}>
      {textCol}
      {imageCol}
    </div>
  )
}

export default function FeatureDeepDives() {
  return (
    <section
      id="features"
      aria-labelledby="features-heading"
      style={{ padding: "100px 32px", maxWidth: "1200px", margin: "0 auto" }}
    >
      <div style={{ textAlign: "center", marginBottom: "80px" }}>
        <h2
          id="features-heading"
          style={{
            fontSize: "clamp(24px, 2.8vw, 38px)",
            fontWeight: 800,
            letterSpacing: "-0.04em",
            color: "#edf0fa",
            margin: "0 0 12px",
          }}
        >
          What it actually does
        </h2>
        <p style={{ fontSize: "16px", color: "rgba(237,240,250,0.45)", margin: 0 }}>
          Every claim is grounded in wired, tested code — not a roadmap.
        </p>
      </div>

      {FEATURES.map((f, i) => (
        <FeatureRow key={f.number} feature={f} reversed={i % 2 === 1} />
      ))}
    </section>
  )
}
```

---

- [ ] **Step 3: Wire both into `LandingPage.tsx`**

Replace the `{/* Remaining sections */}` comment in `LandingPage.tsx` with:

```typescript
import ProofStrip from "./ProofStrip"
import FeatureDeepDives from "./FeatureDeepDives"
```

And replace the comment placeholder inside `<main>`:

```tsx
        {/* ── PROOF STRIP ── */}
        <ProofStrip />

        {/* ── FEATURE DEEP-DIVES ── */}
        <FeatureDeepDives />

        {/* Remaining sections added in Tasks 3–4 */}
```

---

- [ ] **Step 4: Run `tsc --noEmit` and `npm run build`**

```powershell
npx tsc --noEmit
npm run build
```

Expected: 0 errors, build passes.

---

- [ ] **Step 5: Commit**

```powershell
git add src/app/_landing/
git commit -m "feat(landing): proof strip + 5 feature deep-dive sections with screenshots"
```

---

## Task 3: How It Works

**Files:**
- Create: `src/app/_landing/HowItWorks.tsx`
- Modify: `src/app/_landing/LandingPage.tsx`

---

- [ ] **Step 1: Create `src/app/_landing/HowItWorks.tsx`**

```typescript
const STEPS = [
  {
    n: "01",
    title: "Install on your server",
    body: "Deploy to a Coolify instance. Add your GitHub App. The install page gives you exact PowerShell commands for the hook scripts and Windows Task Scheduler registration.",
  },
  {
    n: "02",
    title: "Team codes with Claude Code",
    body: "Each developer adds two hook scripts to their Claude Code settings: pulse-send.mjs (Stop hook) and pulse-time.mjs (5-min ActivityWatch poll). They run locally — no cloud agent, no screensharing.",
  },
  {
    n: "03",
    title: "Pulse narrates automatically",
    body: "Every Stop hook fires a narration. Delta gating skips identical runs. Your dashboard shows live narrations, spend, completion signals, and drift alerts — updated each session.",
  },
]

export default function HowItWorks() {
  return (
    <section
      id="how-it-works"
      aria-labelledby="how-heading"
      style={{
        padding: "100px 32px",
        background: "rgba(255,255,255,0.018)",
        borderTop: "1px solid rgba(255,255,255,0.055)",
        borderBottom: "1px solid rgba(255,255,255,0.055)",
      }}
    >
      <div style={{ maxWidth: "1200px", margin: "0 auto" }}>
        <div style={{ textAlign: "center", marginBottom: "60px" }}>
          <h2
            id="how-heading"
            style={{
              fontSize: "clamp(24px, 2.8vw, 38px)",
              fontWeight: 800,
              letterSpacing: "-0.04em",
              color: "#edf0fa",
              margin: "0 0 12px",
            }}
          >
            How it works
          </h2>
          <p
            style={{
              fontSize: "15px",
              color: "rgba(237,240,250,0.42)",
              margin: "0 auto",
              maxWidth: "420px",
              lineHeight: 1.6,
            }}
          >
            Self-hosted. No cloud agent. Two hook scripts and a self-hosted Coolify deployment.
          </p>
        </div>

        <div className="how-steps" role="list">
          {STEPS.map(s => (
            <div
              key={s.n}
              role="listitem"
              style={{ flex: 1, textAlign: "center", padding: "0 28px" }}
            >
              <div
                style={{
                  width: "56px",
                  height: "56px",
                  borderRadius: "50%",
                  background: "rgba(14,226,158,0.07)",
                  border: "1px solid rgba(14,226,158,0.22)",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  margin: "0 auto 20px",
                  fontFamily: "var(--font-mono, monospace)",
                  fontSize: "16px",
                  fontWeight: 600,
                  color: "#0ee29e",
                }}
                aria-hidden="true"
              >
                {s.n}
              </div>
              <h3
                style={{
                  fontSize: "17px",
                  fontWeight: 700,
                  color: "#edf0fa",
                  margin: "0 0 10px",
                  letterSpacing: "-0.02em",
                }}
              >
                {s.title}
              </h3>
              <p
                style={{
                  fontSize: "14px",
                  color: "rgba(237,240,250,0.48)",
                  margin: 0,
                  lineHeight: 1.65,
                }}
              >
                {s.body}
              </p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
```

---

- [ ] **Step 2: Wire into `LandingPage.tsx`**

Add import at top of `LandingPage.tsx`:
```typescript
import HowItWorks from "./HowItWorks"
```

Replace `{/* Remaining sections added in Tasks 3–4 */}` with:
```tsx
        {/* ── HOW IT WORKS ── */}
        <HowItWorks />

        {/* Remaining sections added in Task 4 */}
```

---

- [ ] **Step 3: `tsc --noEmit` + commit**

```powershell
npx tsc --noEmit
git add src/app/_landing/
git commit -m "feat(landing): how it works 3-beat section"
```

---

## Task 4: Who It's For + Final CTA Band + Footer

**Files:**
- Create: `src/app/_landing/WhoItsFor.tsx`
- Create: `src/app/_landing/CtaBand.tsx`
- Create: `src/app/_landing/LandingFooter.tsx`
- Modify: `src/app/_landing/LandingPage.tsx`

---

- [ ] **Step 1: Create `src/app/_landing/WhoItsFor.tsx`**

```typescript
const CARDS = [
  {
    role: "Engineering managers",
    detail: "at consultancies running Claude Code",
    body: "Know whether a project is moving, stalled, or at risk — without reading code. See Claude Code spend per developer. Get a defensible executive summary with one click.",
  },
  {
    role: "Line managers",
    detail: "of 3–10 developer teams",
    body: "Team-specific views: who is active now, what they committed, whether your prompts are being followed. Time data is transparent and privacy-preserving — developers consent and know exactly what is sent.",
  },
]

const NOT_A_FIT = [
  "Individual developers tracking their own work (MEMBER role has read-only self-views only)",
  "Large enterprises needing SSO or MFA — these are not implemented",
  "Teams not using Claude Code — the pipeline is built around Claude Code hook events",
]

export default function WhoItsFor() {
  return (
    <section
      aria-labelledby="who-heading"
      style={{ padding: "100px 32px", maxWidth: "1200px", margin: "0 auto" }}
    >
      <div style={{ textAlign: "center", marginBottom: "48px" }}>
        <h2
          id="who-heading"
          style={{
            fontSize: "clamp(24px, 2.8vw, 38px)",
            fontWeight: 800,
            letterSpacing: "-0.04em",
            color: "#edf0fa",
            margin: "0 0 12px",
          }}
        >
          Who it&apos;s for
        </h2>
        <p style={{ fontSize: "15px", color: "rgba(237,240,250,0.42)", margin: 0 }}>
          Built for managers at consulting firms running Claude Code. Not a fit for every team.
        </p>
      </div>

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "1fr 1fr",
          gap: "20px",
          marginBottom: "48px",
        }}
      >
        {CARDS.map(c => (
          <div key={c.role} className="who-card">
            <p
              style={{
                fontFamily: "var(--font-mono, monospace)",
                fontSize: "10px",
                fontWeight: 500,
                letterSpacing: "0.1em",
                color: "rgba(14,226,158,0.45)",
                textTransform: "uppercase",
                margin: "0 0 4px",
              }}
            >
              {c.detail}
            </p>
            <h3
              style={{
                fontSize: "16px",
                fontWeight: 700,
                color: "#edf0fa",
                margin: "0 0 10px",
                letterSpacing: "-0.02em",
              }}
            >
              {c.role}
            </h3>
            <p style={{ fontSize: "14px", color: "rgba(237,240,250,0.48)", margin: 0, lineHeight: 1.65 }}>
              {c.body}
            </p>
          </div>
        ))}
      </div>

      <div
        style={{
          background: "rgba(255,77,106,0.04)",
          border: "1px solid rgba(255,77,106,0.12)",
          borderRadius: "10px",
          padding: "24px 28px",
        }}
      >
        <p
          style={{
            fontFamily: "var(--font-mono, monospace)",
            fontSize: "11px",
            fontWeight: 600,
            letterSpacing: "0.08em",
            color: "rgba(255,77,106,0.6)",
            textTransform: "uppercase",
            margin: "0 0 12px",
          }}
        >
          Not a fit
        </p>
        <ul style={{ listStyle: "none", padding: 0, margin: 0, display: "flex", flexDirection: "column", gap: "8px" }}>
          {NOT_A_FIT.map(item => (
            <li
              key={item}
              style={{ fontSize: "13px", color: "rgba(237,240,250,0.38)", display: "flex", gap: "8px" }}
            >
              <span style={{ color: "rgba(255,77,106,0.45)", flexShrink: 0 }}>×</span>
              {item}
            </li>
          ))}
        </ul>
      </div>
    </section>
  )
}
```

---

- [ ] **Step 2: Create `src/app/_landing/CtaBand.tsx`**

```typescript
export default function CtaBand() {
  return (
    <section
      aria-labelledby="cta-heading"
      style={{
        padding: "88px 32px",
        textAlign: "center",
        background: "linear-gradient(135deg, rgba(14,226,158,0.04) 0%, transparent 60%)",
        borderTop: "1px solid rgba(14,226,158,0.1)",
      }}
    >
      <div style={{ maxWidth: "600px", margin: "0 auto" }}>
        <h2
          id="cta-heading"
          style={{
            fontSize: "clamp(24px, 3vw, 40px)",
            fontWeight: 800,
            letterSpacing: "-0.04em",
            color: "#edf0fa",
            margin: "0 0 16px",
            lineHeight: 1.15,
          }}
        >
          Stop guessing what your AI team is doing.
        </h2>
        <p
          style={{
            fontSize: "16px",
            color: "rgba(237,240,250,0.48)",
            margin: "0 0 32px",
            lineHeight: 1.6,
          }}
        >
          Self-hosted. Honest signals. No fake metrics.
        </p>
        <a href="/signup" className="cta-band-btn">
          Get started
        </a>
      </div>
    </section>
  )
}
```

---

- [ ] **Step 3: Create `src/app/_landing/LandingFooter.tsx`**

```typescript
export default function LandingFooter() {
  return (
    <footer
      style={{
        borderTop: "1px solid rgba(255,255,255,0.055)",
        padding: "32px",
      }}
    >
      <div
        style={{
          maxWidth: "1200px",
          margin: "0 auto",
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          flexWrap: "wrap",
          gap: "16px",
        }}
      >
        <span
          style={{
            fontFamily: "var(--font-mono, monospace)",
            fontSize: "13px",
            color: "rgba(237,240,250,0.25)",
          }}
        >
          © 2026 Axis Pulse
        </span>

        <nav aria-label="Footer links" style={{ display: "flex", gap: "24px" }}>
          <a href="/privacy" className="l-footer-link">Privacy</a>
          <a href="/terms" className="l-footer-link">Terms</a>
          <a href="/login" className="l-footer-link">Sign in</a>
        </nav>
      </div>
    </footer>
  )
}
```

---

- [ ] **Step 4: Wire all three into `LandingPage.tsx` — final version**

Replace the full `LandingPage.tsx` with the wired-up version:

```typescript
import "./landing.css"
import HeroClient from "./HeroClient"
import ProofStrip from "./ProofStrip"
import FeatureDeepDives from "./FeatureDeepDives"
import HowItWorks from "./HowItWorks"
import WhoItsFor from "./WhoItsFor"
import CtaBand from "./CtaBand"
import LandingFooter from "./LandingFooter"

function LandingNav() {
  return (
    <nav className="l-nav" aria-label="Site navigation">
      <a href="/" className="l-logo" aria-label="Axis Pulse home">
        <span className="l-logo-axis">axis</span>
        <span className="l-logo-pulse">pulse</span>
      </a>
      <div className="l-nav-links">
        <a href="#features" className="l-nav-link">Features</a>
        <a href="#how-it-works" className="l-nav-link">How it works</a>
        <a href="/login" className="l-nav-link">Sign in</a>
        <a href="/signup" className="l-nav-cta">Get started</a>
      </div>
    </nav>
  )
}

export default function LandingPage() {
  return (
    <div style={{ background: "#0a0e0d", minHeight: "100vh", color: "#edf0fa" }}>
      <LandingNav />
      <main>
        <section
          aria-label="Product walkthrough"
          style={{ padding: "72px 32px 96px", maxWidth: "1360px", margin: "0 auto" }}
        >
          <HeroClient />
        </section>

        <ProofStrip />
        <FeatureDeepDives />
        <HowItWorks />
        <WhoItsFor />
        <CtaBand />
      </main>
      <LandingFooter />
    </div>
  )
}
```

---

- [ ] **Step 5: `tsc --noEmit` + `npm run build`**

```powershell
npx tsc --noEmit
npm run build
```

Expected: 0 errors. Build succeeds. Check that all 7 sections are in the build output for `/`.

---

- [ ] **Step 6: Commit and push**

```powershell
git add src/app/_landing/
git commit -m "feat(landing): who it's for + final CTA band + footer — all 7 sections complete"
git push origin afthab/axis-pulse
```

---

## Task 5: Mobile responsive polish + `npm run build` final check

> Run this task only after Task 4 is committed. Validate the page is responsive at <860px and tsc + build are clean.

**Files:**
- Possibly modify: `src/app/_landing/landing.css` (adjust breakpoints if needed after visual check)
- Possibly modify: `src/app/_landing/WhoItsFor.tsx` (who-cards grid → 1-col on mobile)

---

- [ ] **Step 1: Add mobile grid override for who-cards**

In `landing.css`, confirm the `@media (max-width: 860px)` block includes `who-cards` grid collapse. If it's missing, add:

```css
@media (max-width: 860px) {
  /* (already present: hero-layout, feature-row, how-steps) */
  
  /* Add this if missing: */
  .who-card-grid {
    grid-template-columns: 1fr !important;
  }
}
```

Then add `className="who-card-grid"` to the who-cards wrapping `<div>` in `WhoItsFor.tsx`.

---

- [ ] **Step 2: Verify the `@media (max-width: 640px)` nav links rule is present**

In `landing.css`, confirm:
```css
@media (max-width: 640px) {
  .l-nav-links .l-nav-link { display: none; }
}
```

This hides the "Features" / "How it works" text links on narrow screens while keeping the CTA button visible.

---

- [ ] **Step 3: Final `tsc --noEmit` + `npm run build`**

```powershell
npx tsc --noEmit
npm run build
```

Expected: 0 errors. Build succeeds.

---

- [ ] **Step 4: Final commit + push**

```powershell
git add src/app/_landing/ src/app/page.tsx
git commit -m "chore(landing): mobile responsive polish + build verified"
git push origin afthab/axis-pulse
```

---

## Self-Review

### Spec coverage check

| Spec requirement | Covered by |
|---|---|
| HERO — browser frame with traffic lights, URL pill, LIVE badge, progress bar | Task 1 `HeroClient.tsx` |
| Hero auto-plays 5 steps, syncs URL + copy + screenshot + progress | Task 1 `HeroClient.tsx` (interval + `key` reset) |
| Pause/prev/next control | Task 1 `HeroClient.tsx` |
| `prefers-reduced-motion`: no autoplay, static step 1, manual controls | Task 1 `HeroClient.tsx` `useEffect` |
| VIDEO: placeholder box with label, sync mechanism comment for real video | Task 1 — `<Image>` with `// VIDEO PLACEHOLDER` comment; full comment describes exact swap |
| Stacks on mobile <860px | `landing.css` `@media (max-width: 860px)` `.hero-layout` |
| PROOF STRIP — 4 numbers, mono font | Task 2 `ProofStrip.tsx` |
| 1,773 test cases · $0 AI cost when idle · On-device classification · 3 security scanners | Task 2 `ProofStrip.tsx` PROOFS array |
| FEATURE DEEP-DIVES — 5 alternating L/R sections | Task 2 `FeatureDeepDives.tsx` |
| Screenshots for sections 1–4 | Task 2 — real PNGs from `public/demo/` |
| Section 5 "Privacy by construction" — placeholder flagged | Task 2 — `screenshot: null` + `placeholderLabel` |
| HOW IT WORKS — 3-beat, honest about self-hosted + hook install | Task 3 `HowItWorks.tsx` |
| WHO IT'S FOR — engineering & line managers at consultancies | Task 4 `WhoItsFor.tsx` |
| "Not a fit" block | Task 4 `WhoItsFor.tsx` NOT_A_FIT array |
| FINAL CTA — "Stop guessing what your AI team is doing." + Get started → /signup | Task 4 `CtaBand.tsx` |
| FOOTER — logo, © 2026, /privacy /terms, Sign in → /login | Task 4 `LandingFooter.tsx` |
| CTA: "Get started" → /signup, "Sign in" → /login | Task 1 HeroClient + Task 4 nav + CtaBand |
| NO: "real-time", MFA, email invites, self-serve org provisioning, WebSocket, managed SonarQube | None of these phrases appear in any component |
| Inline styles + CSS vars, NO Tailwind | All files — no `className="..."` Tailwind classes, all layout via `style={{}}` and `landing.css` |
| Keyboard focus / accessibility | `aria-label`, `aria-live`, `role="tab"`, `role="list"`, `focus-visible` in CSS |

### Placeholder scan

- `public/demo/` image filenames — plan uses exact filenames consistent throughout
- Feature 5 placeholder — flagged explicitly with `placeholderLabel`
- Video placeholder — clearly commented with exact swap instructions

### Type consistency

- `STEPS` in `HeroClient` is `as const` — `current.pulseLabel`, `current.routeSuffix`, `current.headline`, `current.body`, `current.bullets`, `current.screenshot`, `current.alt` are consistent throughout the component
- `Feature` type in `FeatureDeepDives` — `screenshot: string | null`, `placeholderLabel?: string` — guards present in `FeatureRow`
