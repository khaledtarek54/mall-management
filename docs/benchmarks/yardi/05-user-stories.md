# 05 — User stories

> The backlog this benchmark produces, as user stories with acceptance criteria. Each carries an
> **ID** (used by [07-phase-plan.md](07-phase-plan.md)), the **phase** it belongs to, and a
> **Today** line saying what the system actually does now — grounded in the code, so nobody
> implements something that already exists.
>
> Roles: **Leasing Manager** (Eltizam leasing) · **Property Accountant** (AR/GL) · **Finance
> Manager** (Eltizam) · **Mall GM** · **Owner** (Jawad) · **Tenant** · **Auditor**.
>
> Priority: 🔴 must-have this cycle · 🟠 should-have · 🟡 next cycle · ⚪ probably never (recorded
> so it stops being re-proposed).

---

## Epic LS — The charge schedule *(phase 1 — the foundation)*

### LS-01 ✅ See the whole term's rent the day the lease is signed — **SHIPPED 2026-08-08**
**As a** Leasing Manager **I want** to enter the full rent schedule — every step, every date range —
when I create the lease **so that** the mall's future revenue is a recorded fact, not a projection
someone re-derives every year.

**Acceptance**
- A lease carries **many** charge rows per charge type, each with `from` / `to` dates and an amount.
- Creating a lease with 5 annual steps produces 5 rent rows, visible in one table with a total.
- Adjacent rows for the same type must be **contiguous and non-overlapping**; a gap or an overlap is
  refused with a clear message naming the two rows.
- The schedule is visible on the lease view page without opening an edit form.

**Today:** `LeaseCreationService::seedStandardCharges()` writes exactly one base-rent row and one
service-charge row with today's amount. The `charges` table already has `start_date`/`end_date` and
`MonthlyBillingService::chargeAppliesToPeriod()` already honours them — **nothing writes more than
one row.**

---

### LS-02 ✅ Bill from the schedule, never from a mutated row — **SHIPPED 2026-08-08**
**As a** Property Accountant **I want** the monthly run to bill whichever schedule row covers the
period **so that** a rent step takes effect on its own date with no nightly job touching live data.

**Acceptance**
- `MonthlyBillingService` selects the row whose date range covers the period; exactly one row per
  charge type may match, and two matching rows is a hard error, not a silent double-bill.
- Billing a period in the past re-reads the row that was effective *then*.
- Regression test: bill Mar-2027 and Apr-2027 on a lease that steps 1 Apr; assert two different
  amounts with no code having mutated anything between the runs.

**Today:** billing filters `is_active = true` charges by date range already — this story is mostly
about *removing* the assumption that only one row per type exists.

---

### LS-03 ✅ Escalation generates the next row instead of overwriting this one — **SHIPPED 2026-08-08**
**As a** Finance Manager **I want** the escalation sweep to *append* the next schedule row **so
that** the rent history is intact and next year's rent is visible and reviewable before it bills.

**Acceptance**
- `RentEscalationService` closes the current row at the day before the effective date and inserts
  the next row — it never writes `amount` on an existing row.
- Re-running the sweep is still idempotent and lock-safe (keep the existing row-lock + re-check).
- The generated row is flagged as system-generated with its source (escalation, and which rate).
- **Regression:** after two escalations, the lease has three rent rows and the 2026 amount is still
  readable.

**Today:** `LeaseRentChangeService::apply()` overwrites `Lease.base_rent_monthly` and the
most-recent active `Charge.amount`; the escalation sweep calls it.

---

### LS-04 ✅ Rate-based charges (EGP/m²/year) — **SHIPPED 2026-08-09**
**As a** Leasing Manager **I want** to enter rent as a rate per m² per year **so that** the money
re-derives when the let area changes and I can compare deals per m².

**Acceptance:** a schedule row stores either a flat amount or `rate × area`; the billed amount is
derived; the rent roll can report EGP/m²/yr for every lease.

**Shipped:** `leases.rent_pricing_basis` (`flat` | `rate`) + `base_rent_rate_per_sqm_year`, with
`Lease::deriveBaseRentFromRate()` as the one authority. Choosing `rate` makes the monthly figure
derived, and `LeaseSpaceChangeService` re-prices the lease from the rate when an expansion or a
contraction moves the let area — the operator no longer recomputes `area × rate ÷ 12` by hand.

**The rate lives on the LEASE; the schedule keeps storing amounts.** That split is the phase-1
invariant, not an implementation shortcut: a schedule row records what was actually in force for
its months, so it must hold a number rather than a formula that would re-answer differently later.
The rate is the *term* the number was derived from.

**Two deliberate limits.** A stated `new_total_rent` still wins — the derivation is a default, not
a cage, and a blended rate for enlarged premises is a real negotiation. And the derivation is
enforced in the model, not the form, so an import or a future screen cannot leave a lease carrying
a monthly amount that disagrees with its own rate and area.

`flat` is the COLUMN default, so nothing written before this re-prices on deploy. The rent roll
shows the contracted rate beneath the effective EGP/m²/yr, and a gap between them — an abatement, a
step, a hand edit — is visible without opening the lease.
`tests/Feature/Regression/RateBasedRentTest.php`.

