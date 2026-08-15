# 08 · Unit Owners (ملّاك الوحدات) — design & phased plan

> **Status:** phase 0 CLOSED; phases 1, 2a and 2b SHIPPED (2026-08-15). Phases 3-6 outstanding.
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

### 4.1 Configurable the way Yardi is configurable

The operator cannot yet answer §8's questions, so the module is built to take them as **data** rather
than waiting on them. That decision needs a precise definition, because "make it configurable" is also
the most reliable way to overengineer a system.

**Yardi is not configurable because it is meta-programmed.** It has no rules engine, no EAV
custom-attribute bag, no workflow designer. Its flexibility comes from *a rich fixed schema plus many
lookup tables*: the party is a customer record, what you bill is a charge code, how you apportion is a
named method stored on the pool, what tax applies is a dated catalogue row, which account it hits is a
posting role. **You configure by adding rows, not by branching code** — and that is why a Yardi install
can still answer "why was this billed?".

**Atriom already has that spine.** This is the finding that makes the whole approach cheap — the work is
not to invent configurability, it is to make the new module plug into what exists instead of hardcoding
answers nobody has:

| Mechanism | What it already configures with no deploy |
|---|---|
| `PropertySettings::OVERRIDABLE` — lease → property → portfolio, allow-listed, and conformance-tested that every entry is actually *wired* | late-fee %, grace days, payment terms, billing day, per property |
| `ChargeCode` + `PostingRoles::ROLES` | what is billed, and which account it lands in |
| `tax_codes` + `tax_rates` — dated rungs resolved for the DOCUMENT's date | whether a supply is taxed, and at what rate, including a rise entered in advance |
| `CamExpensePool::{expense,estimate,denominator}_basis` | the apportionment METHOD, stored on the pool row |
| `ValueSets` | every string set, refused out-of-set on save |

The last one is the pattern to copy most directly: CAM already stores *how to allocate* as a field on the
row rather than as a branch in the service. An assessment basis is the same shape, so §5.7 does not invent
a mechanism — it reuses a proven one.

**What stays fixed, on purpose.** Making any of these configurable would not be flexibility, it would be
a system that cannot explain itself:

- the party holds the ledger — one AR, never a parallel owner-AR;
- the invoice header follows its lines;
- the four settlement channels, and `recomputeTotals()` as their single source of truth;
- double entry, dispatched from `LedgerPoster::JOURNALIZERS`;
- property isolation;
- the closed-period posting guard.

**And what will not be built:** a rules engine, EAV custom fields, strategy classes resolved from a config
string, or a workflow designer. Every one of them trades an answerable audit trail for a flexibility the
operator has not asked for.

**The one unknown configuration cannot defer** is whether a party can be billed with *no lease at all*.
That is `invoices.lease_id` becoming nullable — a schema decision, not a setting, and it is made in
Phase 2 regardless of how §8 is answered.

---

## 5. The design

### 5.1 `unit_ownerships` — a peer of `Lease`

The ownership record. Modelled on `Lease` deliberately: same shape, same lifecycle discipline.

| Column | Notes |
|---|---|
| `asset_id`, `unit_id`, `tenant_id` | property isolation · the unit · the owner party |
| `tenure_type` | `freehold` · `usufruct` · `leasehold_sale` — §8 Q1 as a row. Billing is identical across all three; only the tenure bounds differ, which `started_at`/`ended_at` already carry |
| `assessment_basis` | `area` · `participation` · `purchase_value` · `stated` — §8 Q2 as a row, the same shape as `CamExpensePool::denominator_basis`. Defaults to `area`, i.e. today's behaviour |
| `ownership_share_pct` | co-owners (spouses, partners); sums to 100 per unit per date |
| `participation_pct` *(nullable)* | Yardi's **participation interest** — read when `assessment_basis = participation`; null falls back to area |
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

### 5.2b ⚠️ Phase 2 blocker found 2026-08-15: an invoice finds its property THROUGH the lease

Auditing the nullable-`lease_id` change turned up something the plan had not costed. **Four separate
places derive an invoice's property by walking `lease → unit → asset`**, and every one of them
returns null for an owner invoice that has no lease:

