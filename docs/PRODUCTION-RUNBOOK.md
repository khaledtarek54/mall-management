# Atriom — Production Deployment Runbook

The end-to-end path to running Atriom in production with **real money + real tax**.
The application code is ready; what remains is **config + ops**. Work top to bottom.

> Companion docs: [INFRASTRUCTURE.md](INFRASTRUCTURE.md) (**server topology + provisioning** — the box, Cloudflare Tunnel, managed MySQL, backups; overrides some env defaults below),
> [ETA-PAYMOB-CERTIFICATION.md](ETA-PAYMOB-CERTIFICATION.md) (integration cutover),
> [PAYMENT-LINK-APPLEPAY.md](PAYMENT-LINK-APPLEPAY.md), [gap-analysis/999-production-checklist.md](gap-analysis/999-production-checklist.md), [ROADMAP.md](ROADMAP.md).

---

## 1. Environment (`.env` on the server)

```
APP_ENV=production
APP_DEBUG=false                 # never true in prod (leaks stack traces)
APP_KEY=base64:...              # php artisan key:generate (once)
APP_URL=https://app.eltizam...  # the real HTTPS domain
APP_TIMEZONE=Africa/Cairo       # schedules fire on local wall-clock

LOG_CHANNEL=daily               # not 'stack/single'
LOG_LEVEL=warning               # 'debug' leaks SQL/PII + fills disk
OPS_LOG_STACK=ops_daily,slack   # money/integration events → file + Slack alert
OPS_LOG_LEVEL=info

DB_CONNECTION=mysql             # + DB_HOST/PORT/DATABASE/USERNAME/PASSWORD
SESSION_DRIVER=database          # → redis on the managed-DB topology (INFRASTRUCTURE.md §5)
SESSION_ENCRYPT=true            # encrypt session payloads at rest
QUEUE_CONNECTION=database        # → redis on the managed-DB topology (INFRASTRUCTURE.md §5)
EXPORT_QUEUE_CONNECTION=database # large exports queue instead of timing out

DEMO_USER_PASSWORD=<rotated>    # rotate or delete demo accounts before exposing the URL
SANCTUM_TOKEN_EXPIRATION=43200  # 30 days (mobile)
SECURITY_FORCE_2FA_ROLES="super_admin,manager,accounting,leasing,operations,hr"

MAIL_MAILER=mailersend          # + MAILERSEND_API_KEY + MAIL_FROM_ADDRESS on the verified domain
MAIL_ALWAYS_TO=                 # MUST stay empty in prod (ignored there anyway) — see below
```

**Outbound email** runs on MailerSend's HTTPS API (`mailersend/laravel-driver`) — no SMTP egress rules, no long-lived socket, and the API token is scoped to sending only. Setup: verify the sending domain in the MailerSend dashboard (Domains → add → publish the SPF/DKIM/Return-Path DNS records), issue a token with Email send permission, then set `MAILERSEND_API_KEY` and a `MAIL_FROM_ADDRESS` **on that verified domain** — any other domain 422s with `#MS42207`. Verify with `php artisan integrations:check --mail` (reads the sending quota, sends nothing) then `php artisan mail:test you@example.com` (sends one real message, inline, not queued). Trial plans also cap *unique recipients* (`#MS42225`) until the account is approved in the dashboard. `MAIL_ALWAYS_TO` redirects **every** outgoing email to one inbox so the fake `@*.test` demo addresses don't hard-bounce off the live provider — it is ignored when `APP_ENV=production` so it can never silently swallow tenant mail. To keep mail entirely on the box, set `MAIL_MAILER=log`.

Integration creds (see ETA-PAYMOB-CERTIFICATION.md): `PAYMOB_*` (live, after KYC), `ETA_*` (live + `ETA_MOCK=false` + signing cert), Apple Pay (`PAYMOB_APPLE_PAY_INTEGRATION_ID` + domain file).

**Mobile push** (Firebase Cloud Messaging — free, unlimited): create a free Firebase project, download the service-account JSON (Project settings → Service accounts → Generate new key), then set `PUSH_ENABLED=true`, `FCM_CREDENTIALS=/abs/path/to/service-account.json` (optionally `FCM_PROJECT_ID`). Off by default → `NullPushSender` (the in-app inbox + email still deliver). No cost beyond the Apple Developer Program ($99/yr) needed to ship the iOS app at all.

---

## 2. Deploy steps (each release)

```
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan storage:link            # once — PDFs/media links
php artisan queue:restart           # workers pick up new code
```

