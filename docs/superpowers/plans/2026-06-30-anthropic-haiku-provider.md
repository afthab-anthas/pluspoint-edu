# Anthropic Haiku Provider Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all Groq/OpenRouter AI calls in Axis Pulse with Anthropic Haiku (`claude-haiku-4-5-20251001`), configured once per org by a MANAGER via the `/install` page; fall back to OpenRouter/Groq with a server-side warning when no key is set.

**Architecture:** Store the Anthropic API key per `Organisation` as AES-256-GCM ciphertext (`anthropicApiKeyEnc`) with a 4-char hint for display. A shared `getOrgAnthropicKey()` helper decrypts it on demand. `getAutoCallModel(anthropicApiKey?)` already owns all provider routing — adding an Anthropic path there propagates the change to every AI call site automatically. Call sites that have `organisationId` fetch the key and pass it through; the two orchestrators (`drift/orchestrate.ts`, `debt/orchestrate.ts`) do the fetch before delegating to their AI sub-functions.

**Tech Stack:** `@ai-sdk/anthropic` (new), `node:crypto` AES-256-GCM, Next.js App Router server components + client form, Prisma migration, Vitest.

---

## File Map

**Create:**
- `src/lib/anthropic-key.ts` — encrypt/decrypt/obscure helpers + `getOrgAnthropicKey(organisationId)`
- `src/app/api/admin/org/anthropic-key/route.ts` — MANAGER-only POST: validate key format → test call → encrypt → save
- `src/app/install/_components/AnthropicKeySection.tsx` — client component: key status + form
- `src/tests/anthropic-key.test.ts` — unit tests for crypto round-trip + obscure
- `src/tests/org-anthropic-key-api.test.ts` — RBAC + validation tests for the API route

**Modify:**
- `prisma/schema.prisma` — add `anthropicApiKeyEnc String?` + `anthropicKeyHint String?` to `Organisation`
- `src/lib/ai-provider.ts` — add `createAnthropic`, add `ANTHROPIC_MODEL_ID`, update `getAutoCallModel(anthropicApiKey?)` return type to include `usingFallback`
- `src/lib/narrate.ts` — add `anthropicApiKey?: string | null` param; pass to `getAutoCallModel`; log when falling back
- `src/lib/gemini.ts` — add `anthropicApiKey?: string | null` param; update null-guard; pass to `getAutoCallModel`
- `src/lib/drift/analyse-ai.ts` — add `anthropicApiKey?: string | null` param; pass to `getAutoCallModel`; update `usingFallback` log
- `src/lib/drift/orchestrate.ts` — fetch org key via `getOrgAnthropicKey`; pass to `analyseAiDrift`
- `src/lib/debt/synthesise.ts` — add `anthropicApiKey?: string | null` to params; pass to `getAutoCallModel`; fix `providerMeta` read
- `src/lib/debt/orchestrate.ts` — fetch org key; pass to `synthesiseDebtFindings`
- `src/app/api/projects/[id]/summary/route.ts` — remove hardcoded Groq; use `getAutoCallModel` + `getOrgAnthropicKey`
- `src/app/api/ingest/event/route.ts` — fetch org key after project lookup; pass to both `generateNarration` and `generateFeedSummary`
- `src/lib/cost-ceiling.ts` — update pricing constants to Haiku rates
- `src/app/install/page.tsx` — fetch `anthropicKeyHint` from DB; render `AnthropicKeySection` for MANAGER
- `.env.example` — add `ANTHROPIC_KEY_ENCRYPTION_SECRET`

**Test updates (no new test files):**
- `src/tests/ai-provider.test.ts` — rewrite for Anthropic primary path + fallback
- `src/tests/narrate.test.ts` — remove `GROQ_API_KEY` env var lines; update mock return to include `usingFallback`
- `src/tests/narrate-time-enrichment.test.ts` — replace `@ai-sdk/groq` mock with `@/lib/ai-provider` mock
- `src/tests/feed-summary.test.ts` — update null-guard test (no GROQ_API_KEY)
- `src/tests/executive-summary-api.test.ts` — replace `@ai-sdk/groq` mock with `@ai-sdk/anthropic` mock
- `src/tests/drift-analyse-ai.test.ts` — update mock return to include `usingFallback`
- `src/tests/ingest-narration.test.ts` — remove `GROQ_API_KEY` lines

---

## Task 1: Install `@ai-sdk/anthropic` + Schema + Migration

**Files:**
- Modify: `prisma/schema.prisma`
- Run: `npx prisma migrate dev`

- [ ] **Step 1: Install the Anthropic AI SDK provider**

```bash
cd C:/Users/AfthabAnthas/repos/axis-pulse
npm install @ai-sdk/anthropic
```

Confirm it appears in `package.json` dependencies. Note the installed version — it must be compatible with your `ai@^6.0.194` core (npm will resolve this automatically).

- [ ] **Step 2: Add fields to Organisation in schema.prisma**

In `prisma/schema.prisma`, find `model Organisation {` (line 51). Add two nullable fields after `updatedAt`:

```prisma
model Organisation {
  id        String   @id @default(cuid())
  name      String
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  anthropicApiKeyEnc String?
  anthropicKeyHint   String?

  teams                Team[]
  // ... rest unchanged
}
```

- [ ] **Step 3: Generate and apply the migration**

```bash
cd C:/Users/AfthabAnthas/repos/axis-pulse
npx prisma migrate dev --name add_org_anthropic_key
```

Expected: `✓ Generated Prisma Client` and the migration SQL adds two nullable columns — no destructive operations.

- [ ] **Step 4: Verify migration SQL is non-destructive**

Read the generated migration file at `prisma/migrations/<timestamp>_add_org_anthropic_key/migration.sql`. It must contain only:
```sql
ALTER TABLE "Organisation" ADD COLUMN "anthropicApiKeyEnc" TEXT;
ALTER TABLE "Organisation" ADD COLUMN "anthropicKeyHint" TEXT;
```
No DROP, no NOT NULL without default.

- [ ] **Step 5: Add env var to .env.example**

In `.env.example`, append:

```
# Anthropic Haiku AI provider (org-level, MANAGER-only)
# Generate a 32-byte hex secret: node -e "require('crypto').randomBytes(32).then ? console.log(require('crypto').randomBytes(32).toString('hex')) : console.log(require('crypto').randomBytes(32).toString('hex'))"
ANTHROPIC_KEY_ENCRYPTION_SECRET="replace-with-64-hex-chars-32-bytes"
```

- [ ] **Step 6: Commit**

```bash
git add prisma/schema.prisma prisma/migrations/ .env.example package.json package-lock.json
git commit -m "feat: add @ai-sdk/anthropic + Organisation.anthropicApiKeyEnc schema"
```

---

## Task 2: Crypto Lib (`src/lib/anthropic-key.ts`)

**Files:**
- Create: `src/lib/anthropic-key.ts`
- Create: `src/tests/anthropic-key.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `src/tests/anthropic-key.test.ts`:

```typescript
import { describe, it, expect, beforeEach } from "vitest"

beforeEach(() => {
  process.env.ANTHROPIC_KEY_ENCRYPTION_SECRET = "a".repeat(64) // 32-byte hex
})

import { encryptApiKey, decryptApiKey, obscureKey } from "@/lib/anthropic-key"

