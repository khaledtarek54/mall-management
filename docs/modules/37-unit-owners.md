# 37 · Unit Owners (ملّاك الوحدات)

> The buyer who bought a shop instead of renting one. A peer of the lease, not a variant of it —
> and **not** the mall owner ([module 32](32-owner-statements.md)), who is the opposite money direction.
>
> **Status: phases 1, 2a and 2b SHIPPED (2026-08-15).** The register, the screen, and the monthly
> صيانة run — an owner with no lease is now billed, ages, and posts to the ledger. Outstanding:
> CAM participation (3), owner-let leases (4), the agency fee and remittance (5), portal and
> resale certificate (6).

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
or a second invoice table — were rejected in [plan 08 §4](../plans/08-unit-owners.md).

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
  `DeletionPolicy::WHEN_UNUSED` blocks on `invoices` and `charges`; the FK columns ship in phase 1
  precisely so that guard runs a real query rather than a latent one.
- **Handover is what starts the money**, not contract signature — the operator carries the unit's cost
  until the keys change hands. `UnitOwnershipStatus::HandedOver` is the billable state, and
  `isBillableOn()` is the one predicate combining that with the tenure.
- **A tenure cannot run backwards**, and equal is allowed — a sale that collapses on its own handover
  date stays recordable. Enforced at the model, so an import or API write is covered too.
- **`participation_pct` null falls back to area**, so an unconfigured deed is never a zero share.

## 4. Configuration — the unanswered questions are rows

The operator could not answer [plan 08 §8](../plans/08-unit-owners.md) when this was built, so each
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

`PropertyIsolation::OWNED` (direct `asset_id` — the assessment sweep asks per property, and a join per
row is the N+1 the CAM path already had to fix) · `DeletionPolicy::WHEN_UNUSED` · `ValueSets` ·
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

`BillUnitOwnershipsService` raises the monthly assessment. `invoices.lease_id` and `charges.lease_id`
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

## 8. Still outstanding

CAM participation for owned units (phase 3), owner-let leases (4), the management fee, cash-basis
owner statement and remittance (5), the portal surface and the resale/estoppel certificate (6).

**~25 read sites still scope invoices through `lease.unit`** — reports, widgets, statement PDFs. They
are correct for lease-raised invoices and will MISS owner invoices; migrating them is tracked in
[plan 08 §5.2b](../plans/08-unit-owners.md).

Also unchanged and still true: [module 32](32-owner-statements.md) apportions the whole property P&L
to the mall owner, so it over-pays from the day a unit is genuinely sold. Phase 5.

There is also deliberately **no `sold` unit status**. Occupancy and ownership are different axes: a
sold unit can be occupied, let or empty, and collapsing them into one column would make
`units.status` answer two questions badly. `Unit::isOwned()` answers the ownership one. (This departs
from the plan's own phase-1 line, which listed a "sold" state.)

## 9. Tests

`tests/Feature/UnitOwnershipTest.php` — the party decision, the defaults, which layer refuses, the
handover trigger, resale-as-tenure-end, the reference series, and the audit trail rendered in **both
languages** with no stored value or raw key leaking through.

`tests/Feature/Resources/UnitOwnershipResourceTest.php` — property isolation through the real scoped
query, the RBAC grants, the write guard (with an authorised control beside the refusal), and a sale
recorded end-to-end through the create page.