| Site | What breaks when `lease_id` is null |
|---|---|
| `PropertyIsolation::OWNED[Invoice] = 'lease.unit'` | The invoice falls out of **every property-scoped query** — an receivable that exists but nobody can see. An isolation hole, not a cosmetic gap |
| `InvoiceJournalizer:100` — `$invoice->lease?->unit?->asset_id` | The journal entry posts with **no property dimension**, so per-property P&L and the owner statement silently stop seeing that revenue |
| `Invoice::creating:434` — number prefix | The assessment is numbered `INV-AW-…` regardless of which mall it belongs to |
| `InvoiceItem:100` — marketing-levy accrual | The levy stops finding its budget |

**Recommended fix: denormalize `invoices.asset_id`**, backfilled from `lease.unit.asset_id`, and move
`Invoice` from the `'lease.unit'` chain to `null` (direct column) in the isolation registry. This is
**already the house pattern for exactly this reason** — `Disbursement` and `OwnerStatement` both carry
a denormalized `asset_id` with the note *"journalizer reads own row"*. It is also what Yardi does: AR
belongs to a property by construction rather than by inference through whatever document raised it.

**Why this is not a small change.** It alters how *every* invoice in the system finds its property —
the isolation registry, the GL dimension, document numbering and the levy hook — plus a backfill over
every existing row. It wants its own focused pass with the GL tie-out re-run, not a corner of the
assessment work. Sequenced as **Phase 2a**, before anything writes `unit_ownership_id`.

*The lesson worth keeping: `lease_id` was NOT NULL, so four different pieces of code were entitled to
treat the lease as the route to the property. Relaxing a NOT NULL is never only a schema change — it
is a change to every inference that column licensed.*

#### 2a outcome (2026-08-15) — and what deliberately stayed behind

`invoices.asset_id` shipped, backfilled, with the four inference points repointed at it and the
isolation registry shortened (`Invoice` → direct, `InvoiceItem` → `invoice`, `Payment` → `invoices`).

**It confirmed the pattern rather than invented it: 8 of 10 money records already carried their own
`asset_id`** — deposits, cheques, disbursements, owner statements, journal entries, expenses, vendor
bills, write-offs. Invoice, Payment and CreditNote were the three that still inferred. Invoice is now
the fourth to carry it.

**It also fixed a live bug that predates unit owners entirely.** `units.asset_id` is editable, so a
unit can be re-homed to another mall — and while an invoice inferred its property through
`lease → unit → asset`, re-homing silently re-parented *every invoice that unit ever raised* (issued,
paid, GL-posted) into the new mall's reports and owner statement. The journal entry never moved with
them, because `journal_entries.asset_id` has always been its own column. Sub-ledger and ledger would
have disagreed, in opposite directions, with nothing raising a hand. Pinned by
`InvoiceCarriesItsOwnPropertyTest`.

**~25 READ sites still scope invoices through `lease.unit`** — `ReportService` (×5), `VatReturnService`
(×2), five widgets, the tenant/asset statement PDFs, `BooksReconciliationService`, and several form
pickers. They are **correct today and stay correct for every lease-raised invoice**, which is exactly
why they were not swept now: both forms return the identical answer until an owner invoice exists, so
the change could not be tested and a refactor that cannot be told right from wrong is one to defer.
They are migrated in **2b**, against a real owner invoice that makes the difference observable.

Regenerate the list with:
`grep -rn "lease\.unit" app/ | grep -i invoice`

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

**0.2 · `BillableAgreement` contract. ✅ SHIPPED 2026-08-15.** The billing engine was typed to the
concrete `Lease` in every signature, so it could serve nothing else. `App\Contracts\BillableAgreement`
is the narrow cut — `billingTenantId()`, `assetId()`, `billingCurrency()`, `paymentTermsDays()`,
`billingCycleMonths()`, `isBillableForPeriod()`, `charges()`, `invoiceLinkAttributes()`. Lease-only
concepts (fit-out abatement, holdover, percentage rent, straight-line rent, CAM ceilings, escalations)
stay behind `Lease` and are **not** dragged into the interface — widening `Lease` to mean "or an
ownership" would make every one of those rules answer *not applicable* at runtime instead of at the type
level.

*Outcome.* `Lease implements BillableAgreement` and `IssueInvoiceService` takes the interface, so the
seam Phase 2 needs is already in place: an ownership stamps `unit_ownership_id` from
`invoiceLinkAttributes()` and nothing in the service, or downstream of it, changes.

