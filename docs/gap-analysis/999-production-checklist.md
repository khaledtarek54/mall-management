# Production Readiness Checklist

> **⚠️ [docs/GO-LIVE.md](../GO-LIVE.md) is the gate, not this file.** GO-LIVE is verified against the
> code and dated; this page is the older deployment-mechanics list kept for the steps GO-LIVE does
> not repeat (asset build, file permissions, log rotation, sign-off table). Where the two disagree,
> GO-LIVE wins.
>
> **No counts are asserted here any more (corrected 2026-08-12).** This page used to ask the
> operator to confirm "295 / 295 green", "40+ migrations" and a three-line schedule — baselines from
> 2026-05-31 that had drifted to 4,564 tests, 211 migrations and 31 scheduled commands. A checklist
> whose numbers are wrong is worse than none: the operator either believes something is broken, or —
> far worse — sees a truncated run hit an old number and ticks the box. The rule this project
> applies to generated docs applies here too: **never hand-type a count**. Each item below now
> asserts a STATE the command itself reports.
>
> Single-page sign-off list. Run top-to-bottom before any non-demo deployment.
> Each item has a source (code / config / runbook), a status box, and a verification command where possible.

## 1. Code state

- [ ] `git log --oneline main..HEAD` reviewed; no unfinished branches in the working tree
- [ ] `vendor/bin/pest --parallel` → **green, with no failures and no errors**. Do not check a count: the suite grows every week, and a number here is a number that goes stale.
- [ ] `npx playwright test` → all green. *(Advisory: CI auto-runs are paused, and the E2E suite is slow — see CLAUDE.md.)*
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build` if assets are managed via Vite

## 2. Environment

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL` set to the deployed HTTPS URL
- [ ] `APP_KEY` generated (`php artisan key:generate`) and persisted to env
- [ ] Timezone: `APP_TIMEZONE=Africa/Cairo` (matters for `schedule:run` cron timing — billing:run-monthly runs at 02:00 local)
- [ ] `LOG_CHANNEL=stack`, `LOG_LEVEL=warning` (info+ floods disk)
- [ ] `SESSION_DRIVER=database` (already default)
- [ ] `CACHE_STORE=database` (already default) or `redis` if redis is provisioned

## 3. Database