describe("encryptApiKey / decryptApiKey", () => {
  it("round-trips a key back to the original value", () => {
    const raw = "sk-ant-api03-test-key-1234"
    const enc = encryptApiKey(raw)
    expect(decryptApiKey(enc)).toBe(raw)
  })

  it("produces different ciphertext on each call (random IV)", () => {
    const raw = "sk-ant-api03-test"
    expect(encryptApiKey(raw)).not.toBe(encryptApiKey(raw))
  })

  it("throws when ANTHROPIC_KEY_ENCRYPTION_SECRET is missing", () => {
    delete process.env.ANTHROPIC_KEY_ENCRYPTION_SECRET
    expect(() => encryptApiKey("sk-ant-test")).toThrow("ANTHROPIC_KEY_ENCRYPTION_SECRET")
  })

  it("throws when ciphertext is tampered", () => {
    const enc = encryptApiKey("sk-ant-test-key")
    const buf = Buffer.from(enc, "base64")
    buf[buf.length - 1] ^= 0xff // flip last byte of ciphertext
    expect(() => decryptApiKey(buf.toString("base64"))).toThrow()
  })
})

describe("obscureKey", () => {
  it("shows first 10 chars and last 4 chars with ellipsis", () => {
    const raw = "sk-ant-api03-abcdefghijklmnop-1234"
    expect(obscureKey(raw)).toBe("sk-ant-api0...1234")
  })

  it("handles short keys safely", () => {
    expect(obscureKey("sk-ant")).toBe("sk-ant...nt")
  })
})
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd C:/Users/AfthabAnthas/repos/axis-pulse
npx vitest run src/tests/anthropic-key.test.ts 2>&1 | tail -10
```

Expected: all tests fail with "cannot find module" or "is not a function".

- [ ] **Step 3: Implement `src/lib/anthropic-key.ts`**

```typescript
import crypto from "node:crypto"
import { db } from "@/lib/db"

const ALG = "aes-256-gcm"
const IV_BYTES = 12
const TAG_BYTES = 16

function getEncKey(): Buffer {
  const secret = process.env.ANTHROPIC_KEY_ENCRYPTION_SECRET
  if (!secret) throw new Error("ANTHROPIC_KEY_ENCRYPTION_SECRET is not set")
  const buf = Buffer.from(secret, "hex")
  if (buf.length !== 32) throw new Error("ANTHROPIC_KEY_ENCRYPTION_SECRET must be 32 bytes (64 hex chars)")
  return buf
}

export function encryptApiKey(raw: string): string {
  const iv = crypto.randomBytes(IV_BYTES)
  const cipher = crypto.createCipheriv(ALG, getEncKey(), iv)
  const enc = Buffer.concat([cipher.update(raw, "utf8"), cipher.final()])
  const tag = cipher.getAuthTag()
  return Buffer.concat([iv, tag, enc]).toString("base64")
}

export function decryptApiKey(enc: string): string {
  const buf = Buffer.from(enc, "base64")
  const iv = buf.subarray(0, IV_BYTES)
  const tag = buf.subarray(IV_BYTES, IV_BYTES + TAG_BYTES)
  const data = buf.subarray(IV_BYTES + TAG_BYTES)
  const decipher = crypto.createDecipheriv(ALG, getEncKey(), iv)
  decipher.setAuthTag(tag)
  return decipher.update(data) + decipher.final("utf8")
}

export function obscureKey(raw: string): string {
  if (raw.length <= 8) return raw.slice(0, raw.length - 2) + "..." + raw.slice(-2)
  return raw.slice(0, 10) + "..." + raw.slice(-4)
}

export async function getOrgAnthropicKey(organisationId: string): Promise<string | null> {
  const org = await db.organisation.findUnique({
    where: { id: organisationId },
    select: { anthropicApiKeyEnc: true },
  })
  if (!org?.anthropicApiKeyEnc) return null
  try {
    return decryptApiKey(org.anthropicApiKeyEnc)
  } catch {
    console.error("[anthropic-key] decryption failed for org", organisationId)
    return null
  }
}
```

- [ ] **Step 4: Run tests — all must pass**

```bash
npx vitest run src/tests/anthropic-key.test.ts 2>&1 | tail -8
```

Expected: `Tests  6 passed (6)`.

- [ ] **Step 5: Commit**

```bash
git add src/lib/anthropic-key.ts src/tests/anthropic-key.test.ts
git commit -m "feat: AES-256-GCM encrypt/decrypt helpers for Anthropic API key"
```

---

## Task 3: Update `ai-provider.ts` — Add Anthropic, Update Return Type

**Files:**
- Modify: `src/lib/ai-provider.ts`
- Modify: `src/tests/ai-provider.test.ts`

- [ ] **Step 1: Write failing tests for the Anthropic path**

Replace the entire contents of `src/tests/ai-provider.test.ts` with:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest"

const mockAnthropicChat = vi.hoisted(() => vi.fn(() => "anthropic-chat-model"))
const mockCreateAnthropic = vi.hoisted(() => vi.fn(() => ({ chat: mockAnthropicChat, (OPENAI_MODEL_ID: any): any => mockAnthropicChat() })))
const mockCreateOpenRouter = vi.hoisted(() => vi.fn())
const mockGroqModel = vi.hoisted(() => vi.fn(() => "groq-model"))
const mockCreateGroq = vi.hoisted(() => vi.fn(() => mockGroqModel))
const mockProviderInstance = { chat: vi.fn(() => "openrouter-chat-model") }

vi.mock("@ai-sdk/anthropic", () => ({
  createAnthropic: mockCreateAnthropic,
}))
vi.mock("@openrouter/ai-sdk-provider", () => ({
  createOpenRouter: mockCreateOpenRouter,
}))
vi.mock("@ai-sdk/groq", () => ({
  createGroq: mockCreateGroq,
}))

import { ANTHROPIC_MODEL_ID, OPENROUTER_MODELS, GROQ_MODEL_ID, getAutoCallModel } from "@/lib/ai-provider"

beforeEach(() => {
  vi.resetAllMocks()
  delete process.env.OPENROUTER_API_KEY
  delete process.env.GROQ_API_KEY
  mockCreateAnthropic.mockReturnValue(mockAnthropicChat)
  mockCreateOpenRouter.mockReturnValue(mockProviderInstance)
  mockCreateGroq.mockReturnValue(mockGroqModel)
})

describe("getAutoCallModel — Anthropic primary path", () => {
  it("uses Anthropic when anthropicApiKey is provided", () => {
    const { modelId, usingFallback } = getAutoCallModel("sk-ant-test")
    expect(modelId).toBe(ANTHROPIC_MODEL_ID)
    expect(usingFallback).toBe(false)
    expect(mockCreateAnthropic).toHaveBeenCalledWith({ apiKey: "sk-ant-test" })
  })

  it("returns usingFallback=false when Anthropic key provided", () => {
    const { usingFallback } = getAutoCallModel("sk-ant-test")
    expect(usingFallback).toBe(false)
  })
})

describe("getAutoCallModel — fallback path (no Anthropic key)", () => {
  it("falls back to OpenRouter when OPENROUTER_API_KEY is set", () => {
    process.env.OPENROUTER_API_KEY = "or-test-key"
    const { modelId, usingFallback } = getAutoCallModel(null)
    expect(modelId).toBe(OPENROUTER_MODELS[0])
    expect(usingFallback).toBe(true)
    expect(mockCreateOpenRouter).toHaveBeenCalled()
  })

  it("falls back to Groq when only GROQ_API_KEY is set", () => {
    process.env.GROQ_API_KEY = "groq-test-key"
    const { modelId, usingFallback } = getAutoCallModel(null)
    expect(modelId).toBe(GROQ_MODEL_ID)
    expect(usingFallback).toBe(true)
    expect(mockCreateGroq).toHaveBeenCalled()
  })

  it("uses OpenRouter over Groq when both keys are set", () => {
    process.env.OPENROUTER_API_KEY = "or-key"
    process.env.GROQ_API_KEY = "groq-key"
    const { modelId } = getAutoCallModel(undefined)
    expect(modelId).toBe(OPENROUTER_MODELS[0])
  })

  it("returns usingFallback=true when falling back", () => {
    process.env.GROQ_API_KEY = "groq-key"
    const { usingFallback } = getAutoCallModel(undefined)
    expect(usingFallback).toBe(true)
  })
})

describe("ANTHROPIC_MODEL_ID", () => {
  it("is the Haiku model", () => {
    expect(ANTHROPIC_MODEL_ID).toBe("claude-haiku-4-5-20251001")
  })
})

describe("OPENROUTER_MODELS — :free suffix invariant", () => {
  it("every model ID ends in :free", () => {
    for (const id of OPENROUTER_MODELS) {
      expect(id).toMatch(/:free$/)
    }
  })

  it("has at most 3 entries (OpenRouter models array limit)", () => {
    expect(OPENROUTER_MODELS.length).toBeLessThanOrEqual(3)
    expect(OPENROUTER_MODELS.length).toBeGreaterThanOrEqual(1)
  })
})
```

