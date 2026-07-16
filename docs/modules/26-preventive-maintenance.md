# Module 26 — Preventive Maintenance (الصيانة الوقائية)

> **Status: Phase 7 shipped — CM SLA + penalty assessment + charging it to the vendor's bill.** Recurring
> facility-maintenance **plans** that auto-raise **work orders** (with checklists) when
> due, via the daily `maintenance:generate-preventive` scan; two property-scoped Filament
> resources (plans + work orders), a checklist relation manager (**mark each item pass/fail**),
> status transitions (start / complete / cancel) owned by `MaintenanceWorkOrderService`, the
> `preventive_maintenance.*` RBAC (operations) + module flag, the **equipment register**
> (maintainable-asset codes + sub-codes) with plans and work orders anchored to the machine,
> **corrective maintenance** raised from a failed check (or as a follow-up on a closed job),
> and a **bilingual facility work-log PDF report** (RPT-1). Delivers discovery backlog items
> **MNT-1/2 + RPT-1** and Eltizam FRD **FR-PPM-01..05 · 07** and **FR-CM-01..08 · 14 · 15**
> (the penalty's Dr AP / Cr expense treatment is recorded in `docs/BUSINESS-RULES.md` for
> accountant sign-off; parts/cost still to come — see the roadmap).
> Distinct from tenant-facing maintenance **requests**
> (module 11) — this is internal/facility upkeep (common areas, no tenant), so it has its
> own models.
>
> **Eltizam FRD roadmap.** This module is being grown into the **internal work-order system**
> — corrective maintenance (CM) will live here, not in module 11, because a CM raised from a
> failed common-area check has no tenant and no unit (module 11's `tenant_id`/`unit_id` are
> NOT NULL). Landed so far: the service + state machine + pass/fail gate, the equipment
> register, equipment-anchored plans/work orders (routine vs fixed, yearly frequency), and the
> **CM core** — raised from a failed check, internal/external, follow-up chains — plus per-property
> SLA, breach detection, penalty assessment, and **charging the penalty to the vendor's bill**
> (an AP offset that posts Dr Accounts Payable / Cr the expense the bill charged). Still to
> come: parts + fault attribution + tenant recharge.
>
> **Gotcha, learned the hard way (2026-07-16).** `MaintenancePenalty` posts to the GL, so it
> must be registered in `LedgerPoster::JOURNALIZERS` **and** carry an entry-date column — the
> journalizer alone does nothing. It originally had the journalizer but no registration in any
> dispatch path, so an applied penalty reduced the bill's AP balance while posting no entry,
> and the GL overstated the payable. See
> [module 21 — the registry gate](21-general-ledger.md#gl-registry-gate) before touching the
> penalty's money path, and test through `accounting:sync-ledger`, never `LedgerPoster::post()`.

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

### `sla_policies` — how long a job may take, per property (FR-CM-05)
| Column | Meaning |
|--------|---------|
| `asset_id` · `priority` | unique together — one row per property × priority |
| `resolve_hours` | hours from **acceptance**; must be ≥ 1 |

**A row is an override, not a requirement.** Absent, the operator-wide default applies, so an
operator records only the malls that genuinely differ instead of restating four numbers per
property. Deleting a row returns that property to the default. Resolution is
`App\Support\SlaResolver`: **property policy → `MaintenanceSettings` → `config/maintenance.php`**.

> ⚠️ Tiers 2 and 3 **disagree** and did so long before this table existed: settings say
> urgent=4h/medium=72h, config says urgent=24h/medium=168h. Harmless only because nothing read
> config in practice. The chain now has one documented winner — **settings** — with config as a
> true cold-start default. Don't "fix" config to match: it is what a fresh install with no settings
> row gets, and changing it would silently re-time every such install.

### `maintenance_penalties` — what a vendor owes for missing an SLA (FR-CM-08)
| Column | Meaning |
|--------|---------|
| `maintenance_work_order_id` | **unique** — one penalty per job |
| `vendor_id` · `vendor_contract_id` | who owes it, and under which contract |
| `basis` · `rate` · `hours_over_sla` · `amount` | the terms **as applied**, frozen onto the row |
| `status` | `pending` (accruing) \| `final` (frozen, chargeable) \| `waived` |
| `waived_at` · `waived_by_user_id` · `waive_reason` | the operator's decision not to charge |

Terms live on `vendor_contracts.sla_penalty_basis` + `sla_penalty_rate` (`none` by default —
penalties are opt-in per contract, since most won't have one negotiated), and `job_value` on the
work order carries the vendor's quote for the `percent_of_value` basis.

### `maintenance_plans` — the recurring schedule (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` · `unit_id` | property + optional unit (null = common / asset-wide) |
| `equipment_id` | the **machine** this plan services (FR-PPM-03); null = property/unit-wide |
| `maintenance_type` | **FR-PPM-01** — `routine` (recurring schedule) \| `fixed` (per machine) |
| `title` · `category` · `description` | what to do |
| `frequency_unit` · `frequency_value` | how often (`days`\|`weeks`\|`months`\|**`years`** × N) |
| `checklist` (json) | the template check items |
| `department_id` · `vendor_id` | default assignee |
| `next_due_date` · `last_generated_at` · `is_active` | scheduling state |

⚠️ **FR-PPM-01 is only half-encoded, deliberately.** The FRD defines Fixed as *"performed on a
defined one-time **or periodic** basis per asset"* — two different things. Encoded: the
discriminator, and that a `fixed` plan must name its equipment. **Both types still recur**; a
one-time plan means deactivating it after its first run. Whether one-time needs first-class support
is an **open client question** — don't guess it into the schema.

### `maintenance_work_orders` — a raised job (preventive or corrective)
| Column | Meaning |
|--------|---------|
| `maintenance_plan_id` | the source plan (null = ad-hoc or corrective) |
| `work_order_type` | `ppm` (planned) \| `cm` (**corrective** — FR-CM-01) |
| `execution_type` | **FR-CM-02** — `internal` (in-house) \| `external` (vendor). **CM only**; null on PPM |
| `asset_id` · `unit_id` · `equipment_id` · `reference` | scope + auto `WO-{asset}-{YYYYMM}-{n}`, or **`CM-…`** for corrective |
| `title` · `category` · `status` · `scheduled_for` | the job (`open`\|`in_progress`\|`done`\|`cancelled`) |
| `priority` | **FR-CM-06** — `low`\|`medium`\|`high`\|`urgent`. Normal ≈ `medium`; decides the SLA |
| `acknowledged_at` · `target_resolution_at` | **FR-CM-07** — both stamped on **acceptance**, not creation |
| `sla_breach_notified_at` | idempotency stamp for the hourly breach scan |
| `description` | **FR-CM-04** — what is wrong. Required for CM; distinct from `notes`, which on a PPM order holds the plan's description |
| `vendor_id` · `assigned_to_user_id` | **FR-CM-03** — the company **or** the technician, never both |
| `source_item_id` | **FR-CM-01** — the failed check this CM came from |
| `parent_work_order_id` | **FR-CM-14/15** — the order this one follows up on |
| `completed_at` · `completed_by_user_id` | completion audit |

**Why CM lives here and not in module 11.** A CM raised from a failed check on a common-area
chiller has **no tenant and no unit** — and `tenant_requests.tenant_id`/`unit_id` are both NOT NULL.
Module 11 stays tenant-facing. A CM is a work order with a discriminator rather than a new entity,
so it inherits the state machine, the checklist, the FR-PPM-07 gate and the equipment link already
built here.

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

### `maintenance_work_order_parts` — spare parts on a job (FR-CM-09/10/11, FR-INV-04)
| Column | Meaning |
|--------|---------|
| `maintenance_work_order_id` · `source` (`internal`\|`external`) | the job, and where the part came from |
| `inventory_item_id` · `warehouse_id` | **internal only** — the SKU and the shelf it leaves |
| `description` · `vendor_id` · `reference` | **external only** — what was bought, from whom, on which supplier invoice |
| `quantity` · `unit_cost` · `value` | `value` is always derived (`qty × cost`) on every write path |
| `status` (`pending`\|`approved`\|`rejected`\|`recorded`) | `recorded` = an external purchase: nothing to approve |
| `required_permission` | the tier this draw needed **at request time** — frozen |
| `requested_by_user_id` · `decided_by_user_id` · `decided_at` · `decision_notes` | who asked, who ruled, why |
| `stock_movement_id` | the movement approval created — null until then |

**Why this is its own table and not a `pending` StockMovement.** `stock_movements` is the stock
*ledger*: a row there means stock actually moved, and every on-hand figure in the system is its
`SUM(quantity)`. A pending row would understate on-hand everywhere — the reorder colour, the
low-stock scan, the GL — to represent a draw that hasn't happened. A request is not a movement; it
becomes one on approval, and `stock_movement_id` is the link.

`source` is the fact the system previously could not express (FR-INV-04): an internal draw was
merely *implied* by a StockMovement existing, and a part bought outside was invisible rather than
recorded. "What did we buy outside this month, and from whom?" now has an answer.

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
2a. **An unknown `frequency_unit` throws — it is never guessed.** `advanceDue()` used to end in
   `default => addMonths()`, so an unrecognised unit was silently treated as MONTHS: a plan set to
   **"every 1 year" would have fired twelve times a year**. Every unit is now matched explicitly
   (`days|weeks|months|years` — FR-PPM-02) and anything else throws. The model refuses to *write* a
   bad unit, so the throw is a backstop for a direct DB edit or an import.

   > The throw is only safe because `GeneratePreventiveWorkOrdersService` contains failures **per
   > plan** (mirroring `ScanMaintenanceSlaBreachesCommand`) — otherwise one corrupt row would abort
   > the nightly run and every property would silently stop getting work orders. The command reports
   > `$service->failures` and exits non-zero, so a skipped plan is visible rather than lost.
2b. **The equipment rules are write-time validation and run only on change** (`isDirty`). Running
   them on every save was a trap: the scan calls `$plan->save()` after `advanceDue()`, so a plan
   whose machine had since moved property — or been hard-deleted, since `nullOnDelete` nulls
   `equipment_id` at the DB behind Eloquent's back, leaving a `fixed` plan with no machine — threw
   on every later save and **raised zero work orders from then on**. A fire pump that silently stops
   being inspected is far worse than a stale link. The move itself is blocked at the other end:
   **`Equipment` refuses to change property while any plan or work order references it** (the same
   principle as its sub-code rule).
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
7a. **Corrective maintenance is raised, never reopened** (`RaiseCorrectiveMaintenanceService`).
   Two entry points, both producing a **linked** order rather than mutating an existing one:
   - `fromFailedCheck()` — FR-CM-01. A PPM visit found a fault; the failed item stays linked as the
     CM's provenance. **One CM per failed check** — the action sits on a table row, which is exactly
     where a double-click lands, and without the guard one fault becomes two jobs and two engineers.
     A fail still does **not** block the visit closing: finding the fault *is* the visit succeeding.
   - `asFollowUp()` — FR-CM-14/15. Deliberately allowed on a **terminal** order, which is the whole
     point: the client prefers a new linked job to reopening, so the original's SLA and closure
     record survive for audit. It doesn't mutate the original, so terminal-immutability holds rather
     than being bent. Chains to any depth — some faults take three visits.
7b. **`execution_type` is a real XOR** (FR-CM-02/03): `internal` may not also name a vendor,
   `external` may not also name a technician. Module 11 permits a request to carry **both** at once,
   which is precisely why its assignment could never discriminate internal from external (see doc 11's
   gotchas). Repeating that here would make `execution_type` decorative — the classification has to
   constrain who is actually on the job. The service nulls the unused side so a caller passing both
   gets the intended record rather than an exception it can't explain to the user.

   > The CM guards run on every save, unlike the plan's (rule 2b) — and that is safe *because of
   > their shape*: they throw only when the **wrong** side is set, never when the right side is
   > missing. So deleting a technician or vendor (both `nullOnDelete`, which fires behind Eloquent's
   > back) leaves the CM saveable. Verified by probe, since this module has twice shipped a guard
   > that permanently froze a record.
7c. **The SLA clock starts on ACCEPTANCE** (FR-CM-07), not on creation, and only for CM.
   Module 11 does the opposite — it stamps `target_resolution_at` at create-time — so a request
   nobody picks up for three days has already burned its whole SLA before an engineer sees it, and
   the breach then measures the queue rather than the work. Accepting a CM (`open → in_progress`) is
   the moment the operator takes it on. The stamp is written **once**; a preventive order never gets
   a clock at all, because a scheduled visit's date is the plan's, not a response deadline.
   Consequence worth knowing: **an unaccepted CM never breaches.** That is deliberate — but it means
   "nobody accepted it" is a queue problem the SLA is not designed to catch.
7d. **Breaches are detected hourly** by `maintenance:scan-wo-sla-breaches` (separate from module
   11's `maintenance:scan-sla-breaches` — different subject, different table, its own stamp).
   Idempotent via `sla_breach_notified_at`, re-checked under a row lock inside the transaction, and
   contained per row. The stamp is written **even when the property has no staff to alert**, or a
   mall with nobody assigned would re-alert on every run forever.
7e. **Returning a property to the default is a deactivation, not a delete.** `sla_policies.is_active`
   exists because delete is **super_admin-only project-wide** — without it a manager could set an
   override but never remove one. Deactivating is an EDIT, so it respects that invariant instead of
   routing around it with a "reset" action that would be delete by another name. It also beats the
   workaround of retyping the default into the override: a pinned copy silently stops tracking the
   default the moment the default changes, whereas an inactive row genuinely falls back.
7f. **The penalty basis is configuration, not a decision in code** (FR-CM-08). The FRD says only
   *"automatically flag and calculate a penalty when a CM request exceeds its configured SLA
   duration"* — never on what basis. The three plausible readings (a flat fee, a per-day accrual, a
   share of the job's value) behave differently enough that guessing one and being wrong is a
   rewrite, not a tweak — so all three are supported and the contract picks.
7h. **A charged penalty is an AP offset, not a line on the bill** (FR-CM-08, money half).
   `vendor_bills` has no line items and `balance` is DERIVED by `VendorBill::recompute()` — the
   single source of truth for AP settlement, mirroring the Invoice AR invariant. So a penalty is a
   second offset that recompute() folds in, exactly as `credit_applied_amount` does for tenants.
   Nothing else writes `balance`. Only `applied` counts: `final` is assessed-and-owed, not deducted.
   - A penalty may **never exceed what the bill still owes** — AP would go negative, which is a
     receivable wearing a payable's clothes. Split it across bills instead.
   - **GL: `Dr Accounts Payable / Cr the same expense the bill debited`** — a cost reduction, not
     income, because money from a supplier adjusts the price paid to them unless it buys something
     distinct. The penalty follows the cost. **No VAT line**: liquidated damages are compensation,
     not a supply. Both are ⚠️ recorded in `docs/BUSINESS-RULES.md` for accountant sign-off.
   - ⚠️ **CAM does not follow automatically.** `CamExpensePool.total_actual_expense` is typed in by
     an operator, not derived from the GL — so whoever records the year's actual CAM spend must use
     the figure **net of penalties**, or tenants over-pay CAM while the operator keeps the penalty.
   - The AP **tie-out stays balanced**: the harness re-derives AP as the sum of bill balances, and
     both that and the GL's payable move by the same amount. Proven in the scenario test, because a
     phantom discrepancy at every monthly close would be worse than the feature.
7g. **A penalty is re-assessed, not computed once.** A per-day penalty grows while the job stays
   late. The obvious key — `sla_breach_notified_at` — is a *once-per-record* stamp for the alert,
   and would either charge an accruing penalty once or re-charge it hourly. So there is **one
   penalty row per work order** (a DB unique) and each scan **updates** it; the amount freezes
   (`final`) when the job reaches a terminal state. Re-running the scan is therefore free.
   - Lateness stops at completion, not at "now" — otherwise a closed job's overrun would keep
     growing in the archive and quietly bill more every day.
   - Assessed on closure too, since the scan only looks at OPEN orders: a job that breached and
     closed between two runs would otherwise escape entirely.
   - Part of a day counts as a whole day. Charging 0.4 of a day for a nine-hour overrun invites an
     argument nobody wants.
   - **Only external jobs.** FR-CM-08 is a contractual remedy against the company that missed its
     SLA; an in-house job running late is a management problem, not a billable one.
   - A `percent_of_value` contract with no `job_value` captured assesses **nothing** rather than
     zero — "we don't know yet" must not read as "assessed, owes nothing".
   - The terms are copied onto the row as applied. Re-deriving from the contract at read time would
     silently restate history the moment someone renegotiates the rate. For the same reason the
     governing contract is the one **in force when the job was scheduled**, not the latest.
   - The governing contract may be **portfolio-wide** (`vendor_contracts.asset_id` is nullable and
     the UI offers exactly that); a property-specific contract outranks it. `draft` and `terminated`
     contracts levy nothing — one was never in force, the other no longer is. **`expired` still
     levies**: `vendors:expire-contracts` flips that status once `end_date` passes, so excluding it
     would retroactively erase the penalty on a job that ran while the contract was live. The date
     window judges history; the status only rules out agreements that never applied.
   - **Waiving is terminal** — the hourly scan must never revive an operator's decision.
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

### Spare parts on a job (FR-CM-09/10/11, FR-INV-04)

Everything goes through `WorkOrderPartService`; the relation manager is a thin caller.

- **A draw is requested, not taken.** `requestInternal()` writes a `pending` row and moves **no**
  stock. `approve()` is the only path that calls `StockMovementService::record()` — which re-checks
  on-hand under its own lock, so an approval racing the last unit still can't drive stock negative.
  `reject()` is terminal: a refusal is a decision, not a draft, so the engineer raises a new request
  rather than editing the refusal away.
- **Which approver depends on the value** (FR-CM-11) — resolved by `ApprovalPolicy` against
  `approval_rules`, and **frozen onto the row** at request time (`required_permission`). Re-reading
  the ladder at approval would let an edit to the bands rewrite history about who was supposed to
  sign off. `unit_cost` is frozen for the same reason: re-reading the catalog would restate the
  value a manager was asked to approve — and the value is what picks the manager.
- **Two questions, both required, to decide a draw.** The tier from `ApprovalPolicy`, **and** the
  base inventory right (`WorkOrderPartService::DECIDE_PERMISSION` = `inventory.create`).
  `ApprovalPolicy` answers *"which manager"*, never *"may this person touch inventory at all"* —
  with no bands configured `canApprove()` returns true for **any** signed-in user (its own docblock
  says so, and the first cut of this service ignored it). Checking it alone made deleting the bands
  an open door: **proven** — a read-only `viewer` approved a 50,000 EGP draw and moved the stock.
  Both the service and the action's `visible()`/`authorize()` check both.
- **You cannot approve your own draw.** The FRD asks for a *manager's* sign-off; the control is a
  second pair of eyes. Without it an engineer holding `tier_1` self-serves every low-value part.
- **An outside purchase is recorded, not approved.** FR-CM-10 scopes approval to parts drawn *from
  internal inventory*. Gating an external buy would gate a purchase that has already happened, and
  procurement (FR-PROC-\*) is where that control belongs.
- **You draw from your own mall's shelf** — enforced in the **service**
  (`assertWarehouseServesOrder()`), not the form. The Filament options clamp too, but that only
  protects the one caller that goes through the form; a review probe proved a job on mall AAA could
  consume BBB's stock via the service, dropping BBB's on-hand and posting the cost to BBB's GL
  dimension. The *order's* property is the reference, not `visibleAssetIds()`: an All-Properties
  user may see both malls and still must not cross stock between them. The item catalog is
  deliberately **not** filtered — `inventory_items` is a SHARED register.
- **Money rules live at the write boundary, not on the form.** `unit_cost` uses `filled()`, not
  `??` — a blank `''` is not an absent value, and `?? ` lets `(float) '' === 0.0` price a part at
  zero and drop it to the lowest tier (an approval-ladder bypass by empty string; the
  `meter_readings.cost` trap again). Negative costs are refused in the model's `saving` hook, where
  every path passes, rather than by the form's `minValue(0)`.
- **An approved draw counts only while its movement is live.** Voiding the movement puts the stock
  back, so `counted()` follows the stock ledger (`whereHas('movement')`) rather than a status that
  nothing updates — otherwise the job stays charged for parts sitting back on the shelf (proven:
  on-hand 45 → 50 while `partsCost()` still said 500). `movementWasVoided()` surfaces it in the UI
  so the row doesn't just read "Issued".
- **An external record can be removed; an internal draw cannot.** External is the one path with no
  approval step to catch a fat-finger — it is typed in and counts immediately — so
  `remove()` soft-deletes it with a reason. An internal draw has its own undo paths (reject while
  pending, void the movement once issued); deleting one would strand the movement it made.
- **No parts on a terminal order** — consistent with the module's other writers.
- `MaintenanceWorkOrder::partsCost()` sums `approved` + `recorded` only: a rejected request cost
  the job nothing.

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
| **5 — Equipment ↔ plans/work orders (FR-PPM-01/02/03)** | `equipment_id` on `maintenance_plans` + `maintenance_work_orders` (PPM per machine, carried onto the order so history survives the plan), `maintenance_type` routine/fixed, `years` frequency + `advanceDue()` throwing instead of silently meaning months, per-plan failure containment in the scan | ✅ shipped |
| **6 — Corrective maintenance core (FR-CM-01/02/03/04/14/15)** | `work_order_type` ppm\|cm, CM raised from a failed check (one per check), internal/external as a real XOR, technician-or-vendor assignment, required description, `CM-` references, follow-up chains that never reopen the original | ✅ shipped |
| **7 — CM SLA + breach detection (FR-CM-05/06/07 + FR-CM-08 detection)** | `sla_policies` per property × priority with a settings/config fallback chain, 4 priority tiers, the clock starting on **acceptance**, the hourly breach scan + bell alert, an SLA-target column and breached filter on the list | ✅ shipped |
| **7b — SLA penalty assessment (FR-CM-08)** | penalty terms per vendor contract (**all three bases** — flat, per-day accrual, %-of-job-value — so the client's answer is configuration rather than a rewrite), one re-assessed penalty row per job, freeze on closure, waive with a reason, `job_value` for the percent basis, penalty column + waive action | ✅ shipped |
| **7c — Charging the penalty to the vendor (FR-CM-08 money)** | `vendor_bills.penalty_applied_amount` folded into `VendorBill::recompute()` (the AP single-source-of-truth, exactly as `credit_applied_amount` works on the tenant side), an apply/detach service with a cap so AP never goes negative, `MaintenancePenaltyJournalizer` posting **Dr AP / Cr the same expense the bill charged**, and a "charge to a bill" action that only offers bills able to absorb it. AP tie-out proven to stay balanced. ⚠️ The **treatment** (cost reduction, no VAT) and the **CAM consequence** are recorded in `docs/BUSINESS-RULES.md` and still need accountant sign-off | ✅ shipped, pending sign-off |
| **8 — CM parts + approval (FR-CM-09/10/11, FR-INV-04)** | `maintenance_work_order_parts`: an internal draw is **requested** and moves stock only on approval (its own table, *not* a pending StockMovement — see the domain model), the approver resolved by part value through the generic `ApprovalPolicy` ladder and frozen onto the row, self-approval refused, external purchases recorded with vendor + invoice ref, parts relation manager on the work order | ✅ shipped |
| **9 — Fault attribution + recharge (FR-CM-12/13)** | who caused the failure → who bears the cost, mall-vs-tenant recharge to an `Invoice` via `Charge`/`InvoiceItem` (respecting `recomputeTotals()` as the AR single source of truth) | ⬜ planned |

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

`tests/Feature/Scenarios/MaintenancePlanEquipmentScenarioTest.php` — FR-PPM-01/02/03: a yearly plan
advancing by a **year** (verified to fail if the old `default => addMonths` arm returns), the other
units still correct, an unknown unit refused on write and throwing on corrupt stored data, **one
corrupt plan not stopping every other property's work orders**, routine-by-default, fixed requiring
its machine, cross-property equipment refused, the machine reaching the work order and outliving the
plan — and the two "plan silently stops generating forever" traps (machine hard-deleted; machine
referenced by a plan refusing to move property).

`tests/Feature/Scenarios/SlaPenaltyScenarioTest.php` — FR-CM-08: each basis (flat charged once
however late; per-day accruing 200→400→600 as the job stays late; percent of job value), part of a
day counting as a day, a percent contract with no job value assessing nothing rather than zero, no
penalty for an in-house job or a contract with no terms or a job still inside its SLA, the contract
**in force when scheduled** governing, exactly one penalty however often the scan runs, the amount
freezing on closure and not growing afterwards, assessment on closure even if the scan never saw
the job, waiving with a reason that the scan never revives, and the terms surviving a later
renegotiation of the contract.

`tests/Feature/Regression/SlaGapsTest.php` — the two gaps the SLA slice shipped with: deactivating
an override returns a property to the default (an EDIT, so it respects the super_admin-only delete
invariant rather than routing around it) while keeping the row for reference, and a breached
corrective job now reaches the action-required dashboard — scoped, so it doesn't leak another
property's jobs.

`tests/Feature/Scenarios/WorkOrderSlaScenarioTest.php` — FR-CM-05/06/07/08: the fallback to the
operator default, a property override winning, each property on its own clock, an override touching
only the priority it names, one policy per property × priority, a zero-hour SLA refused, the clock
NOT starting at raise and starting on acceptance, an unaccepted job never breaching, a preventive
order never getting a clock, and the scan alerting once, never twice, never early, never after
completion, never on PPM — and stamping even when nobody is assigned to the property.

`tests/Feature/Scenarios/CorrectiveMaintenanceScenarioTest.php` — FR-CM-01/02/03/04/14/15: a CM
raised from a failed check inheriting where the work is, refused from a passing check, refused twice
for the same check, the PPM visit still closing afterwards, internal/external classification, the XOR
refused from both directions (and the service nulling the unused side rather than erroring), the CM
rules not leaking onto PPM orders, a follow-up linked to a closed order that stays closed, the chain
readable from both ends and to any depth, and `WO-`/`CM-` reference prefixes.

`tests/Feature/Regression/PpmRetiredEquipmentLockoutTest.php` — retiring a machine must not
deadlock the records naming it. Filament derives an `in:` rule from a Select's options and
validates the **stored** value against it, so filtering the equipment picker to `->active()` alone
meant that decommissioning a machine (`is_active=false` **or** a soft delete — both ordinary
lifecycle events) made its plan permanently unsavable, *including the attempt to deactivate it*,
while the scan kept raising work orders for it. Both forms therefore always include the record's own
stored machine. Invisible from the model layer — only driving the real form finds it.

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

`tests/Feature/Scenarios/WorkOrderPartsScenarioTest.php` — the parts rules through the **service**:
a request moves no stock, approval does (and links the movement back to the job), rejection doesn't;
the tier rises with the value and refuses a supervisor above their band (asserted with on-hand
deliberately *short*, proving the authority gate fires **before** the stock check, so the user is
told why rather than "not enough stock"); refusing needs the same authority as approving;
self-approval refused; the tier and the unit cost are frozen against later edits; **a read-only
viewer is refused even with the ladder deleted** (regression — see the business rules); internal vs
external shape guards; no parts on a terminal order.

`tests/Feature/Regression/WorkOrderPartGuardsTest.php` — the review findings, each proven before
its fix and each verified to fail without it: the cross-property draw, the blank-cost ladder bypass,
the voided-movement overcharge, negative costs, removing an external record (and refusing to remove
an internal one), the read-only viewer with the ladder deleted, and the two i18n bugs (a tier label
that rendered a raw translation key on every pending row, and a missing activity-log subject).

`tests/Feature/Resources/WorkOrderPartsRelationManagerTest.php` — the UI is gated the same way the
service is (viewer, ladder-deleted viewer, under-tier supervisor, and the requester themselves are
all offered no button), a draw from another mall's warehouse is **rejected on submit** rather than
merely absent from the dropdown, and the table renders **with rows of every source × status** — the
label/description/badge closures differ per shape, so a one-row table would prove almost nothing.

**Related:** 11 Maintenance (tenant-facing requests), 12 Vendors (assignees), 14 Departments,
01 Properties (asset scope), 18 RBAC (operations), 19 Notifications & Scans (the daily scan),
22 Inventory (the stock a draw comes out of), 28 Approvals (the value → approver ladder).
