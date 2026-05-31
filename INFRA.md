# INFRA — Production Runbook

> One-page deploy / ops reference for Atriom.
> Pair with [docs/gap-analysis/999-production-checklist.md](docs/gap-analysis/999-production-checklist.md) at go-live.

## Architecture

- **App**: Laravel 13 + Filament 4 + Livewire 3, PHP 8.4.
- **Storage**: MySQL primary DB; `storage/app/public` for uploaded media; per-property logo + favicon via Spatie MediaLibrary.
- **3 panels**: `/admin` (operators) · `/owner` (landlords, read-only) · `/portal` (tenants).
- **Mobile API**: `/api/v1` Sanctum tokens (currently auth-only; see [docs/gap-analysis/19-mobile-api.md](docs/gap-analysis/19-mobile-api.md) for the designed-but-not-built shortlist).

## Background processing (REQUIRED)

Three Laravel processes must be live at all times for the app to function correctly:

1. **HTTP** — PHP-FPM behind nginx (or Herd on dev). Handles every browser + API request.
2. **Queue worker** — processes monthly billing, late-fee runs, ETA submissions, and any future notification jobs.
3. **Scheduler** — fires the cron entries defined in [routes/console.php](routes/console.php).

### Queue worker

Run **at least one** worker as a long-lived process. Recommended setup under **systemd**:

```ini
# /etc/systemd/system/atriom-queue.service
[Unit]
Description=Atriom queue worker
After=mysql.service

[Service]
User=deploy
Group=deploy
Restart=always
RestartSec=5
WorkingDirectory=/var/www/atriom
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600 --sleep=3

[Install]
WantedBy=multi-user.target
```

Enable:
```sh
sudo systemctl enable --now atriom-queue
sudo systemctl status atriom-queue
```

After a deploy that touches queued job classes, restart with `sudo systemctl restart atriom-queue` so the worker picks up new code (Laravel jobs are serialized).

### Scheduler

A single cron entry runs the Laravel scheduler every minute. Laravel decides what fires based on `routes/console.php`:

```cron
* * * * * cd /var/www/atriom && php artisan schedule:run >> /dev/null 2>&1
```

Currently scheduled (`php artisan schedule:list`):

| Cron | Job | Purpose |
|---|---|---|
| `0 2 1 * *` | `atriom-monthly-billing` | Monthly invoice generation for active leases. Idempotent per (lease, period). |
| `0 4 * * *` | `atriom-late-fees` | Daily late-fee accrual after grace period. Idempotent per invoice (skips already-fee'd rows). |
| `0 3 15 1 *` | `atriom-cam-reconcile` | Annual CAM reconciliation (review-only by default). Add `--auto-bill` to the schedule if you want fully-automated CAM billing. |
| `30 2 * * *` | `atriom-expire-vendor-contracts` | Daily vendor contract status update (active → expired past end_date). |
| `0 5 1 * *` | `atriom-clean-activity-log` | Drop activity-log rows older than `config('activitylog.clean_after_days', 365)`. |

All entries use `withoutOverlapping()` so a long-running prior run can't double-fire.

## First-deploy checklist (one-time)

```sh
# As the deploy user on the target host:
cd /var/www/atriom

# 1. Code + dependencies
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 2. App key + cache
php artisan key:generate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Database
php artisan migrate --force
# (production: NO --seed; seeders are demo data)

# 4. Storage symlink — REQUIRED, otherwise uploaded files return 404.
php artisan storage:link

# 5. Permissions (filesystem)
chown -R deploy:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# 6. Schedule + queue (above)
sudo systemctl enable --now atriom-queue
# Add the cron entry to deploy user's crontab.
```

## Environment

Production `.env` must set:

| Group | Vars |
|---|---|
| App | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `APP_KEY` (generated) |
| Timezone | `APP_TIMEZONE=Africa/Cairo` (the schedule above is local-time-based) |
| Database | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Cache + queue + session | All `database` driver by default; switch to `redis` if redis is provisioned |
| Mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Seed password | `DEMO_USER_PASSWORD` — rotate from `'password'` BEFORE the URL is shared (audit M17 F-63) |
| ETA | `ETA_MOCK=false`, `ETA_ENDPOINT`, `ETA_AUTH_ENDPOINT`, `ETA_CLIENT_ID`, `ETA_CLIENT_SECRET` (cf. [08-eta.md §5](docs/gap-analysis/08-eta.md)) |
| Integrations | `INTEGRATIONS_PAYMOB_ENABLED`, `INTEGRATIONS_WHATSAPP_ENABLED` — both `false` until creds land |
| Sanctum | `SANCTUM_STATEFUL_DOMAINS` includes the production frontend domain(s) |

## Day-to-day operations

### Monitoring

- **Logs**: `storage/logs/laravel.log` (ship to a log aggregator).
- **Failed jobs**: `php artisan queue:failed`. Re-run with `php artisan queue:retry all`.
- **Schedule health**: `php artisan schedule:list` shows next-run; logging via `Log::channel(...)` inside specific commands if needed.
- **Activity log**: `/admin/activity-log` page — 6-state filter (status / log_name / event / period preset / from / until).

### Incident response — invoice / payment math

The booted-hooks on Payment + the LeaseObserver keep AR consistent automatically. If a discrepancy shows up:

1. Run `php artisan tinker` and call `$invoice->recomputeTotals()` on the affected invoice.
2. For credit notes: `app(\App\Services\CreditNoteService::class)->issue($note)` / `applyToInvoice($note, $invoice)` / `void($note)` are idempotent and DB-transactional.
3. ETA submissions: re-run via the per-invoice "Submit to ETA" action in admin UI; the service is no-op on already-`valid` invoices.

### ETA cutover (one-time when creds arrive)

1. Set the 4 ETA env vars + flip `ETA_MOCK=false`.
2. `php artisan config:clear`.
3. In `/admin/{tenant}/settings → ETA tab`: toggle Mock OFF; verify Issuer Name + TRN.
4. Submit a single B2B test invoice. Expect `eta_status='valid'` + `eta_submission_id` + PDF showing the ETA reference block.
5. If you need to revert: flip `ETA_MOCK=true` + `php artisan config:clear`. In-flight jobs go back to mock; already-`valid` invoices stay valid.

### Property branding refresh

1. `/admin/ALL/properties → Edit Haya Walk`
2. Upload a new logo / favicon via Spatie MediaLibrary fields.
3. Change `primary_color` to the operator's hex.
4. Save. Filament resolves these per-tenant on every page render; no cache clear needed.

## Audit cross-references

- All 60 deferred decisions live in [docs/gap-analysis/998-deferred-backlog.md](docs/gap-analysis/998-deferred-backlog.md).
- Pre-launch sign-off checklist: [docs/gap-analysis/999-production-checklist.md](docs/gap-analysis/999-production-checklist.md).
- Module-by-module audit docs under [docs/gap-analysis/](docs/gap-analysis/).

## When something seems off

1. `php artisan schedule:list` — confirm all 5 entries above are present.
2. `sudo systemctl status atriom-queue` — confirm worker is running, no failures.
3. `php artisan queue:failed` — empty?
4. `tail -100 storage/logs/laravel.log` — last hour of errors.
5. `php artisan about` — confirm env + cache state.
6. `php artisan test --parallel` from the deploy commit — sanity check.
