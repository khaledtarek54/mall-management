# RBAC & Multi-Property Scoping

Role-based access control (RBAC) + tenant-per-property isolation, ensuring staff see only their assigned properties while super_admin has unrestricted access.

## 1. Purpose & business context

The platform is a multi-property ERP: a single operator runs multiple malls, and staff are assigned to specific properties or across all properties. Authorization must:

- Gate CRUD actions per role (nine roles total: `super_admin`, `manager`, `viewer`, `owner`, plus five department roles)
- Enforce that staff assigned to property A never see property B's data (units, leases, invoices, etc.)
- Allow Jawad legal owners read-only access to their owned properties
- Block deletion for all roles except `super_admin` (destructive actions reserved for the platform owner)
- Disable bulk delete project-wide as a safety measure

Implemented via Spatie Laravel Permission (roles × permissions), Filament per-property tenancy (`Asset` as tenant), and scoped queries using `TenantScope` / `AssignedAssets` helpers.

## 2. Domain model

**RBAC tables** (Spatie Permission):
| Table | Columns | Purpose |
|-------|---------|---------|
| `users` | id, name, email, password, email_verified_at | Staff accounts; deleted users cascade delete from `asset_user` / `asset_owner` |
| `roles` | id, name, guard_name (`'web'`), created_at | Nine seeded roles + custom roles created via UI |
| `permissions` | id, name (`"{module}.{action}"`), guard_name, created_at | 50+ permissions (view/create/edit/delete per module + workflow actions) |
| `model_has_roles` | model_id, model_type (`'App\\Models\\User'`), role_id, team_id | User ↔ Role assignments |
| `role_has_permissions` | role_id, permission_id | Role ↔ Permission assignments |

**Scoping tables**:
| Table | Columns | Purpose |
|-------|---------|---------|
| `asset_user` | id, user_id, asset_id, role, assigned_at, ended_at, notes | Staff assigned to a property; a user can be assigned to multiple properties |
| `asset_owner` | id, user_id, asset_id, ownership_percentage, started_at, ended_at | Legal ownership of a mall (for Jawad owners); scopes them read-only to their own properties |
| `assets` | id, code (slugAttribute for tenancy), name, type, ... | Individual properties (malls); a synthetic `code='ALL'` pseudo-asset represents the portfolio view |

**Key models & relationships**:

- **User** (`App\Models\User`):
  - `roles()` — many-to-many via `model_has_roles`; uses Spatie `HasRoles` trait
  - `assignedAssets()` — many-to-many via `asset_user`; staff assignments
  - `ownedAssets()` — many-to-many via `asset_owner`; legal ownership
  - `accessibleAssets()` — returns union of `assignedAssets` + `ownedAssets` (excluding "ALL" pseudo-asset)
  - `departments()` — many-to-many via `department_user` for HR organizational structure

- **Asset** (`App\Models\Asset`):
  - Filament tenant (slug: `code`); single-instance "ALL" reserved via `isAllProperties()`
  - `users()` — reverse of `assignedAssets` and `ownedAssets`

- **Department** (`App\Models\Department`):
  - `asset_id` nullable → global if null, property-scoped if set
  - `members()` — staff assigned to the department
  - Slug (`leasing`, `operations`, `accounting`, `marketing`, `hr`) maps 1:1 to a Spatie role

## 3. Business rules & invariants

**Role definitions** (from `database/seeders/RolesPermissionsSeeder.php`):
- **super_admin**: All permissions, delete, role/setting management → no restrictions
- **manager**: All view/create/edit, no delete, no settings/role edit
- **viewer**: All `.view` + `reports.download` → read-only oversight
- **owner** (Jawad): All `.view` + `reports.download` + `owner_requests.create` → scoped to owned properties
- **leasing**: Properties(view), Units, Tenants, Leases, TenantSales(view)
- **operations**: Maintenance, Vendors, UtilityMeters
- **accounting**: Invoices, Payments, CreditNotes, CAM, Reports
- **marketing**: Marketing Budgets only
- **hr**: Users, Roles(view), Departments(view) — **cannot create/edit roles or departments**

**Permission catalog**: 50+ permissions in `PERMISSIONS` array, grouped by module (assets, units, tenants, leases, invoices, payments, credit_notes, maintenance, tenant_sales, cam, utility_meters, vendors, departments, marketing, owner_requests, notes, users, roles, reports, activity_log, settings). Format: `"{module}.{action}"` where action ∈ `{view, create, edit, delete}` + module-specific actions (`leases.terminate`, `invoices.submit_to_eta`, `maintenance.assign`, etc.).

