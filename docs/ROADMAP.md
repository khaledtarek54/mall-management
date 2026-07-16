# Atriom — Roadmap

> **The single prioritized view.** Go-live blockers, the Eltizam FRD expansion, the
> accounting backlog, and known defects — in one place, because three lists in three
> formats is how the last one drifted.
>
> **Re-baselined 2026-07-16**, every row verified against the code. The previous roadmap
> was written 2026-06-28, before modules 21–28 existed; **19 of its 48 rows had already
> shipped** and four rested on premises that are no longer true (see [§6](#6-retired-rows--do-not-rebuild)).
> Read that section before picking up anything you remember from the old list.

**How to read this.** 🔴 **P0** blocks go-live (real money / tax can't flow safely without
it) · 🟠 **P1** important in the first weeks of production · 🟡 **P2** later.
Owner: 🧑‍💻 code (buildable now) · 🔑 external (needs credentials/KYC/certs/a decision from
the operator) · ⚙️ ops (deploy/infra).

**Where the other lists live:** the FRD plan (`~/.claude/plans/happy-percolating-pearl.md`)
keeps the per-phase implementation detail; [docs/accounting/GAP-ANALYSIS.md](accounting/GAP-ANALYSIS.md)
keeps the accounting capability matrix. This file is the priority call across all of them.

---

## 1. Where the project actually is

Feature-complete against the original requirements and live in pilot with Eltizam.
2227 Pest tests + a Playwright E2E suite; Paymob certified in sandbox. Since the last
roadmap the system roughly doubled: the **general ledger** (module 21) and everything that
posts to it — inventory, fixed assets, HR/payroll, treasury, facility maintenance,
announcements, approvals (22–28) — plus airtight property isolation and manifest-driven E2E.

Two structural facts worth holding:

- **Modules 21–28 have never been gap-analysed.** The first twenty have a module doc *and* a
  per-feature analysis; the newer eight have docs only. Every module that posts money to the
  GL sits in that blind spot. See the census in [PROJECT-MAP.md](PROJECT-MAP.md).
- **The self-enforcing gates are what keep this honest** — property isolation, the E2E
  manifest, and (as of 2026-07-16) the GL registry. Where a gate exists, drift fails CI.
  Where one doesn't, drift ships: that is precisely how an applied SLA penalty came to
  reduce a vendor bill while posting nothing to the ledger.

---

## 2. 🔴 P0 — blocks go-live

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| **ETA live credentials + signing certificate** | Real `client_id`/`client_secret` from the operator's ETA taxpayer profile **and** a CAdES signing certificate. ETA production **rejects unsigned B2B documents**. The pluggable signer seam + refuse-to-submit guard are ready (`config/eta.php:70-74`, `signing.enabled` defaults false). | 🔑 | M |
| **ETA EGS codes + issuer identity** | Register real EGS item codes (base_rent, service_charge, utility, parking, percentage_rent) + issuer TRN/legal name/address. Placeholders still ship (`config/eta.php:36-46` — issuer TRN `100000000`; `:55-62` — EGS `EG-6820-001`). Wrong codes ⇒ rejection. All env-driven, no code change. | 🔑 | S |
| **Flip ETA out of mock mode** | `EtaSettings.php:16` `$mock = true` submits to a fake endpoint — no legally-binding invoices. One-line flip, gated on the two rows above. | 🧑‍💻 | S |
| **Paymob live cutover (KYC + live creds)** | Sandbox fully integrated and certified; no code changes. Operator completes KYC, re-issues all 4 live credentials, re-registers callbacks on the prod domain, runs one small real charge (`PAYMOB-SETUP.md §6`). | 🔑 | S |
| **Database backups** | Documented only (`docs/INFRASTRUCTURE.md:267-286`) — no `spatie/laravel-backup`, nothing in `scripts/`, and **no deploy workflow exists at all**: `.github/workflows/ci.yml:6-11` is the only one and its triggers are commented out. Catastrophic data-loss risk for an ERP holding money, tax and contracts. | ⚙️ | S |
| **Error tracking + centralized logging** | No Sentry/Flare/APM in `composer.json`. The `ops` channel exists but `OPS_LOG_STACK=ops_daily` → **disk-only**; `slack`/`papertrail` stanzas are unconfigured boilerplate. **This is a multiplier:** failed-job alerting, ETA retry alerting and Paymob observability all terminate in a local file — every "we log it loudly" claim is true only for someone SSH'd into the box. `.env.example:82` documents the one-line fix (`OPS_LOG_STACK="ops_daily,slack"`). | ⚙️/🧑‍💻 | M |
| **Rotate the seeded demo password** | Parametrized (`DemoSeeder.php:91`) but the default is still `password` and `.env.example:14` ships it. Now a deploy action, not a build task — rotate/delete demo accounts before the URL is shareable. | ⚙️ | S |
| **Email (SMTP) cutover** | `MAIL_MAILER=log` — invoice/payment/maintenance mail reaches nobody. Operator supplies SMTP host/creds/from-address + SPF/DKIM. | 🔑 | S |
| **`integrations:check` preflight** | Run `php artisan integrations:check` after the live `.env` swap to validate Paymob + ETA creds before the first real charge. Command is built and exits non-zero on failure. | ⚙️ | S |

---

## 3. 🟠 P1 — security & operability

Ordered by real risk, not by age.

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| **Throttle `/admin` + `/portal` login** ⚠️ | **The sharpest live gap.** Neither panel stack has any `throttle:` (`AdminPanelProvider.php:122-133`, `PortalPanelProvider.php:59-70`) — unlimited password guessing against every staff and tenant account. `/pay/*` and the API *are* throttled, which creates a convincing illusion of coverage. Note `danharrin/livewire-rate-limiting` is **not** in `composer.json` (the old roadmap wrongly assumed it was installed). | 🧑‍💻 | M |
| **Lease/Tenant media land on the `public` disk** ⚠️ | `Lease` and `Tenant` are `HasMedia` but register **no collection**, so uploads fall back to the medialibrary default disk `public` (no `config/media-library.php`, no `MEDIA_DISK`). **Signed contracts and tenant tax IDs sit at guessable, unauthenticated URLs** — directly contradicting the project's own rule at `TenantRequest.php:138-142`, which correctly pins `useDisk('local')`. | 🧑‍💻 | S |
| **Ungated portal write actions** ⚠️ | `Portal/.../ViewMaintenanceRequest.php:29-58` (addComment) and `:60-76` (cancel) have **no `isAdmin()` check at all**; `ViewInvoice.php:35-87` (payNow/payDemo) are `visible()`-only, which is **not** a dispatch gate in Filament v4. Read-only `TenantUser`s can drive writes. `TenantUserGatingTest` only asserts `canCreate()` return values and never dispatches. Blast radius is within-tenant. | 🧑‍💻 | M |
| **Role grants are unaudited** | `User::getActivitylogOptions()` exists but `logOnly(['name','email','email_verified_at'])` is column-only — roles are a pivot write (`UserForm.php:43-45`) with no `activity()` call. **Privilege escalation leaves no trail.** | 🧑‍💻 | S |
| **Failed-jobs + scheduler monitoring** | Prose only (`PRODUCTION-RUNBOOK.md:104`). No alert, dashboard, health script, or dead-cron detection. A silently-dead cron means tenants go unbilled and tax submissions are missed for days. Depends on the P0 logging row. | ⚙️ | S |
| **Health check that checks something** | `bootstrap/app.php:13` `health: '/up'` is the stock default — no DB/queue/cache probe, no uptime monitor. A false 200 masks a DB outage. | ⚙️ | M |
| **SLA scans emit nothing** | The other three batch runs log summaries (`MonthlyBillingService.php:120`, `LateFeeService.php:53`, `CamReconciliationService.php:167`); both SLA scans use `$this->info()` only (`ScanWorkOrderSlaBreachesCommand.php:76`, `ScanMaintenanceSlaBreachesCommand.php:87`) — under `schedule:run` that output goes nowhere. Now that penalties are real money, a silent scan is a money problem. | 🧑‍💻 | S |
| **Enforce 2FA on write roles** | Mechanism built (`config/security.php:19-22`) but ships `SECURITY_FORCE_2FA_ROLES=super_admin` — manager/accounting/leasing/operations/hr handle payments and tenant changes without it. The decision was deferred to an env var nobody set. | ⚙️ | S |
| **Production env defaults** | Keys + guidance present, dev defaults still ship: `APP_TIMEZONE=UTC` (schedules fire at the wrong wall-clock — must be `Africa/Cairo`), `LOG_LEVEL=debug` (leaks SQL/PII, fills disk). No logrotate guidance. | ⚙️ | S |
| **App-level HTTPS forcing** | Headers are done and tested (`SecurityHeaders.php` — XFO/nosniff/Referrer-Policy/HSTS + a strict CSP on `/pay/*`). **Missing: no `forceScheme`/`forceHttps` anywhere** — HTTPS relies entirely on the proxy. | 🧑‍💻 | S |
| **Paymob credential vaulting + HMAC rotation** | Live keys in plaintext `.env` (`config/integrations.php:29-32`), no vault, no rotation procedure. A leaked HMAC lets an attacker forge "paid" callbacks. | ⚙️ | M |
| **ETA receiver address per tenant** | `EtaJsonBuilder.php:46-47` hardcodes governate/city to Giza / 6 October — wrong buyer address on real invoices. Blocked on schema: tenants have only a freeform `text('address')`. | 🧑‍💻 | M |
| **ETA retry policy is untested** | `$tries=3` + backoff + `OpsLog::error('eta.job_exhausted')` are correct, but **no test asserts `$tries`/`backoff()`/`failed()`** — the policy protecting tax submissions is unverified. | 🧑‍💻 | S |
| **`PaymobPaymentInitiator` has no logging** | `PaymobClient` and the callback controller now log; the initiator still has zero. Missing tests: expired-token, concurrent attempts, and **there is no payment-link E2E spec** (`tests/e2e/13-*` is `13-eta.spec.js`). This is the public revenue surface. | 🧑‍💻 | M |
| **Ops alerts are bell-only** | Push exists and 8 notifications use mail+database+push, but 7 remain database-only — including exactly the ones you'd want off-app: `MaintenanceSlaBreached`, `WorkOrderSlaBreached`, `LedgerSyncFailed`. | 🧑‍💻 | M |
| **Mobile (Flutter) app v1** | API complete and the password-reset flow is now unified across admin/portal/API. The app repo itself is external. | 🔑 | L |

---

## 4. 🟠 P1 — Eltizam FRD expansion (facility management)

The FRD turns Atriom from a leasing/billing ERP into a facility-management system.
~60 requirements: 12 existed, 15 partial, 33 missing — clustering into four architectural
holes rather than scattered features. Full detail + the decisions taken:
`~/.claude/plans/happy-percolating-pearl.md`.

| Phase | Scope | State |
| --- | --- | --- |
| 0 | Security fixes found during exploration (ungated `ImportAction` on Tenants/Leases) | 🟡 partly folded into §3 |
| 1 | **Equipment register** — `parent_id` sub-code tree, per-property unique codes | ✅ shipped |
| 2 | **Module 26 becomes the internal work-order system** — service + state machine, pass/fail checklist gate, plans (routine/fixed), CM internal-vs-external, per-property SLA, SLA clock on acceptance, follow-up chains | ✅ shipped |
| 3 | **Approval engine** — single approver resolved by amount (module 28) | 🟡 shipped for inventory draws only; **no admin UI to edit the ladder**, and one call site (`WorkOrderPartsRelationManager.php:71`) despite FR-PROC-02 implying procurement scope |
| 7 | **SLA penalties** → vendor bill deduction → GL | ✅ shipped; the GL half was **broken and is now fixed + gated** (2026-07-16) |
| 4 | **Procurement** — purchase requests → approval → order → goods receipt → stock-in | ⬜ next. Also the seam that fixes a **pre-existing GL defect**: receipts pass only a free-text reference, so GRNI `21701001` accumulates and is never cleared (`InventoryMovementJournalizer.php:22-24`) |
| 5 | **Inventory & warehousing** — per-mall low-stock (the portfolio-wide on-hand sum currently shows green for a mall that is out of a part), bins, transfers, `part_source` | ⬜ **transfers are a trap:** `transfer_in`/`transfer_out` exist as enum values, constants, sign-normalisation and translations — but **nothing creates them**. Dead code that looks shipped. |
| 6 | **Fault attribution & recharge** — `fault_party`/`cost_bearer` → tenant recharge | ⬜ **largest commercial exposure in the FRD.** No `Invoice`/`InvoiceItem` references any request; consumed parts are GL cost only and are never rechargeable to the tenant who caused the damage. Must respect `Invoice::recomputeTotals()`. |
| 8 | **Roles & record-level scoping** — `mall_admin`/`coordinator`/`technician`/`customer_service`; per-user record filtering (a new primitive — scoping is property-level only today); export gating; evidence-before-completion | ⬜ Includes the real dashboard bug: **`accounting` sees an empty dashboard** — the role owning invoices, payments, AR and the GL is in no widget's `allowedRoles()`, contradicting FR-DASH-02 |
| 9 | **Intake, areas, permits, violations** — unknown-caller intake, area↔supervisor routing, fit-out permits, violation fines | ⬜ |
| 10 | **POS seam & reporting** — POS adapter + CSV, declared-vs-POS variance, weekly report, workflow visualization | ⬜ Sales are 100% manual twice over today |

**Deferred — needs a finance workshop first:** FR-FIN-06..09, the Jawad/Eltizam revenue
split. It needs legal entities, issuer-vs-payer separation, effective-dated split rules, a
remittance ledger and per-entity VAT; it touches every journalizer and ETA's **single
hardcoded issuer TRN**, which cannot express two entities. It also constrains ETA go-live.

**Eight client questions** are open at the end of the plan file. The one that can't be
inferred at all: **FR-REQ-01 "delegation (from/to)"** — no such concept exists anywhere.

---

## 5. 🟡 P2 — accounting, product polish, scale

### Accounting (detail in [accounting/GAP-ANALYSIS.md](accounting/GAP-ANALYSIS.md))

The core is production-grade: document → balanced entry → trial balance → statements →
close, self-healing, tie-out gated, audit-logged. **The honest remaining set is four
additive items** — none of which make today's books wrong.

| Item | Why | Needs accountant? |
| --- | --- | --- |
| **Opening balances tool** | Load the current position at go-live. Manual journals work; no guided importer. | Yes, if migrating |
| **VAT return (الإقرار الضريبي)** | The periodic *filing report* — distinct from ETA submission, which is built | Yes |
| **Bank reconciliation** | No statement import/matching exists | Yes (bank feed?) |
| **Comparative statements** | Every statement is single-period; owners expect vs-last-year | No |
| Inter-property due-to/due-from | Exact per-property split of shared payments | No |

### Product

| Item | What & why |
| --- | --- |
| **Owner portal is off** | `config/features.php:19` is `false`, absent from `.env`, and forced `true` only in `phpunit.xml:52` — **the Owner panel executes only under test**, so its green tests prove nothing about the shipped default. Needs a product call, a per-property dashboard (today: portfolio roll-up only), and global search (Owner has none, so its search box returns nothing, ever). |
| **Credit notes absent from the portal** | Admin-only (`CreditNoteResource.php:23`) though the API already exposes them per-tenant — a missing resource, not missing data. CAM breakdown *has* shipped on both panels. |
| **Tenant self-service profile** | Portal profile is stock `TenantUser` name/email/password. Nothing writes to `Tenant` (phone/whatsapp/address are fillable with no surface). **Bank details aren't in the schema at all.** |
| **Dashboard drilldowns** | ETA tiles are all clickable; AR-aging/tenant-mix/top-tenants are static. `ReportService::arAgingDrilldown()` already works but is orphaned from the chart. |
| **AR-aging widget vs page RBAC** | The pages gate on `reports.view`; the widget gates on *roles* — revoking `reports.view` closes the page but leaves the dashboard chart visible. |
| **Period exports for the accountant** | Reports are PDF-only and monthly-only; no CSV/Excel, no quarterly. |
| **Bulk actions** | Only `bulkSubmitToEta` exists; maintenance bulk is delete/restore only. |
| **WhatsApp** | A stub that flashes a toast. High-value in Egypt; needs a Business API client. Apple Pay is more built than the old roadmap claimed — only provisioning is outstanding. |
| **Session encryption + explicit CORS** | `SESSION_ENCRYPT=false`; **no `config/cors.php`** → framework default `allowed_origins: ['*']` on `/api/*`. |

### Scale

| Item | What & why |
| --- | --- |
| **Report caching + widget N+1** | Zero caching in `ReportService`; `Pages/Reports.php:78-88` recomputes per render on a live model. `TopTenants` N+1s on sales density; `Owner/PropertiesTable` runs ~6 COUNTs per row. Defer until the first scale event. |
| **Gap-analyse modules 21–28** | The blind spot named in §1. Every GL-posting module is in it. |

---

## 6. Retired rows — do not rebuild

Verified 2026-07-16. These were on the old roadmap and are **done, or were never true**.
Acting on the first one would actively reintroduce a bug.

- ❌ **"MallStats MRR relabel"** — **do not do this.** It was fixed by correcting the *data*
  (`MallStats.php:60-62` now sums contractual rent from active leases), so the "Monthly
  Recurring Revenue" label is accurate. Relabelling to "Billed this month" would restore the
  bug it was meant to fix.
- ❌ **"`danharrin/livewire-rate-limiting` is in composer but unused"** — it was never in
  `composer.json`. The throttling work in §3 starts from zero.
- ❌ **"`PaymentLinkFlowTest` is happy-path only"** — it already covers unknown-token,
  settled, and gateway-down.
- ❌ **"`CreditNoteService` misses locked-declaration + resubmission edge cases"** — the
  locked-declaration path isn't on that service's surface (it's covered in
  `PercentageRentVoidLockedTest`), and "resubmission" has **zero hits** in `app/` or `tests/`.
- ✅ **Shipped:** Sanctum token TTL · admin self-service profile + password reset · async
  exporters (all 5 config-driven) · every "missing" DB index · the Lease↔Charge rent-change
  sync (guarded action + service + tests) · payment over-allocation row-locked backstop +
  concurrency test · queue worker + scheduler deploy docs · `integrations:check` ·
  sales-declaration lock notification · invoice PDF attached to email · rate-limit
  tightening + token entropy (`Str::random(48)` ≈286 bits) · `OwnerRequestService` /
  `MarketingLevyService` / `CreditNote` void-edge-case tests · document attachments ·
  CAM `--auto-bill` (code; the decision is the operator's) · security headers + `/pay` CSP.

> **Trap for the unwary:** `app/Mail/InvoiceIssued.php` has no PDF attachment but is **dead
> code** — the live path is `InvoiceIssuedNotification.php:34-37`, which does attach. Grepping
> the mailable gives a false negative.

---

## 7. Recommended next three (code side)

1. **Close the three ⚠️ security rows in §3** — login throttling, the `public`-disk media
   fallback, and the ungated portal actions. All are live, all are code-only, and the media
   one exposes signed contracts at unauthenticated URLs today.
2. **Wire centralized logging + error tracking** (P0). One env change plus a Sentry hook
   retroactively upgrades failed-job alerting, ETA retry alerting and Paymob observability —
   all of which currently log to a file nobody reads.
3. **FRD Phase 4 (procurement)** — the next FRD phase, and the seam that clears the GRNI
   account that inventory receipts have been silently accumulating.

*Keep this file current: when something ships, move it to §6 rather than deleting it — the
retired list is what stops the next person rebuilding a thing that already works.*
