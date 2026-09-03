# Billing & Invoices
> Generate and track monthly invoices for leased retail units, including VAT compliance, proration, payment reconciliation, and overdue notifications.

> **⚠️ 2026-08-28 — "Nothing was billed" now says WHY, and there is one wording.**
> Reported from the panel: pressing *Bill this period* on a lease's Billing forecast tab answered
> **"Nothing was billed"** over the literal string `admin.billing_preview.reason.lease_not_billable`.
>
> `generateForLease()` answers a refusal as a machine CODE, and `admin.billing_preview.reason.*` is
> the SHORT vocabulary a preview table CELL renders — it had wording for the codes a *plan* can
> produce and none for the three `generateForLease()` adds on top of one (`lease_not_billable`,
> `run_in_progress`, `exception`). The forecast tab routed those into it anyway.
>
> The deeper defect is why that was possible: the lease's own **Generate Invoice** action turned the
> same codes into words with a seven-branch ladder of its own — a title and a paragraph of advice
> per code, including a three-way reading of `lease_not_billable` (wrong status / term ended / not
> yet commenced). **One machine code, two independent translations**, and only one was updated when
> the vocabulary grew. Both now go through `App\Support\BillingRefusal::explain()`.
>
> Two more defects fell out of putting it in one place. **`not_billable_expired` reads "…so :period
> falls after its term" and its only call site passed `date`** — so the operator read the literal
> `:period` mid-sentence, in both languages. And every branch formatted the month with
> `format('F Y')`, which is not localised, so an Arabic refusal said «لا يمكن إصدار فاتورة لهذا
> العقد عن **August 2026**». The billing-window refusal three lines above that ladder had used
> `->locale(app()->getLocale())->isoFormat('MMMM YYYY')` correctly the whole time.
>
> **Why no gate caught it.** `TranslationKeyConformanceTest` resolves an interpolated key to its
> PREFIX — `admin.billing_preview.reason` exists in both catalogues, so every LEAF under it is
> invisible, in every locale. `BillingRefusalVocabularyConformanceTest` checks the leaves and
> derives the vocabulary from `MonthlyBillingService`'s own source rather than from a list beside
> it. Its first version was itself too weak, and mutation testing said so: one fixture per *code*
> never reached the expired branch, so deleting the `:period` fill left it green. It now runs one
> case per refusal an operator can actually be shown, and asserts that case list covers every code
> the service emits. (`ABillingRefusalNamesItsCauseTest`.)

> **⚠️ 2026-08-16 — how far ahead an operator may bill now has ONE answer.**
> The Billing Run Preview offered the last 12 months plus the next one; the lease's own **Generate
> Invoice** picker carried **no bounds at all** and `generateForLease()` no future check — so the
> same deal was billable four months early from one screen while the other refused even to preview
> it. Raising a receivable years ahead posts revenue into a period that may not exist and dates an
> e-invoice into the future.
>
> The rule is `App\Support\BillingWindow` and both screens read it; the picker's `minDate`/`maxDate`
> are the UI half and the closure re-checks, because a picker is not a guard. **It bounds the
> OPERATOR, not the engine** — `MonthlyBillingService` stays unclamped on purpose, since the
> scheduled run, `billing:run --period=`, a backfill and the test suite all bill periods chosen
> deliberately by someone who is not clicking a button. The line is "typed into a form", which is
> where a mis-key becomes a receivable nobody meant to raise.

> **⚠️ Fixed 2026-08-16 — the month rent COMMENCES was billed whole.**
> A lease with `rent_commencement_date = 15 April` was invoiced the full April rent.
> `Lease::rentCommencesOn()` normalises to the 1st — correctly, since billing periods are whole
> months and April genuinely is the first rent month — and its docblock called the remaining half
> *"a proration question, not a period question"*. Nothing answered that question:
> `planInvoiceForLease()` clipped its leading edge to `commencement_date` alone, which on a lease
> that commenced in January is months past, so the multiplier came out at 1.0. **On a 100,000 rent
> that is 46,666.67 charged for a fortnight of a contractually rent-free period — on the first
> invoice a new tenant ever receives.**
>
> The clip is **per charge TYPE**, which is the part worth keeping in mind: under net abatement
> (`rent_only`, the default) the tenant has been paying the service charge and the marketing levy
> since handover, so those bill the **whole** month while the rent beside them bills half. Under
> `gross` the entire invoice was abated, so the entire invoice is clipped. The predicate is
> `Lease::graceAbates()` — deliberately *dateless*, unlike its sibling `abatedChargeTypesFor()`,
> because the crossover month is no longer inside the fit-out window and yet part of it was still
> rent-free.
>
> **Unconditional, for the trailing edge's reason rather than the leading one's.** Whether a
> mid-month *move-in* pays for part of the month is a commercial term the operator sets per run
> (the `$prorate` flag); whether rent is owed before the date the contract says rent commences is
> not a question at all. Only the prorated LINE carries the `(x% pro-rated)` label, so a full-month
> service charge beside it is not mislabelled. Pinned by `RentCommencementIsProratedTest`.


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
>
> **⚠️ The COLLECTIONS half of the same problem, fixed 2026-09-01.** That pass taught the write-off
> side and the tie-out to net prior write-offs, and stopped. Every surface that decides *whether or
> how much to CHASE* still read `balance` — so a partly-forgiven tenant went on being sent overdue
> notices, dunning letters and late fees for the part the operator had written off and the bad-debt
> entry had already relieved. It got sharper, not milder, when the settlement guards learnt the same
> netting: the invoice could then not be paid down to zero either, because the cap refused the
> forgiven part while the reads went on demanding it. A debt that can be neither collected nor closed.
>
> `Invoice::collectableBalance()` is the missing third term — `balance` answers *what was owed*,
> `status` answers *has this left the books*, and a partial write-off is neither.
> `chargeableBalance()` is its penalty twin (collectable, less `disputedOutstanding()`), and the two
> reductions are deliberately different questions: a DISPUTED amount is still claimed and merely not
> chargeable, a FORGIVEN one is not claimed at all. Naming them as a pair is what stops the next
> reduction becoming a fourth inline subtraction somebody has to remember at each site.
>
> **`balance` is unchanged and must stay so** — the write-off cap, the tie-out and the statement
> history all rest on it recording what was billed. What changed is who asks: the overdue scan, the
> tenant dunning sweep, the late-fee selection *and* base, `Tenant::outstandingBalance()` and
> `isDelinquent()`, both AR chokepoints in `ReportService` (which feed ageing, the collections
> worklist, the CSV and five widgets), `MoveOutStatementService` — the one reader where it cost the
> tenant real money, by withholding that much of their own deposit — the statement PDF, the portal
> filter, the reconcile control total, and **all three notifications**. That last group is the
> lesson: the first pass routed the sweeps and left the letters quoting `balance`, so the system
> selected the right invoices and then asked for the wrong amount, which is worse than fixing neither.
>
> Two traps in the query twin, both caught before shipping. `collectableBalanceSql()` uses a **CASE,
> not `GREATEST`** — that function does not exist in SQLite, so a MySQL-only expression is green on
> the real database and fatal in every test. And it takes the query's own table name, because Laravel
> aliases a self-relation's inner table (`whereHas('lateFeeInvoice', …)`) and a hardcoded `invoices.`
> then binds to the OUTER row: valid SQL, no error, wrong answer.
> (`AForgivenSliceIsNotChasedTest`, mutation-proved per layer — including `considered`, because the
> row-level re-check makes every outcome assertion pass with the selection reverted.)
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


> **⚠️ Fixed 2026-08-11 — two defects in `alreadyBilledForMonth()`.**
>
> **A cancelled invoice counted as "already billed."** Voiding a wrong invoice therefore blocked
> re-billing that lease-month **permanently** — both the bulk run and the manual action reported
> `skipped: already_billed` forever, indistinguishable in the run summary from a lease billed
> correctly. Silent lost revenue whose only symptom is money that never arrives. `written_off` is
> deliberately NOT excluded: that debt was rightly billed and still sits on the books as bad debt,
> so re-billing it would charge the tenant twice.
>
> **`nsf_fee` was missing from the one-off exclusion list** — the fourth instance of a class already
> fixed for `percentage_rent`, `utility` and `violation_fine`. A bounced-cheque fee is its own
> invoice dated to the current month, so it overlapped the recurring window and a tenant whose
> cheque bounced silently lost that month's rent invoice. The shape to watch for: *a standalone
> one-off invoice dated into a month the recurring run also bills.*
>
> Pinned by `RebillAfterVoidTest`, which carries the control that the guard is not now too broad.


> **An invoiced period reads from its invoice, in every column (2026-08-28).** The billing forecast's
> reasoning was already right — *"where the period has already been invoiced, the ACTUAL figure is
> the truth about it"* — and was applied to ONE figure: `total` came from the invoice while the
> lines, the net and the VAT beside it stayed the plan.
>
> So a period whose charge was corrected AFTER it was billed rendered a row built from two truths:
> reported from the panel as a service charge reading **14,000** against an invoice total of
> **58,740** that had been raised at **11,000**. Neither number was wrong; they simply could not be
> reconciled by anyone reading the row — the same defect as control totals that will not add up to
> the ledger.
>
> `actuals()` shapes the invoice exactly like a plan, so the row builder cannot tell them apart and
> a column added to the plan later cannot quietly go on forecasting a period that has already been
> billed. A **cancelled** invoice is still ignored: that period genuinely does need billing.
> (`AnInvoicedPeriodReadsFromItsInvoiceTest`, proven by restoring the mixed row.)



> **⚠️ A stopped charge kept appearing in the forecast (fixed 2026-08-28).** `planInvoiceForLease()`
> reads `$lease->charges` and had **no `is_active` filter of its own** — it relied entirely on
> `generateForLease()` having narrowed the relation first with `loadMissing(is_active)`. And
> `loadMissing` means *"load it IF it is not loaded"*, so **whoever loads the relation first decides
> what the planner sees**. The forecast service loaded it unfiltered one call earlier, and the
> planner reused that collection: a charge ended through *End charge* went on being forecast for
> ever while the run correctly ignored it.
>
> **Both lines looked right on their own.** The planner's `loadMissing` is correct — it must not
> re-query for each of a thousand leases in a run — and a bare `loadMissing('charges')` reads as a
> harmless eager-load. Only the ORDER made them wrong, and neither file mentioned the other.
>
> Now asked **inside the plan**, where what-applies is decided, so it holds on every path instead of
> the paths someone remembered to prepare; the forecast's own load is narrowed too. The paired test
> asserts the forecast and the planner name the **same** charges — asserting the forecast alone
> would pass on a forecast that had drifted the other way.
> (`TheForecastShowsOnlyWhatWillBeBilledTest`, proven by removal.)



