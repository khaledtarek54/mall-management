---
name: new-module
description: Scaffold a new module (model + migration, single-action service, Filament resource with RBAC + property scoping, permissions, module doc, tests) following Atriom conventions. Use when adding a new domain area / resource to the mall-management ERP.
argument-hint: <ModuleName> (singular, e.g. "Insurance Policy")
---

# Scaffold a new Atriom module

Goal: add a new module so it's consistent with the rest of the system — RBAC-gated, property-scoped, documented, tested. **Read [docs/OVERVIEW.md](../../../docs/OVERVIEW.md) and a similar existing module's [docs/modules/NN-*.md](../../../docs/modules/) first** (pick the closest analogue — e.g. Vendors for a simple CRUD, Leases for something with a lifecycle).

Work in this order; keep each step green before the next.

## 1. Data layer
- **Migration** (`database/migrations/`): real column types + constraints. Every **NOT-NULL column** needs either a DB default OR model-level coercion — never let a blank form field send `null` into a NOT-NULL column (this bit us on `meter_readings.cost`, `leases.has_percentage_rent`). Add a `unique` index for any natural key (scope it per-asset where relevant, e.g. `unique(['asset_id','code'])`). Soft deletes (`softDeletes()`) if records are user-managed. FK to `assets` if the record belongs to a property.
- **Model** (`app/Models/`): `$fillable`, `$casts`, and `$attributes` defaults for NOT-NULL booleans/numerics. Relationships. If financial, route money through `Invoice::recomputeTotals()` — never set `paid_amount`/`balance` directly. Use the `LogsActivity` trait if the module should appear in the activity log (and add the i18n subject key in `lang/{en,ar}/admin.php`).

## 2. Business logic → single-action services
Put logic in `app/Services/<Verb><Noun>Service.php` (one public action). Keep controllers + Filament pages thin. Wrap multi-write operations in `DB::transaction`; for anything idempotent/scheduled, use `lockForUpdate()->find()` + re-check the stamp **inside** the transaction.

## 3. Filament resource (`app/Filament/Admin/Resources/<Plural>/`)
- Use the **`RoleGatedActions`** trait → gates `canViewAny/canCreate/canEdit/canDelete` on `{module}.{action}` permissions. **Delete stays super_admin-only**; leave **bulk-delete off** (only set `$bulkDeletable = true` deliberately).
- **Property scoping:** the table auto-scopes; for any cross-property **select** (tenant/asset/unit/department) use `App\Support\TenantScope::selectable*` helpers or `modifyQueryUsing` constrained to `TenantScope::visibleAssetIds()` (mirror `PaymentForm`). Never offer cross-property options to a restricted user.
- **`getNavigationGroup()`** → the owning department (Leasing / Operations / Accounting / Marketing / HR / Settings), matching `AdminPanelProvider`.
- **Form validation is part of the field** — mirror the audited conventions: `->required()` for NOT-NULL, `->numeric()->minValue(0)` for money/quantity, `->maxLength(n)` for bounded strings, `->unique(ignoreRecord:true[, modifyRuleUsing for compound])`, `->after('other_date')` for ordered dates. A `Select` with `->options()` already validates server-side — don't add `->in()`.

## 4. Permissions (`database/seeders/RolesPermissionsSeeder.php`)
Add `{module}.{view,create,edit,delete}` and grant to the right roles (owner gets `.view` only; manager gets create+edit; super_admin everything; the owning department role gets its module). Re-run `php artisan migrate:fresh --seed`.

## 5. Documentation (same commit as the code)
Create `docs/modules/NN-<kebab-name>.md` using the standard 10-section template (Purpose · Domain model · Business rules & invariants · Lifecycle/state-machine · Services & commands · Filament fields & validation · Notifications/integrations · **Extension points** · Gotchas · Tests). Add it to the table in `docs/OVERVIEW.md` and the README index.

## 6. Tests (`vendor/bin/pest --parallel` must stay green)
- A `tests/Feature/Scenarios/<Module>ScenarioTest.php` covering happy / negative / boundary / RBAC / scoping / state-transition cases (use the global helpers `makeAsset/makeUnit/makeTenant/makeLease/makeUser`; `seed(RolesPermissionsSeeder::class)` for permission assertions).
- Field-validation tests under `tests/Feature/Regression/Validation/` for the form rules (reject bad + accept good).
- Render Filament tables **with rows** in a test (an empty table hides `$state`-closure bugs).

## Definition of done
`pest --parallel` green · module doc written + linked · permissions seeded · committed to `main` with the `Co-Authored-By:` trailer.
