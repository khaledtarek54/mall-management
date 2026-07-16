# Module 26 — Preventive Maintenance (الصيانة الوقائية)

> **Status: COMPLETE (Phase 3 shipped — pass/fail checklist + completion gate).** Recurring
> facility-maintenance **plans** that auto-raise **work orders** (with checklists) when
> due, via the daily `maintenance:generate-preventive` scan; two property-scoped Filament
> resources (plans + work orders), a checklist relation manager (**mark each item pass/fail**),
> status transitions (start / complete / cancel) owned by `MaintenanceWorkOrderService`, the
> `preventive_maintenance.*` RBAC (operations) + module flag, and a **bilingual facility
> work-log PDF report** (RPT-1). Delivers discovery backlog items **MNT-1/2 + RPT-1** and
> **FR-PPM-07** of the Eltizam FRD. Distinct from tenant-facing maintenance **requests**
> (module 11) — this is internal/facility upkeep (common areas, no tenant), so it has its
> own models.
>
> **Eltizam FRD roadmap.** This module is being grown into the **internal work-order system**
> — corrective maintenance (CM) will live here, not in module 11, because a CM raised from a
> failed common-area check has no tenant and no unit (module 11's `tenant_id`/`unit_id` are
> NOT NULL). Landed so far: the service + state machine + pass/fail gate. Still to come:
> an `Equipment` register (asset codes + sub-codes), `maintenance_type` routine/fixed,
> yearly frequency, per-property SLA + penalties, internal/external classification,
> parts + fault attribution, and follow-up work-order chains.

An operator maintains the building itself — HVAC filters, lift servicing, fire-safety
checks, generator runs — on a recurring schedule, not in response to a tenant. This module
keeps those **plans** and turns them into scheduled **work orders** with checklists the
engineers complete.

---

## 1. Domain model

### `equipment` — the maintainable-asset register (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property the machine stands in |
| `parent_id` | sub-codes (FR-PPM-04) — null = a top-level machine |
| `code` | **unique per property** (FR-PPM-03): `ESC-01` · `ESC-01-MOT` |
| `name_en` · `name_ar` · `category` | bilingual label + the module's category vocabulary |
| `unit_id` · `location` | where it is — `unit_id` null = common area; `location` free text ("Roof, zone B") |
| `fixed_asset_id` | optional link to its accounting twin |
| `is_active` · `notes` | state |

Plus `equipment_inventory_item` — the compatible-spare-parts pivot (FR-PPM-05), answering
"which parts fit escalator ESC-01?".

**Why this exists.** The FRD is asset-centric — every AC unit, escalator and pump carries a code,
with sub-codes for components. Atriom had no such grain: `Asset` is the *mall*, `Unit` is a
*storefront*, `FixedAsset` is a *depreciation record*. Nothing was "chiller CH-01".

