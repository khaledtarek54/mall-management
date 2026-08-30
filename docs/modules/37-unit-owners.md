# 37 · Unit Owners (ملّاك الوحدات)

> **⚠️ An ownership could never be given a schedule, so no owner was ever billed (fixed 2026-08-19).**
> `BillUnitOwnershipsService` bills an ownership from its `charges` rows and skips it when there are
> none — and **no surface in the application created such a row**. `UnitOwnershipResource` had no
> relation managers, its form has no repeater, `ChargeScheduleRelationManager` is mounted only on
> `LeaseResource`, and `ChargeImporter` resolves a `lease_reference` only. The only ownerships with a
> schedule were the ones `DemoSeeder` wrote directly.
>
> So an operator registered a sold unit, the ownership read `handed_over`, `isBillableForPeriod()`
> returned true — and every month the run reported it as an unremarkable `skipped`. **Every owner
> onboarded through the panel went un-billed, permanently and silently.** The third instance of a
> pattern this project has already named twice: the assessment run itself shipped unscheduled, and
> `RemeasureUnitService` shipped with no caller. `ServiceReachability` proves a SERVICE can be
> started; nothing proved the DATA it needs can be created.
>
> Four parts to the fix, and each fails independently:
> - **[`UnitOwnershipChargesRelationManager`](../../app/Filament/Admin/RelationManagers/UnitOwnershipChargesRelationManager.php)**
>   — the assessment schedule, mounted on the resource. Deliberately NOT the lease's
>   `ChargeScheduleRelationManager`: that class types its owner record as a `Lease`, gates on
>   `leases.edit` and the lease's status, and excludes the three types a lease derives from its own
>   services. An ownership has none of that. Rows are **added and ended, never edited** — the same
>   discipline the lease schedule keeps, because an amount edited in place restates months already
>   billed and paid.
> - **`Charge::assertNoScheduleOverlap()` now keys on the AGREEMENT**, not on `lease_id`. It returned
>   early on `blank($lease_id)`, so an ownership's schedule was exempt from the one guard that stops a
>   charge being billed twice — unreachable while there was no schedule screen, and reachable the
>   moment there was.
> - **The run counts `unconfigured` separately from `skipped`.** A skip means "nothing to bill this
>   month"; a handed-over ownership in tenure with no schedule at all means "nobody is billing this
>   unit". `{"considered":8,"created":6,"skipped":2,"failed":0}` read like success.
>
> - **The import road, which was the same hole with a different door.** `ChargeImporter` resolved a
>   `lease_reference` only, so a migrating operator loading a portfolio of sold units ended up exactly
>   where the panel used to leave them. Rather than a second importer, `ChargeScheduleService` was
>   generalised from `Lease` to the `BillableAgreement` contract — it keys off `invoiceLinkAttributes()`
>   now, so a third agreement type needs no change there — and the importer gained an
>   `ownership_reference` column beside `lease_reference`, refusing a row that names both and a row
>   that names neither. It is mounted on the ownerships list as **Import assessments**.
>
> `BillableAgreementIsConfigurableConformanceTest` is the gate: for every `BillableAgreement` it
> requires a charges relation manager **and** an importer column, so the next agreement type cannot
> ship with one road open and the other closed. Pinned by `UnitOwnerAssessmentIsReachableTest`.

