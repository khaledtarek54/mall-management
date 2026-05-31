# Module 10 — Owner Portal

> Date: 2026-05-31
> Status: 🟢 Green — mature read-only third panel; 2 Yellow extensibility findings (nav badges, `cam.view` permission unused).
> Surface: [OwnerPanelProvider](../../app/Providers/Filament/OwnerPanelProvider.php), 3 Resources (Properties / Invoices / Maintenance), [PortfolioStats widget](../../app/Filament/Owner/Widgets/PortfolioStats.php), [AssetStatementPdfService](../../app/Services/AssetStatementPdfService.php).

## 1. Inventory

### 1.1 Panel

[OwnerPanelProvider.php](../../app/Providers/Filament/OwnerPanelProvider.php) (62 LOC). Panel id `owner`, path `/owner`. Branding: `Atriom · Owner Portal`. Discovered resources auto-load; Dashboard registered explicitly. PortfolioStats widget registered explicitly.

**Notably no Filament tenancy.** Owners aren't routed through Filament's `Asset`-tenant URLs — scoping is per-Resource via `getEloquentQuery()` filters against the `asset_owner` pivot. Cleaner for single-portfolio operators; aggregates across all owned assets when an owner has multiple.

### 1.2 Resources

| Resource | LOC | Scope filter | Pages | Notable |
|---|---:|---|---|---|
| [PropertyResource](../../app/Filament/Owner/Resources/Properties/PropertyResource.php) | 88 | `whereHas('owners', fn($q) => $q->where('user_id', auth()->id()))->withCount('units')` (also excludes `ALL_PROPERTIES_CODE`) | List, View | View page has **Download Property Statement** header action — streams `AssetStatementPdfService::build()` (HTTP test at [tests/Feature/Owner/OwnerStatementPdfTest.php](../../tests/Feature/Owner/OwnerStatementPdfTest.php)). Read-only — `canCreate/canEdit/canDelete = false`. |
| [InvoiceResource](../../app/Filament/Owner/Resources/Invoices/InvoiceResource.php) | 80 | `whereHas('lease.unit.asset.owners', fn => where('user_id', auth()->id()))` | List, View | Single status filter. Bulk Download PDFs (zip). Read-only. |
| [MaintenanceRequestResource](../../app/Filament/Owner/Resources/MaintenanceRequests/MaintenanceRequestResource.php) | 80 | `whereHas('unit.asset.owners', fn => where('user_id', auth()->id()))` | List, View | Status + priority filters. Read-only. No comment relation manager (owner doesn't comment). |

### 1.3 PortfolioStats widget

[PortfolioStats.php](../../app/Filament/Owner/Widgets/PortfolioStats.php) (71 LOC). 4 stat cards:

| Stat | Source |
|---|---|
| Assets count + leasable area | `auth->user->ownedAssets()->count()` + sum of `leasable_area_sqm` |
| Occupancy % | iterate owned assets → sum occupied/total units; colour green ≥ 70 % / yellow ≥ 50 % / red < 50 % |
| MRR | `Lease::active()->whereIn('unit.asset_id', ownedIds)->sum('base_rent_monthly + service_charge_monthly')` |
| Outstanding AR | `Invoice::whereIn('status', ['issued','partially_paid','overdue'])->whereHas('lease.unit.asset_id IN ownedIds')->sum('balance')` |

Returns empty when owner has zero assets — no NPE.

### 1.4 Scoping pivot

[asset_owner migration](../../database/migrations/2026_05_23_170119_create_asset_owner_table.php): columns `user_id`, `asset_id`, `ownership_percentage` (decimal), `started_at` date, `ended_at` nullable date, timestamps.

- `User::ownedAssets()` BelongsToMany via pivot.
- `Asset::owners()` BelongsToMany via pivot.

Note: pivot has `ownership_percentage` but no Owner Resource displays it. Acceptable since v1 doesn't yet split revenue across co-owners.

### 1.5 Role gating

[RolesPermissionsSeeder](../../database/seeders/RolesPermissionsSeeder.php) (lines 201-205):

```php
Role::findByName('owner', 'web')->syncPermissions([
    'assets.view', 'units.view', 'leases.view', 'invoices.view',
    'maintenance.view', 'reports.view', 'reports.download',
]);
```

`User::canAccessPanel`:
```php
'owner' => $this->hasRole('owner'),
'admin' => $this->roles()->where('name', '!=', 'owner')->exists(),
```

Owner role cannot access `/admin`; non-owner roles cannot access `/owner`. Verified by e2e test 12-owner-portal.spec.js:38 "Owner cannot reach the admin panel".

### 1.6 Seeded demo data

`owner@jawad.test` / `password`. 100 % ownership of Haya Walk since 2020-01-01. Plaza Annex is NOT owned by this user.

### 1.7 Tests

| File | Cases |
|---|---|
| [Feature/Owner/OwnerStatementPdfTest.php](../../tests/Feature/Owner/OwnerStatementPdfTest.php) | View Property page renders statement action; action streams real PDF (validates %PDF- magic bytes, > 2000 bytes); 404s for non-owner. (Recent commit `7a10690`.) |
| [tests/e2e/12-owner-portal.spec.js](../../tests/e2e/12-owner-portal.spec.js) | 5 cases: dashboard loads with PortfolioStats, Properties resource lists Haya Walk, Invoices scoped, Maintenance scoped, admin panel blocked. |

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md | "Owner Portal (3rd Filament panel, read-only): New panel at `/owner` with own login, dynamic brand resolved from the owner's owned-asset operators." | ✅ |
| FEATURES.md | "Asset has a `belongsToMany` to User via `asset_owner` pivot (`ownership_percentage`, `started_at`, `ended_at`) — drives the Owner Portal." | ✅ |
| FEATURES.md | "PortfolioStats widget — 4 stat cards: Properties count + leasable area, portfolio occupancy %, MRR, outstanding AR — aggregated across the user's owned assets." | ✅ |
| FEATURES.md | "Bulk Download PDFs (zip) — Invoices toolbar action: select rows, get a single zip of all selected invoice PDFs. Available on admin + Owner Portal." | ✅ on Owner InvoiceResource |
| FEATURES.md | "Panel-level gating in `User::canAccessPanel()` — admin panel requires a non-owner role; owner panel requires `owner` role." | ✅ |
| MASTER-PLAN.md | "Owner Portal — new Filament panel at `/owner` with role gating, PortfolioStats widget, read-only Properties / Invoices / Maintenance resources scoped to owned assets." | ✅ |
| DEMO-ELTIZAM.md | `Owner | /owner | owner@jawad.test | password` | ✅ |

## 3. Findings

### 🟢 F-17 does not apply

None of the 3 Owner Resources override `getNavigationBadge()`. No carryover fix needed.

### 🟢 PII is contained

Owner Invoices table shows `tenant.name` only; tax_id / national_id never surface on owner-side views. Owner role permissions don't include `tenants.view` so tenant details aren't reachable even by direct URL.

### 🟢 Scoping is robust

Every Resource's `getEloquentQuery()` independently filters by the owner's pivot. There's no shared trait — copy-paste, but each filter is short and grep-findable. If a new resource is added later, the owner panel author must add the filter explicitly (no implicit leak risk because Filament's default discoverResources requires a matching panel — but worth noting).

