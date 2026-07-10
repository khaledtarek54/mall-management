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

## Shared vs. isolated — the register

The authoritative, testable source of truth is **[`App\Support\PropertyIsolation`](../app/Support/PropertyIsolation.php)**.

**SHARED across all properties** (operator-wide; no per-property row scoping):
User/roles · **LedgerAccount** (one chart; property is a *dimension* on journal lines) · FiscalYear ·
AccountingPeriod · AccountMapping (global default + optional per-property override) · SystemSetting ·
**InventoryItem** (catalog) · **Vendor** (catalog) · **Tenant** / TenantUser / DeviceToken · Note · Department.

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

- Direct-`asset_id` models → **`BypassesScopingOnAll`** (Filament auto-tenancy via
  `$tenantOwnershipRelationshipName = 'asset'`, plus the All-Properties escape hatch).
- Indirect models → **`ScopesViaProperty`** (declare `tenantScopeRelation()`, e.g. `'lease.unit'`).
- Special cases → **`BypassesFilamentTenantAutoScope`** + a custom `getEloquentQuery()`.
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

The guard is **`App\Filament\Admin\Resources\Concerns\GuardsAssetInScope::assertAssetInScope($assetId)`**
— it `abort(403)`s when a restricted user submits a property outside `visibleAssetIds()` and is a no-op
for portfolio users. Wire it from the page's `mutateFormDataBeforeCreate` / `mutateFormDataBeforeSave`.
For models whose `asset_id` is **derived from a relation** (DepositTransaction, CreditNote → lease),
scope the relation picker to `visibleAssetIds()` **and** guard the derived asset via the selected record.

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
