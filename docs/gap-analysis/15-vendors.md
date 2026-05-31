# Module 15 — Vendors

> Date: 2026-05-31
> Status: 🟡 Yellow — clean operational module; 1 inline fix (final F-17 carryover, the 6th and last); 3 Yellow extensibility (no auto-expire job, no tax_id format validation, no model tests).
> Surface: [Vendor model](../../app/Models/Vendor.php), [VendorContact](../../app/Models/VendorContact.php), [VendorContract](../../app/Models/VendorContract.php), [Admin Vendors resource](../../app/Filament/Admin/Resources/Vendors/).

## 1. Inventory

### 1.1 Models

| Model | LOC | Notes |
|---|---:|---|
| [Vendor.php](../../app/Models/Vendor.php) | 84 | Traits `LogsActivity` + `SoftDeletes`. Fillable: 12 fields (`name`, `slug`, `type`, `status`, `legal_name`, `tax_id`, `email`, `phone`, `address`, `city`, `notes`, `metadata`). Cast `metadata` → array. Type enum 5 values (`contractor, supplier, service_provider, consultant, other`). Status enum 3 (`active, inactive, blacklisted`). Relations: `contacts()`, `contracts()`, `maintenanceRequests()` (reverse via `assigned_to_vendor_id`), `primaryContact()` helper. Boot: auto-slug from name. LogsActivity allowlist 6 fields. |
| [VendorContact.php](../../app/Models/VendorContact.php) | 28 | Fillable 6 fields incl. `is_primary` bool. Relation `vendor()` BelongsTo. |
| [VendorContract.php](../../app/Models/VendorContract.php) | 53 | Traits `LogsActivity` + `SoftDeletes`. Fillable 11 fields. Date casts on `start_date`/`end_date`; decimal:2 on `value`. Status enum 4 values (`draft, active, expired, terminated`). Relations: `vendor()`, `asset()` (nullable for cross-property contracts). LogsActivity allowlist 5 fields. |

### 1.2 Migration — [2026_05_24_132917_create_vendors_table.php](../../database/migrations/2026_05_24_132917_create_vendors_table.php)

Single 165-LOC migration creates **3 tables** + **modifies** `maintenance_requests`:

- **vendors**: 12 cols + softDeletes. Unique `slug`. Indexes `(type, status)`, `tax_id`.
- **vendor_contacts**: 7 cols. FK `vendor_id` cascade. Index `vendor_id`.
- **vendor_contracts**: 11 cols + softDeletes. FK `vendor_id` cascade, `asset_id` nullOnDelete. Default currency EGP. Indexes `(vendor_id, status)`, `asset_id`.
- **maintenance_requests modification**: adds `assigned_to_vendor_id` (FK→vendors, nullOnDelete) after `assigned_to`. Comment in migration explains the dual-assignment pattern.

### 1.3 Admin Resource

| File | Notes |
|---|---|
| [VendorResource.php](../../app/Filament/Admin/Resources/Vendors/VendorResource.php) (137 LOC) | `RoleGatedActions` trait. Nav: `OutlinedBuildingOffice2`, sort 6, group Operations. **`$isScopedToTenant = false`** — vendors are global cross-property entities. `getEloquentQuery()` adds `withCount(['contracts as active_contracts_count' ...]` for the table column. Globally searchable: 5 attrs. **Navigation badge: count of vendor contracts expiring in 30 days — fixed inline this module (F-17 final carryover)**. Tooltip via `admin.tooltips.vendor_contracts_expiring`. |
| Schemas/VendorForm (70 LOC) | 2 sections: Vendor Details (2-col, 9 fields), Notes (collapsible). |
| Tables/VendorsTable (79 LOC) | 6 cols incl. `active_contracts_count` badge. Filters: type, status, trashed. Standard CRUD. |
| RelationManagers/ContactsRelationManager (57 LOC) | Form: name + role + email + phone + is_primary toggle. Table sorts primary-first. |
| RelationManagers/ContractsRelationManager (100 LOC) | Form: reference + name + status + asset_id (TenantScope preset) + dates + value (EGP prefix) + scope/notes. Filter by status. |
| Pages/{List, Create, Edit} | Standard pages. |
| `getRelations()` adds `ActivitiesRelationManager` (shared) | |

### 1.4 Cross-refs

- **MaintenanceRequest.assigned_to_vendor_id**: FK established. MaintenanceRequest form has `assigned_to_vendor_id` select filtered to active vendors. Maintenance table has an External Vendor toggleable column. Activity log captures vendor assignment.
- **No Owner / Portal exposure**: vendors are admin-only.

### 1.5 Seeded data

8 vendors with names mapped to maintenance categories: Cool-Air HVAC, BrightSpark Electrical, PureWater Plumbing, CleanFleet Janitorial, SecureGuard Security, GreenLeaf Landscaping, PestStop, FireSafe Consultants. Each has a primary contact; most have annual service contracts.

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md | "Vendor + VendorContact + VendorContract models. Vendor types: contractor · supplier · service_provider · consultant · other. Status: active · inactive · blacklisted." | ✅ exact match |
| FEATURES.md | "Filament resource at /admin/vendors (Operations nav) — type/status badges + filters, active-contracts count column, deep search across name/legal_name/tax_id/email/phone." | ✅ |
| FEATURES.md | "Two relation managers on the vendor edit page: Contacts (`is_primary` toggle, default-sort primary first) and Contracts (asset linkage, value, currency, scope, status enum)." | ✅ |
| FEATURES.md | "Wired into MaintenanceRequest via `assigned_to_vendor_id` FK + `assignedVendor()` relation." | ✅ |
| DEMO.md | "Vendor Management — /admin/vendors with contacts + contracts; maintenance requests route to vendors via the External Vendor select." | ✅ |