### 🟡 F-40. Owner Resources have no nav badges

Owner Portal navigation shows only labels and icons. Useful potential badges:
- **Properties**: number of assets owned (vanity, but on point for multi-asset owners).
- **Invoices**: overdue count (would map to AR Aging warning the same way the admin badge does).
- **Maintenance**: open count.

All three would use `static::getEloquentQuery()->...->count()` patterns (same fix-template applied for F-17 carryover). Owner badges are particularly useful when an owner has multiple assets and wants to see at-a-glance "X invoices overdue across my portfolio".

Defer D-31 — UX feature, not a bug.

### 🟡 F-41. Owner role has `'cam.view'` permission but no CAM resource

[RolesPermissionsSeeder line 203](../../database/seeders/RolesPermissionsSeeder.php) grants owner `'cam.view'` (it's listed in MASTER-PLAN's permission table) but `app/Filament/Owner/Resources/` has no `Cam*` resource. The permission is dormant; owners can't see CAM data. Matches [Module 07 F-29](07-cam.md) which proposed adding a portal-side CAM allocations view; same scope decision applies to the owner side.

Either:
- **A**: Add an Owner-side CAM allocations view (read-only, scoped to owned assets). Useful for owners checking their share of operating-cost reconciliation.
- **B**: Remove `'cam.view'` from the owner role to match the actual surface.

Defer D-32 — bundle with D-22 (portal CAM view).

### 🟢 No multi-asset switcher needed

Owners with multiple assets see one aggregate list per resource. PortfolioStats aggregates across all owned assets. This is correct because the ownership pivot already defines the exact asset set — no "All Properties" pseudo-tenant equivalent needed.

### 🟢 Statement PDF action verified end-to-end

`AssetStatementPdfService::build()` produces 12-month-trailing statement with invoice + payment rollups + delinquent tenant top-10. Test `OwnerStatementPdfTest` decodes the PDF bytes and verifies `%PDF-` magic + size > 2 KB. Recent commit `7a10690` added the HTTP path coverage.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Owner|Portfolio'` | **11 passed / 0 failed** | 1.27 s |
| `npx playwright test tests/e2e/12-owner-portal.spec.js` | **5 passed / 0 failed** | 5.7 s |
| Full Pest (post-D-12 + seeder determinism) | **295 passed / 0 failed** | 4.4 s |

## 5. No inline fixes this module

Both Yellow findings (F-40 nav badges, F-41 CAM permission alignment) are scope/feature decisions, not bugs.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-31 | F-40: add nav badges to Owner Resources (overdue invoices count, open maintenance count) | Apply — small change, real value for multi-asset owners |
| D-32 | F-41: add Owner CAM view OR remove unused permission | Bundle with D-22 (Portal CAM view) at the CAM feature pass |

## 7. Verdict

**🟢 Green.** The Owner Portal is one of the cleanest modules — a focused, read-only third panel with strong tests, strong PII containment, and the right architectural call (no Filament tenancy, scope per-resource via pivot). The two Yellow findings are extensions, not bugs.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢.

## Next

Module 11 — Tenant Portal. Surface: [PortalPanelProvider](../../app/Providers/Filament/PortalPanelProvider.php) (already inventoried in Module 02), [Portal Resources](../../app/Filament/Portal/Resources/) (Invoices, Payments, MaintenanceRequests, TenantSalesDeclarations), [TenantStatementPdfService](../../app/Services/TenantStatementPdfService.php), portal widgets (AccountBalance, OpenMaintenance), and the tenant's view of their own data end-to-end.
