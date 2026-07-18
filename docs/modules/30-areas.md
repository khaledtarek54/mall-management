# 30 · Areas (facility zones)

> A facility zone within a mall — Ground Floor, Food Court, Parking, Roof Plant.
> The building block for **routing**: in a later slice, incoming requests / work
> orders are dispatched to the zone's supervisor(s). This slice ships the register
> and the supervisor assignment; it does **not** yet wire routing into
> units/requests/work orders (that is a separate follow-up).

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

None. This is a straight CRUD register — the business logic is entirely the property
isolation + uniqueness rules enforced by the model, migration, and Filament form. When
routing is added, a single-action dispatch service will read `Area::supervisors()`; there
is deliberately no service yet (nothing routes).

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

## 7. Notifications / integrations

None in this slice. (Routing — the eventual consumer — will notify supervisors; that is a
follow-up.)

## 8. Extension points (how to change safely)

- **Adding routing** (the planned follow-up): add `area_id` to `units` and/or the request /
  work-order tables, backfill, then a `RouteRequestToArea` single-action service reading
  `Area::active()->...->supervisors()`. Keep `area_id` a nullable FK (`nullOnDelete`) so a
  retired zone never strands a historical request.
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

Plus the standing gates: `PropertyIsolationConformanceTest` (classification + scope + guard),
`AdminSmokeManifestConformanceTest` (regenerate with `php artisan atriom:dump-admin-manifest`),
and `TranslationCoverageTest` (EN/AR parity for the `admin.areas.*` keys + the `area`
activity subject).
