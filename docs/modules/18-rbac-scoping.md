# RBAC & Multi-Property Scoping

Role-based access control (RBAC) + tenant-per-property isolation, ensuring staff see only their assigned properties while super_admin has unrestricted access.

> **Total property isolation** (reads **and** writes) has a dedicated reference:
> **[docs/PROPERTY-ISOLATION.md](../PROPERTY-ISOLATION.md)** — the shared-vs-isolated register
> (`App\Support\PropertyIsolation`), the read-scoping traits, the `GuardsAssetInScope` write guard
> (Filament stamps `asset_id` only on *create*, so an editable `asset_id` on *edit* needs a guard), and
> the self-enforcing `PropertyIsolationConformanceTest`. Read it before adding a property-owned module.

## 1. Purpose & business context

The platform is a multi-property ERP: a single operator runs multiple malls, and staff are assigned to specific properties or across all properties. Authorization must:

- Gate CRUD actions per role (nine roles total: `super_admin`, `manager`, `viewer`, `owner`, plus five department roles)
- Enforce that staff assigned to property A never see property B's data (units, leases, invoices, etc.)
- Allow Jawad legal owners read-only access to their owned properties
- Block deletion for all roles except `super_admin` (destructive actions reserved for the platform owner)
- Disable bulk delete project-wide as a safety measure

Implemented via Spatie Laravel Permission (roles × permissions), Filament per-property tenancy (`Asset` as tenant), and scoped queries using `TenantScope` / `AssignedAssets` helpers.


### You cannot grant access you do not hold (2026-08-17)

`users.create` / `users.edit` are held by **`manager`, `hr` and `mall_admin`** as well as super_admin,
and the user form's *Assigned properties* field is what grants access to a mall. Nothing checked the
GRANTOR — so a manager pinned to Mall A could open their own user record, add Mall B, and see Mall B.
Privilege escalation through an ordinary CRUD screen, demonstrated through the real form before the
guard was written.

`UserResource::enforceGrantableAssetsRule()` closes it, deliberately mirroring
`enforceProtectedRolesRule()` — the same screen already used that shape for the same class of problem:

- **Both directions.** A restricted grantor can neither grant a property they cannot see nor
  **revoke** one — stripping someone's Mall B access from Mall A is the same overreach with the sign
  flipped.
- **Reverted and logged**, not refused. The attempt lands in `AccessControlAudit` as
  `property_access_change_blocked`; a probe, even a reverted one, is what a reviewer wants in the
  trail.
- **In `afterCreate`/`afterSave`.** `assignedAssets` is a relationship Select, which saves from
  component state through `saveRelationships()` and never reads mutated form data — a guard in a
  `mutateFormData…` hook would be inspecting a value the save does not use.
- **Against `AssignedAssets::idsForCurrentUser()`, not `TenantScope::visibleAssetIds()`.** The latter
  collapses to the SELECTED property, so an hr user holding two malls and working in one could not
  grant the other — legitimate, and it would read as a bug.
- The picker stays portfolio-wide (`EntitySelect::acrossProperties()`). A narrowed option list is not
  a gate: the assignment arrives as a Livewire payload and a crafted request never opens the dropdown.
  The create DEFAULT is now the grantor's own set, which previously proposed exactly the assignment
  the save blocks.

**Not decided here:** whether `hr` and `mall_admin` should hold `users.edit` at all is the operator's
call. This makes the permission they hold safe; it does not re-cut the catalogue.

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
- **owner** (Jawad): `.view` on the **owner-visible modules only** (`App\Support\OwnerVisibility`) + `reports.download` + `owner_requests.create` + `owner_statements.view_own` → scoped to owned properties
- **leasing**: Properties(view), Units, Tenants, Leases, TenantSales(view)
- **operations**: Maintenance, Vendors, UtilityMeters
- **accounting**: Invoices, Payments, CreditNotes, CAM, Reports
- **marketing**: Marketing Budgets only
- **hr**: Users, Roles(view), Departments(view) — **cannot create/edit roles or departments**

