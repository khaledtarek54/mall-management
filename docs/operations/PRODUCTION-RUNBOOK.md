# Atriom — Production Deployment Runbook

The end-to-end path to running Atriom in production with **real money + real tax**.
The application code is ready; what remains is **config + ops**. Work top to bottom.

> Companion docs: [STAGING.md](STAGING.md) (**the staging box** — what it is for, its `.env` deltas, and which health rows are expected red there),
> [INFRASTRUCTURE.md](INFRASTRUCTURE.md) (**server topology + provisioning** — the box, Cloudflare Tunnel, managed MySQL, backups; overrides some env defaults below),
> [ETA-PAYMOB-CERTIFICATION.md](../integrations/ETA-PAYMOB-CERTIFICATION.md) (integration cutover),
> [PAYMENT-LINK-APPLEPAY.md](../integrations/PAYMENT-LINK-APPLEPAY.md), [../STATUS.md](../STATUS.md), [ROADMAP.md](../ROADMAP.md).

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

**Outbound email** runs on MailerSend's HTTPS API (`mailersend/laravel-driver`) — no SMTP egress rules, no long-lived socket, and the API token is scoped to sending only. Setup: verify the sending domain in the MailerSend dashboard (Domains → add → publish the SPF/DKIM/Return-Path DNS records), issue a token with Email send permission, then set `MAILERSEND_API_KEY` and a `MAIL_FROM_ADDRESS` **on that verified domain** — any other domain 422s with `#MS42207`. Verify with `php artisan integrations:check --mail` (sends nothing) then `php artisan mail:test you@example.com` (sends one real message, inline, not queued). **The check reads the quota AND proves the token may send** — those are two different permissions, and until 2026-08-17 it asked only the first: `/api-quota` answers 200 for any valid token whatever its scopes, so a token issued without Email permission passed the check while every notification in the app died with `403 · This action is unauthorized`. It found nothing for weeks; a payment receipt failing on a box this command called healthy is what exposed it. The send probe posts an empty payload and reads which refusal comes back — 422 (validation) means the token may send, 403 (`#MS40301`) means it may not — so nothing is delivered either way. A `403` here is fixed in the dashboard, not in `.env`: give the token *Email: Full access* under Integrations > API tokens. Trial plans also cap *unique recipients* (`#MS42225`) until the account is approved in the dashboard. `MAIL_ALWAYS_TO` redirects **every** outgoing email to one inbox so the fake `@*.test` demo addresses don't hard-bounce off the live provider — it is ignored when `APP_ENV=production` so it can never silently swallow tenant mail. To keep mail entirely on the box, set `MAIL_MAILER=log`.

Integration creds (see ETA-PAYMOB-CERTIFICATION.md): `PAYMOB_*` (live, after KYC), `ETA_*` (live + `ETA_MOCK=false` + signing cert), Apple Pay (`PAYMOB_APPLE_PAY_INTEGRATION_ID` + domain file).

**Mobile push** (Firebase Cloud Messaging — free, unlimited): create a free Firebase project, download the service-account JSON (Project settings → Service accounts → Generate new key), then set `PUSH_ENABLED=true`, `FCM_CREDENTIALS=/abs/path/to/service-account.json` (optionally `FCM_PROJECT_ID`). Off by default → `NullPushSender` (the in-app inbox + email still deliver). No cost beyond the Apple Developer Program ($99/yr) needed to ship the iOS app at all.

---

## 2. Deploy steps (each release)