> **⚠️ A mid-month resale now rebalances the month (fixed 2026-08-19).** `billOne()` prorates on
> tenure and this doc claimed "a resale on the 10th bills the seller 10/30 and the buyer the rest".
> That is only true if the month is billed *after* the transfer is recorded. In the real sequence —
> the scheduled run raises the assessment on the 1st, the sale completes on the 11th — measured: the
> seller stood billed **3,000.00** for a month they owned 10 of 31 days of (967.74 owed), nothing
> ever corrected it (`Transferred` is not `isBillable()`, so a re-run skips them by design), and the
> buyer was billed **nothing** — then or later, because the transfer carried the terms but not the
> schedule.
>
> `TransferUnitOwnershipService` now, inside the same transaction: **credits the seller's unearned
> days** via `CreditUnearnedBillingService::forOwnershipTransfer()` — the lease side's own
> instrument, generalised to a `BillableAgreement`, so a mid-month move-out and a mid-month resale
> can never give back different amounts for the same shape of month — and **carries the recurring
> schedule to the buyer** from the transfer date, closing the seller's rows on their last owned day.
> One-offs are not carried: a one-off was an event on the seller's holding.
>
> **The schedule alone does not bill the transfer month (fixed 2026-08-23).** Carrying the schedule
> lets the buyer be billed from November onward — it does not raise 11–31 October, because the
> monthly run bills the CURRENT period and never goes back. So the seller was refunded those days
> and the buyer was never charged them, and the unit stayed short a third of a month, permanently
> and silently. A manual re-run recovered it and nothing asked anyone to do that.
>
> The transfer now raises the buyer's part itself, via `BillUnitOwnershipsService::billOne()` in the
> same transaction, and **only when that month was already billed** — otherwise the ordinary run
> bills the buyer and doing it here would raise the assessment twice. `billOne()` re-reads under its
> own lock, refuses a period already billed and prorates on tenure, so it cannot double-bill and
> cannot disagree with the scheduled run. The invoice is returned on the buyer as
> `transfer_buyer_invoice`, beside `transfer_credit_notes`.
>
> Verified end to end: seller 3,000.00 + buyer 2,032.26 − credit 2,032.26 = exactly one month.
> Pinned by `ResaleRebalancesTheMonthTest` and `03_resale_proration.php` (9/9).


> The buyer who bought a shop instead of renting one. A peer of the lease, not a variant of it —
> and **not** the mall owner ([module 32](32-owner-statements.md)), who is the opposite money direction.
>
> **Status: phases 0–4 and 6 SHIPPED (2026-08-16).** The register, the screen, the monthly صيانة run,
> the owner's share of CAM, the owner letting his own unit, and the owner portal with its resale
> certificate — an owner with no lease is billed, ages, posts to the ledger, and can now be paid.
> Outstanding: the management fee, the cash-basis owner statement and remittance (phase 5), which are
> blocked on two accounting answers rather than on code (§8).



### An owner can hold a parking bay (2026-08-19)

`UnitOwnership` holds rentable items through the same polymorphic pivot a lease does, and the bay's
charge joins this agreement's schedule — so it bills on the monthly صيانة assessment run alongside
the service charge. Voyager's condo model, where the owner IS the customer record that rentable
items are assigned to. Screen: a **Rentable items** tab on the ownership, deliberately identical to
the lease's. See [modules/35](35-rentable-items.md).


> **A part month of صيانة is priced by the PROPERTY's method (EG-29, 2026-08-23).** An ownership has
> no lease clause to state one, so `UnitOwnership::prorationMethod()` resolves the two tiers it does
> have — the property, then the portfolio — and `BillUnitOwnershipsService` threads that one answer
> through every line, including the arrears row. Not a gap: the assessment an owner pays is set by
> the operator's own schedule, not negotiated document by document the way a lease is. The
> termination credit reads the same method through `BillableAgreement`, so an ownership's credit
> cannot disagree with the assessment it credits.

## 1. Purpose & business context

Egyptian malls sell units (تمليك) as well as leasing them. The buyer pays no rent, but owes the
service charge (صيانة) whether he trades from the unit, lets it himself, hands it to the operator to
let for him, or leaves it empty. Until this module there was no way to record that he exists.

**Two owners, opposite directions — keep them apart.** A *property* owner (Jawad, module 32) is a
`User` who RECEIVES the property's net. A *unit* owner is a `Tenant` who PAYS the service charge. The
relation is `Asset::propertyOwners()`, renamed from `owners()` for exactly this reason: `$asset->owners`
beside `$unit->owners` was one keystroke from apportioning a statement to the wrong kind of owner.

## 2. Domain model

| Table | Model | Meaning |
|---|---|---|
| `unit_ownerships` | `UnitOwnership` | One party's holding of one unit, over a tenure |
| `tenants.party_type` | `PartyType` cast | Which kind of AR party a `tenants` row is |
| `invoices.unit_ownership_id` · `charges.unit_ownership_id` | — | Nullable, unwritten until phase 2 |

**The design decision: the owner IS a `tenants` row.** That table is the **AR party table** — it holds
whoever can owe the operator money. This is Yardi's own answer (its ledger belongs to a customer
record, and in the condo product the unit owner simply is that record type), and it is what lets
payments, credit notes, deposits, cheques, ageing, the portal and the mobile API serve an owner
without any of them learning that owners exist. The alternatives — a polymorphic invoice counterparty,
or a second invoice table — were rejected in [plan 08 §4](37-unit-owners.md).