- [ ] **Step 2: Run tests — confirm they fail**

```bash
npx vitest run src/tests/ai-provider.test.ts 2>&1 | tail -10
```

Expected: failures including "ANTHROPIC_MODEL_ID is not exported", "usingFallback is not a property".

- [ ] **Step 3: Update `src/lib/ai-provider.ts`**

Replace the entire file:

```typescript
import { createAnthropic } from "@ai-sdk/anthropic"
import { createOpenRouter } from "@openrouter/ai-sdk-provider"
import { createGroq } from "@ai-sdk/groq"

export const ANTHROPIC_MODEL_ID = "claude-haiku-4-5-20251001" as const

export const OPENROUTER_MODELS = [
  "meta-llama/llama-3.3-70b-instruct:free",
  "openai/gpt-oss-120b:free",
  "nvidia/nemotron-3-super-120b-a12b:free",
] as const satisfies ReadonlyArray<`${string}:free`>

for (const id of OPENROUTER_MODELS) {
  if (!id.endsWith(":free")) {
    throw new Error(`[ai-provider] Model ID must end in :free, got: ${id}`)
  }
}
if (OPENROUTER_MODELS.length > 3) {
  throw new Error(`[ai-provider] OpenRouter models array limit is 3; got ${OPENROUTER_MODELS.length}`)
}

export const GROQ_MODEL_ID = "llama-3.3-70b-versatile" as const

export type AutoCallModel = {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  model: any
  modelId: string
  usingFallback: boolean
}

/**
 * Returns an AI model instance for automatic narration/summary calls.
 *
 * Priority:
 *   1. Anthropic Haiku — when anthropicApiKey is provided (org's encrypted key, decrypted by caller)
 *   2. OpenRouter free chain — when OPENROUTER_API_KEY env var is set
 *   3. Groq — when GROQ_API_KEY env var is set
 *
 * usingFallback=true whenever Anthropic is NOT used. Callers should warn when this is true.
 */
export function getAutoCallModel(anthropicApiKey?: string | null): AutoCallModel {
  if (anthropicApiKey) {
    const provider = createAnthropic({ apiKey: anthropicApiKey })
    return {
      model: provider(ANTHROPIC_MODEL_ID),
      modelId: ANTHROPIC_MODEL_ID,
      usingFallback: false,
    }
  }

  console.warn("[ai-provider] No Anthropic key — defaulting to free models (api key not set)")

  const openRouterKey = process.env.OPENROUTER_API_KEY
  if (openRouterKey) {
    const provider = createOpenRouter({
      apiKey: openRouterKey,
      headers: {
        "HTTP-Referer": "https://axisconsulting.ai",
        "X-Title": "Axis Pulse",
      },
      extraBody: {
        models: [...OPENROUTER_MODELS],
      },
    })
    return {
      model: provider.chat(OPENROUTER_MODELS[0]),
      modelId: OPENROUTER_MODELS[0],
      usingFallback: true,
    }
  }

  const groqProvider = createGroq({ apiKey: process.env.GROQ_API_KEY })
  return {
    model: groqProvider(GROQ_MODEL_ID),
    modelId: GROQ_MODEL_ID,
    usingFallback: true,
  }
}
```

- [ ] **Step 4: Run the ai-provider tests — all must pass**

```bash
npx vitest run src/tests/ai-provider.test.ts 2>&1 | tail -8
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/lib/ai-provider.ts src/tests/ai-provider.test.ts
git commit -m "feat: add Anthropic Haiku as primary AI provider in getAutoCallModel"
```

---

## Task 4: API Route — Save Org Anthropic Key

**Files:**
- Create: `src/app/api/admin/org/anthropic-key/route.ts`
- Create: `src/tests/org-anthropic-key-api.test.ts`

- [ ] **Step 1: Write failing tests**

Create `src/tests/org-anthropic-key-api.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from "vitest"

// Hoisted mocks
const mockWithAuthScoped = vi.hoisted(() => vi.fn())
const mockDb = vi.hoisted(() => ({
  organisation: { update: vi.fn() },
}))
const mockEncryptApiKey = vi.hoisted(() => vi.fn(() => "encrypted-base64"))
const mockObscureKey = vi.hoisted(() => vi.fn(() => "sk-ant-api0...1234"))
const mockGenerateText = vi.hoisted(() => vi.fn())
const mockCreateAnthropic = vi.hoisted(() => vi.fn(() => vi.fn()))

vi.mock("@/lib/withAuthScoped", () => ({ withAuthScoped: mockWithAuthScoped }))
vi.mock("@/lib/db", () => ({ db: mockDb }))
vi.mock("@/lib/anthropic-key", () => ({
  encryptApiKey: mockEncryptApiKey,
  obscureKey: mockObscureKey,
}))
vi.mock("ai", () => ({ generateText: mockGenerateText }))
vi.mock("@ai-sdk/anthropic", () => ({ createAnthropic: mockCreateAnthropic }))

import { POST } from "@/app/api/admin/org/anthropic-key/route"
import { NextRequest } from "next/server"

function makeRequest(body: unknown) {
  return new NextRequest("http://localhost/api/admin/org/anthropic-key", {
    method: "POST",
    body: JSON.stringify(body),
    headers: { "Content-Type": "application/json" },
  })
}

beforeEach(() => {
  vi.resetAllMocks()
  process.env.ANTHROPIC_KEY_ENCRYPTION_SECRET = "a".repeat(64)
  mockGenerateText.mockResolvedValue({ text: "ok" })
})

describe("POST /api/admin/org/anthropic-key", () => {
  it("returns 401 when not authenticated", async () => {
    mockWithAuthScoped.mockResolvedValue(null)
    const res = await POST(makeRequest({ apiKey: "sk-ant-api03-test1234" }))
    expect(res.status).toBe(401)
  })

  it("returns 403 when role is LINE_MANAGER", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "LINE_MANAGER",
    })
    const res = await POST(makeRequest({ apiKey: "sk-ant-api03-test1234" }))
    expect(res.status).toBe(403)
  })

  it("returns 403 when role is MEMBER", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "MEMBER",
    })
    const res = await POST(makeRequest({ apiKey: "sk-ant-api03-test1234" }))
    expect(res.status).toBe(403)
  })

  it("returns 400 when apiKey does not start with sk-ant-", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "MANAGER",
    })
    const res = await POST(makeRequest({ apiKey: "gsk-not-anthropic" }))
    expect(res.status).toBe(400)
    const body = await res.json()
    expect(body.error).toMatch(/invalid.*key/i)
  })

  it("returns 400 when key is too short", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "MANAGER",
    })
    const res = await POST(makeRequest({ apiKey: "sk-ant-" }))
    expect(res.status).toBe(400)
  })

  it("returns 422 when Anthropic rejects the key (test call fails)", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "MANAGER",
    })
    mockGenerateText.mockRejectedValueOnce(new Error("authentication_error"))
    const res = await POST(makeRequest({ apiKey: "sk-ant-api03-invalid-key-12345678901234" }))
    expect(res.status).toBe(422)
    const body = await res.json()
    expect(body.error).toMatch(/invalid.*key|key.*rejected/i)
  })

  it("encrypts and saves key when valid MANAGER submits a valid key", async () => {
    mockWithAuthScoped.mockResolvedValue({
      userId: "u1", organisationId: "org1", role: "MANAGER",
    })
    mockDb.organisation.update.mockResolvedValue({})
    const res = await POST(makeRequest({ apiKey: "sk-ant-api03-validkey12345678901234567890" }))
    expect(res.status).toBe(200)
    expect(mockEncryptApiKey).toHaveBeenCalledWith("sk-ant-api03-validkey12345678901234567890")
    expect(mockDb.organisation.update).toHaveBeenCalledWith({
      where: { id: "org1" },
      data: {
        anthropicApiKeyEnc: "encrypted-base64",
        anthropicKeyHint: "sk-ant-api0...1234",
      },
    })
    const body = await res.json()
    expect(body.hint).toBe("sk-ant-api0...1234")
  })
})
```

