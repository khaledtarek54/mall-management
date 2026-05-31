# Module 12 — Tenant Sales Declarations + Percentage Rent

> Date: 2026-05-31
> Status: 🟡 Yellow — Egyptian retail wedge #3, well-modeled and tested; 1 inline fix (F-17 carryover); 4 Yellow extensibility / edge-case findings.
> Surface: [TenantSalesDeclaration model](../../app/Models/TenantSalesDeclaration.php), [Admin TenantSalesDeclarations resource](../../app/Filament/Admin/Resources/TenantSalesDeclarations/), [PercentageRentCalculationService](../../app/Services/PercentageRentCalculationService.php), Portal resource (already inventoried at Module 11).

## 1. Inventory

### 1.1 Model — [TenantSalesDeclaration.php](../../app/Models/TenantSalesDeclaration.php) (81 LOC)

- Traits: `HasFactory`, `LogsActivity`, `SoftDeletes`.
- Fillable: `lease_id, period_start, period_end, declared_sales, calculated_percentage_rent, declared_at, declared_by_type, declared_by_id, status, locked_at, locked_by_user_id, audit_notes`.
- Date casts on 4 cols; decimal:2 on `declared_sales` + `calculated_percentage_rent`.
- Relations: `lease()` BelongsTo, **`declaredBy()` MorphTo** (User OR Tenant — clean polymorphism mirroring `MaintenanceRequestComment::author`), `lockedByUser()` BelongsTo nullable.
- Status enum (**3**): `submitted, locked, disputed`. **No `cancelled` state** — see F-50.
- Helpers: `isLocked()`, `isDisputed()`, `periodLabel()` (ISO month-year).
- LogsActivity on 5 cols (`status, declared_sales, calculated_percentage_rent, locked_at, audit_notes`), log name `tenant_sales`.

### 1.2 Migration — [2026_05_23_160330_create_tenant_sales_declarations_table.php](../../database/migrations/2026_05_23_160330_create_tenant_sales_declarations_table.php)

- 12 columns + softDeletes + timestamps.
- FKs: `lease_id` cascadeOnDelete, `locked_by_user_id` nullOnDelete.
- Polymorphic `declared_by_type` + `declared_by_id` (nullable).
- **Unique constraint** `(lease_id, period_start)` named `tenant_sales_lease_period_unique` — one declaration per lease per month (re-submission requires hard/soft delete of the existing row — see F-51).
- Index `(status, period_start)` for the nav-badge count.

### 1.3 Service — [PercentageRentCalculationService.php](../../app/Services/PercentageRentCalculationService.php) (102 LOC)

| Method | Behavior |
|---|---|
| `calculate(TenantSalesDeclaration): float` | Returns 0 if `lease.has_percentage_rent` is false. **Artificial mode**: `max(0, (sales − threshold) × rate%)`. **Natural breakpoint mode**: `max(0, sales × rate% − base_rent_monthly)`. Rounds to 2 decimals; floored at zero. |
| `recalculate(TenantSalesDeclaration): TenantSalesDeclaration` | Recomputes `calculated_percentage_rent` without changing status — called on form save (admin + portal) so the preview number is always fresh. Does not lock. |
| `lock(TenantSalesDeclaration, User, ?string $notes = null): TenantSalesDeclaration` | **Idempotent** — no-op if already locked. DB transaction: recalculate amount, set status=`locked`, stamp `locked_at` + `locked_by_user_id` + `audit_notes`. **Side effect** if `$owed > 0`: creates a one-off `Charge` via `createPercentageRentCharge()`. |
| `createPercentageRentCharge` (private) | Charge with `type='percentage_rent'`, `frequency='one_time'`, `name="Percentage Rent — {period label}"`, `vat_applicable=false`, `start/end = period bounds`, `is_active=true`. **Not bound to a specific invoice** — `MonthlyBillingService` picks it up at the next monthly run. |

### 1.4 Admin Resource