> **⚠️ A security-deposit invoice swallowed the month (fixed 2026-08-28).** The forecast keyed
> invoices by the MONTH their period starts in, and `keyBy` keeps the last — while a deposit invoice
> takes the LEASE'S OWN TERM as its period, so its `period_start` lands in the commencement month.
> Measured: a month forecasting 59,960 of rent and service charge showed 132,000 of deposit and
> nothing else, and read as **invoiced** while the rent had not been billed at all. Now keyed on the
> WHOLE period, both ends — exact for a monthly row, exact for a quarterly one, and no match at all
> for a document covering three years.
>
> **⚠️ A charge added into a billed period was silently never collected.** The run refuses to bill a
> month twice — correctly — so a back-dated charge sits in the schedule and no invoice ever raises
> it. Measured: a month billed at 44,000, a 14,000 service charge added into it, run answers
> *skipped*, 14,000 lost. **Not refused** — back-dating is a legitimate act and Yardi does not block
> it either — but the operator is now told at the moment they do it, with the covering invoice
> NAMED, because "that period is billed" is not actionable without knowing which document to look
> at. Silent on an open period, and a cancelled invoice does not count.
> (`ABilledPeriodSaysSoWhenYouAddAChargeTest`.)



> **⚠️ A manual invoice prefilled the whole escalation ladder (fixed 2026-08-28).** The form filtered
> the lease's charges on `is_active` and frequency, and on nothing about **when**. A lease carries
> one charge row per escalation step — 44,000 from 2026, 47,080 from 2027, 50,375.60 from 2028 — and
> every one is active and monthly, so picking the lease pulled **all** of them onto one document.
> Measured: a two-month invoice of **148,528.38**, three years of rent and marketing on one page,
> and the late-fee run then charged 2% of that figure.
>
> The billing engine has always billed **one amount per type per month**; the prefill now asks the
> same question through the same resolver (`ChargeScheduleService::rowInForce()`), so the form and
> the run cannot answer differently. The second test asks for a LATER period and expects the later
> step — asserting the first row only would pass on a prefill that simply took whichever came first.
> (`AManualInvoicePrefillsOneMonthTest`, proven by restoring the old filter.)



> **🔴 A billed security deposit silenced the rent for the WHOLE TERM (fixed 2026-08-28).**
> `alreadyBilledForMonth()` keeps `STANDALONE_ITEM_TYPES` — the one-off invoice types that must not
> suppress a month's recurring invoice — under a docblock stating the rule in writing: *"anything
> that raises its own invoice dated into a billed month belongs here, and belongs here in the same
> commit that starts raising it."*
>
> `security_deposit` was never added. It is the **fifth** instance of that class and the only one
> costing more than a month: `BillSecurityDepositService` dates its invoice to the **lease's own
> term** — commencement to expiry — so the overlap test matched **every month of the lease**.
> Measured on a three-year lease: the deposit was billed and then **no rent invoice was raised for
> any month of the term**, reported as an ordinary `skipped` and indistinguishable in the run
> summary from a lease billed correctly. Eight invoices appeared the moment the type was registered.
>
> Found while preparing a termination exercise and noticing the lease had nothing to terminate —
> not by any test, because every billing test bills a lease that has never had a deposit raised on
> it. (`ADepositInvoiceDoesNotSuppressTheRentTest`, whose second case bills four months across two
> years, since a single month would pass on a one-month bug.)


## 1. Purpose & business context

The Billing module automates the monthly invoicing lifecycle for Eltizam operators. Each Eltizam manages leases on behalf of Jawad property owners; invoices are issued to Eltizam's tenants (retailers) for rent, service charges, utilities, and other recurring fees. The system:
- Generates invoices idempotently from lease charges (avoiding duplicates within a period)
- Applies VAT (standard rate, 14% today — settings-driven, see §8) to the supplies the charge-code catalogue marks taxable (base rent is VAT-exempt per Egyptian law)
### How a PART month is priced — a lease term since EG-29 (2026-08-23)

`App\Support\ProrationMethod`, resolved on the same three tiers as every other lease term:
`leases.proration_method` → `PropertySettings('billing.proration_method')` → `BillingSettings`.

Proration was one hardcoded line — days ÷ that month's own length — which is one of the **four
methods Yardi Voyager ships**, and leases say different things:

| Method | A partial month is | 16 days of a 31-day August, rent 30,000 |
|---|---|---|
| `actual` **(default)** | days ÷ that month's length | 15,483.87 |
| `thirty_day` | days ÷ 30 — the "one thirtieth per day" clause | **16,000.00** |
| `year_365` | days × 12 ÷ 365 | 15,780.82 |
| `whole_month` | the whole month | 30,000.00 |

So a clause reading *"one thirtieth of the monthly rent per day"* was under-billed by 516.13 on that
one move-in, and wrong in the seven months that are not thirty days long — on every move-in,
move-out, rent commencement and final cycle.

**A FULL month is exactly one month under every method.** The divisor prices the STUB; without that
rule `thirty_day` bills 31/30 of a month every August and `year_365` bills 31 × 12 ÷ 365 — more than
a month's rent for a month occupied normally, which is not what any of these methods mean.

**`actual` is the default at every tier**, so nothing an install bills today moves.

**One method per invoice.** `planInvoiceForLease()` resolves it once and threads it through all five
`monthsCovered()` call sites — a site left on the parameter default would price one line of an
invoice by a different rule than the rest of it. `CreditUnearnedBillingService` reads the
**agreement's** method for the same reason: a termination credit computed on a different rule than
the invoice it credits disagrees with it by days, on exactly the mid-month move-out it exists for.
`prorationMethod()` is therefore on `BillableAgreement`, so a unit ownership answers it too — from
the property and portfolio tiers, since an ownership has no clause of its own.

### Whether a charge prorates AT ALL — `charges.prorate` (2026-08-23)

EG-29 above answers *how* a part month is priced. Yardi's lease charge row carries the prior
question too — *"charge code · amount · from date · to date · frequency · basis · **prorate flag**"*
([01-yardi-lease-administration.md](../benchmarks/yardi/01-yardi-lease-administration.md) §3.2) —
and until this column existed every monthly row prorated together. A mid-month move-in cut a flat
signage licence, a fixed parking fee and a fixed management fee by the same fraction it cut the
rent: measured, a 5,000 parking fee billed **2,580.65** on a 16 August commencement.

Those charges are not time-priced. A licence buys the right to trade under it for a month; taking it
from the 15th does not make it half a licence.

**On the CHARGE, not the lease**, for the reason `billing_timing` is: the case that matters is
MIXED. Rent prorates and the licence beside it does not, on one lease and one invoice — a per-lease
flag would force the operator to choose which of the two is wrong.

**Null is the normal state.** Nullable; null and `true` both mean *prorate, by the lease's method*.
Only an explicit `false` changes anything, so no figure moves on deploy. Tested `=== false` rather
than falsy — the trap `charges.vat_applicable` fell into (EG-01).

**It is not a fifth proration method.** `Charge::prorationMethodWithin()` resolves a non-prorating
row to `ProrationMethod::WHOLE_MONTH` — the EXISTING rule. That matters beyond tidiness:
`monthsCovered()` is the ONE definition of how much of a period an agreement runs, and
`CreditUnearnedBillingService` reads the same one back, so **a flat charge is not clawed back on a
mid-month move-out either**. A separate "bill it whole" branch in the billing service would have
been a second definition, and the credit note would have refunded half a month the charge says is
fully earned — measured at 17,500 where 15,000 was owed back.

A month the lease never reached still bills **nothing**: whether a part month is worth a whole month
is a different question from whether the lease ran in that month at all.

Reachable from the charge-schedule relation manager (a *"Bills whole months"* toggle, offered only
for a monthly row), from `ChargeImporter`, and read back as a badge on the schedule table.
`AFlatChargeIsPayableInFullForAnyMonthTest` pairs every assertion with a control on the same
invoice — a test that only asserted *"the licence billed 5,000"* would pass just as happily on an
install where nothing prorated at all.

> **A live crash found by writing that test.** EG-29's per-charge closure used `$proration` without
> capturing it in its `use (…)` list. An undefined variable reaching `monthsCovered()`'s
> non-nullable `string $method` is a **TypeError**, so every lease carrying an ARREARS charge
> fatalled the whole billing run — and the suite was green because every test of arrears billing
> called the arithmetic directly rather than the planner. Exactly the shape of the `use ($get)` bug
> that 500'd the invoice form for five days. The regression test drives `planInvoiceForLease()`.

**CAM does NOT use this, deliberately.** `CamReconciliationService` weights a tenure by *area ×
days ÷ period days* — square-metre-days, answering "what share of the mall's GLA did this tenant
occupy across the year". Numerator and denominator must share one day basis, so applying a lease's
30/360 clause there would break the apportionment rather than honour the clause.

- Supports proration at BOTH ends: mid-month commencement (per-run flag) and mid-month
  termination/expiry (unconditional), plus the automatic credit note when the month was already
  billed in advance
- Late-fee rate, minimum, grace, **cap and recurrence** resolve through **three** tiers — **lease → property →
  portfolio** (`Lease::lateFeeTerms()` → `App\Support\PropertySettings` → `BillingSettings`).
  The **cap** joined them on 2026-08-22 (EG-35, finding M-8): `late_fee_minimum` existed and its
  opposite did not, so *"2% per month, minimum EGP 50, capped at EGP 5,000"* was two thirds
  expressible. That asymmetry is the one that costs money — a percentage of an arrears has **no
  upper bound**, so a tenant six months behind on a large invoice drew a penalty proportional to the
  debt rather than to the breach. **0 = no cap at every tier**, which is what every install did
  before the column existed. It is applied **after** the minimum, deliberately: a ceiling the
  operator typed is a statement about the most they will charge, a floor only rounds small ones up,
  and `max()` last would bill above a cap the clause names. It must be returned from
  `lateFeeTerms()` and not only from `LateFeeService`'s no-lease fallback — `invoices.lease_id` is
  NOT NULL, so a cap defined only there is present in the code and inert in production.
