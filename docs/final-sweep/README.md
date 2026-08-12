# Atriom — final pre-staging sweep

> **Date:** 2026-08-11 · **Scope:** the whole system — 48 admin resources, 7 portal resources, 93
> models, 85 services, 195 migrations, 52 API routes, ~103k LOC in `app/`.
>
> **Purpose:** the last full look before staging. Four lenses, as commissioned: **competitive parity**
> (Yardi where it applies, the best alternative where it doesn't), **UI/UX and ease of use**,
> **money cycles and the GL**, and **whatever else a final sweep should cover** — which turned out to
> mean security, performance, data integrity, operational readiness and test honesty.

| File | Covers |
|---|---|
| [01-yardi-parity.md](01-yardi-parity.md) | Leasing & space vs Yardi · facility/vendor/procurement vs the FM specialists · the modules Yardi doesn't cover · **dead weight to remove** |
| [02-ux.md](02-ux.md) | The admin panel (all 48 resources) · portal + mobile API · "can an operator run a month?" |
| [03-money-gl.md](03-money-gl.md) | AR, recoveries, the ledger machinery, Egyptian tax — the money spine |
| [04-architecture-ops.md](04-architecture-ops.md) | Security · performance & scale · production readiness · **test honesty** |
| [05-stop-point-plan.md](05-stop-point-plan.md) | **What "done" means, and the sequenced route to it** |

---

## 1. The verdict in one page

**The core of this system is genuinely good, and most of it should not be touched.** Two adversarial
agents attacked the money core — the four AR settlement channels, `recomputeTotals()`, both
over-allocation guards, proration, the billing run-lock, late-fee idempotency, credit-note
apply/reverse — and could not break any of them. A third went looking for a money source missing from
the GL registry, diffed every `decimal()` column against all 24 sources, and **found none**. A
security agent probed cross-tenant IDOR on every API route, mass assignment, role escalation, and ~40
raw-SQL sites for injection, and found **no CRITICAL**. VAT origination resolves through
`Vat::rateForType()` at all 18 points with **no tax-rate literal in any computation in `app/`**. EN/AR
key parity is exact: 4,153 keys per locale, zero drift.

**Every serious finding is at an edge**, and three root causes generate most of them:

| Root cause | What it produces |
|---|---|
| **A · A hand-written list is never revisited when the thing it lists grows** | The renewal that drops 14 columns and 3 child collections · the importer that bypasses charge seeding · the rent roll that sums 3 of an open-ended set of charge types · the vendor bill that cascades to payments but not penalties · `EAGER` maps missing the highest-volume sources |
| **B · `void()` leaves the original's lines in place, and four consumers filter `posted`-only** | Phantom CAM credit notes to every tenant in a pool · an overstated VAT return · a bank rec that structurally disagrees with the trial balance |
| **C · A guard is declared but does not behave** | `readOnly()->dehydrated()` over a fillable money column · a `system:` posting-date exemption that is false · 9 of 18 conformance gates that check declaration, not behaviour · `lockForUpdate` inert in every test |

**The single largest risk is not in the application — it is the cut-over.** The only bulk path for
loading a real mall's leases has three stacked faults, no test exercises any importer, and the command
written to validate imports is blind to the exact failure importing creates.

---

## 2. How this sweep was run, and why you can trust the numbers

Fifteen independent read-only agents, each barred from running the test suite, Playwright, seeders or a
server. Each was required to cite `file:line`, to prove any "X is missing" claim by naming what it
searched, and to report what it **attacked and could not break** alongside what it found.

**Then I re-verified every CRITICAL and HIGH myself before it entered these documents.** That pass
changed the outcome materially:

- **6 claims retired as false**, two of them mine. Notably: "no lease abstract" (a gated `ViewAction`
  exists), "`Sum::make('total')` sums the wrong column" (resolved in the Filament source — the
  argument is an *id*), "`->money('EGP')` emits Arabic-Indic digits" (ICU says otherwise), and
  "`invoices.balance` is unindexed".
- **2 severities corrected downward.** The approval-ladder gap is *value tiering inert*, not "any user
  can approve" — base RBAC still applies, and I checked all three call sites. The portal password
  reset is an auth-boundary crossing, not direct account theft — completing it still needs the admin's
  inbox.
- **1 severity corrected upward.** `Lease::generateReference()` was rated MEDIUM by one agent; it is
  CRITICAL.
- **2 claims that contradicted documented invariants were checked in vendor source** before being
  written down — the Filament authorization layering (§FS-23) and the SQLite lock compilation
  (§FS-22). Both held.
- **1 cross-agent contradiction resolved** — two agents disagreed about the lease importer; both were
  right, in sequence.

Where a finding is agent-verified rather than lead-verified, the aspect file says so.

---

## 3. The master ledger

Severity is **impact if it reaches production**. `Fix by` is my recommendation, not a constraint.
Effort: **S** ≲ half a day · **M** ≲ 3 days · **L** > 3 days.

> ### Status at 2026-08-12 — 36 closed, 9 open, and NONE of them is engineering
>
> **A struck-through row is done.** Every row was re-checked against the commits on this date; the
> table had drifted, because Phase 1 recorded its work in the callout below and never struck the
> rows it covered, so thirteen shipped items still read as open. FS-18 and FS-19 shipped in
> `8f5fcfa` and were never marked at all. That is the same failure this sweep named three separate
> times — a list nobody can tell is stale reports coverage it does not have — and it had happened
> to the sweep's own ledger.
>
> | What is left | Rows |
> |---|---|
> | **Engineering** | *(none — every engineering row in this sweep is closed)* |
> | **Operator's environment, not code** | FS-10 (backup infrastructure) · FS-12 (deploy workflow) |
> | **Decisions for the accountant/owner, not engineering** | FS-53 – FS-58 |
>
> **Nothing money-destroying remains open.** FS-17 — the renewal that silently dropped the
> escalation clause and the CAM cap — closed 2026-08-12; it was the one row I would not have shipped
> without. FS-33 closed the same day, and it was the most valuable of the rest for a different
> reason: it is not a bug, it is the thing that surfaces the *next* bug on its own instead of waiting
> for a sweep like this one to go looking.

### 3.1 Must fix before staging

These are either money-destroying, credential-grade, or block the cut-over entirely.

> ## ✅ Shipped 2026-08-11 — FS-01, 02, 03, 05, 06, 11/37, 13, 16, 59
>
> Nine of the sixteen rows below are done and pushed (`50166c3`…`df637af`), each with a regression
> test and each guard mutation-checked. Full suite green: **4,218 tests, 4,214 passed, 0 failures.**
>
> **FS-04, FS-14 and FS-15 also shipped 2026-08-11** (`4851b78`, `65b5d51`, `cd3606c`) — the whole
> code side of the cut-over. **FS-07, FS-18, FS-09 and FS-08 shipped the same day** (`8f5fcfa`,
> `24b639e`, and the CAM pool fix) — the books tier is done except **FS-60**. **Still open:
> FS-10, FS-12** (backup and provisioning — ⚙️ ops, Phase 4).
>
> Three corrections came out of doing the work, and they belong here rather than in a changelog:
> the lease-reference crash needs a lease *nothing references* (which is precisely what the broken
> importer produces, so FS-04 and FS-05 compose); the approval-ladder gap loses value **tiering**
> rather than all gating, because base RBAC survives; and one fix — copying `Invoice`'s
> always-regenerate rule onto `Lease` — was **wrong on the first attempt** and caught by the suite,
> because importing an operator's existing contract references requires those references to survive
> the insert.

| ID | Finding | Class | Effort | Where |
|---|---|---|---|---|
| ~~**FS-01**~~ ✅ | **`pay-demo` API endpoint is live in production** — its only gate is `PAYMOB_ENABLED`, which is false at go-live. A tenant marks their own invoices paid; the GL posts `Dr Bank / Cr AR` for money that doesn't exist, and `billing:reconcile` stays green | REMOVE | S | [03 §1.4](03-money-gl.md) |
| ~~**FS-02**~~ ✅ | **Every wizard-created tenant gets the mobile password `password`** — `LeaseCreationService.php:152`, and no form field supplies one. **Chains with FS-01: knowing only a tenant's email is enough to destroy their AR** | BUGFIX | S | [01 §3.1](01-yardi-parity.md) |
| ~~**FS-03**~~ ✅ | **No password recovery works on either surface** — the portal reset resolves against the admin `users` table (and can mail an admin a reset link); the mobile reset URL 404s | BUGFIX | S | [04 §1.1](04-architecture-ops.md) |
| ~~**FS-04**~~ ✅ | **The lease importer: four stacked faults, and PHPStan already found one.** `$this` is used inside a closure built in `static getColumns()` (`:41`), so `unit_id` is never set on a NOT-NULL column; it declares a non-existent `asset_code` column; it bypasses `seedStandardCharges()` so leases bill nothing; and re-running duplicates every lease. **`atriom:audit-charge-schedules` reports a zero-charge lease as clean**, and no test runs any importer. **PHPStan flagged the first fault twice — both entries are in the baseline** | BUGFIX ×4 | M | [01 §import](01-yardi-parity.md) |
| ~~**FS-05**~~ ✅ | **`Lease::generateReference()` is a deterministic duplicate-key 500** — it counts non-trashed rows against a unique index that includes trashed ones, with no lock. Soft-delete one lease and **no lease can be created for the rest of the calendar year**. `AllocatesDocumentNumber` is the correct pattern, four feet away | BUGFIX | S | [04 §5](04-architecture-ops.md) |
| ~~**FS-06**~~ ✅ | **Write-offs are half-applied** — the GL is relieved, `balance` is not; partial write-offs are uncapped and un-netted (write off 25,000 against a 20,000 debt); and a written-off invoice is offered in the payment picker | BUGFIX | M | [03 §1.2](03-money-gl.md) |
| ~~**FS-07**~~ ✅ | **The `posted`-only divergence** — `void()` leaves original lines in place; four consumers filter `posted` only. Worst case: a cancelled 100k bill drives `total_actual_expense` to −100k and **every tenant in the pool gets a credit note** for money nobody over-collected | BUGFIX | M | [03 §1.1](03-money-gl.md) |
| ~~**FS-08**~~ ✅ | ~~**Two CAM pools on one property both consume the same estimate**~~ — **shipped 2026-08-11.** `estimate_charge_codes` is per-pool; the global constant survives only as the floor for an undeclared **`cam`** pool, and any other pool on the billed basis that has not named its codes is **refused** rather than defaulted. The estimate query also had *no participant filter*, so a food-court pool subtracted the whole property's billing — membership is now one predicate (`participantLeaseQuery()`) shared with the allocation side. `CamPoolEstimateScopeTest`, both mutations checked (reverting either reproduces 100,000 where 0 is correct) | BUGFIX | S–M | [03 §1.5](03-money-gl.md) |
| ~~**FS-09**~~ ✅ | ~~**The VAT return is unreachable, and wrong when reached**~~ — **shipped 2026-08-11.** `/admin/vat-return` (tie-out as the subheading, CSV only, no per-property filter — a return is filed per *registration*), and both halves of the credit-note bug fixed: the documents side nets their VAT, and `base_standard`/`base_exempt` net them **per line** so an exempt credit reduces the exempt supply. `VatReturnCreditNotesTest`, both mutations checked; its last case is an unposted invoice that must still report a discrepancy — netting must not become a relaxed check | WIRE + BUGFIX | S | [03 §2.1](03-money-gl.md) |
| **FS-10** | **No rollback and no recovery** — 171 of 195 migrations drop columns in `down()`, so restore *is* the rollback story, and **the backup has produced nothing for 12 days** (`mysqldump` absent). A bad migration is currently unrecoverable | OPERATOR + EDIT | M | [04 §3.1](04-architecture-ops.md) |
| ~~**FS-11**~~ ✅ | **`.env.example` ships `APP_ENV=local`**, which unconditionally passes 5 of 11 health checks, plus `DB_CONNECTION=sqlite` which makes backup capability report "able to back up" on a box that cannot | EDIT | S | [04 §3.1](04-architecture-ops.md) |
| **FS-12** | **Provisioning omits `mysql-client` and Node** — the first is the mysqldump incident; the second means both panels render unstyled | EDIT | S | [04 §3.1](04-architecture-ops.md) |
| ~~**FS-13**~~ ✅ | **`atriom:install` never seeds `approval_rules` or `departments`** — the FR-CM-11 / FR-PROC-02 **value tiering is inert in production** (base RBAC survives), and `required_permission` freezes null so the audit trail cannot show a control was bypassed | EDIT | S | [01 §2.1](01-yardi-parity.md) |
| ~~**FS-14**~~ ✅ | **`asset_owner` has no UI anywhere** — no way to record who owns a property or their `ownership_percentage`, which the entire owner-statement module divides by | WIRE | S–M | [01 §3.2](01-yardi-parity.md) |
| ~~**FS-15**~~ ✅ | **No opening-balance import** — nothing loads AR, deposits, cash or AP at cut-over; opening AR is hand-keyed invoice by invoice | REIMPLEMENT | M | [04 §3.2](04-architecture-ops.md) |
| ~~**FS-16**~~ ✅ | **"Send WhatsApp" is a stub that reports success.** The setting is genuinely wired, so an operator can switch it on and be told a tenant was chased when nothing was sent | REMOVE or BUILD | S | [02 §3.3](02-ux.md) |
| ~~**FS-59**~~ ✅ | **`mall_admin` privilege escalation.** `PROTECTED_ROLES = ['super_admin','manager']` (`UserResource.php:130`), but `mall_admin` is `$managerPerms + imports.execute` (`RolesPermissionsSeeder.php:506`) — **strictly more powerful than `manager`** and unprotected. `canEdit()` is bare `users.edit` with no self-guard (contrast `canSuspend():97`, which has one). Any `users.edit` holder — `hr` qualifies — grants themselves manager-equivalent access **plus** the import right that `$managerPerms` deliberately withholds. **Root cause A exactly: a hand-written list that was never revisited when a role was added above it** | BUGFIX | S | [04 §1](04-architecture-ops.md) |
| ~~**FS-60**~~ ✅ | ~~**The `owner` role reads the operator's own books.**~~ — **shipped 2026-08-11.** Worse than the row said: sixteen models are **SHARED** and carry no `asset_id`, so the grant was not property-limited at all — Jawad could read Eltizam's whole supplier register across every mall it operates. Now a per-module registry (`App\Support\OwnerVisibility`, 29 visible / 19 internal, each with a reason) filtered into the seeder, gated by `OwnerVisibilityConformanceTest` on **both** unclassified and stale entries. Two existing tests asserted the leak as intended behaviour and were rewritten. Mutation-checked: removing the filter reproduces all 17 leaked permissions | EDIT | S | [18](../modules/18-rbac-scoping.md#jawad-owner-scoping) |

### 3.2 Must fix before the first real billing month

| ID | Finding | Class | Effort |
|---|---|---|---|
| ~~**FS-17**~~ ✅ | **`LeaseRenewalService` drops 14 of 43 columns and 3 child collections** — escalation, the collar, CAM cap, %-rent ladder, parking. Renewal is the most routine act in a mall | REIMPLEMENT | M |
| ~~**FS-18**~~ ✅ | **`billing:reconcile` counts 2 of the 4 settlement channels** — the trust tool cries wolf on the two newest money features, and `MonthEndReadinessService` consumes it, so a correct book reports not-ready at close | BUGFIX | S |
| ~~**FS-19**~~ ✅ | **Voiding a monthly invoice permanently blocks re-billing that lease-month**; also `nsf_fee` missing from the same exclusion list, suppressing that month's base rent | BUGFIX | S |
| ~~**FS-20**~~ ✅ | ~~**No monthly trial balance, P&L or balance sheet**~~ — **shipped 2026-08-12.** A `Period` picker on `ScopesLedgerReport` (Full year default, else any month of the fiscal year) reaches all five pages, the PDF header and the export filename. The finding's second half shipped with it: **`FiscalYear::starts_on`/`ends_on` are now honoured**, so an April→March year no longer silently reports the wrong twelve months. Three mutations, each killing its own cases — including the stale-month-on-year-change reset, without which the two pickers can contradict each other | EDIT | S–M |
| ~~**FS-21**~~ ✅ | ~~**The nightly late-fee job is unbounded and re-entrant**~~ — **shipped 2026-08-12.** The guard (`WithoutOverlapping`, keyed by day, `dontRelease()`), `retry_after` raised to 900 past every job timeout, and the sweep now walks a **snapshot of ids** in chunks — deliberately not `chunkById()`, because a fee invoice on zero-day terms is due TODAY and matches the sweep's own filter, so paging forward on id walks into the fees it just raised. Pinned with a one-row page size, since at 250 that hazard is unreachable in a fixture. New `App\Support\QueueJobSafety` + gate classifies every job serialised/concurrency-safe with a stated reason, verifies the SERIALISED ones really declare the middleware, and fails on any timeout reaching `retry_after` — the arithmetic the two config files could not do for each other | BUGFIX | S |
| ~~**FS-22**~~ ✅ | ~~**`lockForUpdate` is a no-op in every test**~~ — **shipped 2026-08-12.** `App\Support\ConcurrencyPolicy` registers all 68 locking files with their lock COUNT (row locks and atomic cache locks together), and the gate fails on an unregistered file, a registered file that stops locking, and count drift — so one lock dropped from `CreditNoteService`'s eleven is visible. `Tests\Support\LockSpy` then makes the lock **observable on SQLite** by compiling it to a SQL comment (valid, behaviour-neutral), so six critical sections are driven through their real service and assert the table they locked. The gate found real drift on its first run — four files whose only `lockForUpdate` was in a comment, one of them a scheduled scan. And the LockSpy caught a false pass in my own test: with auto-apply-credit on, the late-fee case passed with its own lock deleted, because another service locked `invoices` in the same request. Eight mutations, all killed | EDIT + gate | M |
| **FS-23** | **The authz gate is an `OR` where the rule is an `AND`** — verified in Filament source that `visible()` and `->authorize()` are the *same* layer; only in-closure `abort_unless` is independent. 76 write actions rest on one layer | EDIT + gate | M |
| ~~**FS-24**~~ ✅ | ~~**Derived money columns are client-writable**~~ — **shipped 2026-08-12**, and it was three columns not one: `balance`, `paid_amount` AND `number` on an issued invoice, plus the credit note's whole header. All four exploited in a test *before* being fixed. `paid_amount` is discarded (recomputeTotals persists via `saveQuietly()`, so anything reaching the hook is a payload by construction); `balance` is always re-derived; `number` joined the finalized-invoice refusal list. `CreditNoteItem` got the hooks it never had — `recomputeFromItems()` already existed and was simply never called from the side that changes. **Gate: `App\Support\DerivedMoney` + `DerivedMoneyConformanceTest`**, which classifies every model with a fillable money column and proves each derived one by tampering with a committed record. It deliberately does not grep the forms — a `readOnly()->dehydrated()` looks locked and submits anyway | BUGFIX + gate | S |
| ~~**FS-25**~~ ✅ | ~~**Deposit settlement posting-date guard is missing and the registry says otherwise**~~ — **shipped 2026-08-11.** The `system:` exemption is replaced by a real guard in `ApplyDepositToInvoiceService`, and the whole final account is now one transaction (arrears settlement ran outside it, so a later refusal left the deposit spent while the operator was told it failed). Mutation-checked separately — and a **third** guard at the `settle()` level was written, found unkillable by any test, and *removed*: every write underneath is already guarded, so it would have been a fourth copy of one rule | BUGFIX | S |
| ~~**FS-26**~~ ✅ | ~~**`VendorBill` cascades to payments but not SLA penalties**~~ — **shipped 2026-08-11.** The one-line fix the row implies is *fatal* (`HasMany::withTrashed()` does not exist — a penalty has no `deleted_at`), so the list was split: `ledgerChildRelations()` = rows that follow the parent into the bin, `ledgerDerivedRelations()` = rows with their own life whose ENTRY the parent determines. Writing the test also surfaced a **second** live bug: the derived touch must fire on `status`/`category` too, or **cancelling a bill leaves its penalty entry posted indefinitely**. `VendorBillPenaltyCascadeTest` ages the penalty out of the 2-day window first — without that the sweep finds it anyway and the test is green either way, which is what the first draft did | BUGFIX | S |
| ~~**FS-27**~~ ✅ | ~~**Late fees recognise revenue in the invoice's original month**~~ — **shipped 2026-08-11.** The fee is its own invoice dated when incurred (the CAM / %-rent / violation-fine pattern), linked from the overdue one by `late_fee_invoice_id`; the issued invoice is no longer restated at all. **`late_fee` was added to the already-billed probe exclusion in the SAME change** — a standalone invoice dated into the current month would otherwise have silently skipped that lease's rent, the fifth instance of a class fixed four times separately. Two side effects: the closed-period guard now actually applies to the fee, and it **posts in real time**, which retired the load-bearing 04:00/05:00 schedule ordering (verified, not assumed — the hook is config-gated off in tests) | BUGFIX | S |
| ~~**FS-28**~~ ✅ | ~~**Annual percentage rent bills gross**~~ — **shipped 2026-08-11.** `retrueAnnualYear()` now nets, matching `calculate()`; the cumulative stays gross so the marginals still sum to the year's overage. Mutation-checked (kills 4 of 5; the no-clause control correctly survives). The root shape is recorded in [module 09](../modules/09-tenant-sales-percentage-rent.md#deductions--offsets-pr-03): annual %-rent has **two** implementations of "what does this month owe", and the one that bills is the one nobody looks at — every future clause must go into both | BUGFIX | S |
| ~~**FS-29**~~ ✅ | ~~**WHT is computed on the VAT-inclusive amount**~~ — **shipped 2026-08-12**, while `wht_enabled` is still off, which was the whole point. New `WithholdingTax::onBillPayment()` takes the payment's VAT-exclusive share (correct for partial payments; taken from the bill's tax composition so an SLA penalty can't skew it); `on()` stays as the primitive with a docblock naming the misuse. **The existing WHT suite could never have caught this** — every fixture set `vat_amount => 0`. Both mutations reproduce the finding's exact figures (3,420 vs 3,000; 1,710 vs 1,500), and the operator preview now calls the same function as the service | BUGFIX | S |
| ~~**FS-30**~~ ✅ | ~~**The tax invoice carries no seller TRN**~~ — **shipped 2026-08-12.** `TaxSettings::seller_tax_registration_number` + `seller_legal_name` (operator-level: one registered entity, several malls), rendered on the PDF and editable at Settings → Tax. **Blank by default and the line is omitted when blank** — a placeholder TRN reads as valid, gets filed, and fails on audit. The finding's second half shipped too: a **per-rate VAT summary**, which matters here because base rent is exempt and service charge is not, so one invoice routinely carries both. Reads each line's own rate, never today's setting. Both mutations checked; now a go-live gate item ([GO-LIVE §2 A1.1](../GO-LIVE.md)). *Left standing and stated: `EtaSettings` holds the same number, and collapsing the two needs the ETA freeze lifted.* | EDIT | S |
| ~~**FS-31**~~ ✅ | ~~**The SLA clock only starts if someone clicks Start**~~ — **shipped 2026-08-12**, and it was sharper than reported: `open → done` is a legal hop, so a whole external job could be created, worked for three weeks and closed with `target_resolution_at` still null — no breach, no penalty, no record. Now a **second clock**: response from creation, resolution from acceptance *or from when acceptance was due, whichever came first*. FR-CM-07 is intact — an engineer accepting inside the response window still gets their full window and is never charged for queue time — while accepting late cannot buy more time to finish (`min()`, not assignment). Response breaches alert off their own stamp, filter, and sit on the dashboard; the scan backfills the clocks onto pre-existing orders. Deliberately NOT included: a monetary penalty for an unanswered job — that is a contract question for the operator, not one to invent in code. **Two existing scenario tests asserted the defect as the rule** (one named *"sits on an unaccepted job indefinitely without ever breaching"*); both rewritten in the same change | EDIT | S |
| ~~**FS-32**~~ ✅ | ~~**PPM can silently stop generating for a plan forever**~~ — **shipped 2026-08-12.** Three defects in one chain. **(1)** A renewed certificate still counted as lapsed: compliance asked *"any lapsed row?"* of a file that keeps its history, so doing the paperwork correctly bricked the contractor permanently — new `HasSupersededDocuments::current()`, reached by the row question and the set question through the SAME scope chain, and applied to `TenantDocument` too, which had it in the nag channel. **(2)** A genuinely lapsed COI cancelled the WORK rather than the assignment; the round is now raised unassigned with the reason on it, because the gate governs who is sent to site, not whether a statutory inspection exists. **(3)** A stuck plan is now stamped on its own row (icon + reason + a filter that separates *stuck* from merely *overdue*, which look identical) and alerts mail + bell on the transition. The cycle is still not skipped — a missed round is a backlog item, now a visible one. Eleven mutations, all killed | BUGFIX | S |
| ~~**FS-33**~~ ✅ | ~~**The books can drift silently**~~ — **shipped 2026-08-12.** All three channels closed: the tie-out is **persisted** (four `SystemSetting` keys), **alerted** (`BooksDriftDetectedNotification`, mail + bell, on the transition into drift only), and **surfaced on `/health`** as `books_tie_out`; `billing:reconcile --deep` is scheduled Fridays 04:00; and the standing un-postable-document count — which alerted once and then existed only on a report-page banner — joins the same health check. The reason it was invisible is the reason it was hard to see: the sibling alert **returns early on `$failed === 0`**, which is exactly the state of a ledger that drifts while every document posts cleanly. Alert driven through the real command in test, since a test that constructed the notification by hand would have passed against the old code | EDIT | S |

### 3.3 Fix before scale (mall #2, or ~6 months of data)

**FS-34** dashboard hydrates every open invoice twice, 15 uncached aggregates before the permission
filter · **FS-35** bulk "Download PDFs" uncapped and synchronous · **FS-36** exports and importers run
inline, three hard-coding `sync` · **FS-37** cache/session/queue on MySQL with no health enforcement,
while every `Cache::lock()` crosses the network · **FS-38** GL report pages re-run aggregates 2–5× per
render; the balance sheet has no lower date bound · **FS-39** 126 `whereDate()` calls on already-`DATE`
columns defeating every date index · **FS-40** the GL hot path re-reads mappings per document
(`AccountResolver` not container-scoped) · **FS-41** rent roll / expiration schedule N+1 on
`unit.areas` — *best win-to-risk ratio in the sweep* · **FS-42** nine uncached nav-badge COUNTs, with
`Cache::` appearing zero times in 428 Filament files · **FS-43** the tenant list at 6 queries per row ·
**FS-44** CAM reconciliation holds up to 500 row locks in one transaction · **FS-45** `activity_log`
unbounded with no `created_at` index.

### 3.4 UX — best taken as four shared fixes, not 48 edits

**FS-46** a navigation conformance gate (kills the undeclared 11th group **and** the EN/AR Facility
label mismatch that reorders the sidebar by language) · **FS-47** ban bare `Tab::make()` (Settings has
27 validated fields across 7 tabs and refuses invisibly) · **FS-48** a `CatchesRefusals` trait for the
38 Create pages that discard the whole form on a save-time refusal · **FS-49** a `FormDefaults` sibling
to `TableDefaults` (date format, 22 OS-native pickers, the two currency presentations). Then:
**FS-50** drill-down on the four financial statements (`account_id` is already on every row) ·
**FS-51** the portal cannot explain three of the four settlement channels to the tenant — no credit
notes, no deposit surface, no applied credit · **FS-52** the published OpenAPI spec declares no auth
across all 52 operations and points at the dev host.

### 3.5 Decisions, not engineering

| ID | Question | Owner |
|---|---|---|
| **FS-53** | **Is base rent actually VAT-exempt?** Egyptian VAT Law 67/2016 exempts *non-commercial* letting; mall retail is commercial. The architecture is one row-edit away from either answer — but the seeder asserts near-certainty | Accountant |
| **FS-54** | **Partial-exemption apportionment** — input VAT is recovered 100% while most output is exempt. **Answering FS-53 "taxable" makes this disappear entirely** | Accountant |
| **FS-55** | Marketing fund: revenue or a reconciled fund? Today it is revenue, never trued-up, with no tenant reporting | Operator + contract |
| **FS-56** | Mid-year lease termination and CAM — survivors currently absorb the departed tenant's share and the tie-out stays green | Operator |
| **FS-57** | **Egyptian statutory payroll**: finish it, or descope it for go-live and say so. Shipping payroll that computes zero tax is worse than not shipping payroll | Owner |
| **FS-58** | The All-Properties plumbing (12 files) — a documented deferral with a stated trigger, but it sits against "remove stale work as you go" | Owner, 5 minutes |

---

## 4. What is at or above benchmark — do NOT touch

Stated as prominently as the defects, because the temptation during a sweep is to "improve" these.

1. **The four-channel AR core** — `recomputeTotals()` as single source of truth, and
   `InvoiceItemSettlement` deriving every per-line figure so item outstandings always sum to `balance`.
2. **The one-registry GL rule** — all four dispatch paths deriving from `sources()`; no unregistered
   money source exists.
3. **VAT origination** — 18 points, all through `Vat::rateForType()`, zero literals. The
   rate-vs-taxability split is where Yardi puts it.
4. **Lock-and-recheck discipline** — 110 sites, essentially all `whereKey()`-scoped single rows, plus
   `AllocatesDocumentNumber` holding its lock across the insert.
5. **Credit-note reversal by un-applying the original**, never a second offsetting document.
6. **The never-delete policy** and its three tiers — it is why every operator mistake has a correction
   path that leaves a document.
7. **The date-ranged charge schedule** and the occupied-vs-leased predicate split.
8. **The three-layer facility model** — Demand / Execution / Planned generator.
9. **The perpetual stock ledger**, GRNI FIFO sibling re-derive, and the procurement state machine.
10. **The unauthenticated shopper surface** — the best-engineered new surface in the product.
11. **`ChangeImpact`'s conformance gate** — it proves REFUSED by dirtying a committed fixture *and*
    carries a negative control. **The exemplar to copy.**
12. **`ChargeCode`'s container-scoped memo with observer invalidation** — the caching pattern to copy.
13. **EN/AR parity** — 4,153 keys, zero drift, 3,325 `__()` references with zero undefined.
14. **No money is recomputed anywhere in portal or API code** — verified by sweep.
15. **`Health`'s file-based heartbeat**, and `atriom:install` verifying through the journalizers' own
    resolver so "installed" means proved.

---

## 5. The pattern worth naming

> **This project fixes bugs precisely and locally, and has no mechanism that asks "where else?"**

Six of the findings above are the *un-propagated half* of a fix that already exists somewhere in the
codebase: `AllocatesDocumentNumber` has the reference fix that `generateReference()` lacks;
`LedgerReportService` has the void rule that four other services lack; `PostDatedChequeForm` has the
status filter the payment picker lacks; `UnitImporter` has the property clamp and idempotency that
`LeaseImporter` lacks; `RunMonthlyBilling` has the overlap guard `ApplyLateFees` lacks; CAM true-ups
and %-rent overages have the own-invoice-dated-now pattern that late fees lack.

That is the highest-leverage thing to change about how this codebase is maintained — and the
project already knows the answer, because **a convention with a conformance gate has not drifted
once.** Three conventions had no gate: derived-money-columns-not-client-writable, no-DB-enums, and
concurrency (110 untestable lock sites). **Two are now gated** (`DerivedMoneyConformanceTest` and
`NoDatabaseEnumsConformanceTest`, both 2026-08-12) — and building each one corrected the finding
that prompted it: the money sweep turned up two more live exploits than recorded, and the enum count
turned out to be **38, not 62** (the rest were ghosts in a stale local MySQL). Concurrency is the
one left.

See [05-stop-point-plan.md](05-stop-point-plan.md) for the sequenced route.

---

## 6. The static-analysis gate was inert, and the sweep found it by using it

Not a numbered finding — it surfaced while shipping the others, and it belongs here because it is
the same failure mode as three of them.

**PHPStan was red on `main` throughout**, with 20 errors predating this sweep. A gate that is
already red gates nothing: "no new errors" cannot be enforced when the baseline is exceeded, so
every commit in this sweep had to be verified file-by-file by hand. Fixed 2026-08-12:

- **12 of 20 were one root cause: relations without generic return types.** `Invoice::items()`,
  `InvoiceItem::charge()`, `JournalLine::entry()`, `PayrollLine::payroll()`,
  `PurchaseRequestLine::request()`, `OwnerStatementRun::statements()`/`asset()`,
  `OwnerStatement::owner()` — each degraded to `Model`, so every property read through them was
  "undefined". Typing them is a one-line fix per relation and it **cascades**: typing
  `Invoice::items()` alone revealed four further errors in code that had been unresolvable, and
  fixing those revealed two more. The count went *up* before it went down, which is the gate
  working.
- **7 were false positives, and the guards they flagged are load-bearing.** larastan infers
  attribute types from the MIGRATION — a statement about persisted rows, not about an in-memory
  model. Verified in tinker: `(new Lease)->commencement_date === null` is **true**, and
  `(new LeaseOption)->window_opens_on instanceof DateTimeInterface` is **false**, both of which
  PHPStan called impossible. Those checks run in `saving` hooks, before the row exists, and they
  are what stop `Carbon::parse(null)` quietly returning "now". **Deleting them to satisfy the
  analyser would have introduced the bugs they prevent.** Resolved with
  `treatPhpDocTypesAsCertain: false` — PHPStan's own suggested remedy, printed as a tip on every
  one of those errors.
- **2 were `?->` on a `belongsTo` with a NOT NULL foreign key.** Also real: the relation is null
  whenever the parent row is missing, and `Asset` and `Tenant` both soft-delete. Recorded as a
  reasoned `ignoreErrors` entry rather than baseline debt, because it is a decision about a false
  positive and not something to burn down.

**And the baseline itself had rotted: 80 of its 407 entries — a fifth — described errors that no
longer existed.** `reportUnmatchedIgnoredErrors` was `false`, so nothing said so. It is now `true`
and the file is regenerated to 327 exact entries, which means a baselined error that gets fixed
turns the build red until its entry is removed. That reads as friction and is not: it is the only
thing that keeps the file honest.

> The pattern, again: **a baseline nobody can tell is stale reports coverage it does not have** —
> the same shape as the `system:` posting-date exemption that asserted a property which did not hold
> (FS-25), the conformance gate that could not run (the helper-collision note in
> `TestHelperUniquenessConformanceTest`), and the tie-out that cried wolf on every credit note
> (FS-09). Every one of them was a control that looked like it was working.