| File | LOC | Notes |
|---|---:|---|
| [TenantSalesDeclarationResource.php](../../app/Filament/Admin/Resources/TenantSalesDeclarations/TenantSalesDeclarationResource.php) | 93 | `RoleGatedActions` + `ScopesViaProperty` (`tenantScopeRelation()` = `lease.unit`). Nav: `OutlinedPresentationChartLine` icon, sort 6, group "Operations". **Nav badge: count of submitted (warning color) — fixed inline this module (F-17 carryover)**. |
| Form (TenantSalesDeclarationForm) | — | 3-col layout: Lease select + dates + declared_sales + calculated_percentage_rent (read-only) + status + audit_notes textarea. |
| Tables/TenantSalesDeclarationsTable (130 LOC) | — | 8 cols (tenant + unit + period + sales + percentage rent + status + dates). Status filter only. Default sort `period_start DESC`. |
| Pages/{List, Create, Edit, View} | — | CreateTenantSalesDeclaration mutates form data to stamp `declared_at`, `declared_by_type=User`, `declared_by_id=auth-id`, then recalculates. EditTenantSalesDeclaration's `afterSave` recalculates only if status ≠ `locked`. |

### 1.5 Record actions

**Lock**: visible only when `status === 'submitted'`. Modal collects `audit_notes`. Calls `PercentageRentCalculationService::lock()`. Notification shows the EGP amount of the percentage-rent charge created. Idempotent.

**Dispute**: visible only when `status === 'submitted'`. Modal collects `audit_notes` (required). Updates `status='disputed'`. **Cannot dispute a locked declaration via UI** — see F-48 for the architectural gap.

**Edit + standard CRUD**.

### 1.6 Portal flow (already covered at Module 11)

Tenant submits via `Portal/Resources/TenantSalesDeclarations/`. Form filters lease_id to leases where `has_percentage_rent=true`. `declared_by = Tenant`, status='submitted'. Tenant cannot edit after submission.

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md L213-22 | "TenantSalesDeclaration model — `lease_id`, `period_start`/`period_end`, `declared_sales`, `calculated_percentage_rent`, polymorphic `declared_by` (User or Tenant), status enum (`submitted` / `locked` / `disputed`)" | ✅ |
| FEATURES.md L215 | "Artificial: `max(0, (sales - threshold) × rate%)` / Natural breakpoint: `max(0, sales × rate% - base_rent_monthly)`" | ✅ both formulas tested |
| FEATURES.md L217 | "Admin review queue (Operations nav) — filter by status / period, per-row Lock + Dispute actions. Lock auto-creates a one-off `percentage_rent` Charge on the lease for the next monthly billing run." | ✅ |
| FEATURES.md L218 | "Tenant portal submission form — gated to leases with `has_percentage_rent = true`; minimal numeric form, last-month default period, status visibility after submission." | ✅ |
| FEATURES.md L222 | "Nav badge on admin shows pending submission count." | ✅ now per-property after F-17 fix |
| FEATURES.md L112 (seeded data) | "72 tenant sales declarations across 3 historic months × 24 F&B/retail leases with mixed statuses (43 locked → 43 percentage-rent charges auto-generated)" | ✅ |

## 3. Findings

### 🔴 F-17 (Fixed inline, TenantSales carryover) — nav badge bypassed tenant scope

Before:
```php
$count = TenantSalesDeclaration::where('status', 'submitted')->count();
```

After:
```php
$count = static::getEloquentQuery()->where('status', 'submitted')->count();
```

`ScopesViaProperty` applies the `lease.unit.asset_id` filter automatically; the ALL pseudo-asset bypasses. Operators in Haya Walk now see the per-property pending count. Pest 287/287 → 295/295, e2e 5/5 on tenant-sales.spec.js after fix.

**Cross-cutting progress**: ✅ Units (M03) · ✅ Invoices (M05) · ✅ Maintenance (M09) · ✅ TenantSales (this) · ⏳ Vendors (M15).

### 🟡 F-48. No mechanism to dispute or void a locked declaration

The Dispute action is gated to `status === 'submitted'` — so once an admin locks a declaration (creating a `percentage_rent` Charge), they have no UI path to:

- mark it `disputed` later
- void or deactivate the auto-created Charge

