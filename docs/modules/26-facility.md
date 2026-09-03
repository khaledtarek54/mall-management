# Module 26 — Facility (الصيانة الوقائية)

> **Renamed 2026-08-15 — this module no longer uses "maintenance" as an identifier.** It does
> maintenance, but so does module 11's request board, and by name alone the two were
> indistinguishable while being different modules with different RBAC, screens and tables. It is
> now **Facility** — the nav group these screens already lived under.
>
> | was | is |
> |---|---|
> | MaintenanceWorkOrder / …Item / …Part | **`FacilityWorkOrder`** / `…Item` / `…Part` (`facility_work_orders`) |
> | MaintenancePlan | **`ServicePlan`** (`service_plans`) — they schedule any facility service, not only maintenance, since 2026_07_22 |
> | MaintenancePenalty | **`SlaPenalty`** (`sla_penalties`) — matching `AssessSlaPenaltyService` / `ApplySlaPenaltyService`, which already used that word |
> | preventive_maintenance.* permissions | **`facility.*`** |
> | Modules::enabled('preventive_maintenance') | **`Modules::enabled('facility')`** |
> | maintenance:generate-preventive · maintenance:scan-wo-sla-breaches | **`facility:generate-preventive`** · **`facility:scan-sla-breaches`** |
> | maintenance_plans.maintenance_type | `service_plans.plan_type` |
>
> Migration `2026_08_15_150000` renames 5 tables + 5 columns, moves the 7 permission rows, and
> backfills 7 polymorphic columns — there is no morph map, so `journal_entries.source_type` and
> `stock_movements.source_type` both stored FQCNs. It asserts no stale class remains and throws if
> one does, because `LedgerPoster::sync()` responds to an unresolvable source by voiding and
> re-posting rather than erroring.

> **Status: Phase 7 shipped — CM SLA + penalty assessment + charging it to the vendor's bill.** Recurring
> facility **plans** that auto-raise **work orders** (with checklists) when
> due, via the daily `facility:generate-preventive` scan; two property-scoped Filament
> resources (plans + work orders), a checklist relation manager (**mark each item pass/fail**),
> status transitions (start / complete / cancel) owned by `FacilityWorkOrderService`, the
> `facility.*` RBAC (operations) + module flag, the **equipment register**
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
> **Gotcha, learned the hard way (2026-07-16).** `SlaPenalty` posts to the GL, so it
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


