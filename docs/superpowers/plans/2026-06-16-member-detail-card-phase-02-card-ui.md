# Member Detail Card Phase 02 — Card UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the member detail card UI, wire it to `/admin/members` row clicks, and render it as a createPortal modal that shows the Phase 1 route's data without re-implementing any of its gating.

**Architecture:** A pure fetch utility (`fetchMemberCard`) wraps the Phase 1 route and is the only testable unit (project uses node vitest environment — no jsdom). The `MemberDetailCard` component consumes that utility, renders four sections gated only on what the route returned (no client-side role logic), and is mounted to `document.body` via `createPortal`. `MembersClient` gains a "View" button per row that sets `selectedMemberId`, which triggers the portal.

**Tech Stack:** Next.js 14 App Router · React `createPortal` · Vitest (node env) · existing design tokens (teal `#0ee29e`, IBM Plex Mono + Plus Jakarta Sans, flat dark cards)

**Baseline:** 1515 tests green.

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/app/admin/members/_components/member-card-fetch.ts` | Pure async fetch utility — only testable unit for this feature |
| Create | `src/app/admin/members/_components/MemberDetailCard.tsx` | Full card UI component (Header + 4 sections), portal-mounted |
| Modify | `src/app/admin/members/_components/MembersClient.tsx` | Add `selectedMemberId` state + "View" button per row + render portal |
| Create | `src/tests/member-card-fetch.test.ts` | 4 unit tests for fetchMemberCard (200, 403, 404, 429) |
| Modify | `docs/structure.md` | Add 2 new component files |
| Modify | `docs/code.md` | Add fetchMemberCard signature |

---

## Task 1: fetchMemberCard utility + tests

> TDD anchor. This is the only unit the node environment can test directly.

**Files:**
- Create: `src/app/admin/members/_components/member-card-fetch.ts`
- Create: `src/tests/member-card-fetch.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// src/tests/member-card-fetch.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"
import { fetchMemberCard } from "@/app/admin/members/_components/member-card-fetch"

const makeRes = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  })

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})
afterEach(() => {
  vi.unstubAllGlobals()
})

describe("fetchMemberCard", () => {
  it("returns ok:true with parsed data on 200", async () => {
    vi.mocked(fetch).mockResolvedValue(makeRes(200, { identity: { id: "u-1", name: "Alice" } }))
    const result = await fetchMemberCard("u-1")
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.data).toMatchObject({ identity: { id: "u-1" } })
    expect(fetch).toHaveBeenCalledWith("/api/admin/members/u-1/card")
  })

  it("returns ok:false status:403 for cross-team/forbidden member", async () => {
    vi.mocked(fetch).mockResolvedValue(makeRes(403, { reason: "forbidden" }))
    const result = await fetchMemberCard("u-2")
    expect(result.ok).toBe(false)
    if (!result.ok) expect(result.status).toBe(403)
  })

  it("returns ok:false status:404 when member not found", async () => {
    vi.mocked(fetch).mockResolvedValue(makeRes(404, { reason: "not_found" }))
    const result = await fetchMemberCard("u-3")
    expect(result.ok).toBe(false)
    if (!result.ok) expect(result.status).toBe(404)
  })

  it("returns ok:false status:429 when rate limited", async () => {
    vi.mocked(fetch).mockResolvedValue(makeRes(429, { error: "Rate limit exceeded" }))
    const result = await fetchMemberCard("u-4")
    expect(result.ok).toBe(false)
    if (!result.ok) expect(result.status).toBe(429)
  })
})
```

- [ ] **Step 2: Run the test — confirm it fails**

```
cd C:\Users\AfthabAnthas\repos\axis-pulse
npx vitest run src/tests/member-card-fetch.test.ts
```

Expected output: FAIL with `Cannot find module '@/app/admin/members/_components/member-card-fetch'`

- [ ] **Step 3: Implement the utility**

```typescript
// src/app/admin/members/_components/member-card-fetch.ts
import type { MemberCardData } from "@/lib/member-card"