- **A late fee can RECUR while the balance stands** (EG-35, 2026-08-22). `late_fee_recurrence_days`
  on the same three tiers, **0 = charge once**, which is what every install did before it existed.
  Measured from the last fee's ISSUE date, not the invoice's due date — the clause says "again every
  N days", and anchoring to the due date would fire a burst of back-dated fees the first time an old
  arrear is swept. A CANCELLED fee still does not count, so one raised in error is voided and
  re-charged immediately.

  `invoices.late_fee_for_invoice_id` is the fee's pointer back at what it penalises — the audit
  trail, and what makes *"which fees came from this invoice"* answerable once there is more than
  one. It sits alongside `late_fee_invoice_id` on the source, which still names the MOST RECENT fee
  and is the idempotency stamp; two directions, two questions, and the decision reads the trail.

  **`items()->where('type','late_fee')->exists()` is an ABSOLUTE bar and recurrence does not reach
  through it.** It does two jobs by coincidence: it bars an invoice charged under the old in-line
  behaviour, and — because a fee invoice's only line is itself of type `late_fee` — it is what stops
  a late fee earning a late fee. With recurrence on, that second job is the only thing between the
  operator and a penalty compounding on a penalty.
  The lease's negotiated term still wins; what CFG-03 added underneath it is the PROPERTY, because
  Eltizam runs several malls and a late fee is a per-building term. See
  [PROPERTY-ISOLATION.md](../PROPERTY-ISOLATION.md#per-property-configuration-cfg-03-2026-08-12)
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
| `charges` | `Charge` | `lease_id`, `name`, `type` (**string** — a `charge_codes` code, validated at the model, not a DB enum), `amount`, `currency` (EGP), `frequency` (enum: monthly, quarterly, annually, one_time), `vat_applicable` (**nullable** boolean — null means "ask the catalogue", §8), `vat_rate` (**nullable** — an override; null means the dated catalogue answers at billing, §8), `start_date`, `end_date`, `is_active` | Recurring billing items attached to a lease; defines what is billed and how often. A date-ranged SCHEDULE per type — `ChargeScheduleService` closes one row and opens the next, never edits in place. |
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
   - `Charge.vat_applicable` = null (the normal state — ask the catalogue) or an explicit `false`
   - `Charge.vat_rate` = null (the normal state) or a rate somebody deliberately chose
   - `InvoiceItem.vat_rate` comes from `Charge::resolvedVatRate($issueDate)`, which answers the
     override if there is one and the dated catalogue otherwise
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
   - **The same hole was still open on `balance`, `paid_amount` and `number` until 2026-08-12.** The
     2026-08-11 fix guarded the three header columns and short-circuited unless one of *them* was
     dirty — so a payload changing **`balance` alone** returned before the correction and persisted.
     The invoice then read settled in the portal, in AR aging (which filters `balance > 0`), in the
     overdue scan and on every collections screen, while the GL still carried the AR debit.
     `paid_amount` went the same way, and `number` — rendered `disabled()->dehydrated()` — was
     rewritable on an issued invoice, re-labelling a tax document the tenant holds.
     - `paid_amount` is now **discarded** if it arrives dirty: `recomputeTotals()` is its single
       source of truth and persists via `saveQuietly()`, so the legitimate write never reaches the
       hook and anything that does is a payload *by construction*. Reverted rather than refused,
       because the form submits the field on every save and throwing would break ordinary edits.
     - `balance` is now **always** re-derived when either group is dirty.
     - `number` joined the finalized-invoice immutability list (with `issue_date`, `tenant_id`,
       `lease_id`) — a refusal, not a revert, because a renumbered tax invoice is not a slip.
     - Registry + gate: **`App\Support\DerivedMoney`** and `DerivedMoneyConformanceTest`, which
       classifies every model carrying a fillable money column and proves each DERIVED one by
       tampering with a committed record. It deliberately does **not** grep the forms: a
       `readOnly()->dehydrated()` looks locked and submits anyway, and any check over that pattern
       is one refactor away from useless. See `DerivedMoneyColumnsNotClientWritableTest`.
   - **WHICH supplies are taxable is DATA, on the charge code** — `charge_codes.tax_code` names a
     row in the tax catalogue, resolved by the one function every origination point calls,
     `Vat::rateForType($code, $on)`. An accountant rules on a supply by editing a row; adding "key
     money" no longer means a developer decides whether it is taxed. This is the shape Yardi uses (a
     `Tax` flag on the charge code — *"Yes means 'this charge is taxable'; it does not mean 'this
     charge is a tax'"*).
   - **WHAT that tax charges is master data** — `tax_codes` + `tax_rates` (module: the catalogue at
     `/admin/tax-codes`). The charge code carried its own `vat_treatment` + `vat_rate_override` until
     2026-08-12; that stored the *answer* rather than a reference to the thing holding it, so twelve
     charge codes each carried a copy of "14" and none of them could say **when** the rate changed.
     A rate is now a dated rung, resolved for the **document's** date. `TaxSettings::vat_standard_rate`
     is gone with it: settings hold policy, master data holds rates.
   - **A RECURRING charge resolves its rate at BILLING, not when the schedule row was written**
     (2026-08-12). `charges.vat_rate` is an OVERRIDE and **null is the normal state**;
     `Charge::resolvedVatRate($on)` is the one place that answers what a charge bills on a date, and
     `MonthlyBillingService` asks it for the invoice's own `issue_date`.

     Until then every creation path stamped the column from `Vat::rateForType()` and billing read
     that number for the life of the lease — so the catalogue's headline promise (*a rise entered in
     advance applies by itself on the day*) held for late fees, fines, meter recharges, CAM
     recoveries and percentage rent, and **did not hold for rent and service charge**, which is the
     bulk of the money. Amending the lease did not help: the amendment carried the old rate onto the
     new row. Measured, not argued — with a rise to 20% effective 1 September, the resolver answered
     20 for a September document while the September invoice billed 14, quietly under-collecting
     output VAT the operator still owes ETA.

     Yardi is the standard being followed: the charge record holds the amount, the rate comes from a
     tax table resolved at billing. A value in the column now means somebody deliberately departed
     from the catalogue — a deal that fixed a rate — and the schedule table marks that row ⚠.
     Pinned by `VatRiseReachesRecurringRentTest`.
   - **`vat_applicable` is an override on the same terms — and it was the half nobody fixed**
     (EG-01, 2026-08-22). The 2026-08-12 change was applied to `seedStandardCharges()`'s
     SERVICE-CHARGE block and not to the BASE-RENT block two lines above it, and it never touched
     this column at all: `boolean default(true)`, NOT NULL, written by thirteen services from
     `Vat::rateForType($type) > 0`.

     That is the worse half, because `resolvedVatRate()` tests it FIRST and returns before the
     catalogue is consulted. A `base_rent` row is born `false` — rent is in `Vat::EXEMPT_TYPES` —
     and can never become taxable again whatever the accountant rules. Measured: with the charge
     code pointed at `VAT_14`, `Vat::rateForType('base_rent')` answered **14.0**, the charge
     resolved **0.0**, and the billing run raised a rent line with **0.00 VAT** where 14,000 was
     due. It also discarded the operator's own override — a row with a deliberately typed 8% still
     resolved 0.0, because the short-circuit runs before the rate is read.

     It matters now, not hypothetically: Law 157/2025 pulled property rental into the tax net, so
     *"point base rent at VAT_14"* is the change this operator expects to make — and it would have
     appeared to work while every existing lease went on billing rent untaxed.

     So the column is **nullable, null is the normal state**, every row backfilled to null, and the
     test is `=== false` rather than falsy. **No screen ever offered it as a tick** — all three
     UI/import sites derived it — so there was no operator statement to preserve; the operator's real
     channel is the RATE they type, and `vat_rate = 0` still holds a supply untaxed. An explicit
     `false` keeps its meaning and still wins; it simply stopped being written by services that were
     quoting the catalogue back to itself. Pinned by `TaxabilityIsNotFrozenOntoAChargeRowTest`.

     One trap worth naming: `TransferUnitOwnershipService` copied the flag with a `(bool)` cast,
     which turns null into **false** — a resale would have re-frozen "ask the catalogue" into
     "permanently exempt", reintroducing the bug one unit at a time.
   - **The standing wording on the invoice is the OPERATOR's** (EG-15 slice 1, 2026-08-22).
     `App\Support\DocumentText::for($key, $assetId, $tokens)` resolves *this property's row → the
     house row → the translation key the document always used*. **Twelve blocks** today, registered
     in `DocumentText::KEYS`: the PDF's `invoice.footer`, `invoice.payment_instructions` and
     `invoice.terms` (slice 1); the invoice EMAIL's `invoice.email_body`; and a body **and subject**
     each for the overdue reminder, the late-fee notice, the payment receipt and the lease-expiry
     notice (slice 2, 2026-08-23).

     **Mail is plain text too, and that is a decision rather than an omission.** Slice 1 argued
     against a `RichEditor` because operator-authored HTML flowing into mpdf is a poor trade; the
     same holds for email, where it is an injection surface rather than a rendering one. Bodies are
     `nl2br(e(...))` — `e()` INSIDE, or nl2br's own `<br>` gets escaped — and subjects go through
     `DocumentText::forSubject()`, which collapses whitespace so a newline an operator types cannot
     reach a mail header.

     **`TenantFacingWordingIsTheOperatorsConformanceTest` keeps it honest.** It discovers
     notifications from disk, follows `->markdown()`/`->view()` into the blade (a notification that
     renders a view keeps its wording there, which the first cut of the gate missed), and requires
     every tenant-facing mail notice to be templated or exempt with a stated reason. It also asserts
     each key has a floor and a bilingual PICKER LABEL — which is how five blocks that were
     registered, resolvable and impossible to choose in the dropdown were caught.

     Every word on an invoice was a lang key, so changing the footer was a deploy — and two things
     made that worse than the usual complaint. The footer **names payment rails**: *"Payment due
     within :days days of issue · Bank transfer / Card / InstaPay"*, three of them hardcoded on the
     one document every tenant reads monthly, while EG-11 made rails a catalogue the operator adds
     to and retires. And **no invoice showed bank details at all**, so a tenant holding one could
     not know where to pay; there was nowhere to put it.

     **The floor is what makes it safe to deploy** — an install with no rows renders exactly what it
     rendered yesterday, and the two new blocks render nothing at all until written, rather than a
     heading over a gap. **Null `asset_id` is the house default** and every mall sees it
     (`#[PropertyOwned(portfolioRowsWhenNull: true)]`); a row naming a mall overrides it there only,
     which is what bank details need. **Plain text, not a rich editor** — a deviation from EG-15 as
     written, argued in the migration: these blocks are set in the document's own typography, and
     the rich editor belongs with the dunning/message slice where wording is the whole artefact.
     `{days}` is the only token, an unknown one is **printed rather than blanked**, and the body is
     escaped with line breaks preserved. Pinned by `DocumentWordingIsTheOperatorsTest`.
   - **Exempt ≠ zero-rated** — both bill 0 and they are different lines on a VAT return, so the
     treatment is stored on the tax code rather than inferred from a zero on a line, where it could
     never be recovered.
   - **The LINE records which tax it carried** — `invoice_items.tax_code` (2026-08-12). Until then a
     line stored `vat_rate` and nothing else, which is one fact short in two places: `VatReturnService`
     split the taxable base on `vat_rate > 0`, so zero-rated and out-of-scope landed in the same
     bucket; and a hand-typed rate was indistinguishable from the catalogue's. Defaulted at the
     **model layer** (`InvoiceItem::booted`) from the line's charge code, because eight services raise
     lines and setting it in each is the shape that produces the "half a catalogue" bug. It is a
     classification carried alongside the rate, **never a pointer the totals re-derive through** — the
     line keeps whatever rate it was given, which is often a frozen figure (a charge schedule's stored
     rate, a CAM pool's `recovery_vat_rate`).
   - **The rate is picked, not typed.** The line's rate box was a free 0–100 input until 2026-08-12,
     with a comment saying so — `Vat::rateForType()` governed the default and nothing governed the
     value. It is now read-only unless the operator holds **`tax_codes.override`**, and the real gate
     is server-side: `App\Support\CatalogueTaxRate::enforce()`, called from the items repeater's
     `mutateRelationshipDataBeforeCreateUsing`/`…BeforeSaveUsing`. **That layer is load-bearing** —
     the repeater is relationship-backed, so the rows never reach the page's
     `mutateFormDataBeforeCreate`, and an enforcement written there reads correctly and protects
     nothing (it was written there first, and `InvoiceLineTaxCodeTest` caught it). An override
     records `tax_override_reason`; its presence IS the flag. Withheld from `manager` deliberately —
     departing from the catalogue is an accounting act, the same reasoning as `approvals.tier_3`.
   - **The labels say "Tax", not "VAT"** — the code beside the rate can be stamp duty or schedule
     tax. The stored columns stay `vat_rate`/`vat_amount`: renaming committed money columns on posted
     documents is roadmap TX-07's explicit no.
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

**The guard asked the wrong copy of `type` (SW-017, 2026-09-03).** `$deposit->type` is the value being
SAVED, so flipping a drawn-on receipt to `refund` or `forfeit` made the condition false and walked
straight past a freeze whose own dirty list names `type`. It is the worst way through it: the row
stops being a receipt AND takes its money out of the pot in one edit. Asked of
`getOriginal('type')` **or** the new value now, so neither direction can slip through on the value
the other side reads.

*Isolating that in a test took a second receipt on the pot.* With only the drawn-on one, the flip
drives the pot negative and the over-refund CAP refuses first — so the case passes without the freeze
existing at all, which is exactly what the mutation run showed. A 50,000 receipt beside it keeps the
pot positive after the flip, and then only the freeze can refuse.

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

12. **Due date never lands in the past:** `due_date = max(issue_date, today) + lease.paymentTermsDays()`. The column is NOT NULL, so the lease always states its own terms; the property/portfolio default applies at lease **origination**, not here — see gotcha 6.
    - `issue_date` stays at the period start (or the commencement, when prorated) — it is the GL `entry_date` and the `YYYYMM` segment of the invoice number, so it is *not* moved by a late run.
    - The due date instead anchors to when the tenant can actually receive the bill: the later of `issue_date` and today. For an on-time run (the invoice's period is the current month) this equals `issue_date + terms` as before; only a **late / back-filled / off-the-1st** run (a mid-month "Generate Invoice", or `monthly_billing_day > 1`) differs — and there the fix is what stops the invoice being *born overdue* (which would otherwise trip the overdue-scan + a same-day late fee).
    - **Tests:** `BillingScenarioTest::test_derives_the_due_date_as_period_start__payment_terms_days` (on-time) · `InvoiceDueDateNotBornOverdueTest` (late run not born overdue)

### Constraints (database)

11. Invoice.number is UNIQUE
12. Invoice.lease_id is RESTRICTED on delete (soft-deleted invoices retain their lease reference)
13. Invoice.tenant_id is RESTRICTED on delete
14. InvoiceItem.invoice_id is CASCADE on delete (items are purged with invoice)
15. Charge.lease_id is CASCADE on delete (charges are purged with lease)

### The debtor is derived on the FORM — and deliberately not an invariant of the model

The invoice form shows `tenant_id` read-only beside the lease picker, and `CreateInvoice` re-derives
it from the lease on save. A disabled field's value still arrives in the Livewire payload, so
trusting it would let a crafted request bill a party who never agreed to the charge — and until
2026-08-17 a free tenant picker made that two clicks in the UI, with nothing refusing it. Yardi puts
the debtor on the invoice header for the same reason: it is the one fact on the document nobody
should have to infer, so it is shown rather than removed.

> **Do not promote this to a model-level equality rule.** It was tried on 2026-08-17
> (`Invoice::assertTenantMatchesAgreement()`) and the full suite refused it within one run, on two
> deliberate behaviours:
>
> - **`IssueInvoiceService::issue()` takes an explicit `$tenantId`.** A violation fine, a
>   bounced-cheque fee and a late fee carry the debtor stated on their **source document** rather
>   than inferred from the lease — documented in that service, asserted by its own test.
> - **A draft invoice may be freely re-homed** to another lease before it is issued; a draft is a
>   scratch document and the immutability guard is finalized-only.
>
> So "`invoices.tenant_id` equals the agreement's party" is NOT a rule this system holds. It holds
> on the admin form, which never states a debtor, and that is where it is enforced.

### An online receipt names the bank it landed in (SW-228, 2026-09-02)

`RecordsBankAccount` fills a money document's `bank_account_id` from
`asset_id ?? bill?->asset_id ?? TenantScope::currentAssetId()`. The first two are facts the ROW
carries; the third is the mall the operator happens to be looking at — and **`payments` has no
`asset_id` column at all**, deliberately, because a receipt's books dimension comes from the invoices
it settles and those do not exist yet at `creating`.

So off the panel there is nobody to ask, and the receipt named no account. `MoneyAccount::for()` then
falls to the generic `bank` POSTING ROLE, which is where money **nobody attributed** lands — and
`MatchBankStatementLineService::candidatesFor()` finds reconciliation candidates BY the chart
account, so a named bank's own postings were offered alongside every unattributed one. That is
precisely the state the bank register was built to end.

**It was not an edge.** `PaymobPaymentInitiator` and `RecordDemoPaymentAction` are the whole online
CARD channel — the highest volume of inbound receipts on a live install, and the money that lands in
a merchant settlement account — and `PaymentMethod::requiresBankAccount('card')` falls through to
`code !== 'cash'`, so `card` is exactly a rail that is supposed to name one. Both now resolve
`BankAccount::defaultFor($invoice->asset_id, …)`: the invoice knows the mall, so this is a
derivation, not a guess. With no bank account registered it stays null rather than inventing one —
the rule the concern already states for its own null case.

**The gate built for this invariant could not see any of it**, and that is the more useful half.
`MoneyDocumentDoors::doors()` derives a door from a Filament SCHEMA, so an API action, a gateway
callback, a console command or a queue worker is not a door it can look at.
`documentsThatCannotSelfDefault()` + `offPanelCreators()` close that: the first is DERIVED (a
rail-carrying document with no `asset_id` column and no `bill` — today the receipt alone, and a
document that grows one drops out by having it), the second scans every file under `app/` outside
`app/Filament` for a `Model::create([` and reads the array it builds.

**What the review of that gate found, because every one of them is a shape that recurs here:**

- It **bypassed the operator's own tick.** `RecordsBankAccount` asks
  `PaymentMethod::requiresBankAccount($rail)` before defaulting, and `requires_bank_account` is a
  settable column: unticking it for `card` stops the panel asking *and* stops it defaulting, while a
  call site calling `defaultFor()` directly would have gone on stamping one. That is door-versus-
  service drift arriving through a door the gate cannot see, so both creators now go through
  `RecordsBankAccount::defaultBankAccountIdFor()` — the same method the model default uses.
- Its array slicer **failed OPEN**: it counted brackets over raw characters, so a `[` inside a STRING
  in the array (`'cheque [ref missing'`, and `PostDatedChequeService` really does interpolate free
  text into one) never closed, the slice ran to end of file, and a `'bank_account_id'` from an
  unrelated method further down made the creator look compliant. It counts TOKENS now, where a
  bracket inside a string is part of the string.
- Its discriminator asked the wrong question. `method_exists($model, 'bill')` excluded
  `VendorBillPayment` on the grounds that it can reach a property through its bill — but
  `vendor_bills.asset_id` is NULLABLE, so that route can arrive at nothing, and
  `VendorBillService::recordPayment()` takes `?int $bankAccountId = null` as a default parameter. The
  set is the two documents with no `asset_id` column, full stop.
- It matched `VendorBillPayment::create([` for `Payment` — the substring trap that file's own
  `names()` docblock already warns about — and then matched **the sentence in its own source
  explaining that trap**, so it reads the source with comments blanked.
- Keyed by FILE alone, one document's verdict leaked into the next: a seeder that builds several
  money documents reported a compliant payroll as missing because a different document in it was.

**And there are THREE honest answers, not one.** Name the account; or name the PROPERTY, for a
document that carries `asset_id`, which the model then defaults from (`SettleMoveOutService`); or
name a RAIL that needs no account at all — `requiresBankAccount('cash')` is false, the same question
the model asks, so the gate and the model cannot disagree about a cash receipt. A gate that demanded
only the first would be satisfied by pasting a key everywhere. Twelve off-panel creators across five
documents; the sweep covers `database/seeders` too, since a seeder is off-panel in exactly the way
that produced this finding.

### A void cannot leave a bad debt standing (SW-023, 2026-09-02)

A write-off is an accounting ACT, not a status: `WriteOffInvoiceService` posts
`Dr bad_debt_expense / Cr accounts_receivable` against an `InvoiceWriteOff` row, and it deliberately
leaves `invoices.balance` alone — the balance is derived from the four settlement channels and a
write-off is not one of them.

`VoidInvoiceService` knew nothing about that row. Measured on a 10,000 invoice with 4,000 written
off, the books after the void read **AR −4,000** — the invoice's own debit reversed, with the
write-off's credit standing against nothing — and **4,000 of bad-debt expense against a document that
no longer exists**. Negative receivables for one debt, and a loss recognised on money that was never
owed.

*(Counted over `JournalEntry::REPORTABLE_STATUSES` — `posted` **plus** `void` — which is what every
financial read uses. The first measurement summed `posted` alone, read the reversal without the entry
it reverses, and reported −14,000; the tell that the convention was wrong rather than the books is
that the same sum showed a **debit balance on a revenue account**.)*

**Refused, not cascaded.** That is this codebase's rule for money records: correct them through their
own workflow, so an auditor can follow what happened. *Reverse write-off* is a real button, and
reversing first leaves a trail saying the debt was re-opened and then the document withdrawn — which
is what actually happened. Cascading would silently undo an act somebody took deliberately.

It is the same shape as the refusal one line above it in the service: an invoice carrying captured
CASH refuses too, and the remedy there is to refund the payment first.

**And the service guard alone was decoration on the most common cancel in the system.**
`LeaseTerminationService` cancels open invoices with a direct
`update(['status' => 'cancelled', 'balance' => 0])` that never goes near `VoidInvoiceService`, and
its filter — `status in (draft, issued, partially_paid, overdue) AND balance > 0 AND
paid_amount = 0` — matches a partially written-off invoice on **every** clause, precisely because a
write-off leaves `balance` standing and is not a settlement channel. The `cancel_open_invoices` tick
defaults to on. Two layers close it, and the split matters:

- the invoice is **excluded at the selection**, the way that query already excludes an ETA-filed one
  — the loop has no per-row catch, so a refusal there would abort the whole termination and leave the
  lease un-terminatable;
- and `Invoice::updating` refuses a cancel carrying a write-off on **every** path, beside the
  captured-cash guard whose own comment already says it must hold *"on EVERY path, not just
  VoidInvoiceService, and in `updating` so the write is refused rather than merely reported"*.
  That guard was the precedent; this one belongs next to it. Gated in **both** layers — the
service refuses and the header action hides — with the operator's route out (*Reverse write-off*)
visible precisely while the void is not. A FULLY written-off invoice never reaches the check:
`written_off` is already terminal, so this bites only on the partial case, which is the one that
moves money.

## 4. Lifecycle / state machine

| Status | Transition trigger | Next state(s) | Terminal? | Mutable via UI? |
|--------|-------------------|---------------|-----------|-----------------|
| `draft` | Manual creation in Filament | `issued` (Status select) · `cancelled` (Void action) | No | Yes — the Status select is editable while the record is a draft, and it is the only door out |
| `issued` | Invoice created, or due_date is future | `partially_paid` (payment > 0), `overdue` (due_date past), `paid` (balance ≤ 0) | No | Yes (can set manually) |
| `partially_paid` | 0 < paid_amount < total | `paid` (more payments), `overdue` (due_date past) | No | Yes (manual override) |
| `paid` | paid_amount ≥ total | ✓ (stable) | Yes (unless manually adjusted) | Yes (manual only) |
| `overdue` | due_date past AND balance > 0 | `partially_paid`, `paid`, or stays `overdue` | No | Yes (manual override) |
| `disputed` | Manual override | Any (manual resolution) | No (pending investigation) | Yes (manual) |
| `cancelled` | Manual override | ✓ (irreversible in practice) | Yes | Yes (manual) |
| `credited` | Manual override (typically after credit note) | ✓ (irreversible) | Yes | Yes (manual) |

**Automatic transitions:** Only issued/partially_paid/overdue → newer status via `recomputeTotals()` after payment/credit changes. Draft/cancelled/disputed/credited/written_off are preserved and never auto-overwritten.

**`draft` joined that list on 2026-09-02 (SW-215), and it was promoting the only case that matters.**
`InvoiceItem::saved` calls `Invoice::syncTotalsFromItems()` → `recomputeTotals()`, so writing a LINE
onto a draft ISSUED it. Measured through the real create page: the operator picks **Draft**, the
invoice is stored **`issued`**. A draft with no lines is not a document anybody wants — a draft is
precisely an invoice *with* lines that has not been raised yet — so the promotion fired every time.

What it cost: an unissued document went straight in front of the tenant (the subject of the
draft-visibility invariant), onto the books and into the GL, and `InvoiceForm` drops `draft` from its
options once the status has moved, so there was no way back. It was known and written down — the
reason `InvoiceSettlement` gives for refusing cash against a draft says in writing that *"an unissued
document becomes a live one without ever passing through `IssueInvoiceService`"* — recorded as a
hazard to route around rather than as a thing to fix.

Only the STATUS is frozen: `paid_amount` and `balance` still recompute, so a draft carrying a
settlement still reports the right figures, and the derived ladder still moves an ISSUED invoice to
`overdue` on its own. Issuing stays an ACT — `IssueInvoiceService` states the status at create and
the panel's Select is the other door — never a side effect of saving a line.
(`ADraftInvoiceStaysADraftTest`.)

**Two things the freeze then exposed, both of which had guards that did not cover them.**

- **A draft issued into a period that has since CLOSED committed with nothing in the ledger.**
  `SealedPeriod::guard()` looked up the document's posted entry and returned when there was none —
  and a draft has none, because `InvoiceJournalizer` returns null for one — so it skipped exactly the
  document that was about to gain an entry. `GuardsPostingDate` cannot see it either: that guard is
  `isDirty($column)`-only by design, and issuing a draft moves no date. The save committed,
  `SyncDocumentToLedger` refused at `assertOpenPeriodFor()` and only logged, and the result was AR on
  the document with nothing in the GL — `billing:reconcile --deep` permanently red, `books_tie_out`
  red, `atriom:preflight` blocking the next deploy. `guard()` now asks the poster when a document has
  no entry AND its `status` is dirty: a document gains an entry when it becomes postable, and
  `status` is the column every journalizer's own early return reads.
- **An abandoned draft had no way out, and suppressed its lease-month's billing for ever.**
  `VoidInvoiceService` refused a draft with *"A draft invoice is deleted, not voided"* — naming a
  door that does not exist, since `Invoice` is `#[NeverDeletable]`, the resource has no
  `DeleteAction` and the bulk one is hidden panel-wide — while the form removes `cancelled` from its
  options. Meanwhile `MonthlyBillingService`'s already-billed probe counted the draft, so the run
  reported `skipped: already_billed` for that lease-month for ever, indistinguishable from a lease
  billed correctly: the silent lost revenue that probe's own comment describes for the cancelled
  case. A draft is now **cancelled** by the same action (nothing was posted, so there is no reversal
  and no number burnt — the button relabels itself *Cancel draft*), and `draft` joins `cancelled` in
  the probe, because a draft was never billed.

**Overdue flag:** An invoice is "overdue" if status is overdue OR (status is issued/partially_paid AND due_date < today). Method: `Invoice::isOverdue()`.

## 5. Services, jobs & scheduled commands

### IssueInvoiceService — the one seam an AR document is born through

**File:** `/app/Services/IssueInvoiceService.php`

Every service that raises an invoice goes through `issue()`. It derives `subtotal` / `vat_amount` /
`total` from the lines it is given and seeds `paid_amount = 0`, `balance = total`.

It takes an **`App\Contracts\BillableAgreement`**, not a `Lease` — a `Lease` today, a unit ownership in
[plan 08](37-unit-owners.md) phase 2. The service never asks which: the agreement stamps its own
foreign key via `invoiceLinkAttributes()`, and supplies the party, the currency and the payment terms.

**Why it exists.** Eight services hand-built the identical header — `MonthlyBilling`,
`BillMeterReading`, `BillViolationFine`, `LateFee`, `PercentageRentCalculation`,
`CamReconciliation` (×2) and `BillBouncedChequeFee`. Each re-derived the totals from lines it was
about to write anyway, and each hand-seeded the two fields `Invoice::recomputeTotals()` owns. That
is eight chances to seed the AR invariant wrong and eight edits whenever the header changes.

**What it deliberately does not do.** It does not re-implement the header-follows-items rule:
`InvoiceItem::saved` already calls `Invoice::syncTotalsFromItems()`, so the header is re-derived the
moment the lines land. The totals are still computed on the CREATE rather than left to that hook,
because `LedgerRealtimeSync` dispatches on `saved` and an invoice born at zero is one that was
momentarily wrong on the books.

**Three callers pass an override rather than taking the lease's value**, and each has a reason:

| Override | Who | Why |
|---|---|---|
| `tenantId` | violation fine · bounced cheque · late fee | The debtor is stated on the source document, not inferred from the lease it was matched to |
| `currency` | late fee | A penalty is denominated in the currency of the debt it penalises |
| `dueDate` | monthly run | It anchors the due date to the later of the issue date and today, so a back-filled run is not born overdue |

`IssueInvoiceServiceTest` pins the contract **and** sweeps `app/` to prove nothing else hand-builds
an invoice — extracting the seam is worth little if the ninth caller writes its own
`Invoice::create([...])`, which is exactly how the eight accumulated. Filament's create page is not
a hand-built header (form + relationship repeater, corrected by the item hook) and is out of that
sweep by construction.

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
  - **Revenue-at-issue (known):** a cycle spanning a year boundary (e.g. quarterly Nov–Jan) recognises the whole cycle's revenue at issue (Nov). This is the system's documented accrual policy — revenue-at-issue, **no** straight-line spread (see `../STATUS.md A3.2`); the same limitation applies to any advance billing.
  - **Frequency is edit-locked after the first invoice** — cycles are anchored to the commencement, so switching cadence mid-term could strand an unaligned month. The form disables the field once the lease has any invoice; set it at signing.
- **A charge can bill BEHIND the period it covers (EG-30 / M-2, 2026-08-22).** `charges.billing_timing`
  is nullable and **null means advance**, so every charge written before this bills exactly as it did.
  Set to `arrears`, the row covers the PREVIOUS cycle: the September invoice carries August's service
  charge, on a line reading *"Service Charge - August 2026 (in arrears)"*.
  - **Per CHARGE, not per lease**, because the case that matters is mixed — rent ahead, service
    charge behind, one lease. A per-lease flag would force the operator to choose which of the two
    is wrong.
  - **One invoice, not two.** `MonthlyBillingService::coveredWindow()` is the single answer to
    "what does this line cover"; the arrears lines ride the same monthly invoice. A second invoice
    per lease per month was rejected on evidence: `alreadyBilledForMonth()` has silently suppressed
    a lease's base rent **five** times over a second invoice dated into a billed month (percentage
    rent, CAM, utility recharge, violation fine, NSF fee, late fee) and every one was a ONE-OFF —
    a recurring one would fire monthly for every arrears lease.
  - **Stated cost:** the invoice header's `period_start`/`period_end` no longer bounds every line.
    It already did not — late fees, utility recharges and violation fines all ride on invoices
    covering a different window — so the line's own description is what a tenant reads, and that is
    where the covered month is written.
  - **The timing travels with the charge row.** `ChargeScheduleService::setAmount()` inherits it
    onto a successor rung alongside `frequency`/`vat_applicable`/`vat_rate`, and
    `LeaseRenewalService` / `TransferUnitOwnershipService` carry it when they copy a schedule.
    Dropping it on any of those silently reverts an arrears charge to advance and bills the
    crossover month twice — the schedule looking entirely ordinary throughout.
  - **The description says "(in arrears)" as a LITERAL, not a translation.**
    `invoice_items.description` is stored prose and everything already in it is English (the
    `% pro-rated` suffix, the `format('F Y')` month), so translating one clause would freeze the
    billing run's locale into the row — an Arabic-locale queue worker storing an Arabic word beside
    an English month. Localising stored invoice descriptions is real work with a known shape here
    (store the data, resolve the words at render time, as `ActivityVocabulary` does) and is not part
    of this.
  - **An arrears line is never clawed back on termination.** `CreditUnearnedBillingService` prorates
    every time-apportioned line by the invoice's unearned ratio; an arrears line covers a period
    that has already run in full, so a lease ending 15 September would have had half of AUGUST's
    service charge refunded. It is excluded for the same reason a one-off is — earned in full for
    something that already happened.
  - **`alreadyBilledForMonth()` asks whether EVERY line is a one-off, not whether any is.** An
    arrears `utility` charge puts a standalone type on the RECURRING invoice for the first time, and
    the old `whereDoesntHave` reading made the run ignore the invoice it had just raised and bill
    the month twice. `STANDALONE_ITEM_TYPES` names the list once.
  - **Fit-out grace is measured on the covered window too.** The inline grace multiplier is derived
    from the invoice's own period — right for an advance row, wrong for an arrears one, whose
    rent-free month is the one behind it. A tenant whose rent commenced 15 August had August's
    service charge abated in August and would have been billed it in full on the September invoice:
    the abatement given and taken back a month later. `graceMultiplierFor()` answers for any window.
  - **The unit-owner صيانة run reads the same column** (`BillUnitOwnershipsService`), through the
    same `coveredWindow()`. It is the clearest arrears case in the product — an owner is billed
    after the period the common area was actually maintained — and it ignored the flag entirely at
    first, which would have made one column mean two different things in two runs. Its arrears rows
    also prorate against the TENURE held in the covered month, so a handover on 20 February owes
    9/28 of February on the March assessment.
  - **The back-shift uses the lease's FULL cycle, never the truncated final one.** The final-cycle
    block caps `period_end` at the expiry month and re-assigns `$cycleMonths` to the shortened
    length; shifting an arrears window back by THAT drops the months between. An annual lease
    expiring 15 March truncates to three months, so a three-month shift covers Oct–Dec and the whole
    of January–September is billed by nothing — 108,000 EGP on a 12,000/month service charge,
    behind a final invoice whose figures all look plausible. `$fullCycleMonths` is captured before
    the truncation.
  - **`billing_timing` is importable and visible.** A column on the schedule table (toggled hidden,
    blank = advance) and an `ImportColumn` on `ChargeImporter`, because settable-in-the-UI-only is
    the gap a migrating operator falls into — they arrive with a spreadsheet of charges, half billed
    in arrears by their previous system, and can express none of it.
  - **Known limitation: a TERMINATED lease loses its final month's arrears.**
    `LeaseTerminationService` writes `expiry_date = terminationDate`, so `$isFinalCycle` is
    satisfied — but the lease then goes `status = 'terminated'` and `Lease::scopeBillableForPeriod()`
    selects only `active`. Unless the final invoice happens to be raised in the same period as the
    termination, that month's arrears is billed by nothing. Whether termination should raise a final
    arrears settlement is a decision, not an oversight, so it is recorded rather than half-built.
  - **An arrears row prorates against the month it COVERS**, not the month the invoice is dated to:
    a lease commencing 15 August owes half of August's service charge on the September invoice.
  - **Nothing on a lease's first invoice**, because the month it would cover predates the lease. It
    bills normally the following month; deferred, not lost.
  - **The LAST invoice settles the arrears window AND its own month.** Without this the final month
    of every arrears charge was never billed at all: the row is billed one invoice late by design,
    so the last month would need an invoice dated after the lease ended, and
    `Lease::scopeBillableForPeriod()` requires `expiry_date >= period_start` — a lease expiring
    31 August is not selected for the September run, and `leases:expire` has moved its status off
    `active` by then anyway. Silent revenue loss on every arrears lease, with nothing in the run
    summary to say so. The final line covers both months and is labelled as the span
    (*"Service Charge - Jul–Aug 2026 (in arrears)"*), which is what an operator does by hand when a
    tenant leaves. Fixed inside the planner rather than by widening the lease-selection scope, which
    four other callers share.
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

**The reason vocabulary** — eight codes, and the caller must not word them itself. `run_in_progress`,
`lease_not_billable`, `already_billed`, `exception` come from this method; `fit_out`, `off_cycle`,
`no_applicable_charges`, `lease_ended` come from the plan it delegates to. Turn one into words with
`App\Support\BillingRefusal::explain($lease, $period, $result)`, which answers `title` / `body` /
`danger` in the reader's language — never `__('admin.billing_preview.reason.'.$reason)`, which is the
short badge vocabulary a preview table cell uses and does not cover the four this method adds.
`BillingRefusalVocabularyConformanceTest` derives the codes from this file and fails on one with no
wording in either language, and on a screen that calls `generateForLease()` without the presenter.

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

**Global rate: BUILT 2026-07-30 as a setting, MOVED 2026-08-12 to a dated ladder.** The rate is a
rung on the `VAT_14` tax code, edited at **/admin/tax-codes → VAT 14% → Rate ladder**, and read
**only** through `App\Support\Vat`. A change is a NEW rung with the day it comes into force — never
an edit to the old one — so a rise can be entered in advance and a document dated before it still
bills the rate that was in force when it was raised:

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
invoice issued at 14% stays a 14% document forever. Changing a rate affects what is billed
**next**, never what was already billed — otherwise a rate change would silently rewrite history and
de-tie the books from returns already filed.

**Per-supply rates** need no change to the standard rate: point the charge code at a different tax
code (`charge_codes.tax_code` — the operator's catalogue carries schedule tax at seven rates for
exactly this), `charges.vat_rate` is per-charge, and a CAM pool's `recovery_vat_rate` is frozen with
its basis at reconciliation.

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

> **The required particulars of a TAX INVOICE (fixed 2026-08-12).** The document is titled "Tax
> Invoice" and, until this date, printed the property's name, address and city and nothing else —
> **no seller tax registration number anywhere**, because the field did not exist in the data model.
> A tenant cannot support an input-VAT deduction from such a document, so every invoice Atriom had
> ever issued was unusable for the one purpose its title claims.
>
> - **Seller identity is operator-level** — `TaxSettings::seller_tax_registration_number` and
>   `seller_legal_name`, at Settings → Tax. **The billing-enquiries address is the third particular**
>   — `TaxSettings::seller_billing_email`, resolved through the same `IssuingEntity` and omitted
>   from the footer when blank. Until 2026-08-21 all three documents printed a contact built in the
>   Blade out of the property's name against the reserved `.test` TLD, so a tenant querying an
>   invoice wrote to nobody and the operator never learned they had asked (EG-05).
>   Not on `Asset`: Eltizam is one registered entity
>   operating several malls, so the seller is the operator and the building is the trading address.
>   If a second legal entity ever issues its own invoices this becomes a per-asset *override*, never
>   a second copy of the field.
> - **Blank by default, and the line is omitted when blank.** A placeholder TRN is worse than a
>   missing one: it looks valid, the tenant files it, and it fails on audit. A go-live gate item
>   ([STATUS §2 A1.1](../STATUS.md)).
> - **And the document does not call itself a *Tax Invoice* until it is set** (2026-08-25). The
>   title resolves through `IssuingEntity::isTaxRegistered()` in `InvoicePdfService::viewData()`,
>   which hands the template a `documentTitleKey` — plainly *Invoice / فاتورة* on an unconfigured
>   install. Omitting the NUMBER made the document silently *incomplete*, which is the posture that
>   line was chosen for; printing the TITLE anyway made it *confidently wrong*, asserting a tax
>   character it could not support on the one page every tenant files with their own accountant.
>   The KEY travels, not the translated string — the PDF renders in the reader's locale — and the
>   `<title>` tag and the printed heading read the same variable, because a saved PDF keeps the tab
>   title as its filename and the two disagreeing is a document whose name contradicts its heading.
>   **Testing trap:** assert the whole element. *Invoice* is a substring of *Tax Invoice* (and
>   «فاتورة» of «فاتورة ضريبية»), so `toContain(__('admin.pdf.invoice'))` passes on exactly the
>   document being refused.
> - **The VAT summary is per RATE** (`InvoicePdfService::vatSummary()`), shown only when an invoice
>   carries more than one. Base rent is exempt while service charge is standard-rated, so one
>   Atriom invoice routinely carries both, and a single "VAT: 1,400" line does not tell the tenant's
>   accountant which part of the 20,000 carries claimable input tax.
> - **It reads each line's OWN `vat_rate`**, never today's `TaxSettings` — an issued invoice keeps
>   the rate it was billed at, so re-deriving would silently restate every historical document the
>   day the standard rate changes. Pinned by `TaxInvoiceSellerParticularsTest`.
>
> **Duplication CLOSED 2026-08-22.** `EtaSettings::issuer_tax_registration_number` used to hold the
> same number for e-invoicing submissions — one number in two homes an operator could set to
> disagree, so the PDF and the submission would state different registrations. `EtaSettings` was
> DELETED with the ETA freeze (`App\Support\Modules::FROZEN`); all four of its properties were
> inert, the submission pipeline reads `config('eta.*')` from env, and the settings tab's only
> effect was two `->required()` fields nothing consulted. `TaxSettings` is now the sole home, and
> when module 16 resumes `EtaJsonBuilder` must build its issuer block from here — a registration
> number is company identity, not a property of an integration that may be switched off.

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

### Importing charge schedules (`ChargeImporter`, 2026-08-12)

Reached from the Leases list, keyed by lease reference, because it is portfolio-wide work rather
than something done one lease at a time.

**It writes through `ChargeScheduleService` and never touches the table**, which is the whole design.
A lease's charges are a dated SCHEDULE whose rows must butt up exactly: two rows overlapping a month
make it ambiguous which amount applies, and the billing run — which refuses rather than guesses —
bills **nothing at all** for that lease. `atriom:audit-charge-schedules` exists because that has
already happened to legacy rows, and an importer inserting rows directly is the fastest way to
recreate it a hundred times in one upload. `setAmount()` is the one path that closes the outgoing
rung before opening the next.

Every column is therefore an *input* to the service and carries a no-op `fillRecordUsing` — without
it Filament writes `amount` straight onto whichever row the service returned, overwriting the rung it
just decided. **A blank VAT column stays NULL** so the catalogue answers per invoice; defaulting it
would re-freeze the rate and undo the fix above.

**Tests:** `tests/Feature/Regression/CutOverImportersTest.php`.

### Implementing late fees

Late fees are NOT generated automatically by MonthlyBillingService; they are applied on-demand (and
by the 04:00 `billing:apply-late-fees` scheduler) via `LateFeeService::runForToday()`.

1. `LateFeeService` raises **its own invoice, dated today**, carrying a single `late_fee` line, and
   links it from the overdue invoice via `invoices.late_fee_invoice_id`.
2. **The overdue invoice is not touched.** Its totals, its lines and its GL entry all stay exactly
   as issued.
3. Idempotency is the link, not a line: `Invoice::hasLiveLateFee()`. A **cancelled** fee invoice
   frees the source to be charged again (its entry is voided, so nothing double-counts) — the same
   rule as `BillViolationFineService`.
4. **The sweep walks a SNAPSHOT of ids, in chunks of 250** — not `->get()` of the whole backlog, and
   deliberately not `chunkById()`. Two reasons, and the second is the interesting one:
   arrears is the one dataset that never shrinks, so hydrating all of it with its leases at 04:00
   grew every month; and **this loop creates invoices that match its own filter.** A fee invoice is
   issued today and due `today + payment_terms_days`, which on zero-day terms is due TODAY, i.e.
   inside `due_date <= today`. `chunkById()` pages forward on ascending id, so once a page fills it
   walks straight into the fees it just raised and considers charging a fee on a fee. The old
   `->get()` was safe from that by accident; taking the ids up front keeps the property on purpose.
   `LateFeeSweepIsBoundedTest` proves it with a one-row page size, because at 250 the hazard is
   unreachable in a fixture — `chunkById()` only re-queries when a page comes back full.
5. **`ApplyLateFees` is serialised per day** (`WithoutOverlapping(...)->dontRelease()`). It shipped
   without that guard while declaring `$timeout = 600` against a queue `retry_after` of **90**, so
   any run over 90 seconds became reclaimable and a second worker started the same sweep while the
   first was still going. Correctness survived — each invoice is row-locked and its full
   precondition re-checked inside the transaction — but it was double the load and double the memory
   against AR, nightly. `retry_after` was raised to 900 in the same change, and
   `QueueJobSafetyConformanceTest` now classifies every job and fails the build if any timeout
   reaches it. See [module 19](19-notifications-scans.md#queued-jobs-and-re-entrancy).
6. Tests: `LateFeeRecognisedWhenIncurredTest` (the date + the probe + the closed period),
   `LateFeeIdempotentTest`, `LateFeeSweepIsBoundedTest` (the sweep's shape),
   `BillingMathTest::test_late_fee_applies_once_per_invoice`.

> **Until 2026-08-11 the fee was appended to the overdue invoice, and that put it in the wrong
> month.** `InvoiceJournalizer` dates its entry from `issue_date`, so April's penalty on a January
> invoice was recognised as **January revenue** — restating a month already closed, already reported
> to the owner and possibly already filed, from an 04:00 cron with nobody watching. It also restated
> an issued document, so the tenant's copy stopped matching ours. A separate dated invoice is the
> pattern CAM true-ups, percentage-rent overages and violation fines already use.
>
> Two things had to move in the same change, both of a class this codebase has been bitten by
> before: **`late_fee` joins `MonthlyBillingService`'s already-billed probe exclusion** (a
> standalone invoice dated into the current month otherwise reads as "already billed" and the
> recurring run silently skips that lease's rent — fixed one at a time for `percentage_rent`,
> `utility`, `violation_fine` and `nsf_fee`), and **the closed-period guard on `Invoice` now
> actually applies to the fee**, which it never did while the fee was a line on someone else's
> invoice. The batch logs the refusal per invoice and continues.

---

## 9. Gotchas, edge cases & recently-fixed bugs

### ~~A late fee does not post to the GL in real time~~ — it does now, and the schedule ordering stopped being load-bearing

**Resolved 2026-08-11 by FS-27, as a side effect of fixing the month it lands in.**

It used to be true, and here is why it was: `LateFeeService::applyTo()` bumped the overdue invoice's
`subtotal`/`total` and called `recomputeTotals()`, which saves **quietly**. `saveQuietly()` skips
model events, so the near-real-time hook (`LedgerRealtimeSync`) never fired and the GL lagged the
invoice until the 05:00 sweep:

| moment | GL | invoice |
| --- | --- | --- |
| after the initial post | 10,000 | 10,000 |
| immediately after the late fee | **10,000** | **10,200** |
| after `accounting:sync-ledger` | 10,200 | 10,200 |

That drift was called deliberate, and the **schedule ordering was load-bearing**: `ApplyLateFees` at
04:00, `accounting:sync-ledger` at 05:00, one hour of lag. Reordering them would have stretched it
to ~24 hours and risked a month-end fee's period closing before its entry posted.

**A late fee is now its own invoice, and an `Invoice::create()` fires the hook like any other.**
Verified by enabling `accounting.realtime_ledger_sync` (the test suite gates it off for
deterministic posting): the fee's entry exists before any sweep runs. So the fee posts within
seconds, the table above no longer describes anything, and **the 04:00/05:00 ordering is no longer
what keeps late fees correct** — the fee never depended on the invoice it penalises being re-derived
in the first place.

Keep the ordering anyway: the sweep still backstops everything, and other sources still rely on it.
Just don't cite late fees as the reason.



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

### 6. `payment_terms_days` is NOT NULL — the `??` fallback was dead code (corrected 2026-08-12)

**Gotcha:** this section used to read *"if a lease has no payment_terms_days (null), the default is
7"*, over this snippet:

```php
$dueDate = $issueDate->addDays($lease->payment_terms_days ?? 7);
```

**That never happened.** `leases.payment_terms_days` is `unsignedSmallInteger` **NOT NULL with a
database default of 7**, so the right-hand side of every `??` — at *eight* billing call sites — was
unreachable. When CFG-04 replaced the literal `7` with `BillingSettings::defaultPaymentTermsDays()`
it inherited that: the operator could set 30 on the settings screen, see it saved, and every lease
would still be created and billed at 7. A configured setting that reaches nothing.

**The fix is a change of layer, not of value.** The default now applies at **origination** — the
lease form pre-fills the field from `PropertySettings::paymentTermsDays()` (property, falling back to
portfolio) — and from then on the lease carries its own number, read through `Lease::paymentTermsDays()`.

That is also the correct semantics, and what Yardi does: changing a property's default must **not**
retroactively move the due date on receivables already raised, which is exactly what a billing-time
lookup would have done. Pinned by `PropertySettingsReachTheMoneyTest`.

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
+ `PostingDateGuards::guards()`), and its tie-out test drives the **real service and the real
`accounting:sync-ledger` sweep** — a test that calls `LedgerPoster::post()` directly would prove
only the journalizer's arithmetic.

Tests: `tests/Feature/Regression/InvoiceWriteOffTest.php`.

## Chasing an overdue invoice — the dunning ladder (1A-16, 2026-08-25)

`billing:remind-overdue-tenants` filtered on `whereNull(tenant_overdue_notified_at)` and set the
stamp, so **every overdue invoice chased its tenant exactly once, for the life of the invoice**. A
tenant three months behind had been written to as often as one three days behind, nothing recorded
how many times anyone had been asked, and every follow-up was somebody's memory — in a market this
codebase's own `OpeningInvoiceImporter` describes as arrears-chasing-first.

**Two columns and two settings.** `invoices.dunning_level` is the notice NUMBER, beside the existing
`tenant_overdue_notified_at`, which is the date of the LAST notice; together they answer *"how many
times, and when last?"*, which is the whole of the notice history a collections call needs. A
separate history table would record the same two facts per row and be a second place for them to
disagree.

- `BillingSettings::dunning_followup_days` — days since the last notice before chasing again.
  **0 = chase once, and that is how it ships**, so no tenant receives a message on deploy day they
  would not have received the day before. Same reasoning as `late_fee_recurrence_days`: how hard you
  chase is a commercial judgement about tenants the operator has to keep working with.
- `BillingSettings::dunning_max_notices` — the ceiling (default 3, 0 = none). The notice **at** the
  ceiling is a final demand.

**The ladder is per INVOICE, not per tenant** — each invoice is its own claim with its own age, a
tenant may be current on one and months behind on another, and a per-tenant counter would send a
final demand about a bill raised yesterday.

**The final demand is the operator's own words or it does not happen.** `dunning.final_notice` and
`dunning.final_subject` are registered in `DocumentText` with **no floor** — unlike every other block
they have no historical lang key to inherit, because the document never existed — and fall back
through `DocumentText::FALLS_BACK_TO` to the ordinary reminder. Giving them the reminder's lang key
instead would mean an operator who has customised their reminder and written no final demand finds
the sharpest notice reverting to system wording. A system-composed *"FINAL NOTICE"* is also the
message most likely to start an argument nobody intended.

The migration backfills `dunning_level = 1` wherever the stamp is set, so switching the cadence on
cannot send a "first reminder" to a tenant who has already had one. The level resets when the invoice
stops being overdue-and-unpaid. (`ChasingATenantIsALadderNotOneEmailTest`.)

## Sending an invoice to its tenant (UX5-09, 2026-08-25)

`InvoiceIssuedNotification` was dispatched from **one place** — `MonthlyBillingService` — so an
invoice raised by any other path (a violation fine, a CAM recovery, a percentage-rent overage, an NSF
fee, a one-off an operator typed) reached the tenant only if they happened to open the portal, and
there was no send or re-send action anywhere on the invoice. The daily *"I never received it"* call
ended with somebody downloading the PDF and attaching it to their own email.

`SendInvoiceToTenantService` is the seam both paths go through, so there is one answer to "what does
the tenant get". It **refuses a draft** (`isVisibleToTenant()` — the per-record twin of the
`visibleToTenant()` scope, derived from the same `TenantVisibility::hiddenFor()` list so a second
definition of "visible" cannot appear), and it stamps `invoices.tenant_notified_at`, which is the
fact the next such call is settled against — and the reason re-sending is a first-class action rather
than something to guard against.

