# Module 03 — Units

> Date: 2026-05-31
> Status: 🟡 Yellow — code lean; 2 inline fixes (importer enum drift, navigation badge tenant leak); 1 cross-cutting finding affects 4 other resources; 2 deferred items.
> Surface: [Unit model](../../app/Models/Unit.php), [Admin Units resource](../../app/Filament/Admin/Resources/Units/), [OccupancyMap page](../../app/Filament/Admin/Pages/OccupancyMap.php), [UnitImporter](../../app/Filament/Imports/UnitImporter.php), [UnitExporter](../../app/Filament/Exports/UnitExporter.php).

## 1. Inventory

### 1.1 Model — [app/Models/Unit.php](../../app/Models/Unit.php) (69 LOC)

- Traits: `HasFactory`, `SoftDeletes`. **No `HasMedia`, `LogsActivity` on Unit** — consistent with FEATURES.md which lists those only for Lease/Tenant/MaintenanceRequest.
- `$fillable`: `asset_id`, `code`, `floor`, `category`, `area_sqm`, `status`, `description`, `features`.
- `$casts`: `features` → array, `area_sqm` → `decimal:2`.
- Relations: `asset()` BelongsTo, `leases()` HasMany, `maintenanceRequests()` HasMany, `utilityMeters()` HasMany, `activeLease()` HasOne (`status='active'` + `latest('commencement_date')`).
- Helpers: `currentTenant(): ?Tenant` (via `activeLease?->tenant`), `fullName(): string` ("Asset · Code").
- **No model scopes** (e.g. no `scopeVacant()`); status queries are inline `where('status','vacant')` in callers. Acceptable for the codebase's size, but worth flagging.

### 1.2 Migration — [2024_01_01_000002_create_units_table.php](../../database/migrations/2024_01_01_000002_create_units_table.php)

- FK: `asset_id` constrained, cascade-on-delete.
- Status enum: **`vacant`, `reserved`, `occupied`, `maintenance`** (4 states, `vacant` default).
- Category enum: **`retail`, `food_beverage`, `wellness`, `service`, `kiosk`, `office`, `storage`** (7 states, `retail` default).
- Unique: (`asset_id`, `code`); indexes on (`asset_id`, `status`) and `category`.
- Soft deletes enabled.

No later migrations modify the Units table.

### 1.3 Admin Resource — `app/Filament/Admin/Resources/Units/`

| File | LOC | Notes |
|---|---:|---|
| [UnitResource.php](../../app/Filament/Admin/Resources/Units/UnitResource.php) | 105 | Traits: `BypassesScopingOnAll`, `RoleGatedActions`. `$tenantOwnershipRelationshipName = 'asset'` for direct-FK tenancy. Nav: icon `BuildingStorefront`, sort 2, group "Operations". Globally searchable on `code`, `asset.name`, `activeLease.tenant.name`. |
| Schemas/UnitForm.php | 57 | One section "Unit Details" with 3 columns: asset_id (disabled when scoped), code (max 20), floor, category, area_sqm (suffix `m²`), status (default `vacant`), description. |
| Tables/UnitsTable.php | 150 | 8 columns inc. activeLease tenant + rent + expiry; 6 filters (status, category-excluding-office-storage, asset_id, lease_expiring_soon ≤ 90d, lease_expiring_critical ≤ 30d, trashed); header `ExportAction`; bulk `Export|Delete|ForceDelete|Restore`. |
| Pages/ListUnits.php | 25 | Adds `ImportAction(UnitImporter)` + `CreateAction`. |
| Pages/{Create,Edit}Unit.php | thin | Standard Filament pages. |

Importer / Exporter live under `app/Filament/Imports/` and `app/Filament/Exports/` (shared location, not co-located).

### 1.4 OccupancyMap page — [app/Filament/Admin/Pages/OccupancyMap.php](../../app/Filament/Admin/Pages/OccupancyMap.php) (82 LOC)

- Route: `/admin/{tenant}/occupancy-map`, nav group "Operations", icon `Squares2x2`, sort 5.
- Data: groups Units by `floor`, ordered by floor → code; computes counts for each status; eager-loads `activeLease.tenant`.
- View: [resources/views/filament/pages/occupancy-map.blade.php](../../resources/views/filament/pages/occupancy-map.blade.php) (~82 LOC) — legend strip, floor-grouped CSS grid (auto-fill 120px), tile colour from `$statusColors` map, clickable into `UnitResource::getUrl('edit')`.

### 1.5 Owner panel

