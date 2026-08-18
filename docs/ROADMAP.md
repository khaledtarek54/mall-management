# Atriom — Roadmap

> **The single prioritized view.** Go-live blockers, the Eltizam FRD expansion, the
> accounting backlog, and known defects — in one place, because three lists in three
> formats is how the last one drifted.
>
> **Re-baselined 2026-07-16**, every row verified against the code. The previous roadmap
> was written 2026-06-28, before modules 21–28 existed; **19 of its 48 rows had already
> shipped** and four rested on premises that are no longer true (see [§6](#6-retired-rows--do-not-rebuild)).
> Read that section before picking up anything you remember from the old list.

> **⚠️ VERIFICATION SWEEP, 2026-08-18 — THIRTEEN rows in this file claimed something was missing
> that the code already had.** Bank reconciliation (three services + two resources), the owner
> portal (removed by design, not "off"), report CSV, comparative statements, the VAT return,
> inventory's on-hand scoping, stock transfers, low-stock alerts (built *and* scheduled), employer
> social insurance, and — the worst of them — the **#2-priority "CAM recovery-clause engine"**,
> whose caps, admin fee, gross-up and configurable basis are all built. Each is corrected in place.
>
> **Read this before picking up any row: verify the claim against the code first.** Every one of
> those thirteen would have cost a day of rebuilding something that already worked, and two of them
> (§4 phase 5) explicitly instructed the reader to build what was already there. A row saying
> "this is missing" is a hypothesis with a date on it, not a fact — this file is written by hand
> and the code moves faster than it does.

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
> **§8.2 DF-01→05 are shipped/retired** as of 2026-08-12. DF-05's four claimed pairs were all
> false — see the row; a scanning gate replaced the guesswork.
> the settings page is reflection-driven and audited, the statements drill down, there is a report
> hub, a set of filters can be saved and shared, and **a saved view can deliver itself by email**
> (RP-04). What remains in §8.4 is RP-02 (one shared parameter bar), RP-06/07/08, and lifting the
> remaining sixteen reports' CSV out of their export closures so they can be scheduled too.
> Next is **TX-04** (AP input tax: `expenses.vat_amount` and `vendor_bills.vat_amount` are still
> money someone keys). **TX-04 shipped too.** Next is **TX-05** (withholding by rate), which retires
> **TX-05 shipped too — no rate lives in settings any more.** What remains in the tax cycle is
> **TX-08** alone (GL accounts for stamp + schedule), and that is blocked on the accountant's
> sign-off rather than on code.
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
| **Database backups** | 🟡 **In-app half done 2026-07-29** — `spatie/laravel-backup` runs nightly from the **scheduler** (backups belong to runtime, not to a build): `backup:clean` 01:00 → `backup:run` 01:15 → `backup:monitor` 07:30. Archives hold the DB plus `storage/app` (signed leases, tax cards, vendor documents, branding) and land on a dedicated git-ignored `backups` disk outside the backup source. `backup:monitor` is the dead-cron detector: it fails when the newest archive is older than a day. Guarded by `tests/Feature/BackupConfigurationTest.php`. **The two operator questions are now self-answering (2026-07-30):** `/health` gained a `backup_capability` check that **fails in production** when (a) no dump binary is reachable — so `backup:run` would exit 127 and write nothing — or (b) every `BACKUP_DISKS` destination is a local disk, i.e. the copy dies with the machine it is protecting. Both report on a developer box without failing the build, so the answer surfaces on whatever environment it runs rather than depending on someone remembering to ask. **A failed backup is no longer silent (2026-07-30):** `BACKUP_ALERT_EMAIL` is unset by default, and `config/backup.php` builds its channel list as `$backupAlertEmail ? ['mail'] : []` — so spatie's failure notification was routed to **no channel at all**, and nothing else was listening. `App\Listeners\LogBackupFailures` now writes every failure/cleanup-failure/unhealthy event to `OpsLog::error`, which always lands in the ops log and reaches Slack/Sentry once those are wired: no env var is needed for the record to be *made*, only for it to page someone. **Restore is now DRILLED, not assumed (2026-07-30):** `atriom:backup-verify` (weekly, Sun 03:00) opens the newest archive, replays its dump into a scratch database and checks the critical tables are present — `backup:monitor` only ever checked an archive *existed*. **It immediately found a live failure: there is no `mysqldump` binary on this machine, so `backup:run` exits 127 (`sh: mysqldump: command not found`) and the nightly job has been producing nothing.** **✅ CLOSED 2026-08-18, and it was a CODE gap, not an ops one.** `Health::checkBackupCapability()` had read `database.connections.{driver}.dump.dump_binary_path` since the day this was found — but that key existed on **no connection**, so it could only ever resolve to `''` and fall through to the PATH lookup that kept failing. The check was right and there was literally no way to answer it. The seam now exists on `mysql` + `mariadb` (`DB_DUMP_BINARY_PATH`, a directory; empty = use PATH, correct on a normal image). Homebrew's `mysql-client` is keg-only, which is why the binary was present at `/opt/homebrew/opt/mysql-client/bin` all along and never on PATH. **A second bug was hiding behind the first:** `VerifyBackupService::CRITICAL_TABLES` named `journal_entry_lines`, a table that has never existed (it is `journal_lines`), so the restore drill would have answered *BACKUP NOT RESTORABLE* for every healthy archive — the dump crash just meant it never got that far. Both fixed and pinned by `BackupCanActuallyRunTest`. **Verified end to end:** `backup:run` writes an archive and `atriom:backup-verify` replays it — 135 tables, 1,279 statements, every critical table with rows. First verified-restorable backup this project has had. **Still operator work:** confirm the deploy image ships the MySQL client (or set `DB_DUMP_BINARY_PATH` there). **Still operator work:** set `BACKUP_DISKS="backups,s3"` (a copy on the same box as the DB dies with the box — today the default is the LOCAL disk only), `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_ALERT_EMAIL`. There is still **no deploy workflow**. | ⚙️/🔑 | S |
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
| 5 | **Inventory & warehousing** — low-stock alerts, bins, transfers | ✅ **CLOSED 2026-08-18.** All three exist, and two of this row's claims were STALE when re-verified against the code. **On-hand scoping:** fixed — `InventoryItemResource::getEloquentQuery()` scopes `withSum` through `warehouse_id → asset_id`. **Transfers:** built, not dead — `StockMovementService::transfer()` writes both legs in one transaction, out-first so an impossible transfer is refused before anything is written. **Low-stock alerts:** built AND scheduled (`inventory:scan-low-stock`, daily 07:30), per-property by warehouse, idempotent under a row lock. **Bins:** shipped 2026-08-18 — a `bins` table unique per warehouse (so every mall can label its own `A-01`), an optional `stock_movements.bin_id` that costs nothing to an operator who does not rack their storeroom, a bins tab on the warehouse, and a picker scoped to the chosen warehouse. The bin is **validated against the warehouse on write**, not just scoped in the form: a payload naming another building's shelf drops the bin and keeps the movement. On-hand per bin is derived, never stored. `StorageBinsTest`. |
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
| ~~Egyptian tax depreciation (declining-balance) + a second tax book~~ | ✅ **Shipped 2026-08-18.** `Pages\TaxDepreciation` + `TaxDepreciationService`, on the statutory pools of Law 91/2005 Art. 25 — buildings 5% and intangibles 10% straight-line on cost, computers/software 50% and everything else 25% **pooled diminishing value**. `fixed_assets.tax_pool` states the class (free-text `category` cannot be mapped to statute). A **SCHEDULE, not a second ledger**: Egypt files single-book, so nothing posts and the row that earns the page is the temporary DIFFERENCE from the book charge. Rolled forward from history every time rather than accumulated into a column — a stored written-down value drifts on the first re-cost or disposal, and a tax basis that disagrees with the register does not look broken, it gets filed. Catalogued, exportable and schedulable. `TaxDepreciationScheduleTest`. |
| **End-of-service gratuity** | ⚠️ **Half of this row was STALE (verified 2026-08-18).** The EMPLOYER social-insurance contribution **is** posted — `PayrollJournalizer` books `Dr Social Insurance Expense` for the employer share against `Cr Social Insurance Payable`, driven by `PayrollSettings::$employer_social_insurance_rate`. What is genuinely missing is the **end-of-service gratuity**: zero references anywhere in `app/`, so the accruing liability is never recognised and the books understate labour cost by it. *Correctness, not a feature.* | 🧑‍💻 | M |

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
| **2** | ~~CAM recovery-clause engine~~ | ✅ **Largely BUILT — verified against the code 2026-08-18, and this row was the single most misleading one in the file.** **Caps:** built, with banked headroom — `cam_allocations.cap_amount`, `cap_absorbed_amount`, `cap_headroom_used`, `cap_headroom_banked`. **Admin fee:** built — `cam_expense_pools.admin_fee_pct` + `admin_fee_on_net`, so the 10–15% IS bookable and the "revenue Atriom can't charge" claim is false. **Gross-up:** built — `gross_up_pct`, `grossed_up_expense`, `variable_pct`, `controllable_pct`. **Configurable basis:** built — `expense_basis`, `estimate_basis`, `denominator_basis`. Only **pool exclusions** remain unverified. |
| ~~**3**~~ | ~~**Automated rent escalation**~~ ✅ **SHIPPED (2026-07-19)** | `RentEscalationService` + `leases:apply-escalations` (scheduled daily): a lock-safe, idempotent sweep over active `fixed_percent` leases with `next_escalation_date ≤ today` — applies the increase through `LeaseRentChangeService` (base-rent Charge + marketing levy synced) and rolls the date forward a year. **CPI is skipped** (no index feed — inventing a number would be inventing data). | [01](gap-analysis/competitors/01-lease-billing.md) | M |
| ~~**4**~~ | ~~**PDC (post-dated cheque) register + lifecycle**~~ ✅ **SHIPPED (2026-07-19, module [33](modules/33-post-dated-cheques.md))** | Forward-cheque register + maturity schedule + bounce lifecycle (held → deposited → cleared/bounced; cancel). **v1 register-only, settle-on-clear**: the invoice stays open until the cheque clears, when a cheque Payment is recorded (AR via `recomputeTotals`, allocation capped at balance) — no AR-invariant risk. `pdc:scan-maturing` daily. Notes-Receivable-on-receipt accrual deferred to the accountant. | [03](gap-analysis/competitors/03-deposits-utilities-portal-owner.md) | M |
| ~~**5**~~ | ~~**Vendor compliance / COI gate**~~ ✅ **SHIPPED (2026-07-19)** | Vendors now carry `coi_expires_at`/`insurer`/`policy_number` + a private COI document; `Vendor::isDispatchable()`/`scopeAssignable()` (active + COI not lapsed). The `FacilityWorkOrder::saving()` hook **blocks dispatching a blacklisted/inactive or lapsed-COI vendor** (the single server-side gate, assignment-time only so existing orders aren't retroactively broken) and all three module-26 vendor pickers filter to the assignable set. A COI-status column/badge surfaces expiry. | [06](gap-analysis/competitors/06-vendors-areas-permits-violations.md) | M |

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
| ~~Opening balances tool~~ | ✅ **Shipped 2026-08-18.** `Pages\OpeningBalances` + `ImportOpeningBalancesService` — paste the accountant's trial balance (tab, semicolon or quoted CSV), see every bad row at once, then create a **DRAFT** journal entry to review and post from the journal screen. Draft on purpose: an import run twice would otherwise double the whole balance sheet in silence, and posting is the accountant's assertion. AR and fixed assets keep their own importers and are excluded to avoid double-counting. `OpeningBalancesImportTest`. | Yes, if migrating |
| ~~VAT return (الإقرار الضريبي)~~ | ✅ **Verified BUILT 2026-08-18** — `Pages\VatReturn`, catalogued and schedulable (it implements `reportCsv()`). TX-06 also separated zero-rated from exempt. | — |
| ~~Bank reconciliation~~ | ✅ **Verified BUILT 2026-08-18** — `ImportBankStatementService`, `MatchBankStatementLineService`, `ReconcileBankStatementService` + the `BankStatements` / `BankAccounts` resources. | — |
| ~~Comparative statements~~ | ✅ **Verified BUILT 2026-08-18** — `ComparativeStatementService`, consumed by `RendersFinancialStatement`. | — |
| Inter-property due-to/due-from | Exact per-property split of shared payments | No |

### Product

| Item | What & why |
| --- | --- |
| **Owner portal is off** | `config/features.php:19` is `false`, absent from `.env`, and forced `true` only in `phpunit.xml:52` — **the Owner panel executes only under test**, so its green tests prove nothing about the shipped default. Needs a product call, a per-property dashboard (today: portfolio roll-up only), and global search (Owner has none, so its search box returns nothing, ever). |
| ~~Credit notes absent from the portal~~ | ✅ **Shipped 2026-08-18.** `Filament\Portal\Resources\CreditNotes` — read-only list + view, scoped by `tenant_id` **and** `visibleToTenant()` (the column defaults to `draft`, so both are load-bearing), status filter derived from `TenantVisibility::visibleFor()`. `PortalCreditNotesTest`. |
| ~~Tenant self-service profile~~ | ✅ **Shipped 2026-08-18.** `Portal\Pages\CompanyProfile` — phone, WhatsApp, contact person and address, writable by the tenant ADMIN only (`disabled()` is the UI, `save()` is the gate). Legal identity (`name`, `tax_id`, `commercial_register`) and the Sanctum `email`/`password` are deliberately excluded and the save takes a whitelist by key rather than mass-assigning the payload. **Bank details still not in the schema, and deliberately not added** — a payout field nobody asked for is account numbers stored with no decision about who may read them. `PortalCompanyProfileTest`. |
| 🟡 **Dashboard drilldowns** | ETA tiles are all clickable. **AR-aging now reaches its drill-down (2026-08-18)** — the chart's description carries a link to `Pages\ArAging`, which has a bucket `Select`, so one link is the whole drill-down rather than one slice. `arAgingDrilldown()` is no longer orphaned. A per-BAR click handler is deliberately NOT built: it can only be JavaScript on the chart canvas, and nothing here can exercise it in a real browser. **Still static:** tenant-mix and top-tenants. |
| ~~AR-aging widget vs page RBAC~~ | ✅ **Fixed 2026-08-18.** `DashboardLayout::seesMoney()` now requires the MONEY_ROLES role **and** `reports.view`, and is asked from both places that publish receivables on the dashboard — the whole `ArAging` chart and the two money stats inside `MallStats`. It was LATENT, not live: all six roles carrying those widgets hold `reports.view`, so the gates agreed by coincidence rather than by construction, which is exactly what makes a later revocation surprising. `RevokingReportsViewReachesTheDashboardTest` proves the page and the dashboard now answer the same way before AND after a revocation. |
| ~~Period exports for the accountant~~ | ✅ **Verified BUILT 2026-08-18** — `App\Support\ReportCsv` backs CSV on 15 report pages and the resource registers; saved views deliver by email (RP-04). |
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
  `schedule:run` it was discarded. `facility:scan-sla-breaches` runs **hourly** and
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
> **⚠️ "Everything below is operator or environment work, not code" — that claim was WRONG, and it
> cost nineteen days (corrected 2026-08-18).** It was written about the backup row, and the backup
> row turned out to be a missing config seam: `Health::checkBackupCapability()` read
> `database.connections.{driver}.dump.dump_binary_path`, a key that existed on no connection, so it
> could only ever resolve to `''`. The check was correct and **unanswerable** — no operator could
> have configured their way out of it. A second bug sat behind it, in code as well
> (`CRITICAL_TABLES` naming a table that has never existed, so the restore drill would have failed
> on every healthy archive). Both are fixed.
>
> The lesson is not that the remaining rows are secretly code — most genuinely are configuration.
> It is that **"this is ops, not code" is a claim to verify, not a conclusion to record**: stated
> in a roadmap it stops anyone looking again, which is what happened here. Verify before writing it
> down. Two more rows in §5 were stale in the same way and are corrected in place.

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

### 8.1 ~~Tax — take the rate out of settings *and* out of the operator's keyboard (TX)~~ ✅ SHIPPED 2026-08-12

> **This whole section is history.** TX-01 and TX-02 both shipped on 2026-08-12 and every departure
> below is closed. It is kept because the reasoning — *why* a rate is master data rather than a
> setting, and why an override is a permission rather than a prohibition — is what a future change
> has to argue against. **Do not read the paragraph below as the current design:** none of
> `TaxSettings::vat_standard_rate`, `charge_codes.vat_treatment` or `charge_codes.vat_rate_override`
> exists any more. Today, *which* supplies are taxable is `charge_codes.tax_code` and *what that tax
> charges* is the dated `tax_codes` + `tax_rates` catalogue at `/admin/tax-codes`.

**What Atriom did until 2026-08-12.** The rate was one number in an application setting
(`TaxSettings::vat_standard_rate`); *which* supplies were taxable was data on the charge code
(`charge_codes.vat_treatment` + `vat_rate_override`, shipped 2026-08-11); one resolver
(`App\Support\Vat::rateForType()`) was called by every origination point; and an issued document
froze the rate it was billed at. **That last property was already correct and was not touched** — it
is what keeps the books tied to the filed returns.

**Three places it departed from every reference system — all three now closed:**

1. ~~**The rate is typed on the document.**~~ **Closed** — the rate is now read-only without
   `tax_codes.override` (withheld from `manager`), enforced server-side by
   `App\Support\CatalogueTaxRate` from the items repeater's `mutateRelationshipData…Using` hooks.
   *Originally:* `invoice_items.vat_rate` was a free 0–100 `TextInput`
   ([InvoiceForm.php:240](../app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php#L240)),
   and so is a credit-note line
   ([CreditNoteForm.php:203](../app/Filament/Admin/Resources/CreditNotes/Schemas/CreditNoteForm.php#L203)).
   The comment there states the intent plainly — *"the operator can still type a different rate on
   the line"*. So `Vat::rateForType()` governs the **default**, and nothing governs the **value**.
2. ~~**The rate has no effective date.**~~ **Closed** — a rate is a dated rung on its tax code, and
   `Vat::rateForType($code, $on)` resolves for the DOCUMENT's date, so a rise can be entered in
   advance and a back-dated invoice keeps the rate that was in force. *Originally:* Egypt moved
   10% → 14% in 2017. When it moves again, editing
   the setting re-rates everything originated afterwards — *including* an invoice back-dated into
   the old regime, which is the one case that must not follow the new rate.
3. ~~**`TaxSettings::wht_default_rate` is a single number.**~~ **Closed** — withholding is a tax
   code like any other, and no rate lives in settings; the setting that remains names the default
   *nature* of a supplier's payments, not a percentage. *Originally:* Egyptian withholding (Income Tax Law
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
| **TX-05** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `vendors.withholding_tax_code` + `vendors.withholding_exempt` replace the free percentage box; `TaxSettings::wht_default_rate` is **deleted** and `wht_default_tax_code` (a code, ships empty) replaces it — the last rate has left settings. Two columns because the old one overloaded null ("not ruled") against 0 ("exempt"). Rates resolve per PAYMENT DATE and drop the catalogue's negative sign. The vendor import now validates a CODE against the catalogue, so a spreadsheet carrying "2" is refused instead of quietly withholding a rate the operator's sheet does not contain. *(Original row below.)* **WHT by nature** — a `withholding` scope in the same catalogue, picked per vendor or per payment nature; `TaxSettings::wht_default_rate` is deleted, not kept alongside. |
| **TX-06** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** The taxable base is split by the line's tax code, not by `vat_rate > 0` — so **zero-rated is now its own line on the return**, separate from exempt. Non-VAT families (stamp, schedule) are excluded from both the base and the output-VAT tie-out, so commissioning them cannot silently corrupt it. Lines predating the catalogue fall back to the rate heuristic and are **counted and surfaced** (`unclassified_lines`), because "no zero-rated supplies" and "we cannot tell" are different answers and only one is safe to file. *(Original row below.)* **VAT return reads the catalogue** — exempt and zero-rated separate on the return because the code says so, not because someone remembered. `VatReturnService` currently infers from stored rates. |
| **TX-08** | 🟠 | 🧑‍💻 | **Wire the GL for stamp + schedule tax** — the "GL wiring (later)" line the catalogue document has carried since 2026-07-19, and now the only thing keeping eleven of the operator's own codes switched off. Needs a posting role + chart account + mapping per family; then naming it on the code activates it. Blocked on the accountant confirming **stamp applicability**, which is the same sign-off that document has always said it needs. |
| **TX-07** | ✅ | 🧑‍💻 | **CLOSED AS DECIDED 2026-08-12 — the decision IS the deliverable, and it is already implemented.** Every structure added by TX-01→06 is `tax_*` (`tax_codes`, `tax_rates`, `invoice_items.tax_code`), and the invoice line reads "Tax %" from the catalogue. Carried as open work it overstated the backlog by one row. *(Original reasoning below.)* **Naming: `vat_*` → `tax_*`, new structures only.** The concept is spelled `vat_` on ~14 committed money columns. Reference systems name the field *tax* and treat VAT as one *kind* — that is what lets withholding, schedule tax and stamp duty share the layer. **Recommendation: do NOT rename the stored columns.** They are on posted documents, and the rename would reach the frozen e-invoicing module. Instead: every new structure is `tax_*` (`tax_codes`, `tax_code_id`), and the UI label comes from the catalogue, so a jurisdiction that calls it something else needs no deploy. Decided this way deliberately — half-renamed is worse than either end state. |

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
| **DF-01** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12** (status marker was stale until 2026-08-12). `App\Support\LeaseTerm` + `deriveExpiry()`/`deriveTerm()` on the lease form, all three fields `live(onBlur:)`. Bidirectional and both editable: commencement or term recomputes the expiry, and a typed expiry recomputes the term. `monthsBetween()` is defined BY the forward rule (estimate ±1, accept only an exact match) so the two directions cannot disagree about a month-end. Validation that the pair agrees is no longer needed on the form because they can no longer disagree — the importer still refuses a row stating both and disagreeing, since neither can be preferred there. |
| **DF-02** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12** — the renewal modal now shows the expiry its term and commencement produce, before the operator commits a new contract. It took a term and a start date and displayed no end date at all. |
| **DF-03** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** One rule for typed and generated invoices. It changed two existing tests, which is the interesting part: a `fillForm` handing over every field at once can no longer produce an invalid date pair, so both refusals now `->set()` the derived field LAST — the way an operator actually reaches that state. The guards still bite there. |
| **DF-04** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `App\Support\DerivedFields` + `DerivedFieldsConformanceTest`: a schema exposing every field of a registered group as an INPUT must be classified derived or exempt-with-a-reason, every "derived" claim must name a test file that exists, and the gate carries its own smoke test so it cannot silently stop matching. **It found the remaining hole immediately** — the lease IMPORTER took commencement, term and expiry with no relationship between them, so the bulk path could still create the disagreement the form now prevents, a hundred rows at a time. *(Original row below.)* **Make it systemic, or it decays.** One `App\Support\Forms\Derives` helper (pre-fill · live · back-derive · stays editable) plus a registry of derivable pairs and a conformance test that fails when a form exposes both sides of a registered pair without wiring it. This is the only version of "the whole system behaves this way" that is still true after the next form is added — the same play as `PropertyIsolation` and `ChangeImpact`. |
| **DF-05** | ✅ | 🧑‍💻 | **RETIRED 2026-08-12 — the row was wrong, and all four items were false.** Verified against the code rather than re-guessed: **fixed-asset depreciation end** — `fixed_assets` has no end column at all, and should not (the schedule is computed month by month by the posting service; `disposed_on` is a fact, not a projection). **Vendor-contract term** — `vendor_contracts` has `start_date`/`end_date` and **no term column**, so there is no pair; what IS derivable there, `notice_deadline = end_date − notice_period_days`, was already derived in the model's saving hook. **PDC maturity** — `cheque_date` IS the typed maturity, not a derivation of it; the one real derivation (bulk series lodging, first date + interval) already exists. **Work-order SLA due** — computed by `SlaResolver` and never typed into a form. The row had been carried as outstanding work on a plausible guess. **What shipped instead is the thing that stops this recurring:** `DerivedFields::candidatesIn()` SCANS every Filament schema for a start date plus an end date or a duration, and `CANDIDATE_VERDICTS` must carry a verdict (`DERIVES` / `NO_TARGET` / `INDEPENDENT`) with a real reason for each. A list of known groups can only answer "are the two I listed still handled?"; the scan answers "is there anything left?", and keeps answering it as forms are added. Mutation-tested. |

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
  numbers that drive billing. **CFG-03 closed the billing half of this 2026-08-12** (late fee, grace,
  minimum, NSF, payment terms); SLA already had `sla_policies`, and billing day + time remain global.
- **Policy still hardcoded**: AR ageing buckets 30/60/90 ([ReportService.php:37-40](../app/Services/Reports/ReportService.php#L37-L40)), the default lease term (36), default payment terms (`?? 7`, repeated across eight services), document-number formats, fiscal-year start, rounding.

| Row | P | Owner | Work |
|---|---|---|---|
| **CFG-01** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `App\Support\SettingsRegistry` derives the form state AND the write-back from the settings classes by reflection, so `mount()` and `save()` are no longer hand-written maps beside the schema. `SettingsPageConformanceTest` proves both halves end-to-end: a field exists for every property, and a value put into the real form comes back out of the settings object. **It found three live settings with no screen at all** — `auto_apply_tenant_credit`, `holdover_default_rate_pct` and `levy_rate_percent`, the last of which CLAUDE.md called "configurable" — plus `TaxSettings` missing from `config/settings.php`. *(Original row below.)* **Declarative settings registry** — each `Settings` class declares its fields (type · section · validation · label key); the page renders and saves generically. Deletes ~250 lines of hand mapping and the entire inert-screen bug class. Conformance test: every public property of every registered class is rendered **and** written back. |
| **CFG-02** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12** — who / when / old → new, through the same activity log as everything else, written only when something actually changed. *(Original row below.)* **Settings audit trail** — who / when / old → new, through the same activity log as everything else, surfaced as a tab on the page. |
| **CFG-03** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** Three tiers — **lease → property → portfolio** — resolved through the single accessor `App\Support\PropertySettings::get($key, $assetId)`, which takes the asset **explicitly** rather than reading the panel's selected property: the callers are billing services that also run from the scheduler, where a contextual fallback would give one answer in a request and another in the nightly run, on money. Five keys, each WIRED and each proved against the real service (late fee percent/grace/minimum → `LateFeeService`, NSF → `BillBouncedChequeFeeService`, payment terms → lease origination). `/admin/property-overrides` edits the selected property; a blank field inherits and says so twice, because a blank that reads as zero is the whole risk of an override screen. **SLA hours were deliberately dropped from the list** — `sla_policies` is already a per-property override with its own resource, and a second way to say the same thing would disagree with the first. **It also found a live inert setting:** `payment_terms_days` is NOT NULL with a database default, so the `?? defaultPaymentTermsDays()` at eight billing call sites could never fire and CFG-04's configured default reached nothing; the default now applies at **origination**, which is both the fix and the correct semantics (changing it must not re-age receivables already raised). *(Original row below.)* **Per-property overrides** for the settings that legitimately differ (billing day + time, late fee, grace, NSF, SLA hours): global default + optional override, resolved through **one** accessor so a service cannot read the global by accident. Yardi standard. |
| **CFG-04** | ✅ | 🧑‍💻 | **PART SHIPPED 2026-08-12 — the two with real consequence.** **AR ageing boundaries** are `BillingSettings::ar_aging_bucket_days`, read through `App\Support\AgingBuckets`. That also closed a duplication the old const's docblock warned about while carrying it: the ranges were in `AGING_BUCKETS` **and again as literals** inside `agingBucketKey()`, the classifier every invoice goes through — so moving one would have left the summary and the drill-down disagreeing about which bucket an invoice is in. Labels derive from the boundaries, so they cannot claim a range the classifier does not use; a mistyped set clamps rather than throwing. **Default payment terms** replace `?? 7` at twelve call sites — though **CFG-03 found that half of that was inert**: `payment_terms_days` is NOT NULL with a database default, so the `??` never fired at the eight *billing* sites. The setting now applies at lease **origination** instead. **FISCAL YEAR SHIPPED 2026-08-12** — and it was the one with real consequence. `FiscalCalendar` hardcoded 1 Jan–31 Dec and admitted it in its own docblock ("a fiscal year starting in another month is a future option"), while the reports already read `fiscal_years.starts_on` and honoured whatever they found. So the data model supported a July year all along and **nothing could create one**: an entity on a July–June year would run every income statement, every year-end close and every period gate on somebody else's calendar, fixable only by a deploy. Now `AccountingSettings::fiscal_year_start_month`, with the periods walked FORWARD from the start so period 1 is the first month traded. Two traps pinned by mutation: `endOfYear()` on a July start gives 31 December — a silent six-month "year" that still ties out — and periods numbered from January read the year backwards in every close report. **Changing it once an entry is POSTED is refused, not warned about**, because moving the start re-dates periods that already hold entries: a document posted into an open period lands in a closed one, or an entry the accountant closed and reported becomes editable again. Keyed on posted entries rather than on "a calendar exists", so a fresh install can still choose — which is the moment it is actually chosen. A year is named for the calendar year it STARTS in; stated because the convention varies and this reading leaves January installs byte-identical. **NUMBERING + LEASE TERM SHIPPED 2026-08-12.** `App\Support\DocumentNumbering` — ten document prefixes (`INV`, `CN`, `JE`, `BILL`, `EXP`, `DEP`, `PAY`, `PR`, `LSE`, `PDC`) were literals inside ten models, so "our invoices are numbered TX, not INV" was a deploy. This is the item with a **deadline**: after go-live the prefix is on issued documents that cannot be renumbered. Changing one does not renumber anything — numbers are `MAX()` **within a prefix** — so the type simply gains a SECOND series, which is stated plainly because an Egyptian tax-invoice series is expected to run continuously and an auditor will ask about the jump. Allowed anyway: refusing would block a legitimate need, and the operator is accountable for their own numbering. **Two document types sharing a prefix IS refused** — the unique index is per table, so nothing would error and invoices and credit notes would just interleave one sequence, reading as though documents had gone missing. **It found a live collision:** payroll and purchase requests both shipped `PR-{asset}-{YYYYMM}-`, so `PR-AW-202603-0007` could be either; payroll moved to `PAY` (purchase requests had five tests asserting `PR`, payroll none) — free to fix now, not after go-live. Also `default_lease_term_months`, replacing a literal 36 on the lease form, clamped to ≥1 so a mistyped 0 cannot produce a lease the model's own guard then refuses. **Rounding stays hardcoded, deliberately — this row is closed.** 2dp throughout, which is what EGP is quoted and invoiced in; no operator has asked for another and no Egyptian requirement implies one. Building a setting nothing consults is the inert-setting trap CFG-06 now gates against, so it is a non-build rather than an omission. |
| **CFG-05** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `/admin/configuration-health` — six checks that read the LIVE database, each saying **what the gap breaks** rather than which field is empty ("tenants cannot reclaim the VAT you charged them", not "seller_tax_registration_number is empty"). `atriom:health` answers *is it alive*; this answers *is it set up*, and the two fail differently — a perfectly healthy install bills through a floor rate because nobody classified the charge codes. Severity is about money and law, not tidiness. Every check is tested from both sides: a checklist that reports all-clear because its detection is broken is a green light nobody earned. *(Original row below.)* **Configuration health page** — what is unset and what it breaks; the in-app form of [GO-LIVE.md](GO-LIVE.md), and the analogue of Yardi's setup checklist. `atriom:health` already computes most of it. |
| **CFG-06** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `SettingsReachConformanceTest` over `App\Support\SettingsReach`. **Two halves, because a name-grep is not enough.** Half one: every public property of every settings class must be read somewhere outside `app/Settings` and the settings page — catches the setting nothing consults. Half two is the one that matters: every `?? <settings read>` over a **NOT NULL** column is refused unless declared, because that fallback is dead code and the setting silently applies to nothing. That is precisely the shape CFG-03 found by hand — `default_payment_terms_days` had a screen AND a reader and was still inert at eight billing call sites. Both halves were mutation-tested; half two names the offending file and line. **It found a third live inert setting:** `eta.issuer_name` and `eta.issuer_tax_registration_number` are `->required()` on the settings page and read by **nothing**. Not fixed (module 16 is frozen) but registered in `KNOWN_INERT` — deliberately a separate list from `EXEMPT_NO_READER`, since "this is correct" and "this is broken and here is why we have not fixed it" are different claims, and collapsing them is how a real gap becomes invisible again. *(Original row below.)* **Write-side/read-side conformance test** — every setting a screen writes must be the one the code reads. The inert-screen trap becomes a gate instead of a memory. |

### 8.4 Reports — Yardi shape and delivery (RP)

**The coverage is already strong** and should not be rebuilt: 19 report pages (AR ageing + by type +
collections, rent roll, expiration schedule, occupancy cost + map, sales analytics, trial balance,
income statement, balance sheet, cash flow, general ledger, VAT return, weekly spend, monthly close),
CSV on all six main reports, PDF for monthly close and tenant/asset statements, and
`ComparativeStatementService`. **The gaps are shape and delivery, not what is measured.**

| Row | P | Owner | Work |
|---|---|---|---|
| **RP-01** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `/admin/report-hub` — nineteen reports in one categorised, searchable index, each with **a sentence saying which question it answers** (that is the part that earns it: "AR ageing" and "AR ageing by charge type" are indistinguishable as titles). Listed exactly when the operator can open it, by asking each page's own `canAccess()` rather than copying a permission that would drift. `App\Support\ReportCatalogue` + `ReportCatalogueConformanceTest` classify every admin page as a report or exempt-with-a-reason, so the twentieth report cannot ship reachable only by URL. The per-report sidebar entries stay where they are — this adds a way in, it does not move the furniture. *(Original row below.)* **Report hub.** `/admin/reports` is the monthly close, not a catalogue. Yardi's Reports menu is a categorised, searchable list — Financial · Leasing · Operations · Tax — each with a one-line description and a last-run stamp, plus favourites. Every report is already a registered page; this is an index over them. |
| **RP-05 fix** | ✅ | 🧑‍💻 | **2026-08-12 — a saved financial statement saved no parameters.** `ReportParameters::parametersOf()` excluded EVERY trait property, because reflection reports a trait's property as declared on the using class and Filament's `InteractsWithTable` would otherwise put `isTableLoaded`/`isTableReordering` into every saved view. It also excluded `ScopesLedgerReport` — `$year`, `$period`, `$assetId`, the whole parameter surface of **Income Statement, Balance Sheet, Cash Flow, Trial Balance, General Ledger and VAT Return**. Saving "Income Statement — September, Cairo Festival" stored an empty set, and re-opening it (or a **scheduled email delivery** of it, RP-04) rendered the DEFAULT period — an owner receiving a statement headed one month and filled with another's numbers, silently. The line is now OWNERSHIP not mechanism: a first-party trait under `App\` is our own code factored out and its public typed scalars are parameters; a vendor trait is infrastructure the page did not choose. Mutation-tested (5 of 6 cases go red without it), and the regression covers all six reports rather than the one that was noticed. |
| **RP-02** | ✅ | 🧑‍💻 | **PART SHIPPED 2026-08-12 — the shared vocabulary.** `App\Support\ReportFilters` owns `asOf` · `from` · `to` · `property`, and eight report pages now compose from it. **The concrete gap it closed:** "as of which date?" was asked by five reports, each hand-rolling an identical `DatePicker::make('asOf')` under **four different translation keys** (`reports.aged_as_of`, `rent_roll.as_of`, `expiration_schedule.as_of`, `sales_analytics.as_of`) — individually reasonable, collectively inconsistent, and a sixth report would have invented a fifth. Deliberately a vocabulary rather than one bar widget: an ageing report is asked *as of* a date, a statement *for a period*, a spend report *between* two, and forcing one shape would show every report a control it has no use for. `$onChange` is a REQUIRED argument because every filter is `->live()` over memoised rows — a filter that updates state without clearing the cache renders old numbers under a new date, which is invisible and looks authoritative. `ReportFilterConformanceTest` refuses a report page that hand-rolls one, scoped to report pages so ordinary table filters named `from`/`to` are not swept in; three `assetId` pickers are exempted with reasons (the map's is required, the ledger's scopes by posting DIMENSION). **Per-user memory shipped too:** `report_preferences` + `App\Support\ReportPreferences`. **Dates are deliberately NEVER remembered** (`VOLATILE`) — a remembered as-of date opens the AR ageing three weeks later on totals struck at a date the operator did not choose and did not notice, which is the same invisible-but-authoritative failure as a stale filter cache. "What I picked last time" is also least likely to be right for a date, and most likely to be right for a property: the slice is remembered, the moment never is. An explicit URL still beats the memory, so a shared link or a saved view (RP-05) means what it says for every recipient. **The first cut of this was dead on arrival** — remembering was wired only into `ReportFilters`, and the sole rememberable parameter (`assetId`) is declared by the three pages EXEMPT from that component, so nothing ever called it while every resolver test passed; caught by adding tests that drive the real Livewire page, and pinned by them. **COMPLETE 2026-08-12.** Comparison basis shipped with RP-06. "Include-inactive" turned out to be **misnamed**: the rent roll already includes terminated and expired leases and then filters to those live on the as-of date, which is what a rent roll MEANS — a toggle there would make the report wrong. The real exclusion is in the ledger reports: `aggregate()` starts from `journal_lines`, so an account nobody has posted to is **absent rather than zero**. That is the right default (a trial balance of 400 rows, 300 of them zero, is harder to read, not more complete) and the wrong answer for the one thing a trial balance is for — an accountant asking "is that account really nil, or did nobody map it?" gets no answer from absence. Now a **Show accounts with no movement** toggle on the trial balance only, as Yardi places it; a hundred zero revenue accounts on an income statement would be noise. Zero rows are structural and carry zero in both columns, so they cannot move `total_debit`, `total_credit` or `balanced` — turning the switch on must not disturb the tie-out the report exists to prove, and a test pins exactly that, plus the join risk of listing a moved account twice. |
| **RP-03** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** "Save this view" on every catalogued report; saved views list first on the hub, one click to re-run. Parameters are read from each page's own public scalar properties by reflection (`App\Support\ReportParameters`), so a report that grows a filter has it saved with nothing to register — and applying is deliberately lossy, dropping keys the report no longer declares. **Sharing publishes filters, not access:** the hub asks the report's own `canAccess()` before listing a shared view, and the report re-clamps every parameter exactly as it does a hand-typed URL. *(Original row below.)* **Saved report versions** — name a set of parameters and re-run it. Yardi's "report versions"; prerequisite for RP-04. |
| **RP-04** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** A saved view carries a schedule (monthly/weekly, day, recipients) and `reports:deliver` — on the scheduler at 06:00 — emails it as CSV. **It renders AS the person who saved it**, because a report reads whatever the current user may read and a console command has no current user; their `canAccess()` is re-checked, so a schedule stops when access is withdrawn rather than being a standing grant. Idempotent: `last_delivered_on` is claimed under a lock inside the transaction. **Fourteen of twenty reports are deliverable** — every one that has a CSV at all. The six that are not are a checklist, a floor plan, a diagram, a searchable log, a dry run and a PDF pack: they have no CSV to send, which is a better reason than "nobody lifted it yet". `ReportCatalogue::NOT_DELIVERABLE` names each, gated so a new report must declare which it is. *(Original row below.)* **Scheduled delivery** — email a saved report on a schedule (the month-end pack to the owner). Nothing exists today; the scheduler, the PDF builders and the CSV exporter all do. |
| **RP-05** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** A statement account row opens the general ledger for THAT account, **carrying the report's own year, month and property** — landing on "this year, all properties" would answer a different question from the one clicked. A ledger line opens the document that caused it, resolved through `Filament::getModelResource()` (`App\Support\SourceDocumentUrl`) rather than a hand-kept map, so a new posting source is linkable the day its resource exists. Every failure — no resource, no edit page, a record the operator may not view, a deleted source — renders plain text instead of a dead link. **Note the hazard it introduced and closed:** `assetId` now arrives in a query string, so it is clamped to the operator's visible set. *(Original row below.)* **Drill-down.** Verified: no row URLs on the income statement, trial balance or general ledger. A statement line should open its GL entries, and a GL line its source document. **This is the single biggest "why doesn't it feel like Yardi" difference** — the numbers are right, they are just terminal. |
| **RP-06** | 🟡 | 🧑‍💻 | **COMPARATIVE HALF SHIPPED 2026-08-12; budget-vs-actual still a build.** The finding: `ComparativeStatementService` was written, documented and covered by five passing tests — and called by **nothing but those tests**. An operator could not produce a comparative income statement at all. The third instance of that shape in a week (a correct mechanism with no consumer, invisible because every test of it passes). Now a `comparison` parameter on `/admin/income-statement` — **prior period** ("is this month normal?") or **prior year** ("is this March normal?", the only one that survives a seasonal business, and a mall is seasonal). Prior period keeps the same LENGTH, not the same calendar month, because comparing 31 days against 28 invents a variance that is really just February. Columns are prior · change · change %, coloured by DIRECTION only and never by "good" — a rise is welcome in revenue and unwelcome in expenses, and the table does not know which the reader is looking at. A change % over a zero base renders `—` rather than an invented +100%/∞. **The comparison travels with the CSV export**, because a statement read with prior columns and exported without them is a different document under the same name. Default stays no-comparison, so existing saved views and scheduled deliveries render unchanged. **Still to build: budget-vs-actual** — there is no budget model at all, so it is a data model plus an entry screen, not a column. |
| **RP-07** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** `App\Support\ReportXlsx` — bold + filtered header, frozen at row 2, widened columns, `#,##0.00` on money, RTL when the report rendered in Arabic. **No new dependency:** `openspout/openspout` was already present transitively and is now an explicit `composer require` (depending on someone else's transitive dep breaks silently on their next update). **The point is not the extension.** A CSV hands Excel a string and Excel guesses: a column of guessed strings does not sum, and `01234` becomes 1234, so the account code is silently wrong. Types are declared per cell — floats get the money format, ints deliberately do not (a year rendered `2,026.00` is worse than useless). Proved by READING the workbook back, not by asserting a download happened. **Both exports now come from one `ExportsReport` concern**: fourteen report pages each carried their own copy of the CSV action — five subtly different copies — and adding Excel to each would have made twenty-eight. One `reportCsv()` description feeds both formats, so the two exports of a report cannot disagree about what it says. |
| **RP-08** | ✅ | 🧑‍💻 | **SHIPPED 2026-08-12.** One zip per owner per period, from the owner-statement run: income statement · balance sheet · cash flow · rent roll · AR ageing, one folder per property, each as XLSX. Replaces opening five reports, setting the property on each, exporting each and attaching five files — per owner, per month, with a chance at every step of attaching the wrong property's file. **The whole risk is scope**, and a leak here looks exactly like a working feature (the file opens, the numbers are real). **Mutation testing found the guard was not what the code said it was:** deleting the per-asset `setTenant()` leaked nothing, while deleting `Auth::login($owner)` put another landlord's tenants straight into the pack — `TenantScope` derives visibility from the AUTHENTICATED USER. The docblocks now name the load-bearing guard, because a comment crediting the wrong one is how the real one gets deleted by somebody tidying up. **It also found a false-pass test of my own:** the first leak test asserted on FOLDER NAMES, which come from the loop rather than the report contents, so it stayed green under a portfolio-wide render; it now reads the rent roll back out of the zip and looks for the other landlord's tenant by name, with a control that the owner's own tenant IS there. Tenure via `currentOwnedAssets()`, so a landlord who sold in March gets no April figures; an owner holding nothing is REFUSED rather than sent an empty zip that reads as "your malls earned nothing". `OwnerPack::EXCLUDED` records what is deliberately not in it — procurement, payroll, work orders, tenant sales — because an owner is a landlord, not an operator. |

### 8.5 Suggested sequence

**TX-01 → TX-03** first: it is the only workstream touching tax on issued documents, and the longer
free rate entry stays, the more history is entered under it. **DF-01/03/04** next — small, and DF-04
is what stops the pattern decaying. **CFG-01/02** then, because every later configuration row is
cheap once the registry exists and expensive before it. **RP-05 and RP-01** last of the 🟠 rows: the
most visible change per unit of work, and both are additive.