Practical case: admin locks May's declaration on June 3rd. June 10th the tenant flags an error in May's sales. The Charge is already active and will appear in June's invoice. Admin has to: open the Charge directly (no UI from this resource), manually `is_active=false`, then issue a credit note if it already invoiced. Three-step manual flow with no audit trail back to the declaration.

**Fix scope (deferred D-36):** add a `void` record action visible when `status === 'locked'`:
- transitions status → `disputed` (or a new `voided` state — F-50 ties in)
- deactivates the linked `percentage_rent` Charge
- records `audit_notes` explaining the void

Estimated 30-40 LOC across the action + a service method. Defer for product decision on terminology (`disputed` vs `voided`).

### 🟡 F-49. Declared sales values stored plaintext

`declared_sales` is `decimal(14,2)` unencrypted in MySQL. For an audit-sensitive number, Egypt's recent data-protection law (DPL 2020-151) may consider this "commercial data" requiring at-rest protection in certain hosting scenarios. Cloud deployments with disk encryption typically satisfy this, but if hosting requires application-layer encryption, this column would need a mutator+cast.

**Fix scope:** out-of-scope until pilot legal review. **Defer D-37**.

### 🟡 F-50. Status enum has no `cancelled` or `voided` state

Three states (`submitted`, `locked`, `disputed`) cover the happy path. Missing transitions:

- **Cancelled**: declaration was submitted in error (e.g. wrong period) and should be reversible by the tenant — currently they have to call an operator and ask for soft-delete.
- **Voided / re-opened**: an admin needs to "un-lock" a locked declaration after finding an error.

Bundle with F-48 (D-36).

### 🟡 F-51. No re-submission workflow

Unique constraint `(lease_id, period_start)` prevents re-submitting for the same month. Today the workflow is: admin soft-deletes the bad declaration, tenant resubmits, admin reviews. Fine but undocumented. A "Reject & request re-submission" admin action would close the loop.

Bundle with F-48/F-50 (D-36).

### 🟢 Polymorphic `declared_by` is clean

User OR Tenant via MorphTo — same pattern as `MaintenanceRequestComment::author`. Activity log captures everything cleanly.

### 🟢 Calculation formulas are well-tested

Both `artificial` and `natural_breakpoint` modes have explicit test cases in `PercentageRentCalculationServiceTest`. The "no negative rents" floor is verified, as is the `has_percentage_rent=false` short-circuit.

### 🟢 Lock idempotency is real

Re-running lock on an already-locked declaration is a no-op (no double-Charge creation). Tested.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Sales|PercentageRent|TenantSales'` | **9 passed / 0 failed** | 1.01 s |
| `npx playwright test tests/e2e/10-tenant-sales.spec.js` | **5 passed / 0 failed** | 7.1 s |
| Full Pest post-F-17 fix | **295 passed / 0 failed** | 4.29 s |

## 5. Inline fix this module

**F-17 (TenantSales carryover)**: 7 LOC. Cross-cutting progress now 4/5; only Vendors (M15) remaining.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-36 | F-48/F-50/F-51 — add a "void/re-open" record action on locked declarations + `cancelled` enum + re-submission flow | Apply post-pilot; bundle as one product feature |
| D-37 | F-49: at-rest encryption for `declared_sales` | Out-of-scope until legal review |

## 7. Verdict

**🟡 Yellow.** The Sales Declaration + Percentage Rent module is a complete v1 implementation of the wedge feature. The data model, both calculation formulas, idempotent lock action, and the lock → Charge flow all work and are tested. The F-17 carryover was a real per-property bug; fixed inline. The remaining Yellow findings are edge cases and product extensions, not defects in what's shipped.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡.

## Next

Module 13 — Utility Meters & Energy. Surface: [UtilityMeter model](../../app/Models/UtilityMeter.php), [MeterReading model](../../app/Models/MeterReading.php), [Admin UtilityMeters resource](../../app/Filament/Admin/Resources/UtilityMeters/), [EnergyConsumptionTrend widget](../../app/Filament/Admin/Widgets/EnergyConsumptionTrend.php).