No dedicated Owner `UnitResource`. Owners see unit-level data through [PropertyResource](../../app/Filament/Owner/Resources/Properties/PropertyResource.php) + [PropertyInfolist](../../app/Filament/Owner/Resources/Properties/Schemas/PropertyInfolist.php). Owner is **read-only** w.r.t. units; gap-checked in Module 10.

### 1.6 Cross-refs (where Units are read)

- Widgets: `MallStats` (occupancy KPI), `TenantMix` (category mix), `ActionRequired` (vacant cards), `SetupGuide` (step 1).
- Resources: `LeaseResource` form has a `unit_id` select filtered to vacant units; `MaintenanceRequestResource` has `unit_id`; `UtilityMeterResource` allows `unit_id` null for common-area meters.
- Page: `OccupancyMap` consumes Units directly.

## 2. Spec map

| Source | Verbatim claim | Verified |
|---|---|---|
| DEMO.md §2 | "33 of 50 units leased" | Already documented in [01-dashboard.md F-1](01-dashboard.md) — actual 33/58 in All-Properties view |
| DEMO.md §3 | "Pick a vacant unit (the list filters to vacant only). Save. 'Lease is created, unit flips to occupied automatically.'" | Defer verification to Module 04 Leases |
| DEMO-ELTIZAM.md | "50 units, 33 active leases, real data, real money" | Same caveat as DEMO.md §2 |
| FEATURES.md | "OccupancyMap (Operations) — floor-grouped color-coded unit grid for any property" | ✅ exists, healthy |
| FEATURES.md | "Tenant Directory (Unit) — the same data as a list — every unit, every lease, every status" | ✅ TenantResource navigation label is "Tenant Directory"; UnitsTable shows tenant via activeLease |
| FEATURES.md | "Quick New Lease wizard … filters to vacant units … auto-seeds charges, flips unit to occupied" | Defer to Module 04 |

## 3. Findings

### 🔴 F-12 (Fixed inline) — UnitImporter category enum diverged from DB enum

