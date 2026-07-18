# 30 · Areas (facility zones)

> A facility zone within a mall — Ground Floor, Food Court, Parking, Roof Plant.
> The building block for **routing**: a unit belongs to a zone, and a tenant
> request **inherits its unit's zone** on intake, then notifies the zone's
> **supervisor(s)**. This slice ships the register + the supervisor assignment;
> the **request routing** (units → zones → requests → supervisors) is now wired
> (see §7). Work-order routing to zones is still a separate follow-up.

---

## 1. Purpose & business context

Eltizam's facility teams think of a mall in **zones**, not just individual units:
"the food court", "the parking decks", "the roof plant room". Requests and preventive
work naturally belong to a zone, and each zone has one or more **supervisors** who own
it. Modelling the zone explicitly gives the operator a stable target to route work to,
independent of which storefront (`Unit`) or machine (`Equipment`) the work touches — a
common-area fault has neither.

An `Area` is a **property-owned** record (direct `asset_id`), sitting in the Operations
navigation group beside the other facility modules (Equipment, Maintenance, Meters).

## 2. Domain model

### `areas` — a zone in one mall

| Column | Notes |
|---|---|
| `asset_id` | FK → `assets`, `cascadeOnDelete`. The mall the zone belongs to. |
| `name` | Free text (e.g. "Food Court"). |
| `code` | `string(40)`, **unique per property** → `unique(['asset_id','code'])`. |
| `is_active` | boolean, DB default `true` **and** model `$attributes` default `true` (a blank toggle must never send `null` into the NOT-NULL column). |
| `notes` | nullable text. |
| soft deletes | trashed rows still count toward the per-property code unique, so the edit page ships Delete / ForceDelete / Restore to reclaim a burned code. |

### `area_user` — supervisors (many-to-many)

A plain pivot (`area_id`, `user_id`, timestamps; `unique(['area_id','user_id'])`; both FKs
`cascadeOnDelete`). **No model of its own** — exactly like `equipment_inventory_item` — so
it stays outside the `PropertyIsolation` model registry (there is nothing to classify).
A supervisor may cover many areas; an area may have many supervisors.

`Area::supervisors()` is a `BelongsToMany` against the shared **User** model.

## 3. Business rules & invariants

- **Per-property code uniqueness.** Two malls may both have a `GF` zone; one mall may not.
  Enforced by the DB unique index and the form's `unique(...)` rule.
- **Property isolation.** `Area` is registered `OWNED (direct asset_id)` in
  `App\Support\PropertyIsolation`. Reads scope via `BypassesScopingOnAll` +
  `$tenantOwnershipRelationshipName = 'asset'`; writes are guarded with
  `assertAssetInScope()` on **both** Create and Edit pages (Filament only stamps
  `asset_id` on create, never on update). `PropertyIsolationConformanceTest` gates all of
  this — `AreaResource` is registered in `propertyIsolationMustGuardResources()`.
- **Supervisors are the property's staff.** The supervisor picker only offers users
  assigned to the selected property (`asset_user`) plus property-less users — never
  another mall's roster. Same scoping as `CorrectiveWorkOrderForm::technicianOptions`.
- **`is_active` defaults true** at both the DB and model layers.
- **Delete is super_admin-only** (project-wide invariant via `RoleGatedActions`); bulk
  delete stays off.

## 4. Lifecycle / state machine

There is no workflow state beyond `is_active` (a zone is either in service or retired) and
soft-delete. An inactive zone is retained for history but should not be offered as a new
routing target once routing lands.

## 5. Services & commands

**`App\Services\NotifyAreaSupervisorsService`** — the routing dispatch (added with the
routing slice). Given a freshly-created `TenantRequest`, it notifies the request's zone
supervisors (`Area::supervisors`). Idempotent + fail-safe: no zone, a trashed zone, or no
supervisors is a no-op, and every failure is contained (a bad recipient never breaks
request creation). It runs **alongside** the request's department routing, not instead of
it — both fan-outs can fire. See §7.

The CRUD register itself has no service; its business logic is the property isolation +
uniqueness rules enforced by the model, migration, and Filament form.

## 6. Filament fields & validation

`app/Filament/Admin/Resources/Areas/` — Resource + `Schemas/AreaForm` + `Tables/AreaTable`
+ `Pages/{List,Create,Edit}Area`.

- **Property** (`asset_id`) — `TenantScope::selectableAssetOptions()`, defaulted + disabled
  to the current property, enabled only in All-Properties mode.
- **Code** — required, `maxLength(40)`, `unique(ignoreRecord, modifyRuleUsing → asset_id)`
  clamped through `TenantScope::clampAssetId()` (a raw-value unique rule is an existence
  oracle over another property's codes).
- **Name** — required, `maxLength(255)`.
- **Active** — toggle, default true.
- **Supervisors** — `Select`→`multiple()`→`relationship('supervisors','name')`, options
  scoped to the property's staff via a `modifyQueryUsing` closure (`assignedAssets` on the
  selected property OR property-less; grouped so the OR cannot escape the scope).
