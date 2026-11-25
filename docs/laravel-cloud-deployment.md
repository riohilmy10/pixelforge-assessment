# Deploying Pixelforge to Laravel Cloud

> Status: code preparation is complete in this branch. The Laravel Cloud deployment steps below have not been executed yet.

## Contents

1. [Current repo state](#1-current-repo-state)
2. [Code changes prepared in this branch](#2-code-changes-prepared-in-this-branch)
3. [Install the Laravel Cloud CLI (optional)](#3-install-the-laravel-cloud-cli-optional)
4. [Create the Laravel Cloud application](#4-create-the-laravel-cloud-application)
5. [Build and deploy commands](#5-build-and-deploy-commands)
6. [Add resources](#6-add-resources)
7. [Environment variables](#7-environment-variables)
8. [First deploy](#8-first-deploy)
9. [Post-deploy Pixelforge setup](#9-post-deploy-pixelforge-setup)
10. [Create the admin/demo account](#10-create-the-admindemo-account)
11. [Smoke test checklist](#11-smoke-test-checklist)
12. [Gotchas specific to this app](#12-gotchas-specific-to-this-app)

---

## 1. Current repo state

- Branch: `feature/laravel-cloud`
- The preparation changes are included in this branch's pull request.
- It has no upstream branch yet, so Laravel Cloud will not see this branch until it is pushed.
- Laravel Cloud will normally deploy `main`.
- Merge the pull request into `main` before creating the Cloud app.

```bash
git status
git push -u origin feature/laravel-cloud   # only if you keep working on this branch
```

Then merge to `main`, or configure the Cloud environment to deploy `feature/laravel-cloud`.

---

## 2. Code changes prepared in this branch

### 2.1 S3/Flysystem driver and AWS SDK (done)

Generated images are copied from Pixelforge's expiring URL to `FILESYSTEM_IMAGE_DISK`.
Without the S3 driver, image storage jobs fail and images disappear when Pixelforge's URL
expires. The branch now requires:

- `league/flysystem-aws-s3-v3` for Laravel's S3 disk.
- `aws/aws-sdk-php` for Laravel Cloud managed queues.

Both are already in `composer.json` and `composer.lock`. No further Composer command is needed
for this branch.

### 2.2 Email verification is disabled (done)

Email verification is not part of the assessment requirements, so the flow was removed:

- `config/fortify.php` no longer enables `Features::emailVerification()`.
- `User` no longer implements `MustVerifyEmail`.
- The `verified` middleware was removed from `routes/web.php` and `routes/settings.php`.
- The verification screen, profile verification notice, verification page, and verification
  tests were removed.
- Registration now sends a new user straight to the dashboard; no email provider is required
  to use the app.

`Features::resetPasswords()` remains enabled, so password reset still uses mail. Configure a
mail provider only if you want password reset to work. It is not required for the assessment.

Production also enables this password rule in `AppServiceProvider`:

```text
12+ characters, mixed case, letters, numbers, symbols, and not found in Have I Been Pwned.
```

So any account password must be strong, or the registration form will reject it.

### 2.3 Validation and commit

The following passed locally against Postgres 18:

```bash
DB_HOST=127.0.0.1 DB_PORT=5434 php artisan test   # 133 tests
composer lint:check                               # Pint
vendor/bin/phpstan analyse --memory-limit=1G     # PHPStan
npm run build
npm run types:check
npm run check
NODE_ENV=test npm test                            # 18 tests
```

`NODE_ENV=test` matters locally; the current shell has `NODE_ENV=production`, which makes the
Vue tests use production builds and fail.

Commit and push, if you are recreating these changes from an older checkout:

```bash
git add app composer.json composer.lock config database resources/js routes tests
git commit -m "chore: prepare Laravel Cloud deployment"
git push
```

The deployment runbook itself is in `/docs`, which this repository gitignores. To include it in
the commit, add it explicitly:

```bash
git add -f docs/laravel-cloud-deployment.md
```

Merge that commit into `main` before the Cloud deployment. The GitHub Actions workflow runs
PHP 8.5, Node 22, Postgres 18, Pint, PHPStan, Vue checks, and PHPUnit.

---

## 3. Install the Laravel Cloud CLI (optional)

The CLI is optional. You can do the entire first setup in the Laravel Cloud dashboard. Install
it if you want terminal deploys, logs, and Artisan commands.

```bash
composer global require laravel/cloud-cli
export PATH="$PATH:$(composer global config bin-dir --absolute)"
cloud auth
```

Add the `export PATH=...` line to `~/.zshrc` if you want it permanently.

CLI requirements are PHP 8.3+, Composer, and Git. The machine has PHP 8.5, so this works
without Docker.

Useful commands after setup:

```bash
cloud ship             # guided first-time app creation + deploy
cloud deploy           # deploy the current app
cloud deploy:monitor   # watch a deployment
cloud deployment:logs  # inspect a failed build/deploy
cloud environment:logs
cloud command:run --cmd="php artisan ..."
cloud tinker --code="..."
```

Save the app/environment defaults for this repo:

```bash
cloud repo:config
```

---

## 4. Create the Laravel Cloud application

### Recommended dashboard path

1. Sign in at `cloud.laravel.com`.
2. Create/select an organization. The Starter plan is $5/month with $5 usage credit and the
   first month free.
3. Select **New application**.
4. Connect GitHub and grant the Laravel Cloud GitHub app access to
   `riohilmy10/pixelforge-assessment`.
5. Select the repository, name the app `pixelforge`, and choose the region closest to you.
6. Create the application and its default environment.
7. Set the environment branch to `main`.
8. Set the runtime to **PHP 8.5** (or 8.4; the project requires PHP `^8.4.1`).

### Onboarding: "Add resources (optional)"

This is the final onboarding step shown before the first deploy. It only offers Database,
Cache, and WebSockets; object storage and the managed queue are added after the application
exists.

Choose:

- **Database: checked.** Open the dropdown and change it from **Laravel MySQL** to **Laravel
  Serverless Postgres**, version 18. This app uses `DB_CONNECTION=pgsql`, PostgreSQL-specific
  migrations, and CI runs against Postgres 18.
- **Cache: unchecked.** The app uses `CACHE_STORE=database` and `SESSION_DRIVER=database`.
  Valkey is not needed.
- **WebSockets: unchecked.** The app uses `BROADCAST_CONNECTION=log`; Reverb is not used.

The app's object storage bucket and managed queue are added on the environment's
infrastructure canvas after the first deploy. The first deploy is allowed to succeed without
them; add both and redeploy before testing image generation or queued jobs.

### CLI path

```bash
cloud ship --name=pixelforge --region=us-east-2 --database=postgres18 --queue=managed
```

Use the interactive prompts instead if you want to enable the scheduler and scale-to-zero.
Important choices:

- Enable **Laravel Scheduler**.
- Enable **Scale to Zero** (managed queue wakes independently; scheduler wakes the app).
- Choose **Managed Queue**, not a background worker, unless you plan to keep the app awake.
- Do **not** enable **Inertia SSR**. This repo has `config/inertia.php` SSR enabled but no
  `resources/js/ssr.ts`/SSR bundle; Cloud's SSR process is not needed and `npm run build` is
  the correct build.
- If asked to push local `.env` variables, do not push all of them. See the warning below.

`cloud ship` creates the app, database, and queue, but not the bucket, email integration, or
every environment variable. Add those afterward and redeploy.

---

## 5. Build and deploy commands

In the environment's deployment settings:

**Build command**

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci && npm run build && php artisan optimize
```

Notes:

- `npm run build` runs `vp build`. Do not use `npm run build:ssr`.
- `php artisan optimize` is safe here; route/config/view caching was verified locally.
- Cloud's PHP image includes PHP, Composer, Node, and npm.

**Deploy command**

```bash
php artisan migrate --force
```

Do **not** add:

```text
php artisan storage:link
php artisan optimize:clear
php artisan queue:restart
```

Cloud handles queue restarts and deployment filesystems, and `storage:link` will not persist.

---

## 6. Add resources

### 6.1 Database

Add a **Laravel Serverless Postgres** database, version 18, in the same region as the app, and
attach it to the environment.

Cloud injects `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and related
variables. Do not set those manually.

The app uses:

- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- database cache locks for `onOneServer` and `ShouldBeUnique`

Those tables are created by the existing migrations. No Valkey/Redis is required.

### 6.2 Object storage bucket

Add an **Object Storage** bucket:

- Visibility: **Public**
- Disk name: `images` (or `s3`; anything you like, but remember it)
- Attach it to the environment
- Prefer the same region/location as the app

Make it public because `Generation::imageUrl()` calls `Storage::url()`, not `temporaryUrl()`.
A private bucket would produce unsigned URLs that the browser cannot load.

Laravel Cloud injects a Cloud-managed disk configuration (via `LARAVEL_CLOUD_DISK_CONFIG`), so
you do not need to edit `config/filesystems.php` for Cloud. The repo's `s3` block is not what
Cloud uses; the chosen bucket disk name is.

Set:

```dotenv
FILESYSTEM_IMAGE_DISK=images
```

If you choose a different disk name, use that value instead.

### 6.3 Managed queue

Add a **Managed Queue**:

- Name: `default`
- Memory: 256 MB is enough for this app; 512 MB is safer
- Max workers: 1-3 on Starter
- Queue: standard, not FIFO

Laravel Cloud sets `QUEUE_CONNECTION=cloud` automatically. Do not override it with `database`
if you want the managed queue.

This requires the `aws/aws-sdk-php` package from step 2.1. The app's jobs (`SubmitGeneration`,
`ProcessWebhookEvent`, `StoreGenerationImage`) will then process on Cloud's managed workers.

If you instead use a database queue worker:

- Set `QUEUE_CONNECTION=database`.
- Run a worker with `php artisan queue:work`.
- Keep the app awake or use a worker cluster. Queued jobs do not wake a scaled-to-zero app.

### 6.4 Scheduler

On the App cluster, enable the **Scheduler** toggle.

This is not optional for this app. `routes/console.php` schedules:

- `pixelforge:poll` every minute
- `pixelforge:retry-unprocessed-webhooks` every five minutes
- `pixelforge:recover-generation-images` every five minutes
- `pixelforge:reconcile-ambiguous-submissions` every five minutes
- `pixelforge:release-stale-holds` hourly
- `pixelforge:prune-webhook-events` daily

Without the scheduler, webhook recovery, polling, and stale credit-hold release will not run.

### 6.5 Email (optional)

Email verification is disabled, so you do **not** need to attach email for registration or
dashboard access. Email is only needed if you want password reset to work.

If you want Resend:

- Add `resend/resend-php` to Composer.
- Attach **Emails** to the environment.
- Choose/create a verified sending domain.
- Set sender address/name.
- Cloud injects `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, and
  `MAIL_FROM_NAME`.
- Redeploy.

If using another SMTP provider, set `MAIL_MAILER=smtp` and the provider's host, port,
username, password, and scheme.

Otherwise, leave mail unconfigured. Registration and generation testing still work.

---

## 7. Environment variables

Set these in the Cloud environment. Cloud writes them into a `.env` file; you do not commit
`.env`.

```dotenv
APP_NAME=Pixelforge
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://<your-assigned-domain>.laravel.cloud
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
BCRYPT_ROUNDS=12

LOG_CHANNEL=stderr
LOG_LEVEL=debug

DB_CONNECTION=pgsql

SESSION_DRIVER=database
CACHE_STORE=database

FILESYSTEM_DISK=local
FILESYSTEM_IMAGE_DISK=images

BROADCAST_CONNECTION=log

# Optional: only needed if password reset should send email.
# MAIL_MAILER=resend
# MAIL_FROM_ADDRESS=hello@yourdomain.com
# MAIL_FROM_NAME="${APP_NAME}"

PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
PIXELFORGE_MARKUP_PERCENT=25
```

Do **not** set:

```text
DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
QUEUE_CONNECTION            # managed queue sets this to cloud
APP_PORT, VITE_PORT, FORWARD_DB_PORT, FORWARD_MAILPIT_PORT
WWWUSER, WWWGROUP
MAIL_HOST=mailpit, MAIL_PORT=1025
APP_URL=http://localhost:8001
```

Those are local Sail values.

Generate a fresh production key:

```bash
php artisan key:generate --show
```

Run that from the main repo where `vendor/` exists. If the worktree has no vendor, use:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

`APP_URL` is normally injected by Cloud. Verify it after the first deployment; if it is wrong,
set it explicitly and redeploy. Password-reset links and passkey configuration depend on it.

Mail is optional. If using Resend, leave `MAIL_MAILER`/`RESEND_API_KEY` to Cloud. If using
SMTP, set `MAIL_MAILER=smtp` and the SMTP variables.

> **Warning about `cloud deploy --push-env`:** the local `.env` contains local DB, Mailpit,
> Sail ports, `APP_DEBUG=true`, and `APP_URL=http://localhost:8001`. Do not push all of it.
> If you use the CLI, use `--push-env-keys` for only specific keys, and prefer setting
> production values in the dashboard or via `cloud environment:variables` instead.

---

## 8. First deploy

Dashboard: click **Deploy**.

CLI:

```bash
cloud deploy
```

or:

```bash
cloud deploy:monitor
```

First deployments typically take 5-15 minutes; later deployments are usually 1-2 minutes.

Cloud assigns a `*.laravel.cloud` URL after a successful deploy. If you want a cleaner URL:

```bash
cloud environment:vanity-domain --name=pixelforge --deploy
```

Environment-variable changes require a new deployment; they are not applied by a restart alone.

---

## 9. Post-deploy Pixelforge setup

1. Create a **production** API key in the Pixelforge dashboard. Do not reuse the local/tunnel
   key.
2. Set that key's webhook URL to:

   ```text
   https://<your-cloud-domain>/webhooks/pixelforge
   ```

3. Copy the key and its webhook signing secret into the Cloud environment:

   ```dotenv
   PIXELFORGE_API_KEY=...
   PIXELFORGE_WEBHOOK_SECRET=...
   ```

4. Redeploy.
5. Make sure the Pixelforge key has enough credits/quota to generate.

The webhook route is outside the `web` middleware group, so it is CSRF/session-free; the HMAC
signature is the authentication. If the secret is missing or wrong, deliveries will be
rejected.

---

## 10. Create the admin/demo account

Register through the deployed site. Registration now lands directly on the dashboard; there
is no email verification step.

Promote the account:

```bash
cloud command:run --cmd="php artisan pixelforge:make-admin you@example.com"
```

After that, the **Operator report** link appears in the sidebar. Reviewers can create their
own accounts or use a shared demo account.

If you later fix the image bucket and need to backfill failed image copies:

```bash
cloud command:run --cmd="php artisan pixelforge:recover-generation-images"
```

---

## 11. Smoke test checklist

- [ ] `https://<domain>/up` returns 200.
- [ ] Register/login works, or the demo account works.
- [ ] Registration lands on the dashboard without an email-verification step.
- [ ] Submit a generation; status moves through `queued`/`processing`/`succeeded`.
- [ ] The generated image loads from the bucket URL, not a local `/storage/...` URL.
- [ ] Credits are held and then charged; failed generations cost nothing.
- [ ] A second generation can run concurrently without overspending credits.
- [ ] Cloud logs show `pixelforge.webhook_ping` and `pixelforge.webhook_accepted` after a
      generation.
- [ ] The operator report loads and, if Pixelforge is reachable, reconciles against
      `/v1/usage`.
- [ ] `cloud deployment:logs` shows migrations completed successfully.

---

## 12. Gotchas specific to this app

- **Public bucket required:** `Generation::imageUrl()` uses `Storage::url()`. A private bucket
  returns unusable URLs.
- **`FILESYSTEM_IMAGE_DISK` must match the bucket disk name** (`images` in this runbook).
- **Managed queue requires `aws/aws-sdk-php`** in the deployed commit. Adding it only locally
  is not enough.
- **Scheduler is required** for the webhook/poll/recovery commands.
- **Inertia SSR toggle should stay off.** The config has SSR enabled but there is no SSR
  entrypoint/bundle; `npm run build` is correct.
- **Email verification is intentionally disabled.** Registration lands on the dashboard without
  mail. Password reset still needs a configured mailer if you want it.
- **Production password policy** requires 12+ characters with mixed case, numbers, symbols,
  and HIBP validation. Share a demo account if you do not want reviewers to fight it.
- **Do not use `storage:link` or `optimize:clear` in deploy commands.**
- **No Dockerfile or Procfile is needed.**
- **Starter plan includes one managed queue**, which is sufficient here; Postgres and Flex
  compute can scale to zero.
- **Managed queues cap per-job delays at 15 minutes.** This app's final `SubmitGeneration`
  fallback is 30 minutes; it only matters if Pixelforge keeps returning 429/503 into the fifth
  attempt. A worker cluster with the database queue avoids that cap, but is not necessary for
  a normal assessment run.
- **`composer ci:check` needs a running Postgres**; use Sail if you run it locally, or rely
  on the GitHub Actions workflow.

---

## Minimum assessment path

1. Commit and push the prepared code changes to `main`.
2. Create the app on Laravel Cloud.
3. Attach Postgres, a public bucket, and a managed queue.
4. Enable the scheduler.
5. Set the environment variables.
6. Deploy.
7. Point Pixelforge's production webhook at the Cloud URL.
8. Create a demo/admin account.
9. Run the smoke test checklist.
