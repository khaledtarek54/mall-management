# Module 17 — Users & Roles

> Date: 2026-05-31
> Status: 🟡 Yellow — RBAC is well-architected and tested; 5 Yellow extensibility (default password, no self-service, no MFA, free-form `asset_user.role`, no User LogsActivity).
> Surface: [User model](../../app/Models/User.php), [Admin Users resource](../../app/Filament/Admin/Resources/Users/), [Admin Roles resource](../../app/Filament/Admin/Resources/Roles/), Spatie Permission, [RolesPermissionsSeeder](../../database/seeders/RolesPermissionsSeeder.php).

## 1. Inventory

### 1.1 User model — [User.php](../../app/Models/User.php) (119 LOC)

- Extends `Authenticatable`. Implements `FilamentUser` + `HasTenants`.
- Traits: `HasFactory`, `HasRoles` (Spatie), `Notifiable`. **No `LogsActivity`** — see F-67.
- Fillable: `name, email, password`. Casts: `email_verified_at`→datetime, `password`→hashed. Hidden: `password, remember_token`.
- Relations:
  - `ownedAssets()` BelongsToMany via `asset_owner` pivot (`ownership_percentage, started_at, ended_at`).
  - `assignedAssets()` BelongsToMany via `asset_user` pivot (`role, assigned_at, ended_at, notes`).
  - `assignedMaintenanceRequests()` HasMany.
- `canAccessPanel(Panel)`: `'owner' → hasRole('owner')`, `'admin' → roles()->where('name','!=','owner')->exists()`. Owner panel and admin panel are mutually exclusive.
- `getTenants(Panel)`: super_admin sees all real assets (ALL excluded from dropdown), restricted users see their `assignedAssets()` + ALL prepended when count > 1.
- `canAccessTenant(Model)`: rejects soft-deleted assets and non-Asset models; super_admin always passes; non-admins pass iff assigned or accessing ALL with 2+ properties.

### 1.2 Migrations

| File | Effect |
|---|---|
| 0001_01_01_000000_create_users_table.php | Standard Laravel users + password_reset_tokens + sessions. |
| [2026_05_12_122355_create_permission_tables.php](../../database/migrations/2026_05_12_122355_create_permission_tables.php) | Spatie schema (5 tables). Teams disabled. |
| 2026_05_25_192822_create_asset_user_table.php | Staff↔Asset pivot with free-form `role` string (see F-66). |

### 1.3 Spatie Permission setup

[config/permission.php](../../config/permission.php): cache TTL 24h, wildcard perms disabled, events disabled, `register_permission_check_method` enabled. Teams disabled.

**6 built-in roles** (from [RolesPermissionsSeeder::ROLES](../../database/seeders/RolesPermissionsSeeder.php)):

| Role | Scope |
|---|---|
| `super_admin` | All 81 permissions |
| `manager` | Day-to-day ops: create/edit all modules, no delete, no settings/roles |
| `viewer` | All `.view` + `reports.download` |
| `owner` | Owner portal: `assets.view, units.view, leases.view, invoices.view, maintenance.view, reports.*` |
| `leasing` | Lease + tenant pipeline + invoicing; no payments/CAM/maintenance |
| `operations` | Maintenance triage + vendor dispatch: `maintenance.*, vendors.*, utility_meters.view, notes.*` |

**81 permissions** in `RolesPermissionsSeeder::PERMISSIONS` across 18 modules. Naming: `{module}.{action}` — `assets.view`, `invoices.run_monthly_billing`, `leases.renew`, `tenant_sales.lock`, `cam.bill_allocation`, etc. Clear, greppable, audit-friendly.

### 1.4 Admin Users resource

[UserResource.php](../../app/Filament/Admin/Resources/Users/UserResource.php) (104 LOC). `$isScopedToTenant = false`. Nav: `OutlinedUsers`, sort 1, group `settings`. **All CRUD hardcoded to super_admin** (`canCreate/canEdit/canDelete/canView` check `$user->hasRole('super_admin')`). `canDelete` also blocks self-deletion.