- [ ] **Step 2: Run tests — confirm they fail**

```bash
npx vitest run src/tests/org-anthropic-key-api.test.ts 2>&1 | tail -10
```

Expected: fail with "Cannot find module" for the route.

- [ ] **Step 3: Create the directory and route**

```bash
mkdir -p C:/Users/AfthabAnthas/repos/axis-pulse/src/app/api/admin/org/anthropic-key
```

Create `src/app/api/admin/org/anthropic-key/route.ts`:

```typescript
import { NextRequest, NextResponse } from "next/server"
import { generateText } from "ai"
import { createAnthropic } from "@ai-sdk/anthropic"
import { withAuthScoped } from "@/lib/withAuthScoped"
import { db } from "@/lib/db"
import { encryptApiKey, obscureKey } from "@/lib/anthropic-key"
import { ANTHROPIC_MODEL_ID } from "@/lib/ai-provider"

const MIN_KEY_LENGTH = 20 // sk-ant- (7) + at least 13 chars of key material

export async function POST(request: NextRequest) {
  const ctx = await withAuthScoped()
  if (!ctx) return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
  if (ctx.role !== "MANAGER") return NextResponse.json({ error: "Forbidden" }, { status: 403 })

  const body = (await request.json()) as Record<string, unknown>
  const apiKey = typeof body.apiKey === "string" ? body.apiKey.trim() : ""

  if (!apiKey.startsWith("sk-ant-") || apiKey.length < MIN_KEY_LENGTH) {
    return NextResponse.json(
      { error: "Invalid API key — must start with sk-ant- and be at least 20 characters" },
      { status: 400 }
    )
  }

  // Verify the key is actually valid by making a cheap test call
  try {
    const provider = createAnthropic({ apiKey })
    await generateText({
      model: provider(ANTHROPIC_MODEL_ID),
      prompt: "Hi",
      maxOutputTokens: 1,
    })
  } catch {
    return NextResponse.json(
      { error: "Key rejected by Anthropic — check that the key is active and has API access" },
      { status: 422 }
    )
  }

  const enc = encryptApiKey(apiKey)
  const hint = obscureKey(apiKey)

  await db.organisation.update({
    where: { id: ctx.organisationId },
    data: { anthropicApiKeyEnc: enc, anthropicKeyHint: hint },
  })

  return NextResponse.json({ hint })
}
```

- [ ] **Step 4: Run tests — all must pass**

```bash
npx vitest run src/tests/org-anthropic-key-api.test.ts 2>&1 | tail -8
```

Expected: `Tests  8 passed (8)`.

- [ ] **Step 5: Commit**

```bash
git add src/app/api/admin/org/anthropic-key/route.ts src/tests/org-anthropic-key-api.test.ts
git commit -m "feat: MANAGER-only POST /api/admin/org/anthropic-key — validates, encrypts, saves"
```

---

## Task 5: Update All AI Call Sites

**Files (all modified):**
- `src/lib/narrate.ts`
- `src/lib/gemini.ts`
- `src/lib/drift/analyse-ai.ts`
- `src/lib/drift/orchestrate.ts`
- `src/lib/debt/synthesise.ts`
- `src/lib/debt/orchestrate.ts`
- `src/app/api/projects/[id]/summary/route.ts`
- `src/app/api/ingest/event/route.ts`
- `src/lib/cost-ceiling.ts`

These changes are purely mechanical — no new behaviour, just threading the key through and updating the model call. Do them in order.

### 5a — `src/lib/narrate.ts`

- [ ] **Step 1: Update `generateNarration` signature and internals**

Find the function signature (around line 150):
```typescript
export async function generateNarration(
  event: ActivityEvent,
  project: Project,
  traceCtx?: TraceContext
): Promise<void> {
```

Change to:
```typescript
export async function generateNarration(
  event: ActivityEvent,
  project: Project,
  traceCtx?: TraceContext,
  anthropicApiKey?: string | null,
): Promise<void> {
```

Find the comment `// 8. Call AI provider` (around line 306) and the `getAutoCallModel()` call:
```typescript
const { model, modelId } = getAutoCallModel()
```
Replace with:
```typescript
const { model, modelId, usingFallback } = getAutoCallModel(anthropicApiKey)
if (usingFallback) {
  console.warn("[narrate] No Anthropic key — api key not set, defaulting to free models for project", project.id)
}
```

Update the comment on the line above the call from:
```
// 8. Call AI provider (OpenRouter free-model chain when key present, else Groq)
```
to:
```
// 8. Call AI provider (Anthropic Haiku when key present; OpenRouter/Groq fallback otherwise)
```

Find the `maxRetries: 0` comment and update:
```typescript
maxRetries: 0, // SDK must not retry — provider handles failover on fallback chain
```

Find the error log (around line 340):
```typescript
console.error("[narrate] Groq returned malformed JSON for project", project.id, rawText.slice(0, 200))
```
Change to:
```typescript
console.error("[narrate] AI returned malformed JSON for project", project.id, rawText.slice(0, 200))
```

Also remove the comment `// Groq sometimes wraps JSON in markdown code fences; strip them before parsing.` (Claude doesn't need this, but keep the stripping logic — it doesn't hurt).

### 5b — `src/lib/gemini.ts`

- [ ] **Step 2: Update `generateFeedSummary` signature and internals**

Find the function signature:
```typescript
export async function generateFeedSummary(
  excerpt: string,
  filesChanged?: string[],
  gitSummary?: string | null,
): Promise<string | null> {
  // Need at least one AI provider key; without either, skip the AI call
  if (!process.env.OPENROUTER_API_KEY && !process.env.GROQ_API_KEY) return null
```

Replace with:
```typescript
export async function generateFeedSummary(
  excerpt: string,
  filesChanged?: string[],
  gitSummary?: string | null,
  anthropicApiKey?: string | null,
): Promise<string | null> {
  if (!anthropicApiKey && !process.env.OPENROUTER_API_KEY && !process.env.GROQ_API_KEY) return null
```