- [ ] `php artisan migrate --force` — completes with no pending migrations left (`php artisan migrate:status` shows none)
- [ ] `php artisan atriom:install --admin-email=… --admin-name="…"` — roles + permissions, **chart of accounts + account mappings + charge codes + fiscal year**, the **first `super_admin`** (nothing else in the codebase creates a user, so without this nobody can sign in), then verifies the database can post (exits non-zero if it cannot). Skip it and the system bills correctly while the general ledger stays empty. Idempotent; never seeds demo data
- [ ] Store the administrator password it prints — it is shown once and generated, not a default
- [ ] `php artisan atriom:health` — green (it now also reports an unpostable install and any seeded demo login)
- [ ] Production DB user has `CREATE/ALTER/DROP/INDEX` permissions on the schema (Laravel migrations create indexes)
- [ ] `DB_HOST` is the production host, NOT localhost
- [ ] `DB_DATABASE` exists and is empty before first migrate
- [ ] Charset/collation matches (`utf8mb4` / `utf8mb4_unicode_ci`)
- [ ] Daily backups configured (managed DB provider's automatic snapshots ≥ 7 days retention)

## 4. Storage + media

- [ ] `php artisan storage:link` executed on the deployment host (else uploaded files have broken URLs — D-60 / F-76)
- [ ] Media directory `storage/app/public` is writable by the PHP worker user
- [ ] `FILESYSTEM_DISK` set if not using `local` (s3 etc.)
- [ ] If using s3: `AWS_*` env vars set; bucket has correct CORS

## 5. Queue

- [ ] `php artisan queue:work --tries=3 --max-time=3600` running under **supervisor** or **systemd** as the deploy user (D-58 / F-74)
- [ ] Worker count ≥ 1 per concurrent job needed (monthly billing single shot is fine with 1)
- [ ] `php artisan queue:retry all` documented as the failed-job re-run procedure
- [ ] `failed_jobs` table is monitored (alerts on row count > N)

## 6. Scheduler

- [ ] Server cron entry installed: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- [ ] `php artisan schedule:list` shows 3 expected entries:
  - `atriom-monthly-billing` — 1st @ 02:00 local
  - `atriom-late-fees` — daily @ 04:00 local
  - `atriom-cam-reconcile` — Jan 15 @ 03:00 local (review-only by default)
- [ ] Optional (decide pre-launch): add `--auto-bill` to the CAM cron (D-23-bis); add `Schedule::command('activitylog:clean')->monthly()` (D-59 / F-75); add `vendors:expire-contracts` daily (D-43 / F-58)

## 7. Mail

- [ ] `MAIL_MAILER=mailersend` (the shipped driver) — or `smtp`/`log` if the provider changes
- [ ] `MAILERSEND_API_KEY` set to a token with Email send permission
- [ ] `MAIL_FROM_ADDRESS` is a real mailbox on a domain **verified** in MailerSend (else `#MS42207`)
- [ ] MailerSend account approved — trial plans cap unique recipients (`#MS42225`)
- [ ] `MAIL_ALWAYS_TO` empty (it is ignored in production, but leave no misleading value)
- [ ] `php artisan integrations:check --mail` passes, then `php artisan mail:test <inbox>` lands; verify SPF/DKIM at the DNS layer
- [ ] Decide whether InvoiceIssued mail attaches the PDF (D-16 / F-23)

## 8. Sanctum / API

- [ ] `SANCTUM_STATEFUL_DOMAINS` includes the production frontend domain(s)
- [ ] `SESSION_DOMAIN` set if same-site SPA pattern is used
- [ ] `/api/v1` auth surface decision: implement the M19 shortlist OR ship with auth-only and grow per Q2 roadmap (D-56)
- [ ] Password reset flow shipped on portal + API (D-57 / F-8 / F-72) if any non-admin-reset user lifecycle is required

## 9. ETA e-invoicing

- [ ] `ETA_MOCK=false` (was true by default — D-17 / F-24)
- [ ] `ETA_ENDPOINT=https://api.invoicing.eta.gov.eg` (or preprod URL during staging)
- [ ] `ETA_AUTH_ENDPOINT=https://id.eta.gov.eg/connect/token`
- [ ] `ETA_CLIENT_ID` + `ETA_CLIENT_SECRET` from the ETA portal
- [ ] `/admin/{tenant}/settings → ETA tab`: Mock toggle OFF, Issuer Name + TRN match the operator's legal registration
- [ ] Pre-flight test (M08 §5): submit one B2B test invoice → confirm `eta_status=valid` + IDs populated + PDF shows ETA block
- [ ] Rollback procedure: flip `ETA_MOCK=true` + `php artisan config:clear`
- [ ] Decide on retry policy: explicit `$tries=1` + `backoff()` on SubmitInvoiceToEta job (D-25 / F-34)

## 10. Security

- [ ] HTTPS enforced at the reverse proxy (Cloudflare / nginx). Force `https://` redirects.
- [ ] CSP header set (Filament has its own; verify deployments include `style-src 'unsafe-inline'` for the inline brand color injection)
- [ ] Filament demo accounts have **non-default passwords** rotated before any URL is shared (D-48 / F-63)
- [ ] 2FA decision (D-50 / F-65): integrate `laravel/fortify` or `pragma/laravel-2fa` for super_admin role minimum
- [ ] User CRUD has activity log (D-52 / F-67) — small fix worth applying pre-launch
- [ ] Tenant + Vendor `tax_id` format validation (D-44 / F-59) before ETA cutover
- [ ] Reports + ArAging permission gating (D-53 / F-68) — viewer / manager / leasing / operations get `reports.view`

## 11. Monitoring

- [ ] Application log aggregation: ship `storage/logs/laravel.log` to a log service (Logflare / Papertrail / Better Stack)
- [ ] Error reporting: integrate Sentry (or similar). Filament 4 ships with Sentry support
- [ ] Uptime monitor on the public URL + `/up` health endpoint
- [ ] DB connection monitor (queue length, failed_jobs row count, slow queries)

## 12. Branding

- [ ] Operator's per-property logo + favicon uploaded via `/admin/{tenant}/properties → Edit → Media`
- [ ] `primary_color` set to the operator's brand hex
- [ ] ALL-pseudo-asset falls back to Atriom platform branding (intentional)
- [ ] Cross-checked: invalid CSS bug from commit `4a96a67` already fixed; verify post-deploy by inspecting `--primary-500` CSS variable

## 13. Demo readiness (for the live demo)

- [ ] `migrate:fresh --seed` succeeds in <10 s
- [ ] `HayaWalkSeeder::DEMO_RNG_SEED = 4242` produces the locked DEMO.md numbers:
  - Occupancy 66 % (33/50 Haya Walk units)
  - MRR EGP 1,631,275
  - Collected this month ~EGP 170 K
  - AR ~EGP 657 K · 11 overdue · ~EGP 588 K past due
- [ ] DEMO.md §2 Step 0 followed: switch property to Haya Walk before showing KPIs
- [ ] Login table verified: admin@mall.test / manager@mall.test / viewer@mall.test / leasing@mall.test / operations@mall.test all `password`
- [ ] EN ↔ AR language switch works on every panel
- [ ] Both PDFs (Invoice + Statement) downloadable in both locales

## Sign-off

| Role | Name | Date |
|---|---|---|
| Code owner |   |   |
| Operations |   |   |
| Security |   |   |
| Stakeholder (Jawad / Eltizam) |   |   |

---

**Audit closeout date:** 2026-05-31 — *the audit this page came from. It is not a statement about
the system today; see [GO-LIVE.md](../GO-LIVE.md) and [docs/final-sweep/](../final-sweep/) for that.*
**Pest baseline:** none, deliberately — assert green, never a number.
**F-17 cross-cutting fix:** complete (6/6 resources)
**Deferred backlog:** [998-deferred-backlog.md](998-deferred-backlog.md)
