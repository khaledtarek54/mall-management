# 08 · Unit Owners (ملّاك الوحدات) — design & phased plan

> **Status:** design, awaiting sign-off. Nothing built.
> **Standard followed:** Yardi (house rule — [feedback: Yardi is the default standard]).
> **Written:** 2026-08-15.

Egyptian malls sell units (تمليك) as well as leasing them. The buyer is **not a tenant**: he pays no
rent, he pays صيانة; he may trade from the unit himself, let it himself, hand it to the operator to let
for him, or leave it empty. Atriom today has no concept of him at all.

This plan designs that concept the way Yardi does, and sequences it behind one enabling refactor so the
existing money core is extended rather than forked.

---

## 1. The two relationships (and why they are not one feature)

"Unit buyer" is two relationships with **opposite money directions**, and Yardi ships a different product
for each. Conflating them is the main design error to avoid.

| | Owner as **payer** | Owner as **beneficiary** |
|---|---|---|
| Yardi product | Voyager **Condo, Co-Op & HOA** | Voyager/Breeze **Owners** (third-party mgmt) |
| Money direction | owner → operator | operator → owner |
| The document | assessment / due (صيانة) | owner statement + draw |
| Atriom analogue | *(none)* | module 32, but at property level |

A single buyer is usually **both** at once: he pays the assessment on his unit *and* receives net rent if
the operator lets it for him. So both are in scope, staged.

### 1.1 How Yardi models the payer side

- **The unit has an owner of record, and the owner holds the unit's ledger.** This is the structural key:
  in Voyager the AR ledger belongs to a *customer code*, and in the Condo product the owner simply **is**
  that record type. There is no parallel "owner AR" — dues post to the owner's ledger exactly the way rent
  posts to a tenant's.
- **Assessments** are recurring charges generated from the unit's **participation interest** — a percentage
  carried on the unit that sums to 100% across the building. The association budget is allocated by it.
  **Special assessments** are one-off charges for capital works, allocated the same way.
- **Reserve / replacement fund** sits in a book separate from operating, so the sinking fund is never spent
  as operating cash.
- **When the owner lets the unit**, a renter/lessee sub-record is recorded under the owner's unit. The
  lessee exists for access, contact, violations and occupancy — but **the owner stays liable for the
  assessments**. Owner of record ≠ occupant of record.
- **Violations, fit-out/architectural review, work orders** attach to the owner record, not to a lease.
- **Owner portal** — same family as the resident portal: pay dues, read the statement, raise requests.
- **Change of ownership** closes the seller's ledger at a stated balance, opens the buyer's, keeps unit
  history intact, and issues the resale/estoppel certificate saying what is owed at transfer.

### 1.2 How Yardi models the beneficiary side

Owner records under a **management agreement**: fee as % of *collected* rent or fixed; **owner draws** and
**owner contributions**; a **cash-basis owner statement** per owner; an owner portal to read it.

> Note the basis difference: module 32's property-owner statement is **accrual** (it *is* the ledger).
> Yardi's third-party owner statement is **cash** — you remit what you collected. Unit-owner statements
> must be cash-basis, and §5.5 says so explicitly.

*(Yardi's third tier — Investment Management, entity/fund ownership with capital and waterfall
distributions — is where our `asset_owner` pivot sits. It is **not** unit buyers and is out of scope.)*

---

## 2. Where Atriom stands — measured, not asserted

| Fact | Evidence |
|---|---|
| Ownership exists only at **property** level | `asset_owner` pivot → `User`, share + tenure ([AssetOwner.php](../../app/Models/AssetOwner.php)) |
| A `Unit` has **no owner**, and no "sold" state | `units.status` ∈ `vacant · reserved · occupied · maintenance` (`ValueSets:135`) |
| The AR party is **always `Tenant`** | `invoices.tenant_id`, `payments.tenant_id`, `credit_notes.tenant_id`, `deposit_transactions.tenant_id`, `post_dated_cheques.tenant_id` |
| **`invoices.lease_id` is NOT NULL** | `2024_01_01_000006_create_invoices_table.php:14` — the single hardest blocker |
| **`charges.lease_id` is NOT NULL**, cascade-delete | `create_charges_table.php` — nowhere to hang an owner's صيانة schedule |
| **CAM allocations are keyed by `lease_id`** | `CamReconciliationService:53` — an owned unit cannot participate in a pool |
| The billing engine is **typed to `Lease`**, not to an interface | every signature in `MonthlyBillingService` |
| Zero docs coverage | `grep -i "strata\|condo\|freehold\|تمليك"` over `docs/` → nothing |