---

### LS-05 ✅ Free rent that is per-charge, not all-or-nothing — **SHIPPED 2026-08-08**
**As a** Leasing Manager **I want** to abate specific charges for a period **so that** I can give
3 months rent-free while the service charge and marketing levy remain payable.

**Acceptance**
- Abatement is expressed as a schedule row (amount 0, or a negative abatement row) on the specific
  charge type, over a date range.
- Abatement is per charge type, and **new leases default to rent-only (net abatement), which is the
  industry standard** — see [the phase plan §1 Q2](07-phase-plan.md). Existing leases keep the
  full-invoice (gross) grace they were actually billed under; nothing is retroactively rebilled.
- The lease view shows total abatement value over the term.

**Today:** `Lease::periodInFitOut()` suppresses the **entire invoice** — rent, service charge, CAM
and marketing levy together ([S1](04-scenarios.md#s1--new-lease-fit-out-grace--stepped-rent)).

---

### LS-06 ✅ Migrate existing leases with zero behaviour change — **SHIPPED 2026-08-09**
**As a** Property Accountant **I want** the schedule rollout to leave every existing lease billing
exactly what it bills today **so that** nothing re-prices on deploy night.

**Acceptance**
- Migration converts each existing active charge into one open-ended schedule row (`from` =
  commencement or existing `start_date`, `to` = expiry or existing `end_date`).
- A before/after test bills the *same* month on the *same* fixture pre- and post-migration and
  asserts byte-identical invoices.
- The migration is reversible.

**Shipped:** a migration that stamps `start_date` where it is null, and
`atriom:audit-charge-schedules`, which is the part that actually makes deploy night safe.

**The dates were never the risk — the duplicates were.** Under the old model two active `base_rent`
rows meant the run billed *both*: a quiet over-bill somebody noticed a month later. Phase 1's
`assertScheduleUnambiguous()` refuses that shape, which is the right call and a far better failure —
but the refusal is caught and reported, so the lease produces **no invoice at all** and nothing
crashes. Quieter, and worse. Nothing had ever checked whether such leases exist; the audit reports
overlaps, gaps and undated rows, and **exits non-zero**, so it can gate a deploy or a data import
rather than be a report someone remembers to read. Run it before go-live and after every import.

**`end_date` is deliberately left open** — a deviation from the acceptance above. Atriom bills
holdover from the same charge rows, so stamping the lease expiry would stop the rent on the day the
term ended, which is precisely the behaviour change this story exists to prevent.

**Two things the work found.** `Charge` already refuses to *create* an overlapping row, so the
hazard is confined to rows written before that guard — the test fixtures insert raw rows because
that is the only honest way to reproduce them. And the first draft of the migration used
`->join(...)->update(...)`, which runs on MySQL and fails on SQLite (the `SET` clause cannot see the
joined table): green on a laptop, red everywhere else — the same class of cross-database difference
the stamping exists to remove. It is a correlated subquery now.
`tests/Feature/Regression/LegacyChargeScheduleMigrationTest.php`.

---

## Epic LE — Lease events & amendments *(phase 2)*

### LE-01 ✅ Every commercial change is a dated, reasoned event *(shipped 2026-08-09)*
**As an** Auditor **I want** every change to a lease's money or premises recorded as an event with
an effective date, a reason and an actor **so that** I can reconstruct the lease as it stood on any
past date.

**Acceptance**
- An append-only `lease_events` record: type · effective date · reason · actor · document
  reference · the schedule rows it opened/closed.
- Types: rent modification · expansion · contraction · relocation · extension · holdover ·
  abatement · termination.
- The lease view shows a chronological event timeline.
- A rent change made through the UI **cannot** happen without producing an event.

**Shipped:** append-only `lease_events` (`App\Models\LeaseEvent` + `RecordLeaseEventService`),
written inside each change's transaction. The model refuses updates *and* deletes — an editable
audit record is not an audit record. The `leases.notes` append is gone, and the rent-change form now
requires a reason and exposes the effective date the schedule always supported.

---

### LE-02 ✅ Mid-term expansion adds area and money from an effective date *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** to add a unit to a live lease effective on a date **so that**
rent, CAM share and occupancy all change on that date and not before.

**Acceptance:** the `lease_unit` pivot gains effective dates; new schedule rows open on the same
date; CAM allocation reads the summed area (see MF-09); the event is recorded per LE-01.

**Shipped:** `LeaseSpaceChangeService::expand()/contract()`. A contraction CLOSES the pivot row
rather than deleting it, and CAM apportions on **time-weighted** area over the pool year — two
months of extra space is not a year of it.

---

### LE-03 ✅ Temporary relief that reverts by itself *(shipped 2026-08-09)*
**As a** Finance Manager **I want** a temporary rent reduction to carry an end date **so that** the
original rent resumes automatically.

**Acceptance:** the relief is a bounded schedule row; the original schedule resumes the day after;
a test proves the reversion bills without human action ([S6](04-scenarios.md#s6--negotiated-mid-term-relief)).

**Shipped:** `LeaseReliefService` over `ChargeScheduleService::overlayWindow()`. A window spanning a
contracted step produces one relief row per underlying segment and resumes at the **post-step**
amount, so the relief cannot swallow an escalation.

---

### LE-04 ✅ Holdover bills *(shipped 2026-08-09)*
**As a** Property Accountant **I want** an expired-but-occupied lease to bill holdover rent at the
contracted multiple **so that** the mall is paid for the space it is providing.

**Acceptance**
- `holdover_rate_pct` on the lease (default from a setting; typical 150%).
- Converting to holdover is an event that opens a month-to-month schedule row at
  `last rent × rate`, and the lease keeps billing.
- Nothing auto-converts: the operator confirms. The existing holdover **alert** becomes the prompt
  for that action rather than the end of the story.

**Shipped:** `ConvertLeaseToHoldoverService`. The rent is a multiple of the row in force **at
expiry**, not of a projected step the term never reached. A converted lease leaves the
ActionRequired card (the decision is made) but stays under the table's Holdover filter.

---

## Epic OP — Options & critical dates *(phase 3 — cheapest high value)*

### OP-01 ✅ Record the options in the lease — **SHIPPED 2026-08-09**
**As a** Leasing Manager **I want** to record renewal / termination / expansion / ROFR options with
their notice windows **so that** the contract's optionality is in the system, not only in the PDF.

**Acceptance:** `lease_options` — type · earliest notice date · latest notice date · term ·
rent basis (fixed / % uplift / market / CPI) · penalty · status (open / exercised / lapsed /
waived) · notes. Visible on the lease and in a portfolio list.

---

### OP-02 ✅ Be warned before the notice window opens, not after it shuts — **SHIPPED 2026-08-09**
**As a** Leasing Manager **I want** an alert ahead of the **earliest** notice date **so that** the
option can actually be exercised.

**Acceptance**
- A daily scan (same idempotent lock+stamp pattern as `leases:remind-expiring`) alerts at a
  configurable lead time before `earliest_notice_date`, and again before `latest_notice_date`.
- An option whose latest notice date passes is auto-marked `lapsed` with an alert.
- A dashboard card: "options requiring action in the next 90 days".

**Today:** nothing. `leases:remind-expiring` fires 90 days before **expiry** — typically months
after the option window has closed ([S7](04-scenarios.md#s7--renewal-option-with-a-notice-window)).

---

### OP-03 ✅ Encumbered space cannot be quietly re-let *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** a unit subject to another tenant's expansion option or ROFR to
be flagged **so that** we do not promise the same space twice.

**Acceptance:** the unit view and the lease-creation unit picker both show an encumbrance warning
naming the lease and the option; it warns, it does not block.

**Shipped:** `Unit::encumbrances()` / `isEncumbered()`, surfaced in BOTH unit pickers (master and
additional — an expansion right is most often exercised over the adjacent unit, which is exactly
what gets added in the second one). It warns and does not block, per the acceptance: a landlord may
legitimately let encumbered space once the option holder is dealt with, and a hard block would send
the operator round the system rather than to the conversation.

**The gap was never the data.** `LeaseOption::encumbersUnit()` shipped with options and **nothing in
the codebase ever called it** — the model computed the answer and no screen asked.

---

### OP-04 ✅ Exercising an option writes the deal *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** exercising a renewal option to pre-fill the renewal at the
option's rent basis **so that** the contracted terms are what actually gets billed.

**Acceptance:** exercise marks the option, records a LE-01 event, and pre-fills
`LeaseRenewalService` with the option's term and computed rent.

**Shipped:** `ExerciseLeaseOptionService`. The event is typed by what the option DOES, not by the
mechanism — a renewal EXTENDS, an expansion EXPANDS, a termination option TERMINATES — so the
timeline reads in deal terms. The renewal form pre-fills the term, the contracted rent and the
commencement (the day after the current term ends, not the day notice was served), and says on the
modal where the numbers came from.

**`market` and `cpi` bases exercise fine and pre-fill no rent** — a valuation and an index feed are
not numbers this system may invent, the same rule the escalation sweep follows — and the event
records `rent_to_be_agreed` rather than omitting the figure silently.

The notice date is the date notice was SERVED, not the day it was recorded: refusing a late-recorded
notice would push the operator to falsify the date, which is worse than accepting it.

---

## Epic MF — Money flow completion *(phase 4)*

### MF-01 ✅ The bulk run prorates the commencement month *(shipped 2026-08-08)*
**As a** Tenant **I want** my first invoice to cover only the days I occupied **so that** I am not
billed for days before handover.

**Acceptance:** `runForPeriod()` prorates a lease whose commencement falls inside the period,
without waiting for a human to use the manual action. Regression test on the bulk path specifically.

**Shipped:** `billForPeriod()` passes `prorate: true`. Pinned by `BulkBillingProratesCommencementTest`
on the BULK path specifically — the manual action had always been correct, which is why the gap
survived: every test that exercised proration used the path that already worked.

---

### MF-02 ✅ Trailing proration on termination and expiry *(shipped 2026-08-09)*
**As a** Property Accountant **I want** the final month to bill only to the termination date
**so that** I do not have to credit-note every move-out.

**Acceptance:** a lease ending mid-period bills `days ÷ daysInMonth`; if the period was already
billed in full, termination raises the credit automatically and says so.

**Shipped:** `MonthlyBillingService::monthsCovered()` (one rule for both edges and for multi-month
cycles) + `CreditUnearnedBillingService`, which uses the SAME rule so the credit is the exact
complement of the bill. Trailing proration is unconditional; one-off lines are never clawed back.

---

### MF-03 ✅ A move-out final account *(shipped 2026-08-09, completed same day)*
**As a** Property Accountant **I want** one document that settles a departing tenant **so that**
the deposit, the arrears, the damages and the pending CAM true-up are reconciled in one auditable
place.

**Acceptance**
- Shows: deposit held (from `DepositTransaction`) vs the lease's contractual `security_deposit`;
  open AR; itemised deductions; pending CAM/percentage-rent true-ups (estimated or explicitly
  deferred); the net refund or residual debt.
- Posts as one settlement — one `DepositTransaction` disposition, not a manual refund plus a manual
  forfeit.
- Never deletes a money record: corrections go through credit note / reversal
  ([`DeletionPolicy`](../../../app/Support/DeletionPolicy.php)).

**Shipped:** `MoveOutStatementService` computes it (including unreconciled CAM years and missing
sales declarations — the "not knowable yet" section); `SettleMoveOutService` disposes of the deposit
in one act and freezes the statement as the termination lease event's payload.

**The netting shipped too**, via the full registry route rather than a shortcut:
`DepositApplication` is its own document (Dr Deposits Held / Cr AR), the **fourth channel** into
`Invoice::recomputeTotals()`, soft-deleted to reverse — the same shape as `TenantCreditApplication`.
Settlement follows Yardi's order: arrears first, then the operator's deductions, then the refund.

Adding the channel meant telling four other places about it, which is the real cost of one:
`capturedCashPaid()` (a netted deposit is not cash, so a void must still be allowed), the
cancel-invoice release (or the deposit strands on an invoice that left the books), and BOTH payment
over-allocation guards (or a later payment over-settles an invoice the deposit already paid — the
identical bug the tenant-credit channel caused before it was counted).

---

### MF-04 ✅ Write off a bad debt as a bad debt — **SHIPPED 2026-08-09**
**As a** Finance Manager **I want** to write off an uncollectible receivable **so that** the loss
lands in bad-debt expense and the earned revenue stays in the period it was earned.

**Acceptance**
- Write-off posts `Dr Bad Debt Expense / Cr Accounts Receivable`, dated at the write-off decision,
  through a **registered GL source** (one `LedgerPoster::JOURNALIZERS` line + its
  `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` entry) with a posting-date guard.
- Requires an elevated permission and a reason; the invoice becomes `written_off`, excluded from AR
  aging, **not** `cancelled`.
- A test drives the real service **and** the ledger sweep and asserts the tie-out (the GL-registry
  invariant — a test that calls `LedgerPoster::post()` directly proves nothing).
- Recovery of a written-off debt reverses it rather than editing it.

**Today:** absent. Cancel is the only tool, and it reverses revenue in the wrong period
([S13](04-scenarios.md#s13--an-uncollectible-tenant)).

---

### MF-05 🟠 Post month independent of document date
**As a** Property Accountant **I want** to post a correctly-dated document into the current open
period **so that** a closed month does not block me and I never have to falsify a document date.

**Acceptance**
- A `post_month` on every GL-posting source, defaulting to the document date's month.
- Posting into a **closed** post month stays refused (`PostingDateGuards` unchanged); posting a
  February-dated document to March is allowed and shown.
- The monthly close and every GL report read `post_month`; the document keeps its own date for the
  tenant and for ETA.

**Design note:** this touches every journalizer. Scope it deliberately, or defer it to phase 5 —
see [07 §Sequencing risks](07-phase-plan.md).

---

### MF-06 ✅ Payments allocate to invoice items — **SHIPPED 2026-08-09**
**As a** Property Accountant **I want** to record which lines a payment settles **so that** a
disputed CAM line ages separately from the rent that was paid.

**Acceptance**
- An optional item-level allocation under the existing `invoice_payment` pivot. **The invoice
  remains the AR record and `Invoice::recomputeTotals()` remains the single source of truth** —
  item allocation is detail beneath it, never a second truth.
- AR aging can be grouped by item type.
- Unallocated payments still behave exactly as today.

**Shipped:** `invoice_item_payment` + `AllocatePaymentToInvoiceItemsService` + the **Payment split**
action on the invoice, with `App\Support\InvoiceItemSettlement` as the read model.

**Nothing per-item is stored as a balance, and that is the whole design.** The obvious shape — a
settled/outstanding column on each line — would have been a second truth about the same money,
reconciled by hand, and the first credit note applied without an item breakdown would have
desynchronised it silently. Instead every per-line figure is DERIVED from `invoices.paid_amount`, so
**the item outstandings always sum back to `invoices.balance`** by construction. Adding a fifth
settlement channel later changes nothing here.

**Two ways a line gets settled.** Explicitly, when the operator typed what the remittance advice
said; and otherwise by charge-type priority, which is what Voyager does (02-yardi-money-flow.md §4).
Atriom's order is rent → service charge → marketing → utility → parking → % rent → other → **late
fee last**, so a partial payment is never eaten by a penalty and a disputed fee stays visible in
aging as a fee.

**Deviation from Yardi, stated:** Voyager makes that order configurable per AR settings. Atriom's is
a constant. Nobody has asked to tune it, and a settings screen nothing reads is worse than an
explicit constant — this project has shipped that bug twice.

**A mutation test caught a false pass here.** The obvious "a refunded payment's split stops counting"
test passed with the received-status filter deleted, because the pool had gone to zero and the
scaling cap covered it. The case that actually needs the filter is a refunded payment *beside a live
one* — without it, the live money spreads across both splits and reports the CAM as part-paid when it
was paid in full. `tests/Feature/Regression/InvoiceItemAllocationTest.php`.

---

### MF-07 🟠 Mark an invoice line disputed
**As a** Property Accountant **I want** to flag one line as disputed **so that** the late-fee sweep
does not charge a fee on a balance we are still arguing about.

**Acceptance:** a disputed item's amount is excluded from the late-fee base and shown separately in
aging; the tenant portal shows the dispute state.

---

### MF-08 ✅ Per-lease late-fee terms *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** grace days and the late-fee rate to be lease-level overrides
**so that** the system bills what each contract says.

**Shipped:** `Lease::lateFeeTerms()` resolves lease → `BillingSettings` → default. **This uncovered
a live bug:** the Settings screen wrote `BillingSettings` while the service read
`config('billing.*')` from `env`, so every late-fee value an operator saved there was ignored.

---

### MF-09 ✅ CAM reads the lease's whole area *(bug fix — shipped 2026-08-08)*
**As an** Owner **I want** a multi-unit tenant's CAM share to reflect all the space they occupy
**so that** the cost is distributed correctly between tenants.

**Acceptance**
- `CamReconciliationService` sums area over the `lease_unit` pivot for **both** the numerator and
  the denominator.
- **Regression test:** a two-unit lease (900 + 300 m²) in a pool with a single-unit lease gets a
  share of `1200 ÷ total`, not `900 ÷ total`. Note the existing tie-out assertion passes either
  way — assert the *share*, not the total.
- Existing reconciled pools keep their frozen shares; only new reconciliations change.

**Shipped:** both sides sum the `lease_unit` pivot (now `Lease::totalAreaSqmForPeriod()`, time-weighted
since LE-02). `CamMultiUnitAreaTest` asserts the SHARE, not the total — the tie-out passed either way,
which is exactly why a wrong distribution stayed invisible.

---

## Epic RA — Revenue accounting *(phase 5, gated on the accountant's ruling)*

### RA-01 ✅ *(decided 2026-08-09 — built, ships OFF)* The accountant enables straight-line rent
**As a** Finance Manager **I want** a written ruling on whether the owner's books recognise lease
income on a straight-line basis under EAS 49 / IFRS 16 **so that** the GL is either right or
knowingly simplified.

**Resolved to the standard:** EAS 49 requires it, so RA-02 gets **built** — but it ships behind a
setting defaulted **OFF**, because enabling it restates the trial balance and Atriom is single-book
(Egyptian tax follows the invoices). See [the phase plan §1 Q1](07-phase-plan.md).

**Acceptance:** the worked example from
[02 §7](02-yardi-money-flow.md#7-straight-line-rent--the-lessors-revenue-recognition), a before/after
the accountant can read, and their eventual ruling recorded in
[`docs/accounting/ACCOUNTANT-BRIEFING.md`](../../accounting/ACCOUNTANT-BRIEFING.md) and
`docs/BUSINESS-RULES.md`.

---

### RA-02 ✅ Straight-line rent schedule & posting *(shipped 2026-08-09, defaulted OFF)*
**As an** Auditor **I want** lease income recognised evenly over the term **so that** the financial
statements comply.

**Acceptance**
- A per-lease straight-line schedule derived from the LS-01 charge schedule (steps + abatements),
  visible per month: billed · recognised · difference · cumulative deferred balance.
- A monthly posting: `Dr/Cr Deferred Rent Receivable / Rent Revenue`, as a **registered GL source**
  with a posting-date guard and a real-service + sweep tie-out test.
- Amendments (LE-01) trigger recalculation **from the modification date forward only** — never a
  retrospective restatement of closed periods.
- Tenant invoices, VAT and ETA filings are provably unaffected.

---

## Epic RC — Recoveries depth *(phase 6)*

### RC-01 ✅ The pool is sourced from the GL *(shipped 2026-08-09)*
**As a** Property Accountant **I want** the CAM pool to be the sum of chosen expense accounts
**so that** nobody re-keys it and every tenant charge drills to the invoices behind it.

**Acceptance:** a pool holds a set of `LedgerAccount`s; the actual total is a query over posted
entries for the year; the hand-keyed total remains only as a legacy fallback on existing pools;
the tenant charge drills through to the vendor bills.

**Shipped:** `expense_basis = ledger` + a `cam_pool_accounts` pivot; `SyncCamPoolFromLedgerService`
sums POSTED journal lines on those accounts, for the pool's own property and year, **debits less
credits** so a vendor credit reduces the pool instead of being recovered from tenants. The total is
WRITTEN, not queried live — a bill that arrives in March for December must not silently restate
allocations already billed — and a reconciled pool refuses to re-source. `stated` remains the
default, so no existing pool changes basis.

---

### RC-02 🟠 Several pools per property
**As a** Property Accountant **I want** multiple recovery pools per property-year **so that** cost
categories with different participants and different bases reconcile separately.

**Acceptance:** the `(asset_id, period_year)` unique key becomes `(asset_id, period_year, pool_code)`;
each pool has its own participant rule, admin fee, VAT rate and cap scope.

---

### RC-03 ✅ Choose the denominator *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** the share denominator to be configurable (GLA / occupied /
fixed / stated) **so that** the calculation matches what each lease says.

**Acceptance:** denominator basis is a pool setting with a per-lease override; the reconciliation
statement names the basis used; existing pools default to **occupied** so nothing changes.

**Shipped, all three points.** `denominator_basis` = `occupied` (the column default, so no existing
pool moves) · `gla` (the property's gross leasable area — vacancy stays with the landlord) · `fixed`
(a contractually pinned m², falling back to occupied rather than recovering nothing if unset). The
per-lease override is `lease_cam_terms.stated_share_pct`, for the many Egyptian leases that simply
name the percentage — which no denominator can derive. The statement names the basis in the tenant's
own language, and says when a share was stated rather than calculated.

**The part that mattered most was the books tie-out.** `Σ allocated = total_actual_expense` is a
hard check in `BooksReconciliationService`, and it silently encoded "the pool is always fully
recovered" — true only under `occupied`. Under `gla` the shares deliberately sum to under 100%.
Rather than loosen the check, the remainder is STORED as `landlord_unrecovered_amount` and the rule
became `Σ allocated + unrecovered = total`. It is 0.00 on every existing pool, so the check is
byte-identical where nothing changed — and the landlord's share of its own vacancy became a number
on a screen instead of drift in a report. Verified against the real service on all three bases.

---

### RC-04 ✅ Gross-up *(shipped 2026-08-09)*
**As a** Property Accountant **I want** variable expenses grossed up to an occupancy assumption
**so that** the landlord is not under-recovered on a partly-vacant mall.

**Acceptance:** per-account fixed/variable classification; a gross-up % per pool; the statement
shows the gross-up line.

**Note:** the occupancy inputs already exist — `Asset::totalUnitAreaSqm()`, `occupiedAreaSqm()`,
`areaOccupancyRate()` and the declared `leasable_area_sqm`. `CamReconciliationService` just never
reads them. This is wiring, not new data.

**Shipped.** `gross_up_pct` on the pool, `variable_pct` (or per-account `cost_nature` on the pivot
when the expense comes from the ledger), and `grossed_up_expense` stored so a statement replays the
basis rather than re-deriving it. `basis = fixed + variable × (assumption ÷ actual occupancy)`.

Four rules, all pinned:
- **Only when the denominator includes vacancy.** Under `occupied` the shares already sum to 100%,
  so grossing up would bill tenants MORE than the landlord spent — refused, not quietly applied.
- **Never recovers more than was spent**, whatever the settings say. The most aggressive
  combination the form allows (100% variable, 100% assumption) still leaves the landlord whole.
- **Never scales DOWN** a centre fuller than the clause contemplated — that would hand tenants a
  discount the lease never promised.
- **`cost_nature` defaults to FIXED on the pivot** — the opposite of `App\Support\CostNature`'s
  default, on purpose. There "variable" is the conservative reading of an unclassified cost; here
  the same word is the aggressive one, because it grosses the account up and charges tenants more.
  An unclassified account must not quietly raise every bill the day gross-up is switched on.

Occupancy is measured as participants' area ÷ the denominator actually used — the same two numbers
the shares divide, so the gross-up cannot disagree with the apportionment it feeds.

---

### RC-05 ✅ Re-estimate next year *(shipped 2026-08-09)*
**As a** Property Accountant **I want** the reconciliation to propose next year's monthly estimate
**so that** we stop repeating the same shortfall every year.

**Acceptance:** on reconcile, propose a new monthly estimate per lease; on acceptance it opens a
new schedule row (LS-01) effective next January. **The estimate billed and the estimate reconciled
become the same number** rather than two figures kept equal by hand.

**Shipped, both halves.** `estimate_basis = billed` makes `estimated_paid` the service charge that
lease was actually invoiced in the year, so the two numbers are the same by construction rather than
by diligence. `generateAllocations()` writes `proposed_monthly_estimate` (capped cost ÷ 12 — the
cost the tenant actually bears, not the uncapped allocation), and `ApplyCamEstimateService` opens
the new schedule row on 1 January of the following year. Proposing and applying stay separate acts;
applying is idempotent and skips leases that have ended.

---

### RC-06 ✅ A reconciliation statement the tenant can audit *(shipped 2026-08-09)*
**As a** Tenant **I want** a statement showing the pool, exclusions, gross-up, denominator, my
share, the cap and the estimate I paid **so that** I can verify the charge under my audit right.

**Acceptance:** a bilingual (EN + AR) PDF per allocation, downloadable in the portal, retained
against the reconciliation.

**Shipped:** `CamStatementPdfService` + `resources/views/cam/statement.blade.php`. Five sections —
what the mall spent (and **how that figure was arrived at**: the ledger accounts behind it, or that
it was typed), how much of it is yours (area, **the denominator that was used**, share), the cap and
what the landlord absorbed, the settlement against estimates already paid, and next year's proposed
estimate. Downloadable from the admin allocation list AND by the tenant in the portal — an audit
right the tenant has to ask you to exercise is not much of a right.

**Every figure is READ from the allocation, never recomputed**, so the statement cannot drift from
the invoice it explains; the denominator is recovered from the stored share for the same reason. The
cap section is omitted entirely when no cap applied, because a "cap: none" row on every statement in
the mall trains everyone to skip the section that matters on the few where it bites.

---

### RC-07 ✅ Controllable-only caps and cumulative headroom *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** caps scoped to controllable expenses and able to carry unused
headroom **so that** the cap matches the clause.

**Shipped.** `cap_scope` = `total` (the column default, so every existing term keeps capping what
it capped) or `controllable`; `cap_carry_forward` banks the headroom of a year that came in under
the ceiling. The controllable share comes from `controllable_pct`, or per-account from
`cam_pool_accounts.is_controllable` when the expense is ledger-sourced.

**Controllable is a THIRD axis, not RC-04's fixed/variable one.** A security contract is fixed AND
controllable; utilities are variable and NOT controllable; insurance is fixed and not controllable.
Conflating them would cap the wrong half of the pool.

Headroom is read from the ALLOCATIONS, not recomputed from the terms — a cap renegotiated in year
three must not retroactively change what year one banked — and headroom already drawn is netted off,
so a single cheap year cannot subsidise every spike that follows it. That double-spend is pinned.

---

## Epic PR — Percentage rent depth *(phase 6)*

### PR-01 ✅ Cumulative (YTD) basis with an annual settle-up — **ALREADY BUILT (found 2026-08-09)**
**As a** Finance Manager **I want** percentage rent computed on a cumulative YTD basis where the
lease says so, with a year-end reconciliation **so that** seasonal tenants are not over-billed.

**Acceptance**
- `percentage_rent_basis`: `cumulative` (**the industry standard, and the default for new leases**
  — see [the phase plan §1 Q3](07-phase-plan.md)) or `period` (today's behaviour, kept because some
  leases do say monthly). Existing leases keep `period` until their clause is reviewed.
- Cumulative: overage = `max(0, YTD calc − YTD already billed)`; a month can bill **zero**, never
  negative.
- A year-end reconciliation compares annual calc to annual billed and issues the true-up or the
  **credit** — the same asymmetric pattern CAM already uses (bill immediately, credit auto-applied
  FIFO), for the same reason.
- The existing immediate-billing path is untouched for `period` leases.

**CORRECTION (2026-08-09): this was already built, and this story was wrong.**
`percentage_rent_frequency` (`monthly` | `annual`) has been on the lease since the operator opted
in — the module-09 line saying "deferred" describes the state *before* that, and I read it instead
of the code. `PercentageRentCalculationService` computes each month's **canonical chronological
marginal** — `overage(cumulative sales through this month) − overage(through the prior month)`,
each floored at 0 — so the months sum to the year's overage and a seasonal spike is netted against
slow months rather than billed against a monthly breakpoint. `retrueAnnualYear()` re-attributes the
whole year on any lock or void. The admin lease form exposes it with annual-specific labels,
helper text and a threshold warning.

**What is actually left is a DATA question, not an engineering one:** the column defaults to
`monthly`, and **0 of 24** percentage-rent leases are currently set to `annual`. If a lease's
clause settles annually, that lease is on the wrong setting today. Somebody has to read the
clauses — no code will discover it.

*Lesson, per [`feedback_verify_absence_claims`](../../../CLAUDE.md): an absence finding must be
checked against the code, not against a module doc. A stale gap row costs more than a missing one,
because it sends people to rebuild what already exists.*

---

### PR-02 ✅ Tiered breakpoints — **SHIPPED 2026-08-09**
**As a** Leasing Manager **I want** a breakpoint ladder **so that** anchor and large-format deals
can be billed at all.

**Acceptance:** ordered `from`/`to`/`rate` tiers per lease; each band applies to sales within it;
a single-tier ladder reproduces today's numbers exactly.

---

### PR-03 ✅ Deductions against percentage rent — **SHIPPED 2026-08-09**
**As a** Leasing Manager **I want** to mark charges creditable against percentage rent **so that**
"payable to the extent it exceeds CAM and tax" is billable.

---

### PR-04 ✅ Bill on estimated sales when a tenant does not declare — **SHIPPED 2026-08-09**
**As a** Property Accountant **I want** a missing declaration to bill an estimate **so that**
silence is not a way to avoid percentage rent, with a retro-adjustment when the real figure lands.

**Today:** `sales:scan-missing-declarations` chases the tenant and never bills.

---

## Epic RR — The reports that make it visible *(phase 7)*

### RR-01 ✅ Rent roll — **SHIPPED 2026-08-09**
**As an** Owner **I want** a rent roll as at any date **so that** I can see what the mall is
contracted to earn.

**Acceptance:** one row per lease/space at a chosen date — tenant · unit(s) · area · term dates ·
current rent · rent/m² · service charge · marketing · escalation · next step date · options ·
deposit held · AR balance. Property-scoped, CSV **and** PDF (reuse `ReportCsv`/`Exporter` from
module 17), EN + AR.

**Today:** does not exist anywhere in `app/`.

---

### RR-02 ✅ Lease expiration schedule *(shipped 2026-08-09)*
**As a** Leasing Manager **I want** expiries by year with area and rent at risk **so that** I can
plan renewals and forecast vacancy.

**Shipped:** `ReportService::expirationSchedule()` + the Expiration schedule page. Each bucket
carries its share of the mall's area AND income, because "how much of us is up in 2029" is the
actual question. **Holdovers get their own bucket, sorted first** — a lease past its term but still
trading has not rolled off, and burying it in a past year would understate both this year's risk and
today's income.

---

### RR-03 ✅ AR aging by charge type — **SHIPPED 2026-08-09**
**As a** Finance Manager **I want** aging split by what is owed **so that** disputed CAM does not
look like delinquent rent. *(Depends on MF-06.)*

**Shipped:** `ReportService::arAgingByChargeType()` + the **Aging by charge type** page, CSV and
EN/AR. It re-buckets the same invoices `arAgingBuckets()` counts, so the grand total ties exactly;
the per-type split comes from `InvoiceItemSettlement`, so the rows sum back to the invoice balances
by construction rather than by a reconciliation somebody has to run.

The report exists because one aging total is ambiguous. "EGP 400k over 90 days" reads as delinquent
rent and prompts a collections call — if most of it is a service charge the tenant has formally
disputed, the call is the wrong action and the number is the wrong alarm.

---

### RR-04 ✅ Occupancy cost % *(shipped 2026-08-09)*
**As a** Mall GM **I want** each tenant's total occupancy cost as a % of their sales **so that** I
can see who is in trouble before they miss a payment.

**Acceptance:** `(base rent + service charge + marketing + percentage rent + utilities) ÷ declared
sales`, monthly and rolling-12, with a threshold highlight. **This is a query over data Atriom
already holds** — invoices and `TenantSalesDeclaration` — and is the best value-per-line item in
this document.

**Shipped:** `ReportService::occupancyCost()` + the Occupancy cost % page, rolling 12 months by
default, 20% amber / 25% red. Cost is what was BILLED, not paid — mixing in payment behaviour would
make a struggling tenant look cheaper the longer they went without paying. **Late fees and violation
fines are excluded**: they are penalties, and folding them in would invert the signal. A tenant with
no declared sales reads as **unknown, never 0%** — zero would rank whoever files nothing as the
healthiest tenant in the mall.

---

### RR-05 ✅ Sales analytics: MTD / YTD / MAT and like-for-like *(shipped 2026-08-09)*
**As a** Mall GM **I want** moving-annual-total and like-for-like sales **so that** I can judge
trading performance and negotiate renewals on evidence.

**Shipped:** `ReportService::salesAnalytics()` + the Sales analytics page. MTD, YTD, MAT (trailing
twelve months) and growth against the same twelve months a year earlier, per tenant and for the
portfolio.

**Both growth figures are shown on purpose**, because they answer different questions: total MAT
growth says how the centre's income is moving, like-for-like says how the tenants who were already
there are trading. A mall that let ten new shops reads as growth on the first and flat on the
second, and the gap between them IS the story.

**LFL counts only leases that declared in BOTH windows** — that exclusion is the whole point, and
both directions are pinned: a newcomer with no prior year is out, and so is a departed tenant who
would otherwise drag the headline down while saying nothing about how the rest are trading. The
stated rule is *declared in both*, not *trading every month of both*: real declaration data has
gaps and the stricter rule would silently compute a mall-wide metric over a quarter of the mall.
`lfl_leases` reports how many it counted. A tenant with no prior year shows **unknown** growth,
never 0%.

---

## Deliberately not doing — recorded so they stop being re-proposed

| ID | Story | Why not |
|---|---|---|
| ⚪ XX-01 | Charge-level AR (abandon invoice-level) | ETA e-invoicing makes the invoice the legal document. MF-06/07 buy back most of the benefit at a fraction of the risk. See [README](README.md) |
| ⚪ XX-02 | Multiple accounting books (accrual / cash / tax) | Single entity, single currency, one accountant. Large cost, audience of one |
| ⚪ XX-03 | Multi-currency | EGP only, by design |
| ⚪ XX-04 | Deal Manager / leasing pipeline | Real, but it is a *leasing* product, not a leasing-*accounting* fix. Revisit when mall #2 is being leased from scratch |
| ⚪ XX-05 | AI lease abstraction (Smart Lease equivalent) | Depends on LS-01 + OP-01 existing to abstract *into*. Not before |
| ⚪ XX-06 | Bank deposit batches | Egyptian collection is dominated by PDCs and transfers, and module 33 already covers the cheque lifecycle |
| ⚪ XX-07 | TI allowance & commission amortisation | Only bites when there is a termination-penalty clause to compute. Revisit with LE-01 in place |
