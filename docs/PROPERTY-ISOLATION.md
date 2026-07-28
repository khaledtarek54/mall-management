# Property Isolation — how it works & how to extend it safely

> **The living reference.** For the design rationale and sign-off decisions see
> [PROPERTY-ISOLATION-PLAN.md](PROPERTY-ISOLATION-PLAN.md). This file is what you read before
> touching a property-owned module.

## The invariant

> A property-restricted user, with a property selected, can only **read or write** rows belonging to
> that property (or their assigned set in All-Properties mode). Portfolio roles (super_admin / owner)
> may consolidate, but never accidentally.

Isolation is **soft, row-level**: one shared MySQL database; every property-owned row carries `asset_id`
(directly or via a relation chain); `Asset` is the Filament panel tenant. There is **no** separate
database per property — the operator's shared chart of accounts, cross-mall tenants, and portfolio
consolidation all depend on one shared store.

> **Property-first UX — "All Properties" is no longer a selectable operational tenant** (see
> [plans/03-remove-all-properties-mode.md](plans/03-remove-all-properties-mode.md)). The operator always
> works **inside one real mall**: the switcher offers only real properties (`User::getTenants()`), and the
> ALL pseudo-asset is refused by `canAccessTenant()` — a crafted `/admin/ALL` URL 404s. Consequences you
> can rely on **on operational screens**: `currentAssetId()` is always a real mall (never null from
> All-mode), and `visibleAssetIds()` returns `[currentId]`. The ALL pseudo-asset row,
> `Asset::ALL_PROPERTIES_CODE`, `isAllProperties()`, `TenantScope`'s pseudo-asset handling, and **every
> guard described below stay in place** — as internal plumbing for a future read-only *consolidation*
> surface (Phase B) and as defense-in-depth. The guards are still load-bearing: the conformance gate and
> the clobber tests exercise them by **force-setting** the pseudo-asset tenant (`Filament::setTenant`),
> which bypasses `canAccessTenant()` on purpose. Do not "simplify" them away on the theory that All-mode
> is gone — it is gone from the *switcher*, not from the plumbing.

## Shared vs. isolated — the register

The authoritative, testable source of truth is **[`App\Support\PropertyIsolation`](../app/Support/PropertyIsolation.php)**.

**SHARED across all properties** (operator-wide; no per-property row scoping):
User/roles · **LedgerAccount** (one chart; property is a *dimension* on journal lines) · FiscalYear ·
AccountingPeriod · AccountMapping (global default + optional per-property override) · SystemSetting ·
**InventoryItem** (catalog) · **Vendor** (catalog) · **Tenant** / TenantUser / DeviceToken · Note.

> **Department is NOT shared** — it is a *hybrid* per-property model (nullable `asset_id`: null = operator-wide,
> a set value scopes it to one property). It lives in `OWNED`; its resource scopes reads to
> "global OR your visible set" and guards its edit. (Misclassifying it SHARED was a real read leak — a
> `SHARED`-model-has-no-`asset_id` test now guards against that class.)

**The shared-catalog-with-per-property-use pattern** — how something is shared *without* leaking:

| Shared master (global) | → | Per-property usage (`asset_id`) |
|---|---|---|
| Vendor | → | VendorContract, VendorBill |
| InventoryItem | → | Warehouse, StockMovement |
| LedgerAccount | → | JournalEntry / JournalLine (dimension) |
| Tenant | → | Lease, Invoice, Payment |
| AccountMapping | → | per-property override row |

**ISOLATED per-property**: everything in `PropertyIsolation::OWNED` — leases, invoices, payments, credit
notes, deposits, CAM, marketing, meters, maintenance, HR/payroll/custody, fixed assets, warehouses,
journal entries, expenses, and their children.

## The two halves of the mechanism

### 1. Read scoping (which rows a query returns)

- Direct-`asset_id` models, **read-only or with a non-editable `asset_id`** → **`BypassesScopingOnAll`**
  (Filament auto-tenancy via `$tenantOwnershipRelationshipName = 'asset'`, plus the All-Properties
  escape hatch).