Find `const { model } = getAutoCallModel()` and change to:
```typescript
const { model, usingFallback } = getAutoCallModel(anthropicApiKey)
if (usingFallback) {
  console.warn("[feed-summary] No Anthropic key — api key not set, defaulting to free models")
}
```

Update the `maxRetries: 0` comment:
```typescript
maxRetries: 0, // SDK must not retry — provider handles failover on fallback chain
```

### 5c — `src/lib/drift/analyse-ai.ts`

- [ ] **Step 3: Add `anthropicApiKey` param to `analyseAiDrift`**

Find the function signature (around line 540):
```typescript
export async function analyseAiDrift(
  assessmentId: string,
  organisationId: string,
```
Add the param at the end of the params:
```typescript
export async function analyseAiDrift(
  assessmentId: string,
  organisationId: string,
  anthropicApiKey?: string | null,
```

Find `const { model, modelId } = getAutoCallModel()` (around line 688):
```typescript
const { model, modelId } = getAutoCallModel()
```
Change to:
```typescript
const { model, modelId, usingFallback } = getAutoCallModel(anthropicApiKey)
if (usingFallback) {
  console.warn("[drift] No Anthropic key — api key not set, defaulting to free models")
}
```

Update the JSDoc comment on line 536:
```
// GitHub, calls the AI via getAutoCallModel() (Anthropic Haiku when key set; OpenRouter/Groq fallback),
```

Update the `maxRetries: 0` comment:
```typescript
maxRetries: 0, // SDK must not retry — provider handles failover on fallback chain
```

### 5d — `src/lib/drift/orchestrate.ts`

- [ ] **Step 4: Fetch org key in orchestrate, pass to analyseAiDrift**

Add import at the top of `src/lib/drift/orchestrate.ts`:
```typescript
import { getOrgAnthropicKey } from "@/lib/anthropic-key"
```

Find where `analyseAiDrift` is called (around line 234):
```typescript
const aiResult = await analyseAiDrift(assessmentId, organisationId)
```
Replace with:
```typescript
const anthropicApiKey = await getOrgAnthropicKey(organisationId)
const aiResult = await analyseAiDrift(assessmentId, organisationId, anthropicApiKey)
```

### 5e — `src/lib/debt/synthesise.ts`

- [ ] **Step 5: Add `anthropicApiKey` to `synthesiseDebtFindings` params**

Find the function signature (around line 205):
```typescript
export async function synthesiseDebtFindings(params: {
  organisationId:     string
```
Add the new field to the params type:
```typescript
export async function synthesiseDebtFindings(params: {
  organisationId:     string
  anthropicApiKey?:   string | null
```

Find the destructure (around line 211):
```typescript
const { organisationId, sonarIssues, securityFindings, detectedFrameworks } = params
```
Change to:
```typescript
const { organisationId, sonarIssues, securityFindings, detectedFrameworks, anthropicApiKey } = params
```

Find `const { model, modelId } = getAutoCallModel()` (around line 222):
```typescript
const { model, modelId } = getAutoCallModel()
```
Change to:
```typescript
const { model, modelId, usingFallback } = getAutoCallModel(anthropicApiKey)
if (usingFallback) {
  console.warn("[debt-synthesis] No Anthropic key — api key not set, defaulting to free models")
}
```

Find the `providerMeta` read (around line 261):
```typescript
const actualModel  = (providerMeta?.openrouter as { model?: string } | undefined)?.model ?? "unknown"
```
Change to:
```typescript
const actualModel = modelId
```
(The `providerMeta` variable and the `experimental_providerMetadata` cast can be removed entirely from that block — `modelId` is already the correct value.)

### 5f — `src/lib/debt/orchestrate.ts`

- [ ] **Step 6: Fetch org key, pass to synthesiseDebtFindings**

Add import at top of `src/lib/debt/orchestrate.ts`:
```typescript
import { getOrgAnthropicKey } from "@/lib/anthropic-key"
```

Find where `synthesiseDebtFindings` is called (around line 157). Before the call, add:
```typescript
const anthropicApiKey = await getOrgAnthropicKey(scan.organisationId)
```

On the call itself, add the new param:
```typescript
const synthesis = await synthesiseDebtFindings({
  organisationId: scan.organisationId,
  anthropicApiKey,
  // ... existing params unchanged
})
```

### 5g — `src/app/api/projects/[id]/summary/route.ts`

- [ ] **Step 7: Replace hardcoded Groq with getAutoCallModel + org key**

Replace the import block at the top. Remove:
```typescript
import { createGroq } from "@ai-sdk/groq"
```
Add:
```typescript
import { getAutoCallModel } from "@/lib/ai-provider"
import { getOrgAnthropicKey } from "@/lib/anthropic-key"
```

Remove the top-level constant:
```typescript
const MODEL = "llama-3.3-70b-versatile" as const
```

Find where the project is fetched (around line 45):
```typescript
const project = await db.project.findUnique({
  where: { id: params.id },
  include: { team: true },
})
if (!project) return NextResponse.json({ reason: "not_found" }, { status: 404 })
```

After the null check, add:
```typescript
const anthropicApiKey = await getOrgAnthropicKey(project.organisationId)
const { model, modelId, usingFallback } = getAutoCallModel(anthropicApiKey)
if (usingFallback) {
  console.warn("[summary] No Anthropic key — api key not set, defaulting to free models for project", params.id)
}
```

Find the hardcoded Groq call (near the bottom):
```typescript
const groqProvider = createGroq({ apiKey: process.env.GROQ_API_KEY })

const result = streamText({
  model: groqProvider(MODEL),
```
Replace with:
```typescript
const result = streamText({
  model,
```

Find where `modelUsed: MODEL` is stored in the `onFinish` callback:
```typescript
modelUsed: MODEL,
```
Replace with:
```typescript
modelUsed: modelId,
```

Also update the audit call where `model: MODEL` appears:
```typescript
await writeAudit(ctx.userId, "SUMMARY_GENERATE", params.id, { model: MODEL, ...
```
Change to:
```typescript
await writeAudit(ctx.userId, "SUMMARY_GENERATE", params.id, { model: modelId, ...
```

### 5h — `src/app/api/ingest/event/route.ts`

- [ ] **Step 8: Fetch org key, pass to narrate + feedSummary**

Add import at the top:
```typescript
import { getOrgAnthropicKey } from "@/lib/anthropic-key"
```

Find where `generateFeedSummary` and `firstSentence` are imported:
```typescript
import { generateFeedSummary, validateFeedSummary, firstSentence } from "@/lib/gemini"
```
(No change needed here.)

Find Step 12 (narration fire-and-forget, around line 244):
```typescript
// Step 12: Fire-and-forget narration (never blocks the 200 response)
void generateNarration(event, project, traceCtx)
```
Replace with:
```typescript
// Step 12: Fire-and-forget narration (never blocks the 200 response)
void (async () => {
  const anthropicApiKey = await getOrgAnthropicKey(project.organisationId)
  void generateNarration(event, project, traceCtx, anthropicApiKey)
})()
```

Find Step 15 (feed summary, around line 247):
```typescript
if (event.hookSource === "Stop" && event.sessionExcerpt) {
  const excerpt = event.sessionExcerpt
  const eventId = event.id
  const files = event.filesChanged ?? []
  const git = event.gitSummary ?? null
  void (async () => {
    let summary: string | null
    try {
      const aiResult = await generateFeedSummary(excerpt, files, git)
```
Replace `generateFeedSummary(excerpt, files, git)` with:
```typescript
const anthropicApiKey = await getOrgAnthropicKey(project.organisationId)
const aiResult = await generateFeedSummary(excerpt, files, git, anthropicApiKey)
```