export type MemberCardResult =
  | { ok: true; data: MemberCardData }
  | { ok: false; status: number }

export async function fetchMemberCard(memberId: string): Promise<MemberCardResult> {
  const res = await fetch(`/api/admin/members/${memberId}/card`)
  if (res.ok) return { ok: true, data: (await res.json()) as MemberCardData }
  return { ok: false, status: res.status }
}
```

- [ ] **Step 4: Run the test — confirm all 4 pass**

```
npx vitest run src/tests/member-card-fetch.test.ts
```

Expected: `4 passed`

- [ ] **Step 5: Run full suite — confirm 1519 pass (baseline 1515 + 4)**

```
npx vitest run
```

Expected: `1519 passed`

- [ ] **Step 6: Commit**

```
git add src/app/admin/members/_components/member-card-fetch.ts src/tests/member-card-fetch.test.ts
git commit -m "feat(member-card): add fetchMemberCard utility with 4 route-status tests"
```

**→ STOP HERE. Show the test run output to the user before proceeding.**

---

## Task 2: MemberDetailCard component

> Full portal-mounted card. No new tests — pure presentation.
> CRITICAL: every section renders only what the route returned. Zero client-side role logic.

**Files:**
- Create: `src/app/admin/members/_components/MemberDetailCard.tsx`

- [ ] **Step 1: Create the component file**

```tsx
// src/app/admin/members/_components/MemberDetailCard.tsx
"use client"

import { useState, useEffect } from "react"
import { createPortal } from "react-dom"
import { fetchMemberCard } from "./member-card-fetch"
import type { MemberCardData, TimeDataV2, TopApps } from "@/lib/member-card"

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtDuration(sec: number | null): string {
  if (!sec || sec <= 0) return "—"
  const m = Math.floor(sec / 60)
  if (m < 60) return `${m}m`
  const h = Math.floor(m / 60)
  const rem = m % 60
  return rem > 0 ? `${h}h ${rem}m` : `${h}h`
}

function fmtDate(iso: string): string {
  return new Date(iso).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  })
}

function fmtRelative(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const mins = Math.floor(diff / 60_000)
  if (mins < 1) return "just now"
  if (mins < 60) return `${mins}m ago`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 30) return `${days}d ago`
  return fmtDate(iso)
}

function fmtUSD(n: number): string {
  return n < 0.01 ? "<$0.01" : `$${n.toFixed(2)}`
}

