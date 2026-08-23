# Atriom pre-staging QA — findings log

> **All findings marked ✅ in [PRE-STAGING-QA.md](PRE-STAGING-QA.md) were fixed on 2026-08-19**,
> verified against MySQL and covered by a regression test. This file is kept as the evidence: what
> was measured, on what data, and why each fix is shaped the way it is.
>
> **One correction to F-08.** The first fix reduced the CAM denominator so the remaining tenants
> split what a stated share leaves. `CamDenominatorTest` showed that overturns a deliberate design —
> a neighbour's lease says "your pro-rata share", so re-cutting them to cover someone else's
> negotiated discount over-bills them against their own terms. Reverted; only the over-recovery
> direction is guarded, and the guard tests the **projected total share**, not `Σ stated` (a lease
> stated at 12.5% against a 2% area share over-recovers while the stated figures sum to well under
> 100).
Run against an isolated `mall_management_qa` DB (DemoSeeder baseline), driving the real services.

---
## F-01 · BLOCKER · Spacing/Owners · A unit ownership can never be given an assessment schedule
**Module:** 37 unit owners · `BillUnitOwnershipsService`, `UnitOwnershipResource`

`billing:run-assessments` bills an ownership from its `charges` rows and skips it when there are none.
**No surface in the application creates such a row:** `UnitOwnershipResource::getRelations()` does not
exist, `UnitOwnershipForm` has no repeater, `ChargeScheduleRelationManager` is mounted only on
`LeaseResource`, and `ChargeImporter` resolves a `lease_reference` only. The only ownerships in the
system with a schedule are the ones `DemoSeeder` wrote directly.

Effect: an operator registers a sold unit through the panel, the ownership reads `handed_over`
and `isBillableForPeriod()` returns true — and the monthly صيانة run silently reports it as
`skipped`, forever. Same failure mode as the already-documented "the run was never scheduled"
bug: the run now exists, but there is nothing for it to bill.

Repro: `F01_owner_assessment_unreachable.php` (10/10 assertions of the defect hold).

**Fix:** mount `ChargeScheduleRelationManager` on `UnitOwnershipResource` (its `$relationship =
'charges'` already matches `UnitOwnership::charges()`), and make the run report a handed-over
ownership with no schedule as a *warning* rather than an unremarkable skip.

---
## F-02 · HIGH · Spacing/Owners · A mid-month resale never rebalances the month's assessment
**Module:** 37 · `TransferUnitOwnershipService`, `BillUnitOwnershipsService`

`BillUnitOwnershipsService::billOne()` prorates on tenure, and its docblock states "a resale on the
10th bills the seller 10/30 and the buyer the rest". That is only true if the month is billed *after*
the transfer is recorded. In the real sequence — the scheduled run raises the assessment on the 1st,
the sale completes on the 11th — measured:

* seller stands billed **3,000.00** for a month in which they owned the unit for 10 of 31 days (967.74 owed) — **2,032.26 over-billed**;
* no credit note, no unearned-billing credit, nothing corrects it (`UnitOwnershipStatus::Transferred` is not `isBillable()`, so a re-run skips the seller by design);
* the buyer is billed **nothing** for 11–31 Oct, and a manual re-run cannot fix it either — the buyer inherits terms but no charge rows (F-01).

The lease side has `CreditUnearnedBillingService` for exactly this; the ownership side has no equivalent.

Repro: `03_resale_proration.php`.

**Fix:** on transfer, credit the seller's unearned days and open the buyer's schedule from the
seller's (or bill the buyer's part of the month), inside the same transaction as the tenure split.

---
## F-03 · NOTE · Spacing/Owners · `assessment_basis` is collected and never read — ✅ FIXED 2026-08-19
`unit_ownerships.assessment_basis` (area / participation / purchase_value / stated) and
`participation_pct` are on the form, validated, activity-logged — and read by no calculation.
`BillUnitOwnershipsService` bills flat `charges` rows regardless. The model docblock flags these as
"§8's unanswered client questions", so this is a known placeholder rather than a regression — but on
screen it reads as a live setting that changes what an owner is charged, and it does not.

