# Module 16 — Assets & Tenancy

> Date: 2026-05-31
> Status: 🟢 Green — the cross-cutting tenancy machinery is the most heavily tested code in the repo (80 cases). 2 Yellow extensibility findings.
> Surface: [Asset model](../../app/Models/Asset.php), [TenantScope](../../app/Support/TenantScope.php), 3 scoping traits ([ScopesViaProperty](../../app/Filament/Admin/Resources/Concerns/ScopesViaProperty.php), [BypassesScopingOnAll](../../app/Filament/Admin/Resources/Concerns/BypassesScopingOnAll.php), [BypassesFilamentTenantAutoScope](../../app/Filament/Admin/Resources/Concerns/BypassesFilamentTenantAutoScope.php)), [Admin Asset resource](../../app/Filament/Admin/Resources/Assets/), [RegisterProperty tenancy page](../../app/Filament/Admin/Pages/Tenancy/RegisterProperty.php).

## 1. Inventory

### 1.1 Asset model — [Asset.php](../../app/Models/Asset.php) (150 LOC)

- Traits: `HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes`. Implements `HasMedia`.
- **Constant**: `ALL_PROPERTIES_CODE = 'ALL'` — synthetic pseudo-tenant code. Method `isAllProperties()`.
- Fillable: 13 cols (`name, code, type, address, city, country, total_area_sqm, leasable_area_sqm, currency, primary_color, metadata, is_active`).
- Relations: `units()`, `leases()` HasManyThrough via units, `camPools()`, **`owners()`** BelongsToMany via `asset_owner` pivot (with `ownership_percentage, started_at, ended_at`), **`staff()`** BelongsToMany via `asset_user` pivot (with `role, assigned_at, ended_at, notes`), `utilityMeters()`.
- Helpers: `occupancyRate()` (used by HayaWalkSeeder line 244 demo metrics), `vacantUnitsCount()`, `occupiedUnitsCount()`, `logoUrl()`, `faviconUrl()`.
- MediaLibrary: two single-file collections (`logo`, `favicon`) for per-property branding.
- No observers, no boot logic.

### 1.2 Operator model — **retired**

The Operator concept (separate company entity for branding) was **dropped** by `2026_05_25_215041_drop_operators_and_seed_all_properties_asset.php` (per the migration comment "the Operator concept is being retired in favor of per-property tenancy"). Per-property branding now lives directly on Asset (`logo`, `favicon`, `primary_color`). The same migration seeds the synthetic `ALL` Asset row.

### 1.3 Migrations relevant to tenancy

| File | Effect |
|---|---|
| 2024_01_01_000001_create_assets_table.php | Base assets table. |
| 2026_05_23_150522_create_operators_table.php | **(Retired — dropped below.)** |
| 2026_05_23_150523_add_operator_id_to_assets_table.php | **(Retired — dropped below.)** |
| [2026_05_23_170119_create_asset_owner_table.php](../../database/migrations/2026_05_23_170119_create_asset_owner_table.php) | Legal ownership pivot (user_id ↔ asset_id + ownership_percentage + dates). |
| 2026_05_25_192822_create_asset_user_table.php | **Staff** assignment pivot (distinct from ownership): role label + dates + notes. |
| 2026_05_25_215041_drop_operators_and_seed_all_properties_asset.php | Drops `operator_id` FK + operators table; inserts the synthetic `ALL` Asset row (`is_active=false`, `name='All Properties'`, `code='ALL'`). |
| 2026_05_31_162046_add_primary_color_to_assets_table.php | Adds `primary_color` (hex, nullable) for per-property branding. |

### 1.4 Support layer — [TenantScope.php](../../app/Support/TenantScope.php) (99 LOC)

| Method | Behavior |
|---|---|
| `currentAssetId(): ?int` | Returns `Filament::getTenant()?->id` unless the tenant is the ALL pseudo-asset, in which case returns null. |
| `applyTo(Builder $query, ?string $relation = null): Builder` | Universal scoping helper. Direct `where('asset_id', $assetId)` when `$relation` is null; `whereHas($relation, fn => where('asset_id', $assetId))` otherwise. **No-op when `currentAssetId()` returns null** (covers both ALL view and no-tenant contexts). |
| `visibleAssetIds(): ?array` | Returns the asset IDs visible to the user: single tenant → `[id]`; ALL view + super_admin → `null` (unrestricted); ALL view + restricted user → their `AssignedAssets::idsForCurrentUser()` set. |

