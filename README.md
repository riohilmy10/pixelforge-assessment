# Pixelforge

A Laravel + Vue + Inertia app that generates images through the simulated
[Pixelforge](https://pixelforge.promptedit.dev) API, shows the price before submit, and records
the exact cost of every finished generation.

## What it does

- Sign in, write a prompt, choose a size and quality, and optionally add reference image URLs.
- See a live quote before submitting. Pixelforge only reveals text and reasoning token counts
  when a generation finishes, so the quote is a range: a typical figure and a worst case.
- Follow status live (`queued`, `processing`, `succeeded`, `failed`) and see the image.
- Hold credits, buy more with a simulated top-up, and see every movement in a ledger. The
  worst-case quote is reserved while a generation runs, so concurrent generations cannot spend
  the same credits.
- Every succeeded generation records both the provider cost and the user price (provider cost +
  25%). Failed generations cost nothing, and duplicate or out-of-order webhooks cannot
  double-charge. The user debit is capped at the held quote; a rare rate change above the
  quote is absorbed and recorded on the generation rather than making the balance negative.
- Operators get a report of charged spend, provider cost and profit by UTC day and by user,
  reconciled against Pixelforge's own `GET /v1/usage`.

## Stack

Laravel 13, Vue 3 + Inertia v3, TypeScript, Tailwind 4, PostgreSQL 18, Laravel Sail, PHPUnit 12,
Pint, Larastan. Wayfinder generates the TypeScript route helpers.

## Local setup

Everything runs in Docker through Laravel Sail; you do not need PHP or Node on the host.
The commands below assume the `sail` alias; without it, replace `sail` with
`./vendor/bin/sail`.

### Optional: `sail` alias

To stop typing `./vendor/bin/sail`, add the alias Laravel documents:

```bash
# ~/.zshrc on macOS (or ~/.bashrc)
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```

Reload the shell with `source ~/.zshrc`, then run everything from the project root as
`sail up -d`, `sail npm run dev`, `sail artisan test`. The `-f sail` check uses a project-local
`./sail` script when one exists and falls back to `vendor/bin/sail` otherwise.

```bash
cp .env.example .env          # fill in the Pixelforge values

# A fresh checkout has no vendor/ yet, so install Composer dependencies in a container
# before `sail` (which lives in vendor/bin) exists. This works without host PHP.
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  composer:2 install --no-interaction --prefer-dist

# Run Sail with the same ownership as your host checkout.
export WWWUSER="$(id -u)" WWWGROUP="$(id -g)"
sail up -d
sail artisan migrate
sail artisan storage:link
sail npm install && sail npm run dev
```

The host ports are moved off the Sail defaults in `.env` (`APP_PORT=8001`, `VITE_PORT=5174`,
`FORWARD_DB_PORT=5434`, `FORWARD_MAILPIT_PORT=1026`, `FORWARD_MAILPIT_DASHBOARD_PORT=8026`) so
they do not collide with other local projects. Container-internal ports are unchanged. Open the
app at <http://localhost:8001> and Mailpit at <http://localhost:8026>.

Run the queue worker and scheduler in separate terminals:

```bash
sail artisan queue:work --tries=1 --timeout=120
sail artisan schedule:work
```

### Pixelforge credentials

Create one API key per environment in the [Pixelforge dashboard](https://pixelforge.promptedit.dev/register);
each key has its own webhook URL and signing secret. Then set:

```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
PIXELFORGE_MARKUP_PERCENT=25
```

### Webhook tunnel

Webhooks need a public URL. The easiest option is a Cloudflare quick tunnel; it runs
`cloudflared` in Docker so nothing needs to be installed on the host:

```bash
docker run --rm --name pixelforge-tunnel \
  --add-host host.docker.internal:host-gateway \
  cloudflare/cloudflared:latest \
  tunnel --url http://host.docker.internal:8001 --no-autoupdate
```

If `cloudflared` is installed on the host, use:

```bash
cloudflared tunnel --url http://localhost:8001
```

The command prints a `https://<random>.trycloudflare.com` URL. Append `/webhooks/pixelforge`
and paste that into the key's settings in the Pixelforge dashboard. A quick-tunnel URL changes
every time the tunnel restarts, so update the dashboard when you start a new one.

To watch deliveries locally, run `./vendor/bin/sail artisan pail` in another terminal. Each
webhook logs `pixelforge.webhook_ping`, `pixelforge.webhook_accepted`,
`pixelforge.webhook_duplicate` or `pixelforge.webhook_processed` with its event id.

## How the money works

All amounts are integer **nanodollars** (`1 USD = 1_000_000_000 nano`). Pixelforge returns exact
decimal strings for rates and exact integer token counts, so money never passes through a float.

- A quote uses the documented token model: text ≈ characters / 4 (±15%), 765 input image tokens
  per reference, exact output tokens by quality/size, and the per-quality reasoning range.
- The worst-case quote is shown to the user ("pay at most") and snapshotted on the generation.
- The same worst-case amount is **reserved** from the user's available credits before the
  generation is queued. The reserve locks the user row, so two concurrent submits cannot spend
  the same credits.
- On success, the provider cost is recomputed from the generation's **stored rates** and its
  reported usage, then the 25% markup is applied. The hold is released and the actual debit is
  taken in one transaction. The nominal charge is never below provider cost; the user debit is
  capped at the held quote, so a rare rate change above the quote becomes an absorbed overage
  (logged and stored as the difference between `charged_nano` and `settled_charge_nano`).
- A failed generation releases the whole hold and charges nothing. A provisional
  `submit_failed` keeps the hold until reconciliation proves whether Pixelforge accepted the
  generation, so a late success can still settle correctly. Reconciliation is bounded to recent
  rows; an unresolved hold older than 24 hours is released by a scheduled sweep so credits
  cannot be frozen forever.
- `completed_at` is the provider's completion time, so reports aggregate on the UTC day Pixelforge
  bills.

## Credits, balances and the operator report

**Credits.** The dashboard shows available credits (`owned − held`) next to the generator and
blocks submit when the worst-case quote exceeds them. The Credits page has $5/$10/$25 simulated
top-ups and a ledger that explains every purchase, hold, release and charge. A user can still
generate while a previous generation is running as long as the remaining available balance covers
the new worst-case quote.

**Operator access.** Registration never grants operator access. Provision the operator
explicitly after deployment with:

```bash
sail artisan pixelforge:make-admin person@example.com
```

The **Operator report** link appears in the sidebar for admins. It shows charged spend (what users
paid), provider cost (what Pixelforge charged us) and profit for each UTC completion day and each
user, plus a reconciliation strip that compares our provider-cost total with a
`GET /v1/usage` call for the selected range. Pixelforge caps that endpoint at 92 days, so the
report applies the same limit. Provider usage is cached for a minute on normal page loads; the
Apply button and quick ranges pass `refresh=1` to bypass that cache and show Pixelforge immediately.
If Pixelforge is unavailable, the local report still renders and the strip says reconciliation
could not be loaded.

## Webhook robustness

- The signature is verified over the raw request body before JSON decoding, with a 300 second
  replay window and constant-time comparison.
- Deliveries are deduplicated by Pixelforge's event id with a unique index, so a redelivery is
  acknowledged with `204` and never enqueued twice.
- The state machine only moves forward, and `succeeded`/`failed` are final. A late `processing`
  event cannot reopen a finished generation. The one exception is an ambiguous `submit_failed`:
  a late success is still allowed to settle it so a provider-accepted generation is not lost.
- Ambiguous submissions are replayed only for a bounded window. If Pixelforge never confirms
  one, the scheduled stale-hold sweep releases the reservation and closes the row, so credits
  cannot stay frozen forever.
- Cost columns are written once, under a row lock, only on success.
- A scheduled poller reconciles any generation that has not reached a terminal state, covering
  webhooks that were never delivered.
- A scheduled sweep re-dispatches stored webhook events whose processing job was lost or
  interrupted; the unique event id keeps normal redeliveries from creating duplicate work.

## Tests

```bash
sail artisan test      # backend (PHPUnit)
sail npm test          # front-end components (Vitest)
```

The backend suite runs against the Sail Postgres `testing` database and uses `Http::fake`, so it
never calls Pixelforge or needs credentials. It covers the documented worked example, quote ranges, idempotent retries,
signature failures, duplicate deliveries, out-of-order events, exact settlement, failures, image
storage, webhook pruning and the polling fallback. The stretch-goal tests add:
credit reservation and overdraw prevention, exact hold settlement, full refund on failure,
ambiguous-submit holds, legacy rows without a hold, the credit ledger, admin authorization,
per-day/per-user report totals, range validation, first-user operator promotion, and
`/v1/usage` reconciliation (including a graceful provider outage).

The front-end suite uses Vitest + `@vue/test-utils` with happy-dom. It covers the generation
progress stages and settled/failed costs, the image-zoom dialog, the reference-image repeater
limits, and the dashboard's insufficient-credit submit gate. Run it in watch mode with
`sail npm run test:watch`.

```bash
sail exec -T app bash -lc './vendor/bin/pint --test && ./vendor/bin/phpstan analyse --no-progress'
sail npm run check && sail npm run types:check
```

## Front-end

The UI is Vue 3 + Inertia + TypeScript built by Vite. Node is not needed on the host: the Sail
image already ships Node, npm, pnpm and bun.

```bash
sail npm install            # once, or after package.json changes
sail npm run dev            # Vite dev server with HMR
```

`npm run dev` runs Vite inside the `app` container on `VITE_PORT` (5174 in `.env`) and
`compose.yaml` publishes that port, so the browser can reach the HMR socket. Laravel injects the
dev server through `public/hot`; open the app at <http://localhost:8001> as usual. Without
`public/hot`, Laravel falls back to the compiled assets in `public/build`, which is why the app
still loads when the dev server is stopped.

Production build (writes `public/build` and the fonts manifest):

```bash
sail npm run build
sail npm run build:ssr      # only if you enable SSR
```

Front-end checks:

```bash
sail npm run check          # vp check (oxlint + format)
sail npm run check:fix      # autofix
sail npm run types:check    # vue-tsc --noEmit
```

Or start the dev server, queue listener and log tail together in one terminal:

```bash
sail artisan dev            # equivalent: sail composer dev
```

`artisan dev` also runs `php artisan serve` on container port 8000, which is not published — keep
using <http://localhost:8001> (Sail's nginx). It does not include the scheduler, so run
`sail artisan schedule:work` separately when you need polling.

## Scope

This branch contains the five required items plus both stretch goals: credit balances with a
simulated purchase flow and affordability gating, and an operator report reconciled against
Pixelforge's own usage endpoint.