**Fixed.** The basis now drives the **annual CAM true-up** — `CamReconciliationService::ownershipShares()`
— and deliberately nothing else: the monthly صيانة is a `charges` row the parties agreed and the
operator typed, and deriving it from a denominator would overwrite the schedule with a computed
number. `participation` and `stated` both read `participation_pct` and both mean *a percentage of the
pool*, which is the same claim a lease's contractual share makes, so they route through the SAME path
and inherit F-08's over-recovery refusal: a building whose deeds together promise away more than the
pool is refused rather than billed. A null percentage falls back to area, never to zero. `area` is the
default and returns nothing, so every pool that exists reconciles unchanged.

`purchase_value` was the one that needed a decision, because a leased unit has no purchase price to
sum with. The reading chosen — stated in the service, in module 37 and as **B2.5** in
`docs/OPEN-QUESTIONS.md` rather than left implicit — is that the purchase-value owners keep the slice
their AREA gives them collectively and divide it among themselves by price. Σ over the cohort is
therefore identical either way, no leased neighbour moves, and this basis can never itself cause an
over-recovery.

The form now requires `purchase_price` when the basis divides by it, asked of the enum's
`requiredColumn()` rather than a literal — without that an owner assessed on purchase value silently
fell back to floor area, which is the same bug from the other direction.

Pinned by `AssessmentBasisApportionsTheOwnersShareTest` (8 cases). Mutation-tested: stubbing the new
method out turns four of them red and leaves the four controls green — which is the right split,
because the controls are the ones that must not move.

---
## F-04 · HIGH · Leasing · Nothing ever moves a lease from `active` to `expired`
**Module:** 04 · `routes/console.php`, `RentEscalationService`, `Unit::recomputeStatus()`

There is a `vendors:expire-contracts` sweep for vendor contracts and **no equivalent for leases**
(`leases:*` is only `remind-expiring`, `scan-option-windows`, `apply-escalations`). A lease whose
`expiry_date` has passed stays `active` indefinitely unless a human renews, terminates or holds it
over. Measured on a lease that expired 2026-01-31, with today at 2026-08-19:

* the lease still reads **active** seven months later;
* the unit still reads **occupied**, so `Asset::occupancyRate()` / `areaOccupancyRate()` / the
  occupancy map / the rent roll all overstate occupancy;
* **the unit cannot be re-let** — `LeaseCreationService` refuses with *"This unit already has an
  active lease"* on a shop that is physically empty;
* **the escalation sweep still steps its rent**: `RentEscalationService` filters on
  `status = 'active'` and never checks expiry, so it wrote a +10% rent row starting 2026-08-01 on a
  tenancy that ended in January. Invoices are safe (billing refuses with `lease_ended`), but the
  charge schedule, the rent roll and the 24-month billing forecast now carry rent for a dead lease;
* the deposit stays "held" forever and no final account is ever prompted.

Repro: `F04_no_lease_expiry.php` (10/10).

**Fix:** add a `leases:expire` daily sweep (mirror `ExpireVendorContractsCommand`) that moves
`active` → `expired` once `expiry_date` has passed and the lease is not a converted holdover, and
re-projects its units. Independently, add `->whereDate('expiry_date','>=',today)` (or "not ended")
to the escalation sweep's query — the two guards protect different things.

---
## F-05 · MEDIUM · Spacing · Stored `units.status` goes stale on a date boundary
**Module:** 01 · `Unit::recomputeStatus()`

The occupancy projection is correctly **date-aware** (`constrainToCurrentlyHeld` /
`constrainToNotYetReleased`), so a future-dated expansion reads `reserved` and a past-dated give-back
reads `vacant` — verified. But `recomputeStatus()` is only ever called from lease observer events,
the unit create/edit pages and `LeaseSpaceChangeService`. **Nothing runs on a schedule.**

So a give-back effective 1 Jan 2027, recorded today, leaves `units.status = 'occupied'` on 1 Jan 2027
and every day after, until something unrelated happens to touch that lease. Confirmed: on a simulated
2027-03-01 the projection answered "no lease currently holds this unit" while the stored column still
said `occupied`; an explicit `recomputeStatus()` corrected it.

Same root cause as F-04, different trigger. **Fix:** one nightly `units:reproject-occupancy` sweep
(or fold it into the `leases:expire` sweep above) covering units whose lease pivot or lease term
crossed a boundary since the last run.