- **Notes** — textarea.

**Table:** code (mono/bold), name, property badge, supervisors (badge list) + supervisor
count, `is_active` icon; filters for active + trashed; Edit action gated on `canEdit`.

## 7. Notifications / integrations — the routing (module 30 → 11)

The zone is the **routing target** for tenant requests. Three pieces wire it:

1. **A unit belongs to a zone.** `units.area_id` (nullable FK → `areas`, `nullOnDelete`).
   The `UnitForm` picker offers **only the unit's own property's active zones**
   (`asset_id` clamped through `TenantScope::clampAssetId`; out of scope ⇒ nothing), so a
   mall-A unit can never be tagged with a mall-B zone.
2. **A request inherits its unit's zone.** `tenant_requests.area_id` (nullable FK,
   `nullOnDelete`). Derived in `TenantRequest::creating` — so **admin, portal and API all
   inherit it** — when `area_id` is null: `area_id = unit.area_id`. An explicitly-set
   `area_id` is never overridden; a unit with no zone just yields null.
3. **Supervisors are notified.** `TenantRequest::created` fires
   `NotifyAreaSupervisorsService`, which sends `AreaRequestRaisedNotification` (database +
   push, **no mail** — a bell/app signal, matching the SLA-breach channel choice) to the
   zone's supervisors. **Notify, not assign** — assignment stays the coordinator's job.

Reconciled with department routing: **both apply** — the type's default department gets its
notification (`TenantRequestService::notifyOperators`), the zone's supervisors get theirs.

The `created` model event is the single hook every create path passes through (admin
Filament never touches `TenantRequestService`), so no channel can skip the routing.

## 8. Extension points (how to change safely)

- **Request routing is wired** (§7): `units.area_id` + `tenant_requests.area_id`, model-level
  derivation, and `NotifyAreaSupervisorsService`. **Extending to work orders**: add `area_id`
  to `maintenance_work_orders`, derive it the same way (from the linked request or the
  equipment/unit), and reuse `NotifyAreaSupervisorsService` (or a peer). Keep `area_id` a
  nullable FK (`nullOnDelete`) so a retired zone never strands a historical record.
- **The supervisor scope is one predicate** — `AreaForm::applySupervisorScope()` (property's
  own staff OR property-less). The picker, the post-save re-validation
  (`AreaResource::assertSupervisorsInScope`) and its regression test all derive from it; do
  not re-inline the grouped `whereHas()->orWhereDoesntHave()` (the OR must stay grouped or it
  escapes the outer scope).
- **More zone attributes** (floor, GIS polygon, capacity): add nullable columns + form
  fields; the isolation plumbing is unaffected.
- **Do not** give `area_user` a model unless a pivot needs its own behaviour — if you do,
  it must be classified in `PropertyIsolation` (it would be an *indirect* owned model via
  `area.asset_id` → chain `'area'`), or the conformance gate fails.
- **Do not** relax the per-property `code` unique or the `assertAssetInScope` guards.

## 9. Gotchas

- **Trashed rows hold the code.** `areas_asset_code_unique` counts soft-deleted rows, so the
  Edit page must expose Restore/ForceDelete (it does) — otherwise a typo'd code is burned
  forever.
- **The supervisor picker must stay property-scoped.** An ungrouped
  `whereHas(...)->orWhereDoesntHave(...)` would let the OR escape the outer asset scope and
  leak every mall's roster — it is grouped deliberately.
- **`asset_id` is client-supplied on edit.** Filament does not re-stamp it on update; the
  Edit page's `assertAssetInScope()` is the real guard.
- **Bare `Livewire::test(ListAreas)` does not apply Filament auto-tenancy** — cross-property
  read scoping is proven with `scopedResourceQuery()` (the `BypassesScopingOnAll` path), not
  by rendering the list.

## 10. Tests

`tests/Feature/Scenarios/AreaScenarioTest.php` — create + `is_active` default,
per-property code uniqueness (same code allowed across malls, refused within one),
supervisor assignment (many-to-many + pivot uniqueness + a supervisor across many areas),
RBAC (operations/coordinator create; viewer view-only; leasing none; delete = super_admin),
property scoping (read scope via `scopedResourceQuery`, write guard rejects an out-of-scope
`asset_id`), and the table rendering with rows.

`tests/Feature/Scenarios/AreaRoutingScenarioTest.php` — the routing slice: a request inherits
its unit's zone (direct + via `TenantRequestService`), an explicit zone is never overridden,
a caller-only / zone-less request stays zone-less, supervisors are notified on creation
(asserted via `Notification::fake()`), no-zone / no-supervisor creations are safe no-ops, the
supervisor picker offers only the property's own (or property-less) staff, and the post-save
re-validation strips + 403s an out-of-scope attach.

Plus the standing gates: `PropertyIsolationConformanceTest` (classification + scope + guard),
`AdminSmokeManifestConformanceTest` (regenerate with `php artisan atriom:dump-admin-manifest`),
and `TranslationCoverageTest` (EN/AR parity for the `admin.areas.*` keys + the `area`
activity subject).