> **Use `./deploy.sh`.** It runs exactly the sequence below and refuses rather than continues — a
> dirty working tree, a missing `npm`, an asset build that produced nothing, `--skip-migrate` while
> migrations are pending. It prompts on production (`--yes` for a CI caller) and lifts maintenance
> mode even if a step fails, so a broken deploy cannot leave the site dark with no explanation.
> The list below is what it does, and remains the manual fallback.
>
> **Corrected 2026-08-23:** until then the script ran the nine commands in the block below and
> skipped the two this section's own prose calls REQUIRED — `atriom:install --force` and
> `atriom:rebuild-search`. Both are silent when skipped, which is the entire reason for having a
> script, so both are now in it (`--skip-search` opts out of the re-fold on a very large database),
> and `DeployScriptRunsTheReleaseSequenceTest` fails the build if a step leaves the script or the
> two documents drift apart. **The re-fold runs on EVERY release, not only one that changed a
> `searchTextSources()`** — it compares each fold before writing and skips the row when unchanged,
> so on a release that touched nothing it is a read-only pass, and nobody has to remember to ask.

```
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build             # REQUIRED — app assets AND the handbook; see below
php artisan filament:assets
php artisan migrate --force
php artisan atriom:install --force            # REQUIRED on any release adding reference data — see below
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan storage:link            # once — PDFs/media links
php artisan queue:restart           # workers pick up new code
php artisan atriom:rebuild-search   # REQUIRED on any release that changes a searchTextSources()
```

> **Re-run `atriom:install` on every release, not only the first.** It is idempotent by design — it
> re-asserts reference data and touches no business row — and it is the only thing that lays down
> the seeded CATALOGUES. A migration creates the empty `payment_methods` and `expense_categories`
> tables; the rows come from a seeder. Skip this and an upgraded box gets the schema and none of the
> content: no Fawry to activate, none of the five Egyptian overheads that were the point of the
> change, and `RolesPermissionsSeeder` alone will not fix it because the new permissions are only
> half of what is missing. A permission that exists only in the seeder file also leaves its screen
> absent from the navigation for **everyone, including super_admin**, with no error to say why.
>
> **Prove the extensions in FPM, not just in the CLI.** `composer install` above already refuses on
> a box missing `intl`, `gd` or `zip` — but it runs under `php-cli`, and the panel renders under
> `php-fpm`. A box with an extension in one and not the other completes every line of this sequence
> and then throws on every money column. After `queue:restart`, read
> `curl -s -H "X-Health-Token: $HEALTH_TOKEN" https://<host>/health | jq '.checks.php_extensions'`
> **over HTTP**; `composer check-platform-reqs` is the CLI half and cannot see the split. The token
> is required — without it the endpoint answers `status` only and this prints `null` on a healthy
> box.

> **`npm run build` is not optional.** `public/build` is gitignored, so the compiled CSS
> exists only where it was built. Since the panels moved to a custom Filament theme
> (`resources/css/filament/theme.css`, which sets panel density in one place), Filament no
> longer falls back to the stylesheet shipped inside the vendor package — **skip the build and
> `/admin` and `/portal` render with no CSS at all**, as raw unstyled HTML. It fails loudly and
> immediately, but only after the release is live, so it belongs in the sequence rather than in
> someone's memory. `filament:assets` republishes the package's JS/icons after a Filament
> upgrade.


> **`npm run build` also builds the handbook**, and that is deliberate: it chains
> `npm run docs:build`, which regenerates the handbook's datasets from the code registries and then
> renders the site into `storage/app/handbook`. One command, nothing to remember, and the handbook
> can never be a release behind the code it documents.
>
> The output goes **outside the webroot** so nginx cannot serve it directly and the `auth`
> middleware on `/handbook` genuinely applies — it documents posting rules, GL mappings and
> approval ladders. If it is ever missing, `/handbook` answers **503 naming the missing step**
> rather than a 404 that reads like a broken link. `vitepress` is a devDependency, so this must run
> before any `npm prune --production`.

**First deploy only:**

```
php artisan key:generate
php artisan atriom:install --admin-email=you@example.com --admin-name="Your Name"
    # roles + permissions, chart of accounts, account mappings, charge codes, fiscal year,
    # the FIRST ADMINISTRATOR — then VERIFIES the database can post. Prints the generated
    # password once; pass --admin-password= to choose it yourself.
php artisan atriom:preflight        # health + both data audits + the books reconciliation; must be green before real data goes in
```

