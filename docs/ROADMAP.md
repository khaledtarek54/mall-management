# Atriom — Production Roadmap

**Current state:** The app is feature-complete (1098 tests green, Paymob certified, deal closed with Eltizam). We are now *productionizing*: flipping integrations from mock/sandbox to live, hardening security, and standing up ops/observability for real money and Egyptian tax filings.

**How to read this:** Items are grouped by priority — **P0 blocks go-live** (real money / tax can't flow safely without it), **P1 is important soon** (first weeks of production), **P2 is later** (polish, scale, nice-to-haves). Overlapping audit findings across dimensions have been merged into one row. Owner badge: 🧑‍💻 code (we can build now) · 🔑 external (needs credentials/KYC/certs from operator or a third party) · ⚙️ ops (deployment/infra config).

---

## ✅ Completed since this audit

- **Latin-digit lock** — all numbers render in Western digits even in the Arabic UI (`Number::useLocale('en')` + regression test).
- **Security + env hardening bundle** — security headers on every response (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS in prod) + a **strict CSP scoped to the public `/pay` pages**; **Sanctum token TTL** (30-day default, env-driven); **`APP_TIMEZONE`** env-driven (set Africa/Cairo in prod); prod guidance in `.env.example` for `APP_DEBUG`/`LOG_LEVEL`/`SESSION_ENCRYPT`; **2FA config-gated** for write roles (`SECURITY_FORCE_2FA_ROLES`). Confirmed already-covered: Filament throttles login (5/min), demo password is env-driven.
- **Observability + indexes** — `App\Support\OpsLog` helper + a dedicated, PII-scrubbed `ops` log channel instrumenting every money/integration path (Paymob client + callback, ETA submission + job retry-exhaustion, monthly-billing/late-fee/CAM run summaries, payment-link); **`payments.gateway_transaction_id` indexed** (the webhook hot path); the 5 exporters are now config-driven (`EXPORT_QUEUE_CONNECTION`) to queue in prod. *(The audit's other "missing index" claims — activity_log/notifications morphs, invoice_payment/cam/maintenance FKs — were already covered; verified via `SHOW INDEX`.)*

---

## 🔴 P0 — blocks go-live (real money / tax)

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| Flip ETA out of mock mode | `EtaSettings.mock=true` by default submits to a fake endpoint — no legally-binding invoices. Set `ETA_MOCK=false` once live creds + signing are in place (`app/Settings/EtaSettings.php:16`). | 🧑‍💻 | S |
| ETA live credentials + signing certificate | External procurement: real `client_id/client_secret` from the operator's ETA taxpayer profile **and** a CAdES signing certificate (HSM/USB/cloud key vault). ETA production **rejects unsigned B2B documents**; code has the pluggable signer seam + refuse-to-submit guard ready (`docs/ETA-PAYMOB-CERTIFICATION.md §B`, `config/eta.php:63-74`). | 🔑 | M |
| ETA EGS codes + issuer identity | Operator must register real EGS item codes (base_rent, service_charge, utility, parking, percentage_rent) and supply issuer TRN/legal name/address. All env-driven with placeholder defaults today — wrong codes ⇒ rejection (`config/eta.php:35-61`). | 🔑 | S |
| Paymob live cutover (KYC + live creds) | Sandbox is fully integrated and certified; no code changes. External: operator completes Paymob KYC, re-issues all 4 live credentials, re-registers callback URLs on the prod domain, runs one small real charge (`PAYMOB-SETUP.md §6`). | 🔑 | S |
| Database backups in the deploy workflow | Checklist requires daily backups, ≥7-day retention + a tested restore. No backup script, snapshot config, or restore procedure exists in INFRA.md. Catastrophic data-loss risk for an ERP holding money/tax/contracts (`999-production-checklist.md:32`). | ⚙️ | S |
| Error tracking + centralized logging | No Sentry/Flare wired (zero APM packages in composer); logs are disk-only with no Logflare/Papertrail shipping. Queue/payment/ETA failures would surface only via customer complaints. Wire exception capture + log aggregation + alerting (`999-production-checklist.md:95-96`; `bootstrap/app.php` has no handler). | ⚙️/🧑‍💻 | M |
| Sanctum tokens never expire | `config/sanctum.php` sets `expiration => null` — leaked mobile tokens are valid forever with no rotation. Set a finite TTL before mobile clients hold real tokens (`config/sanctum.php:53`). | 🧑‍💻 | S |
| Rotate seeded demo password | Every seeded user (admin + 9 roles) shares hardcoded `password`; `.env.example` ships `DEMO_USER_PASSWORD=password`. Rotate/delete demo accounts before the URL is shareable (`DemoSeeder.php:56`, `.env.example:11`). | 🧑‍💻 | S |
| Paymob + payment-link failure observability & tests | `PaymobClient`/`PaymobPaymentInitiator` have **zero** `Log::` calls — a Paymob outage is silent and transactions stick in `initiated`. Add instrumentation; add failure-path tests (invalid/expired token, failed callback, concurrent attempts) and an E2E payment-link spec — none exist today (`PaymentLinkFlowTest` is happy-path only; no `13-payment-link.spec.js`). This is the new public revenue surface. | 🧑‍💻 | M |
| MallStats "MRR" relabel | The headline stat labelled "Monthly revenue" actually shows billed-this-month (dips in a partial month) — misleading in the Jawad/Eltizam demo. Relabel to "Billed this month" (`MallStats.php:120-132`). | 🧑‍💻 | S |

*Verified-done (no action): tax-ID format validation (D-44), MeterReading UI (D-38), `/me/*` mobile API + password-reset wiring (D-56/57), Portal "Pay Now" → Paymob (D-33), vendor auto-expire + SLA scans (D-43), activity-log retention + maintenance auto-close (D-59/30).*

---

## 🟠 P1 — important soon

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| Queue worker + scheduler deployment | All money flows (monthly billing, late fees, ETA submission, exporters, housekeeping) depend on a long-lived `queue:work` and the `schedule:run` cron. INFRA.md has only a manual runbook — no systemd/supervisor template, provisioning script, restart-on-deploy step, or `storage:link` reminder (broken doc links without it). First deploy will silently not run jobs (`998-deferred-backlog.md:D-58/D-60`, `INFRA.md:21-49`). | ⚙️ | M |
| Failed-jobs + scheduler monitoring & alerting | Checklist says "monitor `failed_jobs` row count" and verify the cron, but no alert, dashboard, or health-check script exists. A failed ETA/payment job (or a silently-dead cron) goes unnoticed for days — tenants unbilled, tax submissions missed (`999-production-checklist.md:45-46`). | ⚙️ | S |
| Health-check + uptime monitoring | `/up` exists but checks nothing meaningful (DB/queue/cache) and no uptime monitor (Pingdom/UptimeRobot) is configured. A false 200 masks a DB outage; downtime found via complaints (`bootstrap/app.php:13`, `999-production-checklist.md:97`). | ⚙️ | M |
| Production env hardening | `.env.example` lacks `APP_TIMEZONE=Africa/Cairo` (schedules fire at wrong wall-clock on UTC), ships `LOG_LEVEL=debug` + `LOG_CHANNEL=stack` (leaks SQL/PII, fills disk), and has no logrotate/retention guidance. Enforce Cairo TZ, warning-level logs, daily channel + rotation (`.env.example:1-12,76`, `config/app.php:72`). | 🧑‍💻/⚙️ | S |
| HTTPS / security headers (CSP, X-Frame-Options, HSTS) | No app-level HTTPS forcing, CSP, or X-Frame-Options. Public `/pay/{token}` is a custom route with no CSP → XSS/clickjacking risk on a real-money page; plaintext creds if the proxy is bypassed (`routes/web.php:42-46`, `999-production-checklist.md:85-86`). | 🧑‍💻 | M |
| Rate-limit auth + admin/portal surfaces | Filament `/admin` and `/portal` logins have **no** route-level throttle (unlimited password guessing); admin Livewire/Filament forms and payment initiation are unthrottled (`web.php` only throttles password-reset). `danharrin/livewire-rate-limiting` is in composer but unused. Add per-IP/email throttles (`AdminPanelProvider.php:47`). | 🧑‍💻 | M |
| Enforce 2FA for all write-capable admin roles | TOTP is forced only on `super_admin`; managers/accounting/leasing handle payments + tenant changes with optional 2FA. Make it mandatory for write roles (`AdminPanelProvider.php:74`). | 🧑‍💻 | M |
| User-model activity logging | `User` uses `LogsActivity` but defines no `getActivitylogOptions()` — staff create/edit/delete, role grants, and password resets are **not** audited. Compliance + investigation gap (`app/Models/User.php`). | 🧑‍💻 | S |
| Admin self-service profile + reset | Password-reset link works but no `EditProfile` action is configured, so operator staff can't update name/email without a super_admin. Portal already has this (`AdminPanelProvider.php:48-60`). | 🧑‍💻 | S |
| Paymob credential vaulting + HMAC rotation | Live `PAYMOB_API_KEY/HMAC_SECRET` sit in plaintext `.env` with no rotation/versioning or secrets manager. A leaked HMAC lets an attacker forge "paid" callbacks. Move to a vault; document rotation (`config/integrations.php:16-42`). | ⚙️ | M |
| Email (SMTP) cutover | `MAIL_MAILER=log` — invoice/payment/maintenance notifications reach nobody. Operator sets real SMTP host/creds/from-address + SPF/DKIM (`.env:52`, `999-production-checklist.md §7`). | 🔑 | S |
| Async/scaled exporters | All 5 exporters use `getJobConnection()='sync'` — exporting a full year of invoices times out at 10K+ rows. Move to queued connection (D-54) (`InvoiceExporter.php:42-44`). | 🧑‍💻 | M |
| DB indexes for scale | Missing indexes: `activity_log` & `notifications` polymorphic FKs, `invoice_payment` pivot FKs, `cam_allocations.lease_id`, `maintenance_requests` composite (status/unit) + comments. Full table scans as tables grow; blocks monthly close (`*activity_log*`, `*cam_allocations*`, `*maintenance_requests*` migrations). | 🧑‍💻 | S |
| Batch-run logging + alerting (billing / late fees / CAM / scans) | `MonthlyBillingService`, `LateFeeService`, `CamReconciliationService`, and SLA scans return/track failure counts but emit no post-run summary log and no failure-rate alert. A partial monthly-billing failure (5 of 500 leases) is invisible until audit. Add structured summaries + threshold alerts + PII scrubbing in logs (`MonthlyBillingService.php:32-39`, `CamReconciliationService.php`). | 🧑‍💻 | M |
| ETA retry-policy verification + failed-submission alerting | `SubmitInvoiceToEta` has `$tries=3` + backoff `[60,300,900]` (correct), but no logging/telemetry — exhausted retries land in `failed_jobs` and a missed tax submission goes unnoticed for weeks. Add logging + alerts grouped by failure reason; test under failure (`SubmitInvoiceToEta.php:24,34-36`). | 🧑‍💻 | S |
| ETA receiver address per-tenant | `EtaJsonBuilder` hard-codes receiver governate/city to Giza / 6 October — wrong buyer address on real invoices. Small schema + form addition to capture each tenant's real address (`EtaJsonBuilder.php:43-50`). | 🧑‍💻 | M |
| "Change rent" action that syncs Lease ↔ Charge | `base_rent_monthly` is editable on the lease edit form but doesn't update the linked `Charge::amount` → stale billing (under/over-charge). Add a guarded sync action (like the table's) (`LeaseForm.php`, D-13). | 🧑‍💻 | M |
| Payment over-allocation hardening | Per-row allocation check uses a loose `0.005` tolerance and isn't concurrency-safe; parallel submits can over-allocate. Add a backend/DB-level guard + concurrency test (`PaymentForm.php:142-184`, D-18). | 🧑‍💻 | M |
| Reports/AR-Aging RBAC consistency | `reports.view` gate is enforced on report pages but cosmetic on some dashboard widgets — viewers can glimpse AR data. Apply the gate consistently (`ArAging.php`, `Reports.php`, D-53). | 🧑‍💻 | S |
| Multi-channel notification fallback | Operator-to-operator alerts (SLA breach, overdue-owner, department messages) are bell-only; missed unless staff log in daily. No SMS/push fallback to mail (`app/Notifications/`, `modules/19`). | 🧑‍💻 | M |
| Mobile (Flutter) app v1 + unified password reset | API is complete but the Flutter app repo doesn't exist; design approval needed on the v1 endpoint shortlist, and portal lacks self-service password reset to unify with the API flow. Critical for the mobile-first positioning (`MOBILE-APP-BRIEF.md`, D-56/D-57/D-6). | 🔑/🧑‍💻 | L |
| `integrations:check` preflight in cutover | Run `php artisan integrations:check` after the live `.env` swap to validate Paymob + ETA creds/connectivity before the first real charge/submission (`CheckIntegrationsCommand.php`). | ⚙️ | S |

---

## 🟡 P2 — later

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| Portal/Owner financial transparency (CAM + credit notes) | Tenants/owners see only consolidated invoice lines — no CAM allocation breakdown (pro-rata %, pool) and no credit-note resource, despite both affecting AR. Drives disputes + manual operator explanations (D-22/D-32/D-42). | 🧑‍💻 | M |
| Owner portal: enable + per-property dashboard + nav badges | Owner portal is feature-flagged off; if shipped, it needs a per-property KPI dashboard (occupancy/collections/AR/maintenance) instead of a single portfolio roll-up, plus nav-badge counts. Product decision: ship pre- or post-pilot (`config/features.php:19`, D-22/D-31/D-32). | 🔑/🧑‍💻 | M |
| Dashboard + ETA-compliance drilldowns | AR-aging buckets, tenant-mix, top-tenants, and ETA pending/rejected tiles aren't clickable — operators can't drill from a number to the underlying list (D-24/D-26). | 🧑‍💻 | M |
| Tenant self-service profile | Tenants can't update their own contact/phone/email/bank details — stale data hurts collections (D-7). | 🧑‍💻 | S |
| Document attachments (maintenance + leases) | medialibrary is installed but unwired — no before/after photos, inspection reports, or signed lease PDFs. Audit trail lacks artifacts (`MaintenanceRequest`/`Lease` have no `HasMedia`). | 🧑‍💻 | M |
| Bulk actions + period-level report exports | No bulk status/assignment (reassign 10 tickets, cancel drafts) and no monthly/quarterly CSV/Excel export for the accountant — manual one-by-one + copy-paste (`Reports.php` PDF-only). | 🧑‍💻 | M |
| Global / cross-entity search | Only column-level `.searchable()`; no single entry point to find a tenant with their invoices + maintenance + CAM. Collections teams hop between tables. | 🧑‍💻 | M |
| Report/query caching + widget N+1 fixes | `ReportService` re-aggregates every load (D-55); `TopTenants` runs an N+1 on sales density; portal/owner tables miss `with()` eager loads. Defer until first scale event. | 🧑‍💻 | M |
| CAM `--auto-bill` decision | Annual `cam:reconcile` runs review-only by default; operator must decide whether to trust `--auto-bill` or keep manual review (D-23-bis). | 🔑 | S |
| Sales-declaration lock notification | Locking a tenant's sales declaration is silent — tenant learns only on revisit; bundle with notification-design work (D-34). | 🧑‍💻 | M |
| Invoice PDF attachment on email | `InvoiceIssued` mail has no PDF attachment; tenants must log in to download (D-16). | 🧑‍💻 | S |
| Tighten secondary rate limits + entropy | Forgot-password throttle (3/min) and public `/pay/*` read throttle (30/min) allow email/token probing; payment-link token uses base-36 (~253 bits). Tighten reads, raise entropy to 256-bit. | 🧑‍💻 | S |
| Session encryption + explicit CORS | `SESSION_ENCRYPT=false` (plaintext sessions in DB); no explicit CORS config for `/api/v1`. Defense-in-depth (`.env.example:87`). | ⚙️/🧑‍💻 | S |
| Portal write-restriction enforcement at action level | Read-only TenantUser write block is UI-level (hidden buttons) only — re-validate at action/save dispatch (`OVERVIEW.md:86`). | 🧑‍💻 | M |
| Apple Pay + WhatsApp | Apple Pay scaffolded but needs a separate Paymob integration + domain-association file (external); WhatsApp is a disabled stub awaiting a Business API client (high-value in Egypt, post-v1). | 🔑/🧑‍💻 | S–L |
| Service test-coverage gaps | No dedicated tests for `OwnerRequestService`, `MarketingLevyService`; `CreditNoteService` misses void/locked-declaration + resubmission edge cases. AR-critical logic. | 🧑‍💻 | M |

---

## Recommended next 3 (code side)

These are the highest-leverage things I can build right now without waiting on external credentials, KYC, or certificates:

1. **Security + env hardening bundle** — set Sanctum token TTL, rotate/parametrize the demo password, enforce `APP_TIMEZONE=Africa/Cairo` + production log level, add HTTPS/CSP/X-Frame-Options + admin/portal/Filament login throttles, and force 2FA on all write roles. (Knocks out several P0/P1 security rows in one pass; no external deps.)
2. **Money-path observability + tests** — add structured logging to `PaymobClient`/`PaymobPaymentInitiator`, ETA submission, and the billing/late-fee/CAM batch runs (with PII scrubbing + failure-rate summaries), and write the missing payment-link failure-path + E2E specs. (Makes real-money flows debuggable before go-live.)
3. **Performance indexes + async exporters** — add the missing DB indexes (`activity_log`/`notifications` morphs, `invoice_payment` pivot, `cam_allocations.lease_id`, maintenance composites) and move the 5 exporters off `sync`. (Pure code; prevents monthly-close slowdowns and export timeouts at production scale.)