---
## C-01 · CONFIG · Payables · Withholding tax needs TWO switches, not one
`TaxSettings::wht_enabled = true` on its own withholds **nothing**: `WithholdingTax::taxCodeFor()`
falls back to `wht_default_tax_code`, which ships empty, and an empty code resolves to a 0% rate.
Measured: enabled + no default code → 0.00 withheld on a 114,000 bill. Setting
`wht_default_tax_code = 'WH_3'` then produced the correct entry —
**Dr AP 114,000 / Cr Bank 111,000 / Cr WHT payable 3,000** — withheld on the **net** 100,000, not the
gross (a naive gross rate would have been 3,420).

Fail-safe by design ("never invent a rate"), but an operator who switches withholding on and sees
nothing happen has no signal. **Before staging:** set `wht_default_tax_code` (or a per-vendor
`withholding_tax_code`) at the same time, and consider warning on Settings → Tax when
`wht_enabled` is on with no default code and no vendor overrides.

## C-02 · CONFIG · Receivables · The returned-cheque (NSF) fee ships at zero
`BillingSettings::nsf_fee_amount` defaults to `0.0`, and `BillBouncedChequeFeeService` refuses with
*"No returned-cheque fee is set"* until it is priced. Correct refusal (it never invents a fee), but
it means the bounced-cheque flow is inert on a fresh box. Set it per property before staging.

## F-06 · MEDIUM · RBAC · The leasing role cannot open any leasing report — but the read-only viewer can — ✅ FIXED 2026-08-19
**Module:** 18 · `RolesPermissionsSeeder`, `RentRoll::canAccess()` et al.

Every report page in the four modules is gated on the single permission `reports.view`. It is held by
`manager`, `accounting` and **`viewer`** — and **not** by `leasing`. Measured against the running
panel:

| screen | manager | accounting | leasing | operations | viewer |
|---|---|---|---|---|---|
| leases | yes | – | yes | – | yes |
| rent-roll | yes | yes | **–** | – | **yes** |
| expiration-schedule | yes | yes | **–** | – | **yes** |
| occupancy-cost | yes | yes | **–** | – | **yes** |
| billing-run-preview | yes | yes | **–** | – | yes |
| sales-analytics | yes | yes | **–** | – | yes |
| units | yes | – | yes | **–** | yes |

So the role that creates, renews and terminates leases cannot open the **rent roll**, the **expiry
schedule** or the **occupancy-cost** report, while a read-only viewer can open all three. A leasing
manager's two most-used screens are invisible to them.

Separately, `operations` holds `facility.*`, `areas.*`, `requests.*` and `procurement.*` but **not
`units.view`** — so the role that routes work orders to units cannot open the unit register.

Neither is a security hole (both fail closed); both are role-design defects an operator meets on day
one. **Fix:** grant `reports.view` to `leasing` (and consider `marketing` for sales analytics), and
`units.view` to `operations`.

**Both were granted in `f0f00844` (2026-08-19)** — `leasing` now holds `reports.view` and `units.view`,
`operations` holds `units.view`. The marketing/sales-analytics half was considered and NOT taken; it
stays a live question rather than an oversight. `52_rbac_matrix.php` reproduced the defect, so the fix
is what turned it red — the assertions were flipped to the fixed behaviour on 2026-08-23, when a full
harness run surfaced them.

## F-07 · LOW · RBAC · Budget is super_admin-only
`Budget::canAccess()` gates on `settings.manage`, held only by `super_admin` — not by `manager` or
`accounting`. Deliberate per its docblock ("setting the plan is a management act"), but it means the
finance lead cannot load a budget without a super-admin. Confirm this is the intent before staging.

## F-08 · HIGH · Leasing/CAM · A contractually stated CAM share over-recovers the pool, silently
**Module:** 08 · `CamReconciliationService::generateAllocations()`, `BooksReconciliationService`

When a lease's contract **names** its CAM percentage (`lease_cam_terms.stated_share_pct`, story
RC-03), that share is used instead of the derived area share — but **the other participants'
denominator is not reduced**. So Σ shares ≠ 100% and the pool recovers more (or less) than the
actual common cost.

