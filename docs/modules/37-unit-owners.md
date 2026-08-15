# 37 · Unit Owners (ملّاك الوحدات)

> The buyer who bought a shop instead of renting one. A peer of the lease, not a variant of it —
> and **not** the mall owner ([module 32](32-owner-statements.md)), who is the opposite money direction.
>
> **Status: phase 1 of [plan 08](../plans/08-unit-owners.md) — the register.** The record, the party
> type and the registries exist. Billing an owner is phase 2; there is no admin screen yet.

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

## 6. What phase 1 does NOT do

Stated so nobody looks for it: no admin screen, no assessment billing, no CAM participation, no owner
portal, no transfer workflow, no GL posting. `invoices.lease_id` is still NOT NULL, so an owner with
no lease **cannot yet be invoiced** — that is the phase-2 schema change. See
[plan 08 §7](../plans/08-unit-owners.md).

## 7. Tests

`tests/Feature/UnitOwnershipTest.php` — the party decision, the defaults, both refusal layers, the
handover trigger, resale-as-tenure-end, and the reference series.