function fmtTokens(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(0)}K`
  return String(n)
}

function fmtSeconds(sec: number): string {
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  if (h === 0) return `${m}m`
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

function roleBadgeStyle(role: string): React.CSSProperties {
  const base: React.CSSProperties = {
    display: "inline-flex",
    alignItems: "center",
    padding: "2px 8px",
    borderRadius: "6px",
    fontSize: "11px",
    fontWeight: 600,
    letterSpacing: "0.05em",
    textTransform: "uppercase",
    whiteSpace: "nowrap",
    fontFamily: "var(--font-mono)",
  }
  if (role === "MANAGER") return { ...base, background: "var(--background-chip)", color: "var(--text-muted)" }
  if (role === "LINE_MANAGER") return { ...base, background: "rgba(14,226,158,0.15)", color: "#0ee29e" }
  return { ...base, background: "var(--surface-subtle)", color: "var(--text-secondary)" }
}

// ── Section shell ─────────────────────────────────────────────────────────────

function Section({ title, note, children }: { title: string; note?: string; children: React.ReactNode }) {
  return (
    <div style={{ marginBottom: "20px" }}>
      <div style={{ display: "flex", alignItems: "baseline", gap: "10px", marginBottom: "10px" }}>
        <h3 style={{
          margin: 0,
          fontSize: "11px",
          fontWeight: 700,
          textTransform: "uppercase",
          letterSpacing: "0.08em",
          color: "var(--text-muted)",
          fontFamily: "var(--font-mono)",
        }}>
          {title}
        </h3>
        {note && (
          <span style={{ fontSize: "10.5px", color: "var(--text-hint, #4b5563)", fontFamily: "var(--font-sans)", fontStyle: "italic" }}>
            {note}
          </span>
        )}
      </div>
      {children}
    </div>
  )
}

function EmptyState({ text }: { text: string }) {
  return (
    <p style={{ margin: 0, fontSize: "12.5px", color: "var(--text-muted)", fontFamily: "var(--font-sans)", fontStyle: "italic" }}>
      {text}
    </p>
  )
}

// ── Section A: AI Usage & Spend ───────────────────────────────────────────────
// Rendered only when data.spend is present in the response (route omits it for non-permitted roles).

function SpendSection({ spend }: { spend: NonNullable<MemberCardData["spend"]> }) {
  const totalTokens = spend.allTimeInputTokens + spend.allTimeOutputTokens
  const stats = [
    { label: "All-time spend", value: fmtUSD(spend.allTimeSpendUSD) },
    { label: "Tokens (I/O)", value: fmtTokens(totalTokens) },
    { label: "Sessions", value: String(spend.sessionCount) },
    { label: "Avg duration", value: fmtDuration(spend.avgSessionDurationSeconds) },
  ]
  return (
    <Section title="AI Usage & Spend" note="from ingested sessions">
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "8px" }}>
        {stats.map(({ label, value }) => (
          <div key={label} style={{
            padding: "10px 14px",
            background: "var(--surface-subtle, rgba(255,255,255,0.03))",
            border: "1px solid var(--border)",
            borderRadius: "8px",
          }}>
            <div style={{ fontSize: "18px", fontWeight: 700, color: "var(--text-primary)", fontFamily: "var(--font-mono)", letterSpacing: "-0.02em" }}>
              {value}
            </div>
            <div style={{ fontSize: "11px", color: "var(--text-muted)", marginTop: "2px", fontFamily: "var(--font-sans)" }}>
              {label}
            </div>
          </div>
        ))}
      </div>
    </Section>
  )
}

// ── Section B: Time at Keyboard ───────────────────────────────────────────────
// Renders whatever state the route returned — never re-gates on consent.

function TimeSection({ time }: { time: MemberCardData["time"] }) {
  if (time.reason === "not_consented") {
    return (
      <Section title="Time at Keyboard">
        <EmptyState text="Hasn't consented to time tracking" />
      </Section>
    )
  }

  const v2 = time as TimeDataV2
  const totalWorked = v2.week.reduce((s, d) => s + d.workedSeconds, 0)
  const totalProductive = v2.week.reduce((s, d) => s + d.productiveSeconds, 0)
  const totalUnproductive = v2.week.reduce((s, d) => s + d.unproductiveSeconds, 0)
  const maxWorked = Math.max(...v2.week.map((d) => d.workedSeconds), 1)

  // Collect app names from all days (only present for v2 consent)
  const appMap = new Map<string, { productive: boolean; seconds: number }>()
  for (const day of v2.week) {
    const apps = day.topApps as TopApps | null
    if (!apps) continue
    for (const a of apps.productive) {
      const prev = appMap.get(a.app)
      appMap.set(a.app, { productive: true, seconds: (prev?.seconds ?? 0) + a.seconds })
    }
    for (const a of apps.unproductive) {
      const prev = appMap.get(a.app)
      appMap.set(a.app, { productive: false, seconds: (prev?.seconds ?? 0) + a.seconds })
    }
  }
  const topApps = [...appMap.entries()]
    .sort((a, b) => b[1].seconds - a[1].seconds)
    .slice(0, 6)

  if (v2.week.length === 0) {
    return (
      <Section title="Time at Keyboard">
        <EmptyState text="No time data in the last 7 days" />
      </Section>
    )
  }

  return (
    <Section title="Time at Keyboard">
      {/* 7-day stacked bar */}
      <div style={{ display: "flex", gap: "4px", alignItems: "flex-end", height: "52px", marginBottom: "8px" }}>
        {v2.week.map((day) => {
          const total = day.workedSeconds
          const pct = (total / maxWorked) * 100
          const productivePct = total > 0 ? (day.productiveSeconds / total) * 100 : 0
          const unproductivePct = total > 0 ? (day.unproductiveSeconds / total) * 100 : 0
          const dayLabel = new Date(day.day).toLocaleDateString("en-GB", { weekday: "short" }).slice(0, 1)
          return (
            <div key={day.day} style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", gap: "3px" }}>
              <div style={{ width: "100%", height: "40px", display: "flex", flexDirection: "column", justifyContent: "flex-end" }}>
                <div style={{ width: "100%", height: `${pct}%`, borderRadius: "3px 3px 0 0", overflow: "hidden", display: "flex", flexDirection: "column" }}>
                  <div style={{ flex: productivePct, background: "#0ee29e", opacity: 0.85, minHeight: productivePct > 0 ? 2 : 0 }} />
                  <div style={{ flex: unproductivePct, background: "rgba(248,113,113,0.65)", minHeight: unproductivePct > 0 ? 2 : 0 }} />
                </div>
              </div>
              <span style={{ fontSize: "9px", color: "var(--text-muted)", fontFamily: "var(--font-mono)" }}>{dayLabel}</span>
            </div>
          )
        })}
      </div>

      {/* Totals row */}
      <div style={{ display: "flex", gap: "12px", marginBottom: topApps.length > 0 ? "10px" : 0 }}>
        {[
          { label: "Worked", value: fmtSeconds(totalWorked), color: "var(--text-primary)" },
          { label: "Productive", value: fmtSeconds(totalProductive), color: "#0ee29e" },
          { label: "Unproductive", value: fmtSeconds(totalUnproductive), color: "#f87171" },
        ].map(({ label, value, color }) => (
          <div key={label} style={{ fontSize: "11.5px", fontFamily: "var(--font-sans)" }}>
            <span style={{ color: "var(--text-muted)" }}>{label}: </span>
            <span style={{ color, fontWeight: 600 }}>{value}</span>
          </div>
        ))}
      </div>

      {/* Top apps — only rendered when present in the route response */}
      {topApps.length > 0 && (
        <div style={{ display: "flex", flexWrap: "wrap", gap: "5px" }}>
          {topApps.map(([app, { productive, seconds }]) => (
            <span key={app} style={{
              padding: "2px 8px",
              borderRadius: "4px",
              fontSize: "10.5px",
              fontFamily: "var(--font-mono)",
              background: productive ? "rgba(14,226,158,0.08)" : "rgba(248,113,113,0.08)",
              color: productive ? "#0ee29e" : "#f87171",
              border: `1px solid ${productive ? "rgba(14,226,158,0.18)" : "rgba(248,113,113,0.18)"}`,
            }}>
              {app} · {fmtSeconds(seconds)}
            </span>
          ))}
        </div>
      )}
    </Section>
  )
}

// ── Section C: Projects ───────────────────────────────────────────────────────

function ProjectsSection({ projects }: { projects: MemberCardData["projects"] }) {
  return (
    <Section title="Projects" note="based on ingested activity">
      {projects.length === 0 ? (
        <EmptyState text="No activity ingested yet" />
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: "1px" }}>
          {projects.map((p) => (
            <div key={p.projectId} style={{
              display: "grid",
              gridTemplateColumns: "1fr 60px 90px",
              gap: "8px",
              padding: "7px 10px",
              background: "var(--surface-subtle, rgba(255,255,255,0.02))",
              borderRadius: "6px",
              alignItems: "center",
            }}>
              <span style={{ fontSize: "12.5px", color: "var(--text-primary)", fontFamily: "var(--font-sans)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                {p.projectName}
              </span>
              <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-mono)", textAlign: "right" }}>
                {p.eventCount} ev
              </span>
              <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)", textAlign: "right" }}>
                {fmtRelative(p.lastActiveAt)}
              </span>
            </div>
          ))}
        </div>
      )}
    </Section>
  )
}

// ── Section D: Recent Sessions ────────────────────────────────────────────────

function SessionsSection({ sessions }: { sessions: MemberCardData["recentSessions"] }) {
  return (
    <Section title="Recent Sessions">
      {sessions.length === 0 ? (
        <EmptyState text="No sessions" />
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: "6px" }}>
          {sessions.map((s) => (
            <div key={s.id} style={{
              padding: "9px 12px",
              background: "var(--surface-subtle, rgba(255,255,255,0.02))",
              border: "1px solid var(--border)",
              borderRadius: "7px",
            }}>
              {s.sessionExcerpt && (
                <p style={{
                  margin: "0 0 5px",
                  fontSize: "11.5px",
                  color: "var(--text-secondary)",
                  fontFamily: "var(--font-mono)",
                  lineHeight: 1.5,
                  display: "-webkit-box",
                  WebkitLineClamp: 2,
                  WebkitBoxOrient: "vertical",
                  overflow: "hidden",
                }}>
                  {s.sessionExcerpt}
                </p>
              )}
              <div style={{ display: "flex", gap: "12px", alignItems: "center" }}>
                <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>
                  {s.projectName}
                </span>
                <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>
                  {fmtDuration(s.sessionDurationSeconds)}
                </span>
                <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)", marginLeft: "auto" }}>
                  {fmtRelative(s.date)}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}
    </Section>
  )
}

// ── Section D: Recent Commits ─────────────────────────────────────────────────

function CommitsSection({ commits }: { commits: MemberCardData["recentCommits"] }) {
  return (
    <Section title="Recent Commits">
      {commits.length === 0 ? (
        <EmptyState text="No commits" />
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: "6px" }}>
          {commits.map((c) => (
            <div key={c.id} style={{
              padding: "9px 12px",
              background: "var(--surface-subtle, rgba(255,255,255,0.02))",
              border: "1px solid var(--border)",
              borderRadius: "7px",
            }}>
              <div style={{ display: "flex", alignItems: "baseline", gap: "8px", marginBottom: "4px" }}>
                <code style={{ fontSize: "11px", color: "#0ee29e", fontFamily: "var(--font-mono)", flexShrink: 0 }}>
                  {c.sha}
                </code>
                <span style={{ fontSize: "12px", color: "var(--text-primary)", fontFamily: "var(--font-sans)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", flex: 1 }}>
                  {c.gitSummary ?? "—"}
                </span>
              </div>
              <div style={{ display: "flex", gap: "10px", flexWrap: "wrap", alignItems: "center" }}>
                <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>
                  {c.projectName}
                </span>
                {c.filesChanged.length > 0 && (
                  <span style={{ fontSize: "10.5px", color: "var(--text-muted)", fontFamily: "var(--font-mono)" }}>
                    {c.filesChanged.slice(0, 3).map((f) => f.split("/").at(-1)).join(", ")}
                    {c.filesChanged.length > 3 && ` +${c.filesChanged.length - 3}`}
                  </span>
                )}
                <span style={{ fontSize: "11px", color: "var(--text-muted)", fontFamily: "var(--font-sans)", marginLeft: "auto" }}>
                  {fmtRelative(c.date)}
                </span>
              </div>
            </div>
          ))}
        </div>
      )}
    </Section>
  )
}

// ── Card content (loaded state) ───────────────────────────────────────────────

function CardContent({ data, onClose }: { data: MemberCardData; onClose: () => void }) {
  const { identity } = data

  const consentLabel =
    identity.consentVersion >= 2 ? "Full consent (v2)"
    : identity.consentVersion === 1 ? "Time only (v1)"
    : "No consent (v0)"

  return (
    <div style={{
      background: "var(--background-card)",
      border: "1px solid var(--border-mid)",
      borderRadius: "16px",
      width: "680px",
      maxWidth: "94vw",
      maxHeight: "88vh",
      display: "flex",
      flexDirection: "column",
      boxShadow: "0 24px 80px rgba(0,0,0,0.55)",
      overflow: "hidden",
    }}>
      {/* ── Header bar ── */}
      <div style={{
        padding: "20px 24px 16px",
        borderBottom: "1px solid var(--border)",
        flexShrink: 0,
        background: "linear-gradient(180deg, rgba(14,226,158,0.04) 0%, transparent 100%)",
      }}>
        {/* Top row: name + close */}
        <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: "12px", marginBottom: "10px" }}>
          <div>
            <h2 style={{ margin: "0 0 2px", fontSize: "18px", fontWeight: 800, color: "var(--text-primary)", fontFamily: "var(--font-sans)", letterSpacing: "-0.02em" }}>
              {identity.name}
            </h2>
            <p style={{ margin: 0, fontSize: "12.5px", color: "var(--text-secondary)", fontFamily: "var(--font-sans)" }}>
              {identity.email}
            </p>
          </div>
          <button
            onClick={onClose}
            aria-label="Close member detail"
            style={{
              flexShrink: 0,
              padding: "4px 8px",
              background: "transparent",
              border: "1px solid var(--border-mid)",
              borderRadius: "6px",
              color: "var(--text-muted)",
              cursor: "pointer",
              fontSize: "14px",
              lineHeight: 1,
              fontFamily: "var(--font-sans)",
            }}
          >
            ✕
          </button>
        </div>

        {/* Meta row */}
        <div style={{ display: "flex", flexWrap: "wrap", gap: "8px", alignItems: "center" }}>
          <span style={roleBadgeStyle(identity.role)}>
            {identity.role === "LINE_MANAGER" ? "Line Manager" : identity.role === "MANAGER" ? "Manager" : "Member"}
          </span>

          <span style={{
            fontSize: "12px",
            color: "var(--text-secondary)",
            fontFamily: "var(--font-sans)",
          }}>
            {identity.teamName ?? <em style={{ color: "var(--text-muted)", fontStyle: "italic" }}>Unassigned</em>}
          </span>

          <span style={{
            padding: "2px 8px",
            borderRadius: "6px",
            fontSize: "11px",
            fontWeight: 600,
            fontFamily: "var(--font-sans)",
            background: identity.isActive ? "rgba(14,226,158,0.10)" : "rgba(248,113,113,0.10)",
            color: identity.isActive ? "#0ee29e" : "#f87171",
          }}>
            {identity.isActive ? "Active" : "Inactive"}
          </span>

          {identity.lastSeenAt && (
            <span style={{ fontSize: "11.5px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>
              Last seen {fmtRelative(identity.lastSeenAt)}
            </span>
          )}

          <span style={{
            marginLeft: "auto",
            fontSize: "10.5px",
            color: "var(--text-muted)",
            fontFamily: "var(--font-mono)",
            background: "var(--background-chip)",
            padding: "2px 7px",
            borderRadius: "4px",
          }}>
            {consentLabel}
          </span>
        </div>
      </div>

      {/* ── Scrollable body ── */}
      <div style={{ overflowY: "auto", padding: "20px 24px 24px", flex: 1 }}>
        {/* Section A: only render if spend key present in response */}
        {data.spend !== undefined && <SpendSection spend={data.spend} />}

        {/* Section B: always render, shape drives content */}
        <TimeSection time={data.time} />

        {/* Section C */}
        <ProjectsSection projects={data.projects} />

        {/* Section D */}
        <SessionsSection sessions={data.recentSessions} />
        <CommitsSection commits={data.recentCommits} />
      </div>
    </div>
  )
}

// ── Main export ───────────────────────────────────────────────────────────────

type CardState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "error"; status: number }
  | { kind: "loaded"; data: MemberCardData }

type Props = {
  memberId: string | null
  onClose: () => void
}

export function MemberDetailCard({ memberId, onClose }: Props) {
  const [mounted, setMounted] = useState(false)
  const [cardState, setCardState] = useState<CardState>({ kind: "idle" })

  useEffect(() => { setMounted(true) }, [])

  useEffect(() => {
    if (!memberId) { setCardState({ kind: "idle" }); return }
    setCardState({ kind: "loading" })
    fetchMemberCard(memberId).then((result) => {
      if (result.ok) {
        setCardState({ kind: "loaded", data: result.data })
      } else {
        setCardState({ kind: "error", status: result.status })
      }
    }).catch(() => {
      setCardState({ kind: "error", status: 0 })
    })
  }, [memberId])

  // Close on Escape
  useEffect(() => {
    if (!memberId) return
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose() }
    document.addEventListener("keydown", onKey)
    return () => document.removeEventListener("keydown", onKey)
  }, [memberId, onClose])

  if (!mounted || !memberId) return null

  const overlay = (
    <div
      style={{
        position: "fixed",
        inset: 0,
        background: "rgba(0,0,0,0.68)",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        zIndex: 10001,
        padding: "20px",
      }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose() }}
    >
      {cardState.kind === "loading" && (
        <div style={{
          padding: "40px 60px",
          background: "var(--background-card)",
          border: "1px solid var(--border-mid)",
          borderRadius: "14px",
          color: "var(--text-muted)",
          fontSize: "13px",
          fontFamily: "var(--font-sans)",
        }}>
          Loading…
        </div>
      )}

      {cardState.kind === "error" && (
        <div style={{
          padding: "32px 40px",
          background: "var(--background-card)",
          border: "1px solid var(--border-mid)",
          borderRadius: "14px",
          width: "400px",
          maxWidth: "92vw",
        }}>
          <p style={{ margin: "0 0 16px", fontSize: "14px", color: "var(--text-primary)", fontFamily: "var(--font-sans)", fontWeight: 600 }}>
            {cardState.status === 403
              ? "Access restricted"
              : cardState.status === 404
              ? "Member not found"
              : cardState.status === 429
              ? "Rate limited — try again shortly"
              : "Could not load member details"}
          </p>
          <p style={{ margin: "0 0 20px", fontSize: "12.5px", color: "var(--text-muted)", fontFamily: "var(--font-sans)" }}>
            {cardState.status === 403
              ? "You can only view members in your own team."
              : cardState.status === 404
              ? "This member may no longer exist."
              : ""}
          </p>
          <button
            onClick={onClose}
            style={{ padding: "7px 16px", background: "var(--background-chip)", border: "1px solid var(--border-mid)", borderRadius: "7px", color: "var(--text-secondary)", cursor: "pointer", fontSize: "12.5px", fontFamily: "var(--font-sans)" }}
          >
            Close
          </button>
        </div>
      )}

      {cardState.kind === "loaded" && (
        <CardContent data={cardState.data} onClose={onClose} />
      )}
    </div>
  )

  return createPortal(overlay, document.body)
}
```