Measured on an isolated 4-shop property, all 250 m², pool actual **1,000,000**:

| lease | area | share used | allocated |
|---|---|---|---|
| Q-01 (stated 40%) | 250 | **40.0000%** | 400,000 |
| Q-02 | 250 | 25.0000% | 250,000 |
| Q-03 | 250 | 25.0000% | 250,000 |
| Q-04 | 250 | 25.0000% | 250,000 |
| | | **Σ 115%** | **Σ 1,150,000** |

**Tenants are billed 1,150,000 of common cost against 1,000,000 actually incurred — 15% over-recovery.**
The reverse case is also reachable: a stated share *below* the area share left the pool 200,000 short,
absorbed by the landlord with no notice.

**And nothing reports it.** The residual is stored as
`landlord_unrecovered_amount := total_actual_expense − Σ allocated` (`CamReconciliationService:277`),
which goes **negative** (−150,000) when tenants are over-charged — a state no screen and no check
reads as a problem. `billing:reconcile`'s `cam_allocations` check tests
`|Σ allocated + landlord_unrecovered − actual| > tol` (`BooksReconciliationService:206`), which the
generator has just made true by construction, so it passes at generation time **whatever the shares
sum to**. (Fairly: the check is *not* a pure tautology — mutation-tested, it does catch an
allocation tampered with after generation, inflating one by 500,000 was detected. What it cannot see
is an over-recovery the generator itself produced.)

Over-recovering CAM is a commercial and legal exposure — most service-charge clauses cap recovery at
actual cost — and it lands on the tenant-facing recovery invoice.

Repro: `F08_cam_stated_share.php`, `F08b_cam_tautology.php`, `F08c_verify.php`.

**Fix (pick one, deliberately):**
1. Exclude stated-share participants from the derived denominator, so the rest apportion only the
   **remaining** pool — Σ then returns to 100%; or
2. keep today's arithmetic but **refuse or warn** when Σ shares ≠ 100% at generation time.
Either way, add a check that compares Σ allocated to `total_actual_expense` **independently of the
stored residual**, and surface a negative `landlord_unrecovered_amount` as an over-recovery.

## F-09 · HIGH · Concurrency · The double-booking and over-allocation guards do not fire under real concurrency
**Module:** 04 / 06 · `LeaseCreationService`, `Payment::assertInvoicesNotOverAllocated()`

Run with **two real MySQL connections** (the Pest suite cannot test this: `SQLiteGrammar::compileLock()`
returns `''`, so all 118 lock acquisitions are inert there, and single-connection tests never interleave).

`LeaseCreationService::create()` takes `Unit::lockForUpdate()` and its comment states this makes the
`isActivelyLeased()` guard *"authoritative"*. **It does not.** The transaction's first statement is a
plain read (`Tenant::findOrFail`), which under MySQL REPEATABLE READ establishes a consistent-read
snapshot **before** the lock is taken. `isActivelyLeased()` is a non-locking read, so it is served
from that stale snapshot and cannot see a lease another transaction committed while this one waited.

Measured, same instant, second transaction, after the first had committed a lease on the unit:

```
B: guard saw isActivelyLeased=false (count 0); after refresh=false     ← snapshot read
B: snapshot read sees 0 · LOCKING read sees 1                          ← lockForUpdate() on the same query
```

The identical pattern applies to `Payment::assertInvoicesNotOverAllocated()`: it locks the *invoice*
rows, then sums the `invoice_payment` pivot with a **plain** read, so it cannot see a concurrent
allocation either.

**What actually prevents the corruption today is the UNIQUE index on the document number** — and it
works only because the number is also computed from the same stale snapshot, so both writers pick the
same one and the database refuses the second. Verified end state after each race: exactly **1** active
lease on the contended unit; exactly **1** September invoice; invoice allocated exactly **30,000.00**
of a 30,000.00 balance. **No data was corrupted in any race.**

So this is an availability + latent-risk defect, not (today) a corruption one:
* the loser sees a raw `UniqueConstraintViolationException` — a 500 — instead of the intended
  *"This unit already has an active lease"* / *"allocation exceeds balance"* refusal;
* if document numbering is ever made properly concurrent, the guards become the only defence — and
  they do not work.