## The document a tenant receives (2026-08-27)

**Language.** The PDF is written in the language its READER reads, not its sender's. It rendered in
`app()->getLocale()` — the operator's panel language, or `config('app.locale')` for a scheduled run
— so an operator working in Arabic sent Arabic documents to tenants whose accountants file in
English. `App\Support\Pdf\DocumentLocale::resolve()` now answers, in order: what the operator picked
on the download modal → the tenant's own `locale` → the current request → the app default. The
download button carries the picker (`App\Support\Filament\PdfDownloadAction`), pre-selected to the
tenant; `/api/v1` takes `?lang=`; the e-mailed copy follows the TENANT, because a tax document is
addressed to the company and must not vary with which portal login happened to be notified.

**Typesetting.** Set in **Direction D** — a full-bleed navy band carrying the mall's identity,
the balance in an amber panel of its own — chosen from four directions drawn side by side in both
languages. Built on the shared shell (`resources/views/pdf/layout.blade.php`, `_styles`, `_issuer`)
and rendered by `App\Support\Pdf\PdfDocument` — the only thing in the app that
constructs mpdf. It carries a running footer with the document's own reference and `page x of y`,
and a cancelled or voided one is watermarked. Do NOT add an `@page` rule to the template: page
geometry belongs to the renderer, and a template that sets its own margins leaves no room for the
footer, which then renders nowhere at all.