- [ ] **Step 2: TypeScript check**

```
npx tsc --noEmit
```

Expected: 0 errors (fix any before moving to Step 3).

- [ ] **Step 3: Commit**

```
git add src/app/admin/members/_components/MemberDetailCard.tsx
git commit -m "feat(member-card): add MemberDetailCard portal component with all 4 sections"
```

---

## Task 3: Wire MembersClient — click to open card

**Files:**
- Modify: `src/app/admin/members/_components/MembersClient.tsx`

- [ ] **Step 1: Read current MembersClient.tsx to confirm the exact import section and state block before editing**

Use the Read tool on `src/app/admin/members/_components/MembersClient.tsx`, lines 1–10.

- [ ] **Step 2: Add import at top of file**

After the existing imports (after line `import { derivePresence } from "@/lib/presence"`), add:

```typescript
import { MemberDetailCard } from "./MemberDetailCard"
```

- [ ] **Step 3: Add selectedMemberId state**

Inside `MembersClient` function body, after the existing `const [mounted, setMounted] = useState(false)` line, add:

```typescript
const [selectedMemberId, setSelectedMemberId] = useState<string | null>(null)
```

- [ ] **Step 4: Add "View" button in the Actions column per row**

Inside the `filteredUsers.map` callback, in the Actions `<div>` (the div with `display: "flex", gap: "5px"...`), add a "View" button as the FIRST child before the existing action buttons:

```tsx
{/* View detail card — always visible for any member row */}
<button
  onClick={() => setSelectedMemberId(user.id)}
  className="members-action-btn"
  style={{
    padding: "4px 11px",
    borderRadius: "6px",
    cursor: "pointer",
    fontSize: "11.5px",
    fontWeight: 500,
    whiteSpace: "nowrap",
    border: "1px solid rgba(14,226,158,0.22)",
    fontFamily: "var(--font-sans)",
    background: "rgba(14,226,158,0.07)",
    color: "#0ee29e",
  }}
>
  View
</button>
```

- [ ] **Step 5: Render MemberDetailCard portal at the bottom of the return**

Inside the `<>` fragment returned by `MembersClient`, after the role-change confirm modal block (the last `{roleConfirm && ...}` block), add:

```tsx
{/* ── Portal: member detail card ── */}
<MemberDetailCard
  memberId={selectedMemberId}
  onClose={() => setSelectedMemberId(null)}
/>
```

- [ ] **Step 6: TypeScript check**

```
npx tsc --noEmit
```

Expected: 0 errors.

- [ ] **Step 7: Full test suite**

```
npx vitest run
```

Expected: 1519 passed (no regressions).

- [ ] **Step 8: Commit**

```
git add src/app/admin/members/_components/MembersClient.tsx
git commit -m "feat(member-card): wire View button in members list to open detail card portal"
```