The cost, stated plainly: **"tenant" means *counterparty* in the schema and *retailer* on screen.**
The UI never shows the word to an owner.

### The four owner states

`UnitManagementMode` — and each is a different money flow. All four owe the service charge; what
varies is the rent.

| Mode | Pays | Receives | Behaviour |
|---|---|---|---|
| `self_occupied` مالك شاغل | service charge | — | No lease at all; still bound by violations, fit-out, mall rules |
| `self_let` مالك مؤجر بنفسه | service charge (owner liable) | rent, directly | Lease recorded for occupancy/SLA; raises no rent invoice |
| `operator_managed` pool الإدارة | service charge + fee | net rent | Operator collects, keeps a fee, remits the net |
| `vacant` وحدة فاضية | service charge | — | The hardest line in the building to collect |

## 3. Business rules & invariants

- **A resale is a tenure end, never a delete.** `ended_at` closes the seller's row and a new row opens
  the buyer's. Deleting would strand every assessment invoice and statement that quoted it.
  `#[DeletableWhenUnused]` blocks on `invoices` and `charges`; the FK columns ship in phase 1
  precisely so that guard runs a real query rather than a latent one.
- **Handover is what starts the money**, not contract signature — the operator carries the unit's cost
  until the keys change hands. `UnitOwnershipStatus::HandedOver` is the billable state, and
  `isBillableOn()` is the one predicate combining that with the tenure.
- **A tenure cannot run backwards**, and equal is allowed — a sale that collapses on its own handover
  date stays recordable. Enforced at the model, so an import or API write is covered too.
- **`participation_pct` null falls back to area**, so an unconfigured deed is never a zero share.

## 4. Configuration — the unanswered questions are rows

The operator could not answer [plan 08 §8](37-unit-owners.md) when this was built, so each
question is data with a default that is **today's behaviour**. Nothing branches on a question nobody
has answered.

| Column | Enum | Default | Answers |
|---|---|---|---|
| `tenure_type` | `UnitTenureType` | `freehold` | تمليك vs حق انتفاع. Billing is identical across all three; only the tenure bounds differ |
| `assessment_basis` | `AssessmentBasis` | `area` | Per m² / deed participation / share of price / stated. `area` is what CAM already does |
| `management_mode` | `UnitManagementMode` | `vacant` | Which of the four states above |
| `management_fee_pct` · `fee_basis` | `ManagementFeeBasis` | null · `collected` | The agency fee. Collected, because billing on billed rent pays the operator for money it has not got |

`remittance_frequency` is deliberately **absent** until the agency phase reads it — a column an
operator can set that no code consults is the inert-configuration bug this codebase has been bitten
by before.

### Enum casts and `ValueSets` — which layer refuses

Both are registered, and they are not the same thing:

- **The cast refuses.** An out-of-set value raises a `ValueError` at assignment, before the row saves.
  It also gives the services a question (`->operatorCollectsRent()`) instead of a string literal.
- **`ValueSets` is the shared vocabulary** — what the importers read to build `Rule::in(...)` and what
  `NoDatabaseEnumsConformanceTest` checks the column against. It stays the *refusal* for every
  registered column that is not cast, which is most of them.

The registry declares these sets **as the enum class**, not as a copied list, so the two cannot drift.

> **Found while building this:** the two mechanisms had never been combined — the app's only
> enum-cast column (`tenant_requests.request_type`) was not registered in `ValueSets`. Combining them
> threw *"Object of class … could not be converted to string"* on **every save**, because a cast
> attribute hands back the enum instance. `ValueSets::guard()` now unwraps a `BackedEnum`.

## 5. Registries this module is in

`#[PropertyOwned]` (direct `asset_id` — the assessment sweep asks per property, and a join per
row is the N+1 the CAM path already had to fix) · `#[DeletableWhenUnused]` · `ValueSets` ·
`DocumentNumbering::TYPES` (`UO-{property}-{year}-0001`) · `ActivityVocabulary` (log name
`unit_ownership`, EN + AR).

## 6. The screen

`UnitOwnershipResource`, in the **Leasing** group beside Units and Leases — because an operator asking
"what is the position of A-102" gets either a lease or an ownership, and should not have to know which
before choosing a menu.

- **Property-scoped both ways.** Reads via `getEloquentQuery()`; writes re-validated by
  `assertAssetInScope()` on create AND edit, because Filament stamps `asset_id` on create only. The
  unit picker reads the FORM's `asset_id`, not the panel's current property — otherwise it offers
  nothing when the panel is not pinned to one mall.