**Free text.** Anything a person typed — a party name, a line description, notes — is fenced with
`App\Support\Pdf\Bidi::isolate()` so it keeps its own direction inside a document written in the
other one. Without it an Arabic document renders an English sentence as `.Issued in error`.

See [OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference) for the
whole rule, and `ADocumentIsWrittenInItsReadersLanguageTest` for what is pinned.

## The invoice's own page: one act, one place (2026-09-01)

**Reported from the panel: the header said "Regenerate payment link" twice.** `EditInvoice`
composed `InvoiceActions::all()` — which defines that act — *and* a second, inline copy of it, so
the same red button rendered twice on every invoice carrying a pay-link token. Both rotated the
same token, so nothing was wrong with either; what was wrong is that a **destructive** act appeared
twice with nothing to say which was which.

It survived because a duplicate is invisible from either definition. Each file is correct on its
own; `cacheAction()` keys by name, so `mountAction('regeneratePaymentLink')` resolved cleanly and
every existing test — including the two that drive that exact act on that exact page — passed.
**Only the rendered header shows two.** The inline copy is gone; the `->authorize()` it declared
moved onto the surviving definition, so the double gate is unchanged.

**The other half is layout.** Thirteen acts rendered as loose buttons filled the header edge to
edge, wrapped the page title down four lines and took the breadcrumb with it. They are now three
dropdowns beside the standalone ledger panel, grouped by the question being asked — the same answer
the lease page already reached (`LeaseActions::GROUPS`), so the two record hubs read alike:

| Group | Acts |
| --- | --- |
| **Document** | download PDF · send to tenant · payment link · regenerate payment link · submit to ETA |
| **Settlement** | apply credit · allocate to lines · reverse credit · reverse deposit application · reverse write-off |
| **Corrections** | dispute line · resolve dispute · write off · void |

A group whose every act is hidden hides itself (`ActionGroup::isHidden()`), so an issued invoice
with nothing to settle shows two dropdowns rather than an empty third. Measured on the demo books:
**seven loose buttons → three controls.**

**The hazard grouping introduces:** an act missing from `EditInvoice::HEADER_GROUPS` is defined and
rendered **nowhere at all** — it passes every visibility and authorisation check and simply never
appears, which is indistinguishable from a feature that was never built. That happened to two lease
actions the day *they* were grouped. `InvoiceActionTopologyTest` asserts the map and the page's own
composition name exactly the same set, in **both** directions, that no name is grouped twice, and —
because grouping must be a layout change and never an authorisation one — that every act is still
mountable by name through the real page. All four teeth mutation-proved.

**And the panel was swept for the same defect elsewhere — there is none.** Two gates, because
neither can see what the other does. `AnActIsDeclaredOnceConformanceTest` reads the source through
`Tests\Support\ActionStrips` (tokenised, so an action declared inside a modal `schema()` closure is
not miscounted as a sibling) across all **330** strips the panel declares, relation managers
included. `NoScreenRendersTheSameActTwiceTest` mounts **159 of 160** screens and reads what Filament
actually cached — the only place a trait-supplied act, a `parent::getHeaderActions()` spread, or a
runtime-composed group is visible. It compares LABELS too: two acts with different names under one
set of words is the same complaint. Both go red when `EditInvoice` is restored to `d4edce7c^`.