**Why not just extend `FixedAsset`.** It's an accounting object under `fixed_assets.*` RBAC — a
maintenance engineer can't even see it — and it exists to be depreciated. Not every maintainable
machine is capitalised (the fire pump isn't), and not every capitalised asset is maintainable
(office furniture). `fixed_asset_id` links the two where they're the same object, so finance and
maintenance keep separate registers, separate permissions, and no double data entry.

**Why a pivot for parts, not a column.** `inventory_items` is deliberately **SHARED** and unscoped
("a pump seal is the same item everywhere"), so it cannot carry a property-owned FK —
`PropertyIsolationConformanceTest` enforces exactly that.

### `maintenance_plans` — the recurring schedule (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` · `unit_id` | property + optional unit (null = common / asset-wide) |
| `title` · `category` · `description` | what to do |
| `frequency_unit` · `frequency_value` | how often (`days`\|`weeks`\|`months` × N) |
| `checklist` (json) | the template check items |
| `department_id` · `vendor_id` | default assignee |
| `next_due_date` · `last_generated_at` · `is_active` | scheduling state |

### `maintenance_work_orders` — a raised job (from a plan or ad-hoc)
| Column | Meaning |
|--------|---------|
| `maintenance_plan_id` | the source plan (null = ad-hoc) |
| `asset_id` · `unit_id` · `reference` | scope + auto `WO-{asset}-{YYYYMM}-{n}` |
| `title` · `category` · `status` · `scheduled_for` | the job (`open`\|`in_progress`\|`done`\|`cancelled`) |
| `completed_at` · `completed_by_user_id` | completion audit |

### `maintenance_work_order_items` — the checklist (child of a work order)
| Column | Meaning |
|--------|---------|
| `maintenance_work_order_id` · `label` | the item |
| `result` (`pending`\|`pass`\|`fail`) | the outcome — **the single source of truth** for item state |
| `marked_at` · `marked_by_user_id` | who recorded the outcome, when |

`result` **replaced** an `is_done` boolean (migration `2026_07_16_100001`), which could not express
a failed inspection — the state a PPM visit exists to find (FR-PPM-07), and the trigger for
corrective maintenance (FR-CM-01). One column, not a boolean *and* an enum: two columns encoding the
same fact drift. `MaintenanceWorkOrderItem::getIsDoneAttribute()` survives as a **read-only**
back-compat accessor (`result !== 'pending'`); write `result`. `done_at`/`done_by_user_id` became
`marked_at`/`marked_by_user_id` — "done" read wrong beside `result = fail` and collided with the
work order's own `completed_at`. Indexed `(maintenance_work_order_id, result)` for the gate + the
progress badge.

---

## 2. Business rules

1. **Property-scoped** (`asset_id`) — all three resources use `BypassesScopingOnAll` +
   `tenantOwnershipRelationshipName='asset'`; create/edit re-validate the submitted `asset_id`
   against `visibleAssetIds()` (`assertAssetInScope`).
1a. **Equipment codes are unique per property**, mirroring `warehouses.code` and
   `fixed_assets.tag`. Two malls may each have an `ESC-01` — a portfolio-wide unique would force
   a mall prefix into every code, which is what `asset_id` is already for.
1a-i. **The equipment pickers clamp the property they key on.** `parent_id`, `unit_id`,
   `fixed_asset_id` and the `code` uniqueness rule are all scoped by `asset_id` — which is
   **client-supplied** (`->live()`, and the Select is enabled in All-Properties mode), so each goes
   through `EquipmentForm::inScopeAssetId()` rather than the raw value. Without it a crafted
   Livewire request pointed `asset_id` at an invisible property and the option lists enumerated its
   units / equipment / fixed assets — rendered long before `assertAssetInScope()` runs at save.
   The `code` rule is the subtle one: Laravel runs every field rule in **one pass before any mutate
   hook**, and `Rule::unique` compiles to a raw query Filament's tenancy scope never touches, so the
   guard fires too late. Keyed raw, it answered *"is this code taken in <property>?"* — the write
   was refused either way, but a `code` error appearing (or not) was a one-bit existence oracle.
   The clamp is `TenantScope::clampAssetId()`. **The whole class has since been swept** —
   `WarehouseForm`, `FixedAssetForm`, `CamExpensePoolForm`, `UnitForm`, `EmployeeForm`, and the
   Admin **and Portal** sales-declaration forms (which keyed on `lease_id`, making the Portal one
   *cross-tenant*). `tests/Feature/Scenarios/UniqueRuleScopeConformanceTest.php` now fails CI for
   any Filament form that keys a unique rule on a raw client-supplied scope.
1b. **A sub-code's parent must be in the same property, and the tree must stay acyclic.** Both are
   enforced in `Equipment::booted()` (the model is the only writer; the DB can't express either).
   A cross-property parent would let Mall A's escalator own Mall B's motor and surface the child in
   the wrong property's tree — a genuine isolation leak. Re-parenting a node under its own
   descendant would detach the branch from every root. `parent_id` is **`nullOnDelete`, not
   cascade**: deleting a parent promotes its components to roots rather than destroying their
   maintenance history. `ancestorIds()`/`selfAndDescendantIds()` both carry a visited-set, so a
   cycle introduced *outside* the model (a direct DB edit, an import) terminates instead of hanging
   the request.
1c. **A machine with sub-codes cannot move to another property** — move or detach the components
   first. The same-property rule in 1b only fires on the *child's* save, so without this a parent
   could walk to another mall and strand its components cross-property. **The check counts trashed
   children** (`children()->withTrashed()`): `parent_id` is `nullOnDelete`, which fires only on a
   *hard* delete, so a soft-deleted child keeps pointing at its parent — let the parent move and
   that child becomes permanently unrestorable (restore → `saving` → the same-property rule throws
   `InvalidArgumentException`, which Filament does not catch → 500, with no UI path to fix it).
   Blocked rather than cascaded because Filament wraps neither create nor save in a transaction: a
   cascade that hit `equipment_asset_code_unique` partway would commit the parent's move and strand
   the children anyway.
1d. **Equipment is deletable/restorable** (`EditEquipment` header actions, mirroring `EditUnit`;
   delete stays super_admin-only). Not optional polish: the model soft-deletes and the table ships a
   `TrashedFilter`, so without them the filter could never match a row and a typo'd code would be
   burned forever — `equipment_asset_code_unique` counts trashed rows, so only a **force**-delete
   frees a code.
2. **`maintenance:generate-preventive`** (daily 02:30) raises a work order for every **due**
   active plan (`next_due_date ≤ today`), copies the checklist template into items, then
   advances `next_due_date` by the plan's frequency. **Idempotent + lock-safe**: the plan row
   is locked + re-checked inside its transaction, and advancing `next_due` is the idempotency
   key (plus a per-cycle duplicate backstop). A long-dormant plan catches up one cycle per run.
3. **`frequency_value ≥ 1`** — coerced in the model, so a plan always advances.
4. **A done/cancelled work order is terminal** — read-only (edit + start/complete/cancel
   hidden, edit page aborts 403); its checklist is frozen (enforced in the service, not only
   the UI).
5. **Marking checklist items** is gated on `preventive_maintenance.complete` and captures
   who/when; **editing the checklist / plan / work order** on `preventive_maintenance.edit`.
6. **A work order cannot close while any checklist item is `pending`** (FR-PPM-07). Enforced by
   `MaintenanceWorkOrderService::transition()` — not by the UI. Three deliberate carve-outs:
   - **A `fail` does not block closure.** Finding a fault *is* the visit succeeding; the fault
     becomes corrective maintenance (FR-CM-01). Only an item nobody looked at blocks.
   - **An order with no checklist is vacuously complete** — the gate must not strand ad-hoc
     orders that never had items.
   - **Cancelling ignores the gate** — that's abandoning the visit, not completing it.
7. **The state machine lives in `MaintenanceWorkOrderService::TRANSITIONS`**, mirroring
   `TenantRequestService` (module 11): illegal hops throw `InvalidArgumentException`;
   business-rule refusals throw `DomainException`, which the Filament action catches and shows
   as a danger notification. `open → done` **is** legal (a short job done in one go); the
   checklist gate — not the path taken to `done` — is the invariant.
8. **The work order is the aggregate root for its checklist.** *Every* mutation of the order **or**
   its items (`transition` · `markItem` · `addItem` · `removeItem`) goes through
   `MaintenanceWorkOrderService::withOrderLock()`, which row-locks the `maintenance_work_orders`
   row inside a transaction. **Don't add a mutator that writes items directly** —
   `PpmChecklistGateLockTest` fails CI if you do.

   > **Why the parent row and not the items.** The gate *counts* pending items, and a count can't
   > lock rows that don't exist yet — locking the item range would still let `addItem()` insert a
   > fresh `pending` row straight past it. Serializing every writer on the single parent row is
   > what makes the count sound.
   >
   > This is load-bearing, and the first cut got it wrong: the gate locked the order but counted
   > items *unlocked*, so item writers (which took no parent lock) never conflicted. Two
   > connections on real MySQL reproduced it — T1 locks the order and sees `pending = 0`, T2
   > un-marks an item without blocking, T1 commits `done`. The order closed with an unchecked
   > item and was **unrecoverable in-app**: `done` is terminal, so the checklist froze with the
   > violation baked in. Post-fix the same probe has T2 block on the order lock until T1 commits,
   > then refuse (terminal).

---

## 3. RBAC & module flag

- Permissions `preventive_maintenance.view/create/edit/delete` (delete = super_admin only) +
  `preventive_maintenance.complete` (tick items, mark done). Granted to the **operations**
  role (maintenance/dispatch); **manager** (all non-delete) + **viewer** (all `.view`) inherit
  via the flat list.
- Module flag **`preventive_maintenance`** (`Modules::KEYS` + `ModulesSettings`), on by default.
- Both the plan + work-order resources share `permissionModule()='preventive_maintenance'`.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Plans + work orders + checklists** | `MaintenancePlan` + `MaintenanceWorkOrder` + items, the daily generation scan (idempotent/lock-safe), two property-scoped resources, checklist relation manager, status transitions, RBAC + module flag, tests | ✅ shipped |
| **2 — Facility work-log report (RPT-1)** | `FacilityWorkLogPdfService` — a bilingual PDF of work orders for a property over a date range (summary by status + category + the detail list), launched from a **"Work log (PDF)"** action on the work-order list, scoped to the user's visible properties | ✅ shipped |
| **3 — Pass/fail checklist + completion gate (FR-PPM-07)** | `result` enum replaces `is_done`; `MaintenanceWorkOrderService` (the module's first service) owns the TRANSITIONS matrix, the row-locked completion gate, and `markItem()`; the Filament actions became thin callers; progress badge counts *marked* and warns amber on any fail | ✅ shipped |
| **4 — Equipment register (FR-PPM-03/04/05)** | `Equipment`: per-property-unique `code` + `parent_id` sub-code tree (same-property + acyclicity guards), nullable `fixed_asset_id` link to the accounting register, `equipment_inventory_item` pivot for compatible spare parts, property-scoped resource under `preventive_maintenance.*` | ✅ shipped |
| **5 — Equipment ↔ plans/work orders** | `equipment_id` on `maintenance_plans` + `maintenance_work_orders` (PPM per machine), `maintenance_type` routine/fixed (FR-PPM-01), `years` frequency unit + fixing `advanceDue()`'s silent `default => addMonths()` arm (FR-PPM-02) | ⬜ next |
| **6 — Corrective maintenance (FR-CM-01..15)** | CM raised from a failed checklist item, internal/external XOR, per-property SLA + priority tiers, vendor SLA penalties, parts + fault attribution + tenant recharge, follow-up work-order chains (`parent_work_order_id`) | ⬜ planned |

---

## 5. Tests

`tests/Feature/Services/PreventiveMaintenanceTest.php` — generation (raises a work order +
copies the checklist + advances `next_due`), idempotency (no double-generate), not-due /
inactive skipped, frequency advance (weeks), blank-checklist-entry skipping, and the command.

`tests/Feature/Resources/MaintenanceWorkOrderResourceTest.php` — `preventive_maintenance.*`
RBAC gating, module-off hiding, property scoping, the complete/cancel actions (+ read-only
role guard), terminal-order immutability (actions hidden), and the checklist add-item action
(+ frozen on a terminal order). `tests/Feature/Resources/MaintenancePlanResourceTest.php` —
plan RBAC + scoping + `assertAssetInScope` guard + the `frequency_value ≥ 1` coercion.

`tests/Feature/Services/FacilityWorkLogTest.php` — the work-log PDF renders (with + without
orders in range) and the export action streams a PDF for an authorised user.

`tests/Feature/Services/MaintenanceWorkOrderServiceTest.php` — the FR-PPM-07 gate and the state
machine: completion refused while any item is `pending` (and the order left untouched, not
half-completed), the message names the outstanding count, completion stamps who/when, a **failed**
item still closes, a checklist-less order closes vacuously, un-marking an item **re**-blocks
closure, an item added to a fully-marked checklist re-blocks it (the gate reads live rows, not a
cached count), illegal hops throw, `open → done` is allowed, cancel bypasses the gate, and a
terminal order's checklist is frozen.

`tests/Feature/Scenarios/EquipmentScenarioTest.php` — the register: per-property code uniqueness
(and two properties reusing `ESC-01`), sub-code trees + deeper nesting, children promoted to roots
on parent delete, the cross-property-parent refusal, self-parent + parent-under-own-descendant
refusals, a walk terminating on a cycle planted *outside* the model, the spare-parts pivot, the
optional fixed-asset twin, the `is_active` default, RBAC + property scoping + `assertAssetInScope`.
`tests/Feature/Resources/EquipmentResourceTest.php` — renders the table **with rows** (a parent, a
sub-code, and a row with every optional column null — an empty table hides `$state`-closure bugs),
create/edit through the form, duplicate-code + required-field validation, the out-of-scope create
**and** edit guards, RBAC, and module-off hiding.

`tests/Feature/Regression/EquipmentTreeIsolationTest.php` — the isolation holes an adversarial
review found in the register's first cut, none of which the passing tests caught: a parent moving
property and stranding its sub-codes (**incl. trashed ones**), the pickers enumerating an invisible
property from a tampered `asset_id`, the `code` rule leaking a code's existence as a one-bit oracle,
and the resource shipping no delete/restore at all (with force-delete proven to be what frees a
burned code).

`tests/Feature/Regression/PpmChecklistGateLockTest.php` — the gate's **lock-safety**. SQLite's
`compileLock()` returns `''` (so `lockForUpdate` emits no SQL and the suite cannot prove the lock by
inspecting queries — the same admission as `PaymentOverAllocationGuardTest`), so it guards three
ways: **structurally**, a reflective source gate asserting every public mutator routes through
`withOrderLock()` and that the helper locks the parent row in a transaction (same technique as
`PropertyIsolationConformanceTest` — this is what stops a future mutator silently bypassing the
lock); **behaviourally**, that item writes run inside a transaction; and **by invariant**, that a
terminal order never carries a pending item. The FOR-UPDATE emission itself is asserted only when
the suite runs against MySQL (skipped on SQLite).

`tests/Feature/Scenarios/PreventiveMaintenanceScenarioTest.php` drives the **real service**, not raw
model writes — otherwise it would pass straight through the gate it is supposed to prove.

**Related:** 11 Maintenance (tenant-facing requests), 12 Vendors (assignees), 14 Departments,
01 Properties (asset scope), 18 RBAC (operations), 19 Notifications & Scans (the daily scan).