**Deletion rule**: Only `super_admin` can delete any record, single or bulk (enforced in `RoleGatedActions::canDelete()` / `canDeleteAny()` regardless of any `{module}.delete` permission in the roles table). Bulk delete is additionally disabled project-wide via `$bulkDeletable = false` on all resources.

**Property scoping**: A user assigned to properties A and B sees only A/B data across all modules. The "ALL Properties" pseudo-tenant (code='ALL') is shown in the property switcher when a user has >1 accessible property; in ALL mode, restricted users still see only their assigned set via `TenantScope::visibleAssetIds()`.

**Feature-gated permissions**: `RoleGatedActions::hasPermission()` requires both the permission AND the module feature flag (`Modules::enabled($module)`) to be true. Disabling a module overrides the permission grant.

**Self-delete guard**: `UserResource::canDelete($user)` denies super_admin deletion of their own account (no other role can delete at all).

## 4. Lifecycle / state machine

No state machine per se. User authorization is static post-login:
- User logs in → roles loaded from `model_has_roles` via Spatie → Laravel's `Auth::user()` resolved
- Per-request gate checks via `Auth::user()->can("{module}.{action}")` or `hasRole($role)`
- Property tenant set from URL slug (Filament's `->tenant(Asset::class, slugAttribute: 'code')`) or property switcher → affects `TenantScope::currentAssetId()`

**Transitions**: Only HR (or super_admin) can modify user roles/assignments in the UI:
- UserResource (gated on `users.create`/`users.edit`, super_admin delete + self-guard)
- RoleResource (super_admin only for create/edit; manager/hr view-only)
- Asset assignments via EditUser page (multi-select of properties)

**Immutable**: Role/permission definitions are NOT changed at runtime (RolesPermissionsSeeder runs once at deploy; super_admin can create custom roles via RoleResource but core roles are locked by design). Departments are a fixed seeded set; super_admin cannot create/delete them.

## 5. Services, jobs & scheduled commands

**TenantScope** (`App\Support\TenantScope`):
- `currentAssetId()` — returns the active Filament tenant's ID, or null if "ALL Properties" or no tenant context (CLI, commands)
- `applyTo(Builder $query, ?string $relation)` — constrain a query to the current property (no-op if "ALL" or no tenant)
- `visibleAssetIds()` — returns array of asset IDs the current user can see in "ALL" mode; null if super_admin or single property
- `selectableAssetOptions()` — property picker (id => name) scoped to the current user's visible set, excluding "ALL"
- `selectableTenantOptions()` — tenant picker scoped to the current user's visible properties (includes lease-less tenants as they're safe everywhere)

**AssignedAssets** (`App\Support\AssignedAssets`):
- `idsForCurrentUser()` — asset IDs for `Auth::user()`, or null if no scoping applies (super_admin or no assignments)
- `idsFor(User $user)` — asset IDs for a given user (staff assignments ∪ legal ownership)
- `isRestricted(?User)` — true when the user has at least one assigned asset and is not super_admin

**RolesPermissionsSeeder** (`database/seeders/RolesPermissionsSeeder.php`):
- Creates all permissions, roles, and syncs role→permission mappings (idempotent via `findOrCreate` + `syncPermissions`)
- Runs at `php artisan db:seed` or `php artisan migrate:fresh --seed`
- No corresponding job; seeder is run during deployment / local setup only

No scheduled commands for RBAC itself. Permission cache expires after 24 hours (configured in `config/permission.php`).

## 6. Filament resources & key fields

**RoleGatedActions trait** (`App\Filament\Admin\Resources\Concerns\RoleGatedActions`):
- Applied to most resources (Asset, Unit, Tenant, Lease, Invoice, Payment, CreditNote, Maintenance, Vendor, etc.)
- Overrides `canViewAny()`, `canCreate()`, `canEdit($record)`, `canDelete($record)` to check `"{module}.{action}"` permissions
- `permissionModule()` auto-derives from model name (snake_case plural; Invoice → `invoices`), overridable per resource
- `hasPermission($action)` gates on both permission AND feature flag (`Modules::enabled($module)`)
- `isSuperAdmin()` for the delete-only check
- `$bulkDeletable = false` (default, off across the whole project)