> **⚠️ Ending a charge from a FUTURE date stopped billing immediately (fixed 2026-09-02).**
> `ChargeScheduleService::close()` stamped `end_date` — the operator's own date, recorded correctly
> — and set `is_active = false` in the same breath. `MonthlyBillingService` selects on `is_active`,
> so the row left the billing plan **at once**: ending a charge from 1 December silently stopped
> invoicing it in September, and the intervening months were never billed. Nothing on any screen said
> so; the schedule showed the right end date the whole time.
>
> `end_date` alone is enough — the planner already refuses a row whose `end_date` falls before the
> period it is billing, so the schedule stops itself on the day the operator chose. The flag is only
> for a stop that has already ARRIVED, where leaving the row active would offer a dead schedule in
> every picker. (`AFutureStopDateStillBillsUntilItArrivesTest`.)

> **⚠️ THE TENANT STATEMENT PRINTED A WINDOW IT DID NOT APPLY (SW-154, fixed 2026-09-02).**
> `TenantStatementPdfService::data()` derived `$asOf` from the caller's `to` — and bounded nothing
> with it. `$invoicesAll` had no upper bound at all, and the recent-invoice list, the payments, the
> credit notes and both settlement queries were `>= $since` with no `<=`. So
> `GET /me/statement?to=2026-03-31` rendered *"as at 31 March"* over rows dated April, May and June,
> on the document a tenant's accountant reconciles a quarter from. The portal and the panel share
> this service, so all three surfaces were wrong together.
>
> The bound is **`endOfDay()`**, and that matters on the datetime columns: `payments.payment_date`
> carries a time, so a bound of 31 March 00:00 silently drops everything received that day — the
> same fault pointing the other way. An `issue_date` is a plain date and survives either bound,
> which is why an invoice-only edge case proves nothing and the regression asserts on a payment.
>
> **What it deliberately does NOT claim:** the balances are as they stand TODAY, not as they stood on
> the end date — a payment made after the window still shows against an invoice inside it.
> Reconstructing a historical balance means replaying four settlement channels to a date, which is an
> aged-debt-as-at report and a different document. What the statement claims is which TRANSACTIONS
> fall in the window, and that is now true. It also selects open invoices on `collectableBalance()`
> rather than `balance`, so a partial write-off is not chased.
>
> An existing fixture had to move with it: `StatementExplainsEverySettlementTest` freezes the clock
> at 17 August and dated its credit note 31 August — a document a fortnight in the FUTURE, which only
> ever passed because no upper bound existed. The date moved, not the assertion.
> (`AStatementStopsAtTheDateItNamesTest`.)