**One live consequence, independent of this plan:** [module 32](../modules/32-owner-statements.md)
apportions the *entire property P&L* to `asset_owner`. The day a unit is sold, the income from that unit
stops being Jawad's — and the statement would still hand him 100% of it. **Selling units breaks owner
statements' arithmetic, not just its coverage.** Phase 5 fixes this; until then it is a known wrong number.

---

## 3. Naming — keeping the two owners apart

The system already has "owner" meaning *mall owner* (Jawad). Adding a second meaning silently is how a
maintainer writes `$asset->owners` when they meant `$unit->owners`.

**Decision: qualify the vocabulary; do not mass-rename tables.** A rename of `owner_statements` /
`OwnerStatementRun` / `owner-statements.*` permissions would touch the GL registry, activity vocabulary,
handbook datasets, translations and ~100 files for zero behaviour change. That is churn, not clarity.

| Concept | Code | UI label EN | UI label AR |
|---|---|---|---|
| Mall/property owner (existing) | `AssetOwner`, `OwnerStatement`, role `owner` | **Property Owner** | **مالك العقار** |
| Unit buyer (new) | `UnitOwnership`, `unit-owners.*` | **Unit Owner** | **مالك وحدة** |

Two cheap renames that *do* earn their diff, done in Phase 0:

- `Asset::owners()` → `Asset::propertyOwners()` (and `User::ownedAssets()` → `ownedProperties()`).
- Every `owner`-facing translation key in `lang/{en,ar}` gets the qualifier, so the panel never says a
  bare "Owner" again.

The new concept is unambiguous by construction — nothing is called plain `Owner`.

---

## 4. The core design decision: who can hold a receivable?

Three candidates were considered.

| | Blast radius | Verdict |
|---|---|---|
| **(a)** Polymorphic invoice counterparty (`billable_type`/`billable_id`) | All four AR settlement channels, both over-allocation guards, every GL journalizer, portal, API, PDC, credit notes | **Rejected** — the highest-risk change in the codebase, for a party that behaves identically |
| **(b)** A separate `unit_owner_invoices` table | Forks AR: second ageing, second statement, second GL path, second void/credit workflow | **Rejected** — two truths about the same money |
| **(c)** *Yardi's answer* — the owner **is** a customer record of the same type | `tenants` gains `party_type`; `invoices.lease_id` becomes nullable | **Chosen** |

> **The rule to carry over:** Yardi has no "tenant-only" receivable. A unit is billable to *whoever holds
> it* — owner or tenant — because both are the same kind of customer record. Assessments, portal, ageing,
> transfer and the GL all follow from that one fact.

Concretely: **`tenants` is the AR party table; `party_type` says which kind.** A unit owner is a `Tenant`
row with `party_type = 'unit_owner'`, surfaced through its own Filament resource with a scoped query. No
new auth, no new AR, no new GL engine. VAT, ageing, late fees, credit notes, PDC, payments, the portal and
the mobile API all keep working because none of them ever asked *why* the party owes money.

The one honest cost: the word "tenant" in the code now means "AR counterparty", not "retailer". That is
recorded as a one-line invariant in `CLAUDE.md` and in [modules/02](../modules/02-tenants.md), and the UI
never shows the word for an owner.

---

## 5. The design

### 5.1 `unit_ownerships` — a peer of `Lease`

The ownership record. Modelled on `Lease` deliberately: same shape, same lifecycle discipline.