**Fix (one line each):** make the guard queries **locking reads** —
`->lockForUpdate()` on `Unit::isActivelyLeased()`'s query and on the pivot sum inside
`assertInvoicesNotOverAllocated()`. Proven above: at the same instant the locking read returns the
correct answer while the plain read does not.

Note: the **cache** locks are fine. `CACHE_STORE=database` (a cross-process store), and the billing
race behaved exactly as designed — one worker created the invoice, the other returned
`run_in_progress`.

## F-10 · MEDIUM · Concurrency · Two document-number paths skip the numbering lock
**Module:** 04 / 06 · `AllocatesDocumentNumber`, `Lease`, `Payment`

`AllocatesDocumentNumber` is a good design (lock held across the INSERT, TTL, degrades to the UNIQUE
index). Two money paths do not go through it:

1. **`Payment` does not use the trait at all.** It has its own `generateUniqueReference()` with a
   retry loop but **no lock**, and the loop's existence check is a plain read. Reproduced: two
   concurrent receipts both computed `PAY-202608-0195`; one 500'd.
2. **`Lease`'s hook is bypassed on the main creation path.** `Lease::creating` returns early
   `if (filled($lease->reference))`, and `LeaseCreationService::create()` passes a reference it
   generated itself — so the lock never runs. Reproduced: two concurrent leases both computed
   `LSE-AW-2026-0034`; one 500'd.

Effect: any two concurrent payment receipts, or two concurrent lease creations, produce a duplicate-key
500 for one operator — regardless of whether they touch the same tenant, invoice or unit.

**Fix:** add the trait to `Payment`; in `LeaseCreationService`, let the model allocate the reference
(drop the pre-computed `'reference' => …` from the payload) so the `creating` hook's lock applies.

## F-11 · MEDIUM · Receivables · An unpaid security-deposit invoice makes `billing:reconcile` cry wolf
**Module:** 04/06 · `InvoiceJournalizer`, `App\Support\DepositHoldings`, `BooksReconciliationService`

Two definitions that are each correct disagree with one another, and the weekly reconciliation
compares them directly:

* `InvoiceJournalizer` credits `deposits_held` **at issue** (Dr Tenant Receivables / Cr Deposits Held) —
  the documented and correct entry for the billing rail;
* `DepositHoldings::held()` counts a billed deposit only once it is **settled** — also correct, and
  deliberate ("an UNPAID deposit invoice is not held… it is a receivable").

So for the whole window between issuing a deposit invoice and the tenant paying it, the GL liability
exceeds the register. Measured: billing a 150,000 deposit and leaving it unpaid moved the GL from
973,335 → 1,123,335 while `held` stayed at 973,335, and `deposits_tie_out` reported:

```
Security deposits — held 973335 (recorded movements 973335 + billed & settled 0)
                    ≠ GL deposits_held 1123335 (delta -150000)
```

It cleared the instant the payment landed. `billing:reconcile --deep` is scheduled **weekly, Friday
04:00**, and payment terms are typically 7 days — so a deposit billed on a Thursday is reported as a
books discrepancy that is not one, every time. This is exactly the "a check that cries wolf is a check
people switch off" failure the CAM check's own comment warns against, and it will fire routinely now
that billing is the recommended deposit rail.

Repro: `F11_unpaid_deposit.php` (5/5).

**Fix:** add the outstanding balance of unsettled deposit lines to the expected side of
`depositTieOutDiscrepancies()`, so the check compares
`recorded + billed&settled + billed&outstanding` against the GL.

## F-13 · LOW · Owner statements · `finalise()` is documented as idempotent and is not — ✅ FIXED 2026-08-19
`FinaliseOwnerStatementRunService::finalise()` carries `if ($fresh->isFinalised()) return $fresh…
// idempotent — already finalised`, but the line above it calls `generate()`, which throws
*"A finalised statement already exists… revise it instead"* — so the idempotent branch is dead code
and a second `finalise()` raises instead of returning. Behaviour is safe (nothing double-posts,
verified: still exactly one posted entry) and the message is sensible, so this is a correctness-of-
comment issue plus an unreachable branch. Either drop the dead branch or check `isFinalised()` before
regenerating.

