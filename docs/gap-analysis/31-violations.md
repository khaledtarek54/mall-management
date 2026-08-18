# Module 31 — Tenant Violations · gap analysis

> **Round 3, 2026-08-18.** First audit — the module shipped after round 2 closed and was on the
> never-gap-analysed list in [PROJECT-MAP](../PROJECT-MAP.md).
> Method: [000-plan.md §Round-2 methodology](000-plan.md) — an absence claim is a hypothesis; a
> finding needs a concrete failure scenario and is proven by exploiting it.
>
> **Benchmark.** [competitors/06](competitors/06-vendors-areas-permits-violations.md) — Yardi,
> Facilio, ServiceChannel. **Its Atriom column is STALE on the headline row**: it records
> "Violation → bill the fine (post to AR) ❌ … recorded, never billed", which was true on 2026-07-18
> and was built afterwards (`BillViolationFineService`). Only the competitor columns are used as the
> yardstick here; the Atriom side is re-derived from code. Same staleness as
> [module 32](32-owner-statements.md) — worth remembering before quoting that file again.

## 1. Verdict

**The module is in good shape, and the part the benchmark called out as missing is now the best-built
part of it.** Billing a fine is idempotent, lock-safe, VAT-exempt on a stated charge code, raises its
own invoice through `IssueInvoiceService`, posts to `misc_income` through the ordinary
`InvoiceJournalizer`, and is deliberately excluded from `MonthlyBillingService`'s already-billed probe
so a fine dated to the violation month cannot suppress that lease's base rent. Once billed, the fine
amount, the debtor and the property all lock, and deletion is refused.

**One 🟠 finding**, and it is the same shape as module 32's: a state the UI permits that the money
path could not handle — here, the arrival of module 37's owner-occupiers.

## 2. Findings

### 🟠 F-A — an owner-occupier can be fined but never billed *(fixed)*

**Benchmark.** A fine a mall assesses is money it intends to collect; the benchmark's complaint was
that Atriom recorded the number and stopped. It now bills — but only one kind of occupier.

**Atriom.** [`BillViolationFineService`](../../app/Services/BillViolationFineService.php) resolved the
debtor's **active lease** in the violation's property and refused when there was none. That was right
when every occupier held a lease. Module 37 (August 2026) introduced the other kind: a unit **owner**
who bought the shop, trades from it himself, and has **no lease at all** — his صيانة is billed
against the ownership instead.

The violation register never learned the difference. Its debtor picker is an unfiltered
`EntitySelect` over `Tenant`, and a unit owner **is** a `tenants` row (`party_type = unit_owner` —
one table for both parties, module 37's own design). So an operator can select an owner-occupier,
record the violation, set a fine, see it in the register with a fine amount — and then find the
fine unbillable.

**Failure scenario — proven.** An owner-occupier stores stock in the common corridor and is fined
EGP 2,500. `bill()` threw *"The tenant has no active lease in this property"*, with the lessee case
passing beside it as a control.

**Why it was structural, not a missing feature.** Nothing downstream needed changing:
`UnitOwnership implements BillableAgreement`, and `IssueInvoiceService::issue()` takes the contract
rather than a `Lease` — which is exactly how the monthly assessment is already raised. Only this
service's lookup was lease-shaped.

**FIXED 2026-08-18.** The lookup falls back to the party's unit ownership in that property when no
active lease exists. **Tenure-aware on the violation's date, not today**, so a later resale cannot
move the debt onto the buyer — the party who owned the unit when it happened is the party who owes
the fine. A party holding neither a lease nor an ownership is still refused, because there is no
agreement to bill against and inventing one would be worse
([`ViolationFineBillsAnOwnerOccupierTest`](../../tests/Feature/Regression/ViolationFineBillsAnOwnerOccupierTest.php),
four cases: the owner, a lessee control, the still-refused stranger, and the GL tie-out **through
the real `accounting:sync-ledger` sweep** — 2,500 to `misc_income`, trial balance balanced, AR
delta 0).

## 3. Verified clean (hypotheses that did NOT hold)

| Hypothesis | Result |
|---|---|
| A fine can be billed twice (duplicate AR) | **False** — the violation is re-read under `lockForUpdate` and re-checked inside the transaction; an existing non-cancelled invoice is returned untouched. Only a **cancelled** invoice (GL entry voided) frees the fine to re-bill |
| `fine_amount` can be edited after billing, diverging the record from the AR | **False** — disabled once `isBilled()`, as are the debtor and the property |
| A billed violation can be deleted, orphaning the invoice | **False** — refused at the model; soft-delete stays allowed deliberately |
| The fine is billed with VAT | **False** — a penalty is not consideration for a supply, so it is out of scope; the rate resolves through `Vat::rateForType()` off the charge code, so the accountant can rule otherwise without a deploy |
| A fine dated to the violation month suppresses that lease's base rent | **False** — `violation_fine` is excluded from the monthly run's already-billed probe, and a regression test pins it |
| The fine invoice can land in another property | **False** — the lease/ownership lookup is constrained to the violation's own `asset_id`, and the debtor is stated on the violation rather than inferred |
| The "Send notice" action is gated only in `visible()` | **False** — dual-gated in `visible()` **and** `action()`, and failure-contained |

## 4. Still open vs the benchmark (not fixed, stated)

- **Fit-out permit approval workflow** — a permit is capture-only by design (a typed `TenantRequest`
  with a validity window, no grant/reject/conditions step). That is module 11's decision, not 31's,
  and the benchmark is right that a mall fit-out permit is usually a control gate. Unchanged here;
  it is a scope question for the operator, not a defect.
- **Contractor permit-to-work / safety permits** — absent, as competitors/06 records. No hot-work or
  isolation permit concept exists.
- **Escalating fines for repeat violations** — no repeat-offence ladder. Each violation is priced by
  hand. Not a benchmark row; noted because the register makes the pattern visible and nothing uses it.