| Column | Notes |
|---|---|
| `asset_id`, `unit_id`, `tenant_id` | property isolation · the unit · the owner party |
| `ownership_share_pct` | co-owners (spouses, partners); sums to 100 per unit per date |
| `participation_pct` *(nullable)* | Yardi's **participation interest** — the assessment/CAM basis when the deed states one; null = fall back to area |
| `management_mode` | `self_occupied` · `self_let` · `operator_managed` · `vacant` — the four states of §6 |
| `status` | `reserved` · `contracted` · `handed_over` · `transferred` |
| `purchase_contract_number`, `purchase_date`, `purchase_price`, `handover_date` | the sale paperwork |
| `started_at` / `ended_at` | tenure, inclusive bounds. **A resale sets `ended_at`; it never deletes the row** — same rule as `AssetOwner`, and for the same reason (issued statements lose their basis) |
| `management_fee_pct`, `fee_basis`, `remittance_frequency` *(nullable)* | folded in rather than a separate `management_agreements` table — it is 1:1 with the ownership in practice (KISS; split it the day it isn't) |
| `payment_terms_days`, `currency`, `notes` | |

Documents (sale contract, ID, power of attorney) via a medialibrary collection — **`useDisk('local')`**,
non-negotiable (`MediaPrivacyConformanceTest`).

Registrations required in the same commit: `PropertyIsolation` (property-owned), `DeletionPolicy`
(`WHEN_UNUSED`, `blocked_by` = `invoices`, `charges`, `leases`), `SearchPolicy`, `ScreenGuides`,
`FieldHelp`, `ChangeImpact`, `ActivityVocabulary`, `ValueSets` for all four string sets.

### 5.2 Charges → agreement-bound, not lease-bound

`charges` gains a nullable `unit_ownership_id`; `lease_id` becomes nullable; **exactly one must be set**
(model-level invariant + a `ChargeAgreementConformanceTest`). The `cascadeOnDelete` on `lease_id` is
retained for leases and *not* copied to ownerships — an ownership is `WHEN_UNUSED`, so it can never be
deleted out from under a charge row.

New `charges.type` values: `assessment` (صيانة), `sinking_fund`, `special_assessment`. New charge codes
with their posting roles — which is the whole GL story, see §5.4.

`ChargeScheduleService` gets the same `Lease` → `BillableAgreement` retype as the billing engine.

### 5.3 Invoicing an owner

`invoices.lease_id` becomes nullable; a nullable `unit_ownership_id` is added; exactly one is set.
Measured blast radius: **52 references to `->lease_id`, 14 to `$invoice->lease`** — tractable, and each is
audited in Phase 2 rather than assumed safe.

Two nullable FKs rather than a morph, on purpose: every existing query keeps working, eager loads stay
typed, and `tenant_id` — which all four settlement channels and every journalizer actually read — stays
NOT NULL and unchanged.

**VAT:** an assessment is a *service supply*, so it is taxable in full. There is no VAT-exempt base rent in
an owner's bill — an owner invoice is 100% taxable where a tenant's is mostly not. Handled entirely by the
charge code's `tax_code`; no new code path. Subject to the accountant's ruling (§8, Q6).

Late fees, ageing, statements, credit notes, PDC, the portal and the mobile API all inherit with **no
change**, because they key on `tenant_id`.

### 5.4 The GL — almost nothing new

Because the assessment is an ordinary `Invoice`, `InvoiceJournalizer` already posts it. What decides the
credit account is the **charge code's posting role**, which is data. So:

- Assessment → `Dr AR / Cr service-charge income` — an existing role, zero code.
- **Sinking fund → `Dr AR / Cr sinking-fund liability`** — money collected for future capital works is
  **not revenue**. This needs one new posting role + GL account, and the accountant's sign-off. It is the
  KISS stand-in for Yardi's separate reserve book: a liability account, not a second book.
- Unit-owner statement run (Phase 5) → **one** new `LedgerPoster::JOURNALIZERS` line + its
  `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` entry. Remittance reuses the existing `Disbursement`.

Per the house rule, each new source needs at least one test driving **the real service plus the sweep** and
asserting the tie-out — not `LedgerPoster::post()` directly.

### 5.5 The agency side — fee, statement, remittance

For `management_mode = operator_managed`, the unit is let under a normal `Lease` (§5.6) and the operator
owes the owner the net:

```
gross collected (cash, this period)
  − management fee (fee_pct × collected, per the agreement)
  − assessments & arrears owed by the owner
  − recharged expenses (repairs the operator paid for the unit)
  = net payable → Disbursement
```

**Cash-basis**, unlike module 32. You remit what you collected; a tenant who did not pay does not create an
owner payable. Stated here because getting it wrong pays out money that has not arrived.

The fee is exactly the one module 32 [deferred in v1](../modules/32-owner-statements.md) — this is where it
gets built, at unit level, and the property-level fee can later reuse the same calculator.

### 5.6 Owner-let leases

`leases` gains a nullable `unit_ownership_id` ("this lease sits under this ownership") and a
`revenue_mode` ∈ `operator_collects` · `owner_collects`.

- `owner_collects` (self-let): the lease is recorded for **occupancy, violations, SLA, fit-out and mall
  rules**, and raises **no rent invoice**. `MonthlyBillingService` skips it; the tenant is still a real
  tenant everywhere else.
- `operator_collects` (rental pool): completely ordinary lease billing, plus §5.5 on top.

**The owner stays liable for the assessment in both cases** — Yardi's rule, and the one that matters when
the lessee walks out mid-year.

### 5.7 CAM & participation interest

An owned unit occupies common area and must carry its share, or the pool's denominator lies and
`landlord_unrecovered` absorbs the gap silently.

`CamReconciliationService` participants become agreements rather than leases (the Phase 0 retype), and a
new `denominator_basis` option resolves each participant's share from `participation_pct` when present,
falling back to area exactly as today. Every existing basis keeps its current answer — the frozen-share
re-run guard is untouched.

### 5.8 Transfer / resale

`TransferUnitOwnershipService`: sets the seller's `ended_at`, opens the buyer's tenure, and produces the
**transfer statement** (Yardi's estoppel/resale certificate) — arrears at the transfer date, prepaid
assessments, sinking-fund balance attributable to the unit.

It **refuses on an outstanding balance** unless the operator supplies an explicit reason, which is recorded.
A `DomainException`, so it renders as a toast, not a 500.

### 5.9 Portal

Reuse the **tenant portal**. A unit owner logs in as a `TenantUser` under their `Tenant` row — zero new
auth, zero new panel. Navigation becomes `party_type`-aware: an owner sees Units, Invoices, Payments,
Statements and Requests; not Leases or Sales Declarations. (Memory of the removed `/owner` panel applies:
a third panel is a maintenance tax nobody pays back.)

---

## 6. The four owner states (the acceptance matrix)

Every phase is tested against these four, because each is a different money flow.

| State | Pays | Receives | System behaviour |
|---|---|---|---|
| **مالك شاغل** occupies & trades himself | assessment + marketing + utilities | — | Billed with **no lease**; bound by violations, fit-out, opening hours |
| **مالك مؤجر بنفسه** lets it himself | assessment (owner liable) | rent, directly | Lease recorded, `revenue_mode = owner_collects`, **no rent invoice** |
| **مالك في pool الإدارة** operator lets it | assessment + management fee | net rent | Full agency: collect, deduct, remit, statement |
| **وحدة فاضية مملوكة** vacant, owned | assessment only | — | Same ageing/late-fee machinery as a tenant — the hardest collection line in the building |

---

## 7. The plan

Phase 0 is a refactor. It is scoped to **exactly the seams phases 1–5 stand on** — no general cleanup —
and every step is behaviour-neutral with the suite green before and after.

### Phase 0 — enabling refactor (behaviour-neutral)

**0.1 · One seam for raising an invoice. ✅ SHIPPED 2026-08-15.** Eight services hand-built the invoice header —
`MonthlyBilling`, `BillMeterReading`, `BillViolationFine`, `LateFee`, `PercentageRentCalculation`,
`CamReconciliation` (×2), `BillBouncedChequeFee` — each repeating `'paid_amount' => 0` and a hand-computed
`'balance'`, i.e. re-stating by hand the two fields `Invoice::recomputeTotals()` owns. Extract
`IssueInvoiceService`. *Payoff:* the money invariant gets one enforcement point instead of eight, and
Phase 2's owner invoice becomes a caller rather than a ninth copy.

*Outcome.* `app/Services/IssueInvoiceService.php` + `tests/Feature/IssueInvoiceServiceTest.php`; the
service is now the **only** place in `app/` that builds an invoice, and the test sweeps for that rather
than trusting it. Three callers keep an explicit override — the debtor on a violation/cheque/late fee,
the currency of a penalised debt, and the monthly run's not-born-overdue due date — each passed by name
rather than silently absorbed. Verified: 259 tests across all eight touched services plus 67 conformance
assertions, all green. One real difference, in the CAM fee-only invoice: it used to be created at 0/0/0
and corrected a moment later by the item hook, and is now raised from its line, so its first persisted
state is already the true one. Same final state, one fewer momentarily-wrong row.

**0.2 · `BillableAgreement` contract.** `MonthlyBillingService` and `ChargeScheduleService` are typed to
the concrete `Lease` in every signature, so they can serve nothing else. Extract a **narrow** interface —
`billingTenantId()`, `assetId()`, `currency()`, `paymentTermsDays()`, `activeChargesOn()`,
`isBillableForPeriod()`, `billingCycleMonths()` — implemented by `Lease` now and `UnitOwnership` in
Phase 2. Lease-only concepts (fit-out abatement, holdover, percentage rent, straight-line rent) stay
behind `Lease` and are **not** dragged into the interface.

**0.3 · Split `Lease`.** 1,360 lines and ~60 public methods spanning eight responsibilities. Pure moves
into `Models\Concerns`: `HasLeaseUnits` (units/area/remeasure), `HasRentTerms` (flat vs rate, escalation),
`HasCamTerms` (ceilings, carry-forward, stated share), `HasBillingEligibility` (billable-for-period,
fit-out, abatement), `HasHoldover`. No logic changes. *Payoff:* this is the file most likely to be touched
by an unrelated change and break something — which is the user's stated concern.

**0.4 · Filament: extract what actually repeats.** Measured: the property column is declared **29×** and a
`status` column **31×** across 50 resources. The property column is pure duplication → one shared
`Support\Filament\Columns::property()`. Status badges have per-resource colour maps → **left alone**;
extracting them would be a config-object abstraction that reads worse than the duplication.

**Deliberately NOT in Phase 0** (churn > value): re-namespacing the 76 flat files under `app/Support`;
splitting `ReportService` (954) and `CamReconciliationService` (858), neither of which this feature
touches; any `Tenant` → `Party` rename.

### Phases 1–6

| Phase | Deliverable | Ends when |
|---|---|---|
| **1 · Ownership record** | `tenants.party_type`; `unit_ownerships` + model + service; Unit Owners resource; unit "sold" state; all eight registries + gates; [modules/37](../modules/) doc | An operator can record who bought which unit, at what share, with the contract on file — and a resale is a tenure end, not a delete |
| **2 · Assessments** | Charges & invoices agreement-bound; `UnitOwnership implements BillableAgreement`; monthly run bills ownerships; sinking-fund charge code + liability role; VAT | A vacant owned unit and an owner-occupier are both invoiced صيانة monthly, age, attract late fees, and post to the GL correctly |
| **3 · CAM participation** | Owned units in the pool; `participation_pct` basis | Pool tie-out holds with a mixed owned/leased building; every existing basis answers identically |
| **4 · Owner-let leases** | `leases.unit_ownership_id` + `revenue_mode` | A self-let unit's tenant is fully governed (violations, SLA, fit-out) while raising no rent invoice |
| **5 · Agency** | Management fee, cash-basis unit-owner statement, remittance, one new GL source; **module 32's apportionment corrected for sold units** | The operator can collect rent for an owner, keep its fee, and pay the net with an audit trail |
| **6 · Portal & transfer** | `party_type`-aware portal; `TransferUnitOwnershipService` + transfer/estoppel statement | An owner logs in, sees and pays their assessment; a resale produces a certificate of what is owed |

House rules that apply to every phase: docs updated in the **same commit**; a regression test in
`tests/Feature/Regression/` for every bug fixed; the four states of §6 covered in
`tests/Feature/Scenarios/`; `pest --parallel` green before every push.

---

## 8. Open questions for Eltizam / Jawad

Phase 1 can start on assumptions; **phases 2, 3 and 5 cannot finish without answers.**

1. **Does this mall actually sell units, and which?** Is the sale freehold (تمليك) or usufruct / long lease
   (حق انتفاع)? The second is legally a lease and might not need any of this.
2. **What is the assessment basis** — per sqm, % of purchase price, or a participation % stated in the
   deed? (Drives §5.7; per-sqm is the cheapest, deed-% is the Yardi-native one.)
3. **Is there a sinking / reserve fund (صندوق صيانة)?** Separate bank account? Who authorises spending it?
4. **Management fee** for the rental pool — what %, and on *collected* or *billed* rent? (Yardi's default
   is collected; billing on billed rent pays the operator for money it hasn't got.)
5. **Whom does an owner-let tenant sign with** — the owner or Eltizam? And who enforces mall rules against
   them?
6. **VAT on assessments** — the accountant's ruling. Assumed taxable in full as a service supply.
7. **Non-payment by an owner** — there is no eviction lever. Lien? Access suspension? Late fee only?
8. **On resale, does the operator approve** the buyer, or hold a right of first refusal?

---

## 9. Risks

- **Module 32 pays out too much from the day the first unit is sold** (§2). Known before this plan;
  fixed in Phase 5. If a unit sells before Phase 5 lands, the property-owner statement must be flagged
  manually.
- **Nullable `lease_id`** — 52 `->lease_id` and 14 `$invoice->lease` sites. Each is audited in Phase 2;
  none is assumed safe.
- **`tenants` meaning "AR party"** — the deliberate cost of §4. Mitigated by an invariant line in
  `CLAUDE.md`, the module doc, and UI labels that never show the word to an owner.
- **Phase 0 scope creep** — the refactor is bounded to three extractions and one column. Anything else
  found on the way gets written down, not done.