**Permission catalog**: 50+ permissions in `PERMISSIONS` array, grouped by module (assets, units, tenants, leases, invoices, payments, credit_notes, maintenance, tenant_sales, cam, utility_meters, vendors, departments, marketing, owner_requests, notes, users, roles, reports, activity_log, settings). Format: `"{module}.{action}"` where action ∈ `{view, create, edit, delete}` + module-specific actions (`leases.terminate`, `invoices.submit_to_eta`, `maintenance.assign`, etc.).

**Deletion rule**: Only `super_admin` can delete any record, single or bulk (enforced in `RoleGatedActions::canDelete()` / `canDeleteAny()` regardless of any `{module}.delete` permission in the roles table). Bulk delete is additionally disabled project-wide via `$bulkDeletable = false` on all resources.

**Property scoping**: A user assigned to properties A and B sees only A/B data across all modules, and works inside one of them at a time — the switcher offers only real malls (the "ALL Properties" pseudo-tenant is no longer selectable; see the "All Properties pseudo-tenant" gotcha below). The pseudo-asset's scoping plumbing (restricted users pinned to their assigned set via `TenantScope::visibleAssetIds()` when it is force-set) is retained for a future consolidation surface.

**Feature-gated permissions**: `RoleGatedActions::hasPermission()` requires both the permission AND the module feature flag (`Modules::enabled($module)`) to be true. Disabling a module overrides the permission grant.

**Self-delete guard**: `UserResource::canDelete($user)` denies super_admin deletion of their own account (no other role can delete at all).

### FR-USR roles & rights (the Eltizam FRD)