---

## Task 4: Update docs

**Files:**
- Modify: `docs/structure.md`
- Modify: `docs/code.md`

- [ ] **Step 1: Update docs/structure.md**

In the `src/app/admin/members/_components/` section, add the two new files:

```
member-card-fetch.ts     Pure async utility: fetchMemberCard(memberId) → MemberCardResult
MemberDetailCard.tsx     Portal-mounted member detail card (Header + Spend + Time + Projects + Sessions + Commits)
```

- [ ] **Step 2: Update docs/code.md**

In the key function signatures section, add:

```
fetchMemberCard(memberId: string): Promise<MemberCardResult>
  Located: src/app/admin/members/_components/member-card-fetch.ts
  Returns: { ok: true, data: MemberCardData } | { ok: false, status: number }
  Calls:   GET /api/admin/members/:id/card
  Used by: MemberDetailCard component
```

- [ ] **Step 3: Commit**

```
git add docs/structure.md docs/code.md
git commit -m "docs(member-card): add member-card-fetch and MemberDetailCard to structure + code docs"
```

---

## Definition of Done

- [ ] `npx tsc --noEmit` exits 0
- [ ] `npx vitest run` shows 1519 tests green (1515 baseline + 4 new)
- [ ] As MANAGER: clicking "View" on a real member opens the card with real spend/tokens/projects/sessions
- [ ] As LINE_MANAGER: clicking an own-team member opens the card; clicking an out-of-team member shows the "Access restricted" blocked state
- [ ] Card opens as a portal (not swallowed by stagger stacking-context)
- [ ] Card is fully interactive: scrollable, close button works, Escape key closes, overlay click closes
- [ ] Section A absent when viewer has no spend access (route omits the key)
- [ ] Section B shows "Hasn't consented to time tracking" for v0/v1 members
- [ ] Honest empty states for all 4 sections
- [ ] `docs/structure.md` and `docs/code.md` updated