> **⚠️ The producer had no trigger (fixed 2026-08-18).** `facility:generate-preventive` raised
> preventive work orders nightly and **nothing in the panel could run it**. The service-plans screen
> already told an operator a plan was OVERDUE, and showed `last_generation_error` when generation was
> FAILING — and offered nothing to do about either; the remedies were waiting for cron or opening a
> shell. Found by sweeping the module for reachability: every other facility service was reachable
> from a screen, this one only from a command, while CAM's pool and a lease's billing both put the
> same act behind a button.
>
> Two actions now: **Generate due work orders** on the LIST header (the whole sweep, exactly as the
> nightly run does it) and **Generate now** on the plan's own Edit page (moved off the row
> 2026-08-30 — the list FINDS, the record ACTS; `App\Filament\Admin\Actions\ServicePlanActions`),
> which is what an operator wants when THIS plan is the one failing. `GeneratePreventiveWorkOrdersService::runFor()` routes through the same private
> path the sweep uses — the trigger type decides which — so a manual generation cannot take a
> different route from the automatic one and raise a different work order. Both report what happened:
> a generation that raised nothing is a RESULT, not a silence.
> `ServicePlanCanBeGeneratedFromTheScreenTest` pins idempotency (clicking twice, or clicking after
> cron has run, must not double the tenant's disruption), the not-due and inactive refusals, and that
> the two paths agree.


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
| `resolve_hours` | hours from **acceptance** (or from when acceptance was due — see §7c); must be ≥ 1 |
| `respond_hours` | hours from **creation** within which somebody must take the job on; **nullable**, meaning this property overrides only the resolution target and takes the operator-wide response target |

**A row is an override, not a requirement.** Absent, the operator-wide default applies, so an
operator records only the malls that genuinely differ instead of restating four numbers per
property. Deleting a row returns that property to the default. Resolution is
`App\Support\SlaResolver`: **property policy → `SlaSettings` → `config/sla.php`**.

> ⚠️ Tiers 2 and 3 **disagree** and did so long before this table existed: settings say
> urgent=4h/medium=72h, config says urgent=24h/medium=168h. Harmless only because nothing read
> config in practice. The chain now has one documented winner — **settings** — with config as a
> true cold-start default. Don't "fix" config to match: it is what a fresh install with no settings
> row gets, and changing it would silently re-time every such install.

### The working calendar — which clock an SLA is measured on (EG-08)

Egypt's weekend is **Friday–Saturday**, and until 2026-08-21 no clock in this system knew: every
deadline was `created_at->addHours($n)`, so a 24-hour urgent job raised Thursday 17:00 fell due
Friday 17:00 with nobody on site — and the vendor SLA penalty was charged off that.

Three pieces:

| | What it is |
|---|---|
| `holidays` | The register. Egypt's holidays are **announced, not computable** — the Eids move with the moon and mid-week holidays are routinely shifted to the Thursday — so the operator keeps the list. Two kinds: `closure` (nobody works) and `short_day` (reduced hours; **Ramadan** is the case it exists for). A null `asset_id` is national; a row naming a property beats it for that date, which is how one mall trades through Eid |
| `CalendarSettings` | The working week and the working day, portfolio-wide. Ships Sun–Thu 09:00–17:00 |
| `App\Support\WorkingCalendar` | The resolver. Pure date arithmetic — `isWorkingDay`, `windowFor`, `addWorkingHours`, `workingDaysBetween`. **Which** clock applies is `SlaResolver::clockFor()`, beside `hoursFor()`, because a second three-tier chain would be two ways to say the same thing |

**It ships off.** `SlaSettings::sla_working_clock_priorities` is empty, so every clock runs on bare
hours exactly as before. Whether "24 hours" means calendar or working hours is a contract term that
differs by priority, and it is the operator's ruling (STATUS.md C-SLA).

**The clock is FROZEN on the job.** `facility_work_orders.sla_clock` is stamped in
`stampSlaClocks()` alongside the two deadlines, and `daysOverSla()` reads it. Resolving it at read
time would have re-priced every job in flight when the setting changed — a pending penalty is
recomputed on every hourly scan and `SlaPenalty.amount` is DERIVED, so the posted entry would be
void-and-reposted. Same principle as labour freezing the craft rate at entry. Null means calendar,
which is what every order predating the feature carries and what a PPM order always will.

**The two overrun measures are COMMENSURATE, and that took a correction.** `daysOverSla()` charges
per day, and its calendar branch is `ceil(elapsedSeconds / 86400)` — elapsed *duration*. The first
cut of the working branch counted working days *touched*, which is a different quantity: an overrun
from Sunday 17:00 to Monday 09:00 contains no working time at all but touches two working days. So
the option sold as *"don't charge a contractor for Friday and Saturday"* charged an EXTRA day on any
overrun crossing a midnight — the ordinary case. It is now elapsed working seconds over the length of
a standard working day, rounded up, which is the same unit the calendar branch uses against the same
`sla_penalties.rate`.

**Floored at 1 day.** A breach falling entirely inside the weekend has zero working time in it, and
`0 × rate` would write a penalty reading "assessed and owed nothing" while a flat-basis penalty
charged in full for the same breach.

**Acceptance re-derives on the promised clock too.** FR-CM-07 recomputes the resolution deadline from
the moment a job is taken on; doing that in bare hours discarded the working deadline, and since the
working one is always later in wall-clock the `min()` that follows picked the calendar figure every
time — leaving a job stamped `working` whose deadline was not.

**Module 11 is wired too** (EG-38, 2026-08-21). `SlaSettings` is shared, so a knob honoured here and
ignored there meant `medium` had two meanings depending on whether the fault arrived as a work order
or a tenant request. Both modules now take the canonical clock names from `SlaResolver::CLOCK_*`
(neither owns that vocabulary) and both freeze the answer on the row —
`facility_work_orders.sla_clock` and `tenant_requests.sla_clock`. The measure has to follow the
clock, not just the deadline, and there are **four** of them — `daysOverSla()` here prices a GL
penalty, `hoursOverSla()` and `hoursOverResponseSla()` are frozen onto the `sla_penalties` row,
quoted in the breach bell and its email and printed on the list, and `TenantRequest::hoursOverSla()`
words the tenant-request bell. The middle two were missed for a week, so one penalty row read
"66 hours over" beside an amount priced at **one working day**. A figure that is breached floors at
1 on the working clock: an overrun that fell entirely across a weekend contains no working time, and
"0 hours late" on a late job is a false statement. On the calendar clock 0 still means "less than an
hour late", which is a different claim and is left alone. See
[modules/11](11-tenant-requests.md).

**Deliberately NOT in scope: PM compliance.** A PPM order never receives an SLA clock —
`stampSlaClocks()` returns early for anything non-corrective — and skipping Fri/Sat before calling a
preventive round late would be a *tolerance window*, which this module refuses by design.

### `sla_penalties` — what a vendor owes for missing an SLA (FR-CM-08)
| Column | Meaning |
|--------|---------|
| `facility_work_order_id` | **unique** — one penalty per job |
| `vendor_id` · `vendor_contract_id` | who owes it, and under which contract |
| `basis` · `rate` · `hours_over_sla` · `amount` | the terms **as applied**, frozen onto the row |
| `status` | `pending` (accruing) \| `final` (frozen, chargeable) \| `waived` |
| `waived_at` · `waived_by_user_id` · `waive_reason` | the operator's decision not to charge |

Terms live on `vendor_contracts.sla_penalty_basis` + `sla_penalty_rate` (`none` by default —
penalties are opt-in per contract, since most won't have one negotiated). The `percent_of_value`
basis reads the work order's own service cost — `act_service_cost` once a bill has landed, falling
back to `est_service_cost` — never a hand-typed figure beside it (see *`job_value` was REPLACED*).

### `service_plans` — the recurring schedule (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` · `unit_id` | property + optional unit (null = common / asset-wide) |
| `equipment_id` | the **machine** this plan services (FR-PPM-03); null = property/unit-wide |
| `maintenance_type` | **FR-PPM-01** — `routine` (recurring schedule) \| `fixed` (per machine) |
| `title` · `category` · `description` | what to do |
| `frequency_unit` · `frequency_value` | how often (`days`\|`weeks`\|`months`\|**`years`** × N) |
| `trigger_type` | **`time`** (the calendar) \| **`usage`** (a counter) — see *Usage-triggered plans* below |
| `utility_meter_id` · `usage_threshold` · `usage_at_last_generation` | the counter a usage plan watches, how far it must move, and the reading it was last serviced at |
| `checklist` (json) | the template check items |
| `department_id` · `vendor_id` | default assignee |
| `next_due_date` · `last_generated_at` · `is_active` | scheduling state |

⚠️ **FR-PPM-01 is only half-encoded, deliberately.** The FRD defines Fixed as *"performed on a
defined one-time **or periodic** basis per asset"* — two different things. Encoded: the
discriminator, and that a `fixed` plan must name its equipment. **Both types still recur**; a
one-time plan means deactivating it after its first run. Whether one-time needs first-class support
is an **open client question** — don't guess it into the schema.

### `facility_work_orders` — a raised job (preventive or corrective)
| Column | Meaning |
|--------|---------|
| `service_plan_id` | the source plan (null = ad-hoc or corrective) |
| `work_order_type` | `ppm` (planned) \| `cm` (**corrective** — FR-CM-01) |
| `execution_type` | **FR-CM-02** — `internal` (in-house) \| `external` (vendor). **CM only**; null on PPM |
| `asset_id` · `unit_id` · `equipment_id` · `reference` | scope + auto `WO-{asset}-{YYYYMM}-{n}`, or **`CM-…`** for corrective |
| `title` · `category` · `status` · `scheduled_for` | the job (`open`\|`in_progress`\|`done`\|`cancelled`) |
| `priority` | **FR-CM-06** — `low`\|`medium`\|`high`\|`urgent`. Normal ≈ `medium`; decides the SLA |
| `target_response_at` | **FR-CM-07** — stamped at creation; how long the job may sit before anybody takes it on |
| `acknowledged_at` | when somebody took it on — stops the response clock |
| `target_resolution_at` | **FR-CM-07** — stamped at creation from the response deadline, pulled IN on an early acceptance, never pushed out by a late one |
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

### `facility_work_order_items` — the checklist (child of a work order)
| Column | Meaning |
|--------|---------|
| `facility_work_order_id` · `label` | the item |
| `result` (`pending`\|`pass`\|`fail`) | the outcome — **the single source of truth** for item state |
| `marked_at` · `marked_by_user_id` | who recorded the outcome, when |

`result` **replaced** an `is_done` boolean (migration `2026_07_16_100001`), which could not express
a failed inspection — the state a PPM visit exists to find (FR-PPM-07), and the trigger for
corrective maintenance (FR-CM-01). One column, not a boolean *and* an enum: two columns encoding the
same fact drift. `FacilityWorkOrderItem::getIsDoneAttribute()` survives as a **read-only**
back-compat accessor (`result !== 'pending'`); write `result`. `done_at`/`done_by_user_id` became
`marked_at`/`marked_by_user_id` — "done" read wrong beside `result = fail` and collided with the
work order's own `completed_at`. Indexed `(facility_work_order_id, result)` for the gate + the
progress badge.

### `facility_work_order_parts` — spare parts on a job (FR-CM-09/10/11, FR-INV-04)
| Column | Meaning |
|--------|---------|
| `facility_work_order_id` · `source` (`internal`\|`external`) | the job, and where the part came from |
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

## Equipment criticality (2026-08-11)

`equipment.criticality` — **critical** (trading stops or someone is unsafe) · **important** (a
service degrades) · **routine**. Three values, not five: a scale nobody can apply consistently is a
field that gets left on its default.

**It changes what happens, which is the whole point of the field.**

- A **corrective** job raised against the machine starts at `urgent` (critical) or `high`
  (important); routine keeps the previous fixed `medium`, so nothing that existed before changes.
- A **preventive** round inherits the same — the generator set no priority at all, so every plan
  produced `medium` whatever it was servicing.
- The **work-order create form** pre-fills the priority when a machine is picked, visibly, so it
  reads as a suggestion rather than magic. Only on create: re-picking the machine on an existing job
  must not silently re-grade a priority someone already decided.

**Two rules worth keeping straight:**

1. **An operator who states a priority gets it.** They can see the machine and the system cannot,
   and a system that quietly disagrees with an explicit choice teaches people to distrust the field.
2. **On the tenant-request path it takes the HIGHER of the two** — the tenant's reported priority and
   the machine's. The tenant's figure is their view of the disruption and is usually the default;
   criticality is the business's view of the machine. Taking the higher can only raise a job, never
   quietly lower one. Under-prioritising a chiller because a tenant ticked "medium" is the failure
   this field exists to prevent.

An unknown or blank value falls back to `routine`, not `critical`: guessing the alarming end would
page someone at 2am for a hand dryer, and that is how an alert channel stops being read.

Tests: `tests/Feature/Regression/EquipmentCriticalityTest.php` (7).

## A work order has a thread (2026-08-28)

`facility_work_order_comments` — author (morph), `body`, `is_internal` — with
`CommentOnWorkOrderService`, `WorkOrderCommentsRelationManager` on the work order, and
`AWorkOrderHasAThreadTest`.

**What it replaced.** `facility_work_orders.notes`: one field, no author, no timestamp, last writer
wins. So the conversation a job actually generates — access arranged for Sunday, part on
back-order, the tenant refused entry — either overwrote itself or lived in somebody's WhatsApp.
`TenantRequest` has had exactly this since module 11; the work order, which generates *more*
conversation because a third party executes it, had nothing. `notes` stays as it was: the
operator's own single field, not a thread.

**Three rules, each with a reason:**

- **`is_internal` defaults to FALSE.** A comment is a conversation until someone says otherwise.
  Defaulting to internal would make the vendor portal silent by accident and nothing would error —
  the contractor would simply never hear anything, which is the hardest failure to notice.
- **Flipping `is_internal` is a DISCLOSURE, not an edit.** It publishes a staff note to a party
  outside the company, so the action confirms and is gated in both `visible()` and `authorize()` —
  the same treatment the tenant thread gives its own toggle.
- **A terminal job's thread is closed.** A done or cancelled work order is immutable; a thread that
  still accepted messages would be the one mutable part of a record an auditor reads as settled.

**Why a single-action service rather than a `create()` at the call site.** There will be two authors
and two panels: staff in `/admin`, and a contractor in `/vendor` at step 3 of
[12b](12b-VENDOR-PORTAL-DESIGN.md). Two code paths writing one row is how the two come to disagree
about who may write and when — which §9 of that design names as the risk worth avoiding.

**This is step 1 of the vendor portal, built first because it is the only new domain object that
design needs and it is useful on its own.** Everything else the portal wants is a screen over
something already built.

## Accept is its own act (2026-08-28)

`AcceptWorkOrderService` stamps `acknowledged_at` — the field the response SLA is measured to
(FR-CM-07: the clock starts at acceptance, so an engineer is not charged for queue time).

**What changed and why it matters more than it looks.** Until now `acknowledged_at` was set as a
SIDE EFFECT of staff moving the job to `in_progress`. So the response time this system has been
reporting is *the moment a coordinator got round to updating a column*, not the moment the contractor
agreed. Letting the contractor stamp it themselves — from `/vendor`, step 3 of
[12b](12b-VENDOR-PORTAL-DESIGN.md) — is, in that design's words, "the single biggest change in data
quality, and it costs nothing new to build".

- **Idempotent and lock-safe.** Two contacts at one contractor both pressing accept must not move the
  clock, and the second must not see an error either — they did what they were asked. The re-read
  happens INSIDE the transaction after the lock, because a value read before the wait answers from a
  pre-commit snapshot.
- **A terminal job cannot be accepted** — there is nothing left to agree to, and stamping the clock
  afterwards rewrites a response time already reported.
- **The admin action was ADDED, not replaced.** §9 of the design names the risk that would make the
  portal a bad idea — contractors who will not log in — in which case `acknowledged_at` stops being
  filled by staff and starts being filled by nobody, making the SLA *worse* than before. So the
  operator keeps "Accept for the contractor", and **both sides call the one service**: two ways to
  accept must not mean two code paths.
- **WHO accepted is in the activity trail, not a column.** The work order is audited so the stamp is
  recorded; what a diff cannot say is whether the contractor agreed or a coordinator agreed for them,
  and that is the whole difference this makes to the SLA figure.

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
2. **`facility:generate-preventive`** (daily 02:30) raises a work order for every **due**
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
   > plan** (mirroring `ScanTenantRequestSlaBreachesCommand`) — otherwise one corrupt row would abort
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
5. **Marking checklist items** is gated on `facility.complete` and captures
   who/when; **editing the checklist / plan / work order** on `facility.edit`.
6. **A work order cannot close while any checklist item is `pending`** (FR-PPM-07). Enforced by
   `FacilityWorkOrderService::transition()` — not by the UI. Three deliberate carve-outs:
   - **A `fail` does not block closure.** Finding a fault *is* the visit succeeding; the fault
     becomes corrective maintenance (FR-CM-01). Only an item nobody looked at blocks.
   - **An order with no checklist is vacuously complete** — the gate must not strand ad-hoc
     orders that never had items.
   - **Cancelling ignores the gate** — that's abandoning the visit, not completing it.
7. **The state machine lives in `FacilityWorkOrderService::TRANSITIONS`**, mirroring
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
7c. **TWO clocks (FR-CM-07, extended 2026-08-12).** A corrective job has a **response** deadline
   from creation and a **resolution** deadline from acceptance, and they answer different questions:
   *did anybody take this on* and *did they fix it in time*. Preventive orders have neither — a
   scheduled visit's date is the plan's, not a response deadline.

   The resolution rule in one sentence: **a job has `resolve_hours` from the moment it was accepted,
   or from the moment it should have been — whichever came first.** So `FacilityWorkOrder::
   stampSlaClocks()` writes both at creation (the resolution one measured from the response
   deadline), and `FacilityWorkOrderService` pulls the resolution deadline IN when the job is
   accepted early. Accepting **late cannot push it out** — `min()`, not assignment — because
   ignoring a job must not buy more time to finish it.

   FR-CM-07's intent is unchanged: module 11 stamps `target_resolution_at` at create-time, so a
   request nobody picks up for three days has burned its whole SLA before an engineer sees it, and
   the breach measures the queue rather than the work. An engineer who accepts inside the response
   window still gets their **full** window from that moment, and is never charged for queue time.

   **What this replaced.** `target_resolution_at` used to be written in exactly one place — the
   manual `open → in_progress` hop — and `open → done` is a legal transition. An external job could
   therefore be created, worked for three weeks and closed with the target still null;
   `isSlaBreached()` requires a non-null target, so the hourly scan, the penalty gate, the table
   filter and the dashboard card all skipped it permanently. *Not clicking Start was a silent way to
   waive a vendor's penalty, with nothing recording that it happened.* Two scenario tests asserted
   that behaviour as if it were the rule, one of them named "sits on an unaccepted job indefinitely
   without ever breaching"; both were rewritten in the same change.

   **What is deliberately NOT here:** a response breach carries no monetary penalty.
   `AssessSlaPenaltyService` implements FR-CM-08, which is about a job that ran late, and whether an
   unanswered job is separately chargeable is a contract question for the operator — not one to
   invent in code. It is alerted, filtered and counted; it is not billed.
7d. **Breaches are detected hourly** by `facility:scan-sla-breaches` (separate from module
   11's `requests:scan-sla-breaches` — different subject, different table, its own stamp).
   Idempotent via `sla_breach_notified_at`, re-checked under a row lock inside the transaction, and
   contained per row. The stamp is written **even when the property has no staff to alert**, or a
   mall with nobody assigned would re-alert on every run forever. The response breach runs the same
   shape off **its own** stamp (`response_breach_notified_at`): a job answered late but fixed on
   time is a different conversation from one answered on time and fixed late, and sharing a stamp
   would let whichever clock breached first silence the other. The scan also **backfills** the two
   clocks onto corrective orders raised before they existed — done there rather than in a migration
   because the targets resolve through live settings and per-property policies, and a migration that
   reads application state breaks the day that state changes shape.
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
   - A `percent_of_value` contract with neither an actual nor an estimated service cost assesses
     **nothing** rather than zero — "we don't know yet" must not read as "assessed, owes nothing".
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
   `FacilityWorkOrderService::withOrderLock()`, which row-locks the `facility_work_orders`
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

### Usage-triggered plans — running hours, not the calendar (2026-08-17)

Every plan was time-driven. That is right for a statutory round ("extinguishers, annually") and
wrong for the machines a mall runs: a chiller, a lift and a generator are serviced on **running
hours**. A genset idle for six months needs nothing; one running double shifts needs servicing twice
as often. **A calendar plan gets both wrong in opposite directions** — over-servicing the idle
machine and under-servicing the hard-worked one, which is the failure the interval exists to prevent.

**Usage is read from a meter**, because `meter_readings.reading_value` is already a cumulative
counter with a per-property register, a reading workflow, an import path and property scoping around
it. A separate "equipment runtime" table would duplicate all of that to hold the same shape of
number. `utility_meters.type` gained **`hours`** in the same change — a one-line `ValueSets` edit now
that no enum survives in the DDL. An hours meter is monitored and never recharged, which the
recharge path already handles (no tariff + no override = 0, and a zero-cost recharge is refused).

**`trigger_type` is an XOR.** "Every 500 hours OR every 12 months, whichever comes first" is a real
CMMS pattern and is deliberately **not** built: it is a third mode with its own reset semantics (does
the calendar clock restart when the usage trigger fires?) and nobody has said which answer they want
— the same discipline this doc already applies to FR-PPM-01. It arrives as a third value in
`ValueSets` plus one branch in the generator, with no migration.

**The load-bearing line is `scopeDue()`'s `trigger_type` filter.** `next_due_date` is NOT NULL, so a
usage plan carries one like every other row; without that clause it would match the time round *as
well as* its counter and raise **two work orders for one service**. Pinned by
*"never lets a usage plan also fire on its calendar"*.

**The baseline is seeded, not zero.** A meter installed years ago reads 40,000 hours — measuring the
first delta from zero would make a new plan instantly overdue by eighty thresholds and raise a
backlog of services that were never missed. `ServicePlan::saving()` seeds
`usage_at_last_generation` from the counter's current value when a plan starts watching a meter, or
is pointed at a different one.

**The baseline advances to the triggering READING, not by the threshold.** A machine that ran 700
hours between reads has had 700 hours of wear; crediting it with only 500 would leave 200 banked and
raise a second, immediately-due job for work just scheduled.

Other decisions worth knowing:

- **A rolled-over or replaced counter reads LOWER than the baseline.** Clamped at 0, so the plan is
  simply not due and the operator re-baselines by saving it. Reported negative, the arithmetic would
  go true again the moment the counter passed the *old* baseline — months of wear too late.
- **"Unknown" is not "zero".** No meter, no reading or no baseline returns `null` from
  `usageSinceLastGeneration()`, never `0.0` — zero would read as "not due yet" forever on a plan that
  is actually misconfigured, the silent-failure shape `last_generation_failed_at` already exists to
  avoid.
- **Due-ness is decided in PHP, not SQL.** `scopeUsageTriggered()` is only a cheap pre-filter; the
  real test is `isDueByUsage()`, re-run under the row lock. A join that decided it would be a second
  copy of the rule, and the scan would be the only thing that knew it.
- **The order says the counter raised it** (`admin.service_plans.raised_by_usage` in the notes), so a
  technician knows it was hours and not the calendar — and it still says so after the plan is edited
  or deleted.

Tests: `tests/Feature/Regression/ServicePlanTriggersOnUsageTest.php`.

### Assignment is an XOR on a CORRECTIVE order, and deliberately not on a plan

`FacilityWorkOrder` enforces a real either/or through `execution_type` (FR-CM-02/03): an
`internal` order cannot also name a vendor, an `external` one cannot also name an in-house
technician. Module 11 lets a tenant REQUEST carry both at once, which is exactly why assignment
could not serve as the internal-vs-external discriminator and why `execution_type` exists.

**That guard is scoped to `TYPE_CM` on purpose, and a preventive plan is exempt.** A
`service_plans` row may name a `department_id` AND a `vendor_id`, and
`GeneratePreventiveWorkOrdersService` copies both onto the generated order without classifying it.

The asymmetry is intentional, not an oversight: a corrective job is dispatched to ONE party now,
while a preventive round genuinely splits — the in-house team does the monthly filter change and a
contractor does the annual statutory inspection, off the same plan. Forcing the XOR here would make
that unrepresentable.

**The consequence to know about:** a generated preventive order carries no `execution_type`, so it
cannot be filtered or reported as internal-vs-external the way a corrective one can. If that
reporting is ever wanted, the change is to classify the plan and stamp the order — not to tighten
the CM guard, which is already right for what it covers.

### A plan that cannot generate says so

Two correct decisions used to combine into a silent failure. `generateFor()` wraps a cycle in one
transaction, so a throw undoes `advanceDue()` with everything else — right, because a statutory
round must not be skipped just because tonight's attempt failed. And `run()` contains the failure
per plan — right, because one bad row must not stop every other property. Together: **the plan
retried the same cycle every night, forever**, and the only trace was a `Log::warning` plus a
non-zero exit from a cron job that has no `onFailure` hook anywhere in `routes/console.php`. The
lift inspection stopped and nobody was told.

Three changes, 2026-08-12:

| | |
|---|---|
| **The round still happens** | A contractor who cannot be dispatched no longer cancels the WORK — only the assignment. The order is raised with `vendor_id = null` and a note on it naming the vendor and the reason, for a coordinator to reassign. The compliance gate governs *who is sent to site*, not whether the inspection exists; ServiceChannel and Corrigo behave the same way. |
| **Being stuck is visible** | `service_plans.last_generation_failed_at` + `last_generation_error`, written **outside** the rolled-back transaction (the stamp is the only surviving trace of an attempt that undid everything else it did) and cleared inside the transaction that finally succeeds. Surfaced on the row: an icon and the reason under the due date, plus a **"Not generating (stuck)"** filter — because a stuck plan and an overdue plan look identical, a date in the past, which sends somebody chasing a technician for a round the system never asked anybody to do. |
| **Somebody is told** | `PreventiveGenerationFailedNotification`, mail + bell, to the property's managers and operations staff (`AssetStaffRecipients`), on the transition into failure only — a nightly repeat of a known problem is a message people filter. |

**The plan still does not skip the cycle.** A missed statutory round is a backlog item, not something
to step over; what changed is that the backlog is now visible. The concrete trigger was the plan
contractor's insurance lapsing — and the *renewal* case was itself a bug, fixed in
[module 12](12-vendors.md#compliance-documents--vendor_documents-module-12b).

**Tests:** `tests/Feature/Regression/PreventiveGenerationDoesNotStopSilentlyTest.php`.

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
- `FacilityWorkOrder::partsCost()` sums `approved` + `recorded` only: a rejected request cost
  the job nothing.

### What reaches the general ledger (and what deliberately doesn't)

| Thing | GL treatment |
|-------|--------------|
| An **approved internal draw** | posts via its `StockMovement` → `InventoryMovementJournalizer`: **Dr** repairs & maintenance / **Cr** inventory, dimensioned to the property that owns the stock. Stock becomes cost the moment it is issued. |
| A **voided** draw's movement | the entry is voided and `counted()` stops charging the job — the cost comes back out with the stock. |
| A **pending** or **rejected** draw | nothing. No stock moved, so there is nothing to recognise. |
| A **recorded external purchase** | **nothing** — see below. |
| An **applied SLA penalty** | posts via `SlaPenaltyJournalizer` (**Dr** AP / **Cr** the expense the bill charged). |

`FacilityWorkOrderPart` is deliberately **not** a GL source: the `StockMovement` is the
accounting event, and giving the part its own journalizer would post the same cost twice.

> ⚠️ **The external-purchase seam — a known reporting gap.** A part bought outside never touched
> our stock, so there is no inventory to relieve and no movement to post. Its accounting document
> is the **vendor bill** (Dr expense / Cr AP), which posts through its own journalizer — but
> nothing links the two, and nothing forces a bill to exist for a recorded external part. So
> `partsCost()` can exceed what the GL knows about the job.
>
> This is a *reporting* gap, not a GL imbalance: an unrecorded purchase is **absent** from the
> books rather than **wrong** in them, and `billing:reconcile` stays green (proven in
> `WorkOrderPartLedgerDispatchTest`). Don't "fix" it by journalizing the part — that would
> double-count the moment someone also enters the bill. The seam closes with procurement
> (FR-PROC-\*), where the bill is raised against the request that caused it.

### Raised from a tenant's report (module 11 → 26)

A tenant reports a fault (a `TenantRequest`, module 11); staff raise a corrective work order to fix
it. `RaiseCorrectiveMaintenanceService::fromTenantRequest()` builds the work order and links it back
via `facility_work_orders.tenant_request_id`.

- **The link did not exist in either direction.** The closest was `source_item_id` — a CM off a
  *failed PPM check*, a different origin. A tenant-reported fault had no path to a work order at all.
- **The request supplies WHERE the work is** — its unit (and thereby property), category and
  department are facts about the fault. Its title/description/priority pre-fill the form so an
  engineer isn't retyping the tenant's complaint.
- **One request → many work orders** (a flood needs plumbing AND electrical); a work order services
  at most one request, so the FK is on the work order.
- **`nullOnDelete`, never cascade.** The facility work is a real event with its own cost and GL
  trail; deleting the tenant's ticket must not erase it. The link is provenance, not ownership.
- Gated on `facility.create` (the action creates a work order), not on the request's
  own permissions — triaging a ticket and raising facility work are different rights.
- **This is what FR-USR-06's evidence clause stands on:** a request may later be completed with "an
  uploaded image **or a linked work order**". The gate itself is a separate change.

---

### Fault attribution & cost bearer (FR-CM-12, FR-CM-13)

**Read the FRD's verbs before changing this.** Verbatim:
> FR-CM-12 — "For parts sourced from outside, the system shall **determine responsibility** (and who
> bears the cost) based on **who caused the part to fail, as recorded on the work order**."
> FR-CM-13 — "The system shall **record** whether the mall or the tenant is financially responsible
> for a repair, based on who caused the damage."

*Determine* and *record*. **No requirement anywhere in the FRD asks the system to invoice, bill, or
recharge a tenant**, and its own *Open Items* list never raises it. This module therefore records the
finding and derives the bearer — and stops. Khaled confirmed record-only (2026-07-16). The recharge
seam is documented-but-unbuilt in `AttributeWorkOrderFaultService`'s footer, with the questions that
must be answered first (BUSINESS-RULES open question 14). **Nothing in module 26 can bill anyone.**

- **The bearer is derived, not typed in** (`FacilityWorkOrder::bearerFor()`), because FR-CM-13
  says "based on who caused the damage". Only `fault_party = tenant` lands on the tenant.
- **Vendor fault maps to the mall on purpose.** Reading "the vendor broke it" as "the vendor pays"
  is the obvious mistake: FR-CM-13 offers only mall|tenant, and recovering from a contractor is a
  different mechanism (the SLA penalty against their bill, FR-CM-08). Encoding `vendor` as a bearer
  would quietly answer a question the FRD never asked.
- **`undetermined` lands on the mall** — you cannot bill someone on a shrug. The burden of proof is
  on the party making the claim.
- **A manager rules, not the engineer** (`facility.attribute_fault`, withheld from
  `operations`). Recording what you found is engineering; asserting that a *tenant* is financially
  responsible is a commercial claim — the same second-pair-of-eyes principle as FR-CM-10.
- **You cannot blame a tenant who does not exist.** A work order carries a NULLABLE `unit_id` — a
  common-area chiller has no occupier — so the service refuses `bearer = tenant` when
  `bearingTenant()` is null (no unit, or a vacant one). This is the case that would otherwise
  produce a claim addressed to nobody. The tenant is resolved live through the unit's active lease,
  never stored: the answer must be "who occupies that unit", not "who occupied it when someone
  clicked".
- **Allowed on a `done` order, refused on a `cancelled` one.** The cause is usually only known once
  the machine is open, and FR-CM-12 wants it "as recorded on the work order" — refusing it after
  closure would mean the finding could never be recorded at all. Terminal immutability protects the
  record of the *work*; this is the commercial finding about it. A cancelled job never happened, so
  there is no cost to apportion.
- **Revisable, with provenance.** A cause is often revised once the engineer opens the machine;
  freezing the first guess would make the record *less* true. `fault_recorded_by_user_id` /
  `fault_recorded_at` / `fault_notes` are the control, rather than immutability. The activity log
  additionally records the before/after diff of `fault_party` / `cost_bearer` / `fault_notes`.
- **FR-CM-12's external-part scoping** — `FacilityWorkOrderPart::costBearer()` *reads* the job's
  attribution rather than storing its own copy, exactly as the FRD says ("as recorded on the work
  order"); a copy could disagree the moment someone revises the finding. Internal draws return null:
  FR-CM-12 is scoped to parts "sourced from outside", and our own stock is our own cost.

---

## 3. RBAC & module flag

- Permissions `facility.view/create/edit/delete` (delete = super_admin only) +
  `facility.complete` (tick items, mark done). Granted to the **operations**
  role (maintenance/dispatch); **manager** (all non-delete) + **viewer** (all `.view`) inherit
  via the flat list.
- `facility.attribute_fault` (FR-CM-12/13) is **deliberately withheld from
  `operations`** — it is the one permission in this module that is a commercial judgement rather
  than an operational one. manager + super_admin only.
- Module flag **`facility`** (`Modules::KEYS` + `ModulesSettings`), on by default.
- Both the plan + work-order resources share `permissionModule()='facility'`.

---


### Evidence on a job (2026-08-19)

A work order carries an **`evidence` media collection** — photographs and paperwork — on
`useDisk('local')`, i.e. private. The gap analysis had this filed as "closes on its checklist with
no required evidence"; reading the code said something stronger, that `FacilityWorkOrder` did not
implement `HasMedia` at all, so there was no way to attach a photograph in the first place. The
missing thing was the capability, not the gate over it.

It matters for a mall specifically because the work order is the record that settles arguments
later — with the tenant about what was done inside their shop, with the vendor about whether the
job justified the invoice, with an insurer about the state of plant before a failure. None of
those are winnable from a list of ticks.

**One collection, not a before/after pair.** Which photograph is "before" is a judgement made at
upload time and frequently got wrong, and a mislabelled pair is worse evidence than an unlabelled
set: it asserts something false about a job somebody may later be billed for. Upload order and the
file's own timestamp carry the sequence.

**Uploadable after closure, deliberately.** A photograph is the one thing an engineer legitimately
adds after the fact — the job is finished, the phone is in their pocket. The commercial fields stay
frozen; refusing the upload too is how a record ends up with no evidence at all.

**Requiring one is `SlaSettings::$require_completion_evidence`, and it ships OFF.** Not timidity:
switching it on mid-flight refuses the next completion every engineer attempts, on jobs they have
already finished, over a rule nobody told them about — and the reliable outcome is a photograph of
a wall, taken to clear the validation. Evidence collected to satisfy a gate is worse than none,
because it looks like proof. Attachments first, habit second, requirement third. Same posture as
straight-line rent and the NSF fee.

The guard lives in `FacilityWorkOrderService::assertEvidencePresent()`, beside
`assertChecklistComplete()` — in the **service**, because `transition()` is the one road to `done`
(the Filament action, the console and any future API all arrive through it) while a form guard
protects one screen. It throws a `DomainException`, so it renders as a toast telling the engineer
what to do rather than a 500.

### Permit to work — authorising hazardous contractor work (2026-08-19)

**An EXTENSION, and flagged as one.** Voyager does not model safety permits — it is lease
administration, and the benchmark folder has *zero* hits for hot work, isolation or permit-to-work.
This follows the FM/CMMS standard, where ServiceChannel, Facilio and Maximo all treat a permit as
core, and ordinary safety practice. The house rule is to name the Voyager construct or admit the
invention: admitted.

`work_permits` is a register, not a form. A contractor cutting or welding in a plant room,
isolating a panel, or working above a trading floor is a risk the operator carries whether or not
anyone wrote it down.

**Two properties make it a control rather than paperwork, and both are load-bearing:**

1. **Bounded to the HOUR.** `valid_from`/`valid_to` are datetimes. "Hot work permitted on Tuesday"
   is not a permit; a permit good for a whole day is one somebody uses at 19:00 after the fire
   officer has gone home.
2. **It must be CLOSED, and an issued permit past its window with no closure is *the finding*.** It
   means nobody recorded that the welding stopped and the area was checked — the first thing an
   insurer or a safety auditor asks for. Like the post-dated-cheque coverage gap, it is invisible on
   every screen that shows what EXISTS, because the missing thing is a closure that was never
   written.

**There is deliberately no `expired` status.** Expiry is a fact about the clock, not a decision
anybody made, and a sweep flipping permits to `expired` would quietly close the very question the
register exists to ask. `WorkPermit::hasLapsed()` derives it; `work_permits.status` is recorded in
`App\Support\ProjectedState::NOT_PROJECTED` with that reasoning, precisely because it looks like a
projection and must not become one.

**Issuing reuses `Vendor::isDispatchable()`.** A contractor who is blacklisted or whose compliance
documents have lapsed is exactly who a permit must not be issued to. `FacilityWorkOrder::saving`
already refuses to dispatch them; a permit issued to the same contractor would be that hazard with
a signature on it. Reusing the predicate rather than re-testing the conditions is what stops the
permit becoming the one door left open. A permit with **no** registered vendor is allowed — a named
individual, a tenant's own fitter — and then the contractor's name and phone are the record.

**Issuing is its own right (`work_permits.issue`), separate from `work_permits.edit`.** Editing a
draft and authorising hazardous work are not the same act, and the second is what a named person is
accountable for.

**And once it is issued, what it authorises is FIXED (SW-066, 2026-09-03).** The register hides its
Edit shortcut the moment a permit leaves draft, under a comment stating that rule in as many words —
*"a live authorisation is not a draft"* — and for the whole of this module's life that was the only
thing enforcing it. A hidden row action is a rendering decision: `EditWorkPermit` is the record hub,
it is reached by URL, `canEdit()` went on answering true, and nothing at the model refused the save.
So an issued permit could be re-pointed at another unit, its window extended or its conditions
rewritten while people were already working under it — and the guard at the door, and the manager
acting on the hourly closure alert, would both be reading something nobody authorised.

`WorkPermit::updating` refuses it, on a **denylist of substance** (what work, where, when, under what
conditions, by whom) rather than an allowlist of what the acts write — `getDirty()` is read after
every `saving` hook, so an allowlist refuses any save carrying a column some other hook derived. The
acts' own columns (`status`, `issued_*`, `closed_*`, `closure_notes`) stay writable, which is what
keeps closing and cancelling an issued permit working.

**`canEdit()` is deliberately not the lever.** The acts live on the record page and gate on
`canIssue()`, so refusing the page would strand *close* and *cancel* for exactly the permits that
need them — the (role, state) reachability trap `RowActionPolicy::IN_ROW_EXCEPTIONS` records. Correct
a live permit by cancelling it and issuing a corrected one, which is what the refusal says.

**The form disables itself too, and the page drops a live permit's payload — both halves are needed.**
A model that refuses under a screen still offering thirteen editable fields is an affordance without
the right behind it, which is the rule `PropertyField` states for its own case. And a **disabled
Filament field is still DEHYDRATED** (measured on v4.11.8), so disabling alone does not stop the write:
`DateTimePicker->seconds(false)` truncates the window to the minute, so filling from an untouched form
made `valid_from`/`valid_to` dirty against a stored value carrying seconds, and pressing **Save without
touching anything** was refused. `DemoSeeder` builds every permit from `Carbon::now()`, so that is the
ordinary state of a real row — while the fixtures that missed it all parse a zero-second literal. The
same truncation was silently trimming seconds off live safety windows before the freeze existed.

**`issued_at`, `issued_by_user_id` and `reference` are frozen too**, and `status` may move forward but
never back to `draft`. Who authorised hazardous work and when is the most audit-sensitive pair on the
row; `reference` is the number quoted at the gate and on the radio; and closed → draft → rewrite →
issue again is a second authorisation on one reference with the previous closure still attached.

**Closing LATE is allowed; cancelling is not the same thing.** Refusing a late closure would leave
the register permanently wrong about a job that did finish safely, and would push people to cancel
instead — destroying the distinction between "closed late" and "never happened", which is the only
distinction an auditor cares about. A closure requires a note: "closed" with nothing written is
indistinguishable from somebody tidying a list.

**Where the finding surfaces.** `facility:scan-open-permits` runs **hourly** (a permit is bounded to
the hour, so a daily sweep could leave hazardous work unaccounted for most of a day), reports
without writing, logs off-box through `OpsLog` and mails the property's managers and operations
staff as well as belling them. It prints and logs the finding **before** it delivers anything —
observed, not theorised: a rate-limited mail provider returning 429 aborted the sweep mid-notify and
the operator saw a stack trace instead of the permits. The register carries the same count as a
**danger navigation badge**, scoped through `TenantScope::visibleAssetIds()`, because an alert
somebody dismissed on Friday is the whole reason the state persists.

**The permit must be readable after it is issued.** Editing stops the moment a permit is issued (the
freeze above; the row's Edit shortcut disappears and the form disables itself) — so a View action
renders the abstract as a native
infolist, and the *same* abstract is shown inside the issue confirmation. The facts a person needs
to authorise hazardous work are exactly the facts anyone needs to check it later, and a confirmation
dialog that says only "are you sure?" asks a named person to accept a risk they cannot see. Missing
conditions render in red rather than as a blank.

**Separate from the tenant's fit-out permit, deliberately.** `TenantRequestType::Permit` is a TENANT
asking permission through the portal (module 11). This is the OPERATOR authorising a contractor,
often its own vendor, with no tenant involved. Folding them together would make a safety control
lease-shaped — the same mistake that once keyed rentable items to a lease.

### التخصصات — the trade register (2026-08-20, close-out step 1)

**Benchmark:** ServiceChannel makes the trade the spine of the model
(`docs/benchmarks/fm/02-servicechannel-contractor-loop.md` §2) — it routes the work, decides which
providers are eligible, carries the SLA and is the axis every spend report groups by.

**What it replaced.** A work order's `category` — HVAC, plumbing, electrical — was a `Select`
populated from `__('admin.facility.categories')`, **a translation array**. Three consequences, none
of which ever presented as an error:

1. It was not in `App\Support\ValueSets`, so the column was **unenforced**: any string saved.
2. The canonical list lived in `lang/en` and `lang/ar`, so an operator could not add a trade
   without a deploy — and the two files had to be kept in step by hand. `EquipmentForm` and
   `EquipmentTable` had each hardcoded their own *shorter, different* subset of it, so landscaping,
   pest control, waste and security were missing from the equipment screens entirely.
3. **`vendors` had no trade at all** — only `type` (contractor / supplier / service_provider / …).
   So nothing could say who does HVAC: the vendor picker on an HVAC fault offered the stationery
   supplier, "spend by trade" had no dimension to group by, and `VendorScorecardService` compared a
   cleaning contractor with an HVAC contractor.

**Trade and craft are ONE register, deliberately.** Maximo keeps the *trade* (what the work is)
apart from the *craft* (what a person is) and carries the labour rate on the craft. In a mall those
are the same list — an HVAC technician does HVAC work — and two registers an operator must keep in
step would buy nothing at this scale. So `trades.standard_hourly_rate` lives here, and it is what
the work-order cost object reads to turn reported hours into money (close-out step 2). Split them
the day one trade genuinely needs several rates.

**The rate is nullable and that is the design.** A trade with no rate produces **no** labour cost —
visibly missing. A default rate would produce a number that looks computed and is invented.

**`category` was DROPPED, not kept beside `trade_id`.** Two columns answering "what kind of work is
this" is two truths about one question and the reader cannot tell which is current. The backfill
joined on the code — the exact string the old column held — and mapped every row in the live
database (7 work orders, 5 plans, 14 machines, zero unmapped).

**Eligibility is a suggestion; compliance is the gate.** `Vendor::assignableOptions()` now GROUPS
the picker — "Does this trade" first, "Other vendors" after — rather than filtering. Filament
validates a `Select` against its options with `Rule::in`, so dropping the others would *refuse* a
legitimate pick, and the day the usual HVAC contractor is unavailable is a real day. The thing that
genuinely blocks a dispatch stays `Vendor::isDispatchable()` — compliance, which is a decision the
operator actually made about that vendor.

#### Retiring a trade must not break the records that carry it

`Trade` is `#[DeletableWhenUnused]`, so a trade that has routed work cannot be deleted and both the
model and the screen guide say **deactivate** instead. Taking that documented path broke the module:
`Trade::options()` returned active trades only, Filament validates a `Select` against its options
with `Rule::in`, and so every work order, plan and machine carrying the retired trade failed
validation — on a field nobody had touched. An operator fixing a typo in a title got an error on the
trade.

`Trade::options($keep)` now always offers the record's CURRENT value, flagged `⚠` because it is no
longer a choice anyone should make afresh — the same convention `Vendor::assignableOptions()` uses
for a vendor who has stopped being dispatchable, which is where the shape was already understood and
simply had not been applied to the trade itself. Filters pass `activeOnly: false`, because the rows
still carry the retired value and hiding it hides the rows. `RetiredTradeStillEditableTest` pins all
three surfaces plus the control that a retired trade does **not** come back as a choice for
everyone else.

#### The defect this surfaced: a tenant picks a PROBLEM, not a trade

`RaiseCorrectiveWorkOrderService::fromTenantRequest()` used to copy the request's `category`
straight onto the work order. But a tenant request's category is a subcategory of its
`TenantRequestType`, and only **one** of those types has subcategories that are trades:

```
Maintenance → electrical · plumbing · hvac · structural · cleaning · safety · other   ← trades
Access      → keys_cards · parking · after_hours · visitor · delivery
Document    → lease_copy · renewal · termination_notice · noc_certificate
Complaint   → noise · cleanliness · conduct · other
```

Because the target column was an unenforced string, `noise`, `parking` and `lease_copy` **saved
into it silently** — verified in the live database — and then rendered blank in a `Select` that
offers only trades, while every "by category" report grouped by a value that is not one.

`tradeForRequest()` now matches on the code and resolves to **null** where there is no trade, which
is the honest answer for a noise complaint; a coordinator raising facility work from one states the
trade themselves, and an explicit `trade_id` always wins.

### The work order as a COST OBJECT (2026-08-20, close-out step 2)

**Benchmark:** `docs/benchmarks/fm/01-maximo-work-and-asset.md` §4. Six of the eight scenarios in
`03-scenarios.md` failed on this one absence, and every maintenance report worth having needs it.

**What was wrong.** `facility_work_orders` carried no cost at all. Parts posted through
`StockMovement`, contractor work through `VendorBill`, in-house wages through `Payroll` — **every
figure was correctly in the general ledger and none of them could be attributed to the job, the
machine or the shop.** So "what has this chiller cost us", "what is our maintenance cost per m²" and
"repair or replace" were unanswerable. Worst of all, **in-house labour was captured nowhere**, so
internal work cost zero on every report, insourcing always looked free, and every outsourcing
decision was wrong by the whole wage bill.

#### It is a cost object, NOT a GL source — and that distinction is load-bearing

The money is already posted, by three documents that each own their entry:

| Bucket | Posted by |
|---|---|
| material | `StockMovement` → `InventoryMovementJournalizer` |
| service | `VendorBill` / `Expense` → their journalizers |
| labour | `Payroll` → `PayrollJournalizer`, as `salaries_expense`, in total |

The `act_*` columns are a **management dimension over already-posted money** — which job, which
machine, which trade consumed it. Registering a journalizer for them would post every maintenance
cost in the business **twice, and balanced**, which is what makes it dangerous rather than obvious.
`WorkOrderIsACostObjectNotAGlSourceTest` fails the build if anyone tries, and proves the property as
well as the registry entry.

The same caution applies to reading: **a job's labour cost does not ADD to the wage bill, it
EXPLAINS part of it.** A report summing payroll and work-order labour double-counts.

#### `recomputeCosts()` is the single source of truth

Written the way `Invoice::recomputeTotals()` is, and for the same reason — several independent
channels change the number, so exactly one method computes it and every channel calls it. **Never
set an `act_*` column anywhere else.** Three channels feed it, and adding a fourth means adding it
here *and* wiring that model's events:

- **labour** — `facility_work_order_labour`, hours × the craft rate frozen at entry
- **material** — approved/recorded part draws (`facility_work_order_parts.value`)
- **service** — vendor bills + expenses booked to the job

**Net of tax, and net of an applied SLA penalty.** VAT is recoverable and is not what the work cost;
a penalty credited against a contractor's invoice genuinely reduces it, and `SlaPenaltyJournalizer`
already credits the same expense account, so netting it here keeps this figure and the ledger
telling one story. A cancelled document costs nothing — the same exclusion `VendorBill::recompute()`
makes for a voided payment. **A document moved between jobs recomputes the job it left**, which is
the failure nobody would notice because both numbers stay plausible.

#### Labour: ask for time, never for money

Nobody is asked "what did this cost" — they are asked "how long did it take and who did it", which
is a question a technician can answer truthfully, and the craft rate turns it into money. A
hand-typed cost is a guess with a decimal point.

The rate is **frozen on the row at entry**, so a rise never re-prices work done last March. A trade
with **no** rate produces hours and no cost — visibly missing rather than invented, which is also
how an operator sees which trade still needs a rate. The craft defaults to the job's trade but a
line may state its own: an electrician helping on an HVAC job is real, and forcing the job's trade
onto their hours would misreport both.

#### Three buckets, not Maximo's four — a stated deviation

Maximo splits labour · material · service · **tool**. A mall operator holds no tool inventory: the
scissor lift is hired, which arrives as a vendor bill or an expense and lands in `service`. An
always-zero column is a report line nobody can read. Folded, and said so rather than implied.

#### `job_value` was REPLACED, not kept beside it

It was read by exactly one thing — the SLA percent-of-value penalty basis — and for an external job
it *is* what the contractor will charge, i.e. the service estimate. Two columns holding one number
is two truths, and nobody reading a penalty could tell which it used. Backfilled into
`est_service_cost`, then dropped. `AssessSlaPenaltyService` now prefers the **actual** service cost
once a bill has landed and falls back to the estimate — which it could never do before, because the
actual did not exist: a penalty assessed after invoicing used to be computed off a quote nobody had
updated.

#### The planned total is derived on EVERY save, not only by the cost channels

`recomputeCosts()` is called by the three **cost** channels — labour, parts, bills — and none of
them touches an estimate. So editing `est_service_cost` on the form left the stored `est_total_cost`
at whatever it had been, and `costVariance()` — the number an operator acts on — was computed from
the stale figure. `deriveEstimatedTotal()` now runs from `saving` as well, which `saveQuietly()`
does not fire, so the recompute path calls it directly and cannot loop.

#### Hours may be booked on a job already `done`; a part draw may not

The distinction is not arbitrary. A part draw **moves stock** — an inventory transaction with a
general-ledger consequence — and that must not happen against work that is over. An hour booked is
the opposite: it records what a person already did, allocating a wage payroll has **already**
posted, and timesheets routinely arrive after the job was marked done. Refusing them would simply
mean the hours never get recorded, which is the gap the whole feature exists to close. A
**cancelled** job is refused, because it did not happen, so hours against it are a data error rather
than a late entry. Nothing here can un-freeze an SLA penalty: that basis reads the *service* cost.

#### Estimates are nullable; actuals default to zero

An actual is a roll-up and is always known — zero means nothing has been spent. An estimate is a
judgement nobody may have made, and `0` would claim the job was expected to be free. Planned-vs-
actual is the point of the pair, so "not estimated" and "estimated at nothing" must stay different.

### PM compliance — is the preventive programme actually being done? (2026-08-20, step 3)

**Benchmark:** Maximo §6 makes PM compliance a first-class measure, because a preventive programme
nobody measures is a list of intentions.

**What was wrong.** `scheduled_for` on a generated order **is** the plan's `next_due_date` — the
generator copies it — and `completed_at` is stamped on completion. Both have been stored since the
module shipped and **nothing ever compared them.** The quarterly generator load-bank test could
generate in March, sit open, and be closed in July with a tick: complete, four months late, and no
measure able to say so. The raw material was all present; only the question was missing.

**Four states**, derived and never stored — `overdue` is a function of *today*, so storing it would
need a sweep and would go wrong on a day when nothing happened (`App\Support\ProjectedState`'s
whole subject):

| State | Meaning |
|---|---|
| `on_time` | completed on or before the day it was due |
| `late` | completed, but after |
| `overdue` | not completed, and the day has passed — **the finding** |
| `due` | not completed, still inside its window — not yet anything |

`null` where the question does not apply: a corrective job answers to its SLA instead, and a
cancelled cycle was never going to happen.

**Completing at 16:00 on the due day is ON TIME.** Comparing a datetime against a date column would
call every afternoon completion late — reporting a compliant programme as failing, and destroying
trust in the measure on day one. Both the accessor and the scopes compare whole days.

#### Measured strictly, with no tolerance — a stated deviation

Maximo allows a PM tolerance window. There is none here, deliberately: a single global tolerance is
wrong in both directions — three days is most of a weekly cleaning round and nothing at all on an
annual overhaul — and a percentage of the cycle would be a policy nobody has agreed to. Strict never
**overstates** compliance, which is the safe direction, and the `late` rows are visible for an
operator to judge. Revisit with a per-plan tolerance if the operator asks for one, not before.

#### Rated per PLAN, not as one portfolio number

"87% compliant" tells an operator nothing they can act on; *"the generator monthly test-run is 40%"*
names the thing to fix. `ServicePlan::complianceRate()` is the share of that plan's **settled**
cycles done on time — settled meaning the answer is known (completed, or overdue with nobody having
done it). A cycle still inside its window is excluded: counting it as a failure would make every
plan look bad the day after it generated, and counting it as a success would be a claim nobody can
make yet. A plan with nothing settled has **no** rate — 0% and 100% would both be inventions.

`scopeWithComplianceCounts()` gives a list the same figure in one query instead of four per row.
Both paths go through the same three scopes, and a test pins that they agree — otherwise a table
would report a different compliance from the record it links to.

**Measured on the demo portfolio the day it shipped: 0% across all five plans, with four overdue
preventive jobs.** That is the measure doing its job on data nobody had questioned.

**The finding reaches the dashboard, not just a filter.** Step 3 first shipped the states, the
column and the filters, and left the finding behind a filter an operator has to choose — the
"capability with no surface" pattern this codebase names twice elsewhere. `ActionRequired` now
carries a **ppm_overdue** card, gated on `facility.view` like every other, linking to the filtered
register. It is **warning, not danger**: unlike a breached SLA nobody is waiting on the phone, and
colouring routine maintenance the same red as an urgent fault is how a dashboard stops being read.

### The tenant confirms the work is done (2026-08-20, close-out step 4)

**Benchmark:** ServiceChannel §4/§6 — *"A tenant confirming completion is a control, not a courtesy.
It is what stops a job being closed by the person who was paid to do it."* Scenario S7 is the shape
of the failure: a drain partially cleared, marked done, and the shop floods two days later during
trading hours.

**What already existed, checked before anything was built.** The lifecycle was already right —
`resolved → closed` and `resolved → in_progress` are both legal — `requests:auto-close` already
closed a resolved request after `config('requests.auto_close_after_days')`, and a tenant could
already **rate** one. So the gap was narrower than "no confirmation concept": the tenant could give
feedback *after the fact* and could not **accept or dispute the resolution itself**. The operator
closed it, or the timer did, and a tenant's only recourse to "it is not actually fixed" was to raise
a second request that nothing connected to the first.

**Two actions on a `resolved` request, in the portal:**

- **Confirm** → `closed`, stamping `confirmed_at` and **which person** accepted. The portal is
  multi-user; a confirmation nobody signed is the same evidence as no confirmation. The modal shows
  the resolution notes, so nobody confirms work they have not read.
- **Not fixed** → back to `in_progress`, with a **required** reason posted to the comment thread. A
  bare "not fixed" sends an engineer back knowing no more than the first time, and it is the
  tenant's own words that say whether this is the same fault or a new one. The thread rather than a
  column, because a second dispute would overwrite a column and the history is the point.

`CONFIRMABLE` is deliberately narrower than `RATEABLE`: a tenant may rate a job that is already
closed, but may only confirm one that is still `resolved` — confirming is a control *before*
closure, and there is nothing left to control once it is shut.

#### It does not reopen the work order, deliberately

A terminal work order is immutable here, and the module already has the right construct: a
**follow-up** job, linked to the original by `parent_work_order_id` and separately costed. Raising
one is an operator's decision about who to send and when — not something a tenant's click should do
on their behalf. What the click does is make the request the operator's problem again, which is what
a tenant can legitimately demand.

#### The portal and `/api/v1` are the same surface — both, or neither

Step 4 first shipped the two actions to the web portal alone. That breaks a stated invariant, and it
matters beyond consistency: **the mobile app is where a shop manager actually is**, so a control
living only on a desktop screen is one most tenants would never use.
`POST /me/requests/{id}/confirm` and `/dispute` mirror it, through the same service so the two
refuse identically, and the resource carries **`canConfirm`** beside `canCancel`/`canRate` so the
app never re-derives the rule. `canConfirm` is narrower than `canRate` — rating stays open on a
closed request, confirming does not — and `docs/api/MOBILE-API.md` says so, marked as a change the
app has to make rather than a fact about the backend.

#### Silence is consent, and the two are now distinguishable

`requests:auto-close` keeps its behaviour and gains a meaning: a tenant who does not answer within
the window is taken to have accepted. That is the right default — chasing a retailer for a click is
how a queue of "resolved" requests never closes. But a close nobody confirmed must not *look* like
one somebody did, so `confirmed_at` tells them apart, and the admin list says either
**"Confirmed by Ahmed Hassan"** or **"Closed unconfirmed"**. An operator asking "did the tenant
actually say this was fixed?" can now get an answer.

### What the demo data shows (2026-08-20)

Everything in this module worked before `DemoSeeder` exercised any of it, which meant Operations
rendered a register of jobs with **0.00 in every cost column** and empty NTE, failure, permit and
compliance screens. A capability nobody can SEE reads exactly like one that was never built — the
same failure class as a service with no entry point — so the fixture is part of the feature.

`seedFacilityCosts()`, `seedPmHistory()` and `seedRepeatVisits()` now lay down: labour and parts on
a completed visit; a contractor's quote, an approved supplementary and a bill filed **against the
job**; one job **over its NTE with nobody's approval on it**; 26 past preventive visits so
compliance reads 60–86% instead of a uniform 0%; the three-visit S6 repeat on a shop; and three
permits — live, closed, and one **lapsed with no sign-off**. `seedTradeCostBase()` runs BEFORE the
generator, because a plan's hours are priced when the job is raised: with rates still null every
generated job carries a zero estimate for the life of the demo.

Trade rates and NTE ceilings are seeded there and **not** at install, deliberately — they are the
operator's own cost base, a plausible default silently misprices every hour booked against it, and
ServiceChannel likewise ships NTE opt-in per category. The demo run that uses all of this is
[docs/DEMO.md](../DEMO.md) §3–§4.

### Failure codes and repeat visits (2026-08-20, close-out step 5)

**Benchmarks:** Maximo §7 (failure class → problem → cause → remedy) and ServiceChannel §4
(repeat-visit tracking, *"the highest-value cheap signal in retail FM"*). Scenario S6 is the
failure: the same escalator handrail reported four times in five weeks, four contractor visits,
EGP 8,800 — and a register showing four unrelated successes.

#### Three levels, scoped by trade rather than chained — a stated deviation

Maximo chains causes to problems and problems to an asset's failure class. **That chain is a matrix
somebody must populate before anything can be recorded**, and an unpopulated matrix offers no codes,
so nobody records anything and the primitive is dead on arrival.

Here `Trade` **is** the class — it already classifies work orders, plans and machines, and a second
taxonomy would be one more list to keep in step — and a code is *scoped* to a trade rather than
chained to a parent. A code with **no** trade is offered everywhere, which is what makes a starter
set possible and stops a newly-added trade having an empty picker. Revisit the chain if the operator
ever asks which causes belong to which problem, and not before.

A code is unique **within its type**, not globally: "leak" is a legitimate problem *and* a
legitimate cause, and one row serving both would make the pickers lie.

#### Optional at completion, and that is the design

Nothing is required. Switching a requirement on refuses the next completion every engineer attempts,
and the reliable outcome is whichever code clears the validation fastest — worse than a blank,
because it looks like data. Same posture as `require_completion_evidence`. The three pickers sit on
the **"Mark done" dialog**, where the engineer already is; a screen they have to go and find
afterwards is one nobody visits. Codes are written **before** the transition, so a checklist refusal
does not lose what they typed.

**The starter set is fifteen obvious codes, all trade-null, and it is a starting point rather than a
claim about this operator's business.** Maximo ships none and expects the library to be built;
shipping thirty invented Egyptian-mall codes would be exactly the guess this project refuses
elsewhere.

#### Repeat visits — same THING, not merely the same property

`isRepeatVisit()` asks whether somebody has already been here, for this, recently:

- the **same machine** where the job names one, otherwise the **same shop** — two jobs in one mall
  are not a repeat of each other, and counting them so would make every busy property look like a
  failure;
- the **same trade** — an electrical fault and a plumbing fault in one shop are two problems;
- inside `config('facility.repeat_visit_days')`, default 30 (the retail-FM convention, and a
  judgement rather than a law);
- **excluding follow-ups** — `parent_work_order_id` says the operator planned this continuation; it
  is not a fault that came back;
- **corrective work only, on BOTH sides** (2026-08-20) — a preventive visit recurring at the
  frequency somebody planned is the programme working, not a fault returning.

A job naming **neither** a machine nor a shop matches nothing. Without that guard every common-area
job would "repeat" every other job in its trade and the signal would be noise on day one.

**The corrective-only rule was added after the fact, and how it was found is the point.** Seeding a
preventive HISTORY into the demo data (so compliance had something to measure) lit up **20 repeat
flags, 18 of them scheduled PPM**: a fortnightly plan makes every one of its own visits a repeat of
the last, so on any real preventive programme the signal that exists to find the unfixed fault would
have been ~90% noise. The whole suite was green throughout — every existing test raised corrective
work, so none had ever put a preventive job through the question. It is guarded in three places
(candidate side of both the scope and its count twin, and the subject side of each), because the
model path and the list path must agree: a badge that contradicts the record it links to is worse
than no badge. All three are mutation-proven in `RepeatVisitsAndFailureCodesTest`, and the case that
separates the subject-side guards from the candidate-side one is a **scheduled visit following a
corrective fix** — without them, every plan on a machine that ever broke reads as a recurring
failure.

`scopeWithPriorVisitCount()` counts a whole page in **one** query — measured at 14 queries for 12
rows before it existed, on a column that is visible by default, and the badge also read the same
fact twice. The scope and `priorVisitCount()` are two spellings of one rule, pinned by a test that
they agree, because a badge contradicting the record it links to is worse than no badge. Its date
arithmetic branches on the driver (`datetime()` vs `date_sub()`), and `tests/Mysql/` executes the
MySQL half, which the ordinary SQLite suite can never reach.

It surfaces where it changes a decision: a **red badge** on the work-order list (not hidden by
default — a coordinator triaging today's faults is exactly who needs it) and a **repeat-visits
column on the vendor scorecard**, which is ServiceChannel's point — the provider who keeps coming
back to bill twice. Counted on the vendor's own repeat jobs, not on every repeat at their sites: a
contractor answers for returning to their own work, not for a fault somebody else failed to fix.

### Not-to-exceed and the proposal loop (2026-08-20, close-out step 6)

**Benchmark:** ServiceChannel §3. Every job carries an **NTE** — the most a contractor may spend
without coming back — and work expected to exceed it needs a **proposal first**. Scenario S4: a leak
reported, the contractor decides the riser must be replaced, does it, and invoices EGP 46,000
against an expected EGP 4,000 repair.

**Atriom already had the AFTER control and nothing before the money.**
`PurchaseRequest::billingVariance()` is a real three-way match — the gap analysis rates it *stronger*
than the benchmark's — but it fires when the invoice arrives, and negotiating after the work is done
is not negotiating. This is the other half, not a replacement.

**The ceiling comes from the trade and is applied when the job is RAISED.** Changing a trade's
default must not silently re-authorise every open job in it. A trade with no default leaves the job
with no ceiling — honest, where `0` would mean "may spend nothing".

#### A proposal IS the estimate

Its three buckets **are** the cost object's three buckets, deliberately. Approving one writes
`est_labour_cost` / `est_material_cost` / `est_service_cost` onto the job, so step 2's
planned-vs-actual variance means *"did the contractor deliver what they quoted?"* — the question the
whole loop exists to answer — rather than two unrelated sets of numbers about the same work. The
total is derived from the breakdown, never typed: a quote whose total disagrees with its own parts
is the argument nobody wants when the invoice lands.

**A quote is either the whole price or EXTRA on top of one already agreed**, and the two behave
differently. Found by review, on the live database, after the first version treated every quote as a
replacement: approving 38,000 and then a supplement of 8,000 left the ceiling at 38,000 and
collapsed the estimate to **8,000**, so the job read as 38,000 overspent. Worse than unsupported — it
corrupts the figure planned-vs-actual is measured against, and nothing on the screen said which the
operator was recording. A full quote replaces; a supplementary one **adds** to both, and a
supplement does not withdraw other pending supplements, because two pieces of extra work are not
alternatives to each other.

Approving **raises** the ceiling and never lowers it — approving a smaller quote must not quietly
tighten what the contractor was already permitted for other work on the job — and **withdraws any
competing quote**, because two live approvals make "what was agreed?" unanswerable. A refusal
requires a reason and touches neither the ceiling nor the estimate: it says only that this price was
not accepted.

**Deciding is a spending decision**, so it goes through `ApprovalPolicy` on the quoted amount, the
same ladder a purchase request uses. Without that a coordinator could authorise EGP 200,000 of work
they could not have raised a purchase order for, which would make the ladder a rule about paperwork
rather than about money.

#### Over-NTE is SHOWN, never blocked — a stated deviation

ServiceChannel holds the invoice. Here the breach is a badge, a filter and a number, and accounts
payable is not jammed — **the same settled reasoning as the three-way match**, which deliberately
does not block because a bill legitimately covers more than the goods. A job can grow for something
nobody could have proposed for. The control is that a contractor *should have proposed before
exceeding*; the enforcement is that the breach is visible and attributable to a figure somebody
actually agreed to.

#### The contractor does not submit it themselves — yet

ServiceChannel's provider logs in and submits. That portal is gap **O2** and remains open, so a quote
is recorded **by the operator** on the contractor's behalf, exactly as a vendor bill is. The loop is
real; its self-service half is not built, and this does not pretend otherwise.

### Routes and planned cost (2026-08-20, close-out step 7 — the last)

**Benchmarks:** Maximo §6 (routes) and §3 (job-plan estimates).

#### Routes — a failed line names the DEVICE

Scenario S5: a quarterly round over 42 fire extinguishers, three of them fail. A `ServicePlan`
targeted **one** machine with a free-text checklist, so an operator either created 42 plans or one
plan whose checklist had 42 lines — and *"Extinguisher 2-17 — fail"* is a **string**, so no report
could say which devices were overdue and 2-17's own history stayed empty however often it failed.

`service_plan_stops` is the route and `facility_work_order_items.equipment_id` is what turns a failed
line into a fact about a device. A plan with **no** stops is an ordinary single-target plan and
behaves exactly as before, so nothing an operator already built changes.

**One work order with a line per stop — not a work order per stop.** Maximo offers both. Per-stop
children earn their keep when each stop needs separate assignment or separate costing; a
fire-extinguisher round needs neither, and 42 work orders for one walk is the failure the route
exists to prevent. A stated deviation — revisit if a route ever spans trades or contractors.

A stop is a **machine** specifically. A round over areas is a different shape and the plan already
carries `area_id` for it; three nullable targets here would repeat an ambiguity rather than resolve
one.

**A decommissioned machine drops off the round, and the round still runs.** Found by review: a
retired extinguisher kept appearing on the sheet, so an engineer was sent to inspect a device that
is not there, and a `fail` recorded against it would be a fact about nothing. Skipped rather than
refused — one dead stop out of 42 must not stop the other 41 being inspected. That differs from a
single-target plan whose machine is retired, where generating the job is a useful prompt that the
plan itself is now pointless.

#### Planned cost — hours on the plan, money at generation

Without an estimate on the plan, every job the preventive programme raises is un-estimated for ever
and step 2's `costVariance()` is null across the whole programme.

The plan stores `est_labour_hours` and the generator turns it into money **at the trade's rate on
the day the job is raised** — the same origination rule as every other rate here. Storing a labour
*cost* on the plan would freeze a rate for the plan's whole life, which is exactly what
`charges.vat_rate` did wrong before 2026-08-12: a rise entered in advance reached one-off charges
and never reached rent.

A trade with no rate produces hours and **no** labour cost, visibly missing rather than invented; a
plan with no estimate leaves its jobs un-estimated rather than estimated at zero, because "nobody
planned this" and "this was expected to be free" are different claims and the variance depends on
which one is true.

### Where the work-order concerns live (2026-08-20)

The close-out tripled `FacilityWorkOrder`, to **1,149 lines carrying seven subjects that change for
different reasons** — a compliance rule, a costing rule, a spend rule, an SLA rule, a
fault-attribution rule. The line count was the symptom; the cost was that changing any one of them
meant reading a file where all seven lived, which is exactly the coupling that makes a system
expensive to add to.

Four concerns are now traits in `app/Models/Concerns/FacilityWorkOrder/`, the directory pattern the
`Invoice` and `Lease` concerns already use:

| Trait | Subject |
|---|---|
| `HasWorkOrderCost` | the cost object — three buckets, `recomputeCosts()`, the variance |
| `TracksPmCompliance` | was the planned work done on time |
| `RecordsFailuresAndRepeats` | what went wrong, and whether we have been here before |
| `ControlsSpendAgainstNte` | the ceiling, the quotes, the breach |

Their lifecycle hooks moved with them, as `boot{Trait}()` — so a concern owns its own behaviour
rather than leaving half of itself in the model's `booted()`.

**The SLA and fault-attribution concerns deliberately stayed.** They are stable and nobody is
changing them; moving code for tidiness buys churn rather than clarity. The four extracted are the
ones that GREW — which is the honest test of whether a concern has earned its own file.

The refactor is behaviour-neutral, proven by the suite returning an identical result. It also
tripped `UnresolvedClassReferenceConformanceTest` on a missing `Trade` import — the invariant that a
class you name must be one you imported, caught by its own gate rather than by a browser.

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Plans + work orders + checklists** | `ServicePlan` + `FacilityWorkOrder` + items, the daily generation scan (idempotent/lock-safe), two property-scoped resources, checklist relation manager, status transitions, RBAC + module flag, tests | ✅ shipped |
| **2 — Facility work-log report (RPT-1)** | `FacilityWorkLogPdfService` — a bilingual PDF of work orders for a property over a date range (summary by status + category + the detail list), launched from a **"Work log (PDF)"** action on the work-order list, scoped to the user's visible properties | ✅ shipped |
| **3 — Pass/fail checklist + completion gate (FR-PPM-07)** | `result` enum replaces `is_done`; `FacilityWorkOrderService` (the module's first service) owns the TRANSITIONS matrix, the row-locked completion gate, and `markItem()`; the Filament actions became thin callers; progress badge counts *marked* and warns amber on any fail | ✅ shipped |
| **4 — Equipment register (FR-PPM-03/04/05)** | `Equipment`: per-property-unique `code` + `parent_id` sub-code tree (same-property + acyclicity guards), nullable `fixed_asset_id` link to the accounting register, `equipment_inventory_item` pivot for compatible spare parts, property-scoped resource under `facility.*` | ✅ shipped |
| **5 — Equipment ↔ plans/work orders (FR-PPM-01/02/03)** | `equipment_id` on `service_plans` + `facility_work_orders` (PPM per machine, carried onto the order so history survives the plan), `maintenance_type` routine/fixed, `years` frequency + `advanceDue()` throwing instead of silently meaning months, per-plan failure containment in the scan | ✅ shipped |
| **6 — Corrective maintenance core (FR-CM-01/02/03/04/14/15)** | `work_order_type` ppm\|cm, CM raised from a failed check (one per check), internal/external as a real XOR, technician-or-vendor assignment, required description, `CM-` references, follow-up chains that never reopen the original | ✅ shipped |
| **7 — CM SLA + breach detection (FR-CM-05/06/07 + FR-CM-08 detection)** | `sla_policies` per property × priority with a settings/config fallback chain, 4 priority tiers, the clock starting on **acceptance**, the hourly breach scan + bell alert, an SLA-target column and breached filter on the list | ✅ shipped |
| **7b — SLA penalty assessment (FR-CM-08)** | penalty terms per vendor contract (**all three bases** — flat, per-day accrual, %-of-job-value — so the client's answer is configuration rather than a rewrite), one re-assessed penalty row per job, freeze on closure, waive with a reason, `job_value` for the percent basis, penalty column + waive action | ✅ shipped |
| **7c — Charging the penalty to the vendor (FR-CM-08 money)** | `vendor_bills.penalty_applied_amount` folded into `VendorBill::recompute()` (the AP single-source-of-truth, exactly as `credit_applied_amount` works on the tenant side), an apply/detach service with a cap so AP never goes negative, `SlaPenaltyJournalizer` posting **Dr AP / Cr the same expense the bill charged**, and a "charge to a bill" action that only offers bills able to absorb it. AP tie-out proven to stay balanced. ⚠️ The **treatment** (cost reduction, no VAT) and the **CAM consequence** are recorded in `docs/BUSINESS-RULES.md` and still need accountant sign-off | ✅ shipped, pending sign-off |
| **8 — CM parts + approval (FR-CM-09/10/11, FR-INV-04)** | `facility_work_order_parts`: an internal draw is **requested** and moves stock only on approval (its own table, *not* a pending StockMovement — see the domain model), the approver resolved by part value through the generic `ApprovalPolicy` ladder and frozen onto the row, self-approval refused, external purchases recorded with vendor + invoice ref, parts relation manager on the work order | ✅ shipped |
| **9 — Fault attribution + cost bearer (FR-CM-12/13)** | `fault_party` + derived `cost_bearer` on the work order with provenance, a manager-only ruling, a guard against blaming a tenant who doesn't exist, and `costBearer()` on outside-sourced parts reading the job's finding. **Record-only — the FRD says *determine* and *record*, never *bill*** (scope corrected 2026-07-16 by reading the source .docx; the earlier roadmap entry here promised a recharge the FRD never asked for). The recharge seam is designed, documented, and deliberately unbuilt. | ✅ shipped |
| **10 — Close-out sweep (2026-07-26)** | **[HIGH · money] SLA-penalty sub-hour fix** — `hoursOverSla()` truncated a sub-hour overrun to 0, so a job minutes late escaped the penalty forever (and per-day mis-counted at the day boundary); now gated on `isSlaBreached()` + `daysOverSla()` (see §7g). **Notifications** — a raised work order (`WorkOrderRaisedNotification`, FRD MNT-2) and an assigned technician (`WorkOrderAssignedNotification`, sharp because `AssignmentScope` hides the rest) are no longer silent; owner Jawad now gets the CM-breach alert too (FR MNT-5 / NOT-2). | ✅ shipped |
| **11 — Permit to work (2026-08-19, gap O5)** | `work_permits` + `WorkPermitService` (issue/close/cancel), hourly `facility:scan-open-permits` reporting permits past their window with no closure, property-scoped register with live/overdue filters and a danger navigation badge, `work_permits.issue` as a right of its own, readable abstract on View and inside the issue confirmation, folded global search on the reference. **An EXTENSION, not a Yardi construct** — see above | ✅ shipped |
| **12 — Trade register (2026-08-20, close-out step 1)** | `trades` + `trade_vendor`; work orders, service plans and equipment all classify by a ROW instead of a translation key; `standard_hourly_rate` (the craft rate the cost object will read); the vendor picker grouped by eligibility; `category` dropped from all three tables with a code-matched backfill. Fixed a live defect on the way: a tenant's problem category was being written into the work order's trade | ✅ shipped |
| **13 — The work order as a cost object (2026-08-20, close-out step 2)** | Planned and actual cost in three buckets on `facility_work_orders`; `facility_work_order_labour` (the primitive that did not exist — hours × the craft rate, frozen at entry); `facility_work_order_id` on `vendor_bills` and `expenses` so contractor work is attributable at all; `recomputeCosts()` as the single source of truth with all three channels wired; cost columns on the job, lifetime cost on the machine; `job_value` replaced by `est_service_cost` and the SLA percent basis rewired to prefer the actual. **Explicitly NOT a GL source** — the money is already posted three other ways, and a gate keeps it that way | ✅ shipped |
| **14 — PM compliance (2026-08-20, close-out step 3)** | Four derived states on a preventive order (`on_time` · `late` · `overdue` · `due`) with query twins the column, the two filters and the plan figure all share; `ServicePlan::complianceRate()` per plan with a one-query list variant pinned to agree with it. Strict, with no tolerance window — a stated deviation from Maximo, because one global number is wrong for both a weekly round and an annual overhaul | ✅ shipped |
| **15 — Tenant confirms the resolution (2026-08-20, close-out step 4)** | Confirm / "not fixed" on a `resolved` request in the portal, recording WHICH person accepted; a dispute returns it to `in_progress` with a required reason on the comment thread. Auto-close keeps taking silence as consent, and `confirmed_at` now distinguishes a close the tenant made from one the timer did. Does **not** reopen the work order — that is a follow-up job and an operator's decision | ✅ shipped |
| **16 — Failure codes + repeat visits (2026-08-20, close-out step 5)** | `failure_codes` (problem · cause · remedy, scoped by trade, unique within type) recorded optionally on the "Mark done" dialog; `isRepeatVisit()` derived from same-machine-or-shop + same-trade + a 30-day window, corrective work only, excluding planned follow-ups; a red badge on the register and a repeat-visits column on the vendor scorecard. **Three levels scoped by trade, not Maximo's chained four** — a chain is a matrix nobody populates, and an unpopulated matrix means nothing gets recorded | ✅ shipped |
| **17 — NTE + proposals (2026-08-20, close-out step 6)** | `trades.default_nte` → `facility_work_orders.nte_amount` applied at raise time; `work_order_proposals` with the cost object's own three buckets, so approving one raises the ceiling AND sets the job's estimate; deciding gated by the `ApprovalPolicy` ladder on the quoted amount; over-NTE shown as a badge and a filter, **never blocked** — the same reasoning as the three-way match | ✅ shipped |
| **18 — Routes + planned cost (2026-08-20, close-out step 7)** | `service_plan_stops` turns a plan into a round; `facility_work_order_items.equipment_id` makes a failed line a fact about a device rather than a string; `est_labour_hours` on the plan priced at the trade's rate when each job is raised, giving the cost object a planned side across the whole preventive programme. One job per round, not one per stop — stated deviation | ✅ shipped |

---

## 5. Tests

`tests/Feature/Services/PreventiveWorkOrderTest.php` — generation (raises a work order +
copies the checklist + advances `next_due`), idempotency (no double-generate), not-due /
inactive skipped, frequency advance (weeks), blank-checklist-entry skipping, and the command.

`tests/Feature/Resources/FacilityWorkOrderResourceTest.php` — `facility.*`
RBAC gating, module-off hiding, property scoping, the complete/cancel actions (+ read-only
role guard), terminal-order immutability (actions hidden), and the checklist add-item action
(+ frozen on a terminal order). `tests/Feature/Resources/ServicePlanResourceTest.php` —
plan RBAC + scoping + `assertAssetInScope` guard + the `frequency_value ≥ 1` coercion.

`tests/Feature/Services/FacilityWorkLogTest.php` — the work-log PDF renders (with + without
orders in range) and the export action streams a PDF for an authorised user.

`tests/Feature/Services/FacilityWorkOrderServiceTest.php` — the FR-PPM-07 gate and the state
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

`tests/Feature/Scenarios/ServicePlanEquipmentScenarioTest.php` — FR-PPM-01/02/03: a yearly plan
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

`tests/Feature/Scenarios/CorrectiveWorkOrderScenarioTest.php` — FR-CM-01/02/03/04/14/15: a CM
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

`tests/Feature/Scenarios/PreventiveWorkOrderScenarioTest.php` drives the **real service**, not raw
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

`tests/Feature/Regression/WorkOrderPartLedgerDispatchTest.php` — the **money half**, and it never
touches `LedgerPoster`: it drives the service and then the real `accounting:sync-ledger` sweep, so
it fails if the dispatch wiring regresses. Proves an approved draw posts (balanced, expense-vs-
inventory, property-dimensioned), doesn't double-post on re-run, reverses on void, and that the
books tie out after each. This exists because the SLA penalty shipped green while posting nothing —
its test called `LedgerPoster::post()` directly, proving the arithmetic and never the path — and
parts shipped with no GL coverage at all while the demo data contains zero of them, so
`billing:reconcile` was silent on them too. Verified load-bearing: a draw that posts nothing fails
it.

`tests/Feature/Scenarios/FaultAttributionScenarioTest.php` — FR-CM-12/13: the bearer derives from
the cause and *only* `tenant` lands on the tenant (vendor fault included, explicitly); an engineer
cannot rule that a tenant is liable; a common-area job and a vacant unit both refuse a tenant bearer
(and write nothing); a `done` job can still be attributed but a `cancelled` one cannot; revision
re-stamps who ruled; an override needs a reason; an external part reads the job's finding and moves
with it, while an internal draw has none. All five guards verified load-bearing by mutation.

**Related:** 11 Maintenance (tenant-facing requests), 12 Vendors (assignees), 14 Departments,
01 Properties (asset scope), 18 RBAC (operations), 19 Notifications & Scans (the daily scan),
22 Inventory (the stock a draw comes out of), 28 Approvals (the value → approver ladder).

> **⚠️ A work order could be assigned once and never REASSIGNED (fixed 2026-09-02).**
> `assigned_to_user_id` drives `FacilityWorkOrder::notifyAssignee()` and was rendered on the
> **corrective** form only — i.e. at creation from a tenant request. So the technician who is off
> sick keeps the job, the one who picks it up is never told, and the model's own assignment
> notification is unreachable for every job that changes hands. A supervisor's most ordinary act had
> no screen.
>
> The picker is on the main form now, scoped to staff who can reach that property. The grouping in
> `technicianOptions()` is load-bearing: ungrouped,
> `whereHas(...)->orWhereDoesntHave(...)` lets the OR escape the property clause and hands the picker
> every mall's roster. (`TwoActsWithNoScreenTest`.)


## An SLA penalty must reach the job's cost (fixed 2026-09-02)

A work order is a **cost object** and `FacilityWorkOrder::recomputeCosts()` is its single source of
truth — the same discipline that makes every AR settlement channel call
`Invoice::recomputeTotals()`. `VendorBill` keeps its end of that bargain in a `saved` hook, and
`VendorBill::recompute()` ends with **`saveQuietly()`**, which is exactly the call that skips it.

So every derived figure `recompute()` writes was invisible to the cost object, and the SLA penalty is
the one that matters. `ApplySlaPenaltyService` sets `penalty_applied_amount` and calls `recompute()`;
`recomputeCosts()` nets that very column out of the job's service cost
(`sum(subtotal - coalesce(penalty_applied_amount, 0))`). Applying a penalty therefore reduced what was
payable to the contractor and left `act_service_cost` standing at the full amount:

| | payable | `act_service_cost` |
|---|---|---|
| bill 50,000, no penalty | 50,000 | 50,000 |
| penalty 8,000 applied — **before** | 42,000 | **50,000** |
| penalty 8,000 applied — now | 42,000 | 42,000 |

The job overstated its cost by exactly the penalty, permanently, and the planned-versus-actual
variance the whole cost object exists for read wrong in the direction that flatters the contractor.
Detaching a penalty had the mirror fault.

**`saveQuietly()` is right and stays** — a derivation is not an operator action, and logging it would
bury the change somebody actually made under a cost row nobody typed. What was missing is the cascade
beside it, and it lives in `recompute()` rather than in `ApplySlaPenaltyService`: every derived figure
that method writes is invisible to the cost object, so a fix in the service would leave the NEXT
caller to remember it — which is precisely the failure being fixed.

**Deliberately not a GL question.** The penalty already posts through `SlaPenaltyJournalizer`; these
columns are a management dimension over money the ledger already has, which is why a work order must
never become a GL source (`WorkOrderIsACostObjectNotAGlSourceTest` gates that).

**The cascade is GUARDED, and the review of the first pass is why.** `act_service_cost` reads
`subtotal` net of `penalty_applied_amount` on a non-cancelled bill and nothing else, so the cascade
fires only when one of those moved. Unguarded, `VendorBillService::approve()` ran the four costing
aggregates **twice** (its own `save()` fires the `saved` hook, then `recompute()` fired the cascade),
`cancel()` three times, and every vendor-bill payment paid for a `find()` plus four aggregates to
re-derive a figure a payment provably cannot move — 13 queries to 18, measured.
`saveQuietly()` still populates `wasChanged()`, so the guard is free. It also keeps the SLA breach
scan's `facility_work_orders` row lock out of the AP payment path, which the unguarded version put
there.

**Two consequences worth stating rather than leaving to be found:**

- **A penalty can pull a job out of the NTE breach list.** `overNteBy()` and `scopeOverNte()` read
  `act_total_cost` live, so a job at 50,000 against a 45,000 not-to-exceed is over — charge an 8,000
  penalty and it reads 42,000 and drops off the filter. That is arguably right (the mall did not pay
  it) and it is a real change to a control whose own docblock says the enforcement *is* that the
  breach stays visible and attributable.
- **`recomputeCosts()` is still an UNLOCKED read-modify-write** (SW-203). Two writers on one job —
  a bill payment and a penalty application — each compute from their own snapshot and the last one
  wins, which can silently put the penalty back into the job cost. Pre-existing across all four
  channels, widened here, and recorded rather than fixed because the repair is a lock on the work
  order and belongs with the concurrency registry.

Tests: `APenaltyReachesTheJobsCostTest` — apply; detach; **waive** (the caller in a different
service, which reaches the bill only through `recompute()` and which a fix inside
`ApplySlaPenaltyService` would have missed); the guard refusing a no-op; the planned-versus-actual
variance itself; and a bill with no job at all. Mutation-proved in both directions — removing the
cascade turns 5 of 6 red, removing the guard turns 1 red.
