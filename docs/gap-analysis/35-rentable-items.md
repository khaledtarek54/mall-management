# Module 35 — Rentable Items (parking · storage · signage) · gap analysis

> **Round 3, 2026-08-18.** First audit — on the never-gap-analysed list in
> [PROJECT-MAP](../PROJECT-MAP.md). Method: [000-plan.md](000-plan.md).
> Benchmark: [competitors/README](competitors/README.md) §space & parking and
> [benchmarks/yardi/09-yardi-space-and-parking.md](../benchmarks/yardi/09-yardi-space-and-parking.md).

## 1. Verdict

**No defects found.** This is the cleanest module audited in round 3, and unusually so: every
hypothesis that caught something in modules 31, 32 and 33 was already handled here.

The one thing that WAS broken was a **test**, red on `main` since 2026-08-17 — see F-A.

## 2. Findings

### 🟡 F-A — a rename left its test behind, and the suite has been red for a day *(fixed)*

`LeaseRentableItemsVisibleTest` called `callTableAction('assign', …)` and
`assertTableActionHidden('assign')`. The action is named **`assignRentableItem`**.

The timeline settles it: the test was written **2026-08-10** (`89b58d68`, "show the space a lease
rents"), when `assign` was correct. The action was renamed on **2026-08-17** by `a6e76105`
("refactor(leases): the list finds, the record acts"), and the test was not updated. Two of the four
failures standing on `main` are this, and nothing else.

**The product was never broken** — `LeaseRentableItemsRelationManager` and `LeaseActions` agree on
the new name throughout. Fixed by correcting the test, which also *proves* the feature: it now drives
assign through the screen and asserts the negotiated rate (650) reaches the lease's parking charge in
the same click.

**Swept for siblings.** Every action name used in `tests/` via `callTableAction` /
`assertTableAction*` / `mountTableAction` / `TestAction::make` was compared against every
`Action::make('…')` in `app/`. The only unmatched names are Filament's built-ins
(`create` · `edit` · `delete` · `attach` · `detach` · `export`), which register without an explicit
`Action::make`. No other stale reference exists. Worth noting the failure mode was loud rather than
silent — Filament throws "Action [assign] not found on table" instead of treating an unknown action
as hidden, so `assertTableActionHidden` could not pass vacuously.

## 3. Verified clean

| Hypothesis | Result |
|---|---|
| A bay can be let to two leases at once | **False** — the item is re-read under `lockForUpdate` and the existing hold re-checked inside the transaction; registered in `ConcurrencyPolicy` with its lock count |
| A Mall A bay can be attached to a Mall B lease | **False** — refused explicitly (`rentable_item_other_property`), comparing the item's `asset_id` against the lease's own unit |
| An out-of-service bay can still be let | **False** — refused, and the picker offers only lettable items so the rule is not first met as an error |
| Releasing a bay leaves the charge running | **False** — the charge is end-dated and deactivated, and the item returns to `available` |
| A negotiated rate can be negative | **False** — refused |
| A let item can be deleted, orphaning lease history | **False** — `#[DeletableWhenUnused(blockedBy: ['leases'])]`, with "set it out of service" named as the remedy |

## 4. Scope gap (stated, not fixed)

**An owner-occupier cannot hold a bay.** `AssignRentableItemService::assign()` takes a `Lease`, and
the pivot is `lease_rentable_item` — lease-keyed by construction. A unit owner who bought a shop and
trades from it (module 37) plausibly rents parking too, and today cannot.

Unlike the violation fine (module 31) and the NSF fee (module 33), **this is not the same bug**:
those inferred a lease where the money core already accepted a `BillableAgreement`, so the fix was a
lookup. Here the *relationship itself* is lease-shaped, so supporting owners needs a migration — a
second pivot column or a polymorphic holder — plus a decision about whether an owner's bay charge
belongs on his assessment run. **A feature with a schema cost, not a defect**, and recorded as such
rather than fixed on my own authority.
