# Pixelforge API — working notes

Source: https://pixelforge.promptedit.dev/docs (single page). These are condensed notes, not a copy.

## Basics
- Base URL `https://pixelforge.promptedit.dev/v1`, header `Authorization: Bearer <key>`, JSON in/out.
- Ids: `gen_…` generations, `evt_…` webhook events. Timestamps ISO 8601 UTC.
- Money in API responses is an exact decimal string with 9 decimals (`"0.169080000"`). Never floats.
- The API **never returns a price** — compute it from `usage` × `rates`.
- Limits are per **account** (across keys): 60 req/min, **3 queued+processing at once**, 500 generations per UTC day.

## Endpoints
| Method | Path | Notes |
|---|---|---|
| POST | `/generations` | `prompt` (≤4000 chars, required), `size` (`1024x1024` default, `1536x1024`, `1024x1536`), `quality` (`low`, `medium` default, `high`), `reference_images` (≤4 http(s) URLs), `metadata` (≤20 string→string, values ≤500). Unknown params → 422. Returns **202** generation with `status: queued`. Optional `Idempotency-Key` (≤255 chars). |
| GET | `/generations/{id}` | 404 for missing or other account's. |
| GET | `/generations` | `limit` 1–100, `status`, `cursor`; returns `data`, `has_more`, `next_cursor`. |
| GET | `/pricing` | No auth. Rates + token tables. |
| GET | `/usage` | `start_date`, `end_date` (UTC, inclusive, ≤92 days). Per-key. Per UTC day **of completion**. `cost_usd` 9-decimal string. |

### Idempotency
- Same key + same params → 202 + `Idempotent-Replayed: true`, returns the existing generation (current status). Not billed again, doesn't count toward limits.
- Same key + different params → 409 `idempotency_key_reused`. Keys never expire, account-scoped.

## Generation object
`id, object, status, prompt, size, quality, reference_images, metadata, image_url, image_url_expires_at, usage, rates, error, created_at, started_at, completed_at`

- Status: `queued → processing → succeeded | failed` (final).
- `usage` (only when succeeded): `input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, total_tokens`.
- `rates`: per-1M-token USD strings frozen **at creation** — bill with these.
- `error` (only when failed): `code` = `generation_failed` | `internal_error`.
- `image_url` signed PNG, expires **24h after completion** (re-fetching doesn't extend).
- Timing: a few seconds queued, 5–30 s processing (`[slow]`: 60–90 s).

## Pricing (USD per 1M tokens)
| Type | Rate | Known up front? |
|---|---|---|
| input_text | 5.00 | estimate: `ceil(chars/4)`, actual within ±15% (Unicode chars; keyword tags count) |
| input_image | 10.00 | exact: 765 per reference image |
| output | 40.00 | exact — table below |
| reasoning | 12.50 | range only — table below |

Output tokens:

| quality | 1024x1024 | 1536x1024 | 1024x1536 |
|---|---|---|---|
| low | 280 | 420 | 420 |
| medium | 1100 | 1650 | 1650 |
| high | 4300 | 6450 | 6450 |

Reasoning ranges: low 0–500, medium 200–2000, high 1000–8000.

Cost = Σ tokens × rate / 1,000,000. Never rounded by Pixelforge. Only succeeded generations are billed.

Docs worked example (max): high 1536x1024, 400-char prompt, 2 refs, max reasoning → **$0.373875**.
Usage example: 41 text + 3300 out + 2950 reasoning → **$0.169080000**.

## Errors (`{"error": {type, code, message, param?, errors?}}`)
| HTTP | code | Handling |
|---|---|---|
| 401 | invalid_api_key | config problem |
| 422 | validation_failed | user error, show message |
| 400 | content_policy_violation | nothing created, show message |
| 404 | not_found | |
| 409 | idempotency_key_reused | bug on our side |
| 429 | rate_limit_exceeded | wait `Retry-After` |
| 429 | concurrency_limit_exceeded | `Retry-After: 5`; retry when one finishes |
| 429 | daily_limit_exceeded | no Retry-After; give up until next UTC day |
| 503 | overloaded | `Retry-After: 2`; nothing created; retry with same Idempotency-Key |
| 422 | date_range_too_long | usage range > 92 days |

Rejected requests create nothing and cost nothing. Retries count toward the per-minute limit.

## Webhooks
- Events: `generation.processing`, `generation.succeeded`, `generation.failed`, `ping`. **No queued event.**
- Body: `{id: evt_…, object: "event", type, created_at, data: {object: <generation>}}`.
- Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature: t=<unix>,v1=<hex>`.
- Signature: `hex(HMAC-SHA256(secret, "{t}.{raw body}"))`, constant-time compare, reject `|now - t| > 300`.
- 2xx within 10 s = delivered. Else retried at 10s, 30s, 2m, 10m, 15m (6 attempts). 3xx = failure.
- **At-least-once** (dedupe on event id), **out of order** (never regress from a final state), **may arrive before the POST response returns**.
- Delivery not guaranteed → poll as a fallback.
- URL must be public (no localhost) → use a tunnel locally.

## Test keywords (case-insensitive, anywhere in prompt, count as text tokens)
`[blocked]` 400 content policy · `[overloaded]` 503 · `[fail]` accepted then fails · `[succeed]` never fails randomly · `[slow]` 60–90 s · `[duplicate]` every webhook sent twice.
Without keywords: random small share of failures, 503s and duplicate webhooks.