(The `anthropicApiKey` fetch can be shared between Step 12 and Step 15 if they're in the same async block — but since they're separate fire-and-forget closures, each fetches independently. This is two DB reads but both are non-blocking and fast.)

Update the comment on the feed summary block:
```typescript
// Step 15: Fire-and-forget feed summary for Stop events (Anthropic Haiku; falls back to free models)
```

### 5i — `src/lib/cost-ceiling.ts`

- [ ] **Step 9: Update pricing to Haiku rates**

Find and replace the pricing constants:
```typescript
// Claude Sonnet 4.6 pricing (USD per token)
export const INPUT_COST_PER_TOKEN = 3e-6    // $3 / 1M input tokens
export const OUTPUT_COST_PER_TOKEN = 15e-6   // $15 / 1M output tokens
```
Replace with:
```typescript
// Claude Haiku 4.5 pricing (USD per token)
export const INPUT_COST_PER_TOKEN = 8e-7    // $0.80 / 1M input tokens
export const OUTPUT_COST_PER_TOKEN = 4e-6   // $4.00 / 1M output tokens
```

- [ ] **Step 10: Run tsc to confirm no type errors**

```bash
cd C:/Users/AfthabAnthas/repos/axis-pulse
npx tsc --noEmit 2>&1 | head -30
```

Expected: exit 0, no errors.

- [ ] **Step 11: Commit all call site changes**

```bash
git add src/lib/narrate.ts src/lib/gemini.ts src/lib/drift/analyse-ai.ts src/lib/drift/orchestrate.ts
git add src/lib/debt/synthesise.ts src/lib/debt/orchestrate.ts
git add src/app/api/projects/[id]/summary/route.ts src/app/api/ingest/event/route.ts
git add src/lib/cost-ceiling.ts
git commit -m "feat: thread Anthropic key through all AI call sites; update Haiku pricing"
```

---

## Task 6: Update Affected Tests

**Files:**
- Modify: `src/tests/narrate.test.ts`
- Modify: `src/tests/narrate-time-enrichment.test.ts`
- Modify: `src/tests/feed-summary.test.ts`
- Modify: `src/tests/executive-summary-api.test.ts`
- Modify: `src/tests/drift-analyse-ai.test.ts`
- Modify: `src/tests/ingest-narration.test.ts`

Run the full suite first to see which tests fail:

```bash
npx vitest run 2>&1 | grep -E "FAIL|Tests" | tail -20
```

### 6a — `src/tests/narrate.test.ts`

- [ ] **Step 1: Update mock return value and remove GROQ_API_KEY env lines**

The `getAutoCallModel` mock exists already at line 10:
```typescript
vi.mock("@/lib/ai-provider", () => ({
  getAutoCallModel: mockGetAutoCallModel,
}))
```

In `beforeEach`, find `mockGetAutoCallModel.mockReturnValue(...)` and update to include `usingFallback`:
```typescript
mockGetAutoCallModel.mockReturnValue({
  model: "mock-model",
  modelId: "claude-haiku-4-5-20251001",
  usingFallback: false,
})
```

Search for every `process.env.GROQ_API_KEY = "test-key"` in this file and delete those lines (there are ~7 of them). The test no longer needs a GROQ key since it mocks `getAutoCallModel` entirely.

Update test description strings that mention "Groq":
- `"skips Groq call when..."` → `"skips AI call when..."`
- `"does not crash when Groq returns malformed JSON"` → `"does not crash when AI returns malformed JSON"`
- `"does not crash when Groq returns an empty response"` → `"does not crash when AI returns an empty response"`
- Comment `// generateNarration — malformed JSON from Groq` → `// generateNarration — malformed JSON from AI`
- Comment `// TDD: SDK-level maxRetries must be 0 — failover happens at OpenRouter level` → `// TDD: SDK-level maxRetries must be 0 — SDK must not retry`

Run:
```bash
npx vitest run src/tests/narrate.test.ts 2>&1 | tail -8
```

Expected: all pass.

### 6b — `src/tests/narrate-time-enrichment.test.ts`

- [ ] **Step 2: Replace @ai-sdk/groq mock with @/lib/ai-provider mock**

The file currently has:
```typescript
vi.mock("@ai-sdk/groq", () => ({
  createGroq: vi.fn(() => () => "mocked-groq-model"),
}))
```

Replace this with:
```typescript
vi.mock("@/lib/ai-provider", () => ({
  getAutoCallModel: vi.fn(() => ({
    model: "mock-model",
    modelId: "claude-haiku-4-5-20251001",
    usingFallback: false,
  })),
}))
```

Remove every `process.env.GROQ_API_KEY = "test-key"` line.

Run:
```bash
npx vitest run src/tests/narrate-time-enrichment.test.ts 2>&1 | tail -8
```

Expected: all pass.

### 6c — `src/tests/feed-summary.test.ts`

- [ ] **Step 3: Update the null-guard test and beforeEach**