- **RBAC** on `unit_ownerships.*`, granted to **leasing** (a unit is either let or sold, and the same
  team answers for both). Delete stays super_admin-only project-wide.
- **"Current owners only" is the default filter.** The register accumulates former owners by design,
  so "who owns this today" has to be the default view rather than a filter the operator remembers.
- **Conditional fields, driven by the enums**: `participation_pct` appears exactly when the chosen
  basis reads it (`AssessmentBasis::requiredColumn()`), the fee fields only when the operator manages
  the unit. A basis that needs a number nobody typed is the inert-configuration bug again.

## 7. Billing an owner (phase 2)

`BillUnitOwnershipsService` raises the monthly assessment.

**How it is triggered (wired 2026-08-18).** `billing:run-assessments` runs it, scheduled in
`routes/console.php` on the operator's billing day at **02:30** — half an hour after the lease run,
which it deliberately does not share a cache lock with (the two bill disjoint agreements). The manual
re-run is the **Run Owner Assessments** button on the Invoices list header, beside the lease run and
gated on the same `invoices.run_monthly_billing` right.

> Between module 37 shipping (August 2026) and 2026-08-18 **none of that existed**: the service had no
> caller outside `DemoSeeder` and the test suite, so no handed-over owner was ever billed in
> production. Its own docblock spoke of "the scheduled one" while no schedule called it. Pinned by
> `UiSweepUnreachableFunctionalityTest`.
 `invoices.lease_id` and `charges.lease_id`
are now nullable, and **exactly one** of `lease_id` / `unit_ownership_id` is set on each — enforced at
the model, not as a CHECK, because SQLite drops CHECKs on any later `->change()` to the table.

- **Handover is the trigger.** `UnitOwnershipStatus::HandedOver` + a tenure covering the period.
- **Proration and the invoice header are SHARED, not reimplemented.** The run calls
  `MonthlyBillingService::monthsCovered()` and `IssueInvoiceService` — the two rules that must never
  fork. A resale on the 10th bills the seller 10/31 and the buyer 21/31, summing to exactly one
  month: neither owner subsidises the other and the mall is not short.
- **A co-owner pays `ownership_share_pct` of the assessment**, not all of it.
- **VAT comes from the charge code**, resolved for the invoice's date — an assessment is a service
  supply and taxable in full, with no exempt base rent to net against.
- **The GL needs no new journalizer.** An assessment is an ordinary `Invoice`, so `InvoiceJournalizer`
  posts it; the credit account is the charge code's posting role, which is data.
- **Never a zero invoice** — an empty or fully-abated schedule produces no document rather than a
  0.00 one that ages and duns.

### Why this is a separate service from the lease run

`MonthlyBillingService` is 760 lines of lease law — fit-out grace, holdover, percentage rent,
straight-line rent, escalation ladders. An ownership has a tenure and a schedule and none of the rest.
Generalising it would make every one of those rules answer *not applicable* at runtime, on the one
path where a wrong answer bills the wrong person.

## 7b. CAM — a sold unit carries its share (phase 3)

An owner occupies common area, so he is a pool participant like any tenant.

**The design, because it is not obvious: an owner's monthly صيانة IS his CAM estimate.** A tenant
pays a monthly service-charge estimate and settles it annually against actuals; an owner pays a
monthly assessment. Same economic act, same `service_charge` charge type — which is exactly what
`estimateBilledFor()` sums. So an ownership joins as an ordinary participant whose `estimated_paid`
is the assessments it was billed that year, and it settles with a true-up like anybody else. No
parallel system.

What an ownership does NOT bring is CAM **clause** machinery — ceiling, controllable carve-out,
banked carry-forward. Those are negotiated into a lease; a sale has none, so the cap block is skipped
rather than answered with neutral values.

**What it DOES bring is `assessment_basis`, and that is what decides the share (2026-08-19,
pre-staging QA F-03).** Until then the column was on the form, validated, required the right
companion field, activity-logged with a translated vocabulary — and **read by no calculation**. Every
ownership took the plain area path, so an operator who recorded the deed participation his contract
names was billed on floor area instead and nothing said so. The enum's own docblock warns against
exactly that shape of bug while being an instance of it.

