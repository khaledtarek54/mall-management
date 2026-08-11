# Billing & Invoices
> Generate and track monthly invoices for leased retail units, including VAT compliance, proration, payment reconciliation, and overdue notifications.


> **⚠️ Fixed 2026-08-11 — partial write-offs were uncapped and broke the AR tie-out.**
>
> `WriteOffInvoiceService` deliberately does not touch `balance`: balance is derived from the four
> settlement channels and a write-off is not one of them, so the invoice keeps recording what was
> owed. That decision is right, and two consequences of it were unhandled.
>
> **The cap was against `balance`, which a write-off never changes.** Prior write-offs were never
> subtracted and the modal re-offered the full balance as its default and max — so writing off
> 5,000 of 20,000 and then accepting the default booked **25,000 of bad debt against a 20,000
> receivable**: AR credited below the debt, the invoice flipped to `written_off` and thereby
> *excluded* from the tie-out, leaving a permanent −5,000 delta with no document behind it. The cap
> now nets prior write-offs (`Invoice::writtenOffAmount()`), the full-write-off test compares
> against what is LEFT so two partials that clear the debt retire the invoice, and the modal
> defaults to the remainder and says how much was already written off.
>
> **`glTieOut()` excluded only invoices written off in FULL.** A partial one stays live, so its
> whole balance counted toward `expectedAr` while the GL had already been relieved of the
> written-off part — an AR delta from the day it was booked, permanently, with no way to clear it.
> `expectedAr` now subtracts write-offs recorded against invoices still in the counted set.
> Mutation-checked: removing that subtraction reproduces a −5,000 delta on a 5,000 partial.
>
> **Separately, the payment picker offered written-off invoices.** It filtered `balance > 0` with
> no status filter, so cash could be allocated to a debt whose GL relief was already booked —
> driving AR negative while the bad-debt expense stayed. The sibling picker in
> `PostDatedChequeForm` had always filtered status, which is what made this an omission rather
> than a design. Both the picker and its auto-suggest now exclude `cancelled`, `credited` and
> `written_off`.
>
> A write-off is still **not** a fifth settlement channel — `recomputeTotals()` is untouched. See
> `PartialWriteOffIntegrityTest`.


> **➕ Added 2026-08-11 — opening-balance import (the cut-over path).**
> There was previously **no way to load opening AR at all**: the GL side could be a manual journal,
> but the tenant side had to be hand-keyed invoice by invoice.
>
> Opening receivables arrive as **open items — real invoices**, not a lump sum per tenant. Aging,
> the dunning ladder, statements and per-invoice payment allocation all work on documents: a single
> balance has no number to quote to a retailer who disputes it, no due date to age against, and
> nothing for a payment to allocate to. Yardi and MRI both load open items at cutover, for exactly
> these reasons.
>
> **They deliberately post nothing.** `invoices.is_opening_balance` marks them and
> `InvoiceJournalizer` returns no payload — the same mechanism a draft already uses. The revenue
> was earned in the operator's previous system and is already inside the opening trial balance the
> accountant loads as one manual journal entry; posting it again would recognise it twice and
> double AR. Mutation-checked: letting them post reproduces a delta equal to the whole opening
> balance.
>
> **The tie-out is therefore the migration's proof.** `glTieOut()` counts these invoices in
> `expectedAr` while the accountant's entry supplies GL AR, so `billing:reconcile` going square
> after a cutover is the statement *"the receivables I loaded equal the receivables my accountant
> says I have"*. A migration that quietly loaded 90% of the debt is otherwise indistinguishable
> from one that worked. `OpeningBalanceImportTest` drives that whole sequence end to end.
>
> The operator's **own invoice number is preserved** — `Invoice::creating` skips its
> always-regenerate rule for an opening item, because that number is the one printed on the
> paperwork the retailer already holds, and quoting it is the point of loading open items.

## 1. Purpose & business context

The Billing module automates the monthly invoicing lifecycle for Eltizam operators. Each Eltizam manages leases on behalf of Jawad property owners; invoices are issued to Eltizam's tenants (retailers) for rent, service charges, utilities, and other recurring fees. The system:
- Generates invoices idempotently from lease charges (avoiding duplicates within a period)
- Applies VAT (standard rate, 14% today — settings-driven, see §8) to the supplies the charge-code catalogue marks taxable (base rent is VAT-exempt per Egyptian law)
- Supports proration at BOTH ends: mid-month commencement (per-run flag) and mid-month
  termination/expiry (unconditional), plus the automatic credit note when the month was already
  billed in advance
- Late-fee rate, minimum and grace are **per-lease overrides** over the portfolio default
  (`Lease::lateFeeTerms()`); the default comes from `BillingSettings`, which is what the admin
  Settings screen actually writes
- Enforces quarterly/annual charge cadences (e.g., calendar-month-agnostic quarterly billing)
- Tracks payment status via a payment-allocation pivot and credit notes
- Notifies tenants on issuance and alerts Jawad owners on overdue balances

This is the core AR (accounts receivable) engine; all recurring revenue flows through it.

## 2. Domain model

### Key tables & models