In `beforeEach`, find:
```typescript
process.env.GROQ_API_KEY = "test-key"
delete process.env.OPENROUTER_API_KEY
```
Replace with:
```typescript
delete process.env.GROQ_API_KEY
delete process.env.OPENROUTER_API_KEY
```
(The tests pass `anthropicApiKey` through the function now, so env vars don't gate the call.)

The existing test:
```typescript
it("returns null when neither GROQ_API_KEY nor OPENROUTER_API_KEY is set", async () => {
  delete process.env.GROQ_API_KEY
  delete process.env.OPENROUTER_API_KEY
  const result = await generateFeedSummary("some excerpt")
  expect(result).toBeNull()
})
```
Update description and assertion — passing no `anthropicApiKey` and no env vars must still return null:
```typescript
it("returns null when no Anthropic key and no fallback env keys are set", async () => {
  delete process.env.GROQ_API_KEY
  delete process.env.OPENROUTER_API_KEY
  const result = await generateFeedSummary("some excerpt", [], null, null)
  expect(result).toBeNull()
})
```

Update the comment `// 8. SDK-level maxRetries must be 0 — failover at OpenRouter level only` to:
`// 8. SDK-level maxRetries must be 0 — SDK must not retry`

Run:
```bash
npx vitest run src/tests/feed-summary.test.ts 2>&1 | tail -8
```

Expected: all 48 pass.

### 6d — `src/tests/executive-summary-api.test.ts`

- [ ] **Step 4: Replace @ai-sdk/groq mock with @ai-sdk/anthropic mock**

Find:
```typescript
vi.mock("@ai-sdk/groq", () => ({
  createGroq: vi.fn().mockReturnValue(() => "mock-model"),
}))
```
Replace with:
```typescript
vi.mock("@ai-sdk/anthropic", () => ({
  createAnthropic: vi.fn().mockReturnValue(() => "mock-model"),
}))
vi.mock("@/lib/anthropic-key", () => ({
  getOrgAnthropicKey: vi.fn().mockResolvedValue("sk-ant-test"),
}))
```

Run:
```bash
npx vitest run src/tests/executive-summary-api.test.ts 2>&1 | tail -8
```

Expected: all pass.

### 6e — `src/tests/drift-analyse-ai.test.ts`

- [ ] **Step 5: Update mock return value to include usingFallback**

Find:
```typescript
vi.mock("@/lib/ai-provider", () => ({
  getAutoCallModel: vi.fn(() => ({ model: "mock-model", modelId: "mock-model-id" })),
}))
```
Replace with:
```typescript
vi.mock("@/lib/ai-provider", () => ({
  getAutoCallModel: vi.fn(() => ({
    model: "mock-model",
    modelId: "claude-haiku-4-5-20251001",
    usingFallback: false,
  })),
}))
```

Run:
```bash
npx vitest run src/tests/drift-analyse-ai.test.ts 2>&1 | tail -8
```

Expected: all pass.

### 6f — `src/tests/ingest-narration.test.ts`

- [ ] **Step 6: Remove GROQ_API_KEY env line, add anthropic-key mock**

Find and delete:
```typescript
process.env.GROQ_API_KEY = "test-key"
```

Add a mock for `getOrgAnthropicKey` so the ingest route can fetch the key without hitting the DB:
```typescript
vi.mock("@/lib/anthropic-key", () => ({
  getOrgAnthropicKey: vi.fn().mockResolvedValue("sk-ant-test-key"),
}))
```

Run:
```bash
npx vitest run src/tests/ingest-narration.test.ts 2>&1 | tail -8
```

Expected: all pass.

- [ ] **Step 7: Run the full test suite**

```bash
npx vitest run 2>&1 | tail -8
```

Expected: all tests pass (2140+ tests).

- [ ] **Step 8: Commit**

```bash
git add src/tests/narrate.test.ts src/tests/narrate-time-enrichment.test.ts
git add src/tests/feed-summary.test.ts src/tests/executive-summary-api.test.ts
git add src/tests/drift-analyse-ai.test.ts src/tests/ingest-narration.test.ts
git commit -m "test: update all test mocks for Anthropic provider migration"
```

---

## Task 7: Install Page UI — `AnthropicKeySection`

**Files:**
- Create: `src/app/install/_components/AnthropicKeySection.tsx`
- Modify: `src/app/install/page.tsx`

- [ ] **Step 1: Create `AnthropicKeySection.tsx`**

Create `src/app/install/_components/AnthropicKeySection.tsx`:

```tsx
"use client"

import { useState } from "react"
import styles from "../install.module.css"

interface Props {
  initialHint: string | null
}

type Phase =
  | { type: "idle" }
  | { type: "loading" }
  | { type: "success"; hint: string }
  | { type: "error"; message: string }

export function AnthropicKeySection({ initialHint }: Props) {
  const [phase, setPhase] = useState<Phase>({ type: "idle" })
  const [keyInput, setKeyInput] = useState("")
  const [currentHint, setCurrentHint] = useState(initialHint)

  async function save() {
    const trimmed = keyInput.trim()
    if (!trimmed) return
    setPhase({ type: "loading" })
    try {
      const res = await fetch("/api/admin/org/anthropic-key", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ apiKey: trimmed }),
      })
      const body = await res.json() as { hint?: string; error?: string }
      if (!res.ok) {
        setPhase({ type: "error", message: body.error ?? `HTTP ${res.status}` })
        return
      }
      setCurrentHint(body.hint ?? null)
      setKeyInput("")
      setPhase({ type: "success", hint: body.hint ?? "" })
    } catch {
      setPhase({ type: "error", message: "Network error — please try again." })
    }
  }

  const isValidFormat = keyInput.trim().startsWith("sk-ant-") && keyInput.trim().length >= 20

  return (
    <div
      className={styles.card}
      style={{
        position: "relative",
        background: "var(--card-gradient)",
        border: "1px solid var(--border)",
        borderRadius: "14px",
        overflow: "hidden",
      }}
    >
      {/* Top accent line — purple tint to distinguish from green install steps */}
      <div style={{
        position: "absolute",
        top: 0, left: 0, right: 0,
        height: "1px",
        background: "linear-gradient(90deg, transparent 0%, rgba(139,92,246,0.35) 30%, rgba(139,92,246,0.15) 70%, transparent 100%)",
      }} />

      <div style={{ padding: "22px 24px 24px" }}>
        {/* Header */}
        <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", marginBottom: "14px" }}>
          <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
            <div style={{
              width: "34px",
              height: "34px",
              borderRadius: "9px",
              background: "rgba(139,92,246,0.08)",
              border: "1px solid rgba(139,92,246,0.22)",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              flexShrink: 0,
            }}>
              {/* AI sparkle icon */}
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path d="M12 2l2.4 7.2H22l-6.2 4.5 2.4 7.3L12 17l-6.2 4L8.2 13.7 2 9.2h7.6L12 2z" fill="rgba(139,92,246,0.8)" />
              </svg>
            </div>
            <div>
              <p style={{ margin: 0, fontSize: "9px", fontWeight: 700, letterSpacing: "0.10em", textTransform: "uppercase", color: "var(--text-muted)", lineHeight: 1 }}>
                AI Intelligence
              </p>
              <p style={{ margin: "3px 0 0", fontSize: "15px", fontWeight: 700, color: "var(--text-primary)", lineHeight: 1.2 }}>
                Anthropic API Key
              </p>
            </div>
          </div>

          {/* Key status chip */}
          {currentHint ? (
            <div style={{
              display: "flex",
              alignItems: "center",
              gap: "5px",
              padding: "3px 8px",
              background: "rgba(139,92,246,0.06)",
              border: "1px solid rgba(139,92,246,0.18)",
              borderRadius: "6px",
              flexShrink: 0,
            }}>
              <div style={{ width: "5px", height: "5px", borderRadius: "50%", background: "rgba(139,92,246,0.8)", boxShadow: "0 0 4px rgba(139,92,246,0.5)" }} />
              <span style={{ fontSize: "10px", color: "rgba(139,92,246,0.8)", fontFamily: "var(--font-mono)" }}>
                {currentHint}
              </span>
            </div>
          ) : (
            <div style={{
              display: "flex",
              alignItems: "center",
              gap: "5px",
              padding: "3px 8px",
              background: "rgba(251,191,36,0.06)",
              border: "1px solid rgba(251,191,36,0.18)",
              borderRadius: "6px",
              flexShrink: 0,
            }}>
              <div style={{ width: "5px", height: "5px", borderRadius: "50%", background: "rgba(251,191,36,0.7)" }} />
              <span style={{ fontSize: "10px", color: "rgba(251,191,36,0.8)" }}>not configured</span>
            </div>
          )}
        </div>

        {/* Description */}
        <p style={{ margin: "0 0 6px", fontSize: "13px", color: "var(--text-secondary)", lineHeight: 1.65 }}>
          Powers narrations, feed summaries, drift analysis, and executive summaries with{" "}
          <span style={{ color: "var(--text-primary)", fontWeight: 600 }}>Claude Haiku</span>
          {" "}— fast, accurate, structured output. Without a key, the platform falls back to free AI models which are unreliable for structured tasks.
        </p>

        {/* Warning when not configured */}
        {!currentHint && (
          <div style={{
            display: "flex",
            alignItems: "flex-start",
            gap: "8px",
            padding: "7px 10px",
            background: "rgba(251,191,36,0.05)",
            border: "1px solid rgba(251,191,36,0.14)",
            borderRadius: "7px",
            marginBottom: "16px",
          }}>
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" style={{ flexShrink: 0, marginTop: "1px" }}>
              <path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm9-1v4.5H7V7h2Zm-1-3a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z" fill="rgba(251,191,36,0.7)" fillRule="evenodd" />
            </svg>
            <p style={{ margin: 0, fontSize: "11.5px", color: "rgba(251,191,36,0.8)", lineHeight: 1.5 }}>
              <strong style={{ color: "rgba(251,191,36,0.95)" }}>API key not set — defaulting to free models.</strong>{" "}
              Narrations and summaries may be unreliable or fail silently. Add your key below to enable Haiku.
            </p>
          </div>
        )}

        {/* Input + save */}
        <div style={{ display: "flex", gap: "8px", alignItems: "flex-start", marginBottom: "8px" }}>
          <input
            type="password"
            value={keyInput}
            onChange={(e) => {
              setKeyInput(e.target.value)
              setPhase({ type: "idle" })
            }}
            placeholder="sk-ant-api03-..."
            disabled={phase.type === "loading"}
            style={{
              flex: 1,
              padding: "8px 12px",
              background: "var(--surface-subtle)",
              border: `1px solid ${phase.type === "error" ? "rgba(239,68,68,0.4)" : "var(--border-mid)"}`,
              borderRadius: "8px",
              color: "var(--text-primary)",
              fontSize: "13px",
              fontFamily: "var(--font-mono)",
              outline: "none",
            }}
          />
          <button
            onClick={save}
            disabled={!isValidFormat || phase.type === "loading"}
            className={styles.btnPrimary}
            style={{
              padding: "8px 16px",
              background: isValidFormat && phase.type !== "loading" ? "rgba(139,92,246,0.9)" : "var(--surface-subtle)",
              border: "none",
              borderRadius: "8px",
              color: isValidFormat && phase.type !== "loading" ? "#fff" : "var(--text-muted)",
              fontSize: "13px",
              fontWeight: 600,
              cursor: isValidFormat && phase.type !== "loading" ? "pointer" : "not-allowed",
              whiteSpace: "nowrap",
              flexShrink: 0,
            }}
          >
            {phase.type === "loading" ? (
              <span style={{ display: "flex", alignItems: "center", gap: "6px" }}>
                <span className={styles.spinner} style={{ width: "11px", height: "11px", borderWidth: "1.5px" }} />
                Verifying…
              </span>
            ) : currentHint ? "Update key" : "Save key"}
          </button>
        </div>

        {/* Status messages */}
        {phase.type === "error" && (
          <p style={{ margin: "4px 0 0", fontSize: "12px", color: "rgba(239,68,68,0.9)", lineHeight: 1.5 }}>
            {phase.message}
          </p>
        )}
        {phase.type === "success" && (
          <p style={{ margin: "4px 0 0", fontSize: "12px", color: "rgba(139,92,246,0.9)", lineHeight: 1.5 }}>
            ✓ Key saved — Haiku is now active for all AI tasks. Showing as{" "}
            <code style={{ fontFamily: "var(--font-mono)", fontSize: "11px" }}>{phase.hint}</code>
          </p>
        )}

        <p style={{ margin: "10px 0 0", fontSize: "11px", color: "var(--text-muted)", lineHeight: 1.5 }}>
          Get your key at{" "}
          <a
            href="https://console.anthropic.com/settings/keys"
            target="_blank"
            rel="noreferrer"
            style={{ color: "rgba(139,92,246,0.8)", textDecoration: "underline", textDecorationStyle: "dotted", textUnderlineOffset: "2px" }}
          >
            console.anthropic.com/settings/keys
          </a>
          {" "}— stored encrypted, visible only to Managers.
        </p>
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Update `src/app/install/page.tsx`**

Add the import at the top:
```typescript
import { AnthropicKeySection } from "./_components/AnthropicKeySection"
```

Add a DB fetch alongside the existing queries. Find:
```typescript
const [user, existingToken] = await Promise.all([
  db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } }),
  db.userToken.findUnique({
    where:  { userId: ctx.userId },
    select: { tokenPreview: true },
  }),
])
```
Change to:
```typescript
const [user, existingToken, org] = await Promise.all([
  db.user.findUnique({ where: { id: ctx.userId }, select: { name: true } }),
  db.userToken.findUnique({
    where:  { userId: ctx.userId },
    select: { tokenPreview: true },
  }),
  ctx.role === "MANAGER"
    ? db.organisation.findUnique({
        where: { id: ctx.organisationId },
        select: { anthropicKeyHint: true },
      })
    : Promise.resolve(null),
])
```

In the JSX, find the `<div>` that wraps sections:
```tsx
<div style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
  <MachineSetupSection existingPreview={existingToken?.tokenPreview ?? null} />
  <InstallClient />