- **Pre-fix:** [UnitImporter:40](../../app/Filament/Imports/UnitImporter.php#L40) enforced `in:retail,food_beverage,service,wellness,kiosk,anchor,office,other`.
- **Migration enum:** `retail, food_beverage, wellness, service, kiosk, office, storage`.
- **Two-way bug:**
  - Importer **accepted** `anchor` and `other` — both values pass row validation but fail at SQL insert with an enum-mismatch error.
  - Importer **rejected** `storage` — a legitimate enum value the DB stores natively; importing a CSV with `category=storage` fails row validation.
- **Fix applied:** importer now uses `in:retail,food_beverage,wellness,service,kiosk,office,storage` — exact mirror of the migration enum. See [UnitImporter:40](../../app/Filament/Imports/UnitImporter.php#L40).
- **Regression check:** Pest 287/287 green; Playwright `15-csv-import.spec.js` 3/3 green.

### 🔴 F-17 (Cross-cutting; partially fixed) — Navigation badges bypass tenant scope

- `UnitResource::getNavigationBadge()` was `static::getModel()::where('status','vacant')->count()` — a raw model query that ignores Filament's active tenant.
- Operational effect: when an operator is scoped to Haya Walk, the "Units" nav badge showed the **portfolio-wide** vacant count (33 across Haya Walk + Plaza Annex), not the per-property count (25 on Haya Walk).
- **Fix applied (Unit only):** [UnitResource:76-84](../../app/Filament/Admin/Resources/Units/UnitResource.php#L76) now uses `static::getEloquentQuery()->where('status','vacant')->count()`. `BypassesScopingOnAll::scopeEloquentQueryToTenant` handles the per-property filter and correctly returns the global count for the synthetic "All Properties" pseudo-asset.

**Same pattern in 4 other resources — carried forward to their module audits:**

| Resource | Badge | Module that owns the fix |
|---|---|---|
| [InvoiceResource:86-93](../../app/Filament/Admin/Resources/Invoices/InvoiceResource.php#L86) | overdue invoice count | Module 05 — Invoices |
| [MaintenanceRequestResource:65-79](../../app/Filament/Admin/Resources/MaintenanceRequests/MaintenanceRequestResource.php#L65) | open + has-urgent | Module 09 — Maintenance |
| [VendorResource:114-127](../../app/Filament/Admin/Resources/Vendors/VendorResource.php#L114) | (tbd at Module 15) | Module 15 — Vendors |
| [TenantSalesDeclarationResource:62-69](../../app/Filament/Admin/Resources/TenantSalesDeclarations/TenantSalesDeclarationResource.php#L62) | (tbd at Module 12) | Module 12 — Tenant Sales |

Each of those audits will fix the same pattern and reference this finding by number.

### 🟡 F-13. No dedicated `UnitTest.php`

- `php artisan test --parallel --filter='Unit|Occupancy'` matches 16 tests but they're scoping/widget tests where "Unit" appears in the suite path, not Unit-model tests.
- The Unit model has 4 non-trivial methods: `activeLease()` (HasOne with `status='active'` + latest filter), `currentTenant()` (`activeLease?->tenant`), `fullName()` (pure), `maintenanceRequests()`/`utilityMeters()` (plain HasMany).
- The HasOne+latest pattern in particular can break silently if two leases share `status='active'` (which should never happen, but the model doesn't enforce it). A targeted test would assert "two active leases on the same unit returns the latest by commencement_date".
- Deferred to a later test-writing pass (out of audit scope to add new test files without explicit ask).

### 🟡 F-18. UnitImporter `status` rule order mismatch

- Importer status rule: `in:vacant,occupied,maintenance,reserved`.
- DB enum order: `vacant,reserved,occupied,maintenance`.
- The **set** of values is identical (so no bug), but the **order** difference is cosmetic-only — Laravel's `in:` ignores order. Worth a one-line reorder for grep-readability, but not behavior-affecting.

### 🟢 No architectural finding for "no UnitPolicy"

- `app/Policies/` directory does not exist. **No resource in the project uses Laravel-native policies.** Permission gating goes through:
  - `RoleGatedActions` trait — wraps Filament `canCreate`/`canEdit`/`canDelete` with Spatie role checks.
  - `RoleScopedWidget` trait — gates widgets per role.
  - Module gating (`Modules::enabled('reports')`) for entire resources/pages.
- This is a **deliberate, consistent architectural choice**, not an oversight. Filament 4 + Spatie permission + Role-gated traits is the project's authoritative permission stack. Documenting here so future reviewers don't propose policies as a "missing standard Laravel feature".

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Unit|Occupancy'` | **16 passed / 0 failed** | 1.18 s |
| `npx playwright test tests/e2e/15-csv-import.spec.js` | **3 passed / 0 failed** | 6.5 s |
| `php artisan test --parallel` (post-importer-fix regression) | **287 passed / 0 failed** | 4.13 s |
| `php artisan test --parallel` (post-nav-badge-fix regression) | **287 passed / 0 failed** | 4.15 s |

Already-covered behaviors elsewhere:
- Occupancy maths: `MallStats` widget tests (Module 01).
- Per-property scoping: `WidgetScopingTest`, `TenantScopeTest`, `ResourceScopingTest`.
- Unit display in lease/invoice contexts: `LeaseResource` + `InvoiceResource` tests (queued for Modules 04, 05).

## 5. Manual UX

- OccupancyMap page renders under Playwright system smoke (`99-system-smoke.spec.js`); not re-run this pass.
- UnitsTable + import action visibly rendered in `15-csv-import.spec.js`.
- No new manual UX defects observed.

## 6. Inline fixes committed in Module 03

1. **F-12**: `UnitImporter` category rule aligned to migration enum exactly.
2. **F-17 (Unit only)**: `UnitResource::getNavigationBadge()` now uses `static::getEloquentQuery()` so vacant counts respect the current tenant scope.

Each fix is ≤6 LOC. Full test suite passes after each.

## 7. Deferred decisions

| # | Decision | Default if not raised |
|---|---|---|
| D-10 | F-13: add `tests/Feature/Models/UnitTest.php` covering `activeLease`, `currentTenant`, `fullName`, and the "no two active leases" guarantee | Defer to a dedicated test-writing pass after the 20-module sweep |
| D-11 | F-18: reorder importer `status` rule to match DB enum order for grep-readability (`vacant,reserved,occupied,maintenance`) | Apply when I next touch this file |

## 8. Verdict

**🟡 Yellow.** The Unit module's code is small, clean, and well-scoped. Two real bugs were latent: the CSV importer would have failed silently on any production CSV using `storage` (and would have crashed on `anchor`/`other`), and the navigation badge was lying about per-property counts. Both are fixed inline. One cross-cutting finding (F-17) seeded fix work for 4 other modules — they each get a one-line note in their audits.

Module ratings to date:
- Module 00: 🟢, Module 01: 🟡, Module 02: 🟢, Module 03: 🟡.

## Next

Module 04 — Leases. Surface: [Lease model](../../app/Models/Lease.php), [Resources/Leases](../../app/Filament/Admin/Resources/Leases/), [LeaseCreationService](../../app/Services/LeaseCreationService.php), [LeaseRenewalService](../../app/Services/LeaseRenewalService.php), [LeaseTerminationService](../../app/Services/LeaseTerminationService.php), and the unit-status side-effects (Lease save → unit flip to occupied) DEMO.md §3 calls out.