### 1.5 Scoping traits

| Trait | Used by | Purpose |
|---|---|---|
| [ScopesViaProperty](../../app/Filament/Admin/Resources/Concerns/ScopesViaProperty.php) (39 LOC) | Lease, Invoice, Payment, MaintenanceRequest, TenantSalesDeclaration | Indirect-FK resources (don't carry `asset_id` directly). Requires `abstract tenantScopeRelation(): string`. `getEloquentQuery()` whereHas's that relation. Composes `BypassesFilamentTenantAutoScope`. |
| [BypassesScopingOnAll](../../app/Filament/Admin/Resources/Concerns/BypassesScopingOnAll.php) (36 LOC) | Unit, UtilityMeter, CamExpensePool | Direct-FK resources. Overrides `scopeEloquentQueryToTenant` to skip scoping when the active tenant is the ALL pseudo-asset (Filament still applies the `asset_id` filter for real tenants). Also bypasses SoftDeletingScope on route binding. |
| [BypassesFilamentTenantAutoScope](../../app/Filament/Admin/Resources/Concerns/BypassesFilamentTenantAutoScope.php) (36 LOC) | CreditNote (special case: standalone notes visible across properties) | Minimal opt-out: `scopeEloquentQueryToTenant` becomes a no-op so the resource can implement custom logic. Also bypasses SoftDeletingScope on route binding. |

### 1.6 Admin Asset Resource

[AssetResource.php](../../app/Filament/Admin/Resources/Assets/AssetResource.php) (148 LOC). `$isScopedToTenant = false` — Asset IS the tenant, sits above the per-property layer. `getEloquentQuery()` always hides the `ALL` row from the management list. When in a specific property scope, narrows to `id == currentAssetId()`. When in ALL view (super_admin), unrestricted; non-super-admin restricted to their `AssignedAssets::idsForCurrentUser()`. **`canCreate()` blocks creation when inside a specific property** — operators must switch to ALL view before adding a new asset.

Relation managers: AssetUnitsRelationManager, AssetStaffRelationManager, ActivitiesRelationManager.

### 1.7 Tenancy registration page

[RegisterProperty.php](../../app/Filament/Admin/Pages/Tenancy/RegisterProperty.php) (52 LOC). Extends Filament's `RegisterTenant`. On fresh install where a user has zero properties assigned, Filament redirects here. The page calls `AssetForm::configure()` and `handleRegistration()` creates the Asset + syncs the user to `asset_user` pivot with `role='manager'`. **No multi-property growth path UX** beyond this — see F-62.

### 1.8 Property switcher (top bar)

Filament 4's built-in panel tenancy + AdminPanelProvider config:
- `.tenant(Asset::class, slugAttribute: 'code')` — URLs are `/admin/{code}/...`.
- `.tenantRegistration(RegisterProperty::class)` — fresh-install onboarding.

`User::getTenants(Panel)`:
- super_admin → all real assets (ALL excluded from the switcher dropdown — they don't need it).
- restricted user → assigned-asset set + the ALL pseudo-tenant prepended when count > 1.
- single-property user → just that property (ALL not shown — would be confusing).

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| MASTER-PLAN.md §1 | "Per-property panel tenancy — Filament 4's built-in tenancy with Asset as the tenant model. URLs scoped at `/admin/{property-code}/...`, top-nav property switcher, synthetic 'All Properties' pseudo-tenant for portfolio-wide views, three shared traits (ScopesViaProperty, BypassesScopingOnAll, BypassesFilamentTenantAutoScope) keep the resource code one-liner-thin." | ✅ all three traits exist, exactly as named |
| MASTER-PLAN.md | "Replaced the earlier session-based Operator scope entirely." | ✅ Operator dropped 2026-05-25 |
| FEATURES.md | "Per-property panel branding — `logo`, `favicon`, `primary_color` resolved from the active Asset; ALL pseudo-tenant falls back to platform defaults." | ✅; recent commit `4a96a67` fixed a CSS injection bug in this path |
| MASTER-PLAN.md | "Widgets use `TenantScope::applyTo()` for consistent filtering." | ✅ confirmed in MallStats, MonthlyRevenueTrend, ArAging, etc. |

## 3. Findings

### 🟢 Cross-cutting design choices are correct

- ALL_PROPERTIES_CODE as a real-but-inactive DB row (not a session flag) lets URLs cleanly route to `/admin/ALL/...`. Filament's slug-based resolution handles it identically to any tenant.
- Three traits with sharp responsibility split: indirect-FK / direct-FK / custom. Resource authors pick one and inherit the right scoping for their FK shape.
- `TenantScope::applyTo()` is the single source of truth for query scoping outside Filament's auto-scope (widgets, relation managers, nav badges all use it).
- The fix to the cross-cutting F-17 (nav badges respect tenant scope) brings every Filament admin resource into consistent behavior.

### 🟢 Per-property branding refactor is complete

Operator-as-separate-entity model retired. All branding (logo, favicon, primary_color) now lives directly on Asset. Recent commit [4a96a67](../../docs/gap-analysis/15-vendors.md) fixed an invalid-CSS bug in this path.

### 🟡 F-61. No model-level test for Asset::occupancyRate(), isAllProperties() etc.

The tenancy layer is heavily tested (80 cases under `tests/Feature/Tenancy/`), but the Asset model's own computed methods (`occupancyRate()`, `vacantUnitsCount()`, `occupiedUnitsCount()`, `logoUrl()` / `faviconUrl()` fallback behavior) don't have dedicated tests. They're exercised indirectly via widget tests and the seeder. A small `tests/Feature/Models/AssetTest.php` would tighten coverage.

**D-46** deferred — bundle with the post-sweep test-writing pass.

### 🟡 F-62. No multi-property growth path documented

The RegisterProperty page is built for fresh-install (zero properties). After Haya Walk is registered, operators can:

- Create a new Asset only from ALL view (and super_admin / multi-property roles can reach ALL).
- A single-property non-super-admin user adding a second property has no documented path — they'd need a super_admin to grant them access to ALL view first.

For an Atriom-as-platform scenario where customers grow from 1 property to 3-5, this onboarding gap matters. Today the workaround is: super_admin creates the new Asset, then attaches the operator user to `asset_user` pivot.

**D-47** deferred — UX decision: should a single-property manager be able to register a sibling property directly?

### 🟢 80 tests across the tenancy layer

| File | Coverage |
|---|---|
| `tests/Feature/Tenancy/TenantScopeTest.php` | `currentAssetId()`, `visibleAssetIds()` matrix |
| `tests/Feature/Tenancy/TenantScopeApplyToTest.php` | `applyTo()` with + without relation chains |
| `tests/Feature/Tenancy/AssetResourceTest.php` | List scoping, create gating, ALL row hidden |
| `tests/Feature/Tenancy/UserTenantsTest.php` | `getTenants()` ALL-prepend logic, soft-deleted asset handling, single-property omission |
| `tests/Feature/Tenancy/ResourceScopingTest.php` | Deep coverage across all scoped resources |
| `tests/Feature/Tenancy/SoftDeletedAssetTest.php` | Soft-deleted assets unreachable + route binding bypasses SoftDeletingScope |
| `tests/Feature/Branding/*` (via filter match) | Branding resolution incl. the 4a96a67 fix |

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Tenancy|Asset|TenantScope|UserTenants|SoftDeletedAsset|Branding'` | **80 passed / 0 failed** | 2.64 s |
| Full Pest (post-Module 15) | **295 passed / 0 failed** | 4.6 s |

## 5. No inline fixes this module

Both Yellow findings are scope/test extensions. The 6-instance F-17 cross-cutting fix already cleaned up the per-property nav-badge behavior across every resource that touched this layer.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-46 | F-61: add Asset model tests | Bundle with post-sweep test pass |
| D-47 | F-62: multi-property growth path | UX decision; revisit when first customer expands |

## 7. Verdict

**🟢 Green.** The tenancy substrate is the most thoughtful and well-tested code in the repository. The 80-test coverage + 3-trait split + ALL-as-real-row design pattern is genuinely production-ready. The two Yellow findings are forward-looking refinements, not defects.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢.

## Next

Module 17 — Users + Roles. Surface: [User model](../../app/Models/User.php), [Admin Users resource](../../app/Filament/Admin/Resources/Users/), [Roles resource](../../app/Filament/Admin/Resources/Roles/), [RolesPermissionsSeeder](../../database/seeders/RolesPermissionsSeeder.php), Spatie permission setup, and the `canAccessPanel` gating that fans out across the 3 panels.