| Basis | Share of the pool | Note |
|---|---|---|
| `area` | Time-weighted floor area ÷ the pool's denominator | The default, and today's behaviour — every existing pool reconciles unchanged |
| `participation` | `participation_pct` as-is | A deed participation sums to 100 across the building, so it names a share of the WHOLE pool |
| `stated` | `participation_pct` as-is | A percentage the parties simply agreed. Same column, same meaning, different provenance |
| `purchase_value` | The cohort's own area share, re-cut among the purchase-value owners by price | See below |

`participation` and `stated` are the same claim a lease's contractual share makes, so they route
through the **same** path and inherit F-08's guard for free: a building whose deeds together promise
away more than the pool is **refused**, not billed. A null percentage falls back to area, never to
zero — a zero share silently excuses an owner from the cost his neighbours are funding and looks
identical on screen to a correctly configured 0%.

**`purchase_value` needed a decision**, because a leased unit has no purchase price to sum with, so
there is no single denominator that includes everybody. The reading chosen — stated here rather than
left implicit, and logged as **B2.5** in [STATUS](../STATUS.md) — is that the
purchase-value owners keep the slice their AREA gives them **collectively** and divide that slice
among themselves by price. Σ over the cohort is identical either way, so no leased neighbour moves
and this basis can never itself cause an over-recovery. An owner with no purchase price recorded
leaves the cohort entirely, numerator and area alike, and falls back to area.

**The basis governs the ANNUAL true-up and nothing else.** The monthly صيانة stays a `charges` row —
an amount the parties agreed and the operator typed. Deriving it from a denominator would overwrite
the schedule with a computed number and restate months already billed and paid. The form now says
so, and requires `purchase_price` when the basis divides by it. Pinned by
`AssessmentBasisApportionsTheOwnersShareTest`.

> **What it was doing before.** Measured on a mall half let and half sold with a 100,000 pool: the
> one tenant was allocated **100%** and billed **EGP 100,000**, where a just share of his own half is
> 50,000. The owner used the common area and the tenants paid for it. And **the pool tied out
> exactly** — Σ allocated = total expense by construction — so the books-check passed and the report
> showed nothing amiss. A tie-out proves the money was fully apportioned; it cannot notice it was
> apportioned over the wrong set of parties.

Two traps worth knowing if you touch this again:

- **The frozen-share key.** Re-run shares were pinned by `pluck(..., 'lease_id')`. Every ownership
  row keys on `null`, so a second sold unit would overwrite the first and both would re-run against
  one share — visible only as a broken tie-out on the SECOND reconciliation of a pool that
  reconciled cleanly the first time. Keyed `L:12` / `O:7` now.
- **Allocating without billing is a cash regression, not a partial fix.** The tenant correctly drops
  to 50,000 while the owner's 50,000 sits uncollectable. The billing path moved to
  `BillableAgreement` in the same change for that reason, and a test asserts the owner's true-up
  becomes a real invoice.

## 7c. The owner lets his own unit (phase 4)

`leases.unit_ownership_id` — **one nullable column, and deliberately nothing else.**

This is Yardi's construct: in Voyager Condo/Co-Op & HOA a lessee in a sold unit is a **sub-record
under the owner's unit**. Two consequences follow, and both are pinned by
`OwnerLetsHisOwnUnitTest`:

- **The owner remains liable for the assessment.** Letting the unit does not move the service charge
  onto the occupant and does not suspend it.
- **The lessee is a real occupant.** Access, violations, SLA, fit-out and the occupancy figure all
  apply to a tenant the mall never signed. Owner of record ≠ occupant of record.

> **What was nearly built here, and why it was not.** The plan called for a second column,
> `leases.revenue_mode` (`operator_collects` / `owner_collects`), so billing could skip a lease whose
> rent the owner keeps. **Yardi has no such flag** — whether the operator collects is a term of the
> MANAGEMENT AGREEMENT, which this system already holds as `unit_ownerships.management_mode`. A flag
> on the lease would have been a second place to state one fact, and the two would eventually
> disagree. It would also have been dead code: `MonthlyBillingService` returns early for a lease with
> no applicable charge, so a tenancy carrying no rent charge **already bills nothing**. Asserted in
> the test with a paired control rather than assumed.

## 7d. Where this module departs from Yardi, deliberately

Stated plainly so nobody reads the whole module as standard behaviour:

| Ours | Yardi | Why |
|---|---|---|
| `management_mode` — four states (`self_occupied` · `self_let` · `operator_managed` · `vacant`) | Splits the two directions across two products: condo (owner pays) and third-party management (owner receives) | An Egyptian mall sells units and manages them in one building, so one register has to say which arrangement each unit is under |
| `assessment_basis` — four options (`area` · `participation` · `purchase_value` · `stated`) | Participation interest, carried per unit | `purchase_value` and `stated` are Egyptian developer-contract practice; `area` is the default and reproduces today's CAM behaviour |
| `tenure_type` — `freehold` · `usufruct` · `leasehold_sale` | n/a | حق انتفاع is an Egyptian legal instrument with no Voyager equivalent. Billing is identical across all three; only the tenure bounds differ |

Everything else in this module — the owner as a customer record, assessments on his ledger, CAM
participation, the lessee-under-owner lease — is Voyager behaviour.

## 7e. The owner's portal, and the resale certificate (phase 6)

**The portal needed no new panel** — and that is the party decision paying for itself a third time.
An owner IS a `tenants` row, the portal authenticates a `TenantUser` against one, and every portal
query already scopes on `tenant_id`. He signs in and sees his assessments and payments with no code
written for it. Yardi's condo owner portal is likewise the same product the residents use.

Two things did need fixing, both found by writing the test first:

- **CAM allocations were scoped `whereHas('lease', …)`** — an owner's allocation has no lease since
  phase 3 made ownerships participants, so he was billed a true-up whose basis he could not see. One
  more instance of the lease-chain assumption.
- **Leases and sales declarations were offered to him.** He signs neither. Hidden for a unit owner,
  unchanged for a retailer — asserted both ways, because over-applying the filter is the easier
  mistake.

### The resale certificate

`TransferUnitOwnershipService` — Yardi's change-of-ownership. It closes the seller's tenure, opens
the buyer's, keeps the unit's history, and returns the **resale (estoppel) certificate**.

**Where an operator does it (added 2026-08-18; split across two surfaces 2026-08-30):**
**Resale certificate** is a DOWNLOAD and stays on the register row beside the tenure it copies
(read-only, gated on `unit_ownerships.view`, "as at" any date). **Transfer ownership** is a write and
moved to the ownership's own Edit page (`App\Filament\Admin\Actions\UnitOwnershipActions`, gated on
`unit_ownerships.edit`, hidden once the tenure is terminal). Both read the same
`UnitOwnershipsTable::certificateSummary()`, so the figure the operator confirms against is one
definition rather than two. The transfer modal
shows the outstanding figure live as the date moves, so the arrears refusal is visible before it
fires rather than after. Until then the service had no caller outside its tests: a unit could be
resold in the real world and there was no way to record it.


- **Every figure is read from the books**, never typed. `outstanding` is the invoices' own `balance`,
  which `Invoice::recomputeTotals()` owns across all four settlement channels — because that number
  is what the buyer's solicitor holds back from the price.
- **Arrears are refused, not warned about.** Transferring over a debt is possible but deliberate
  (`allowOutstanding: true`) and recorded on the ownership. Letting it through quietly is how a debt
  becomes the wrong person's.
- **The buyer inherits the TERMS, not the debt** — tenure type, assessment basis, share. The seller's
  arrears stay on the seller's ledger, and the certificate states them.
- The seller's row is closed, **never deleted**: his assessments, CAM shares and statements all point
  at it.

## 8. Still outstanding — and it is not code

**Phase 5 (the management fee, the cash-basis unit-owner statement and remittance) is BLOCKED on two
answers, not on engineering.** Both are logged where they will be seen:

| Question | Where | Why it blocks |
|---|---|---|
| Which GL account does **management-fee income** post to? | [STATUS §B2.1](../STATUS.md) · [ACCOUNTANT-BRIEFING ٤.٧ Q-OWN-1](../accounting/ACCOUNTANT-BRIEFING.md) | It is the OPERATOR's revenue, not the property's. Posting it to a guessed account puts Eltizam's income in Jawad's P&L |
| Is there a **sinking fund**, and which **liability** account? | §B2.2 · Q-OWN-2 | Money held for a future obligation is not revenue. Shipping it as income would overstate the P&L and hide a liability |

Two more that change numbers rather than block: whether an owner's صيانة is property revenue or cost
recovery (§B2.3 — it currently posts as revenue), and whether Eltizam approves a resale buyer
(§B2.4 — no approval step today).

