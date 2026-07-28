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
  manifest, media privacy, and (as of 2026-07-16) the GL registry. Where a gate exists, drift
  fails the suite. Where one doesn't, drift ships: that is precisely how an applied SLA
  penalty came to reduce a vendor bill while posting nothing to the ledger.
  **CI auto-runs are ON** (§2, re-enabled 2026-07-18), so those gates now fail the check and
  block a merge — not only when someone runs `pest --parallel` locally.

---

## 2. 🔴 P0 — blocks go-live

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| ~~Decide: is CI a gate or a suggestion?~~ | ✅ **CI is a gate — re-enabled 2026-07-29** after a three-day pause (2026-07-26 → 07-29). `.github/workflows/ci.yml` fires on `push` + `pull_request` to `main`/`develop` plus `workflow_dispatch`; `composer audit`, PHPUnit, PHPStan and Playwright all block a merge, and the five conformance gates (PropertyIsolation, GlRegistry, MediaPrivacy, AdminSmokeManifest, DashboardLayout) enforce inside the test job. *Why the pause ended:* while auto-runs were off, a dashboard widget carrying no authorization gate shipped and published the property's receivables to the HR and marketing dashboards — exactly the class those gates exist to catch. An earlier pause had likewise let PHPStan drift ~208 errors above baseline. **A gate rots precisely when it is not enforced.** | ⚙️ | — |
| **Keep `composer audit` green** | Now a CI job. Its first run (2026-07-16) found **22 advisories across 12 packages**, incl. two HIGH: Filament **MFA recovery codes reusable via concurrent submission**, and a medialibrary **file-upload restriction bypass** — plus a Filament **scope-enforcement** CVE landing directly on this project's property-isolation invariant. All fixed inside existing constraints (Filament 4.11.3→4.11.8, Laravel 13.11.2→13.20.0, medialibrary 11.22.1→11.23.2); 2283 tests green. Nobody knew because nothing looked — and a CVE lands with no change to your code, so only a scheduled check finds it. **This is the row most likely to bite again if CI stays manual.** | ⚙️ | S |
| **ETA live credentials + signing certificate** | Real `client_id`/`client_secret` from the operator's ETA taxpayer profile **and** a CAdES signing certificate. ETA production **rejects unsigned B2B documents**. The pluggable signer seam + refuse-to-submit guard are ready (`config/eta.php:70-74`, `signing.enabled` defaults false). | 🔑 | M |
| **ETA EGS codes + issuer identity** | Register real EGS item codes (base_rent, service_charge, utility, parking, percentage_rent) + issuer TRN/legal name/address. Placeholders still ship (`config/eta.php:36-46` — issuer TRN `100000000`; `:55-62` — EGS `EG-6820-001`). Wrong codes ⇒ rejection. All env-driven, no code change. | 🔑 | S |
| **Flip ETA out of mock mode** | `EtaSettings.php:16` `$mock = true` submits to a fake endpoint — no legally-binding invoices. One-line flip, gated on the two rows above. | 🧑‍💻 | S |
| **Paymob live cutover (KYC + live creds)** | Sandbox fully integrated and certified; no code changes. Operator completes KYC, re-issues all 4 live credentials, re-registers callbacks on the prod domain, runs one small real charge (`PAYMOB-SETUP.md §6`). | 🔑 | S |
| **Database backups** | 🟡 **In-app half done 2026-07-29** — `spatie/laravel-backup` runs nightly from the **scheduler** (backups belong to runtime, not to a build): `backup:clean` 01:00 → `backup:run` 01:15 → `backup:monitor` 07:30. Archives hold the DB plus `storage/app` (signed leases, tax cards, vendor documents, branding) and land on a dedicated git-ignored `backups` disk outside the backup source. `backup:monitor` is the dead-cron detector: it fails when the newest archive is older than a day. Guarded by `tests/Feature/BackupConfigurationTest.php`. **Still operator work:** set `BACKUP_DISKS="backups,s3"` (a copy on the same box as the DB dies with the box), `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_ALERT_EMAIL` — and **test a restore**, which no code can assert for you. There is still **no deploy workflow**. | ⚙️/🔑 | S |
| **Turn on the alerting you already have** | Code is **done** (2026-07-16); this is now two env vars. **Sentry** is wired and inert until `SENTRY_LARAVEL_DSN` is set (PII withheld — `send_default_pii=false` + a `before_send` reusing OpsLog's redaction; self-hostable if the data must stay in-country). **Slack** alerting needs `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`; the threshold is now `LOG_SLACK_LEVEL` (default `error`), decoupled from `LOG_LEVEL` so it can't page on every routine warning. Until both are set, every money/integration failure is visible only to someone SSH'd into the box. | ⚙️/🔑 | S |
| **Rotate the seeded demo password** | Parametrized (`DemoSeeder.php:91`) but the default is still `password` and `.env.example:14` ships it. Now a deploy action, not a build task — rotate/delete demo accounts before the URL is shareable. | ⚙️ | S |
| ~~Email (SMTP) cutover~~ | ✅ **Done 2026-07-24 — and not over SMTP.** Outbound mail runs on MailerSend's HTTPS API (`mailersend/laravel-driver`, `MAIL_MAILER=mailersend`) from the verified domain `tri-tech.net`: no SMTP egress rules, no long-lived socket, sending-scoped token. A real test message was delivered end-to-end. Two operator steps remain, both outside the code: **get the MailerSend account approved** (trial plans cap *unique recipients* — a second address 422s with `#MS42225`), and keep SPF/DKIM published for whichever domain production sends from. Preflight `integrations:check --mail` (sends nothing), live send `mail:test <inbox>`. `MAIL_ALWAYS_TO` protects non-production from the fake `@*.test` demo addresses. | 🔑 | — |
| **`integrations:check` preflight** | Run `php artisan integrations:check` after the live `.env` swap to validate Paymob + ETA creds before the first real charge. Command is built and exits non-zero on failure. | ⚙️ | S |

---

## 3. 🟠 P1 — security & operability

Ordered by real risk, not by age.

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| **Failed-jobs + scheduler monitoring** | Prose only (`PRODUCTION-RUNBOOK.md:104`). No alert, dashboard, health script, or dead-cron detection. A silently-dead cron means tenants go unbilled and tax submissions are missed for days. Depends on the P0 logging row. | ⚙️ | S |
| **Health check that checks something** | `bootstrap/app.php:13` `health: '/up'` is the stock default — no DB/queue/cache probe, no uptime monitor. A false 200 masks a DB outage. | ⚙️ | M |
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
| 3 | **Approval engine** — single approver resolved by amount (module 28) | 🟡 shipped (`5f1a679`) + wired to work-order spare parts (`01ea84a`, FR-CM-09/10/11). Remaining: **no admin UI to edit the ladder** (`ApprovalRule` is seeded/DB-only), and procurement is still to come |
| 7 | **SLA penalties** → vendor bill deduction → GL | ✅ shipped; the GL half was **broken and is now fixed + gated** (2026-07-16) |
| 4 | **Procurement** — purchase requests → approval → order → goods receipt → stock-in | ⬜ next. Also the seam that fixes a **pre-existing GL defect**: receipts pass only a free-text reference, so GRNI `21701001` accumulates and is never cleared (`InventoryMovementJournalizer.php:22-24`) |
| 5 | **Inventory & warehousing** — low-stock alerts, bins, transfers | 🟡 `part_source` internal/external + the parts draw shipped (`01ea84a`). **Remaining, and the order matters:** fix the portfolio-wide on-hand sum FIRST (`InventoryItemResource.php:86` sums every mall together, so the reorder colour shows **green for a mall that is out of a part**) — low-stock alerts built on top of it would be wrong. Then bins. **Transfers are a trap:** `transfer_in`/`transfer_out` exist as enum values, constants, sign-normalisation and translations — but **nothing creates them**. Dead code that looks shipped. |
| 6 | **Fault attribution** — `fault_party`/`cost_bearer` on the work order | ✅ shipped `e4011f6`, **record-only** — and that is the correct scope. The plan called for a "→ Charge/InvoiceItem recharge path"; re-reading the source FRD, **FR-CM-12/13 say "determine" and "record"**. Nothing in the FRD asks the system to invoice or recharge a tenant, and its own Open Items never raise it. The recharge was an invention of the plan, not a requirement — building it would have billed real tenants on an assumption. **Do not "finish" this by adding recharge without the client asking.** |
| 8 | **Roles & record-level scoping** — `mall_admin`/`coordinator`/`technician`/`customer_service`; per-user record filtering; export gating; evidence-before-completion | ✅ **shipped.** All four FR-USR roles exist; per-user assignment scoping (`AssignmentScope`, `6e9fc97`); the `accounting`-empty-dashboard bug fixed (`9e24649`); request-side evidence (`f9d53c3`); coordinator + customer_service roles + gated request-queue export (`cb1b7aa`+). **WO-side evidence deferred** — the checklist gate (FR-PPM-07) already satisfies FR-USR-06; requiring a photo *on top* is open **client question E.4**, not a build. |
| 9 | **Intake, areas, permits, violations** — unknown-caller intake, area↔supervisor routing, fit-out permits, violation fines | 🟡 **in progress.** ✅ **unknown-caller intake** (staff can log a request from an unregistered caller; model-enforced across admin/portal/API). ✅ **areas + area→supervisor routing** (module 30: supervised zones + `area_id` on units/requests + a supervisor notification alongside department routing; each slice adversarially reviewed, one cross-property unit-zone leak caught + fixed). ⬜ **fit-out permits** + ⬜ **violation fines** remain. *(A separate deferred slice: give `TenantRequest` its own `asset_id` to allow truly unit-less common-area requests — an invariant refactor, not yet done.)* |
| 10 | **POS seam & reporting** — POS adapter + CSV, declared-vs-POS variance, weekly report, workflow visualization | ⬜ Sales are 100% manual twice over today |

**Deferred — needs a finance workshop first:** FR-FIN-06..09, the Jawad/Eltizam revenue
split. It needs legal entities, issuer-vs-payer separation, effective-dated split rules, a
remittance ledger and per-entity VAT; it touches every journalizer and ETA's **single
hardcoded issuer TRN**, which cannot express two entities. It also constrains ETA go-live.

**Eight client questions** are open at the end of the plan file. The one that can't be
inferred at all: **FR-REQ-01 "delegation (from/to)"** — no such concept exists anywhere.

---

## 5. 🟡 P2 — accounting, product polish, scale

### Generic-ERP parity — the Egyptian statutory floor (from the [Odoo gap analysis](gap-analysis/odoo/README.md))

A 2026-07-18 comparison of Atriom's generic modules against Odoo Community + Enterprise
found that Atriom **matches or exceeds Odoo on the property-fit modules** (it ships
Enterprise-tier capabilities — depreciation, payslips+GL, perpetual costing, GRNI — that
Odoo Community lacks, plus عهدة, which Odoo doesn't model at all). The genuine gaps cluster
into **three that a mall operator's accountant/auditor will actually raise** — treat these
as one workstream, separate from the FRD:

| Gap | Why it matters | Domain |
| --- | --- | --- |
| **Bank reconciliation** | No statement import/matching *anywhere* — cash/bank GL is asserted, never verified against a bank statement. Surfaced in two domains independently; the #1 control gap. | [Accounting](gap-analysis/odoo/01-accounting.md) + [Treasury](gap-analysis/odoo/06-treasury.md) |
| **Egyptian tax depreciation (declining-balance) + a second tax book** | Depreciation is straight-line only, but Egyptian income tax (Law 91/2005) is pool-based diminishing-value — so no tax-basis figure can be produced at all. | [Fixed Assets](gap-analysis/odoo/04-fixed-assets.md) |
| **Employer social insurance + end-of-service gratuity** | *Correctness*, not features: payroll posts only the withheld employee side, so the employer contribution and accruing gratuity are never expensed/accrued — the books **understate labour cost and liabilities today**. | [HR/Payroll](gap-analysis/odoo/05-hr-payroll.md) |

The 🟡 tail (worth doing, not urgent): ~~per-property year-end close (F-80)~~ ✅ **done 2026-07-19**
(per-asset closing entries — the owner-statements prerequisite),
VAT-return report, comparative statements, weighted-average inventory costing, reorder
auto-purchase, finishing the dead transfer stub, capex bid-comparison, statutory rate
automation. The **⏭️ declined** set (multi-currency, consolidation, drop-ship, Odoo's full
salary-rule engine) is Odoo *breadth* that's either N/A to a single-entity EGP operator or
Enterprise-gated — don't mistake it for a backlog.

### Property + Facility depth — the moat (from the [competitive gap analysis](gap-analysis/competitors/README.md))

A 2026-07-18 comparison of Atriom's *property* and *facility* modules against the software a
mall operator actually shortlists (**Yardi Voyager, Re-Leased, AppFolio** on the property side;
**Facilio, ServiceChannel, IBM Maximo, Fiix, MaintainX/Limble** on facility) found the moat
**real but lopsided**: Atriom is at or above the field on billing correctness, the double-entry
GL *under* the property engine, per-property isolation, the SLA-penalty-to-AP move, and Egyptian
VAT/ETA fit — and genuinely behind on lease-admin *breadth*, the *owner deliverable*, and the
field-tech/vendor edge. Reviewed with the operator 2026-07-18; the strengthen list below is the
decision, ordered.

| # | Strengthen | Why it's real for this operator | Domain | Effort |
| --- | --- | --- | --- | --- |
| ~~**1**~~ | ~~**Owner statements + disbursements**~~ ✅ **SHIPPED (2026-07-19, module [32](modules/32-owner-statements.md))** | The operator-for-owner deliverable: per-property owner statement (income − expenses = net, reused from the GL) → finalise accrues Dr Owner Distributions / Cr Due to Owner → disbursement clears it against the bank. Two GL sources tie-out-verified; **F-80 fixed** as a prerequisite; the PortfolioStats `ownership_percentage` bug fixed. Bilingual PDF + owner bell. v1 defers the management fee + co-owner split (operator calls). | [03](gap-analysis/competitors/03-deposits-utilities-portal-owner.md) | L |
| **2** | **CAM recovery-clause engine** | Caps, the routine 10–15% CAM **admin fee** (real bookable landlord revenue Atriom can't charge), gross-up-to-occupancy, pool exclusions, configurable basis. `cap_amount`/`exclusions` sit on `CamAllocation` unread; basis is hard-coded to occupied leased area. Yardi's crown jewel. | [02](gap-analysis/competitors/02-cam-turnover-rent.md) | L |
| ~~**3**~~ | ~~**Automated rent escalation**~~ ✅ **SHIPPED (2026-07-19)** | `RentEscalationService` + `leases:apply-escalations` (scheduled daily): a lock-safe, idempotent sweep over active `fixed_percent` leases with `next_escalation_date ≤ today` — applies the increase through `LeaseRentChangeService` (base-rent Charge + marketing levy synced) and rolls the date forward a year. **CPI is skipped** (no index feed — inventing a number would be inventing data). | [01](gap-analysis/competitors/01-lease-billing.md) | M |
| ~~**4**~~ | ~~**PDC (post-dated cheque) register + lifecycle**~~ ✅ **SHIPPED (2026-07-19, module [33](modules/33-post-dated-cheques.md))** | Forward-cheque register + maturity schedule + bounce lifecycle (held → deposited → cleared/bounced; cancel). **v1 register-only, settle-on-clear**: the invoice stays open until the cheque clears, when a cheque Payment is recorded (AR via `recomputeTotals`, allocation capped at balance) — no AR-invariant risk. `pdc:scan-maturing` daily. Notes-Receivable-on-receipt accrual deferred to the accountant. | [03](gap-analysis/competitors/03-deposits-utilities-portal-owner.md) | M |
| ~~**5**~~ | ~~**Vendor compliance / COI gate**~~ ✅ **SHIPPED (2026-07-19)** | Vendors now carry `coi_expires_at`/`insurer`/`policy_number` + a private COI document; `Vendor::isDispatchable()`/`scopeAssignable()` (active + COI not lapsed). The `MaintenanceWorkOrder::saving()` hook **blocks dispatching a blacklisted/inactive or lapsed-COI vendor** (the single server-side gate, assignment-time only so existing orders aren't retroactively broken) and all three module-26 vendor pickers filter to the assignable set. A COI-status column/badge surfaces expiry. | [06](gap-analysis/competitors/06-vendors-areas-permits-violations.md) | M |

**The 🟡 tail from the same analysis** (real, lower): meter/usage-based PM triggers, vendor
scorecards, asset criticality (one field, high leverage), fit-out-permit approval workflow,
reorder-driven auto-purchase, bill-the-violation-fine-to-AR, utility tariff/recharge automation,
lease document generation + e-sign, first-class rent-free/stepped schedules, annual/YTD turnover
breakpoints, the deposit-balance/reconciliation layer. **The field-technician mobile app** is a
real facility gap but sits with the external mobile-app work (§3, 🔑 L).

**⏭️ Declined — explicitly, so nobody mistakes the specialists' breadth for a backlog:**
IoT/BMS + predictive maintenance, automated POS feeds, a vendor marketplace/network, the full
Maximo-grade reliability-analytics suite, office-lease base-year CAM, interest-bearing/trust-segregated
deposit accounts, skills/geo load-balanced dispatch, multi-currency/consolidation. Same discipline
as the Odoo verdict: **keep layering depth onto the property + facility + Egyptian-books spine; do
not grow sideways toward every-industry breadth.**

**De-risking (not features), belongs near the top regardless of band:** certify ETA + Paymob out
of mock/sandbox (§2 already tracks these) and close the blacklisted-vendor dispatch gate (row 5).
They make a moat that *already exists* actually usable/safe.

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
| **Settings-driven Egyptian tax catalog** | Operator request (2026-07-18/19): make **all** Egyptian taxes configurable as settings/dropdown selections rather than hardcoded (today VAT 14% + marketing levy 5% are constants). The operator supplied the concrete ETA code set (VAT 14%/0%/exempt · Stamp 20% · Schedule 0.5–30% · Withholding −0.5 to −5%, Sales+Purchases each) — captured verbatim as the source spec in [docs/accounting/EGYPTIAN-TAX-CATALOG.md](accounting/EGYPTIAN-TAX-CATALOG.md). A tax catalog (name, family, direction, signed rate, invoice label, active) selected on charges/fees, GL-wired via `AccountMapping`. Confirm treatment (esp. withholding + stamp) with the accountant first. Unblocks the deferred **owner-statement management fee** (its VAT-on-fee toggle would draw from this). |
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

> ### ⚠️ How to read a "this is missing" row — read this before acting on §3
>
> Of the first four rows anyone actually verified, **two were false**, and both had the same
> shape: *"I grepped for mechanism M in file F, didn't find it, therefore it's missing."*
> Both times the codebase solved the problem — correctly — somewhere else:
>
> | Claim | Reality |
> |---|---|
> | "`/admin` + `/portal` login are unthrottled" | Throttled in the **Livewire component**, not route middleware |
> | "Role grants are unaudited" | Audited by `AccessControlAudit` in **`app/Support/`**, not on the model |
>
> The rows that proved **true** had the opposite shape — *"here is code doing the wrong
> thing"* (media on the public disk; `visible()` used as a gate). Both were exploitable and
> were exploited before being fixed.
>
> **So: absence-of-mechanism claims here are unreliable; presence-of-bad-code claims are
> reliable.** Before building anything from §3, go find the mechanism first — it may already
> exist under a different name, in a different layer. It cost two false priorities to learn
> this; don't pay for it twice.

- ❌ **"MallStats MRR relabel"** — **do not do this.** It was fixed by correcting the *data*
  (`MallStats.php:60-62` now sums contractual rent from active leases), so the "Monthly
  Recurring Revenue" label is accurate. Relabelling to "Billed this month" would restore the
  bug it was meant to fix.
- ❌ **"`/admin` + `/portal` login are unthrottled"** — **false, and it was briefly the top row
  of this file (2026-07-16).** Both panels throttle at **5 attempts / 60s per IP**:
  `Filament\Auth\Pages\Login` uses `WithRateLimiting` and calls `rateLimit(5)` as the first
  statement of `authenticate()`, and both panels use that page via `->login()`. The audit
  that reported it grepped the panels' **route middleware** for `throttle:` — but the throttle
  is in the Livewire component, not the route. Now pinned empirically by
  `tests/Feature/Regression/PanelLoginThrottleTest.php` so nobody re-derives the false version.
- ❌ **"Role grants are unaudited / privilege escalation leaves no trail"** — **false, and it
  was briefly a ⚠️ row in §3 (2026-07-16).** Role *and* permission grants/revokes have been
  audited since **2026-06-28** (`2a27946` → `f244d87`, hardened again in `b165dde`) via
  `App\Support\AccessControlAudit`, with **20 passing tests** in
  `tests/Feature/AccessControlAuditTest.php` covering the User form, department membership,
  relation-manager attach/detach, role deletion as a mass revoke, and correctly *not* logging
  during seeding. The audit that reported it read `User::getActivitylogOptions()`, saw
  `logOnly(['name','email',…])`, and stopped. `AccessControlAudit`'s own docblock explains why
  it diffs at each UI mutation point instead: **Filament's roles Select saves through the raw
  belongsToMany pivot, which fires no spatie event**, and spatie's events carry the full
  requested set rather than the delta, so an event listener would log phantom grants on every
  idempotent re-assign. The model-level approach the row implied would have been *worse* than
  what exists.
- ❌ **"`danharrin/livewire-rate-limiting` is in composer but unused"** — it *is* installed and
  *is* used (see above). It's a transitive dependency of Filament, so it appears in
  `composer.lock` but not in `composer.json`'s require block — which is what made two separate
  audits, in opposite directions, both get this wrong.
- ❌ **"`PaymentLinkFlowTest` is happy-path only"** — it already covers unknown-token,
  settled, and gateway-down.
- ❌ **"`CreditNoteService` misses locked-declaration + resubmission edge cases"** — the
  locked-declaration path isn't on that service's surface (it's covered in
  `PercentageRentVoidLockedTest`), and "resubmission" has **zero hits** in `app/` or `tests/`.
- ✅ **Fixed 2026-07-16 — SLA scans were silent.** Both scans reported only via
  `$this->info()`/`$this->warn()`, and `routes/console.php` captures no console output, so under
  `schedule:run` it was discarded. `maintenance:scan-wo-sla-breaches` runs **hourly** and
  assesses money: a throw in `AssessSlaPenaltyService` meant **the vendor was never charged**
  and nothing recorded it (per-row containment keeps the command green on purpose). Both now
  emit an `OpsLog::error` per failed row, an `info` summary per run, and a `warning` when a run
  had failures. Guarded by `SlaScanObservabilityTest`, which asserts on the ops channel rather
  than the console — the console is what production throws away.
- ✅ **Fixed 2026-07-16 — the Slack alert threshold was coupled to `LOG_LEVEL`.** The
  "one env change" this roadmap used to recommend would have paged on every routine warning in
  production (`LOG_LEVEL=warning`) and on everything in staging (`debug`). Now `LOG_SLACK_LEVEL`,
  defaulting to `error`.
- ✅ **Fixed 2026-07-16 — `Lease`/`Tenant` media on the `public` disk.** Both models
  implemented `HasMedia` but registered no collection, so `documents` inherited
  medialibrary's `env('MEDIA_DISK', 'public')` default and every signed contract and tax
  card was written to the webroot. Both now pin `useDisk('local')`; `Asset`'s logo/favicon
  state `useDisk('public')` explicitly rather than relying on the same fail-open default.
  Gated by `MediaPrivacyConformanceTest` (every collection must declare a disk; private
  unless allowlisted as branding) + `PrivateDocumentStorageTest`.
- ✅ **Fixed 2026-07-16 — ungated portal write actions.** `visible()` is **not** an
  authorization gate: Filament's `mountAction()` checks `isDisabled()` and never
  `isVisible()`, so a hidden action stays dispatchable. A read-only `TenantUser` could
  cancel and comment on their company's requests (demonstrated, then fixed).
  `addComment`/`cancel`/`payNow`/`payDemo`/`rate` now gate in **both** `visible()` and
  `action()` via `abort_unless(Portal::isAdmin(), 403)` — the pattern `InvoicesTable`
  already used. Guarded by `PortalReadOnlyDispatchGuardTest`, which dispatches through
  `mountAction` because `->callAction()` asserts visibility first and would report the bug
  as fixed while it was still exploitable.
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

## 7. Recommended next

**The code-side observability work is done** — what remains is four env vars an operator sets:
`SENTRY_LARAVEL_DSN`, `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`, and
`APP_TIMEZONE=Africa/Cairo`. Until they're set, every failure path is invisible off-box.

1. **Gap-analyse modules 22–25** — inventory, fixed assets, HR/payroll, treasury. These are
   the remainder of the old "21–28" row: 21, 26, 27, 28 and 29 have since been closed out and
   carry close-out notes in their module docs, but 22–25 have only had a **UX pass**
   (registers/CSV), not a correctness sweep. Every other module that got one produced a real
   money or exposure bug, so this is still the highest expected-value work left.
2. **The public payment surface** — `/pay/{token}` is unauthenticated, internet-facing and
   takes money, yet `PaymobPaymentInitiator` has **zero logging** and there is **no
   payment-link E2E spec at all** (`tests/e2e/13-*` is ETA). Missing tests: expired token,
   concurrent attempts — the one revenue path with no E2E coverage at all.
3. **A health check that checks something, and dead-cron detection** — `bootstrap/app.php`
   still ships the stock `health: '/up'`, which returns 200 with the database down. The scheduler now
   carries 25 entries (billing, GL sync, SLA scans, backups); a silently dead scheduler means
   unbilled tenants and missed tax submissions. `backup:monitor` is the pattern to copy.
4. **The remaining ⚙️ go-live rows** (§2) — backups are now half done in-app (2026-07-29);
   what is left there is off-site copies, an archive password and **a tested restore**. There
   is still **no deploy workflow at all**, which makes several other rows moot.

> Before starting anything from §3, read the warning at the top of §6. Two of the first four
> rows anyone verified were false.

*Keep this file current: when something ships, move it to §6 rather than deleting it — the
retired list is what stops the next person rebuilding a thing that already works.*