**`Lease` already satisfied five of the eight methods before the interface existed** — `assetId`,
`paymentTermsDays`, `billingCycleMonths`, `isBillableForPeriod`, `charges`, with identical signatures.
That is the evidence the seam was found rather than invented; only three had to be written.

*Deliberately deferred:* `MonthlyBillingService` stays typed to `Lease`. It is soaked in the lease-only
concepts above, and the honest way to learn which of them an ownership genuinely needs is to have one —
generalizing it now, against no second implementer, would be guessing. Phase 2 does it with the
implementer in hand. Verified: 254 tests green across the engine, the lease lifecycle and eight
conformance gates.

**0.3 · Split `Lease` — ❌ RETIRED 2026-08-15, not done.** The plan was to move 1,360 lines into five
concerns. On reflection that is **relocation, not decoupling**: `Lease` would still expose ~60 public
methods, still be the same surface to every consumer, and the diff would be wide across the model half
the system touches. The structural win — the one that actually lets a new agreement type be added
without editing the engine — was 0.2, and it is done. Splitting for file size is cosmetic, and the
codebase's own standard is that a refactor must pay for something.

**0.4 · Extract the repeated Filament property column — ❌ RETIRED 2026-08-15: the premise was wrong.**
The raw count said `asset.name` was declared 29× and looked like pure duplication. Reading the
declarations, they are not: they differ in badge, sortable, toggleable and placeholder — all real
per-table decisions — and they use six different label keys, which looked like the actual bug.
Then the keys were checked, and **all six render the identical word in both languages** ("Property" /
"العقار"). So there is no user-visible inconsistency and no duplication worth extracting; converging
the keys would be churn with no behaviour or UX change. Retired rather than done, and recorded here so
nobody re-derives it from the same misleading count.

**Phase 0 is therefore CLOSED at 0.1 + 0.2** — the two steps that carry weight.

*The general lesson, since it cost two investigations: a `grep | sort | uniq -c` count identifies
candidates, never findings. Both retired items looked compelling as counts and dissolved on reading the
code — the same shape as the house rule that absence claims are usually false.*

**Deliberately NOT in Phase 0** (churn > value): re-namespacing the 76 flat files under `app/Support`;
splitting `ReportService` (954) and `CamReconciliationService` (858), neither of which this feature
touches; any `Tenant` → `Party` rename.

### Phases 1–6

| Phase | Deliverable | Ends when |
|---|---|---|
| **1 · Ownership record** — ✅ **SHIPPED 2026-08-15** | `tenants.party_type`; `unit_ownerships` + model; six config enums; the admin resource with property scoping + `unit_ownerships.*` RBAC; bilingual screen guide, labels, hints and activity vocabulary; search indexing; every registry + gate; [modules/37](../modules/37-unit-owners.md). *No unit "sold" state — occupancy and ownership are different axes; see modules/37 §7* | An operator can record who bought which unit, at what share, with the contract on file — and a resale is a tenure end, not a delete |
| **2a · Give an invoice its own property** — ✅ **SHIPPED 2026-08-15** *(added 2026-08-15 — see §5.2b)* | `invoices.asset_id` denormalized + backfilled; `Invoice` moves from the `'lease.unit'` isolation chain to a direct column; the journalizer, number prefix and levy hook read the row instead of inferring through the lease. **CreditNote, InvoiceWriteOff, PaymentJournalizer and VoidPaymentService had the same shape and were corrected with it** — the payment journalizer worst, crediting AR at portfolio level against a property-level debit, drift a portfolio-wide tie-out cannot see | Every existing invoice answers "which property" from its own row, GL tie-out unchanged — **prerequisite for anything below** |
| **2b · Assessments** — ✅ **SHIPPED 2026-08-15** | Charges & invoices agreement-bound; `UnitOwnership implements BillableAgreement`; the ownership billing run; sinking-fund charge code + liability role; VAT | A vacant owned unit and an owner-occupier are both invoiced صيانة monthly, age, attract late fees, and post to the GL correctly |
| **3 · CAM participation** — ✅ **SHIPPED 2026-08-15** | Owned units are pool participants; `participation_pct` basis; frozen shares keyed by agreement (`L:12`/`O:7`) because a `lease_id` pluck collapsed every ownership onto `null`; the cap/ceiling/carry-forward block stays lease-only, since those are lease terms | Pool tie-out holds with a mixed owned/leased building; every existing basis answers identically |
| **4 · Owner-let leases** — ✅ **SHIPPED 2026-08-15** | `leases.unit_ownership_id`, and **only** that — the `revenue_mode` flag this row used to name was mine, not Voyager's, and was dropped: Yardi expresses who keeps the rent in the management agreement, not on the lease | A self-let unit's tenant is fully governed (violations, SLA, fit-out) while the ownership it sits under is visible on the lease |
| **5 · Agency** — 🔴 **BLOCKED** on Q-OWN-1 (management-fee income account) + Q-OWN-2 (sinking-fund liability account); [OPEN-QUESTIONS §B2](../OPEN-QUESTIONS.md) | Management fee, cash-basis unit-owner statement, remittance, one new GL source; **module 32's apportionment corrected for sold units** | The operator can collect rent for an owner, keep its fee, and pay the net with an audit trail |
| **6 · Portal & transfer** — ✅ **SHIPPED 2026-08-15** | `party_type`-aware portal; `TransferUnitOwnershipService` + transfer/estoppel statement. Found two lease-chain assumptions in the portal: an owner's CAM allocation was scoped `whereHas('lease')` and so invisible to him while appearing on his invoice, and he was offered a Leases screen he can have no rows for | An owner logs in, sees and pays their assessment; a resale produces a certificate of what is owed |

