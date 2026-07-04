# Module 26 — Preventive Maintenance (الصيانة الوقائية)

> **Status: Phase 1 shipped.** Recurring facility-maintenance **plans** that auto-raise
> **work orders** (with checklists) when due, via the daily `maintenance:generate-preventive`
> scan. Two property-scoped Filament resources (plans + work orders), a checklist relation
> manager (tick items done), status transitions (start / complete / cancel), the
> `preventive_maintenance.*` RBAC (operations) + module flag. Delivers discovery backlog
> items **MNT-1/2**. Distinct from tenant-facing maintenance **requests** (module 11) — this
> is internal/facility upkeep (common areas, no tenant), so it has its own models.

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
| `is_done` · `done_at` · `done_by_user_id` | who ticked it, when |

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
   hidden, edit page aborts 403); its checklist is frozen.
5. **Ticking checklist items** is gated on `preventive_maintenance.complete` and captures
   who/when; **editing the checklist / plan / work order** on `preventive_maintenance.edit`.

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
| **2 — Facility work-log report (RPT-1)** | a bilingual PDF/exportable report of completed facility work per property/period (reactive + preventive) | ⏳ next |

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

**Related:** 11 Maintenance (tenant-facing requests), 12 Vendors (assignees), 14 Departments,
01 Properties (asset scope), 18 RBAC (operations), 19 Notifications & Scans (the daily scan).