`php artisan migrate --force --seed` is **NOT** for prod — `DemoSeeder` is demo data.

`atriom:install` exists because this step used to be a checklist, and this line used to say "seed
only `RolesPermissionsSeeder`" — which produced an install that **bills perfectly and posts
nothing**: a correct invoice, `accounting:sync-ledger` refusing every entry, and a general ledger at
zero. The chart of accounts is a seeder, not a migration. The command runs the reference-data
seeders in order and then **verifies the result through the same resolver the journalizers use**,
exiting non-zero if the database still cannot post — so "installed" means proved, not "the seeders
exited 0". It is **idempotent** (safe to re-run on a live system; it re-asserts reference data and
touches no business row) and it **never** seeds demo data.

**The administrator half exists for the same reason.** `DemoSeeder` is the only thing in the
codebase that has ever created a `User` — so a production box, which must not run it, finished this
sequence with an **empty users table and no way into `/admin`**. Nothing said so: the login page
renders and simply rejects every credential, which reads as a typo. `atriom:install` creates the
first `super_admin` (generating a strong password and printing it once — never a default, never a
published one), skips silently when one already exists, and warns with the exact flags when run
scripted with none. `atriom:health`'s `admin_access` check keeps the empty state from being quiet if
someone skips it.

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
  **backup freshness**, storage, 2FA enforcement, **whether this install can post to the books**
  and **whether the seeded demo logins still exist**, and answers 503 when any of them is wrong.
  - Anonymous callers get `{"status":"ok"|"degraded"}` and nothing else; set `HEALTH_TOKEN`
    and pass `?token=` (or `X-Health-Token`) to see which check failed.
  - **Why it has to be external:** every scheduled monitor — `backup:monitor` included — can
    only report a problem while the scheduler is running, so none of them can report that the
    scheduler has *stopped*. A dead cron silences the billing run, the GL sync, the nightly
    backup and every alarm at once, and looks exactly like a quiet night. Something off-box
    has to ask.
  - Same checks from the CLI, non-zero on failure: `php artisan atriom:health`.
  - **`accounting` — can this install post at all?** The chart of accounts is a SEEDER, not a
    migration, so a database that has only been migrated bills perfectly and posts NOTHING: the
    invoice is correct, `accounting:sync-ledger` refuses every entry with *"No account mapping for
    role 'accounts_receivable'"*, and the ledger stays at zero. The realtime hook is best-effort and
    the sweep's non-zero exit goes to a cron log, so the first person to notice would be the
    accountant asking for a trial balance a month later. The check resolves every posting role
    through the real `AccountResolver` — the same path the journalizers use — and names the remedy
    (`php artisan db:seed --class=AccountingSeeder`).
  - **`demo_accounts` — are the published logins still live?** `DemoSeeder` creates eight admin
    users (including a **super_admin**) and two portal users on one password that DEMO.md publishes.
    Matched on the demo email domains, not the password hash: rotating `DEMO_USER_PASSWORD` changes
    the secret, not the fact that the account belongs to nobody.
  - Both fail only outside `local`/`testing` — a developer between `migrate` and `db:seed` is not a
    broken install, and a check that cries wolf locally gets ignored in production.

---

## 7. Pre-flight gates (run before the first real charge / tax filing)

```
php artisan integrations:check          # Paymob auth + ETA OAuth reachable with live creds
php artisan marketing:ensure-budgets    # provision current-year budgets
php artisan billing:reconcile           # books tie out (AR + marketing + CAM) — exits non-zero on drift
vendor/bin/pest --parallel              # full suite green
```
Then: Paymob KYC done + live keys + prod callback URLs registered; ETA signing cert installed + `ETA_MOCK=false` tested against preprod; `php artisan mail:test` lands in a real inbox from the verified domain; accountant has signed off [BUSINESS-RULES.md](../BUSINESS-RULES.md).