| Table | Model | Key columns | Meaning |
|-------|-------|------------|---------|
| `invoices` | `Invoice` | `number` (unique, e.g. `INV-AW-202603-0001`), `lease_id`, `tenant_id`, `status` (enum: draft, issued, partially_paid, paid, overdue, disputed, cancelled, credited), `issue_date`, `due_date`, `period_start`, `period_end`, `subtotal`, `vat_amount`, `total`, `paid_amount`, `credit_applied_amount`, `balance`, `currency` (EGP), `eta_submission_id`, `eta_status`, `owner_overdue_notified_at` | One per lease per billing period; issue_date = period_start for full months or commencement for prorated first month. |
| `invoice_items` | `InvoiceItem` | `invoice_id`, `charge_id` (nullable), `description`, `type` (enum: base_rent, service_charge, utility, parking, percentage_rent, late_fee, other), `amount`, `vat_rate`, `vat_amount`, `total` | Line items derived from Lease charges; one per applicable charge per invoice. |
| `charges` | `Charge` | `lease_id`, `name`, `type` (**string** — a `charge_codes` code, validated at the model, not a DB enum), `amount`, `currency` (EGP), `frequency` (enum: monthly, quarterly, annually, one_time), `vat_applicable` (boolean), `vat_rate` (from the code's VAT treatment, §8), `start_date`, `end_date`, `is_active` | Recurring billing items attached to a lease; defines what is billed and how often. A date-ranged SCHEDULE per type — `ChargeScheduleService` closes one row and opens the next, never edits in place. |
| `payments` → `invoice_payment` (pivot) | Payment / Invoice | `invoices.invoice_payment.allocated_amount`, `payment.status` (captured, pending, failed, refunded) | Many-to-many junction; each payment can be allocated across multiple invoices. Only **captured** payments count toward AR settlement. |

### Relationships

- **Invoice** `belongsTo` Lease, Tenant
- **Invoice** `hasMany` InvoiceItem
- **Invoice** `belongsToMany` Payment (via `invoice_payment` pivot with `allocated_amount`)
- **InvoiceItem** `belongsTo` Invoice, Charge (nullable)
- **Charge** `belongsTo` Lease

### Column notes

- `credit_applied_amount`: Tracks credit notes applied to this invoice durably (separate from payment pivot). Critical for preventing credit erasure during payment recomputes.
- `balance` = `total - paid_amount` (recomputed after each payment, credit, tenant-credit or deposit application)
- `status` auto-advances: `issued` → `overdue` (if due_date is past), `partially_paid` (if 0 < paid < total), `paid` (if balance ≤ 0). Manual overrides (disputed, cancelled, credited) are preserved.
- `eta_status`: Egyptian Tax Authority compliance status; initially null, updated via EtaSubmissionService.

## 3. Business rules & invariants

> **Finalized invoices are immutable in the form (GL integrity, Phase 1).** Once an invoice is
> past `draft` (they're born `issued`), the admin form disables its line items and the
> lease/tenant/issue_date selects — corrections go through a void / re-issue or a credit note, not a
> silent edit that would desync the GL. `status` stays editable for forward transitions, but
> reverting to `draft` is refused (UI options + an `Invoice::updating` guard) so the lock can't be
> bypassed. System paths (LateFeeService, CAM) still mutate via the model. See
> [module 21 §Document immutability](21-general-ledger.md).
>
> **Correcting a finalized invoice = void, not edit.** The "Void invoice" action
> (`VoidInvoiceService`, gated `invoices.edit`, with a reason) sets `status='cancelled'` → returns any
> applied credit, zeros the balance, and reverses the GL entry. Captured **cash** payments block it
> (refund the payment first); then re-issue a corrected invoice.

### Money & VAT

1. **VAT is 14% and applies to service charges, CAM and utilities — NOT base rent, percentage rent, penalties, the marketing levy or parking.** Which supplies are taxable is set per charge code (below); what an individual document bills is then frozen on the row:
   - `Charge.vat_applicable` = true/false
   - `Charge.vat_rate` = 14.00 (for taxed charges)
   - `InvoiceItem.vat_rate` inherits from Charge; if Charge.vat_applicable = false, vat_rate = 0
   - VAT per item = `amount * (vat_rate / 100)`, rounded to 2 decimals
   - Invoice totals: `subtotal = sum(item.amount)`, `vat_amount = sum(item.vat_amount)`, `total = subtotal + vat_amount`
   - **Test:** `BillingScenarioTest::test_computes_subtotal_vat_and_total_exactly__service_charge_taxed_base_rent_exempt` confirms base rent = 0 VAT, service charge = 14% VAT
   - **The header is DERIVED, and enforced at the model** (`Invoice::syncTotalsFromItems()`, fired
     from `InvoiceItem::saved`/`deleted` and from `Invoice::saving` when an existing invoice's header
     is written). Until the 2026-08-11 validation sweep this lived only in `InvoiceForm` — the three
     fields are `readOnly()`, which is an HTML attribute, and `dehydrated()`, so a tampered Livewire
     payload persisted a header of `1` against 12,280 of items, and any direct item write moved the
     lines without the header. That matters because `InvoiceJournalizer` debits AR with the **header**
     total and credits revenue from the **item** amounts: a divergence computes the two sides of one
     journal entry from two different numbers. An invoice with **no** items keeps its header (legacy /
     opening-balance rows have nothing to derive from). See `InvoiceHeaderTiesToItemsTest`.
   - **WHICH supplies are taxable is DATA, on the charge code** — `charge_codes.vat_treatment`
     (`standard` / `exempt` / `zero_rated`) plus an optional `vat_rate_override`, resolved by the one
     function every origination point calls, `Vat::rateForType($code)`. An accountant rules on a
     supply by editing a row; adding "key money" no longer means a developer decides whether it is
     taxed. This is the shape Yardi uses (a `Tax` flag on the charge code — *"Yes means 'this charge
     is taxable'; it does not mean 'this charge is a tax'"*), widened to three treatments because
     **exempt ≠ zero-rated**: both bill 0 and they are different lines on a VAT return, and the
     distinction cannot be recovered later from documents that recorded only a zero.
   - `Vat::EXEMPT_TYPES` survives as the **floor**, not the policy: what an unseeded database bills,
     so an empty catalogue can never fall through to the standard rate and charge 14% on base rent.
     `ChargeCodeVatTreatmentConformanceTest` asserts floor and catalogue resolve every code
     identically — the same arrangement (and gate) `InvoiceJournalizer::REVENUE_ROLE` has for posting
     roles. See `ChargeCodeVatTreatmentTest` for a ruling reaching the services, and
     `ExemptChargeTypesAgreeAcrossPathsTest` for the drift that started it: the form's type-switch
     once carried its own list of two while the services originated six, so a hand-added late fee /
     marketing levy / fine defaulted to 14% no service would ever have charged.
   - **Parking is a charge code like any other** — its taxability was a settings toggle
     (`TaxSettings::parking_vat_applicable`) for one day, 2026-08-10 to 2026-08-11, and is now the
     `parking` row's treatment. One question with two homes is how the two come to disagree.

2. **Proration:** `MonthlyBillingService::monthsCovered()` is **the one rule** — it sums each month's
   own covered fraction over the cycle, so the commencement edge, the termination edge and a
   multi-month cycle all come out of one formula (a full month contributes 1, a partial one its
   day-share, a month the lease does not reach contributes 0). VAT is recalculated on the prorated
   amount. **`CreditUnearnedBillingService` calls the same method** when a termination credits back a
   month already billed, so the credit is the exact complement of the bill rather than an independent
   day-count that would drift on every quarter-billed lease.
   - **Formula:** factor = `(periodEnd.diffInDays(commencement) + 1) / (periodEnd.diffInDays(periodStart) + 1)`
   - **Test:** `BillingScenarioTest::test_pro_rates_the_first_partial_month_when_prorate_is_requested` pins 16 days in March from 15th = 16/30 = 0.5333
   - **Gotcha:** Proration only applies if (a) the flag is true AND (b) commencement is between periodStart and periodEnd AND (c) commencement > periodStart. **The bulk run passes the flag as of 2026-08-08** — before that it took the default `false`, so a mid-month move-in billed by the scheduled run was charged a full month (`BulkBillingProratesCommencementTest`). The flag remains on the single-lease action as an override, for a contract that bills the first month in full.
   - **Trailing proration shipped 2026-08-09** (story MF-02): a lease terminating or expiring mid-month bills only the days it ran, and `LeaseTerminationService` raises the credit note for a month already billed in advance. See gotcha 9.

3. **Charge frequency & applicability:**
   - **Monthly** — always applies (if active in the period)
   - **Quarterly** — applies when calendar-month difference (day-of-month agnostic) from start_date is a multiple of 3
     - **Formula:** `((periodYear - startYear) * 12 + periodMonth - startMonth) % 3 === 0`
     - **Gotcha (fixed):** Old code used `diffInMonths()` which counts whole months; 2026-01-15 to 2026-04-01 = 2 whole months (bug). New formula uses calendar-month delta = 3.
     - **Test:** `QuarterlyChargeTimingTest::test_bills_a_mid_month_quarterly_charge_exactly_3_calendar_months_after_its_start__april` + `test_does_not_bill_the_mid_month_quarterly_charge_in_the_off_quarter_month__may`
   - **Annually** — applies in the anniversary month of start_date (or January if no start_date)
   - **One-time** — applies only if start_date falls within the billing period
   - All frequencies respect `start_date` and `end_date` windows (if charge window ends before period or starts after, it doesn't apply)

4. **Invoice number allocation is serialised per prefix** (`App\Models\Concerns\AllocatesDocumentNumber`).
   Allocation is read-`MAX(number)`-then-insert — check-then-act across a round-trip — and the
   `->exists()` probe is **not** protection: it cannot see another connection's uncommitted row.
   Demonstrated on the real database, two allocations with no insert between them both returned
   `INV-AW-202607-0082`. Reachable because invoices are created by **five** services and three take
   no lock (`BillMeterReadingService`, `BillViolationFineService`, `CamReconciliationService`), so
   the nightly billing run racing a violation fine or a CAM reconciliation is exactly it.
   - The lock is taken in `creating` and released in `created`, so it **spans the INSERT** — a lock
     around only the arithmetic leaves the identical window (A computes 0082, releases, B computes
     0082 before A's row lands).
   - Keyed on the number **prefix** (`INV-AW-202607-`), so one property never blocks another and
     invoices never block journal entries. `numberPrefix()` is the single definition of that string,
     shared with `generateNumber()` — a lock keyed on a prefix that no longer matches the sequence
     it guards protects nothing. *(Extracting it caught exactly that: Payroll's prefix is `PR-`, not
     the `PAY-` a hand-copied key assumed.)*
   - The `UNIQUE` index stays the final arbiter — this makes collisions not happen, it does not make
     the index redundant. Before it, the index was doing the code's job and paying in availability:
     a duplicate-key 500, or a scheduled billing job dying part-way through a month.
   - **All seven numbered documents share this** — Invoice, JournalEntry, CreditNote, VendorBill,
     Expense, DepositTransaction, Payroll. Guarded by `DocumentNumberAllocationTest`, which fails if
     a new numbered document ships without the trait.

5. **Invoice number format:** `INV-{ASSET_CODE}-{YYYYMM}-{SEQNUM}` (e.g. `INV-AW-202603-0001`)
   - Derived from Lease.unit.asset.code at invoice creation time (booted hook)
   - Sequence resets per month per asset
   - **Test:** `InvoiceTest::test_auto_generates_a_unique_invoice_number_with_the_asset_code_and_period`

### Security deposits — the receipt freezes once the deposit is drawn on

The held balance is **derived**, never stored: `MoveOutStatementService::depositHeld()` =
`recorded` receipts − refunds − forfeits − `DepositApplication`s, and
`ApplyDepositToInvoiceService` locks the invoice and caps at `min(balance, held, requested)`. There
is no cached figure to drift, which is why this path came through the 2026-08-11 close-out with one
finding rather than several.

That finding was the other end of it. `DepositTransaction` had no immutability guard, and applying a
deposit writes a `DepositApplication` while leaving the receipt `recorded` — so the editable window
never closed:

> receive 10,000 → net 8,000 against the tenant's arrears → edit the receipt down to 2,000 →
> **`depositHeld` = −6,000**.

The tenant's AR was settled by money the landlord no longer records receiving, the move-out statement
owes them a negative deposit, and the receipt's `Dr Cash / Cr Deposits Held` re-derives at the new
figure while the application's `Dr Deposits Held` does not move.

`DepositTransaction::hasBeenDrawnOn()` is the predicate: has anything been netted, refunded or
forfeited against **this lease's** deposit. It is asked of the LEASE, not the row, because the
deposit is one pot per lease — a second receipt cannot be reduced either once the pot it joined has
been spent from — and it reads the ORIGINAL `lease_id`, so re-pointing a used receipt is judged
against the tenant it actually belongs to. Amount / lease / tenant / property / date / type / status
freeze; `notes` stays editable. An UNUSED receipt stays fully correctable, the same rule as the عهدة
in module 25. Tests: `DepositReceiptFrozenOnceUsedTest`.

### AR Reconciliation

6. **Paid amount is the sum of two sources:**
   - Captured payments via the `invoice_payment` pivot: `sum(invoice_payment.allocated_amount where payments.status = 'captured')`
   - Credit notes applied durably via `invoices.credit_applied_amount`
   - **Formula (in `Invoice::recomputeTotals()`) — FOUR channels:**
  `paid_amount = sum(captured payments) + credit_applied_amount + applied tenant credit + applied security deposit`
  - `credit_applied_amount` — a credit note applied to this invoice (a durable column; see the bug below).
  - `TenantCreditApplication` — an on-account overpayment drawn onto this invoice (Dr Unearned / Cr AR).
  - `DepositApplication` — a security deposit netted at move-out, MF-03 (Dr Deposits Held / Cr AR).

  **Every calculation that decides "how much of this invoice is settled" must count all four.**
  Each of the last three was added separately, and each time something downstream had to be told:
  `capturedCashPaid()` (the void guard — none of the three is cash), the cancel-invoice release
  (or the settlement strands on an invoice that left the books), and BOTH payment over-allocation
  guards (`assertInvoicesNotOverAllocated`, `refitAllocationsToBalance`) — omitting one there lets a
  payment over-settle an invoice another channel already paid, burying the excess as negative AR.
   - This ensures a credit note isn't erased by a later payment recompute
   - **Test:** `CreditNoteBalanceDriftTest::test_keeps_an_applied_credit_when_a_later_captured_payment_recomputes_the_invoice`

7. **Balance is never negative:** `balance = max(0, total - paid_amount)` (rounded to 2 decimals)

### Idempotency & lifecycle

7. **Invoice generation is idempotent per lease+period:** If an invoice already exists with the same period_start, the run skips it.
   - **Entry points:** `MonthlyBillingService::runForPeriod()` (batch all active leases) or `generateForLease()` (single lease, returns status array)
   - **Both entry points serialise on the same period lock** `Cache::lock('billing:run:Y-m')`. The idempotency check is a check-then-create with **no DB unique key**, so the lock — not the probe — is what actually prevents a duplicate. The manual "Generate Invoice" action used to take no lock, so a double-click, a second admin, or a manual generate racing the scheduled run could each pass the probe and mint a second invoice for the same lease-month. `generateForLease()` now contends on the period lock and returns `{status: 'skipped', reason: 'run_in_progress'}` when a run holds it. **Test:** `SingleLeaseBillingLockTest`.
   - **The probe is `alreadyBilledForMonth()` — period-OVERLAP + item-type exclusion.** An invoice already-bills a month when its period overlaps that month (`period_start ≤ month-end AND period_end ≥ month-start`) AND it carries **none** of the special item types `[percentage_rent, cam_recovery, cam_admin_fee]`. The two invoice kinds that legitimately share a lease + period but are NOT the regular rent invoice — the annual **CAM year-end recovery** (`cam_recovery`/`cam_admin_fee` items) and the immediate **percentage-rent overage** (`percentage_rent` item) — are excluded by item type, so a back-filled/late run still bills the rent (a regular invoice never carries those types). *This item-type test replaced the old `period_end ≤ month-end` heuristic when quarterly/annual billing landed — a multi-month cycle invoice would have slipped past that heuristic and double-billed.* See module 09 § "The billing gap" and §14 below.
   - **Test:** `BillingScenarioTest::test_skips_with_already_billed_when_an_invoice_already_covers_the_period` + `Services/MonthlyBillingServiceTest::test_is_idempotent_a_second_run_for_the_same_period_creates_no_duplicates` + `PercentageRentImmediateBillingTest::the immediate overage invoice does not suppress that month's regular rent invoice`

8. **Lease eligibility has ONE definition, applied by both entry points** —
   `Lease::isBillableForPeriod()`, with `scopeBillableForPeriod()` as its query form:
   - Status = `active` (not draft, terminated, etc.)
   - `commencement_date` ≤ `period_end`
   - `expiry_date` ≥ `period_start` *(the column is NOT NULL, so the open-ended branch both forms
     carry is unreachable today — defence in case it is ever relaxed)*
   - **The manual "Generate Invoice" action used to apply NONE of this.** `runForPeriod()` filters
     eligibility in its query; the single-lease path is handed a lease the operator already picked,
     so it had no filter. Measured: it created a real AR invoice — which posts to the GL — for a
     **terminated** lease, a **draft** lease, and a lease **past its expiry**, each of which the
     scheduled run refused. One click, a dead lease billed into the books.
   - The one existing test that appeared to cover this asserted `no_applicable_charges` for a
     terminated lease, and passed **by accident**: termination deactivates the charges, so the path
     fell through to "nothing to bill" without ever asking whether the lease was billable. A
     terminated lease still carrying one active charge would have billed. The reason is now
     `lease_not_billable`, and the UI explains which of the three it is (wrong status / not yet
     commenced / already ended) rather than showing a misleading "generation failed".
   - **Test:** `ManualBillingEligibilityTest` (both paths agree, and the predicate agrees with the
     scope) + `BillingScenarioTest` draft/terminated/expiry cases.

9. **The final month of an expiring lease is PRO-RATED (changed 2026-08-09, story MF-02).** It used
   to bill in full, because proration keyed on commencement only; the Yardi benchmark ([S8]
   (../benchmarks/yardi/04-scenarios.md#s8--termination-mid-month-and-the-final-account)) is the
   decision that reversed it. **Trailing proration is unconditional** — unlike the commencement kind,
   which stays behind the `$prorate` flag — because billing days after a lease has ended is an error
   with a manual workaround, not a commercial term. A **converted holdover is exempt**: its expiry is
   deliberately in the past and `holdover_from` is what makes it billable at all, so clipping to
   expiry would bill it nothing. The invoice's `period_end` reports the day the lease actually ran
   to, not the calendar month end.

10. **Only active leases with overlapping commencement/expiry are billed by runForPeriod:**
   - Status = 'active' (not draft, terminated, etc.)
   - commencement_date ≤ period_end
   - expiry_date is null OR expiry_date ≥ period_start
   - **Test:** `BillingScenarioTest::test_runForPeriod_does_not_bill_a_draft_lease` + `test_runForPeriod_does_not_bill_a_terminated_lease` + `test_runForPeriod_does_not_bill_a_lease_whose_expiry_precedes_the_period`

11. **Status auto-transitions (unless manually overridden to cancelled/disputed/credited):**
   - `issued` (new invoices)
   - → `overdue` if due_date < today AND balance > 0
   - → `partially_paid` if 0 < paid_amount < total
   - → `paid` if balance ≤ 0 AND paid_amount > 0
   - Manual statuses (cancelled, disputed, credited) are preserved across recomputes
   - **Test:** `InvoiceTest::test_recalculates_balance__status_when_paid_amount_changes`

### Due date & payment terms

12. **Due date never lands in the past:** `due_date = max(issue_date, today) + lease.payment_terms_days` (default 7 if not set)
    - `issue_date` stays at the period start (or the commencement, when prorated) — it is the GL `entry_date` and the `YYYYMM` segment of the invoice number, so it is *not* moved by a late run.
    - The due date instead anchors to when the tenant can actually receive the bill: the later of `issue_date` and today. For an on-time run (the invoice's period is the current month) this equals `issue_date + terms` as before; only a **late / back-filled / off-the-1st** run (a mid-month "Generate Invoice", or `monthly_billing_day > 1`) differs — and there the fix is what stops the invoice being *born overdue* (which would otherwise trip the overdue-scan + a same-day late fee).
    - **Tests:** `BillingScenarioTest::test_derives_the_due_date_as_period_start__payment_terms_days` (on-time) · `InvoiceDueDateNotBornOverdueTest` (late run not born overdue)

### Constraints (database)

11. Invoice.number is UNIQUE
12. Invoice.lease_id is RESTRICTED on delete (soft-deleted invoices retain their lease reference)
13. Invoice.tenant_id is RESTRICTED on delete
14. InvoiceItem.invoice_id is CASCADE on delete (items are purged with invoice)
15. Charge.lease_id is CASCADE on delete (charges are purged with lease)

## 4. Lifecycle / state machine

| Status | Transition trigger | Next state(s) | Terminal? | Mutable via UI? |
|--------|-------------------|---------------|-----------|-----------------|
| `draft` | Manual creation in Filament | `issued` (via save) | No | Yes (read-only in form; set before creation) |
| `issued` | Invoice created, or due_date is future | `partially_paid` (payment > 0), `overdue` (due_date past), `paid` (balance ≤ 0) | No | Yes (can set manually) |
| `partially_paid` | 0 < paid_amount < total | `paid` (more payments), `overdue` (due_date past) | No | Yes (manual override) |
| `paid` | paid_amount ≥ total | ✓ (stable) | Yes (unless manually adjusted) | Yes (manual only) |
| `overdue` | due_date past AND balance > 0 | `partially_paid`, `paid`, or stays `overdue` | No | Yes (manual override) |
| `disputed` | Manual override | Any (manual resolution) | No (pending investigation) | Yes (manual) |
| `cancelled` | Manual override | ✓ (irreversible in practice) | Yes | Yes (manual) |
| `credited` | Manual override (typically after credit note) | ✓ (irreversible) | Yes | Yes (manual) |

**Automatic transitions:** Only issued/partially_paid/overdue → newer status via `recomputeTotals()` after payment/credit changes. Cancelled/disputed/credited are preserved and never auto-overwritten.

**Overdue flag:** An invoice is "overdue" if status is overdue OR (status is issued/partially_paid AND due_date < today). Method: `Invoice::isOverdue()`.

## 5. Services, jobs & scheduled commands

### MonthlyBillingService

**File:** `/app/Services/MonthlyBillingService.php`

#### runForPeriod(?CarbonImmutable $period = null): array

Generates invoices for every active lease for a given month. Defaults to the current month.

**Signature:**
```php
public function runForPeriod(?CarbonImmutable $period = null): array
```

**Return:** `['period' => 'Y-m', 'leases_considered' => int, 'created' => int, 'skipped' => int, 'failed' => int, 'failed_lease_ids' => int[]]`

**Behavior:**
- Selects all leases with status='active', commencement ≤ period_end, and expiry_date >= period_start (or null)
- Processes each in chunks of 100 (via chunkById for memory efficiency)
- **Suppresses the entire invoice during a lease's fit-out / rent-free grace** — `Lease::periodInFitOut()` (from `rent_commencement_date`) returns true for periods inside the grace, so `generateInvoiceForLease` returns null (nothing bills — rent, service, CAM, levy all held). The single-lease path returns reason `fit_out` so the UI says "in fit-out period". See module 04 § "Fit-out grace".
- **Honours the lease billing frequency (in advance)** — a `quarterly`/`semiannual`/`annual` lease (`Lease::billingCycleMonths()` = 3/6/12) bills only on a **cycle-start month** (`isBillingCycleStart()`, anchored to the first billable month); on other months `generateInvoiceForLease` returns null. On a cycle-start month the invoice period spans the whole cycle and each **monthly** charge bills × months-in-cycle (a one-off charge bills ×1). A prorated mid-month commencement prorates only the first month → multiplier `factor + (months − 1)`. The single-lease path returns reason `off_cycle` for a mid-cycle month. Monthly leases (cycle = 1) are unchanged.
  - **Final cycle is capped at the expiry month.** A lease whose term isn't a whole number of cycles has its last cycle truncated at `expiry_date`'s month (both `period_end` and the ×months multiplier shrink together), so nothing bills for whole months after the lease ends — the final month bills in full, matching monthly end-of-term. (Caught by the pre-merge adversarial review.)
  - **Revenue-at-issue (known):** a cycle spanning a year boundary (e.g. quarterly Nov–Jan) recognises the whole cycle's revenue at issue (Nov). This is the system's documented accrual policy — revenue-at-issue, **no** straight-line spread (see `OPEN-QUESTIONS.md A3.2`); the same limitation applies to any advance billing.
  - **Frequency is edit-locked after the first invoice** — cycles are anchored to the commencement, so switching cadence mid-term could strand an unaligned month. The form disables the field once the lease has any invoice; set it at signing.
- Skips any lease that already has an invoice covering the period (idempotent)
- Wraps each lease in its own transaction; one failure doesn't abort the whole run
- Fires `InvoiceIssuedNotification` to tenant on success
- Logs failures with lease_id and exception message

**Idempotency:** Yes. Checked via `Invoice::where('lease_id', $lease_id)->whereDate('period_start', $periodStart)->exists()`.

**Locking:** The whole run holds `Cache::lock('billing:run:Y-m')` (900s) so a manual CLI run can't race the scheduled job; each lease is also wrapped in its own transaction. The single-lease `generateForLease()` path contends on the **same** period lock (see Idempotency §7), so a manual "Generate Invoice" can't race the batch and double-bill.

**When it runs:** Typically via `RunMonthlyBillingCommand` triggered by a scheduler or admin action in Filament (see Tables section). Can also be queued via `--queue` flag.

#### previewForPeriod(?CarbonImmutable $period = null, ?int $assetId = null): array

The **dry run** behind the Billing Run Preview page (`/admin/billing-run-preview`). Returns
`{period, rows[], totals}` — one row per eligible lease with what it *would* be billed, or the
**reason** it would not (`fit_out` · `off_cycle` · `no_applicable_charges` · `already_billed`).
Writes nothing.

**Why it cannot lie:** every row is produced by `planInvoiceForLease()` — the same method
`generateInvoiceForLease()` persists verbatim — and the lease set comes from the same
`billableForPeriod()` scope and the same already-billed probe. A preview computed by a second
implementation is a preview that can drift from the run; this one is the run, minus the writes.

`$assetId` scopes to one property. The scheduled job and the CLI pass null (portfolio-wide,
unchanged); the admin page passes the property the operator is in, and `runForPeriod()` accepts the
same argument so **what gets posted is exactly what was previewed**. Tests:
`tests/Feature/Regression/BillingRunPreviewTest.php` (preview == run, line for line) and
`BillingRunPreviewAuthzTest.php` (posting is gated on `invoices.create`, mutation-verified).

#### generateForLease(Lease $lease, ?CarbonImmutable $period = null, bool $prorate = false): array

Generates a single invoice for one lease for a given month. Used by the Filament UI to issue an invoice for a specific lease on demand.

**Return:** `['status' => 'created'|'skipped'|'failed', 'reason' => string (optional), 'invoice' => Invoice|null]`

**Behavior:**
- Checks idempotency (skips if already billed)
- Loads active charges for the lease
- Filters charges by applicability (frequency + time window)
- If no applicable charges, returns `['status' => 'skipped', 'reason' => 'no_applicable_charges', 'invoice' => null]`
- Applies proration only if (a) prorate=true AND (b) lease commences mid-period
- Computes line items, totals, VAT
- Creates Invoice + InvoiceItems in a transaction
- Accesses marketing levy (wrapped in try-catch so billing is not blocked by budget errors)
- Fires notification on success

**Proration flag:** Defaults to false. When true and commencement is between period start/end, pro-rates charges and shifts period_start/issue_date to commencement.

**When it's called:** Primarily from the Filament invoice creation form (manually triggered by admin) or test scenarios.

### Private helpers

#### chargeAppliesToPeriod(Charge $c, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool

Determines if a charge is due in the given month based on its frequency and time window.

**Quarterly logic (day-of-month agnostic):**
```php
'quarterly' => $charge->start_date
    ? ((($periodStart->year - $charge->start_date->year) * 12 + $periodStart->month - $charge->start_date->month) % 3 === 0)
    : ($periodStart->month - 1) % 3 === 0,
```

**Annual logic:**
```php
'annually' => $charge->start_date
    ? $charge->start_date->month === $periodStart->month
    : $periodStart->month === 1,
```

#### notifyInvoiceIssued(Invoice $invoice): void

Notifies the tenant via `$tenant->notifyPortal(InvoiceIssuedNotification)`. Wraps in try-catch (fails gracefully if notification queue is down).

#### Marketing levy — a billed line, budget accrues from it

**The 5% marketing levy IS billed to the tenant** (operator-confirmed 2026-07-19): `MarketingLevyService::createLevyCharge()` puts a recurring monthly `marketing` Charge (= 5% of base rent) on the lease at creation/renewal/rent-change, and the monthly run bills it as its own line item (routed to `marketing_revenue` in the GL). The property's **marketing budget accrues FROM the billed line item** (`InvoiceItem::booted()`), so there is no double-count — the accrual derives from what was actually billed. *(The old internal-accrual `accrueMarketingLevy()` method is retired.)* **VAT:** currently 0% (mirrors rent); flagged for the accountant as possibly a 14% taxable service — see [BUSINESS-RULES.md](../BUSINESS-RULES.md).

**Per-lease optional + rate override (2026-07-19):** the levy is on by default but a lease can **opt out** (`has_marketing_levy = false` → no marketing line; the charge is deactivated, not deleted) and can **override the rate** (`marketing_levy_rate`; blank = the mall default). `createLevyCharge()` is idempotent and re-runs on lease edit, so toggling the option or changing the rate re-syncs the charge for the next run; both settings carry forward on renewal. See [04-leases.md](04-leases.md).

---

### RunMonthlyBilling (Job)

**File:** `/app/Jobs/RunMonthlyBilling.php`

Queued job that dispatches `MonthlyBillingService::runForPeriod()`.

**Constructor:** `__construct(public ?string $period = null)` — period as 'Y-m' string, defaults to current month.

**Timeout:** 600 seconds (10 min).

**Tries:** 1 (no retry on failure).

**Invoked via:** `RunMonthlyBillingCommand --queue` flag.

---

### RunMonthlyBillingCommand

**File:** `/app/Console/Commands/RunMonthlyBillingCommand.php`

CLI entry point for monthly billing.

**Signature:**
```
billing:run-monthly {--period= : YYYY-MM} {--queue : Dispatch the job}
```

**Usage examples:**
```bash
php artisan billing:run-monthly                    # Current month, sync
php artisan billing:run-monthly --period=2026-03  # March 2026, sync
php artisan billing:run-monthly --queue            # Current month, queued
```

**Behavior:**
- Parses period (defaults to now)
- If `--queue`, dispatches RunMonthlyBilling job
- Otherwise, calls service directly and prints table with stats
- Exits with FAILURE code if any lease failed

---

### ScanOverdueInvoicesCommand

**File:** `/app/Console/Commands/ScanOverdueInvoicesCommand.php`

Notifies Jawad owners about overdue invoices on their properties (daily, idempotent).

**Signature:**
```
billing:scan-overdue-invoices {--dry-run : Print without notifying}
```

**Behavior:**
- Fetches all invoices with status in [issued, partially_paid, overdue], balance > 0, due_date < now, and owner_overdue_notified_at is null
- For each, locks the invoice and re-checks the stamp inside a transaction (prevents concurrent double-notify)
- Resolves owners via `AssetStaffRecipients::owners($asset_id)`
- Sends `InvoiceOverdueOwnerNotification` to each owner (database channel)
- Sets `owner_overdue_notified_at = now()` (idempotency marker)
- If `--dry-run`, prints what would be alerted without writing

**Idempotency:** Via `owner_overdue_notified_at` timestamp. Each overdue invoice alerts once, ever.

**Locking:** Uses `lockForUpdate()` within transaction to prevent overlapping scans from notifying the same invoice twice.

**Concurrency note:** Safe for parallel runs; the lock + re-check pattern prevents duplicate notifications.

## 6. Filament resources & key fields

### Admin InvoiceResource

**File:** `/app/Filament/Admin/Resources/Invoices/InvoiceResource.php`

**Scoping:** `ScopesViaProperty` trait (tenant-scoped). Access controlled via lease.unit.asset_id (property).

**Permissions:** Standard RBAC (can be gated per role via Resource::canCreate/Edit/Delete methods, not shown in read view).

#### Form (InvoiceForm)

**File:** `/app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php`

**Sections:**

1. **Invoice Details** (3 columns)
   - `number` — disabled, auto-generated at save
   - `lease_id` (required) — searchable relationship, server-side search on reference + tenant name + unit code; `live()` afterStateUpdated prefills tenant and items
   - `tenant_id` (required) — relationship, searchable
   - `status` — enum select (draft/issued/partially_paid/paid/overdue/disputed/cancelled/credited)
   - `issue_date` (required) — date picker, live
   - `due_date` (required) — date picker, validated `after('issue_date')` (no same-day or past due dates allowed)
   - `period_start` (required) — date picker
   - `period_end` (required) — date picker

2. **Items** (repeater, live)
   - `type` (required) — enum (base_rent/service_charge/utility/parking/percentage_rent/late_fee/other), default base_rent
   - `description` (required) — text
   - `amount` (required, ≥ 0) — numeric, live(onBlur), triggers recomputeItem()
   - `vat_rate` (required, 0–100%) — numeric, defaults to `Vat::standardRate()` (§8), live(onBlur), triggers recomputeItem()
   - `total` — computed, disabled, shows amount + VAT
   - **Dynamic VAT:** Item auto-recalculates `vat_amount = amount * vat_rate / 100`, then `total = amount + vat_amount`
   - **Live recalculation:** Changes to amount or vat_rate trigger parent invoice totals update (subtotal, vat_amount, total, balance)
   - **Prefilling:** When lease is selected, if no items exist, reads lease.charges (monthly + one_time only) and pre-fills repeater

3. **Amounts** (4 columns, read-only)
   - `subtotal` — sum of item.amount
   - `vat_amount` — sum of item.vat_amount
   - `total` — subtotal + vat_amount
   - `balance` — total - paid_amount

4. **Notes** (collapsible)
   - `notes` — textarea

**Validation:**
- `due_date` must be after `issue_date` (custom message)
- All required fields must be filled
- Amounts must be ≥ 0
- VAT rate 0–100%

#### Table (InvoicesTable)

**File:** `/app/Filament/Admin/Resources/Invoices/Tables/InvoicesTable.php`

**Columns (read-only):**
- `number` — searchable, copyable, mono font
- `tenant.name` — searchable, bold
- `lease.unit.code` — badge, gray
- `period_start` — formatted as "Mar 2026"
- `total` — money (EGP), right-aligned, sortable
- `paid_amount` — money (EGP), success color, right-aligned, sortable
- `balance` — money (EGP), danger if > 0, bold, right-aligned, sortable
- `due_date` — formatted d/m/Y, danger color if past, sortable
- `status` — badge with i18n label, color-coded (success=paid, warning=partially_paid/disputed, danger=overdue, info=issued)
- `eta_status` — badge (if ETA module enabled), color-coded (success=valid, info=submitted, danger=invalid/rejected, gray=cancelled/pending/null)

**Filters:**
- Status — select (draft/issued/partially_paid/paid/overdue)
- Tenant — relationship + search
- Unit — select with search
- Period — date range (period_start)
- Due date range — date range (due_date)
- Overdue only — toggle (balance > 0 AND due_date < now)
- ETA status (if module enabled) — select
- Needs ETA attention — eta_status in (invalid, rejected)
- ETA pending — eta_status is null or pending
- Trashed — soft-delete toggle

**Header Actions:**
- **Export** — CSV via InvoiceExporter
- **Run monthly billing** — admin action, requires confirmation, launches MonthlyBillingService::runForPeriod() and shows success/warning notification with stats

**Record Actions:**
- **Edit** — if canEdit($record)
- **Download PDF** — streams InvoicePdfService::build($record) as PDF
- **Send WhatsApp** — if config enabled, status in [issued/partially_paid/overdue], visible if canEdit()
- **Submit to ETA** — if ETA module enabled, eta_status not already 'valid', status in [issued/partially_paid/paid/overdue]
  - Shows mock/live warning
  - Calls EtaSubmissionService::submit($record)
  - Notifies on success with updated eta_status and submission_id

**Bulk Actions:**
- Export
- Download PDFs as ZIP
- Bulk submit to ETA (skips already-valid, notifies count submitted/skipped)
- Delete (soft)
- Force delete
- Restore

**Navigation badge:** Count of overdue invoices (balance > 0, due_date < now) in the active property, red color.

**Global search:** Searches number, tenant.name, lease.unit.code, lease.reference.

---

### Portal InvoiceResource (Tenant view)

**File:** `/app/Filament/Portal/Resources/Invoices/InvoiceResource.php`

**Scoping:** Filtered to tenant_id = Portal::tenantId() (current logged-in tenant).

**Capabilities:** Read-only (canCreate/Edit/Delete all return false).

**Pages:**
- **ListInvoices** — table view (same columns as Admin, minus eta_status if tenant-facing, minus edit actions)
- **ViewInvoice** — detail view via infolist

---

### Filament TenantScope

**Reference:** `/app/Support/TenantScope.php`

Used in form/table queries to auto-scope to the current property (Asset):
```php
->when(
    TenantScope::currentAssetId(),
    fn ($q, $assetId) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId))
)
```

## 7. Notifications & integrations

### InvoiceIssuedNotification

**File:** `/app/Notifications/InvoiceIssuedNotification.php`

**Sent to:** Tenant (the billed entity).

**Channels:** mail + database (bell entry).

**Email:**
- Subject: "Invoice {number} issued"
- Markdown template: `emails.invoice-issued`
- Attachment: PDF from InvoicePdfService (generated inline)

**Database (bell):**
- Type: 'invoice_issued'
- Title: "Invoice Issued"
- Body: "Invoice {number} · EGP {total} due {due_date}"
- Icon: document-text, color primary
- Duration: persistent (stays until dismissed)

**Fired:** At the end of `MonthlyBillingService::generateInvoiceForLease()` (both batch and single-lease flows).

**Wrapping:** Wrapped in try-catch in `notifyInvoiceIssued()` — failures log but don't block invoice creation.

---

### InvoiceOverdueOwnerNotification

**File:** `/app/Notifications/InvoiceOverdueOwnerNotification.php`

**Sent to:** Jawad owners of the property (via AssetStaffRecipients::owners()).

**Channels:** database (bell only, not email).

**Database:**
- Type: 'invoice_overdue'
- Title: "Invoice Overdue"
- Body: "Invoice {number} · {days} days overdue · EGP {balance} owed"
- Icon: banknotes, color danger
- Duration: persistent

**Fired:** By `ScanOverdueInvoicesCommand` for each invoice with balance > 0, due_date < today, and no prior notification (idempotent via `owner_overdue_notified_at`).

---

### ETA Integration

**Module flag:** `Modules::enabled('eta')`.

**Interaction:** Invoices can be submitted to the Egyptian Tax Authority (ETA) system.

**Fields:**
- `eta_submission_id` — unique ID returned by ETA
- `eta_submitted_at` — timestamp of last submission
- `eta_response` — JSON blob (full ETA response)
- `eta_status` — enum (pending, submitted, valid, invalid, rejected, cancelled)
- `eta_long_id` — alternate ETA identifier

**Service:** `EtaSubmissionService::submit(Invoice $invoice): Invoice` — updates eta_* fields and returns the refreshed model.

**Visibility:** Filters/actions in table are hidden if ETA module is disabled.

**No side effects on AR:** ETA submission is purely compliance; it doesn't affect invoice balance, status, or payment reconciliation.

## 8. Extension points — how to change/extend SAFELY

### Adding a new charge type

1. **Add to enum:** Edit migration or add new migration to expand the `type` enum in both `charges` and `invoice_items` tables.
   ```php
   // In migration:
   $table->enum('type', ['base_rent', 'service_charge', 'utility', 'parking', 'percentage_rent', 'late_fee', 'other', 'my_new_type']);
   ```

2. **Update Charge model:** No code change needed (enum is handled by DB).

3. **Update VAT logic (if applicable):** If the new type should be taxed:
   - In Filament form, the VAT rate defaults to 14 but is user-selectable per charge.
   - No code change needed; the form respects `charge.vat_applicable` already.

4. **Add translation keys:** For Filament enums, add i18n keys:
   ```php
   // In app/Filament/Resources/Invoices/Schemas/InvoiceForm:
   Select::make('type')->options(fn () => __('admin.enums.invoice_item_type'))
   // Ensure 'admin.enums.invoice_item_type.my_new_type' exists in lang files
   ```

5. **Add tests:** Add a scenario in `BillingScenarioTest.php` that exercises the new type.
   ```php
   it('bills the new charge type with correct VAT', function () {
       $lease = billingLease();
       billingCharge($lease, [
           'name' => 'My Charge', 'type' => 'my_new_type', 'amount' => 1000,
           'vat_applicable' => true, 'vat_rate' => 14
       ]);
       $invoice = app(MonthlyBillingService::class)->generateForLease($lease)['invoice'];
       expect((float) $invoice->vat_amount)->toBe(140.0);
   });
   ```

**DO NOT:** Hard-code charge types in the service; the system is generic and works by reading charge.type and charge.vat_applicable.

---

### Changing the VAT rate (globally or by tenant)

**Global rate: BUILT 2026-07-30 — this section used to describe it as future work.** The rate lives
in `TaxSettings::vat_standard_rate` (alongside withholding tax — it is tax policy, not billing
cadence), is edited at **/admin/settings → Tax**, and is read **only** through
`App\Support\Vat`:

```php
Vat::rateForType($code);      // ← what a NEW line of this charge code bills at. Use this.
Vat::onType($amount, $code);  // the VAT due on it
Vat::standardRate();          // the configured percentage, e.g. 14.0
Vat::atRate($amount, $rate);  // VAT at a stored/frozen rate (a document, a CAM pool)
Vat::EXEMPT;                  // 0, named — never a bare literal at a call site
```

**Do not write a literal rate anywhere.** `VatRateSettingTest` scans `app/` and fails with the
offending file:line if one reappears — that is how the previous eight copies were found.

**Do not call `standardRate()` / `on()` from a service either.** They cannot see the accountant's
ruling, so a service using them keeps taxing a supply the catalogue exempted —
`ExemptChargeTypesAgreeAcrossPathsTest` fails the build on one under `app/Services`.

### Billing a charge code the accountant added

No deploy, end to end (shipped 2026-08-11 — the sweep's §9 L7):

1. **Charge Codes → New**: the code, both names, the posting role (which account it books to) and the
   VAT treatment.
2. **The lease → Charge schedule → Add charge**: pick the code, amount, frequency and the month it
   starts. It routes through `ChargeScheduleService::setAmount()`, so it closes-and-opens like every
   other writer; adding a code the lease already has RESTATES it from that date rather than
   rewriting what was billed. VAT defaults from the code's treatment and stays editable for the deal.
3. The monthly run bills it, and `InvoiceJournalizer` posts it to the account chosen in step 1
   (through `ChargeCode::roleFor()`), with no code change anywhere.
4. **Stop charge** on a schedule row ends future billing from a chosen month
   (`ChargeScheduleService::close()`); everything already billed stays as billed.

`charges.type` was a DB enum until then, so a code added in step 1 could be billed as a one-off
invoice line and **not** set up as a recurring charge — the promise stopped where most of the money
is. The enum's checking is replaced by `Charge::assertTypeIsAKnownChargeCode()`: catalogue first,
`InvoiceItemType` as the floor for an unseeded database, refusing with a message that names the
catalogue instead of a driver error.

**Three types are not hand-writable** — `base_rent`, `marketing`, `parking`. Each is DERIVED by its
own service (Change Rent, the levy off base rent, the rentable-items pivot), and a hand-made row
would sit beside the one that service maintains and double-bill. The picker disables them and the
action refuses them.

### Making a charge code exempt (or taxable)

No deploy: **Charge Codes → the code → VAT treatment**. Exempt and zero-rated both bill 0; pick
zero-rated only for a supply that is taxable at 0%, since the two are reported apart. A code on a
schedule rate of its own fills in **Rate for this code**; left blank it follows the standard rate,
including future changes to it. A ruling reaches the **next** charge or invoice line raised —
issued documents keep the rate they were billed at.

**Only origination reads the setting.** Once a charge or invoice line exists it carries its own
`vat_rate` column, and every downstream path (the monthly run, renewal, rent changes, credit notes,
the ETA payload) reads that stored figure. This is deliberate and must not be "simplified": an
invoice issued at 14% stays a 14% document forever. Changing the setting affects what is billed
**next**, never what was already billed — otherwise a rate change would silently rewrite history and
de-tie the books from returns already filed.

**Per-supply rates** are already supported without touching the setting: `charge_codes.vat_treatment`
(+ `vat_rate_override`) sets what a supply originates at, `charges.vat_rate` is per-charge, and a CAM
pool's `recovery_vat_rate` is frozen with its basis at reconciliation.

**Per-tenant or per-lease rate:** Currently not supported. To add, store vat_override on Tenant or Lease and read it in MonthlyBillingService. This would be a larger feature (need UI, tests, migration).

---

### Adding a new charge frequency

Example: **fortnightly** (every 2 weeks).

1. Add to migration:
   ```php
   $table->enum('frequency', [..., 'fortnightly']);
   ```

2. Implement `chargeAppliesToPeriod()` logic:
   ```php
   'fortnightly' => // biweekly logic here
   ```

3. **Challenge:** Fortnightly doesn't align to calendar months. You'd need to track which fortnight(s) fall within the billing month. More complex than monthly/quarterly/annual.

4. Test thoroughly to avoid billing a charge 0 or 2 times per month unexpectedly.

**Recommendation:** Keep to calendar-aligned frequencies (monthly, quarterly, annual) unless you redesign the period model.

---

### Integrating a payment gateway (e.g., Paymob)

The payment system is separate from invoicing; see the Payment module. Key integration points:

- When a payment is captured, `Payment.invoices().attach($invoiceId, ['allocated_amount' => $amt])`
- Capture webhook triggers `$invoice->recomputeTotals()` (see PaymentController or PaymentService)
- This updates paid_amount, balance, and status

**To integrate:**

1. Ensure the gateway can POST payment confirmations.
2. In the payment handler, call `Invoice.recomputeTotals()` after attaching payments.
3. Write a test that simulates the webhook → attachment → recompute flow.

**DO NOT:** Modify invoice.total or invoice.subtotal in the payment handler; these are set at issuance and immutable.

---

### Customizing invoice PDF layout or adding dynamic sections

**File:** `/app/Services/InvoicePdfService.php` (not shown, but referenced throughout).

To customize the PDF:

1. Edit `InvoicePdfService::build(Invoice $invoice): string` to change PDF generation logic.
2. Ensure the PDF includes all required tax fields for ETA compliance (if applicable).
3. Test the PDF visually and ensure file size is reasonable (PDF generation can be slow).

---

### Extending the AR reconciliation (e.g., discount, writing off bad debt)

Currently `balance = max(0, total − paid_amount)`, where `paid_amount` already includes the applied credit (see §2's four-channel formula — this line previously double-counted it). To add discounts or write-offs:

1. Add a column `invoices.discount_amount` or `invoices.writeoff_amount`.
2. Update `Invoice::recomputeTotals()`:
   ```php
   $settled = $paid + $credit_applied + $discount + $writeoff;
   $this->balance = max(0, $this->total - $settled);
   ```

3. Add UI in Filament form to set discount_amount.
4. Add audit logging (already in place via LogsActivity on Invoice).
5. Test that balance remains >= 0.

**Validation:** Ensure total discount + writeoff ≤ total (no negative balances).

---

### Implementing late fees

Late fees are NOT generated automatically by MonthlyBillingService; they are applied on-demand via `LateFeeService::runForToday()` (separate module, not detailed here). To integrate with invoicing:

1. `LateFeeService` creates an InvoiceItem with type='late_fee' and attaches it to the overdue invoice.
2. The invoice's total increases; balance is recalculated.
3. Test: `BillingMathTest::test_late_fee_applies_once_per_invoice` confirms idempotency and grace period.

---

## 9. Gotchas, edge cases & recently-fixed bugs

### A late fee does not post to the GL in real time — and the schedule ordering is why that is fine

`LateFeeService::applyTo()` bumps `subtotal`/`total` and calls `recomputeTotals()`, which saves
**quietly**. `saveQuietly()` skips model events, so the near-real-time GL hook
(`LedgerRealtimeSync`) never fires. Measured end to end:

| moment | GL | invoice |
| --- | --- | --- |
| after the initial post | 10,000 | 10,000 |
| immediately after the late fee | **10,000** | **10,200** |
| after `accounting:sync-ledger` | 10,200 | 10,200 |

**This is deliberate, not a defect.** `LedgerPoster::sync()` names this exact case — *"entry differs
(e.g. late fee bumped the total) → void the stale one + re-post"* — and chooses sweep-based
self-healing over entangling the real-time hooks with the `recomputeTotals`/`saveQuietly` machinery.

**What makes it safe is the schedule ordering, which is therefore load-bearing:**
`ApplyLateFees` runs at **04:00**, `accounting:sync-ledger` at **05:00**. The drift lasts about an
hour. Moving the fee job after the sweep would silently stretch that to ~24 hours and put a
month-end fee at risk of its accounting period closing before the entry posts — at which point the
re-post is refused and it surfaces as `LedgerSyncFailed` instead. Both `routes/console.php` entries
now say so; don't reorder them without reading that note.



### 1. Proration factor precision

**Gotcha (corrected):** the factor is **NOT** rounded — it is kept at full precision and only the
per-line *money* is rounded to 2 dp (`round($charge->amount * $multiplier, 2)`). Rounding the factor
was the earlier behaviour and it undercharged: a clean fraction like 1 day of 30 billed 999 instead
of 1000. Round the amount, never the ratio.

**Example:** 16 / 30 = 0.5333… kept in full. × 10,000 = 5,333.33 (not 5,333.00).

**Impact:** Minimal; only visible in edge cases with many fractional cents. The 2-decimal rounding per item absorbs most variance.

---

### 2. Quarterly charge bug (FIXED)

**Bug (old code):** `chargeAppliesToPeriod()` used `diffInMonths()` to decide quarterly applicability. `diffInMonths()` counts whole months, so 2026-01-15 to 2026-04-01 = 2 whole months (not 3). A quarterly charge would be billed a month late.

**Fix:** Use calendar-month delta: `((periodYear - startYear) * 12 + periodMonth - startMonth) % 3 === 0`. This is day-of-month agnostic and correctly identifies 3-month cadences.

**Tests:** `QuarterlyChargeTimingTest` pins the corrected behavior.

**Impact:** Quarterly charges now bill on the correct month. This is a breaking fix; if you have old invoices that were generated with the old logic, they are already in the past and immutable.

---

### 3. Credit note AR drift (FIXED)

**Bug (old code):** When a credit note was applied to an invoice, it bumped `paid_amount`, but `Invoice::recomputeTotals()` (called on a later payment) only summed `captured payments` pivot — it ignored the applied credit. So the credit was silently erased.

**Example:**
- Invoice total 1000, issued.
- Credit 300 applied → paid_amount = 300, balance = 700.
- Payment 700 captured → `recomputeTotals()` sums only the payment (700) and sets paid_amount = 700, balance = 300. The credit vanishes!

**Fix:** Added `invoices.credit_applied_amount` column. `CreditNoteService::applyToInvoice()` bumps this column, then calls `recomputeTotals()`, which sums both the payments pivot AND credit_applied_amount.

**Formula at the time of that fix:** `paid_amount = sum(captured payments) + credit_applied_amount`. Two more channels have been added since (tenant credit, then a netted security deposit) — see §2 for the current four.

**Migration:** Backfilled existing invoices by calculating credit = paid_amount − sum(captured payments) for each invoice.

**Tests:** `CreditNoteBalanceDriftTest::test_keeps_an_applied_credit_when_a_later_captured_payment_recomputes_the_invoice`.

**Impact:** Credits are now durable and survive payment recomputes. This is critical for AR accuracy.

---

### 4. Invoice number collision risk (low)

**Gotcha:** Invoice numbers are generated at save time (booted hook) to ensure uniqueness. If two requests create invoices in quick succession (same second, same property, same month), they could contend on the sequence counter. The code includes a retry loop (up to 100 attempts) to allocate a unique number.

**Safeguard:** Each invoice.number is UNIQUE in the DB. If a collision occurs, the save fails and exception is caught by MonthlyBillingService (logged as failure for that lease).

**Impact:** Extremely rare; the 100-attempt retry is conservative. Only possible if you're bulk-creating invoices in parallel for the same property in the same second.

---

### 5. Charge window edge cases

**Gotcha:** If a charge has start_date = 2026-03-15 and you bill 2026-03-01, the charge does NOT apply (its start is after period_end). The period must overlap the charge window.

```php
// In chargeAppliesToPeriod():
if ($charge->start_date && $charge->start_date->greaterThan($periodEnd)) {
    return false;
}
```

**Why:** Prevents double-billing a charge if it starts mid-period and proration is not enabled. Callers must explicitly enable prorate=true to bill the partial month.

---

### 6. Null payment_terms_days

**Gotcha:** If a lease has no payment_terms_days (null), the default is 7. This is in `MonthlyBillingService::generateInvoiceForLease()`:

```php
$dueDate = $issueDate->addDays($lease->payment_terms_days ?? 7);
```

**Impact:** Safe; the default is reasonable for most leases.

---

### 7. Lease status transition and billing

**Gotcha:** `runForPeriod()` only bills active leases at query time. If a lease transitions from draft → active after the query runs, it won't be billed for that month. Conversely, if a lease terminates mid-period, it was already billed for the full month (no proration on termination).

**Why:** Leases are billed monthly in bulk; individual status changes are not re-triggered. To re-bill a lease after status changes, call `generateForLease()` explicitly (UI or manual command).

---

### 8. Soft-deletes and invoice lookups

**Gotcha:** Invoices are soft-deleted (SoftDeletes trait). When checking idempotency, the service uses `Invoice::where('lease_id', $lease)->whereDate('period_start', $period)` — this does NOT include soft-deleted invoices by default (Eloquent scoping).

**Why:** Prevents re-creating an invoice if the old one was accidentally trashed.

**If you need to untrash:** Use `->withTrashed()` or `->onlyTrashed()` queries explicitly.

---

### 9. Marketing levy — billed line, budget accrues from it

**Gotcha:** the 5% marketing levy is a real `marketing` **Charge billed to the tenant** (a line on the monthly invoice), NOT an internal-only accrual. The property's marketing **budget accrues from the billed `InvoiceItem`** (via `InvoiceItem::booted()`), so the accrual mirrors what was actually billed (a prorated month accrues 5% of the prorated rent) with no double-count.

**Why:** tenants pay a "marketing fund contribution" on top of rent (standard mall practice). The budget accrual is a non-AR side-effect derived from the billed line.

**Impact:** the levy raises the tenant's invoice total + AR and posts to `marketing_revenue`. VAT is currently 0% — flagged for the accountant as possibly 14%.

---

### 10. Period boundary conditions

**Gotcha:** A lease with commencement_date = 2026-03-31 (end of month) and billing for 2026-03 results in prorated billing for 1 day (factor = 1/31). This is correct but very small charge. Depending on VAT, the rounded charge could be 0.01 EGP or 0.

**Why:** Math is precise; edge cases are rare in practice.

**Mitigation:** Manual invoice creation in Filament allows override of amounts if needed.

---

### 11. Concurrent overdue scans

**Safeguard:** `ScanOverdueInvoicesCommand` uses `lockForUpdate()` within a transaction to prevent overlapping runs from double-notifying the same owner. The idempotency marker `owner_overdue_notified_at` ensures each invoice is alerted only once.

**Impact:** Safe to run the command frequently (e.g., every hour) without risk of duplicate notifications.

---

## 10. Tests & related modules

### Test files for this module

| File | Purpose |
|------|---------|
| `/tests/Feature/Services/MonthlyBillingServiceTest.php` | Batch billing idempotency, lease filtering, charge applicability |
| `/tests/Feature/Scenarios/BillingScenarioTest.php` | End-to-end invoice generation, VAT, proration, due dates, frequency logic |
| `/tests/Feature/Scenarios/InvoiceOverdueScenarioTest.php` | Overdue status, owner notifications, scanning |
| `/tests/Feature/Models/InvoiceTest.php` | Invoice model helpers (isOverdue, daysOverdue, recalculateBalance, number generation) |
| `/tests/Feature/BillingMathTest.php` | Percentage rent, CAM allocation, late fees, billing idempotency (integration scenarios) |
| `/tests/Feature/Regression/QuarterlyChargeTimingTest.php` | Quarterly charge calendar-month logic (regression) |
| `/tests/Feature/Regression/CreditNoteBalanceDriftTest.php` | Credit + payment reconciliation (regression) |
| `/tests/Feature/Resources/InvoiceEtaFiltersTest.php` | ETA compliance filters in Filament table |
| `/tests/Feature/Resources/InvoiceDateValidationTest.php` | Filament form validation (due_date > issue_date) |
| `/tests/Feature/Notifications/InvoiceAndPaymentNotificationsTest.php` | Notification dispatch (issued, overdue) |
| `/tests/Feature/InvoiceOverdueOwnerAlertTest.php` | Owner overdue notifications and idempotency |
| `/tests/Feature/Api/V1/InvoicesTest.php` | API endpoints (if any) |
| `/tests/Feature/Api/V1/Tenant/DemoPayInvoiceTest.php` | Tenant payment simulation |

**Key test scenarios to understand before extending:**
1. **BillingScenarioTest** — canonical source for business rules (VAT, proration, due dates, charge frequency)
2. **QuarterlyChargeTimingTest** — quarterly billing cadence (the fixed bug)
3. **CreditNoteBalanceDriftTest** — credit + payment AR settlement (the fixed bug)

---

### Related modules

| Module | Interaction |
|--------|-------------|
| **Lease** | Invoices are tied to leases; lease commencement/expiry/status control billing eligibility. Payment terms default is read from lease. |
| **Charge** | Defines what is billed; the service reads Charge.type, frequency, amount, vat_applicable, vat_rate, start_date, end_date, is_active. |
| **Payment & Payment Pivot** | Captured payments settle AR via the invoice_payment pivot. `Invoice::recomputeTotals()` is called whenever payments change. |
| **Credit Notes** | Applied credits settle AR durably via credit_applied_amount. `CreditNoteService::applyToInvoice()` bumps this column. |
| **Late Fees** | Applied on-demand via separate LateFeeService; adds an InvoiceItem type='late_fee' to an overdue invoice. |
| **CAM Reconciliation** | CAM allocations are billed to tenants as charges; the service reads active charges and bills them. |
| **Marketing Levy** | 5% of base rent, **billed to the tenant** as a `marketing` line; the property marketing budget accrues from the billed line item (no double-count). |
| **ETA Compliance** | Invoices can be submitted to the Egyptian Tax Authority; ETA status is tracked but does not affect AR. |
| **Tenant & Tenant Notifications** | Notifications are sent to tenants (mail + portal bell) on issuance. |
| **Jawad (Owner) Notifications** | Owner overdue alerts are sent via the Notification system (database channel). |

---

### Documentation links

- **Lease module:** `/docs/modules/xx-leases.md` (commencement, expiry, status, payment terms)
- **Charge module:** Not a separate module; Charge is a sub-entity of Lease. See Lease module for details.
- **Payment module:** `/docs/modules/xx-payments.md` (payment capturing, reconciliation, Paymob integration)
- **Credit Notes module:** `/docs/modules/xx-credit-notes.md` (credit issuance, application, AR settlement)
- **Late Fees module:** Integrated into Billing module; LateFeeService applies fees to overdue invoices.
- **ETA Compliance module:** `/docs/modules/xx-eta.md` (Egyptian Tax Authority integration)

---

## Summary

The Billing & Invoices module is the core AR engine of the platform. It automates monthly invoice generation from lease charges, enforces Egyptian tax compliance (14% VAT on service charges only), supports lease commencement proration, and reconciles payments + credit notes durably. The system is designed for idempotency (safe to re-run) and includes extensive tests covering edge cases (quarterly billing cadence, credit + payment drift, proration precision). Key extension points are adding charge types/frequencies and integrating external payment gateways. Recently fixed bugs include quarterly charge timing (day-of-month agnostic calendar delta) and credit note AR drift (via credit_applied_amount column), both of which are regression-tested.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Invoice` | **Never deletable** | cancel the invoice, or issue a credit note |
| `Charge` | Deletable (super_admin) | configuration: a recurring billing line; issued invoices keep their own copy |
| `InvoiceItem` | Deletable (super_admin) | parent-managed: rebuilt whenever the invoice is recomputed |

---

## Bad-debt write-off (2026-08-09)

An uncollectible receivable had two homes before this, and **both were wrong**:

- **Cancel** reverses the revenue in the *current* period — including revenue earned and recognised
  in a prior year. The year it was actually earned ends up understated, this year overstated, and
  the bad debt never appears as bad debt at all; it hides as a revenue reduction.
- **Leave it** and AR aging carries fiction forever, so every collections figure lies.

`WriteOffInvoiceService::write()` keeps the revenue where it was earned, credits AR, and debits
Bad Debt Expense **dated at the decision** — [`InvoiceWriteOff`](../../app/Models/InvoiceWriteOff.php)
is its own document with its own date, reason and author, because a column would have left the GL
with nothing to post and no date to post it on.

| Rule | Why |
|---|---|
| Status becomes **`written_off`**, never `cancelled` | `cancelled` means *this should never have been billed*; `written_off` means *it was rightly billed and will not be paid*. Different facts, different accounting |
| `balance` is **left standing** | It is derived by `recomputeTotals()` from payments and applied credit, and a write-off is neither. The balance is the record of *what was written off*; the **status** is what takes it out of AR |
| `written_off` joins the `recomputeTotals()` overrides | otherwise the next recompute drags an accepted-uncollectible debt back to `overdue` |
| `written_off` is excluded from the **AR tie-out expectation** | the GL side has already been relieved, so counting the untouched balance would raise a false AR delta on every written-off debt (mutation-verified) |
| A **partial** write-off leaves the invoice live | writing off 5,000 of a 20,000 debt does not mean the other 15,000 stopped being owed |
| Reversal is a **soft-delete**, not an edit | a recovered debt; the sweep voids the entry and *both* decisions stay on the record. This is also why the model is parent-managed rather than `NEVER_DELETABLE` — classifying it `NEVER` would have broken the recovery path, the exact trap `CLAUDE.md` warns about |
| The date is guarded in the **service** | it is operator-typed and becomes a journal `entry_date`; without the guard the row commits, the operator sees "Saved", and the entry is refused inside the best-effort sync job that only logs |

Registered as a GL source (`LedgerPoster::JOURNALIZERS` + `LedgerRealtimeSync::SOURCE_DATE_COLUMNS`
+ `PostingDateGuards::GUARDS`), and its tie-out test drives the **real service and the real
`accounting:sync-ledger` sweep** — a test that calls `LedgerPoster::post()` directly would prove
only the journalizer's arithmetic.

Tests: `tests/Feature/Regression/InvoiceWriteOffTest.php`.