The fee **percentage and its basis are already configurable per ownership** (`management_fee_pct`,
`fee_basis`), so the day those accounts exist the remaining work is the statement and the posting,
not a redesign.

## 9. Everything else is done

Phases 0–4 and 6 have shipped. What remains is the management fee, the cash-basis owner statement
and remittance — phase 5, blocked on the two accounting answers in §8 above rather than on code.

**~25 read sites still scope invoices through `lease.unit`** — reports, widgets, statement PDFs. They
are correct for lease-raised invoices and will MISS owner invoices; migrating them is tracked in
[plan 08 §5.2b](37-unit-owners.md).

**Four of those sites were admin surfaces, and are now fixed** (2026-08-16). They are worth naming
because they show the two shapes this bug takes — and they are opposite shapes, so a single habit of
"add the ownership branch" would have got two of them wrong:

| Surface | Was | Shape |
|---|---|---|
| `/admin/invoices`, `/admin/payments` | Scoped by chaining `lease.unit` / `invoices.lease.unit`, so an owner assessment (which has no `lease_id`) was **invisible** | Too NARROW — the owner's document did not exist on screen |
| `PaymentForm`'s tenant picker | `whereHas('leases.unit')`, so a property-restricted user could not select an owner at all — his assessment could be raised and chased but **never paid** | Too NARROW — the AR had no way to clear |
| `TenantResource`'s register | Admitted the owner only via `orWhereDoesntHave('leases')`, the branch meant for a tenant *just created* | Too WIDE — an owner is permanently unleased, so **every owner showed on every property's register** |

The rule the fixes follow: **ownership is its own branch, matched to the property of the unit owned** —
never a relaxation of the lease branch. `orWhereDoesntHave('leases')` looks like it fixes the picker
and does: it also exposes every unaffiliated tenant in the portfolio on a restricted user's payment
form, which is the isolation that callback exists to enforce. Pinned by
`tests/Feature/Regression/OwnerCanBeHandedAPaymentTest.php`, which was verified by reverting each fix
and watching the matching case fail.

Also unchanged and still true: [module 32](32-owner-statements.md) apportions the whole property P&L
to the mall owner, so it over-pays from the day a unit is genuinely sold. Phase 5.

There is also deliberately **no `sold` unit status**. Occupancy and ownership are different axes: a
sold unit can be occupied, let or empty, and collapsing them into one column would make
`units.status` answer two questions badly. `Unit::isOwned()` answers the ownership one. (This departs
from the plan's own phase-1 line, which listed a "sold" state.)

## 10. Demo data

`DemoSeeder` seeds **7 ownerships over 5 units** on Atriom Walk, with 42 assessments billed through
`BillUnitOwnershipsService` — not inserted, per the seeder's own rule that demo data an operator
could not have produced hides the bugs a seeder exists to surface.

All four owner states appear, plus the two shapes that cannot be pictured from a single row: a
**co-owned** unit (60/40, and the two owners together pay exactly one unit's assessment) and a
**resold** unit whose register carries the former owner and the current one.

> **Order matters, and the first cut got it wrong.** Applying the resale *before* the back-billing
> marked the seller `transferred`, and only a HANDED-OVER ownership bills — so he ended with a closed
> tenure and not one assessment against it. That is data no operator could produce, and it quietly
> defeated the reason the seller's row is kept at all: there was no history left for it to be the
> basis of. The seeder now bills the months he owned it, transfers, then bills the buyer's months.

`LearningSeeder` deliberately seeds **no** ownerships — it is the empty-mall variant, and its whole
point is that an experiment's own numbers are the only numbers on screen.

## 11. Tests

`tests/Feature/UnitOwnershipTest.php` — the party decision, the defaults, which layer refuses, the
handover trigger, resale-as-tenure-end, the reference series, and the audit trail rendered in **both
languages** with no stored value or raw key leaking through.

`tests/Feature/Resources/UnitOwnershipResourceTest.php` — property isolation through the real scoped
query, the RBAC grants, the write guard (with an authorised control beside the refusal), and a sale
recorded end-to-end through the create page.

`tests/Feature/Regression/OwnerCanBeHandedAPaymentTest.php` — the two admin surfaces in §9: the owner
is offered on the payment form of the property he owns in, he is on that property's register and not
on another's, and a tenant affiliated with nowhere stays visible. It runs as a **property-restricted**
user on purpose — `visibleAssetIds()` is null for a super_admin, so every case here would pass without
asserting anything if it ran as one.