First deploy only: `php artisan key:generate`, `php artisan migrate --force --seed` is **NOT** for prod (DemoSeeder is demo data) — seed only `RolesPermissionsSeeder` + real data import.

---

## 3. Queue worker (REQUIRED — money flows depend on it)

Without a running worker, billing/late-fee/ETA jobs + exports silently never run. Use supervisor:

```ini
[program:atriom-worker]
command=php /var/www/atriom/artisan queue:work --tries=3 --max-time=3600 --sleep=3
directory=/var/www/atriom
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/atriom-worker.log
stopwaitsecs=3600
```
`supervisorctl reread && supervisorctl update && supervisorctl start atriom-worker:*`

---

## 4. Scheduler (REQUIRED — drives all the cadences)

One cron entry runs every scheduled job (monthly billing, daily late-fees + overdue/SLA scans, CAM, **marketing:ensure-budgets**, activity-log clean, vendor expiry):

```cron
* * * * * cd /var/www/atriom && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. Backups (REQUIRED — money/tax/contract data)

**Now runs in-app, from the scheduler** (§4) — no separate cron, no second set of DB
credentials:

| When | Command |
| --- | --- |
| 01:00 | `backup:clean` — retention first, so space is freed before the new archive is written |
| 01:15 | `backup:run` — database + `storage/app` (signed leases, tax cards, vendor documents, branding) |
| 07:30 | `backup:monitor` — fails if the newest archive is older than a day |

Archives land on the `backups` disk (`storage/backups`), which is git-ignored and outside the
backup source.

**Set before go-live:**
```dotenv
BACKUP_DISKS="backups,s3"        # a copy on the same box as the DB dies with the box
BACKUP_ARCHIVE_PASSWORD=...      # the archive holds a full dump: tax cards, leases, money
BACKUP_ALERT_EMAIL=ops@...       # unset ⇒ no mail is sent at all
```

**Still yours to do: test a restore, monthly, into a scratch DB.** Nothing in the app can
assert that an archive is restorable — only that one exists and is recent.

```bash
# restore drill
unzip -p storage/backups/Atriom/<newest>.zip db-dumps/mysql-atriom.sql | mysql -u<user> -p atriom_scratch
```

---

## 6. Observability & alerting

- **Error tracking**: `composer require sentry/sentry-laravel` → set `SENTRY_LARAVEL_DSN`. (No APM is wired today.)
- **Ops events**: already routed via `App\Support\OpsLog` to the `ops` channel — point `OPS_LOG_STACK` at Slack (§1) so Paymob/ETA/billing failures page you.
- **Failed jobs**: monitor the `failed_jobs` table (alert if > 0); `php artisan queue:retry all` to replay.
- **Uptime**: external monitor (UptimeRobot/Pingdom) on **`/health`**, not `/up`. `/up` is
  Laravel's stock route and answers 200 with the database down, the queue stalled and the
  scheduler dead. `/health` checks database, cache, queue depth, **scheduler heartbeat**,
  **backup freshness** and storage, and answers 503 when any of them is wrong.
  - Anonymous callers get `{"status":"ok"|"degraded"}` and nothing else; set `HEALTH_TOKEN`
    and pass `?token=` (or `X-Health-Token`) to see which check failed.
  - **Why it has to be external:** every scheduled monitor — `backup:monitor` included — can
    only report a problem while the scheduler is running, so none of them can report that the
    scheduler has *stopped*. A dead cron silences the billing run, the GL sync, the nightly
    backup and every alarm at once, and looks exactly like a quiet night. Something off-box
    has to ask.
  - Same checks from the CLI, non-zero on failure: `php artisan atriom:health`.

---

## 7. Pre-flight gates (run before the first real charge / tax filing)

```
php artisan integrations:check          # Paymob auth + ETA OAuth reachable with live creds
php artisan marketing:ensure-budgets    # provision current-year budgets
php artisan billing:reconcile           # books tie out (AR + marketing + CAM) — exits non-zero on drift
vendor/bin/pest --parallel              # full suite green
```
Then: Paymob KYC done + live keys + prod callback URLs registered; ETA signing cert installed + `ETA_MOCK=false` tested against preprod; `php artisan mail:test` lands in a real inbox from the verified domain; accountant has signed off [BUSINESS-RULES.md](BUSINESS-RULES.md).

---

## 8. Rollback

- Code: redeploy the previous tag + `php artisan queue:restart`.
- DB: migrations are forward-only — restore from the latest backup (§5) if a migration must be undone.
- Integrations: flip `ETA_MOCK=true` / `PAYMOB_ENABLED=false` to safely disable a misbehaving gateway without a deploy.
