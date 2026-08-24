# Atriom — final pre-staging verification (2026-08-24)

> **The evidence record of the final verification round before staging.** Sixteen agents across
> eight lenses — Yardi gap verification · large-system/market gaps · AR posting · AP/GL posting ·
> recent-commit bug hunt · runtime/ops checks · UI/UX vs market · architecture deviations — with
> **every finding adversarially verified** (a second, independent agent attempted to refute each one
> with code evidence before it was allowed onto this page). 82 findings were raised, **80 confirmed,
> 2 refuted**, and the two refutations are recorded in §9 because a refuted absence claim is this
> project's most repeated documentation defect. ~3.0M tokens, 967 tool calls, read-only throughout.
>
> **This is a findings record, not a second live list.** Open items here are candidates for
> [STATUS.md](../STATUS.md) / [ROADMAP.md](../ROADMAP.md) adoption; once adopted there, the row here
> is history, exactly as [PRE-STAGING-FINDINGS.md](PRE-STAGING-FINDINGS.md) (F-01…F-13, all closed)
> is history. Finding IDs are kept from the audit so every claim traces to its evidence.
>
> ---
>
> ## ✅ ACTED ON — 2026-08-24, the same day
>
> **Every MVP-blocking finding in this report is FIXED**, with regression tests
> (`ClearedChequeSettlesWhatIsOpenTest`, `CutoverAndChargeTermsSurviveTest`) and the touched suites
> re-run green: AR-GL-02 · GAP1B-01 · AR-GL-01 · D3-01 · D3-02 · D3-03 · M4B-01 · M4B-02 · M4B-03,
> plus the small ones (M4B-04, M4B-05, AR-GL-04, D3-05, OPS-02) and the two actively-misleading
> doc/code claims (M4B-07, D2-06).
>
> **Everything else was judged not MVP-blocking and moved to
> [POST-STAGING-BACKLOG.md](POST-STAGING-BACKLOG.md)** with the reason it can wait. That document is
> now the live list; the sections below are kept as the EVIDENCE — what was measured, on what data,
> and what the adversarial pass could not refute.