---

## 8. Rollback

- Code: redeploy the previous tag + `php artisan queue:restart`.
- DB: migrations are forward-only — restore from the latest backup (§5) if a migration must be undone.
  **Read [§13](#13-undoing-a-schema-change) before running `migrate:rollback`**: 171 migrations drop
  columns in `down()`, so a rollback destroys the data in them rather than returning you to the
  previous release.
- Integrations: flip `ETA_MOCK=true` / `PAYMOB_ENABLED=false` to safely disable a misbehaving gateway without a deploy.

---

# Incident procedures

> These five were written 2026-08-13, because the sweep before staging found the runbook covered
> how to *deploy* the system and nothing about the days it misbehaves. Each is the thing you do at
> 08:00 when something has already gone wrong — so each starts with **how to tell**, not with a
> fix, and each says what NOT to do, because in every one of these the instinctive move is the
> damaging one.

## 9. A billing run failed or half-completed

**How to tell.** Tenants report a missing invoice; `atriom-monthly-billing` is absent from the
scheduler log; or the run reports `Failed > 0`.

```
php artisan billing:run-monthly --period=YYYY-MM     # re-run, safely
```

**Re-running is the correct first move, and it is safe.** The run is idempotent: it skips any
lease already billed for that period (`Considered / Created / Skipped / Failed`). A clean re-run
after a partial one reports the remainder as *Created* and everything else as *Skipped*. This is
verified — a second run on a complete month creates 0 and skips all.

**If rows report `Failed`:** the reason is per-lease, not global. The usual causes, in order of
likelihood:

1. **The period is closed.** A closed accounting period refuses the posting date. Reopen it
   (`/admin/month-end-close`) or bill into the open month with a post-month override.
2. **Overlapping or missing charge rows.** `php artisan atriom:audit-charge-schedules` — a lease
   whose charge rows overlap bills **nothing**, and the refusal is caught rather than thrown.
3. **A missing account mapping.** `php artisan atriom:health` → `accounting`.

**Do NOT** create the missing invoices by hand. They would carry no `period_start`/`period_end`
linkage the run recognises, so the next run bills that lease-month *again* and the tenant gets two.

**Afterwards, always:** `php artisan billing:reconcile --deep`. A partial run is exactly the state
that leaves AR and the GL disagreeing, and this is the only thing that tells you which document.

---

## 10. A user is locked out

**First, which surface?** They are different systems and the fix for one does nothing for the other:
`/admin` is a `User` with spatie roles · `/portal` is a `TenantUser` · the mobile app authenticates a
`Tenant` through `/api/v1`.

**Admin.** There is no self-service reset for `/admin` by design.

```
php artisan tinker
>>> $u = App\Models\User::where('email', 'them@example.com')->firstOrFail();
>>> $u->update(['password' => bcrypt('<a generated password>')]);   # hashed by the cast
>>> $u->status                                                       # 'suspended' locks them out too
```

If `status` is `suspended`, that is a deliberate act by an administrator — find out who and why
before reversing it; it is in the activity log.

**2FA is the more common cause than a forgotten password.** A lost authenticator device locks an
account whose password is fine. Clear the enrolment and let them re-enrol:

```
>>> $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null,
...   'two_factor_confirmed_at' => null])->save();
```

**Nobody can reach `/admin` at all.** `atriom:health` → `admin_access` reports zero `super_admin`
accounts. Re-run `php artisan atriom:install --admin-email=…`; it creates the first administrator,
prints a generated password once, and skips silently when one already exists.

**Portal / mobile.** Both have working self-service reset. If the mail never arrives, that is a mail
problem, not an auth one — `php artisan integrations:check --mail`. Note `APP_MOBILE_RESET_URL`:
unset, the mobile reset mail's only button 404s, and `atriom:health` fails in production while it is.

**Do NOT** grant a role to work around a lockout. `super_admin` is not a way to log someone in, and
the grant outlives the incident.

---

## 11. Rotating a secret

Order matters: **add the new credential, deploy, verify, then revoke the old one.** Revoking first
means every request between revocation and deploy fails.

```
# 1. edit .env on the box            2. the cache is what the app actually reads
php artisan config:cache
# 3. workers hold the OLD value in memory until restarted — this is the step people miss
php artisan queue:restart
# 4. prove it
php artisan integrations:check
```

**`APP_KEY` is not in this list.** Rotating it makes every encrypted column and every session
unreadable. It is a data migration, not a secret rotation; do not do it casually.

| Secret | Verify with | Note |
|---|---|---|
| `PAYMOB_*` | `php artisan integrations:check` | Rotate the HMAC secret and the callback URL together, or callbacks fail signature checks. |
| `MAIL_*` | `php artisan mail:test` | Must land in a real inbox from the verified domain. |
| DB password | `php artisan atriom:health` → `database` | Update the backup config too — it authenticates separately. |
| `BACKUP_ARCHIVE_PASSWORD` | `php artisan atriom:backup-verify` | **Archives written with the OLD password stay encrypted with it.** Keep the old one escrowed for as long as you keep those archives, or they are unreadable. |
| `HEALTH_TOKEN` | `curl -H "X-Health-Token: …" /health` | Update the external monitor in the same change or it starts alerting. |

---

## 12. Cut-over — loading a real mall

Rehearse this on **staging** first, twice, and get the same result both times. That is the exit
test; a cut-over you have not repeated is a cut-over you have not tested.

**Order is not optional** — each import resolves records the previous one created:

```
php artisan atriom:install --admin-email=…      # reference data + first admin, then PROVES the DB can post
```

1. **Properties, floors, units** — `UnitImporter`
2. **Tenants** — `TenantImporter` (identity on tax id, then email; never on name)
3. **Leases** — `LeaseImporter` (seeds the standard charges; a lease with none bills nothing)
4. **Charge schedules** — `ChargeImporter`, which writes through `ChargeScheduleService`
5. **Vendors · Employees · Fixed assets · Meter readings** — their own importers
6. **Opening balances** — `OpeningInvoiceImporter`

**Opening AR arrives as real invoices, not a lump sum.** Ageing, dunning, statements and
per-invoice allocation all work on documents. They deliberately post NOTHING to the GL — the
revenue is already inside the accountant's opening journal entry, and posting it again would
double-count every pound of it. Imported fixed assets carry
`opening_accumulated_depreciation` for the same reason.

**Then, before anyone bills anything:**

```
php artisan atriom:audit-charge-schedules   # overlapping/gapped/undated charge rows — exits non-zero
php artisan billing:reconcile --deep        # the AR tie-out is what proves the migration loaded everything
php artisan atriom:health
```

**Do NOT** run `migrate:fresh --seed` on the target. `DemoSeeder` is demo data, and it is the one
thing in the codebase that creates users.

---

## 13. Undoing a schema change

**Read this before `migrate:rollback`.** 171 of this project's migrations drop columns in `down()`.
Rolling back does not return you to the previous release — it **destroys the data in those
columns**, and no error says so. Rollback is a development tool here, not a production one.

**In production, restore instead.** The backup IS the rollback story:

```
php artisan atriom:backup-verify --keep    # replay the newest archive into a scratch schema FIRST
```

That proves the archive restores *before* you touch the live database, and `--keep` leaves the
scratch schema up so you can inspect it. Only then restore over production, redeploy the matching
release tag, and `php artisan queue:restart`.

**The narrow case where rollback is right:** the migration is the newest one, it added something
and dropped nothing, and no release has run against it. Read its `down()` and confirm — do not
infer it from the name.

**Whatever the route, afterwards:** `php artisan billing:reconcile --deep`. A restore returns the
database to an earlier moment; any document created after that moment is gone, and the tie-out is
what tells you whether the books still agree with themselves.