</div>
```
Change to:
```tsx
<div style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
  <MachineSetupSection existingPreview={existingToken?.tokenPreview ?? null} />
  <InstallClient />
  {ctx.role === "MANAGER" && (
    <AnthropicKeySection initialHint={org?.anthropicKeyHint ?? null} />
  )}
</div>
```

Also update the step header from `2 steps` to `2 steps + AI setup`:
```tsx
<span style={{...}}>
  2 steps + AI setup
</span>
```

- [ ] **Step 3: Run tsc to confirm no type errors**

```bash
npx tsc --noEmit 2>&1 | head -20
```

Expected: exit 0.

- [ ] **Step 4: Run full test suite**

```bash
npx vitest run 2>&1 | tail -8
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/app/install/_components/AnthropicKeySection.tsx src/app/install/page.tsx
git commit -m "feat: AnthropicKeySection on install page — MANAGER can save/update org Anthropic key"
```

---

## Task 8: Final Verification + Push

- [ ] **Step 1: Run tsc clean**

```bash
npx tsc --noEmit 2>&1
```

Expected: no output, exit 0.

- [ ] **Step 2: Run full test suite**

```bash
npm test 2>&1 | tail -10
```

Expected: all tests pass (2140+), 0 failed.

- [ ] **Step 3: Smoke test the UI manually**

Start the dev server:
```bash
npm run dev
```

1. Log in as a MANAGER user
2. Visit `/install`
3. Confirm the `Anthropic API Key` section renders at the bottom
4. Confirm the "not configured" yellow chip shows when no key is set
5. Enter an invalid key (e.g. `gsk-fake`) → confirm 400 error message appears
6. Enter a valid `sk-ant-api03-...` key → confirm "Verifying…" spinner, then success message with hint
7. Refresh the page → confirm the purple chip now shows the hint instead of "not configured"
8. Log in as a MEMBER or LINE_MANAGER → confirm the section does NOT appear on `/install`

- [ ] **Step 4: Verify ANTHROPIC_KEY_ENCRYPTION_SECRET in your Coolify env**

In Coolify environment variables, add:
```
ANTHROPIC_KEY_ENCRYPTION_SECRET=<64 hex chars from: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))">
```

This must be set BEFORE deploy — without it the encrypt/decrypt calls throw and key saves will fail.

- [ ] **Step 5: Push**

```bash
git push origin afthab/axis-pulse
```

Wait for CI to pass (ESLint → tsc → Prisma migrate → Vitest → build). Do NOT assume deployed until Coolify webhook fires and the build log shows green.

---

## Self-Review

**Spec coverage check:**

| Requirement | Task |
|---|---|
| Org-wide, MANAGER-only key | Task 4 (RBAC gate) + Task 7 (UI MANAGER-only) |
| One key per org | Organisation model has one field; POST always overwrites |
| AES-256-GCM encryption | Task 2 |
| Key verified against Anthropic on save | Task 4 (test call in route) |
| Haiku for narrations | Task 5a (`narrate.ts`) |
| Haiku for feed summary | Task 5b (`gemini.ts`) |
| Haiku for drift analysis | Task 5c + 5d |
| Haiku for debt synthesis | Task 5e + 5f |
| Haiku for executive summary | Task 5g |
| Haiku for ingest feed (Stop events) | Task 5h |
| Fallback to OpenRouter/Groq with warning | Task 3 (`getAutoCallModel`) |
| "api key not set" disclaimer in server logs | Task 3 + every call site in Task 5 |
| Warning banner on install page when not set | Task 7 (`AnthropicKeySection`) |
| Updated cost pricing | Task 5i |
| All tests updated | Task 6 |
| Env var documented | Task 1 (`.env.example`) |

**No gaps found.**
