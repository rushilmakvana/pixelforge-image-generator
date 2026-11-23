# PromptForge: image generation on Pixelforge

A Laravel 12, Vue 3 and Inertia app. Users write a prompt, see what it will cost, and generate an image through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API. They are billed Pixelforge's exact cost plus 25%.

- **Generate** (`/generate`): prompt, size, quality and up to 4 reference URLs, with a live cost estimate.
- **History** (`/history`): every generation with live status, estimated and actual cost, and the image.
- **Credits** (`/credits`): balance, simulated "Buy credits" packs, and the full ledger.
- **Spend report** (`/admin/report`, admins only): charged, provider cost and profit per UTC day and per user,
  reconciled against Pixelforge's `GET /v1/usage`.

API notes: [docs/PIXELFORGE_NOTES.md](docs/PIXELFORGE_NOTES.md).

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET in .env
composer dev            # web server + queue worker + scheduler + vite
```

Webhooks need a public URL. Run a tunnel (`ngrok http 8000`, `expose share`, or `cloudflared`), then set
`https://<tunnel>/webhooks/pixelforge` as the webhook URL of your **local** Pixelforge API key.

To make someone an admin: `php artisan app:make-admin you@example.com`.

Tests: `php artisan test`. They cover pricing against the docs' worked examples, duplicate, out-of-order and early
webhooks, signature checks, and failure and rejection paths.

## How the money works

**Units.** All amounts are integer **nanodollars** (`BIGINT`, 1 USD = 10⁹). Pixelforge reports costs to 9 decimals,
and every rate × token product is a whole number of nanodollars, so nothing is ever rounded. With the current rates
every provider cost is a multiple of 2,500 nanodollars, so the 25% markup is exact as well. If rates ever stopped
dividing evenly, the code rounds *up* by at most a billionth of a dollar. Floats never touch money.

**Actual cost.** `provider_cost = Σ usage[type] × rates[type] / 1M` uses the `rates` frozen on the generation when it
was created, not today's prices. Then `charge = provider_cost × 1.25`. Both are stored on the generation.

**Estimate.** Output and reference-image tokens are known exactly up front. Prompt tokens (`ceil(chars/4)` ±15%) and
reasoning tokens (a range per quality) are not. The UI shows the expected cost (estimated prompt tokens, midpoint
reasoning) and the full min–max range. The server computes the estimate with the same calculator that does the
billing.

**Balance (ledger).** `credit_transactions` is append-only and `users.balance_nanos` is its cached sum, updated in the
same transaction.

| Event | Ledger entry |
|---|---|
| Buy credits | `purchase +X` |
| Submit | `hold −max_estimate` (refused if the balance can't cover the worst case) |
| Succeeded | `release +max_estimate`, `charge −actual` |
| Failed / rejected | `release +max_estimate` (free) |

Holding the worst case means a user can never be charged more than they have. A unique index on
`(generation_id, type)` makes a second charge for the same generation impossible at the database level.

## Robustness

**Submitting** (`SubmitGeneration` job)
- The generation row is created first, with a UUID. That UUID is sent as the `Idempotency-Key` on every attempt, so
  retries after a timeout or a 5xx can't create or bill a second generation.
- `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, `503 overloaded` and network errors are retried after
  `Retry-After` (with exponential backoff and jitter) for up to about 30 minutes. Meanwhile the card shows why it is
  waiting, e.g. "Waiting for a free slot".
- `400 content_policy_violation`, `422`, `429 daily_limit_exceeded` and `401` fail the generation immediately, with a
  clear message, at no cost.

**State machine** (`GenerationService::applyRemote`). Every update (the POST response, a webhook, or a poll) goes
through one method. That method locks the generation row and only moves status *forward*:
`pending → queued → processing → succeeded | failed`. A final state never changes. Settling the hold and charging
happens there, exactly once.

**Webhooks** (`POST /webhooks/pixelforge`)
- An HMAC-SHA256 signature over `"{t}.{raw body}"`, compared in constant time; timestamps older than 300 s are
  rejected (`400`).
- Each event id is stored in `webhook_events` (unique). A redelivered event is acknowledged and skipped. Recording the
  event and applying it commit in one transaction, so if applying fails, Pixelforge's retry tries again.
- **Out of order:** a late `processing` after `succeeded` is ignored by the forward-only state machine.
- **Before the POST returns:** our row id travels in `metadata.generation_id`, so a webhook can find its generation
  before we've stored Pixelforge's `gen_…` id.
- **Lost submission:** if we gave up after timeouts but Pixelforge did create the generation, its webhook still
  updates the generation and bills it correctly.

**Polling fallback.** Webhook delivery isn't guaranteed. A scheduled task (every minute) polls in-flight generations
that haven't changed for a minute, at most 20 per run, to stay under the 60 requests/min limit.

**Live UI.** The Generate and History pages re-fetch their data every 2.5 s while anything is still in flight, and
stop once everything is final.

**Admin report.** It groups succeeded generations by the **UTC day they completed**, using Pixelforge's own
`completed_at`, because that is how `/v1/usage` buckets them. Each day shows Pixelforge's figure next to ours, with a
✓ or ✗.

## Deploying (Laravel Cloud)

1. Push to GitHub and create a Laravel Cloud app from the repo, with a Postgres or MySQL database attached.
2. Set the environment variables `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, using a **separate**
   Pixelforge key for production.
3. Enable a **queue worker** (`php artisan queue:work`) and the **scheduler**.
4. Deploy commands: `php artisan migrate --force`.
5. In the Pixelforge dashboard, set the production key's webhook URL to `https://<app>/webhooks/pixelforge`.
