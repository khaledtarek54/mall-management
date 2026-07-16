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

MAIL_MAILER=smtp                # + MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION + MAIL_FROM_* ; set SPF/DKIM
```

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

Daily MySQL dump, ≥7-day retention, **tested restore**. Example (cron):
```cron
0 1 * * * mysqldump -u<user> -p<pass> atriom | gzip > /backups/atriom-$(date +\%F).sql.gz && find /backups -name 'atriom-*.sql.gz' -mtime +14 -delete
```
Plus storage/ (uploaded media/PDFs). Verify a restore into a scratch DB monthly.

---

## 6. Observability & alerting

- **Error tracking**: `composer require sentry/sentry-laravel` → set `SENTRY_LARAVEL_DSN`. (No APM is wired today.)
- **Ops events**: already routed via `App\Support\OpsLog` to the `ops` channel — point `OPS_LOG_STACK` at Slack (§1) so Paymob/ETA/billing failures page you.
- **Failed jobs**: monitor the `failed_jobs` table (alert if > 0); `php artisan queue:retry all` to replay.
- **Uptime**: external monitor (UptimeRobot/Pingdom) on `/up`.

---

## 7. Pre-flight gates (run before the first real charge / tax filing)

```
php artisan integrations:check          # Paymob auth + ETA OAuth reachable with live creds
php artisan marketing:ensure-budgets    # provision current-year budgets
php artisan billing:reconcile           # books tie out (AR + marketing + CAM) — exits non-zero on drift
vendor/bin/pest --parallel              # full suite green
```
Then: Paymob KYC done + live keys + prod callback URLs registered; ETA signing cert installed + `ETA_MOCK=false` tested against preprod; SMTP sends a real test email; accountant has signed off [BUSINESS-RULES.md](BUSINESS-RULES.md).

---

## 8. Rollback

- Code: redeploy the previous tag + `php artisan queue:restart`.
- DB: migrations are forward-only — restore from the latest backup (§5) if a migration must be undone.
- Integrations: flip `ETA_MOCK=true` / `PAYMOB_ENABLED=false` to safely disable a misbehaving gateway without a deploy.