**UserResource** (`App\Filament\Admin\Resources\Users\UserResource`):
- Bypasses RoleGatedActions; uses bespoke gates:
  - `canAccess()` / `canViewAny()` → checks `users.view` (not a standard resource gate)
  - `canCreate()` → checks `users.create`
  - `canEdit($record)` → checks `users.edit`
  - `canDelete($record)` → `Auth::id() !== $record->id && Auth::user()->hasRole('super_admin')`
  - `canForceDelete($record)` / `canRestore($record)` → super_admin only
  - `$isScopedToTenant = false` — not property-scoped (single user spans many properties)
- Form fields: name, email, password (hashed), two_factor_secret (nullable), roles (multi-select via Filament's relationship manager)
- Assigns roles by syncing `model_has_roles` entries

**RoleResource** (`App\Filament\Admin\Resources\Roles\RoleResource`):
- Uses RoleGatedActions: `permissionModule() = 'roles'`
- Gates: `roles.view` (manager/hr view-only), `roles.create` (super_admin only), `roles.edit` (super_admin only)
- `$isScopedToTenant = false` — roles are system-wide
- Custom role creation: super_admin can build arbitrary permission combinations via a picker in RoleForm
- Form: name (text), permissions (multi-select checkbox grid, grouped by module from `RolesPermissionsSeeder::PERMISSIONS`)

**DepartmentResource** (`App\Filament\Admin\Resources\Departments\DepartmentResource`):
- Hard-disables create/delete for all roles (fixed seeded set): `canCreate() = false`, `canDelete($record) = false`
- `canViewAny()` gates on `departments.view` (all roles holding the permission can see the list)
- `$isScopedToTenant = false` — global concept, though individual departments can be property-scoped (`asset_id`)
- Form: name, code, description, asset_id (null = global), head_user_id (nullable), is_active, sort_order

**Form field scoping** (e.g., CamExpensePoolForm):
```php
Select::make('asset_id')
    ->options(fn () => TenantScope::selectableAssetOptions())
    ->disabled(fn () => TenantScope::currentAssetId() !== null)
    ->default(fn () => TenantScope::currentAssetId())
```
When a user is pinned to property A, the asset_id picker auto-defaults to A and is disabled; in "ALL" mode, they can manually select A but not B.

**Tenant picker scoping** (e.g., MaintenanceRequestForm):
```php
Select::make('tenant_id')
    ->options(fn () => TenantScope::selectableTenantOptions())
```
Returns tenants leased in the user's visible properties, plus lease-less orphan tenants (safe everywhere, as they belong to no property).

## 7. Notifications & integrations

No integrations specific to RBAC. User activity is logged to `activity_log` table via Spatie ActivityLog (User model logs name/email/email_verified_at changes).

### Access-control audit trail (`log_name = access_control`)

Role and permission grants/revokes are audited — the "who granted whom which access" record that the model-attribute logging can't capture (roles/permissions are pivot rows). All entries land under the **`access_control`** log name in the standard Activity Log viewer (danger-coloured badge), via `App\Support\AccessControlAudit`.

Auditing is done with explicit **before/after diffs** at each UI mutation point — **not** via spatie's permission events. Two reasons events are unfit here: (1) Filament's roles `Select` saves through the **raw belongsToMany pivot** (`sync()`/`detach()`), which fires no spatie event — so the *primary* admin path would be invisible; (2) spatie fires its events with the full *requested* set, not the delta, so an event listener logs phantom grants on every idempotent re-assign. A diff is delta-aware (no phantoms) and catches every path. (`config('permission.events_enabled')` stays **false**.)

- **User↔role** (`role_granted` / `role_revoked`): `CreateUser`/`EditUser` diff the roles relationship in `afterCreate`/`afterSave` (capturing the "before" set in `beforeSave`). Department membership goes through `Department::registerMember` / `unregisterMember` / `assignRolesToMembers`, which are idempotent (grant only if the user lacks the role) and self-audit — so re-running the roster on a member-attach neither re-attaches nor logs phantom grants.
- **Role↔permission** (`permission_granted` / `permission_revoked`): `CreateRole`/`EditRole` diff via `AccessControlAudit::logPermissionDiff()`. **Role deletion** (a cascade mass-revoke) is logged by the `DeleteAction->before()` hook (`role_deleted`, listing the holders) before the record is gone.
- **Protected-role guard (who may confer power)**: the **crown-jewel write-everything roles** — `super_admin` *and* `manager` (`UserResource::PROTECTED_ROLES`) — may only be granted or revoked by a super_admin. Enforced **post-sync** by `UserResource::enforceProtectedRolesRule()` (called from `CreateUser::afterCreate` / `EditUser::afterSave` after the roles relationship is written — Filament saves the Select from component state, so mutating form data beforehand does *not* hold): for a non-super_admin actor it reverts any add/remove of a protected role (leaving the target's status unchanged) and logs `protected_role_change_blocked`. Everything else — functional/**department** roles + read-only `viewer`/`owner` — stays grantable by any `users.edit` holder (e.g. HR managing staff).
  - **Department roles** are granted by *membership*, not by editing the Role: adding a user to a department (gated by `departments.edit`) confers that department's role (`leasing`/`operations`/`accounting`/`marketing`/`hr`) and removing them revokes it. This is intended (the DEPT-6 model) and is bounded — a department's slug can never be a protected role, so this path can only ever confer a scoped functional role, and every grant/revoke is audited.
- **Only authenticated, human-initiated changes are logged** (`AccessControlAudit::log()` gates on `auth()->check()`): seeding (`migrate:fresh --seed`) and CLI grants have no causer, so they're skipped — keeping the trail meaningful and the test suite deterministic. The audit write is wrapped in a try/catch (advisory — it must never abort the grant it records). Direct pivot manipulation in code (`$user->roles()->detach()`) bypasses these points and is **not** captured.

Database notifications are used for operational events (portal maintenance requests, sales declarations, SLA breaches, audit events) and are scoped per-user inbox (no RBAC-specific notification dispatch rules).

## 8. Extension points — how to change/extend SAFELY

### Add a new module (e.g., "Contracts")
1. **Seed permissions** in `database/seeders/RolesPermissionsSeeder.php::PERMISSIONS['contracts']`:
   ```php
   'contracts' => [
       'contracts.view'   => 'View contracts',
       'contracts.create' => 'Create contracts',
       'contracts.edit'   => 'Edit contracts',
       'contracts.delete' => 'Delete contracts',
   ]
   ```

2. **Assign to roles** in `RolesPermissionsSeeder::syncRolePermissions()`:
   ```php
   Role::findByName('leasing', 'web')->syncPermissions([
       // existing perms...
       'contracts.view', 'contracts.create', 'contracts.edit',
   ]);
   ```

3. **Create the resource** in `app/Filament/Admin/Resources/`:
   ```php
   class ContractResource extends Resource {
       use RoleGatedActions;
       protected static ?string $model = Contract::class;
   }
   ```
   The permission module auto-derives from the model name (`Contract` → `contracts`).

4. **Scope the query** in the resource's table:
   ```php
   ->modifyQueryUsing(fn ($q) => TenantScope::applyTo($q, 'vendor'))
   ```
   Or override `getEloquentQuery()` if needed.

5. **Add tests** in `tests/Feature/Scenarios/AuthorizationMatrixTest.php` to verify every role's access.

### Add a new role (e.g., "Tenant Relations")
1. Add to `RolesPermissionsSeeder::ROLES`:
   ```php
   'tenant_relations' => 'Tenant communications + billing queries — read-only + leasing.create.',
   ```

2. Assign permissions in `syncRolePermissions()`:
   ```php
   Role::findByName('tenant_relations', 'web')->syncPermissions([
       'tenants.view', 'leases.view', 'invoices.view', 'leasing.create',
   ]);
   ```

3. Update the authorization matrix test if gating new modules.

4. The new role is now available in UserResource's role multi-select; any user assigned it gains the permissions immediately (Spatie Permission cache refreshes per request).

### Customize property scoping for a resource
1. If the model has `asset_id` directly:
   ```php
   ->modifyQueryUsing(fn ($q) => TenantScope::applyTo($q))
   ```

2. If the asset is via a relation (e.g., Invoice → Lease → Unit → Asset):
   ```php
   ->modifyQueryUsing(fn ($q) => TenantScope::applyTo($q, 'lease.unit'))
   ```

3. In a custom form field, use:
   ```php
   Select::make('asset_id')->options(fn () => TenantScope::selectableAssetOptions())
   ```

4. Test scoping with `ScopingScenarioTest` patterns (two properties A/B, restricted user to A, assert B's records never surface).

### Allow bulk delete on a resource (dangerous)
1. Override `$bulkDeletable = true` in the resource class (e.g., `VendorResource`).
2. Bulk delete remains super_admin only even when enabled (see `RoleGatedActions::canDeleteAny()`).
3. Add a test case to `DeleteAuthorizationScenarioTest` to verify only super_admin can bulk-delete.

### Lock a resource (like Department)
1. Override `canCreate()` and `canDelete()` to return false:
   ```php
   public static function canCreate(): bool { return false; }
   public static function canDelete(Model $record): bool { return false; }
   ```
2. This is done in DepartmentResource; Department is a fixed seeded set managed by migrations, not user UI.

### Check authorization in code (non-Filament contexts)
Use Laravel's gate helpers:
```php
Auth::user()->can('invoices.create')
Auth::user()->hasRole('super_admin')
Auth::user()->hasRole(['leasing', 'accounting'])
```

Or in Blade:
```php
@can('invoices.create') ... @endcan
@role('super_admin') ... @endrole
```

## 9. Gotchas, edge cases & recently-fixed bugs

### "All Properties" pseudo-tenant
- The synthetic Asset with `code='ALL'` is a real DB row used so Filament can resolve it from URL slugs.
- When a user switches to "ALL", `TenantScope::currentAssetId()` returns null (meaning "do not filter by asset_id").
- Restricted users still see only their assigned properties in "ALL" mode via `visibleAssetIds()` — queries use `whereIn('asset_id', [...])` instead of a single asset_id filter.
- The "ALL" asset never appears in property-picker dropdowns (`selectableAssetOptions()` explicitly excludes it).

### Soft-deleted assets
- Trashed assets are excluded from the property switcher (`User::getTenants()` queries untrashed assets).
- Users assigned to a deleted asset cannot access it (checked in `canAccessTenant()` via `$tenant->trashed()`), preventing URL tampering.

### Jawad owner scoping
- Legal owners are scoped via `asset_owner` table (not `asset_user`), with `ownership_percentage`.
- They see the same read-only access as the `viewer` role: all `.view` permissions + `reports.download`.
- If an owner is also staff-assigned to a different property, their `accessibleAssets()` union includes both.
- Owners cannot be assigned to "ALL" pseudo-asset (excluded in queries).

### UserResource force-delete is currently open (BUG)
- UserResource does not use RoleGatedActions, so `canForceDelete()` inherits Filament's permissive default.
- Only UserResource is affected; all other resources properly gate force-delete in RoleGatedActions.
- Workaround: manually override `canForceDelete()` in UserResource or use RoleGatedActions (adds complexity due to custom permission gating).
- Tracked in `DeleteAuthorizationScenarioTest` comments.

### Module feature flags override permissions
- Even if a user has `credit_notes.view`, if the module is disabled in settings, `CreditNoteResource::canViewAny()` returns false.
- The sidebar also hides disabled modules via `shouldRegisterNavigation()`.

### Department scoping in picker
- `Department::selectableOptions()` returns only active departments in the current user's visible properties, plus global (null asset_id) departments.
- A property-restricted user never sees another property's departments via the picker, but the database query is unrestricted (no foreign key).

### Circular permission dependencies
- No explicit cycles (a role's permissions are a flat set from Spatie), but custom roles can create unintuitive combos (e.g., `invoices.create` without `invoices.view`).
- Recommend validating custom roles in the UI or via a test matrix to catch nonsensical combos.

### Cache invalidation
- Spatie Permission caches all roles/permissions for 24 hours.
- When roles or permissions change (via UI or seeder), the cache is flushed by Spatie observers.
- In edge cases (e.g., tests that don't flush cache), use `app(PermissionRegistrar::class)->forgetCachedPermissions()` to reset.

## 10. Tests & related modules

### Test files covering RBAC & scoping

**`tests/Feature/Scenarios/AuthorizationMatrixTest.php`** — the canonical authorization matrix:
- Every role × resource × action combo (view/create/edit/delete)
- Drives real Filament gate methods (`canViewAny()`, `canCreate()`, `canEdit($record)`, `canDelete($record)`)
- Verifies department roles see only their sidebar group (e.g., leasing cannot see invoices)
- Tests module feature-flag gating (disabled module denies access even with permission)
- Tests guest (unauthenticated) denial
- Locked-set sanity checks (Department cannot be created/deleted; Role create/edit super_admin only)

**`tests/Feature/Scenarios/ScopingScenarioTest.php`** — cross-property data isolation:
- Two properties A/B + fixture setup
- Manager assigned only to A: cannot access B as tenant, sees only A's units/leases/invoices, restricted to A in "ALL" mode
- Jawad owner: legal ownership via `asset_owner` scopes read-only to owned property
- Two co-owners independent (A owns A, B owns B)
- Picker helpers: `selectableAssetOptions()`, `selectableTenantOptions()`, `Department::selectableOptions()` correctly hide out-of-scope rows

**`tests/Feature/Scenarios/DeleteAuthorizationScenarioTest.php`** — deletion rules:
- Only super_admin can single-delete any record
- All other roles denied delete (manager, viewer, owner, all departments)
- Bulk delete disabled everywhere (canDeleteAny() = false for all roles)
- Force-delete also super_admin only (except UserResource BUG)
- UserResource self-delete guard (super_admin cannot delete their own account)

**`tests/Feature/Tenancy/ResourceScopingTest.php`** — per-property resource queries:
- Drives actual resource table queries as super_admin, switching tenants
- Verifies each resource's scoping modifier (TenantScope::applyTo) filters correctly
- Tests "ALL Properties" tenant returns all data for super_admin

**`tests/Feature/Resources/PortalCamAllocationScopingTest.php`** — CAM-specific scoping (example of module-specific scoping test).

### Seeder usage
- `RolesPermissionsSeeder::class` is seeded at `php artisan db:seed` and in test `beforeEach()` to populate real permission/role data.
- Must seed before running `AuthorizationMatrixTest` (checked at top of test file).

### Helper functions in test utilities (e.g., `tests/Pest.php`)
- `makeUser($role, $assignedAssetIds = [])` — creates a test user with the given role + optional property assignments
- `makeAsset([$code => ..., $name => ...])` — creates a test property
- `asTenant($asset, $callback)` — sets Filament tenant context for the callback
- `scopedResourceQuery(ResourceClass)` — runs a resource's getEloquentQuery() in the current tenant context

### Related modules
- **Filament Admin Panel** (`app/Providers/Filament/AdminPanelProvider.php`) — configures `.tenant(Asset::class, slugAttribute: 'code')` for per-property routing and per-request `Filament::getTenant()` context
- **Modules Settings** (`app/Settings/ModulesSettings.php`) — feature flags checked by `Modules::enabled($module)` in RoleGatedActions
- **Activity Log** (`app/Models/User` LogsActivity trait) — audit trail of user create/edit/delete (passwords redacted)
- **Department** (`app/Models/Department`) — hybrid design: department membership + Spatie role assignment
- **Database Notifications** — scoped per-user inbox (separate from RBAC but delivered based on user context)

---

## Quick Reference

| Concept | Location | Key Method/Field |
|---------|----------|------------------|
| Roles & Permissions | `database/seeders/RolesPermissionsSeeder.php` | `ROLES`, `PERMISSIONS`, `syncRolePermissions()` |
| User RBAC | `app/Models/User.php` | `roles()`, `can()`, `hasRole()` (Spatie trait) |
| User Scoping | `app/Models/User.php` | `assignedAssets()`, `ownedAssets()`, `accessibleAssets()` |
| Tenant Scoping | `app/Support/TenantScope.php` | `currentAssetId()`, `visibleAssetIds()`, `selectableAssetOptions()` |
| Staff Assignment Scoping | `app/Support/AssignedAssets.php` | `idsForCurrentUser()`, `idsFor($user)`, `isRestricted()` |
| Resource Permission Gating | `app/Filament/Admin/Resources/Concerns/RoleGatedActions.php` | `canViewAny()`, `canCreate()`, `canEdit()`, `canDelete()`, `hasPermission()` |
| User Management | `app/Filament/Admin/Resources/Users/UserResource.php` | `canAccess()`, `canDelete()` + self-guard |
| Role Management | `app/Filament/Admin/Resources/Roles/RoleResource.php` | `permissionModule()`, custom permission picker |
| Permission Config | `config/permission.php` | Spatie cache, table names, column names |
| Authorization Tests | `tests/Feature/Scenarios/AuthorizationMatrixTest.php` | Matrix of 9 roles × 15 resources × 4 actions |
| Scoping Tests | `tests/Feature/Scenarios/ScopingScenarioTest.php` | Cross-property isolation, picker helpers |
| Deletion Tests | `tests/Feature/Scenarios/DeleteAuthorizationScenarioTest.php` | Super_admin only, bulk delete disabled |

