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

1. **Property-scoped** (`asset_id`) — both resources use `BypassesScopingOnAll` +
   `tenantOwnershipRelationshipName='asset'`; create/edit re-validate the submitted `asset_id`
   against `visibleAssetIds()` (`assertAssetInScope`).
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
| **4 — Equipment register (FR-PPM-03/04/05)** | `Equipment` model: `code` + `parent_id` sub-code tree (per-property unique, `LedgerAccount` is the reference pattern), nullable `fixed_asset_id` link to the accounting register, pivot to `InventoryItem` for compatible spare parts, `equipment_id` on plans + work orders | ⬜ next |
| **5 — Corrective maintenance (FR-CM-01..15)** | `maintenance_type` routine/fixed, yearly frequency, CM raised from a failed checklist item, internal/external XOR, per-property SLA + priority tiers, vendor SLA penalties, parts + fault attribution + tenant recharge, follow-up work-order chains (`parent_work_order_id`) | ⬜ planned |

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