**Fixed** by doing both: the run is locked and checked BEFORE `generate()` is called, and the
unreachable branch below it is gone. The early return is deliberately narrow — only **this** run
short-circuits, so a draft run for a period some other run has already finalised still reaches
`generate()` and is still told to revise instead. That distinction is load-bearing on the correction
path: `revise()` supersedes a run and then calls `finalise()` on that same row, so a check written as
"has this ever been finalised" rather than "is it finalised now" would return the superseded run
untouched and leave the operator unable to restate a wrong statement.

Pinned by `FinaliseOwnerStatementIsActuallyIdempotentTest` — four cases: the control that a first
finalise actually posts (through the real `accounting:sync-ledger` sweep, per the one-registry rule),
the second call returning the same run with the FIRST call's stamps, three calls posting exactly one
entry, and a revision still producing a new finalised version. Mutation-tested: disabling the branch
fails three of them with the original error message verbatim.

---

# VERIFIED CORRECT (no defect found)

Everything below was driven through the real services against MySQL with real data and produced the
right numbers and the right refusals. Roughly **600 assertions**, all passing.

* **Billing engine** — VAT (rent exempt / service charge 14% / levy exempt), mid-month commencement
  with and without proration, mid-month expiry (always prorated), last-day commencement (1 day, not 0),
  rent-commencement clipping per charge type, quarterly cadence + cycle-start-only billing, a final
  partial quarter capped at expiry, gross vs rent-only fit-out abatement, run idempotency,
  overlapping-schedule refusal at both the write and the billing seam.
* **Lease lifecycle** — escalation ladder projected at signing and compounding correctly, the sweep
  dated to the anniversary (not the night it runs) and idempotent, collar ceiling clamping a mistyped
  70%, collar floor, fixed-amount steps unclamped by a percent collar, CPI never invented, extension
  (refuses moving expiry backwards, re-derives term, re-projects steps), renewal carrying the full
  term set including the collar, double-booking refused, holdover at a percentage of passing rent.
* **Termination & move-out** — unearned billing credited to the exact day-share, earned invoices left
  standing, future-period invoices cancelled, part-paid invoices never silently cancelled, deposit
  netted against arrears as a `DepositApplication`, forfeit and refund recorded, deductions capped at
  the deposit held.
* **Percentage rent** — artificial breakpoint, natural breakpoint, tiered ladder charging each band
  only within it, and a short first year correctly pro-rating the annual breakpoint.
* **Relief** — a bounded window that ends by itself, resumes at the **post-step** amount, and does not
  swallow a contracted escalation.
* **Premises** — expansion and give-back close the `lease_unit` row rather than deleting it, so CAM
  still sees the months the tenant genuinely held the space; date-aware occupancy.
* **Receivables** — all four settlement channels (cash, credit note, tenant credit, netted deposit)
  settling one invoice to exactly its total, over-allocation refused on the form path and clamped on
  the gateway path, void invoice / void payment / partial and full write-off with reversal, late fees
  as their own dated invoice with grace and dispute handling, post-dated cheques (clear, bounce, NSF
  fee, idempotent), closed-period refusals on every operator-typed posting date.
* **Payables** — draft ≠ payable, approval posting Dr expense + Dr VAT-recoverable / Cr AP, payment
  capped at the balance, withholding tax on the **net** (3,000 not 3,420), void restoring the payable,
  cancel refused once money has moved, SLA penalties deducting and posting their own entry, GRNI
  cleared by the vendor bill instead of double-charging the expense.
* **Property isolation** — foreign-property URLs 404, `/admin/ALL` 404, scoped queries return only the
  selected mall, write guards refuse a foreign or blank property, cross-property credit notes and
  on-account credit refused, one payment cannot span two tenants.
* **UI** — all 76 pages across the four modules render 200 (plus 11 accounting screens), every Create
  form mounts, the invoice form's `->live()` prefill runs and derives the debtor from the lease, a
  crafted debtor in the payload is overridden, the property field is pinned.
* **Accounting** — the trial balance balanced after **every** operation; AR and AP tied to source
  documents at every checkpoint; `billing:reconcile --deep` clean; owner statements tie to the income
  statement to the penny and post Dr Owner Distributions / Cr Due to Owner; revise supersedes and the
  sweep voids the old entry.