- Direct-`asset_id` models whose **form exposes an editable `asset_id`** (the operator picks the mall;
  the Select is enabled in All-Properties mode) → **`BypassesFilamentTenantAutoScope`** + a manual
  `getEloquentQuery()` (the `currentAssetId()` / `visibleAssetIds()` block, as `AnnouncementResource`
  does). **Do NOT use `BypassesScopingOnAll` here** — it keeps `isScopedToTenant() === true`, so
  Filament's `creating` hook force-associates `asset_id` with the current tenant, and in All-Properties
  mode (tenant = ALL pseudo-asset) that silently clobbers the chosen mall (the "Announcements tenancy
  trap"; `Test D` of the conformance gate + `AllPropertiesCreatePinsAssetTest` guard against it). Keep
  the `assertAssetInScope` write guard on create **and** edit.
- Indirect models → **`ScopesViaProperty`** (declare `tenantScopeRelation()`, e.g. `'lease.unit'`).
- Other special cases → **`BypassesFilamentTenantAutoScope`** + a custom `getEloquentQuery()`.
- Widgets / services / reports → **`App\Support\TenantScope`** (`applyTo`, `visibleAssetIds`,
  `reportAssetIds`, `selectable*`). **Always** derive the constraint from `visibleAssetIds()` (null only
  for portfolio users), never `currentAssetId()` alone — the latter is null in All-mode and leaks.

### 2. Write guarding (which property a create/edit may target)

**Filament stamps `asset_id` = current tenant on CREATE only** (for `isScopedToTenant() === true`
resources) — never on update. So:

| Resource kind | Create | Edit |
|---|---|---|
| `isScopedToTenant() === true` (auto-stamped) | Safe — Filament overwrites any tampered `asset_id` | **Needs a guard** if `asset_id` is editable (not re-stamped on update) |
| `isScopedToTenant() === false` (opts out) | **Needs a guard** — `asset_id` is fully client-supplied | **Needs a guard** |

The guard is **`App\Filament\Admin\Resources\Concerns\GuardsAssetInScope`**. It `abort(403)`s when a
restricted user submits a property outside `visibleAssetIds()` and is a no-op for portfolio users. Wire it
from the page's `mutateFormDataBeforeCreate` / `mutateFormDataBeforeSave`:

- **Direct `asset_id`** (Expense, VendorBill, Payroll, JournalEntry, OwnerRequest, Unit, CamExpensePool,
  UtilityMeter, Employee, …): `assertAssetInScope($data['asset_id'])`.
- **Chain-derived** (Invoice/TenantSalesDeclaration/CreditNote/DepositTransaction ← lease;
  Lease/MaintenanceRequest ← unit; Payment ← allocated invoices): the property comes from a client FK, so
  the picker `->when(currentAssetId(), …)` is a **no-op in All-mode and leaks** — scope every such picker to
  `visibleAssetIds()` **and** guard with the FK-resolving helpers `assertLeaseAssetInScope` /
  `assertUnitAssetInScope` / `assertUnitsAssetInScope` / `assertInvoiceAssetInScope`.
- **Relation managers** are outside the resource-page flow — guard a client-supplied `asset_id`/FK there
  with a field `->rules([...])` closure (see `Vendors/RelationManagers/ContractsRelationManager`).

**Filament stamps `asset_id` only on CREATE** (for `isScopedToTenant()===true` resources), never on
update — so an editable `asset_id`/FK on the **edit** page always needs a guard.

## The self-enforcing gate

**[`tests/Feature/Scenarios/PropertyIsolationConformanceTest.php`](../tests/Feature/Scenarios/PropertyIsolationConformanceTest.php)**
fails CI when a new model/resource ships unclassified, unscoped, or unguarded:

- **A** — every Eloquent model is classified in `PropertyIsolation`; direct-FK owned models have an
  `asset_id` column; indirect ones expose their chain's first hop.
- **B** — every property-owned admin resource scopes its table reads (a scoping trait, a
  `getEloquentQuery()` override, or an explicit `$tenantOwnershipRelationshipName`).
- **C** — every must-guard resource wires `assertAssetInScope` on its create/edit pages; **and** any
  not-auto-stamped (`isScopedToTenant=false`) owned resource exposing an editable `asset_id` is
  auto-flagged if it's missing from the must-guard set.
- **D** — no owned resource whose **create form** exposes an editable, dehydrated `asset_id` still uses
  Filament auto-tenancy (`isScopedToTenant() === true`). Such a resource clobbers the operator's picked
  mall to the ALL pseudo-asset on create in All-Properties mode; it must opt out via
  `BypassesFilamentTenantAutoScope`. (The gate renders each create form and inspects the `asset_id`
  component's disabled/dehydrated state, so a new such resource fails CI unless it opts out.)

## How to add a new property-owned module safely

1. Give the model an `asset_id` column (or a clean relation chain to one).
2. Register it in **`PropertyIsolation::OWNED`** (`null` for direct-FK, or the chain string).
3. Scope the resource table: `BypassesScopingOnAll` (direct) or `ScopesViaProperty` (indirect).
4. Scope every cross-property form select via `TenantScope::selectable*` / `visibleAssetIds()`.
5. If the form exposes/derives an editable `asset_id`: `use GuardsAssetInScope`, call
   `assertAssetInScope(...)` from create **and** edit pages, and add the resource to
   `propertyIsolationMustGuardResources()` in the conformance test.
6. Run `vendor/bin/pest --parallel` — the conformance gate tells you what you missed.

## Boundaries (out of scope by design)

- **Tenant portal** (`TenantUser`) and **Mobile API** (`Tenant`, Sanctum) are **tenant-scoped**, not
  asset-scoped: a retailer sees all their own data across every mall they lease in. Cross-tenant API
  returns **404**. This is intentional and unchanged.
- **Scheduled scans** run in console context (no Filament tenant → portfolio-wide) by design.

---

## The second scoping primitive: assignment (FR-USR-04)

`TenantScope` answers **"which properties may you see"**. `App\Support\AssignmentScope` answers
**"which rows within them are yours"**. They are independent and both apply — being *assigned* a job
never grants access to the mall it sits in.

The FRD: *"Every user shall see only the requests/work orders assigned to them, **filtered by role
and assignment**."* "Every user" is not literal — its own role table gives the Admin "full access for
their assigned mall" and the Coordinator "oversight", while the **In-house Technician** "sees only
work assigned to them". **The role decides whether the filter applies.**

- Expressed as a permission (`{module}.view_all`), not a role list — holders oversee the module,
  non-holders see their own work. Every pre-existing role was granted it, so nothing narrowed.
- **Fails closed:** no user, or no permission, means restricted.
- **A query constraint, never a filter.** A filter is a checkbox the user can clear; that is not
  what "sees only work assigned to them" means. It also covers the record page for free, since
  Filament resolves records through the same query.

> ⚠️ **`ScopesViaProperty` IS `getEloquentQuery()`.** A resource using that trait (TenantRequest and
> anything else scoped through a relation rather than its own `asset_id`) gets its property
> isolation *entirely* from that method. Declaring `getEloquentQuery()` in the class **shadows the
> trait** — a class method always beats a trait's — which silently deletes property isolation:
> every restricted user reads every mall.
>
> This is not hypothetical; it happened while building FR-USR-04, and `ResourceScopingTest` +
> `OpsIsolationScenarioTest` caught it. **Alias the trait and wrap it:**
>
> ```php
> use ScopesViaProperty {
>     ScopesViaProperty::getEloquentQuery as scopedViaPropertyQuery;
> }
>
> public static function getEloquentQuery(): Builder
> {
>     return AssignmentScope::apply(static::scopedViaPropertyQuery(), 'maintenance', 'assigned_to');
> }
> ```

**Note also:** FR-USR-04 puts a *permission check in the query layer*, which previously had none.
A fixture that builds `makeUser('super_admin')` without seeding roles now sees nothing — correctly,
since fail-closed — so any test asserting scoping must seed `RolesPermissionsSeeder`.

## Gotcha — "was this user ever scoped?" must not read a soft-deleting relation

`AssignedAssets::idsFor()` returns `null` (= unrestricted) for a user who was
NEVER assigned or an owner — deliberate back-compat for single-mall deployments
— but the fail-closed sentinel `[0]` (= sees nothing) for a user whose scope has
**lapsed**.

That probe must ask about the **assignment**, not the asset. It originally used
`assignedAssets()` / `ownedAssets()`, which are relations to a **soft-deleting**
`Asset`. Archiving a property therefore made them return nothing, so a staff
member assigned to exactly that property fell into the "never scoped" branch and
became **unrestricted** — gaining read access to every other property in the
portfolio. Archiving a mall is an ordinary super_admin action, so this was
reachable, not theoretical.

It now reads the `asset_user` / `asset_owner` pivot rows directly, which are
independent of the asset's soft-delete state.

**If you touch this probe:** any relation you use must survive the related model
being soft-deleted, or the failure mode is silent privilege escalation rather
than an error. Guarded by
`tests/Feature/Regression/AssignedAssetsLapsedScopeTest.php`.