## 3. Findings

### 🔴 F-17 (Fixed inline, Vendors — final carryover, the 6th) — nav badge bypassed tenant scope

The Vendor nav badge is **interesting** because it queries `VendorContract`, not `Vendor`. The other 5 carryovers all queried the resource's own model:

Before:
```php
$count = VendorContract::query()->where('status','active')
    ->whereDate('end_date', '<=', now()->addDays(30))
    ->whereDate('end_date', '>=', now())
    ->count();
```

Vendors themselves are global (`$isScopedToTenant = false`) — that's by design (one HVAC contractor serves multiple properties). But contracts carry `asset_id` (nullable), so operators in a specific property should see only that property's expiring contracts.

After:
```php
$query = VendorContract::query()->where('status','active')
    ->whereNotNull('end_date')
    ->whereDate('end_date', '<=', now()->addDays(30))
    ->whereDate('end_date', '>=', now());

if ($assetId = TenantScope::currentAssetId()) {
    $query->where('asset_id', $assetId);
}

$count = $query->count();
```

In Haya Walk view → only Haya Walk's expiring contracts. In ALL → portfolio-wide count.

**Note** this uses `TenantScope::currentAssetId()` directly rather than `static::getEloquentQuery()` because the badge queries a different model (`VendorContract`) than the resource (`Vendor`). The other 5 fixes all used `static::getEloquentQuery()` and worked because the badge queried the same model as the resource.

**Cross-cutting F-17 progress — COMPLETE: all 6 done.**
- ✅ Units (M03) · ✅ Invoices (M05) · ✅ Maintenance (M09) · ✅ TenantSales (M12) · ✅ CreditNotes (M14) · ✅ Vendors (this).

### 🟡 F-58. No scheduled auto-expire for vendor contracts

The migration enum includes `expired` and `terminated` as terminal states, but no scheduled command/job auto-transitions `active` contracts past their `end_date` to `expired`. The nav badge alerts to "expiring in 30 days" but if an operator ignores it, the contract stays `active` forever.

**Fix scope:** add `vendors:expire-contracts` console command + `Schedule::command('vendors:expire-contracts')->daily()` in `routes/console.php`. Single short command that does `VendorContract::where('status', 'active')->whereDate('end_date', '<', today())->update(['status' => 'expired'])`. ~30 LOC.

**D-43** deferred — bundle with the Maintenance auto-close work (Module 09 D-30).

### 🟡 F-59. No `tax_id` format validation

`Vendor.tax_id` is `varchar(50)` with no regex enforcement. Egyptian tax IDs follow a specific format (typically `XXX-XXX-XXX` 9 digits + dashes). An operator could enter "12345" or "TBD" without complaint, and that vendor would later fail ETA submission attempts.

**Fix scope:** add a form rule on `VendorForm.tax_id`. Same validation regex would apply to Tenant.tax_id (currently also unvalidated — re-check Module 02). ~5 LOC per resource.

**D-44** deferred — bundle as one "tax_id format validation" cleanup.

### 🟡 F-60. No dedicated VendorTest

The vendor module ships without a `tests/Feature/Models/VendorTest.php`. E2E covers UI (create vendor end-to-end, relation managers load), but model methods (`activeContractsCount`, `primaryContact`, slug uniqueness, status transition logic) aren't unit-tested.

**D-45** deferred — bundle with the post-sweep test-writing pass.

### 🟢 Vendor relationship to MaintenanceRequest is clean

`assigned_to_vendor_id` is an additional FK alongside the internal `assigned_to` (User) — a request can have both. Activity log captures both fields. Form select filters to active vendors only. Clean dual-assignment model.

### 🟢 Vendor contracts can be cross-property

`vendor_contracts.asset_id` is nullable. A national security contract can apply across the portfolio with `asset_id = NULL` and still appear in every property's nav badge (because the `TenantScope` filter is conditional — only applied when `currentAssetId()` is not null). Worth confirming this is the intent, but matches the data model design.

### 🟢 LogsActivity coverage is right-sized

Vendor: 6 fields. VendorContract: 5 fields. Doesn't log every cosmetic edit (e.g. notes, slug regen) — only the operationally-significant ones.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Vendor'` | **5 passed / 0 failed** | 0.95 s |
| `npx playwright test tests/e2e/16-credit-notes-vendors.spec.js` | **6 passed / 0 failed** (1 flake on slow run, passed on retry) | 10.7 s |
| Full Pest post-F-17 fix | **295 passed / 0 failed** | 4.56 s |

## 5. Inline fix this module

**F-17 (Vendors final carryover)**: 20 LOC. Cross-cutting F-17 work now **complete**.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-43 | F-58: add `vendors:expire-contracts` daily command + schedule | Apply — small lift, real operator value |
| D-44 | F-59: add tax_id format validation (Vendor + Tenant) | Apply — small, surfaces data quality issues pre-ETA submission |
| D-45 | F-60: dedicated VendorTest | Bundle with post-sweep test pass |

## 7. Verdict

**🟡 Yellow.** Vendors is a clean operational module — global vendor identity + per-property contracts + dual-assignment to maintenance. The F-17 carryover was a real per-property bug. Closing the cross-cutting F-17 work brings every Filament admin resource into consistent tenancy behavior.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡.

## Next

Module 16 — Assets / Tenancy. Surface: [Asset model](../../app/Models/Asset.php), [Operator](../../app/Models/Operator.php), the `ALL_PROPERTIES_CODE` pseudo-asset, [TenantScope](../../app/Support/TenantScope.php), the 3 scoping traits (`ScopesViaProperty` / `BypassesScopingOnAll` / `BypassesFilamentTenantAutoScope`), and the property-switcher UX.