| Role | What the FRD says | How it's built |
|---|---|---|
| **`mall_admin`** | *"Admin (per mall): full access for their assigned mall; **the only role that can import/upload data**"* (FR-USR-01/02) | a `manager` plus `imports.execute`, scoped to their properties by the same `AssignedAssets` mechanism as everyone else. **Not** given delete — the FRD's "full access" is ambiguous and delete is super_admin-only project-wide (client question 23) |
| **`technician`** | *"In-house Technician: normal employee; **sees only work assigned to them**"* (FR-USR-04) | the one role deliberately lacking `{module}.view_all`, which is what makes `AssignmentScope` bite |
| **`coordinator`** | *"Coordinator: manages assignment and oversight of requests/work orders"* | holds `maintenance`/`facility` `.view_all` (so `AssignmentScope` does **not** restrict it) plus `maintenance.assign` — assignment is oversight, you cannot hand out work you cannot see. Narrower than `operations`: no meters/inventory/procurement |
| **`customer_service`** | *"Customer Service"* — the front-desk/intake role | may **log** a request and see **every** request (`maintenance.view_all`, so it can answer "what's the status of mine?") but has **no work authority** — no `assign`, `change_status`, `complete` or `edit`; `tenants.view` to identify the caller |
| **`vendor`** | *"View-only access; can upload CSV files … but cannot otherwise import/edit"* (FR-USR-03) | an external contractor login: `.view`/`.view_all` on the **maintenance** surface only. **No** create/edit/delete/status-change, and **no** tenants/leases/financials/HR/GL — it must not read another party's commercial data. Property-scoped. **FR-USR-03's "CSV upload" is deferred** — deliberately NOT the blanket admin `imports.execute` (that is tenants/leases/units import, which would widen the FR-USR-02 admins-only import gate for an external party, with no vendor-facing surface to use it); a real vendor upload needs its own surface + permission. *(Finer "only my own jobs" scoping also needs a vendor-user→company link that doesn't exist yet.)* |

#### Import is not a flavour of create (FR-USR-02)

> *"The system shall restrict data import/upload functionality to **Admin users only**; all other
> roles may export/download but not import."*

Every `ImportAction` was gated on `canCreate()`, so every manager and the whole leasing team could
import. Creating a tenant is one considered row; **one wrong CSV column rewrites hundreds at once,
and the damage surfaces later — in the billing.** That is why the FRD singles it out.

`App\Support\Imports::allowed()` is the single gate, and
`ImportIsAdminOnlyTest` carries a **reflective conformance gate** that walks `app/Filament` and fails
the build if any `ImportAction::make` is not gated on it. A fourth import button cannot ship the way
the first three did.

`imports.execute` is explicitly rejected from the manager blanket grant — it is not a `.delete`, so
the "everything except delete" filter would otherwise hand it straight back to every manager and
quietly undo the requirement.

#### Assignment scoping (FR-USR-04)

See [PROPERTY-ISOLATION.md](../PROPERTY-ISOLATION.md#the-second-scoping-primitive-assignment-fr-usr-04)
— `AssignmentScope` is the system's second scoping primitive and composes with `TenantScope`. Note
its trap: `ScopesViaProperty` **is** `getEloquentQuery()`, so declaring that method in a resource
shadows the trait and silently deletes property isolation.

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

### The audit trail is bilingual — because it stores data, not prose

**The rule: an activity row records `log_name`, `event`, field keys and raw values; every
human-readable word is resolved at READ time** by `App\Support\ActivityVocabulary`. That is what
lets one stored row read correctly in Arabic and in English, and what lets a wording fix reach
rows written years ago. A sentence baked in at write time can never be either — which is why the
five void/reverse services store a **key** (`invoice.voided`) rather than `'Invoice voided'`.

`ActivityVocabulary` answers four questions, and nothing else may `__()` on activity data:

| | resolves from |
|---|---|
| `subject($logName)` | `admin.activity.subjects.*` → `subjects.default` |
| `event($event)` | `admin.activity.events.*` → humanised |
| `description($key)` | `admin.activity.descriptions.*` (**nested**, since `__()` reads dots as nesting) → the raw stored string, so pre-rule history stays readable |
| `field($logName, $f)` | `admin.activity.fields.{log_name}.{field}` (per-model override) → **`admin.fields.*`, the catalogue the FORMS label from** → humanised |
| `value(...)` | `admin.statuses.{log_name}.*` by convention, else the `VALUE_VOCABULARY` registry of exceptions; then `FOREIGN_KEYS` (an id becomes the record's name); otherwise formatted from the subject model's own **`$casts`** (money / date / bool / JSON), never a hand-kept list of "which fields are money" |

**`VALUE_VOCABULARY` is keyed by the catalogue the FORM for that field reads from**, verified
against the real `Select` options — not inferred from key overlap, which produces confident
nonsense: `admin.enums.category` is the RETAIL category list, so pointing `expense.category` at it
renders an expense as "Food & Beverage". TenantRequest's column is `request_type`, not `type`.

**`FOREIGN_KEYS` turns an id into a name** — "Lease LSE-AW-2026-0011", not "Lease 328". An id is
not an audit trail. Naming follows the existing `label()` / `displayName()` convention, then
`reference` / `number` / `name` / `code` / `title`; `AccountingPeriod`, `EmployeeAdvance` and
`MarketingBudget` gained a `label()` because they had nothing to be named by. Keys may be scoped
`{log_name}.{field}` where a column is model-specific (`equipment.parent_id`). Identifiers stay
verbatim in both languages — `charge_code.code` and `account_mapping.key` are what the accountant
types and searches for. `preloadReferences()` resolves a whole page in one query per table; both
surfaces call it from `getTableRecords()`, because these references live in a JSON column and no
Eloquent relation exists to eager-load.

Reusing `admin.fields.*` for field labels is a correctness property, not just DRY: the audit trail
then calls a column exactly what the form that edits it calls it.

`ActivityLogChangeRenderer` owns markup only, and normalises **two columns**: spatie writes the
model diff to `attribute_changes`, while anything from `withProperties()` lands in `properties`
(the Settings / Property Overrides pages record `changes[key] = ['from','to']` there). Scalar
context from that payload — `CONTEXT_KEYS`: `reason`, `amount`, `bill`, `asset_id` — renders under
its own label. A description that merely repeats the event is dropped — spatie defaults it to the
event name, so ~1.8k rows say "created"/"updated", which the Event badge beside them already says
in the reader's language.

**Three RTL rules the cell depends on**, all learned from the Arabic screen rather than reasoned
about: the arrow flips to `←` (`→` is not bidi-mirrored, so left alone it points at the old
value); every label and value is a **`<bdi>`**, so an Arabic label and a Latin value are not
reordered into each other; and the label→value gap is **layout, not a space character** — a plain
space between scripts is absorbed when the bidi algorithm reorders the run, which is why
«الاسم» and "Parking & rentable items" rendered with nothing between them. That gap is an **inline
style, not a utility class**: this markup is injected via `->html()` and never seen by the Tailwind
scanner that builds Filament's stylesheet, so an unused class compiles to nothing — silently, in
the exact place the fix lives.

The three void services (`VoidInvoiceService`, `VoidPaymentService`,
`VoidVendorBillPaymentService`) pass an explicit log name — bare `activity()` files the row under
spatie's `default`, so a voided invoice showed **"Other"** in the What badge and was invisible to
the log-name filter. `vendor_bill_payment` is a log name with no model behind it, like `settings`.

**Gate: `ActivityLogVocabularyConformanceTest`.** Every model log name, every `activity('…')`
literal, every `->event('…')`, every `logOnly()` column and every `->log('…')` description must
resolve in **both** locales, or the build fails. Two traps it encodes:

- **`Lang::has($key, 'ar')` falls back to English** (`has($key, $locale, $fallback = true)`), so
  the obvious EN↔AR parity check answers TRUE for a key that exists only in English. Every parity
  check passes `fallback: false`; every READ-path lookup deliberately keeps the fallback.
- **A sweep that matches nothing passes.** The predecessor gate filtered on
  `Spatie\Activitylog\Traits\LogsActivity::class` — a class that does not exist in the version we
  ship (`Models\Concerns\LogsActivity`). `::class` is a compile-time string and never checks the
  class exists, so it skipped all 64 models and was green for a year. Detection is now by
  `class_basename`, and both gates assert the sweep **found** something before asserting anything
  about what it found.

#### Extension points — adding or changing anything that logs activity

**Adding a model to the activity log** (`use LogsActivity` + `getActivitylogOptions()`):

1. `useLogName('your_thing')` → add `admin.activity.subjects.your_thing` to **`lang/en/admin.php`
   AND `lang/ar/admin.php`**.
2. Every column in `logOnly([...])` needs a label. Check `admin.fields.*` first and **reuse the
   key the form already uses** — same word for the same field is the point. Only add a new
   `admin.fields.*` entry if none exists; put it in both locales.
3. Any column holding an enum-ish string (`status`, `type`, `category`, `method`, `priority`, …)
   needs a row in `ActivityVocabulary::VALUE_VOCABULARY`, **except** a `status` whose catalogue is
   already `admin.statuses.{log_name}` — that resolves by convention with no entry. Point it at
   the group **the form's `Select` reads from**; do not pick a group because its keys happen to
   match. Values with no catalogue (free text, operator-entered codes) correctly render verbatim.
4. Any `*_id` column → add it to `ActivityVocabulary::FOREIGN_KEYS` so the diff names the record
   instead of printing an id. The model must be nameable: `label()` / `displayName()`, or a
   `reference` / `number` / `name` / `code` / `title` column. If it has none, **give it a
   `label()`** (see `AccountingPeriod`). Use a `{log_name}.{field}` key when the column means
   something model-specific (`equipment.parent_id`).

**Logging something by hand** (`activity()->…->log()`):

- Pass the log name — `activity('invoice')`, never bare `activity()`, which files the row under
  spatie's `default` and renders as "Other" while being invisible to the log-name filter. Then
  add its `admin.activity.subjects.*` key; a log name needs no model (`settings`).
- `->event('x')` → add `admin.activity.events.x` in both locales.
- `->log('...')` must be a **KEY, not a sentence** — `invoice.voided`, resolved from
  `admin.activity.descriptions.*`, which is **nested** (`__()` reads dots as nesting, so a flat
  `'invoice.voided' => …` key can never be found). Prose written here can never be translated
  afterwards, by anything.
- Extra `withProperties()` keys only render if listed in `ActivityLogChangeRenderer::CONTEXT_KEYS`,
  and each needs an `admin.fields.*` label in both locales.

**Adding a new translation key of any kind**: put it in `lang/en` and `lang/ar` in the same commit.
Do not verify with `Lang::has($key, 'ar')` — it falls back to English and answers TRUE.

`ActivityLogVocabularyConformanceTest` enforces every line above, so a miss is a red build rather
than an English word on an Arabic screen. Run it after touching any of this:
`vendor/bin/pest --parallel tests/Feature/Scenarios/ActivityLogVocabularyConformanceTest.php`.
To re-check against real data rather than the registries, render live rows in `ar` and look for
surviving ASCII tokens — that is how the 24 missing vocabularies were found, after the registry
looked complete.

### Access-control audit trail (`log_name = access_control`)

Role and permission grants/revokes are audited — the "who granted whom which access" record that the model-attribute logging can't capture (roles/permissions are pivot rows). All entries land under the **`access_control`** log name in the standard Activity Log viewer (danger-coloured badge), via `App\Support\AccessControlAudit`.

Auditing is done with explicit **before/after diffs** at each UI mutation point — **not** via spatie's permission events. Two reasons events are unfit here: (1) Filament's roles `Select` saves through the **raw belongsToMany pivot** (`sync()`/`detach()`), which fires no spatie event — so the *primary* admin path would be invisible; (2) spatie fires its events with the full *requested* set, not the delta, so an event listener logs phantom grants on every idempotent re-assign. A diff is delta-aware (no phantoms) and catches every path. (`config('permission.events_enabled')` stays **false**.)

- **User↔role** (`role_granted` / `role_revoked`): `CreateUser`/`EditUser` diff the roles relationship in `afterCreate`/`afterSave` (capturing the "before" set in `beforeSave`). Department membership goes through `Department::registerMember` / `unregisterMember` / `assignRolesToMembers`, which are idempotent (grant only if the user lacks the role) and self-audit — so re-running the roster on a member-attach neither re-attaches nor logs phantom grants.
- **Role↔permission** (`permission_granted` / `permission_revoked`): `CreateRole`/`EditRole` diff via `AccessControlAudit::logPermissionDiff()`. **Role deletion** (a cascade mass-revoke) is logged by the `DeleteAction->before()` hook (`role_deleted`, listing the holders) before the record is gone.
- **Protected-role guard (who may confer power)**: the **crown-jewel write-everything roles** — `super_admin`, `manager` *and* `mall_admin` (`UserResource::PROTECTED_ROLES`) — may only be granted or revoked by a super_admin. **`mall_admin` was added 2026-08-11, closing a live privilege escalation**: it is seeded as `$managerPerms + imports.execute`, a strict *superset* of `manager`, so protecting `manager` while leaving `mall_admin` grantable protected nothing — any `users.edit` holder (`hr` has it, without `roles.edit`) could grant themselves manager power plus the import right `$managerPerms` deliberately withholds. The list is hand-written, which is how the gap opened; `ProtectedRolesCoverSupersetsTest` now derives the rule from the seeded permission sets and fails the build if any unprotected role covers a protected one. Enforced **post-sync** by `UserResource::enforceProtectedRolesRule()` (called from `CreateUser::afterCreate` / `EditUser::afterSave` after the roles relationship is written — Filament saves the Select from component state, so mutating form data beforehand does *not* hold): for a non-super_admin actor it reverts any add/remove of a protected role (leaving the target's status unchanged) and logs `protected_role_change_blocked`. Everything else — functional/**department** roles + read-only `viewer`/`owner` — stays grantable by any `users.edit` holder (e.g. HR managing staff).
  - **Department roles** are granted by *membership*, not by editing the Role: adding a user to a department (gated by `departments.edit`) confers that department's role (`leasing`/`operations`/`accounting`/`marketing`/`hr`) and removing them revokes it. This is intended (the DEPT-6 model) and is bounded — a department's slug can never be a protected role, so this path can only ever confer a scoped functional role, and every grant/revoke is audited.
- **Only authenticated, human-initiated changes are logged** (`AccessControlAudit::log()` gates on `auth()->check()`): seeding (`migrate:fresh --seed`) and CLI grants have no causer, so they're skipped — keeping the trail meaningful and the test suite deterministic. The audit write is wrapped in a try/catch (advisory — it must never abort the grant it records). Direct pivot manipulation in code (`$user->roles()->detach()`) bypasses these points and is **not** captured.

Database notifications are used for operational events (portal maintenance requests, sales declarations, SLA breaches, audit events) and are scoped per-user inbox (no RBAC-specific notification dispatch rules).

## 8. Extension points — how to change/extend SAFELY

### The dashboard: `App\Support\DashboardLayout`

**One registry decides what every role's dashboard is** — `LAYOUTS` maps a role to an ordered list
of widget classes, `App\Filament\Admin\Pages\Dashboard` composes from it, and
`RoleScopedWidget::canView()` asks it. `DashboardLayoutConformanceTest` fails the build on an empty
role, an unknown role, or a widget in neither a layout nor `NOT_ON_DASHBOARD`.

**To add a widget:** write it with `use RoleScopedWidget` (plus `widgetModule()` if it belongs to a
toggleable module), then name it in the layout of every role that should see it. There is no
"visible by default" — Filament's `discoverWidgets()` registers the whole directory with the panel,
so a widget outside the registry is simply never composed.

**Do NOT** add widgets to `->widgets([...])` in `AdminPanelProvider`. That list publishes to every
role and leaves gating to each widget's own `canView()`, which is precisely how it went wrong.

**Money:** `MONEY_ROLES` is the list of roles that may see collections/receivables figures.
`MallStats` filters its AR and collections cards on it, and `ActionRequired` gates each alert card
on the `{module}.view` permission of the register it links to (`CARD_PERMISSIONS`) — so an
operations user is not told about overdue invoices they cannot open.

*Fixed 2026-07-28.* Before the registry, visibility was thirteen separate `allowedRoles()` lists.
Nobody could read them back by role, and the result was: **six of fifteen roles had a completely
blank dashboard** (owner, marketing, hr, technician, vendor, mall_admin — the last of which is
documented as "a manager for their assigned properties"); `MonthlyCloseStats` shipped with **no
gate at all** and so published the property's invoicing, collections rate, outstanding AR and every
ageing bucket to every role on the panel, HR and marketing included; and a manager's dashboard was
eleven widgets and a 2,900px scroll.

### Account lifecycle — suspend, don't delete

`users.status` is `active` | `suspended` (a string column validated by `User::STATUSES`, never a DB
enum). Suspending is gated on `users.edit` **plus a self-guard** (`UserResource::canSuspend()`) —
locking yourself out of the panel you administer is not recoverable from inside it.

The block lives in `User::canAccessPanel()`, not at the login form, so Filament re-runs it on every
request: suspending someone who is already signed in ends their session at the next page load.
`App\Filament\Admin\Pages\Auth\Login` then tells them **why** — but only after the submitted
password has already verified, so a wrong password on a suspended account still gets the generic
message and nothing is enumerable.

*Why it exists (2026-07-28):* the only way to stop a login was to delete the user, which takes
their name off every record they touched and off the activity log with it. An auditor expects a
leaver's account disabled and kept.

### Creating a property is a privilege, and Filament does not assume that

`RegisterProperty` (the tenant-registration page) is a **privilege boundary**. Filament's stock
`canView()` asks `authorize('create', Asset::class)`, and with no policy registered Filament's
`authorize()` helper defaults to **ALLOWED** — the same "custom things default to permitted" trap
as custom actions.

*Fixed 2026-07-28:* a user with zero accessible properties is routed to that page, so a read-only
`viewer` (the auditor role), a `technician`, even an external `vendor` login was served a working
"Create your first property" form — and `handleRegistration()` then attached them to the new mall
with the pivot role `manager`. **Eleven of fourteen roles could mint themselves a property they
then administered.** Only `super_admin`, `manager` and `mall_admin` hold `assets.create`.

The page now serves two audiences: the form for those who may create, and a "no property assigned
yet — ask an administrator" explanation for everyone else (who previously got a bare 404 with no
hint what to do). The gate is in `handleRegistration()`, not in the form's visibility — visibility is a UI
decision, and the handler is where the authorization belongs regardless of whether hiding
happens to block a given Livewire entrypoint (see the correction at the end of this doc).

### Cloning a role

Building a narrow role meant ticking the right boxes out of ~200 across 40 collapsed sections. In
practice nobody did — they handed out `manager`. The **Clone role** action on the roles table copies
a source role's whole permission set into a new name, which is then narrowed by unticking. The clone
is written to the access-control trail (`AccessControlAudit`) like any other grant, so a role does
not appear in the audit from nowhere fully armed.

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

### Gate custom actions explicitly (they do NOT inherit gating)

`RoleGatedActions` auto-authorizes only the **built-in** Edit/Delete/Create actions a resource generates. A **custom `Action::make('…')`** (row, header, bulk, or relation-manager) defaults to **allowed** — Filament has no permission to infer. **Every custom action that mutates must carry its own `->visible(...)` gate**, e.g.:
```php
Action::make('lock')->visible(fn ($record) => $record->status === 'submitted'
    && auth()->user()?->can('tenant_sales.lock'))
```
Same for **relation-manager** Create/Edit/Delete/Attach/Detach (Filament authorises those only via `isReadOnly()`, and there are no model policies): gate create/edit on the child module's `.edit` permission, **delete on super_admin** (`auth()->user()?->hasRole('super_admin')`), and **department-membership attach/detach on `roles.edit`** (attaching a member *grants* a spatie role, so it needs role-management authority, not merely `departments.edit`). Header/table actions on a list page that should run page-class logic must be routed to the page (`->url(Resource::getUrl('edit', …))`), not left as modals.

### Property scoping covers "All Properties" mode

Scoping is **not** "only when a single property is selected". In **All-Properties** mode a *restricted* user must still be pinned to their assigned set — only super_admin (or an unconstrained user) is genuinely portfolio-wide. Always derive the constraint from `TenantScope::visibleAssetIds()` (returns `null` only for super_admin), not `currentAssetId()` (null in All-mode → no scope → **leak**). This is wired into `TenantScope::applyTo()` (widgets/services), `ScopesViaProperty`/`BypassesScopingOnAll` (resource tables), and the per-resource `getEloquentQuery()` overrides. Cross-property **selects** use `TenantScope::selectable*` helpers (or `modifyQueryUsing` with `visibleAssetIds()`).

## 9. Gotchas, edge cases & recently-fixed bugs

### "All Properties" pseudo-tenant — no longer selectable (property-first UX)
- **As of the property-first change** ([plans/03-remove-all-properties-mode.md](../plans/03-remove-all-properties-mode.md)), "All Properties" is **not offered in the switcher** (`User::getTenants()` returns only real malls) and is **not an accessible tenant** — `canAccessTenant()` refuses the pseudo-asset for everyone, so `/admin/ALL` **404s**. The operator always works inside one real mall.
- The synthetic Asset with `code='ALL'` is still a real DB row (seeded by migration) — **kept** as internal plumbing for a future read-only consolidation surface (Phase B) and as a defensive sentinel. It never appears in property-picker dropdowns (`selectableAssetOptions()` excludes it).
- The **All-mode scoping plumbing below still exists and is still tested** by force-setting the pseudo-asset tenant (`Filament::setTenant`, which bypasses `canAccessTenant()`): when the pseudo-asset is the active tenant, `TenantScope::currentAssetId()` returns null and restricted users are pinned to their assigned set via `visibleAssetIds()` (`whereIn('asset_id', [...])`). On **operational** screens this path is now unreachable — `currentAssetId()` is always a real mall — but keep deriving scope from `visibleAssetIds()` (never `currentAssetId()` alone) so the consolidation surface stays leak-proof when it lands.

### Soft-deleted assets
- Trashed assets are excluded from the property switcher (`User::getTenants()` queries untrashed assets).
- Users assigned to a deleted asset cannot access it (checked in `canAccessTenant()` via `$tenant->trashed()`), preventing URL tampering.

### Jawad owner scoping
- Legal owners are scoped via `asset_owner` table (not `asset_user`), with `ownership_percentage`.
- If an owner is also staff-assigned to a different property, their `accessibleAssets()` union includes both.
- Owners cannot be assigned to "ALL" pseudo-asset (excluded in queries).

**An owner is NOT a `viewer`, and treating it as one was a live leak until 2026-08-11.** The two
roles were seeded from the same "every `.view`" filter — but `viewer` is an internal auditor and
`owner` is the counterparty on the other side of the management contract. Property isolation was
assumed to keep the difference honest. It does not, for two independent reasons:

- **Sixteen models are SHARED** (`#[PortfolioShared]`) and carry no `asset_id`, so "scoped to
  the properties they own" simply does not apply to them. Jawad could read Eltizam's whole supplier
  register — rates and contracts across every mall it operates, including a competing owner's —
  plus every staff account, the SKU catalogue, the chart of accounts and the settings.
- **Property scope is the wrong axis for some of it anyway.** Assigning a member of staff to Jawad's
  mall does not make their salary Jawad's information.

Which modules an owner may read is now a per-module decision recorded in
**`App\Support\OwnerVisibility`** (29 visible / 19 operator-internal, each with a stated reason).
`RolesPermissionsSeeder` filters the owner grant through `OwnerVisibility::allows()` — the one call —
and `OwnerVisibilityConformanceTest` fails the build on a group classified in neither list, so a new
module forces the decision rather than inheriting "the owner sees it". It also fails on a *stale*
entry, because an allow-list alone goes stale in the other direction: ship a property module, forget
to list it, and the owner silently loses oversight they are contractually owed.

Benchmark: Yardi's owner/investor portal exposes financials, rent roll, occupancy, budget-vs-actual,
distributions and **property AP** — so `expenses`/`vendor_bills` stay visible, since the owner
statement charges those costs to them anyway. **One deliberate deviation, stricter than Yardi: the
vendor *register* is withheld.** The owner still sees which vendor did what on their property (it is
on the bill and the work order); the register itself is portfolio-wide.

> **Existing installs must re-run the seeder** — `php artisan db:seed --class=RolesPermissionsSeeder`
> — for the revocation to land. `applyGrants()` clears and rewrites each listed role's pivot rows,
> so the extra permissions are removed rather than merely no longer added.

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

---

## Correction — the `visible()` dispatch premise (2026-07-31)

**Correction 2026-07-31 — the "still dispatchable" half of this was WRONG on the version we ship.** `mountAction()` does check `isDisabled()`, and `CanBeDisabled::isDisabled()` returns true when `isHidden()` does (`vendor/filament/actions/src/Concerns/CanBeDisabled.php:24`), so on **Filament v4.11.8 a `visible()`-only action IS refused at dispatch**. Found by mutation-testing the module-08 fix: deleting CAM's `abort_unless` left `CamActionAuthzTest` fully green — those tests never exercised the gate they describe. **Double-gate anyway**, for a reason that survives the correction: `->authorize()` is a stated intent, while hidden-implies-disabled is an upstream implementation detail that can change in a release and would silently reopen every `visible()`-only write at once. `FilamentActionDispatchContractTest` pins that upstream behaviour so an upgrade that changes it turns the build red; `ActionAuthzConformanceTest` enforces the layer we control.