[UserForm.php](../../app/Filament/Admin/Resources/Users/Schemas/UserForm.php) (76 LOC): Account section (name + email + password — required on create, optional on edit, hashed via `dehydrateStateUsing`). Roles section (multi-select). **Properties section** — multi-select to `assignedAssets` excluding ALL pseudo-asset; **default on create = all real properties** (intentional — operators usually want full access by default, then deselect for restricted users).

[UsersTable.php](../../app/Filament/Admin/Resources/Users/Tables/UsersTable.php) (90 LOC): 4 cols (name, email, roles badge with colour-coded role names, created_at). Filters: role (multi-select), created date range. Standard edit + bulk delete.

### 1.5 Admin Roles resource

[RoleResource.php](../../app/Filament/Admin/Resources/Roles/RoleResource.php) (76 LOC). Spatie `Role` model. Uses `RoleGatedActions` trait (permission-based: `roles.{view|create|edit|delete}`). Nav: `OutlinedShieldCheck`, sort 90, group `settings`.

[RoleForm.php](../../app/Filament/Admin/Resources/Roles/Schemas/RoleForm.php) (68 LOC): role `name` (snake_case regex, ≤125 chars, unique; **disabled for built-in roles**), guard hidden (default `web`), then **one collapsible section per module** with `CheckboxList` of that module's permissions. Pages explicitly clear Spatie's permission cache after create/edit via `app(PermissionRegistrar::class)->forgetCachedPermissions()` — verified at [CreateRole:33](../../app/Filament/Admin/Resources/Roles/Pages/CreateRole.php#L33) and [EditRole:49](../../app/Filament/Admin/Resources/Roles/Pages/EditRole.php#L49).

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| DEMO.md | "Three roles: super admin, manager, viewer. The login they're using now is super admin..." | ✅ (in reality 6 roles seeded; demo emphasizes the 3 main ones) |
| DEMO.md | "Custom Roles + Permissions — `/admin/roles` lets admins create custom roles with any of 81 granular permissions." | ✅ 81 permissions, custom roles supported |
| DEMO.md | "Role-tailored dashboards — log in as `leasing@mall.test` or `operations@mall.test` to demo per-role widget sets." | ✅ widgets use `RoleScopedWidget` trait + `allowedRoles()` |
| FEATURES.md | "Spatie Permission (RBAC) + Spatie ActivityLog (audit trail) + Spatie MediaLibrary (document attachments)" | ✅ but User isn't itself ActivityLogged — see F-67 |

## 3. Findings

### 🟢 RBAC architecture is production-ready

- Clear naming (`{module}.{action}`).
- 6 roles cover real-world operator personas.
- Spatie cache TTL 24h with explicit `forgetCachedPermissions()` calls on role mutations — fresh permission state is guaranteed within the same request.
- Per-role widget visibility via `RoleScopedWidget` + `allowedRoles()` (verified in Module 01).
- Built-in role names locked from edit; custom roles fully supported.

### 🟡 F-63. Seeded users all use the literal password `'password'`

DEMO.md explicitly lists `admin@mall.test / password`, `manager@mall.test / password`, etc. The seeder uses `Hash::make('password')` for all 6 demo accounts. This is correct for demo + dev but **dangerous for any environment that goes live**, including a pilot deployment on a public URL.

**Fix scope (deferred D-48):** introduce a `DEMO_USER_PASSWORD` env var defaulting to `'password'`, plus a production-deploy hook that forces a rotation on first login. ~30 LOC.

### 🟡 F-64. No user self-service

There is no Filament page or API endpoint for a user to:
- Change their own password
- Update their own name / email
- Trigger a password-reset email

Today, password rotation requires a super_admin to edit the user via /admin/users. Laravel ships built-in password reset that just needs to be wired into the panel.

**Fix scope (deferred D-49):** add a Filament `EditProfile` page (or integrate Filament's `\Filament\Pages\Auth\EditProfile`); enable password reset via Filament's `->passwordReset()` panel method. ~50 LOC.

### 🟡 F-65. No 2FA / MFA

Filament doesn't ship MFA out of the box; Spatie has no TOTP plugin. Not a code defect — a maturity gap for production.

**Fix scope (deferred D-50):** integrate `laravel/fortify` or `pragma/laravel-2fa`. Significant work; out of scope for the audit but goes on the production checklist.

### 🟡 F-66. `asset_user.role` pivot is free-form string

The staff-assignment pivot lets you set `role='Property Manager'`, `'Site Engineer'`, `'Cashier'`, etc., distinct from Spatie's roles. No enum validation, no UI to manage these labels. The migration comment doesn't explain whether this is intentional flexibility or under-utilized scaffolding.

Today the column is barely used (the seeder sets `role='manager'` in RegisterProperty; no other writer). Worth deciding whether to:
- **A**: enum-validate it (small list: `manager, engineer, supervisor, cashier, security`)
- **B**: remove the column if it's not driving any logic
- **C**: leave free-form and document the intent in a CLAUDE.md or migration comment

**Defer D-51** — product decision.

### 🟡 F-67. User CRUD has no audit log

User model doesn't use `LogsActivity` trait. Creating a user, editing roles, deleting accounts — none of those leave an activity-log entry. For an audit-sensitive deployment, this is a real gap.

**Fix scope (deferred D-52):** add `use LogsActivity` to User model with allowlist `['name', 'email']` and a Spatie pivot-event hook for `roles` sync. ~20 LOC. Should be applied **before pilot deployment**.

### 🟢 Per-property staff scope via asset_user works

`UserForm::Properties` section + UserPropertyAssignmentTest covers the create/edit flow. `User::getTenants()` reads from `assignedAssets()` and prepends ALL on multi-property users. Module 16 tests confirm the scoping.

### 🟢 RoleResource permission-cache invalidation is explicit

CreateRole and EditRole call `forgetCachedPermissions()` after save — so role edits are immediately visible without waiting for the 24h cache to expire.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='User|Role|Permission|PanelAccess'` | **31 passed / 0 failed** | 2.73 s |
| Full Pest | **295 passed / 0 failed** | 4.x s |

Highlights:
- [Auth/PermissionsTest](../../tests/Feature/Auth/PermissionsTest.php) — super_admin/viewer/manager/leasing/operations permission matrices.
- [Users/UserPropertyAssignmentTest](../../tests/Feature/Users/UserPropertyAssignmentTest.php) — create form defaults, edit form preserves state, restricted/unrestricted tenant visibility.
- [Tenancy/UserTenantsTest](../../tests/Feature/Tenancy/UserTenantsTest.php) — ALL prepend logic, soft-deleted block.

## 5. No inline fixes this module

F-63/F-64/F-65 are production-deploy items. F-66 is a product decision. F-67 (User LogsActivity) is small but pre-pilot; flagged for explicit decision rather than applying mid-audit because turning on activity logging changes the DB pattern of every user mutation, which deserves a dedicated commit + verification.

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-48 | F-63: env-driven seeded password + rotation hook | Apply pre-pilot |
| D-49 | F-64: enable Filament EditProfile + password reset | Apply pre-pilot |
| D-50 | F-65: integrate 2FA | Production-checklist item; revisit pre-launch |
| D-51 | F-66: `asset_user.role` enum or remove | Product decision |
| D-52 | F-67: add LogsActivity to User | Apply pre-pilot — audit trail matters |

## 7. Verdict

**🟡 Yellow.** RBAC is well-architected (clear naming, 81 perms, 6 built-in roles, fast cache invalidation, comprehensive tests). The Yellow items are operational-readiness gaps (default password, self-service, MFA, audit log) — none affect the demo path but four of the five should be addressed before any non-demo deployment.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢 · 17 🟡.

## Next

Module 18 — Reports. Surface: [Services/Reports/](../../app/Services/Reports/), [Reports page](../../app/Filament/Admin/Pages/Reports.php), [AR Aging page](../../app/Filament/Admin/Pages/ArAging.php), [ActivityLog page](../../app/Filament/Admin/Pages/ActivityLog.php), and the various PDF generators for monthly close + tenant statements.
