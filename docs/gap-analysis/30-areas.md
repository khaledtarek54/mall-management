# Module 30 — Areas (facility zones) · gap analysis

> **Round 3, 2026-08-18.** First audit, and the last of the eight never-gap-analysed modules.
> Method: [000-plan.md](000-plan.md).

## 1. Verdict

**No findings.** Every hypothesis was already handled, and two of them were handled by the project
having *already applied a lesson it learned elsewhere* — which is the interesting result here.

## 2. Verified clean

| Hypothesis | Result |
|---|---|
| A crafted payload can attach an out-of-scope supervisor | **False, and specifically defended.** The picker scopes options, but `CreateArea::afterCreate()` / `EditArea::afterSave()` re-validate the attached staff and **strip + 403** any out-of-scope attach. The docblock names the reason: the supervisors relationship syncs from component state *after* the model saves, so a `mutateFormData` guard would protect nothing — the same lesson `UserResource::enforceGrantableAssetsRule()` learned on the property-assignment field |
| Re-homing a zone to another property leaves a now-invalid supervisor attached | **False** — explicitly why the guard runs on **save**, not only on create |
| The options query leaks another mall's roster | **False** — the `whereHas ∪ whereDoesntHave` clause is **grouped**, with a comment stating that an ungrouped OR would escape the outer asset scope once applied. That is the exact `EntitySelect` trap CLAUDE.md documents, pre-empted here |
| A work order can be routed to another property's zone | **False** — `Area` is `#[PropertyOwned]`, so the register and every picker scope to the selected property |
| `area_id` derivation picks the wrong source | **False** — derived at the model layer from the tenant request first, then the unit, and only when not already set; it keys off the FK rather than the relation, because a set `area_id` guarantees a row while the zone itself may be soft-deleted |
| Deleting a zone orphans or blocks its work orders | **False** — `area_id` is `nullOnDelete()` on units, tenant requests and plans, so a retired zone releases its references rather than orphaning them or wedging the delete. Consistent with `#[DeletionAllowed(reason: 'configuration: a zone used for routing')]`; delete stays super_admin-only |
| A soft-deleted zone still notifies its supervisors | **False** — the fan-out uses the default relation, which excludes trashed, and no-area / no-supervisors / a bad recipient are all contained no-ops so routing can never break request or work-order creation |
| An area could be soft-deleted with no way to restore or purge it | **False** — `EditArea` ships Delete / ForceDelete / Restore, with a docblock explaining that without them a zone would be immortal *and* its code permanently burned, since the unique index counts trashed rows |

## 3. Why this module came out clean

Worth recording, because it is the counter-example to the rest of round 3. Every other module here
failed on **a rule the code knew and nothing enforced** — a documented remedy nobody would run, an
assumption no screen upheld, a premise that stopped being true when a new party type arrived.

Areas is the module where those lessons had already been *transplanted*: the relationship-save guard
from the user form, the grouped-OR clause from `EntitySelect`, the trashed-parent handling from
elsewhere in facility. Each carries a comment naming the failure it prevents. That is what a
codebase looks like when a lesson gets applied rather than only written down — and it is the
argument for why the round-3 findings were worth turning into gates rather than fixes alone.

## 4. Not assessed

- **Zone-based auto-assignment beyond notification.** `NotifyAreaSupervisorsService` tells a zone's
  supervisors that work exists; it does not assign it to them. Whether routing should also assign is
  a product question and the module doc treats notification as the deliberate scope.