House rules that apply to every phase: docs updated in the **same commit**; a regression test in
`tests/Feature/Regression/` for every bug fixed; the four states of §6 covered in
`tests/Feature/Scenarios/`; `pest --parallel` green before every push.

---

## 8. The open questions, and where each one becomes a row

**Revised 2026-08-15 — the operator cannot answer these yet, and per §4.1 the module takes them as data.**
The build is therefore *not blocked* on any of them. Each becomes configuration on a mechanism that
already exists, and each has a default that is safe to ship and cheap to change:

| # | Question | Becomes | Default shipped |
|---|---|---|---|
| 1 | Freehold (تمليك) or usufruct (حق انتفاع)? | `unit_ownerships.tenure_type` ∈ `freehold` · `usufruct` · `leasehold_sale` (`ValueSets`) | `freehold` — and usufruct behaves identically for billing, differing only in tenure bounds |
| 2 | Assessment basis — per m², % of purchase price, deed %? | `assessment_basis` on the ownership, mirroring `CamExpensePool::denominator_basis` | `area` — today's CAM behaviour, so a mixed building reconciles the way it already does |
| 3 | Sinking fund (صندوق صيانة)? | a `ChargeCode` whose posting role is a **liability**, not revenue | absent — no row, no fund; adding one is a row + an account |
| 4 | Management fee %, on collected or billed rent? | `management_fee_pct` + `fee_basis` on the ownership, mirroring `estimate_basis` | `collected` (Yardi's default — billing on billed rent pays the operator for money it has not got) |
| 5 | Does an owner-let tenant sign with the owner or Eltizam? | `leases.revenue_mode` ∈ `operator_collects` · `owner_collects` | `operator_collects` — today's only behaviour |
| 6 | VAT on assessments? | `charge_codes.tax_code` → the dated `tax_rates` catalogue | **already built; zero new work.** The accountant changes a row |
| 7 | Non-payment lever against an owner? | the existing `PropertySettings` late-fee tier already applies | late fee + ageing, exactly as for a tenant |
| 8 | Operator approval on resale? | a `PropertySettings::OVERRIDABLE` flag on the transfer workflow | off |

Six of the eight need no new mechanism at all, and #6 needs no new *anything*. What remains genuinely
outstanding is not a code question:

- **The GL accounts** for a sinking-fund liability and management-fee income — the accountant's, and the
  same blocker as [the chart of accounts](../accounting/ACCOUNTANT-BRIEFING.md), not this module's.
- **Whether units are being sold at all**, which decides the *priority* of phases 1–6, not their design.
  Note §2's live consequence stands either way.

### The original questions, for the record

Phase 1 can start on assumptions; **phases 2, 3 and 5 previously could not finish without answers** —
which is precisely why they were converted above rather than waited on.

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