**Sections:** [0 · Verdict & market position](#0--the-verdict-and-where-atriom-stands-in-the-market) ·
[1 · What was verified clean](#1--what-was-verified-clean) ·
[2 · Money defects](#2--money-defects-new-fix-before-real-money) ·
[3 · Other new defects](#3--other-new-defects) ·
[4 · Gap analysis: Yardi, market, large systems](#4--gap-analysis--yardi-first-market-second-large-systems-third) ·
[5 · Architecture & operational deviations](#5--architecture--operational-deviations) ·
[6 · UI/UX vs the market](#6--uiux-vs-the-market) ·
[7 · Staging/ops readiness](#7--stagingops-readiness) ·
[8 · Documentation drift](#8--documentation-drift-found-by-this-round) ·
[9 · Refuted findings](#9--refuted-findings-kept-for-honesty) ·
[10 · Ordered recommendations](#10--ordered-recommendations)

---

## 0 · The verdict, and where Atriom stands in the market

**Nothing found blocks a staging cutover.** Every read-only gate the project ships was executed and
passed (§1), the AR/AP posting cores verified sound at their foundations, and the go-live blockers
remain exactly the credentials, infrastructure and client decisions STATUS.md already records. What
this round adds is **two high-severity money findings** (AR-GL-02, GAP1B-01), a cluster of
medium-severity latent money defects that should be fixed before the first real month, and a set of
gap-record blind spots — none of which changes the staging answer, all of which change what the
first weeks of production should schedule.

### The market position, stated directly

**Atriom is a full mall-operations ERP for the Egyptian market — and within that scope it is not a
"smaller Yardi"; on the money core it is at or above Yardi, and on Egyptian fit it is ahead of every
Western benchmark.** Measured across all eight lenses:

| Territory | Position |
|---|---|
| **Leasing, billing, AR, recoveries, % rent** | **At or above Yardi Voyager Commercial.** Date-ranged charge schedules, four-method proration with a per-charge prorate flag, CAM caps/gross-up/frozen shares with a mutation-tested tie-out, cumulative % rent, holdover at uplift, PDC register that exceeds anything the Western tools model. All 43 Yardi-cycle stories shipped and re-verified this round. |
| **The books** | **Above the specialist benchmarks.** A real double-entry GL under the property engine: 24 registered posting sources, hard Σdebit=Σcredit at one choke point, never-deletable money records, dated four-family tax catalogue (VAT · stamp · schedule · withholding), a real per-property year-end close, proven backup/restore. The FM specialists push all of this to an external ERP; Yardi ships nothing like the Egyptian tax model. |
| **Egyptian fit** | **Unmatched.** Dated statutory ladders (tax, payroll), bilingual books and documents, PDCs, ETA pipeline (frozen by choice), Law-157/2025-ready VAT treatment as configuration. No benchmark ships any of it. |
| **Facility management** | **At parity with the Maximo/ServiceChannel core** (cost object, trades, PM compliance, failure codes, NTE/proposals, routes — all verified real this round), **behind on the edges**: no vendor self-service portal (designed, not built), no technician mobile app (declined — panel-on-phone has real gaps, UX5-05), no barcode/cycle counts. |
| **Breadth vs large systems** (SAP RE-FX, Oracle, MRI) | **Deliberately narrower, and almost every decline is honest and enforced**: single entity (open decision A2.7), single currency (enforced at the model layer), single book, no admin API, single-level approvals. The declines that are *not yet recorded as decisions* are listed in §4.3. |
| **The gaps an operator would feel** | Lease document generation/e-sign (O1), leasing CRM/pipeline (new, 1A-15), a collections dunning ladder (new, 1A-16), the management fee (blocked on the accountant, B1), consolidated statements (built but unreachable, GAP1B-02). |

**So: does it support the full experience for mall property management?** For the *operational
day* of an Egyptian mall operator — lease → bill → collect → recover → maintain → account → report,
in two languages, under Egyptian tax — **yes, and deeper than the alternatives**. What it does not
yet cover is the *pre-contract* edge (prospect pipeline, lease generation/signing), the *post-due*
edge (escalating collections), the *counterparty* tax dimension (exempt tenants, tenants who
withhold), and multi-entity finance. Those are the honest boundary of the product today, every one
is on a record with an owner, and none is a rebuild — the money core underneath them is settled.

---

## 1 · What was verified clean

Run live on this workstation (read-only), or re-proven from committed code, during this round:

- **`billing:reconcile --deep`: 9/9 checks OK** — 293 invoices, 11,183,784.59 EGP invoiced,
  9,701,959.05 collected, 1,481,825.54 outstanding, books tie to AR/AP. Ran clean twice, including
  after the activity-log session's commits landed mid-audit.
- **Both data audits clean**: 33 leases, every charge schedule unambiguous; every money document
  names a property (Department/DocumentTemplate/Holiday portfolio rows correctly excluded).
- **Configuration health: 8 checks, matching STATUS.md to the row** — red on exactly seller tax
  identity + billing contact (= A1.1, the top of STATUS §9).
- **`atriom:health`: 17 OK / 3 FAIL, all three workstation artifacts** (no cron heartbeat, no queue
  worker + 714 stale E2E-era `SyncDocumentToLedger` jobs, stale local backup — see OPS-05/06).
- **Scheduler roster complete**: all 34 commands at their documented cadences plus the two
  `Schedule::job` money runs and the heartbeat. Nothing missing, nothing erroring.
- **AR posting core**: all seven AR journalizers date entries from their declared
  `SOURCE_DATE_COLUMNS`; every AR source posting-date-guarded or defensibly exempt; **all four
  settlement channels counted at every site checked** (recomputeTotals, capturedCashPaid, the
  cancel release, both over-allocation guards under locking reads, InvoiceItemSettlement, the
  reconcile checks); VAT rounded per line with header = Σ of 2dp lines and the journal tying to the
  piaster; a mixed VAT+stamp invoice splits per tax role and balances; Paymob capture clamp, PDC
  clearing backstop, credit-note un-apply and voided-payment cascade all hold.
- **AP/GL core**: hard balance assert at `JournalPostingService::assertLinesValid`; **all thirteen
  cash-touching journalizers resolve through `MoneyAccount::for()`** with zero hard-coded cash/bank
  roles left; withholding computed on the VAT-exclusive base, clamped, reported as withheld÷base;
  the close gate is two-sided (posted entries + dated-but-unposted documents); `matches()` found
  churn-free; **a real year-end close exists** (per-property retained-earnings roll + consolidated
  bucket, lock-safe, UI-reachable).
- **Filament CRUD authorization**: the 9 container bindings verified present; bulk
  delete/force-delete on money tables gated by `canDeleteAny`/`canForceDeleteAny` refusing
  `NeverDeletable` first; no `app/Policies` fall-through surface found open.
- **EGP-only genuinely enforced** — eleven currency columns pinned to `['EGP']` in `ValueSets`, so
  a non-EGP document is refused, not mis-summed.
- **Secrets posture no worse than STATUS §1.6 records** — `.env` never in git history, no
  credentials in DB settings, ops logs redacted, passwords on the activity-log never-log list,
  Paymob HMAC rotatable with a bounded two-secret window that fails closed.
- **Staging docs accurate**: every env key, command, `deploy.sh` step and `/health` behaviour the
  staging docs claim verified true against `.env.example`, `artisan list` and live HTTP probes
  (`/up` → 200; anonymous `/health` → status-only). 350 routes: 237 admin / 29 portal / 60 api.
- **The facility close-out is real code**, not doc claims: Trade, FailureCode, labour capture,
  WorkOrderProposal, `recomputeCosts()` all spot-checked on disk.
- **Bilingual depth exceeds the benchmarks**: per-locale bell rows, `preferredLocale` mail, 17
  RTL-branching PDF templates, eleven `fallback: false` parity gates, 110 EN+AR screen guides.
- Recent high-risk commits verified clean: dual-HMAC fails closed; `depositHeld` twin shares one
  status constant; F-02 buyer bill reuses `billOne()` under its own lock; `NavigationItemMemo` is
  container-scoped; no `__()` locale-arg misuse remains; new `create()` calls' keys all fillable.

---

## 2 · Money defects (new — fix before real money)

None is wrong-money **today**; each is armed by a routine event (an unlinked cheque clearing, a
renewal, the accountant's C-TAX answer, a non-January fiscal year, a schedule catch-up, cutover).

### AR-GL-02 · HIGH — a cleared series cheque mints credit no invoice can ever draw; the tenant can then be late-fee'd while the mall holds their money
`PostDatedChequeService::clear()` allocates only `if ($cheque->invoice_id)`
(PostDatedChequeService.php:66), and `lodgeSeries()` deliberately does not pre-link — its docblock
promises *"each cheque settles whatever is open when it clears, through the normal clear() flow"*,
which clear() does not do. The unlinked cheque clears into a captured `Payment` with **zero
allocations**: the GL is correct, but `Tenant::creditBalance([$assetId])` attributes credit only
through allocated invoices (Tenant.php:508 `whereHas('invoices')`), so the receipt belongs to **no
property**; `ApplyTenantCreditService` caps at `min(balance, per-property, global)` where the
per-property term is 0, every draw is refused, and the invoice's auto-apply hook swallows the
refusal by design. The month's invoice stays open → picked up by the overdue scan and
`LateFeeService`. Corroborating: `PaymentForm` *refuses* creating a zero-allocation receipt for
exactly this orphaning reason — the cheque-clear path mints the record the form guard exists to
prevent. The series flow is the stated Egyptian norm, so this is the mainline path, not an edge.
**Fix (M):** auto-allocate `clear()` to the tenant's open invoices in the cheque's property
oldest-first (mirroring `ApplyDepositToInvoiceService::settleOpenAr`), or attribute a no-allocation
receipt's credit to `Payment::originatingAssetId()`; regression test = clear an unlinked series
cheque, assert the next invoice settles. *(Independently re-verified by hand in this synthesis.)*

### GAP1B-01 · HIGH — the security-deposit sub-ledger has no opening/cutover path — the one sub-ledger the opening-balance machinery missed
Opening AR has `invoices.is_opening_balance` (journalizer skips), opening fixed assets have their
flag, the trial balance pastes — but the per-lease deposit register has no equivalent. At cutover a
legacy tenant's held deposit either reads **zero** (`Lease::depositHeld()` sums register rows;
move-out netting has nothing to net) or, if keyed as ordinary receipts, each posts Dr Cash /
Cr deposits_held — **inventing cash and double-counting the liability** already in the opening TB.
`deposits_tie_out` goes loudly red either way; the mechanism to make it green does not exist.
STATUS A3.7 lists "deposits held" under "the machinery is built", which is true only of the GL
line. **Fix (S):** mirror the `is_opening_balance` pattern on `DepositTransaction` (flag +
journalizer skip + importer/LeaseImporter column). Must land **before data migration**, not before
staging.

### AR-GL-01 · MEDIUM — `CreditNoteJournalizer` hard-codes `vat_payable`: the 2026-08-19 tax-role grouping never reached the reversal document
Only three journalizers resolve tax through the tax code's posting role (invoice, vendor bill,
expense). The credit note unconditionally debits `vat_payable` from the header and never reads its
items — despite `credit_note_items.tax_code` existing precisely so a reversal carries its supply's
own treatment, and the form copying the code across. The day the accountant answers **C-TAX** by
pointing a charge code at stamp/schedule (a configuration act — no deploy, no gate), every credit
against such a line debits the wrong liability and permanently breaks the VAT return's `ties_out`.
Secondary: system-raised notes (move-out, CAM) drop the classification entirely
(`CreditNoteItem` has no tax_code default seam — verified, unlike invoice items). **Fix (S):**
group the debit side by item tax role exactly as `InvoiceJournalizer` groups the credit side;
stamp `tax_code` in `describeAs()`/`CreditUnearnedBillingService`; add the credit-note case to
`TaxPostsToItsOwnAccountTest`. Do this **before C-TAX is answered**.

### M4B-01 · MEDIUM — Form 41's "remitted" and its withheld-ledger tie-out are exact negations of each other — any in-period remittance false-alarms the control
`WithholdingTaxReturnService::accountMovement()` computes net movement both ways over the same
lines, so `remitted ≡ −withheld_ledger`. Booking Q1's remittance in April (the standard flow) makes
Q2's return read *ties_out ✗, remitted 0.00*. The screen's own docblock prescribes the very booking
that breaks it. Dormant only while `wht_enabled` is off. **Fix (S)** before withholding is switched
on: separate reversal-pair-aware credits from genuine remittance debits + one regression test.

### M4B-02 · MEDIUM — year-end close is hardcoded to the calendar year while the fiscal start month is configurable and C-FY is open
`YearEndCloseService` sweeps 1 Jan → 31 Dec and keys `closingEntriesFor()` on `whereYear`, while
`FiscalCalendar` builds a July FY as 1 Jul → 30 Jun and the UI action pairs the two. On any
non-January install the retained-earnings roll spans halves of two fiscal years and posts
mid-year. Dormant on the January default; **armed the day the client answers C-FY with anything
else** — and C-FY is on STATUS's pre-first-invoice list. **Fix (S–M):** derive the span from
`FiscalCalendar::ensureYear()`, date the entry at `ends_on`, add a July-year close test.

### M4B-03 · MEDIUM — one poison schedule aborts the whole nightly recurring-cost run
`GenerateRecurringExpensesService::generate()` has no per-schedule error isolation. A schedule
whose catch-up due date lands in a **closed period** throws from the posting-date guard, rolls back
(so `last_generated_on` never advances and it fails identically every night), **and propagates out
of the foreach** — every schedule with a higher id books nothing, nightly, with no alert (the
schedule entry has no `onFailure`). A historical `starts_on` — natural entry for an existing levy —
is enough to arm it. **Fix (S):** per-schedule try/catch counted into the command report + alert
(match `SyncLedgerCommand::recordAndAlertFailures`), skip-and-report on a closed due period.

### D3-01/D3-02/D3-03 · MEDIUM ×3 — the charge-copy sites drop the newest charge terms (`prorate`, `billing_timing`) — this codebase's signature enumerate-from-the-diff defect, recommitted
- **D3-01:** `ChargeScheduleService::overlayWindow()`'s relief + resumed rows copy
  name/frequency/VAT but **not `billing_timing`** (nor `prorate`) — granting relief on an
  arrears-billed service charge silently flips its segments to advance, double- or zero-billing the
  crossover months. The successor-rung fix in the same file documents this exact failure and its
  comment falsely claims relief "comes through here" (`LeaseReliefService` calls `overlayWindow`,
  bypassing `setAmount()`'s carry).
- **D3-02:** four charge-copy sites drop the day-old `charges.prorate` flag — renewal
  (`LeaseRenewalService`), resale (`TransferUnitOwnershipService`, which **also drops `end_date`**,
  so a seller's bounded charge re-opens unbounded on the buyer), relief overlay, holdover
  conversion. One renewal at a time re-introduces the measured 2,580.65/5,000 flat-fee defect
  EG-29's prorate flag was built to end.
- **D3-03:** `BillUnitOwnershipsService` ignores `charges.prorate` while
  `CreditUnearnedBillingService` honours it — an ownership bill and its mid-month resale credit
  price the same line by two different rules (~1.68× one month's flat fee collected), violating
  EG-29's own stated invariant. The importer reaches ownership rows, so `prorate=false` is
  reachable there today.

**Fix (S, one pass):** enumerate every `Charge::create` site **by grep** and carry
`billing_timing` + `prorate` (+ `end_date` on the resale copy); thread
`prorationMethodWithin()` into `billOne()`; regression test per path.

### Lower-severity money items
- **AR-GL-03 · LOW** — the tenant Statement of Account itemizes only payments + credit notes while
  its `total_paid` counts all four channels; a deposit-netted or credit-drawn settlement cannot be
  reconciled from the printed rows — worst on final move-out statements. Fix S.
- **AR-GL-04 · NOTE** — `ApplyDepositToInvoiceService` still stamps `asset_id` via lease→unit, the
  chain every sibling settlement document was moved off on 2026-08-18; currently harmless, XS to
  align (`$invoice->asset_id` — and note `deposit_applications.asset_id` is nullable, so the `?->`
  chain would store null silently).
- **M4B-04 · LOW** — the rail tier of the `MoneyAccount` fall-through checks existence only: an
  inactive/summary chart account on a payment rail strands postings (noisily — sync alerts, close
  blocks) instead of falling to the floor as the bank and category tiers do. XS.
- **M4B-05 · NOTE** — `ChangeImpact` classifies `Payment.tenant_id` DERIVED but `matches()` cannot
  see line dimensions, so the promised re-post never fires; promote to REFUSED or reword. XS.
- **D3-05 · LOW** — the vendor-bills danger badge counts EG-33's staged **drafts** as overdue
  payables (`NON_POSTABLE_STATUSES` exists and the badge doesn't use it). XS.

---

## 3 · Other new defects

- **D3-04 · MEDIUM — `TableView::makeDefault()` clears defaults by the view OWNER's id.** A
  colleague adopting a shared view silently wipes the owner's stated personal default (the exact
  invariant the migration promises cannot happen); a non-owner's "clear default" cannot escape a
  team default yet toasts success; two shared defaults can coexist with the **oldest** winning.
  Fix M: restrict set-default on shared views to their owner/managers, or add the per-user
  preference row; test where the owner holds a personal default.
- **UX5-07 · MEDIUM — the Arabic-chrome gate sweeps the ADMIN panel only.** The tenant portal — the
  most Arabic-first surface — has no runtime chrome guard (the static blank-label and `__()`
  locale-arg sweeps do cover it; runtime-derived labels, table/filter/action chrome and group
  headings do not). Portal chrome is translated *today*; this is a missing guard, not a present
  defect. Fix S: parameterise the existing sweep over both panels + report-page filter schemas.
- **D2-09 · LOW — append-only tables with no prune**: `notifications` (fastest grower), `exports`
  (+files on disk), `failed_import_rows`, `failed_jobs`, expired `personal_access_tokens`
  (`sanctum:prune-expired` unscheduled). Only activity_log got EG-34's treatment. Post-staging
  backlog: a small retention registry + one scheduled command.

---

## 4 · Gap analysis — Yardi first, market second, large systems third

### 4.1 The standing record is accurate
Every consequential open row was re-verified against code and **held**: B1 (management fee —
recorded, billed by nothing, honest interim test in place), B4 (Paymob sandbox), B5 (ETA frozen),
O1 (no lease generation/e-sign — `DocumentTemplate` is EG-15 wording, not this), O2 (no vendor
panel; 12b design exists), O4 (fit-out conditions — `WorkPermit.conditions` is the hot-work permit,
not this), O7 (single-vendor procurement; `WorkOrderProposal` is a reusable in-repo quote pattern
that shrinks the effort), O16, O18, H2, H3, and the sampled STATUS §5 rows (EG-02 counterparty tax,
tenant-side withholding, deferred income, WO-to-tenant recharge, single-level approvals,
WhatsApp/SMS, portfolio-wide roles, PDC purpose column). **The doc drift found runs in the opposite
direction — things built but still listed as open** (§8).

### 4.2 Blind spots vs Yardi's mall/retail stack — on no record at all (new)
| ID | Gap | Sev | Disposition to record |
|---|---|---|---|
| **1A-15** | **Leasing CRM / deal pipeline** (prospect → LOI → executed; renewal pipeline). The project's own benchmark folder states the verdict — *"for an operator leasing a second mall from scratch, the pipeline IS the job"* — and the verdict document never absorbed it. Zero pipeline constructs in code. | MED | Open (M) **or** decline — but decide it |
| **1A-16** | **Collections/dunning ladder.** One overdue reminder per invoice, **ever** (`whereNull(tenant_overdue_notified_at)`); no second notice, final demand, legal-handoff state, or per-tenant notice history — in a market the codebase itself calls arrears-chasing-first. EG-15 shipped the *wording* blocks; nothing records the *ladder*. Strongest genuinely-new gap of the round. | MED | Open (S–M: notice-level column + per-level DocumentText wording + days-overdue bands) |
| **1A-17** | **Lease assignment/sublease (تنازل)** exists as a recordable clause, not an executable event — no tenant-swap; the terminate+new-lease workaround breaks AR continuity. | LOW | Open (S) or stated decline |
| **1A-18** | **TI allowances / leasing commissions** — absent; Egyptian practice grants rent-free fit-out (built), so decline is probably right. | LOW | Decline row (XS) |
| **1A-19** | **Specialty leasing** (short-term kiosk/pop-up *license* agreements, specialty-income split). Modelable today as a short lease + `RentableItem::TYPE_KIOSK` covers the cart register; the light-weight agreement + reporting split is the residual. | LOW | Decline-with-workaround (XS) or S |
| **1A-20** | **Footfall/traffic counting** — absent; same no-sensor-estate logic as the declined IoT row. | NOTE | Decline row (one line) |
| **1A-21** | **Tenant sales audit** (declared vs audited, retro-bill) — no formal workflow, but the money half exists (void → re-declare → re-lock re-attributes correctly; `disputed` status is a hook). With POS feeds declined, the audit right is the only defence against under-declaration. | NOTE | KEEP/EXTEND row (XS) |

### 4.3 The large-system lens (MRI, SAP RE-FX, Oracle, Odoo Enterprise)
- **Verified-open and correctly recorded**: single legal entity/TRN (**A2.7 is the one that cannot
  wait past the first invoice** — a second owner TRN makes every second-mall invoice VAT-invalid
  until the M-sized per-asset issuer override ships); no intercompany/trust segregation (B.7/B.5/
  B.8, right-sized as M/L/XL); single-level approvals (C3.6); counterparty tax dimension (A2.6 +
  A2.1 — and **A2.1 is probably a certainty in Egypt**, since corporate tenants are required to
  withhold from rent: its §5 "do you need this?" placement understates how early it bites).
- **GAP1B-02 · MEDIUM — consolidated financial statements are built and unreachable.** The code
  supports consolidation (`reportAssetIds(null)`; the year-end close writes a consolidated bucket)
  but no surface reaches it since All-Properties mode was removed — while STATUS §4 claims
  "per property and consolidated | as described". For an owner holding both malls, one combined
  P&L is a routine quarterly ask; today it is two PDFs and a spreadsheet. Either reopen
  deliberately (M) or fix the STATUS row (XS).
- **GAP1B-05 · LOW** — no admin API / outbound webhooks / BI feed, **and no recorded decision**
  about it (unlike every other declined breadth item). Mostly correct for one operator whose
  consumer is an accountant; record the decline (XS). Same for **D2-10**.
- **GAP1B-06 (narrowed)** — importer census: no importer for **equipment** (the FM register every
  CMMS onboards first) or **unit-ownership records**; the PDC leg was refuted (§9) — `lodgeSeries`
  IS bulk entry of the cutover drawer.
- **GAP1B-07 · LOW** — monthly billing is one queue job, 600s timeout, tries=1: fine at 2 malls, a
  known-shape ceiling at 10 (per-property dispatch is the shape to reach for). A timeout-killed run
  *does* surface — failed_jobs turns health red.
- **Verified sound, nothing to do**: EGP enforcement (GAP1B-08), year-end close existence,
  fixed-asset↔GL depth, audit-trail depth, backup/restore.

---

## 5 · Architecture & operational deviations

Fourteen candidates assessed; **the pattern is the opposite of the usual failure mode** — the
deviations are reasoned, written down, and gated by mutation-proven tests.

| ID | Deviation | Verdict |
|---|---|---|
| D2-01 | Derived ledger (void-and-repost) vs post-once orthodoxy | **DEFENSIBLE** — journal is append-only at row level (reversal pairs, `#[NeverDeletable]`), ChangeImpact evidence rule governs; owner-reported months DO notify on restate. Brief the accountant on the reversal-pair convention during parallel run |
| D2-02 | No Laravel Policies — container-bound authz | **DEFENSIBLE** — 9 bindings + refusal gates verified down to bulk force-delete on money tables. Residual: bespoke convention; gates advisory while CI is off |
| D2-03 | Suite on sqlite, prod on MySQL | **DEFENSIBLE with residual** — the 6-case MySQL tier + QA harness are real and green (2026-08-24) but opt-in and outside the push loop; this class shipped twice before. → run on the staging box (D2-14) |
| D2-04 | CI paused, direct pushes, no review | **RISK, owner's standing decision** — 72 gates + CVE audit run only when a human remembers (first-ever audit run found 22 advisories incl. 2 HIGH). Mitigation: `gh workflow run ci.yml` before the cutover commit |
| D2-05 | Single scheduler + database-queue default; money runs are queued jobs | **DEFENSIBLE** — health goes red on dead worker/cron/failed jobs/db-drivers-in-prod; cutover step 3 installs cron+worker. Open half is STATUS §1.2 (off-box alerting) |
| D2-06 | ~~No morph map~~ | **STALE PREMISE** — enforced alias morph map since 2026-08-15; **CLAUDE.md:152 still asserts "There is no morph map"** (§8) |
| D2-07 | ~~Session-state property switcher~~ | **STALE PREMISE** — standard URL-scoped Filament tenancy; the one session leak (search following you across malls) was found and fixed 2026-08-24 |
| D2-08 | Plaintext .env secrets, no vault | **RISK, recorded (STATUS §1.6)** — verified nothing worse exists anywhere |
| D2-09 | No retention beyond activity_log | **NEW, LOW** — §3 |
| D2-10 | No admin API | **DEFENSIBLE, unrecorded** — record the decline |
| D2-11 | Hand-maintained bilingual lang arrays | **DEFENSIBLE** — eleven fallback-false gates; residual: not every Mailable send site verified to set recipient locale |
| D2-12 | 9 vendor-class rebindings + 4 upstream contract pins | **DEFENSIBLE** — upgrade cost localized and red-by-design; lock at v4.11.8, installs off the lock |
| D2-13 | Leading-wildcard LIKE search, unmeasured at scale | **KNOWN-OPEN (H3)** — measure on the posture-B staging box before optimising |
| D2-14 | Cutover runbook omits the MySQL test tier | **NEW, XS** — the tier exists *for* the first real-MySQL box and skips silently elsewhere; add one line to step 5 |

---

## 6 · UI/UX vs the market

The 2026-08-23/24 UX push **held up under code verification** — payment against 3 invoices is one
form with oldest-first auto-allocation and a live unallocated bar; a PDC year is one dialog; lease
creation has a 2-step quick wizard; 26 tables carry money summarizers; 337 empty-state
customizations; rule-derived saved views on 34/66 lists; role dashboards are worklists
(ActionRequired's 17 permission-gated cards), not stats. What is still below a Yardi/MRI/AppFolio
user's expectation clusters in four places, none staging-blocking:

| ID | Gap | Sev / Effort |
|---|---|---|
| UX5-02 | **No dunning cadence** (the UX face of 1A-16) — repeat chasing is entirely manual | MED / M |
| UX5-03 | **The collections loop has no endpoint** — the worklist row offers only a statement; the tenant hub's Payments tab cannot create; "call → they paid → record it" costs six steps. The payment form itself is excellent once reached | MED / S |
| UX5-01 | **No CAM reconciliation workbench** — the year-end is four sequential row actions with no arithmetic shown before commitment (allocations ARE inspectable after). The only unshipped 🟠 UI story | MED / M |
| UX5-04 | **⌘K reaches records only** — 33 report/utility pages are sidebar-scan-only, while UX-28 advertises the palette | MED / S |
| UX5-06 | **Dead-end KPIs**: MallStats (the landing row for every money role), MonthlyRevenueTrend, EnergyConsumptionTrend have zero drill-down links. *(ArAging and TenantMix DO link — the finder's list was 40% corrected by the verifier.)* | MED / S |
| UX5-05 | **Technician on a phone**: PM jobs show **no date at all** (`scheduled_for` is md-only; the SLA clock column is blank on preventive jobs) and no equipment code, with no operator override of `visibleFrom('md')`. Since O3 (tech app) is declined, panel-on-phone IS the tool. The STATUS §7 "shows cost variance" half is stale — those columns are toggled off by default | MED / S |
| UX5-08 | Tenant 360 lacks the violations tab and sales trend (UX-07's recorded open half) | LOW / S |
| UX5-09 | **A manually raised invoice notifies nobody**, and there is no send/resend action on the invoice record — the daily "I never received it" conversation ends in a hand-mailed PDF | LOW / S |
| UX5-10 | No consolidated approvals inbox (per-module badges + tabs only; single-level ladder makes this mild) | LOW / S |
| UX5-11 | UX-08/UX-10/UX-12 live only in the benchmark doc, absent from ROADMAP "the single prioritized list" — and the drill-down topic already has a CLOSED row there while the benchmark's stays open: the two homes disagree today | NOTE / XS |

Plus UX5-07 (portal Arabic gate, §3).

---

## 7 · Staging/ops readiness

**Verdict: staging-ready.** Every claim in the staging docs verified true (OPS-08, §1). Items:

- **OPS-05 · NOTE** — this workstation's DB still carries the 2026-08-23 E2E residue: 5 rows +
  **716 stale queued jobs** (714 `SyncDocumentToLedger` — redundant; reconcile passes 9/9), which
  is what turns health's queue row red locally. Reseed after the activity-log session finishes.
- **OPS-06 · MEDIUM (known-open)** — backups: local-only destination, blank archive password/alert
  email, newest archive ~147h old. Exactly STATUS §1.1; posture-A staging expects these red,
  posture-B must treat them as production.
- **OPS-07 · NOTE (known-open)** — configuration health red on seller tax identity + billing
  contact = A1.1, precisely as documented.
- **OPS-02 · LOW** — `Health::checkRuntimeDrivers` refuses only the `database` driver; a deployed
  box on `QUEUE_CONNECTION=sync` (jobs inline in web requests) passes every health row. XS fix.
- **OPS-03 · LOW** — a staging box built per the docs keeps `EXPORT_QUEUE_CONNECTION=sync`
  (STAGING.md's delta block never names the key; the production runbook does). One-line delta.
- **D2-14 · LOW** — add `composer test:mysql` (+ optionally the QA harness) to the cutover runbook
  step 5 — non-destructive, and the tier exists precisely for that box.
- **PATH note** — `php` is not on this shell's default PATH (Herd's php84 is), which CLAUDE.md
  already warns will bite the Playwright specs that shell out to artisan.

---

## 8 · Documentation drift found by this round

All stale **in the direction of understating the build** — things shipped that the record still
sells as open. Half a day closes all of it:

| Where | Stale claim | Reality |
|---|---|---|
| STATUS §0 + gap-analysis H1 | FixtureColumnsExist gate "switched off" | Switched ON 2026-08-24 (7335552f, 72 ghost keys cleared) — hours after both docs were re-verified |
| STATUS §5 C3.1 | Warehouse bins = size-M work | `Bin` shipped 2026-08-18 with UI + write guards |
| STATUS §5 C3.2 | Stock transfers: "nothing creates them" | Same-property transfer is a working UI action; cross-property is a *reasoned in-code decline* (adjust-out + receive-in). The atomic inter-mall question stays open |
| gap-analysis §3.2 vs §7 | CPI escalation "Absent by design — O14" | Built 2026-08-19 (`RentIndex`); §7 says so — one document, two states |
| gap-analysis §3.6 vs §7 | Revenue forecast "nothing projects forward — O10" | `/admin/revenue-forecast` built 2026-08-19; §7 says so |
| STATUS §4 A3.4/A3.8 | "per property and consolidated — as described" | Consolidated statements unreachable from any surface (GAP1B-02) |
| **CLAUDE.md:152** | **"There is no morph map"** | Enforced alias morph map since 2026-08-15 (`Relation::enforceMorphMap`), aliases stored, conformance-gated — the instruction file future sessions act on denies it |
| STATUS §7 | Technician phone list "shows cost variance" | Cost columns are toggled off by default on every width; the real phone gap is no date/equipment on PM rows (UX5-05) |
| benchmarks/yardi/03 | "no CAM statement that shows the working" | `CamStatementPdfService` ships; the verdict doc's ✅ row is right |
| B1 sizing | gap doc says M, STATUS says XS | Reconcile (the WorkOrderProposal pattern arguably shrinks O7 too) |
| `LedgerReportService::cashFlow()` docblock | Explains the retired code-prefix classification | Code resolves via `CashFlowSection::for()`; the comment is an active trap (M4B-07) |
| ci.yml header | "five self-enforcing conformance gates" | 72+ |

---

## 9 · Refuted findings (kept for honesty)

- **GAP1B-06 (PDC importer leg)** — REFUTED: `lodgeSeries` ("Lodge a whole SERIES at once — the
  Egyptian norm") creates up to 60 cheques in one modal — bulk *entry* of the cutover drawer, found
  under the name the finder did not try. The equipment/ownership importer legs survive at LOW.
- **OPS-04 (`APP_MOBILE_RESET_URL` in no template)** — REFUTED: it is in `.env.example:26` as a
  commented entry under an 8-line explainer; the finder's anchored grep missed it — this codebase's
  documented too-narrow-search failure mode, caught by the verify pass working as designed.

---

## 10 · Ordered recommendations

**Before real money (not before staging):**
1. **AR-GL-02** — settle-on-clear (or property-attributed credit) for unlinked cheques. The series
   flow is the market norm and the failure charges late fees to a tenant who paid.
2. **D3-01/02/03** — one grep-derived pass carrying `prorate` + `billing_timing` (+ resale
   `end_date`) across every `Charge::create` site, + `billOne()` reading the flag.
3. **AR-GL-01** — credit-note tax-role grouping, *before the accountant answers C-TAX*.
4. **M4B-03** — recurring-run per-schedule isolation + alert.
5. **GAP1B-01** — deposit opening-balance flag, *before data migration*.
6. **M4B-01** — Form 41 remittance-aware tie-out, *before `wht_enabled` goes on*.
7. **M4B-02** — fiscal-year-aware close, *before C-FY is answered non-January*.

**At the staging cutover (ops, XS each):** run `gh workflow run ci.yml` once; add
`composer test:mysql` + `EXPORT_QUEUE_CONNECTION` to the staging deltas; extend
`checkRuntimeDrivers` to refuse `sync`; reseed this workstation after the activity-log session
lands; measure H3 (search LIKE) on the posture-B box.

**Docs (half a day):** §8's table, plus dispositions for §4.2's six blind spots and the two
unrecorded declines (admin API, footfall) so the open list is again the only list a reader needs;
move UX-08/10/12 into ROADMAP or close them there.

**First-weeks backlog (product):** dunning ladder (1A-16/UX5-02) · collections inline actions
(UX5-03) · quick-nav (UX5-04) · MallStats drill-downs (UX5-06) · technician phone columns (UX5-05)
· portal Arabic gate (UX5-07) · invoice send/resend (UX5-09) · saved-view default ownership
(D3-04) · consolidated-statement decision (GAP1B-02) · leasing-pipeline decision (1A-15) ·
statement itemization (AR-GL-03) · A2.1 escalated to the accountant as *probably yes, and early*.

---

*Method note: findings were produced by eight specialized read-only audit agents and each batch was
then adversarially verified by an independent agent instructed to refute, with file:line evidence
required in both directions; the two highest-severity money findings were additionally re-verified
by hand during synthesis. The activity-log session's WIP committed mid-audit (8092d90f et seq.);
every citation here is committed code.*
