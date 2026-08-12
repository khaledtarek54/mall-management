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

> **🟠 OPEN CYCLE, 2026-08-12 — tax master data, derived fields, configuration, reporting → [§8](#8-cycle--tax-master-data-derived-fields-configuration-reporting-opened-2026-08-12).**
> **TX-01 and TX-02 shipped the same day**: the rate is out of settings and onto a dated ladder,
> seeded from the operator's own tax sheet. **TX-03 shipped too**: a document line now records which
> tax it carried, and the rate is picked rather than typed unless the operator holds
> `tax_codes.override`. **TX-06 shipped too** — the return now separates zero-rated from exempt.
> Next is **TX-04** (AP input tax: `expenses.vat_amount` and `vendor_bills.vat_amount` are still
> money someone keys). **TX-04 shipped too.** Next is **TX-05** (withholding by rate), which retires
> `TaxSettings`' last tax field, and **TX-08** (GL accounts for stamp + schedule).
> Four workstreams, every "today" claim verified against the code with file:line. Headline findings:
> the VAT **rate is typed on the document** and has no effective date (the catalogue governs the
> default, nothing governs the value); a lease's **term and expiry are three independent inputs**
> that can be saved disagreeing; the settings page maps every field twice by hand and **records no
> history of who changed a rate**; and the financial statements have **no drill-down** to the GL or
> the source document. Sequence in [§8.5](#85-suggested-sequence).

> **✅ CYCLE COMPLETE, 2026-08-09 — all 43 stories shipped.** The benchmark cycle below ran to the
> end; every story in [05-user-stories.md](benchmarks/yardi/05-user-stories.md) is ✅, the gap
> analysis is re-verified, and the two live defects named in the original banner were fixed on day
> one. **The one prediction that held throughout:** the defect was state-not-schedule in the LEASING
> model, and the money core did not need rebuilding — everything below the charge row was extended,
> never reopened. What remains is NOT code: 24 percentage-rent leases whose contracts someone must
> read, Jawad's real Egyptian chart of accounts, the VAT questions in
> [BUSINESS-RULES.md](BUSINESS-RULES.md), and running `atriom:audit-charge-schedules` against real
> imported data before go-live. *The original banner is kept below — it records what was expected to
> be hard, which is worth more than a summary written after the fact.*
>
> **⚠️ New cycle, 2026-08-08 — leasing & money flow, benchmarked against Yardi.** The owner asked
> whether the lease → charge → invoice → GL chain is modelled on the wrong business. It is, in
> exactly one place: **Atriom stores the lease's current state and mutates it; Yardi stores a
> date-ranged charge schedule and reads it.** Seven of fifteen benchmark scenarios break on that
> one difference. The money core (`recomputeTotals`, the GL registry, the deletion policy) is at or
> above the benchmark and must **not** be rebuilt. Two live defects were found while writing it:
> **CAM allocates on the master unit's area only** (every multi-unit lease under-charged) and **the
> bulk billing run never prorates** (every mid-month commencement over-charged) — both fix now,
> outside the phases. Full write-up, scenarios, user stories and the sequenced plan:
> **[docs/benchmarks/yardi/](benchmarks/yardi/README.md)** → start at
> [the phase plan](benchmarks/yardi/07-phase-plan.md). **The three open questions are resolved to the
> industry standard** (2026-08-08, owner's instruction — "work as standard systems do"): straight-line
> rent per **EAS 49** gets built but ships **switched off** (single-book; tax follows invoices, the
> accountant flips it); fit-out grace becomes **per-charge, defaulting to rent-only** — *net* abatement
> is the norm and today's gross grace likely gives away ~108k of service charge per new tenant; and
> percentage rent — the cumulative YTD basis **already exists** per lease
> (`percentage_rent_frequency = 'annual'`); the earlier claim that Atriom was period-only was wrong,
> and what remains is a **data audit** of which clauses settle annually (0 of 24 leases are set to it). In each case the standard is
> the default, the alternative is supported, and the lease decides.

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
| **CI runs on demand only** | `.github/workflows/ci.yml` is `workflow_dispatch` only — owner's standing call (the test job needs 20+ min; too slow for the push loop). So `composer audit`, the test suite, PHPStan, Playwright and the five conformance gates (PropertyIsolation, GlRegistry, MediaPrivacy, AdminSmokeManifest, DashboardLayout) are **ADVISORY** — keep `pest --parallel` green locally instead. The jobs themselves were **repaired 2026-07-29** (all four had been failing on every run since ≥2026-07-23 for non-product reasons: `composer audit` missing `--locked` so it audited nothing, a PHPStan baseline carrying **absolute paths** that matched one laptop only, and two timeouts set below the real runtime), so `gh workflow run ci.yml` is worth running before anything risky. **Two figures in this row were stale and are corrected here (re-verified 2026-08-11):** the suite is **~1.5 min** on a quiet machine, not 20+ (measured 2026-08-10: 795s → 75–97s on the same ~3.7k cases; a full run today took 4.2 min on a machine shared with a second session, which is the contention premium, not the suite). And the old "cost of changing this later" — 453 files replaying 150 migrations per worker, with `php artisan schema:dump` as the lever — was **wrong on all three counts**: migration replay is ~1.5s per worker, about 0.3% of the run. The real cost was `RolesPermissionsSeeder` (925ms × ~230 `beforeEach` blocks) and one 80s test file pinning a worker; both are fixed. Recorded because a decision revisited later deserves the measured numbers rather than the assumed ones — the standing call itself is the owner's and is not what this correction touches. | ⚙️ | — |
| **Keep `composer audit` green** | Now a CI job. Its first run (2026-07-16) found **22 advisories across 12 packages**, incl. two HIGH: Filament **MFA recovery codes reusable via concurrent submission**, and a medialibrary **file-upload restriction bypass** — plus a Filament **scope-enforcement** CVE landing directly on this project's property-isolation invariant. All fixed inside existing constraints (Filament 4.11.3→4.11.8, Laravel 13.11.2→13.20.0, medialibrary 11.22.1→11.23.2); 2283 tests green. Nobody knew because nothing looked — and a CVE lands with no change to your code, so only a scheduled check finds it. **This is the row most likely to bite again if CI stays manual.** | ⚙️ | S |
| **ETA live credentials + signing certificate** | Real `client_id`/`client_secret` from the operator's ETA taxpayer profile **and** a CAdES signing certificate. ETA production **rejects unsigned B2B documents**. The pluggable signer seam + refuse-to-submit guard are ready (`config/eta.php:70-74`, `signing.enabled` defaults false). | 🔑 | M |
| **ETA EGS codes + issuer identity** | Register real EGS item codes (base_rent, service_charge, utility, parking, percentage_rent) + issuer TRN/legal name/address. Placeholders still ship (`config/eta.php:36-46` — issuer TRN `100000000`; `:55-62` — EGS `EG-6820-001`). Wrong codes ⇒ rejection. All env-driven, no code change. | 🔑 | S |
| **Flip ETA out of mock mode** | `EtaSettings.php:16` `$mock = true` submits to a fake endpoint — no legally-binding invoices. One-line flip, gated on the two rows above. | 🧑‍💻 | S |
| **Paymob live cutover (KYC + live creds)** | Sandbox fully integrated and certified; no code changes. Operator completes KYC, re-issues all 4 live credentials, re-registers callbacks on the prod domain, runs one small real charge (`PAYMOB-SETUP.md §6`). | 🔑 | S |
| **Database backups** | 🟡 **In-app half done 2026-07-29** — `spatie/laravel-backup` runs nightly from the **scheduler** (backups belong to runtime, not to a build): `backup:clean` 01:00 → `backup:run` 01:15 → `backup:monitor` 07:30. Archives hold the DB plus `storage/app` (signed leases, tax cards, vendor documents, branding) and land on a dedicated git-ignored `backups` disk outside the backup source. `backup:monitor` is the dead-cron detector: it fails when the newest archive is older than a day. Guarded by `tests/Feature/BackupConfigurationTest.php`. **The two operator questions are now self-answering (2026-07-30):** `/health` gained a `backup_capability` check that **fails in production** when (a) no dump binary is reachable — so `backup:run` would exit 127 and write nothing — or (b) every `BACKUP_DISKS` destination is a local disk, i.e. the copy dies with the machine it is protecting. Both report on a developer box without failing the build, so the answer surfaces on whatever environment it runs rather than depending on someone remembering to ask. **A failed backup is no longer silent (2026-07-30):** `BACKUP_ALERT_EMAIL` is unset by default, and `config/backup.php` builds its channel list as `$backupAlertEmail ? ['mail'] : []` — so spatie's failure notification was routed to **no channel at all**, and nothing else was listening. `App\Listeners\LogBackupFailures` now writes every failure/cleanup-failure/unhealthy event to `OpsLog::error`, which always lands in the ops log and reaches Slack/Sentry once those are wired: no env var is needed for the record to be *made*, only for it to page someone. **Restore is now DRILLED, not assumed (2026-07-30):** `atriom:backup-verify` (weekly, Sun 03:00) opens the newest archive, replays its dump into a scratch database and checks the critical tables are present — `backup:monitor` only ever checked an archive *existed*. **It immediately found a live failure: there is no `mysqldump` binary on this machine, so `backup:run` exits 127 (`sh: mysqldump: command not found`) and the nightly job has been producing nothing.** **Re-verified 2026-08-11 — still true, twelve days later:** `which mysqldump` finds nothing and `atriom:health` still reports it under `backup_capability`. The check is doing its job; nobody has acted on it. Confirm the deploy image ships the MySQL client. **Still operator work:** set `BACKUP_DISKS="backups,s3"` (a copy on the same box as the DB dies with the box — today the default is the LOCAL disk only), `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_ALERT_EMAIL`. There is still **no deploy workflow**. | ⚙️/🔑 | S |
| **Turn on the alerting you already have** | Code is **done** (2026-07-16); this is now two env vars. **Sentry** is wired and inert until `SENTRY_LARAVEL_DSN` is set (PII withheld — `send_default_pii=false` + a `before_send` reusing OpsLog's redaction; self-hostable if the data must stay in-country). **Slack** alerting needs `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`; the threshold is now `LOG_SLACK_LEVEL` (default `error`), decoupled from `LOG_LEVEL` so it can't page on every routine warning. Until both are set, every money/integration failure is visible only to someone SSH'd into the box. | ⚙️/🔑 | S |
| **Rotate the seeded demo password** | Parametrized (`DemoSeeder.php:91`) but the default is still `password` and `.env.example:14` ships it. Now a deploy action, not a build task — rotate/delete demo accounts before the URL is shareable. **No longer a line someone has to remember (2026-08-11):** `/health` + `atriom:health` gained a `demo_accounts` check that FAILS in production while any `@mall.test` / `@atriom.test` / `@atriomwalk.test` login exists, naming them. Matched on the account, not the password hash — rotating the secret leaves an account nobody owns on a role nobody audits. | ⚙️ | S |
| ~~Email (SMTP) cutover~~ | ✅ **Done 2026-07-24 — and not over SMTP.** Outbound mail runs on MailerSend's HTTPS API (`mailersend/laravel-driver`, `MAIL_MAILER=mailersend`) from the verified domain `tri-tech.net`: no SMTP egress rules, no long-lived socket, sending-scoped token. A real test message was delivered end-to-end. Two operator steps remain, both outside the code: **get the MailerSend account approved** (trial plans cap *unique recipients* — a second address 422s with `#MS42225`), and keep SPF/DKIM published for whichever domain production sends from. Preflight `integrations:check --mail` (sends nothing), live send `mail:test <inbox>`. `MAIL_ALWAYS_TO` protects non-production from the fake `@*.test` demo addresses. | 🔑 | — |
| **`integrations:check` preflight** | Run `php artisan integrations:check` after the live `.env` swap to validate Paymob + ETA creds before the first real charge. Command is built and exits non-zero on failure. | ⚙️ | S |

---

## 3. 🟠 P1 — security & operability

Ordered by real risk, not by age.

| Item | What & why | Owner | Effort |
| --- | --- | --- | --- |
| ~~Failed-jobs + scheduler monitoring~~ | ✅ **Done 2026-07-29.** The scheduler stamps a heartbeat every minute and `/health` fails when it goes stale — dead-cron detection that does not itself depend on cron, which is what every scheduled monitor gets wrong. `/health` also fails on any `failed_jobs` row and on a queue backlog (a stopped worker). Thresholds in `config/health.php`. Still worth wiring `OPS_LOG_STACK`/Sentry so the 503 also *pages* someone. | ⚙️ | — |
| ~~Health check that checks something~~ | ✅ **Done 2026-07-29.** `/health` checks database, cache, queue depth, **scheduler heartbeat**, **backup freshness** and storage, answering 503 when any fails; `php artisan atriom:health` does the same from the CLI. Anonymous callers get status only — `HEALTH_TOKEN` unlocks the detail. **Point the uptime monitor at `/health`, not `/up`.** | ⚙️ | — |
| ~~Production env defaults~~ | ✅ **Done 2026-07-29 — both halves, and both were mis-described.** **Timezone was a MONEY bug, not a scheduling one:** `app.timezone` is what `now()` returns, so it decides which day and which accounting period a document belongs to. Under UTC every document created between ~21:00 and midnight Cairo was attributed to the PREVIOUS day — a payment taken 00:30 Cairo on 1 Aug stored as `2026-07-31 21:30`, putting its `payment_date`, GL `entry_date` and period in **July**. Default is now `Africa/Cairo`; the suite pins UTC in `phpunit.xml`. **Logging: the stated reason was wrong.** `debug` does not leak SQL — nothing here enables query logging (no `DB::listen`, no Telescope, no debugbar). The real risk was the channel: stock `stack` → `single` = one `laravel.log` appended to forever and never pruned, and a full disk *stops* the app (MySQL, sessions and uploads all lose their writes) rather than slowing it. Default is now `daily` (rotates, prunes to `LOG_DAILY_DAYS=14`) at `info` — which is also the "logrotate guidance" this row asked for: it doesn't need logrotate, it needed the safe channel to be the default. **`.env.example` pinned `LOG_STACK=single`**, which would have defeated the config default since that file is what a deploy copies. Guarded by `ProductionDefaultsTest`. | ⚙️ | — |
| ~~App-level HTTPS forcing~~ | ✅ **Done 2026-07-29.** `URL::forceScheme('https')` when `security.force_https` is on — default ON outside local/testing, so a production deploy is secure without anyone remembering an env var. Verified: an `http://` APP_URL under `APP_ENV=production` now yields `https://` links, and local stays http for Herd. **A second gap was open next to it: there was no `trustProxies()` call at all**, so Laravel ignored `X-Forwarded-*` — the client IP was the PROXY's, giving login throttling one shared bucket for the whole internet and putting the wrong address in the audit trail. Both now configured (`TRUSTED_PROXIES`, default `*` — safe only while the app is unreachable except through the proxy). This matters most for the URLs that LEAVE the building: the tenant payment link, password resets, the Paymob return URL, the button in every emailed alert. HSTS was already sent but only protects a browser that has already completed one https visit — it does nothing for the first click on a link in an email. Guarded by `HttpsUrlGenerationTest`. | 🧑‍💻 | — |
| **Paymob credential vaulting + HMAC rotation** | **Verified 2026-08-11 — the code half of this row is sound and only the OPS half remains.** The callback verifies `hash_hmac('sha512', …)` with `hash_equals` (timing-safe) and refuses outright when the secret is unset, so it fails closed rather than open; a replayed valid callback is already a no-op (terminal-status guard + the transaction id is promoted at capture, so the second lookup misses); and over-allocation is clamped by `refitAllocationsToBalance`. One real weakness was found and fixed: the terminal-status check sat OUTSIDE the transaction with no row lock, so a gateway retry overlapping a still-running delivery could fire the captured transition twice and send two receipts — it could not double-collect. Now locked and re-checked inside the transaction. **What is left is genuinely not code:** live keys sit in plaintext `.env` (`config/integrations.php:29-32`) with no vault and no written rotation procedure. Rotation would also want the verifier to accept an old and a new secret during the window — worth building only alongside a rotation procedure that someone will actually run. | ⚙️ | S |
| ~~Ops alerts are bell-only~~ | ✅ **Done 2026-07-29.** The count was worse than this row said — **14** notifications were `['database']` only, not 7. The five with a *clock* on them now mail as well: both SLA breaches, `VendorDocumentExpiring` (a lapsed certificate means the vendor legally cannot be dispatched), `VendorContractRenewalDue` (past the notice deadline an auto-renewing contract commits another full term — the one that spends money by being missed), and `LedgerSyncFailed` (the GL refusing documents, previously visible **only** as an in-app bell, with no `OpsLog` line either). The mail is **derived** from the bell payload via `AlsoSendsByMail` rather than written a second time, so the two cannot drift. The other nine were left in-app deliberately — department messages, owner statements, sales declarations, low stock are read when you next look, and mailing everything trains people to ignore what matters. Both halves pinned by `OffAppAlertsTest`, including a guard against someone copying the trait onto the quiet ones. **Push remains built-but-not-live** (blocked on the Firebase project + APNs key), so mail is the off-app path today. | 🧑‍💻 | — |
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
| 3 | **Approval engine** — single approver resolved by amount (module 28) | 🟡 shipped (`5f1a679`) + wired to work-order spare parts (`01ea84a`, FR-CM-09/10/11). ✅ **Closed 2026-08-11 — the ladder is now an operator screen** (`ApprovalRuleResource`, Settings → Approval Bands, gated on the `approvals.manage_rules` permission that already existed and is withheld from `manager` on purpose). Was: **no admin UI to edit the ladder** (`ApprovalRule` is seeded/DB-only), and procurement is still to come |
| 7 | **SLA penalties** → vendor bill deduction → GL | ✅ shipped; the GL half was **broken and is now fixed + gated** (2026-07-16) |
| 4 | **Procurement** — purchase requests → approval → order → goods receipt → stock-in | ✅ **shipped** (module 29 — `PurchaseRequestResource`, the PO document, GRNI FIFO clearing; row corrected 2026-08-11, it still read "next"). Also the seam that fixes a **pre-existing GL defect**: receipts pass only a free-text reference, so GRNI `21701001` accumulates and is never cleared (`InventoryMovementJournalizer.php:22-24`) |
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
| **Bank reconciliation** | No statement import/matching *anywhere* — cash/bank GL is asserted, never verified against a bank statement. Surfaced in two domains independently; the #1 control gap. **Re-verified and planned 2026-08-11** — genuinely absent (no `bank_accounts`, statement or match model; `BooksReconciliationService` is the internal AR/AP tie-out, a different thing), and now scoped in [BANK-RECONCILIATION-PLAN](accounting/BANK-RECONCILIATION-PLAN.md): six slices, of which the first three ARE the control. The prerequisite nobody had noticed is that `bank`/`cash` are account ROLES, not accounts an operator manages — you cannot reconcile "the bank role" once a property has two banks. | [Accounting](gap-analysis/odoo/01-accounting.md) + [Treasury](gap-analysis/odoo/06-treasury.md) |
| **Egyptian tax depreciation (declining-balance) + a second tax book** | Depreciation is straight-line only, but Egyptian income tax (Law 91/2005) is pool-based diminishing-value — so no tax-basis figure can be produced at all. | [Fixed Assets](gap-analysis/odoo/04-fixed-assets.md) |
| **Employer social insurance + end-of-service gratuity** | *Correctness*, not features: payroll posts only the withheld employee side, so the employer contribution and accruing gratuity are never expensed/accrued — the books **understate labour cost and liabilities today**. | [HR/Payroll](gap-analysis/odoo/05-hr-payroll.md) |

The 🟡 tail (worth doing, not urgent): ~~per-property year-end close (F-80)~~ ✅ **done 2026-07-19**
(per-asset closing entries — the owner-statements prerequisite),
~~VAT-return report~~ ✅ **shipped 2026-08-11**, *reachable* 2026-08-11 (`VatReturnService` — output and input VAT read from the LEDGER, with the invoices used to PROVE it ties; the taxable standard/exempt split comes from the documents because the GL knows revenue by account and not by tax treatment. The service landed with **zero callers** and this line called it done; it now has `/admin/vat-return` and nets credit notes — see [module 17](modules/17-reports.md#vatreturnservice-appservicesreportsvatreturnservicephp)), comparative statements, weighted-average inventory costing, reorder
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
reorder-driven auto-purchase, ~~bill-the-violation-fine-to-AR~~ ✅ **shipped** (`BillViolationFineService`), utility tariff/recharge automation,
lease document generation + e-sign, first-class rent-free/stepped schedules, annual/YTD turnover
breakpoints, ~~the deposit-balance/reconciliation layer~~ ✅ **shipped** (`DepositApplication` + the move-out statement). **The tail is now tracked in [BACKLOG.md](BACKLOG.md)**, which separates rows re-verified against the code from rows carried forward — three of these turned out to be already built when checked on 2026-08-11. **The field-technician mobile app** is a
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
- ✅ **"Enforce 2FA on write roles"** — **done 2026-07-30, and the row's premise was wrong.** It
  said the mechanism was built and only the env var was unset. In fact **2FA was enforced on
  nobody, super_admin included**: the panel passed a closure to `->forceTwoFactorSetup()`, the
  plugin evaluates that argument when the PANEL IS REGISTERED — at boot, before any request is
  authenticated — so `auth()->user()` was null, it stored `false`, and the panel's `array_filter`
  dropped the enforcement middleware entirely. Setting the env var, the fix this row proposed,
  would have changed nothing while leaving everyone believing the panel was protected. Same trap
  already documented on `->colors()`. The role decision now lives in
  `App\Http\Middleware\ForceTwoFactorForRoles` (per request). Mutation-checked: restoring the old
  wiring fails 3 of the 8 tests.
  **Posture changed the same day, operator's call: enforcement is OPT-IN.** It briefly defaulted ON
  outside local/testing; it now forces nobody until `SECURITY_FORCE_2FA_ROLES` is set, because
  switching it on marches every listed role through TOTP enrolment at their next login — a rollout
  to schedule with staff, and pre-go-live it would block the very people doing data validation.
  The mechanism is built and tested; only the switch is off. So that "off" can't repeat the months
  of silent non-enforcement, `php artisan atriom:health` **FAILS** on a production environment with
  no roles forced, and prints the exact line to paste. `SecurityDefaults::FORCE_2FA_ROLES` is now
  the recommended list + the health check's yardstick rather than an automatic default.
- ✅ **"ETA receiver address per tenant" + "ETA retry policy is untested"** — **both done
  2026-07-30.** The address one was worse than the row said: not just "wrong buyer address" but
  **four constants** (Giza / 6th of October City / building 1, with the freeform address stuffed
  into `street`) on **every** document. Mock mode hid it — the fake endpoint accepts anything, so
  the first real filing would have been the test. `tenants` now carries the four parts, the
  governorate is a fixed 27-entry list (ETA does not treat "Cairo", "cairo" and "القاهرة" alike),
  and the builder **refuses** rather than guesses — parsing a freeform bilingual address into parts
  would put invented data on a legal tax document. Building it turned up a third refusal nobody had
  considered: an invoice whose tenant is **archived** was filed as buyer "Unknown", tax id
  `000000000`, hardcoded address — a document naming a buyer that does not exist. See
  **[modules/16](modules/16-eta-einvoicing.md)**.
- ✅ **"Gap-analyse modules 22–25"** — **done 2026-07-29.** Every one produced a real finding,
  and the four together produced a systemic one.
  - **22 Inventory** — `transfer_in`/`transfer_out` were in the migration's enum, the model
    constants, the journalizer and the ledger's Transfers tab, and **nothing could create one**;
    the tab was permanently empty. Underneath, the F-83 value guard was not scoped to the types
    that post, so `record()` rejected every transfer that did not carry an explicit cost — the
    type was unusable even from code. Built `transfer()` (atomic pair, same-property only,
    because a transfer posts no GL entry and that is only true inside one property's books).
  - **23 Fixed Assets** — three operator-typed dates became a GL `entry_date` with **no
    closed-period guard at all**: `acquisition_date`, `disposed_on`, `--month`. Disposal is the
    worst, being terminal.
  - **24/25 HR + Treasury** — the F-93/F-89 fix had guarded the money going **out** (settlement,
    repayment) and left the money going **in** unguarded. An unguarded custody grant was
    self-trapping: recorded, unbacked in the books, and unsettleable, because the settlement
    guard then refused every settlement of it.
  - **The systemic finding** — that made six repeats of one bug class, so the answer stopped
    being another fix: `App\Support\PostingDateGuards` + `PostingDateGuardConformanceTest` now
    force every GL source to declare its guard. Building the registry immediately found four
    more nobody had looked at (expenses, marketing spend, deposits, payment *edits*) and a fifth
    in invoices. See **[modules/21 § Posting-date gate](modules/21-general-ledger.md)**.
- ✅ **"The public payment surface is uninstrumented + untested"** — **done 2026-07-29.** Three
  distinct things came out of actually looking at it, and only one was the thing the row named:
  - **A real double-charge race.** `PaymobPaymentInitiator::start()` was check-then-act with a
    *network call* between the check and the act, so two simultaneous taps both found no
    reusable session and both opened one — two live Paymob orders against one debt, each
    allocated the full balance. Now serialised on a cache lock held across the gateway call;
    the second request waits and reuses the first one's session. `PaymobConcurrentSessionTest`
    reproduces the race and the timeout/exception release paths.
  - **A leaked pay link could never be revoked.** `/pay/{token}` is a bearer URL with no login
    and **no expiry**: forwarded mail, a shared inbox or a screenshot exposed the tenant, the
    line items and the amounts permanently, and the operator had no remedy. Added
    `Invoice::rotatePaymentLinkToken()` + a **Regenerate payment link** action (gated on
    `invoices.edit` in both `visible()` and `action()`, `ops.log`-audited). Deliberately *not*
    an expiry — that would silently kill legitimate links in already-sent mail.
  - **Logging + E2E**, the row's original ask: `paymob.session_started` / `session_reused` /
    `session_discarded_stale_amount`, and `tests/e2e/23-payment-link.spec.js` — the first
    coverage of the app's only unauthenticated HTML surface. Writing the logging also revealed
    `OpsLog::REDACT` matches keys **exactly**, so `token` never covered `payment_token` — a
    credential that authorises a charge. Fixed in the same pass.
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

> **The single go-live gate is [GO-LIVE.md](GO-LIVE.md)** — every configuration item, credential and
> unanswered question in one list, each verified against the code on 2026-08-11. This section stays
> the engineering view; that one is what you hand to whoever is launching.


> **Re-verified against the running system 2026-08-11.** `atriom:health` reports `database`, `cache`,
> `storage` OK; `queue`, `scheduler` and `backups` FAIL, which is the honest answer on a developer box
> with no worker, no cron and no archive — those three are what the row below asks an operator to
> stand up. The one finding that is **not** a dev-box artefact is `mysqldump`, still missing twelve
> days after it was first recorded: the nightly backup has been writing nothing that whole time, and
> the check that says so has been reporting correctly and going unread. Nothing in §2 has become
> false since it was written; the two stale NUMBERS in the CI row are corrected in place.
>
> **Everything below is operator or environment work, not code.** That is the finding, and it is
> worth stating plainly rather than filling the gap with engineering: the code-side of go-live is
> done, and what remains cannot be written — only configured.

**The code-side observability work is done** — what remains is four env vars an operator sets:
`SENTRY_LARAVEL_DSN`, `OPS_LOG_STACK="ops_daily,slack"` + `LOG_SLACK_WEBHOOK_URL`, and
`APP_TIMEZONE=Africa/Cairo`. Until they're set, every failure path is invisible off-box.

1. **Point the uptime monitor at `/health`** — the endpoint exists as of 2026-07-29 and
   already fails on a dead scheduler, a stale backup, a stopped worker or a DB outage. It is
   worth nothing until something external polls it.
2. **The remaining ⚙️ go-live rows** (§2) — backups are now half done in-app (2026-07-29);
   what is left there is off-site copies, an archive password and **a tested restore**. There
   is still **no deploy workflow at all**, which makes several other rows moot.

> Before starting anything from §3, read the warning at the top of §6. Two of the first four
> rows anyone verified were false.

*Keep this file current: when something ships, move it to §6 rather than deleting it — the
retired list is what stops the next person rebuilding a thing that already works.*

---

## 8. Cycle — tax master data, derived fields, configuration, reporting (opened 2026-08-12)

> **Four workstreams the owner opened on 2026-08-12.** Every "today" statement below was verified
> against the code the same day and carries its file:line, because the last two roadmaps both
> shipped rows that rested on something that had already been built.
>
> **The benchmark is what reference systems actually do — Yardi first, then Odoo / SAP / NetSuite /
> MRI / ERPNext where the question is generic accounting rather than property management.** Where
> the standard and a stated preference disagree, the standard wins and the disagreement is named
> rather than quietly resolved (owner's instruction, 2026-08-12). Two rows below do exactly that.

### 8.1 Tax — take the rate out of settings *and* out of the operator's keyboard (TX)

**What Atriom does today.** The rate is one number in an application setting
(`TaxSettings::vat_standard_rate`); *which* supplies are taxable is data on the charge code
(`charge_codes.vat_treatment` + `vat_rate_override`, shipped 2026-08-11); one resolver
(`App\Support\Vat::rateForType()`) is called by every origination point; and an issued document
freezes the rate it was billed at. **That last property is already correct and must not be
touched** — it is what keeps the books tied to the filed returns.

**Three places it departs from every reference system:**

1. **The rate is typed on the document.** `invoice_items.vat_rate` is a free 0–100 `TextInput`
   ([InvoiceForm.php:240](../app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php#L240)),
   and so is a credit-note line
   ([CreditNoteForm.php:203](../app/Filament/Admin/Resources/CreditNotes/Schemas/CreditNoteForm.php#L203)).
   The comment there states the intent plainly — *"the operator can still type a different rate on
   the line"*. So `Vat::rateForType()` governs the **default**, and nothing governs the **value**.
2. **The rate has no effective date.** Egypt moved 10% → 14% in 2017. When it moves again, editing
   the setting re-rates everything originated afterwards — *including* an invoice back-dated into
   the old regime, which is the one case that must not follow the new rate.
3. **`TaxSettings::wht_default_rate` is a single number.** Egyptian withholding (Income Tax Law
   91/2005 art. 59) is rate-by-nature — supplies, services, contracting and professional fees each
   differ. One default cannot express it, so today it is either wrong or switched off.

**What the reference systems do** — this is the standard, not a preference:

| System | Where the rate lives | Selected or typed |
|---|---|---|
| **Yardi Voyager** | taxability is a `Tax` flag on the charge code (Atriom already matches this); the **rate** is a tax-rate table set up per property | selected; override needs rights |
| **Odoo** | `account.tax` master records — scope (sale/purchase), computation, tax group, GL accounts | selected on the line; a rate change is a **new record**, the old one archived |
| **SAP** | tax codes with country + **validity period** | posted as a code; the code carries the rate |
| **NetSuite** | tax codes / tax groups, effective-dated | selected |
| **MRI · ERPNext** | effective-dated tax tables / tax templates | selected |

Two rules are consistent across all of them:

- **A rate is never typed on a document — it is resolved from an effective-dated tax master.**
- **An override is a permission, not a prohibition.** Yardi gates it on rights; Odoo lets you edit
  the tax amount on a line; SAP allows a manual tax entry against the code. There is a reason: a
  supplier's invoice rounds differently, or a contract fixed a rate. Forbid it outright and
  operators enter the difference as an invented line item — the same money, now unclassifiable.

**Where this lands against the ask.** The instinct — *pick from a list, don't type* — is exactly the
standard. The **home** is not: a tax rate is not an application setting, it is **master data with a
validity period and a GL account**, in the same tier as the chart of accounts and the charge-code
catalogue. Settings hold *policy* ("do we withhold at all"); the catalogue holds *rates*. And
"so it can't be changed manually by the user" is right for almost every user and wrong as an
absolute — the standard is rights-gated override with the reason recorded.

| Row | P | Owner | Work |
|---|---|---|---|
| **TX-01** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12. `tax_codes` + `tax_rates` catalogue**, seeded from the operator's own `account.tax` sheet ([EGYPTIAN-TAX-CATALOG.md](accounting/EGYPTIAN-TAX-CATALOG.md)) — VAT · stamp · schedule · withholding, each in both directions. Rates are **dated rungs** (no end date, so windows cannot overlap or gap). Screen at `/admin/tax-codes` with a rate-ladder relation manager; `tax_codes.*` RBAC; `TaxCatalogueConformanceTest` gates rows invented beyond the sheet **and** rows dropped from it. Stamp + schedule ship switched off — their GL accounts are not wired, and `TaxCode` refuses to activate a taxable code with no rate or no posting role. *(Original row below.)* **`tax_codes` catalogue.** Code · EN/AR name · scope (`output`/`input`/`withholding`) · treatment (`standard`/`exempt`/`zero_rated`) · rate · `effective_from`/`effective_to` · posting role · `is_active`. **Effective-dated: a rate change is a new row, never an edit** — the same rule the rest of the system already applies to posted money. `atriom:install` seeds the Egyptian set. |
| **TX-02** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `charge_codes.tax_code` replaces `vat_treatment` + `vat_rate_override` (both dropped in the same migration, which backfills each accountant ruling onto the equivalent tax code and carries the operator's configured rate across). `Vat::rateForType($code, $on)` resolves for the DOCUMENT's date; `TaxSettings::vat_standard_rate` is deleted. *(Original row below.)* **Charge code points at a tax code.** `vat_treatment` + `vat_rate_override` migrate in and are **dropped in the same change** (stale-work rule). `Vat::rateForType($code)` gains a date — `rateForType($code, $on)` — so origination resolves the rate *in force on the document's date*, not today's. `Vat::EXEMPT_TYPES` survives unchanged as the unseeded floor, with its existing conformance test. |
| **TX-03** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `invoice_items.tax_code` + `credit_note_items.tax_code` (defaulted at the MODEL layer from the charge code, so the eight services that raise lines cannot forget it); the rate is read-only without the new **`tax_codes.override`** permission — withheld from `manager` deliberately — and enforced server-side in `App\Support\CatalogueTaxRate`, called from the repeater's save hooks because the relationship-backed repeater never reaches the page's `mutateFormDataBeforeCreate`. Overrides record `tax_override_reason`. Labels now read "Tax", not "VAT". *(Original row below.)* **Invoice + credit-note lines select a tax code**, filtered to those valid on the document date; the resolved rate renders read-only beside it and is still frozen onto the line. Free rate entry moves behind a new `billing.override_tax` permission (accounting + super_admin) and writes the reason to the activity log. |
| **TX-04** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `vendor_bills.tax_code` + `expenses.tax_code` (both post to `vat_recoverable`, so the whole input side of a filed return previously rested on an unexplained number). Picking a code derives the amount; the amount stays **editable**, because a supplier's document states its own tax — a departure beyond **EGP 1** demands a written reason instead (`required()` is real server-side validation). Backfill classifies only what it can prove: a zero reclaim → `VAT_EXEMPT_P`, an exact standard-rate figure → `VAT_14_P`, everything else null. *(Original row below.)* **AP is currently un-coded.** `expenses.vat_amount` ([ExpenseForm.php:100](../app/Filament/Admin/Resources/Expenses/Schemas/ExpenseForm.php#L100)) and `vendor_bills.vat_amount` ([VendorBillForm.php:188](../app/Filament/Admin/Resources/VendorBills/Schemas/VendorBillForm.php#L188)) are money someone keys. Give both an input-tax selector with a derived amount and a small tolerance for supplier rounding (Odoo's behaviour), so input VAT is **classified** rather than a number. |
| **TX-05** | 🟠 | 🧑‍💻 | **WHT by nature** — a `withholding` scope in the same catalogue, picked per vendor or per payment nature; `TaxSettings::wht_default_rate` is deleted, not kept alongside. |
| **TX-06** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** The taxable base is split by the line's tax code, not by `vat_rate > 0` — so **zero-rated is now its own line on the return**, separate from exempt. Non-VAT families (stamp, schedule) are excluded from both the base and the output-VAT tie-out, so commissioning them cannot silently corrupt it. Lines predating the catalogue fall back to the rate heuristic and are **counted and surfaced** (`unclassified_lines`), because "no zero-rated supplies" and "we cannot tell" are different answers and only one is safe to file. *(Original row below.)* **VAT return reads the catalogue** — exempt and zero-rated separate on the return because the code says so, not because someone remembered. `VatReturnService` currently infers from stored rates. |
| **TX-08** | 🟠 | 🧑‍💻 | **Wire the GL for stamp + schedule tax** — the "GL wiring (later)" line the catalogue document has carried since 2026-07-19, and now the only thing keeping eleven of the operator's own codes switched off. Needs a posting role + chart account + mapping per family; then naming it on the code activates it. Blocked on the accountant confirming **stamp applicability**, which is the same sign-off that document has always said it needs. |
| **TX-07** | 🟡 | 🧑‍💻 | **Naming: `vat_*` → `tax_*`, new structures only.** The concept is spelled `vat_` on ~14 committed money columns. Reference systems name the field *tax* and treat VAT as one *kind* — that is what lets withholding, schedule tax and stamp duty share the layer. **Recommendation: do NOT rename the stored columns.** They are on posted documents, and the rename would reach the frozen e-invoicing module. Instead: every new structure is `tax_*` (`tax_codes`, `tax_code_id`), and the UI label comes from the catalogue, so a jurisdiction that calls it something else needs no deploy. Decided this way deliberately — half-renamed is worse than either end state. |

### 8.2 Derived-but-editable fields (DF)

**The reported case is real and is worse than described.** In the lease form
([LeaseForm.php:195-212](../app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php#L195-L212)),
`commencement_date`, `term_months` (default 36) and `expiry_date` are **three independent inputs**.
Nothing derives, and nothing checks: a lease can be saved as "36 months" spanning twelve. `term_months`
is not decoration — it is logged on the lease, copied by renewal, and read by the option-exercise
service — so the two can disagree and the disagreement propagates.

Same shape elsewhere, all verified 2026-08-12:

- **Renewal modal** ([LeasesTable.php:504-524](../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php#L504-L524)) — `new_term_months` + `commencement_date`, and **no expiry shown at all**. The operator commits a renewal without seeing when it ends.
- **Manual invoice** ([InvoiceForm.php:159](../app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php#L159)) — `due_date` is free, while *every* service derives it from the lease's payment terms (`MonthlyBillingService.php:514`, `BillMeterReadingService.php:103`, `LateFeeService.php:150`, `CamReconciliationService.php:699`, and four more). A hand-typed invoice therefore ages on a different rule from a generated one, and AR ageing is the report the owner reads.
- **Lease options**, **fixed assets** (`acquisition_date` + `useful_life_months`, no derived depreciation end), **vendor contracts** ([ContractsRelationManager.php:83-88](../app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php#L83-L88), start/end with no term).

**The standard behaviour** (Yardi and MRI both): the derived field is **pre-filled and editable**,
and editing it **back-derives its partner**, so the pair cannot disagree. Term ⇄ expiry is
bidirectional — typing an expiry recomputes the term rather than contradicting it.

| Row | P | Owner | Work |
|---|---|---|---|
| **DF-01** | 🟠 | 🧑‍💻 | Lease **term ⇄ expiry**, bidirectional, both editable. Validation that the pair agrees becomes unnecessary because they can no longer disagree. |
| **DF-02** | 🟠 | 🧑‍💻 | Renewal + option-exercise modals show the derived expiry before the operator commits. |
| **DF-03** | 🟠 | 🧑‍💻 | Manual invoice `due_date` derives from `issue_date` + the lease's `payment_terms_days`, editable — one rule for typed and generated invoices. |
| **DF-04** | 🟠 | 🧑‍💻 | **Make it systemic, or it decays.** One `App\Support\Forms\Derives` helper (pre-fill · live · back-derive · stays editable) plus a registry of derivable pairs and a conformance test that fails when a form exposes both sides of a registered pair without wiring it. This is the only version of "the whole system behaves this way" that is still true after the next form is added — the same play as `PropertyIsolation` and `ChangeImpact`. |
| **DF-05** | 🟡 | 🧑‍💻 | Remaining pairs: fixed-asset depreciation end, vendor-contract term, PDC maturity, work-order SLA due. |

### 8.3 Settings — make configuration declarative, audited and per-property (CFG)

**Today.** One 458-line page maps every field **twice** by hand — once in `mount()`
([Settings.php:68-125](../app/Filament/Admin/Pages/Settings.php#L68-L125)) and once in `save()`
([Settings.php:148-210](../app/Filament/Admin/Pages/Settings.php#L148-L210)). Adding a setting means
editing three files, and **omitting the `save()` line makes the screen silently inert** — a bug class
this project has already been bitten by. Three further gaps, verified:

- **No audit trail on settings.** `settings.manage` gates *who may*, but nothing records **who did,
  when, and from what to what**. In a system where money records are undeletable and the charge-code
  catalogue is activity-logged, the VAT rate and the late-fee percent are the one place a number can
  change leaving no history.
- **Settings are global; Eltizam runs several malls.** Yardi configures billing day, late fees and
  SLA hours **per property**. Atriom has airtight per-property isolation everywhere *except* the
  numbers that drive billing.
- **Policy still hardcoded**: AR ageing buckets 30/60/90 ([ReportService.php:37-40](../app/Services/Reports/ReportService.php#L37-L40)), the default lease term (36), default payment terms (`?? 7`, repeated across eight services), document-number formats, fiscal-year start, rounding.

| Row | P | Owner | Work |
|---|---|---|---|
| **CFG-01** | 🟠 | 🧑‍💻 | **Declarative settings registry** — each `Settings` class declares its fields (type · section · validation · label key); the page renders and saves generically. Deletes ~250 lines of hand mapping and the entire inert-screen bug class. Conformance test: every public property of every registered class is rendered **and** written back. |
| **CFG-02** | 🟠 | 🧑‍💻 | **Settings audit trail** — who / when / old → new, through the same activity log as everything else, surfaced as a tab on the page. |
| **CFG-03** | 🟠 | 🧑‍💻 | **Per-property overrides** for the settings that legitimately differ (billing day + time, late fee, grace, NSF, SLA hours): global default + optional override, resolved through **one** accessor so a service cannot read the global by accident. Yardi standard. |
| **CFG-04** | 🟡 | 🧑‍💻 | Lift the hardcoded policy into settings — **ageing buckets first** (they change the report the owner reads), then default lease term · payment terms · numbering formats · fiscal year · rounding. |
| **CFG-05** | 🟡 | 🧑‍💻 | **Configuration health page** — what is unset and what it breaks; the in-app form of [GO-LIVE.md](GO-LIVE.md), and the analogue of Yardi's setup checklist. `atriom:health` already computes most of it. |
| **CFG-06** | 🟡 | 🧑‍💻 | **Write-side/read-side conformance test** — every setting a screen writes must be the one the code reads. The inert-screen trap becomes a gate instead of a memory. |

### 8.4 Reports — Yardi shape and delivery (RP)

**The coverage is already strong** and should not be rebuilt: 19 report pages (AR ageing + by type +
collections, rent roll, expiration schedule, occupancy cost + map, sales analytics, trial balance,
income statement, balance sheet, cash flow, general ledger, VAT return, weekly spend, monthly close),
CSV on all six main reports, PDF for monthly close and tenant/asset statements, and
`ComparativeStatementService`. **The gaps are shape and delivery, not what is measured.**

| Row | P | Owner | Work |
|---|---|---|---|
| **RP-01** | 🟠 | 🧑‍💻 | **Report hub.** `/admin/reports` is the monthly close, not a catalogue. Yardi's Reports menu is a categorised, searchable list — Financial · Leasing · Operations · Tax — each with a one-line description and a last-run stamp, plus favourites. Every report is already a registered page; this is an index over them. |
| **RP-02** | 🟠 | 🧑‍💻 | **One parameter bar.** Reports variously take a period, an as-of, or a range. A shared filter component — property · as-of/period · comparison basis · include-inactive — remembered per user. |
| **RP-03** | 🟠 | 🧑‍💻 | **Saved report versions** — name a set of parameters and re-run it. Yardi's "report versions"; prerequisite for RP-04. |
| **RP-04** | 🟠 | 🧑‍💻 | **Scheduled delivery** — email a saved report on a schedule (the month-end pack to the owner). Nothing exists today; the scheduler, the PDF builders and the CSV exporter all do. |
| **RP-05** | 🟠 | 🧑‍💻 | **Drill-down.** Verified: no row URLs on the income statement, trial balance or general ledger. A statement line should open its GL entries, and a GL line its source document. **This is the single biggest "why doesn't it feel like Yardi" difference** — the numbers are right, they are just terminal. |
| **RP-06** | 🟡 | 🧑‍💻 | **Comparative + budget columns** on the income statement (prior period · prior year · variance). `ComparativeStatementService` gets part way; **budget-vs-actual is a build, not a column** — there is no budget model at all. |
| **RP-07** | 🟡 | 🧑‍💻 | **Excel export** alongside CSV — headers, number formats, frozen panes. What an accountant actually receives from Yardi, and the reason CSV gets reformatted by hand today. |
| **RP-08** | 🟡 | 🧑‍💻 | **Owner pack** — module 32 issues owner statements; group the owner's reports into one deliverable pack (FRD ask). |

### 8.5 Suggested sequence

**TX-01 → TX-03** first: it is the only workstream touching tax on issued documents, and the longer
free rate entry stays, the more history is entered under it. **DF-01/03/04** next — small, and DF-04
is what stops the pattern decaying. **CFG-01/02** then, because every later configuration row is
cheap once the registry exists and expensive before it. **RP-05 and RP-01** last of the 🟠 rows: the
most visible change per unit of work, and both are additive.
