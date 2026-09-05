# Departments

> The fixed organizational backbone of the operator's ERP — 5 seeded departments (HR, Marketing, Accounting, Leasing, Operations) that own resources, staff, and inter-departmental workflows.

## 1. Purpose & business context

**Who and what:** An Eltizam (mall operator) is organized into **departments**, each a self-contained ERP module. The five core departments model real business functions: HR (staff), Marketing (campaigns/budget), Accounting (invoices/payments/CAM), Leasing (units/tenants), Operations (maintenance). Each department:
- Owns resources (invoices belong to Accounting; maintenance requests route to Operations or Leasing).
- Maintains a roster of staff with tenure & role labels.
- Controls access via RBAC roles (each department = one named spatie role: `accounting`, `leasing`, `operations`, `marketing`, `hr`).
- Can message other departments (e.g., Operations → Accounting: "Please invoice for this maintenance").

**Why it exists:** The ERP is **department-oriented** — every feature is scoped to a department. This allows:
- **Org flexibility:** new departments can be added (as seeders) without migrations.
- **Sidebar grouping:** the admin nav is grouped by department, so staff see only their module.
- **Hybrid access:** RBAC roles (permission axis) stay global and additive; departments (org axis) control which resources a role can see.
- **Clear ownership:** every major entity (invoice, lease, maintenance request) is assigned to the responsible department.

## 2. Domain model

| Table | Model | Key columns | Meaning |
|-------|-------|------------|---------|
| `departments` | `Department` | `id` (int), `name` (str 150), `slug` (str unique), `code` (str 20, nullable unique), `description` (text nullable), `asset_id` (FK nullable→Asset), `head_user_id` (FK nullable→User), `is_active` (bool, default true), `sort_order` (smallint, default 0), `metadata` (json nullable), `created_at`, `updated_at`, `deleted_at` (soft) | The department record. `slug` auto-generated from name (e.g., "Marketing" → `marketing`); unique slug is the role name. `asset_id = null` means operator-wide (global); set value scopes to one property. `head_user_id` is an optional single department lead. Indexed: `(is_active, sort_order)`, `asset_id`. Logged via spatie/activitylog. |
| `department_user` | Pivot | `id`, `user_id` (FK→User, cascade), `department_id` (FK→Department, cascade), `role` (str 100 nullable, e.g., "Lead", "Coordinator"), `assigned_at` (date nullable), `ended_at` (date nullable), `notes` (text nullable), `created_at`, `updated_at` | Membership roster. **Unique:** `(user_id, department_id)`. Indexed: `department_id`. Mirrors the `asset_user` staff pivot pattern: a free-form label (`role` here, `title` on `asset_user`) and tenure dates. Attaching the same member twice is refused in both layers — see module 01's *A party is attached ONCE*; this manager had the identical defect and it was fixed in the same change. |

**Relationships:**
- `Department.asset()` — BelongsTo (nullable).
- `Department.head()` — BelongsTo User (nullable).
- `Department.members()` — BelongsToMany User via `department_user`, pivot includes `role`, `assigned_at`, `ended_at`, `notes`.
- `User.departments()` — BelongsToMany Department (inverse).

## 3. Business rules & invariants

| Rule | Formula/threshold | Test guard |
|------|-------------------|-----------|
| **Fixed reference set** | Only 5 departments exist (seeded): HR, Accounting, Leasing, Operations, Marketing. No create/delete UI. | `DepartmentTest::seeds five core departments`, `DepartmentResourceTest::locks create/delete`, `DepartmentScenarioTest::locks fixed set for every role` |
| **Slug uniqueness & auto-generation** | If slug is empty at create, auto-generate from name (lowercased, slugified). If a collision exists (even soft-deleted), append `-2`, `-3`, etc. Slug is unique across all. | `DepartmentTest::auto-generates unique slug` |
| **Global vs. property-scoped** | `asset_id = null` ⟹ global (all properties). `asset_id = {id}` ⟹ scoped to one property. A department can be global or property-scoped, not both. | `DepartmentTest::treats null asset as global` |
| **Membership uniqueness** | A user can belong to a department at most once (composite PK). `syncWithoutDetaching()` ensures idempotency. | `DepartmentMembershipTest::attaches staff`, `DepartmentScenarioTest::registering same user twice is idempotent` |
| **registerMember() is atomic** | Adds user to pivot + grants the department role (spatie). One call does both. | `DepartmentRolesTest::registering a user grants the role`, `DepartmentScenarioTest::registering a user grants permission set` |
| **unregisterMember() is atomic** | Removes from pivot + revokes the department role. Other department roles (if user is in multiple depts) are kept. | `DepartmentRolesTest::unregistering removes role and membership`, `DepartmentScenarioTest::unregistering removes only the dept role, leaving others` |
| **Role-per-department naming** | Each department's spatie access role is the **slug** (e.g., `operations`, `accounting`, `marketing`). `Department.roleName()` returns the slug. | `DepartmentRolesTest::maps each department to its role` |
| **Inter-department messages** | A message is sent to all members of a target department except the sender (even if sender is a member of that dept). If department is empty, send nothing and return count=0. | `DepartmentMessageTest`, `DepartmentScenarioTest::fan-out excluding sender`, `DepartmentScenarioTest::sends nothing if empty` |
| **Message label format** | Label is `"{sender.name} ({sender's first department name})"`. If sender has no departments, label is just the name. | `DepartmentScenarioTest::labels the message with sender name and originating dept` |
| **Soft-delete cascade** | When a department is soft-deleted, the pivot rows remain (they are not cascade-deleted). Hard-delete cascades. | `DepartmentMembershipTest::cascades pivot when hard-deleted` |
| **selectableOptions() scoping** | Returns `id => name` of active departments visible to the current user. Super_admin sees all active depts. Scoped users see global depts + depts of their accessible properties. | `Department.selectableOptions()` code / tested in maintenance redirect form |

## 4. Lifecycle / state machine

Departments are **reference data**, not workflows. They have no lifecycle transitions.

| Status | Meaning | Transition to |
|--------|---------|---------------|
| (implied: always exists) | A seeded, read-only department. `is_active = true` at seed. | — |

**Immutability:**
- `name`, `code`, `slug`, `asset_id` are **disabled in the form** (read-only during edit).
- `is_active`, `head_user_id`, `description`, `sort_order` are **editable**.
- **No create, no delete** — UI enforces `DepartmentResource::canCreate() = false` and `canDelete() = false` for all roles.

## 5. Services, jobs & scheduled commands

| Service/Command | Signature | Does | Idempotent? | Transaction? |
|---|---|---|---|---|
| **DepartmentMessageService::send()** | `send(Department $to, User $from, string $body): int` | Resolves sender's first department name; sends `DepartmentMessageNotification` to all members of `$to` except `$from`; returns count of recipients notified. | Yes (fan-out is stateless). | No explicit txn; notification is async-queued. |
| **Department::registerMember()** | `registerMember(User $user, array $pivot = []): void` | Attaches user to pivot with optional `role`, `assigned_at`, etc. via `syncWithoutDetaching()`. Then grants the department's spatie role. | Yes (sync is idempotent; re-granting a role is idempotent). | No explicit txn. |
| **Department::unregisterMember()** | `unregisterMember(User $user): void` | Detaches user from pivot. Then revokes the department role. | Yes (detach + remove-role are idempotent). | No explicit txn. |
| **Department::assignRolesToMembers()** | `assignRolesToMembers(): void` | Loads all current members and grants the department role to each. | Yes (assigning a role that's already held is no-op). | No explicit txn. |

## 6. Filament resources & key fields

### DepartmentResource (`app/Filament/Admin/Resources/Departments/DepartmentResource.php`)

**Pages:**
- `ListDepartments` — table of all active departments; no header actions (no create).
- `EditDepartment` — edit form + `DepartmentMembersRelationManager` tab.

**Form fields (DepartmentForm):**
| Field | Type | Rules | Notes |
|-------|------|-------|-------|
| `name` | TextInput | disabled | Read-only; seeded at create. |
| `code` | TextInput | disabled | Read-only; seeded at create. |
| `asset_id` | Select (relationship) | optional | Scopes department to a property; null = global. Searchable, preloaded. |
| `head_user_id` | Select (relationship) | optional | Department lead (User). Searchable, preloaded. |
| `is_active` | Toggle | — | Default true. Hides inactive depts from sidebar. |
| `sort_order` | TextInput | numeric, default 0 | Controls nav sort order. |
| `description` | Textarea | — | Optional free-form text; 3 rows. |

**Table columns:**
- `name` (bold, searchable)
- `code` (badge, gray)
- `head.name` (nullable)
- `asset.name` (nullable, shows "Global" if null)
- `is_active` (boolean icon)
- `sort_order` (sortable, default sort)

**Record actions:**
- **Message** — opens a modal, prompts for `body`, calls `DepartmentMessageService::send()`, shows success toast with recipient count.
- **Edit** — visible if user has `departments.edit`.

**No delete/bulk-delete/trashed filter** — departments are reference data.

### DepartmentMembersRelationManager

**Title:** "Department Members" (or reused "Asset Staff").

**Form (attach/edit):**
- `role` (str 100, helper text).
- `assigned_at` (date picker, default now).
- `ended_at` (date picker, optional).
- `notes` (textarea, 2 rows).

**Table columns:**
- `name` (searchable, bold)
- `email` (copyable)
- `roles.name` (badge, translated).
- `pivot.role` (nullable).
- `pivot.assigned_at` (date formatted as d/m/Y).

**Attach action:**
- Filters users to exclude `owner` role (admin-panel staff only).
- Calls `Department::assignRolesToMembers()` after attach (grants the dept role).

**Record actions:**
- **Edit** — edits pivot fields.
- **Detach** — removes from pivot and revokes the department role.

**Sort:** default `pivot_assigned_at desc`.

### RBAC permissions

**Permission:** `departments.view`, `departments.create` (gated to false), `departments.edit`, `departments.delete` (gated to false).

**Role grants:**
- `super_admin` — all.
- `manager` — view, create (gated), edit.
- `viewer` — view only.

**Department-named roles (e.g., `accounting`, `leasing`):**
- Each grants all permissions scoped to that department's resources (e.g., `accounting` grants `invoices.create`, `payments.view`).
- Granted to users via `Department::registerMember()`.

**TenantScope:** DepartmentResource is NOT scoped to a property (`isScopedToTenant = false`). All users see the same department list.

## 7. Notifications & integrations

### DepartmentMessageNotification

**Fired by:** `DepartmentMessageService::send()` → `Notification::send($recipients, new DepartmentMessageNotification($body, $fromLabel))`.

**Via:** `database` (bell entry; queued).

**Payload:**
```php
[
    'type' => 'department_message',
    'title' => 'Message from ' . $fromLabel,
    'body' => $body,
    'icon' => 'heroicon-o-chat-bubble-left-right',
    'color' => 'info',
    'url' => null,
    'format' => 'filament',
    'duration' => 'persistent',
]
```

**Recipients:** All members of the target department except the sender.

**Use case:** Cross-departmental coordination (e.g., Operations → Accounting: "Please invoice for job #42").

## 8. Extension points — how to change/extend SAFELY

### Adding a new department
1. **Add a row to `DepartmentSeeder::CORE`** with `name`, `code`.
2. **Create a matching spatie role** with the department's slug as the name (e.g., `services` → role `services`). Add it to `RolesPermissionsSeeder`.
3. **Grant permissions to the new role** in `RolesPermissionsSeeder` (e.g., `$services->givePermissionTo([...]))`).
4. **Add i18n keys** in `lang/{locale}/admin/navigation.php`:
   - `admin.resources.department.singular/plural` (if using a unique label).
   - `admin.groups.{slug}` (e.g., `admin.groups.services`).
5. **Re-seed** the database or run migrations if needed.
6. **No code changes needed** — the Department model, Filament resource, and RBAC system are data-driven.

### Scoping a department to a property
1. **Edit the department record** (or in a seeder) and set `asset_id = {property_id}`.
2. **Verify `selectableOptions()`** respects the scope (it does via `TenantScope::visibleAssetIds()`).
3. **No code changes needed** — the model and form already support nullable `asset_id`.

### Adding a new resource to a department
1. **Create the Filament resource** as usual.
2. **Set `getNavigationGroup()` to return `__('admin.groups.{department_slug}')`** (e.g., `__('admin.groups.leasing')`).
3. **Add the resource to the role's permission set** in `RolesPermissionsSeeder` (e.g., grant `leases.view` to the `leasing` role).
4. **Do NOT break invariant:** ensure the resource's view is scoped to members of that department (check `RoleGatedActions` trait or auth guards).

### Changing who can send inter-department messages
1. **Currently:** any user with `departments.view` can open the message modal.
2. **To restrict:** in `DepartmentResource::table()`, conditionally set visibility on the Message action (e.g., only `super_admin`, or only users in the `accounting` department).
3. **To widen:** ensure the originating department is set correctly via `$from->departments()->first()` in `DepartmentMessageService::send()`.

### Modifying the message payload or delivery
1. **Change the `DepartmentMessageNotification` constructor** to accept additional fields (e.g., `$priority`, `$attachments`).
2. **Update `DepartmentMessageService::send()`** to pass those fields.
3. **Update the form/action** in `DepartmentsTable` to gather the new fields.
4. **Test:** add a test case in `DepartmentMessageTest` or `DepartmentScenarioTest` that verifies the new payload.

### Linking a model to a department
Examples: `TenantRequest` has `department_id`; `Invoice` is owned by the Accounting department.

1. **Add `department_id` FK column** to the model's migration (cascade or set-null, as appropriate).
2. **Add `belongsTo` relation** on the model.
3. **Add `hasMany` relation** on the Department model (optional, for inverse queries).
4. **In the Filament form,** include a `Select` field pointing to `Department::selectableOptions()` (scopes the picker to user's visible departments).
5. **In queries,** scope to the user's department(s) if needed (e.g., maintenance requests for Operations staff).

### Do NOT break invariants:

- **Do not allow creating/deleting departments** via UI — enforce the fixed set at the resource level (`canCreate()`, `canDelete()` remain `false`).
- **Do not grant a department role outside `registerMember()`** — always pair membership with the role grant (use `Department::registerMember()`, not manual `members()->attach()` + manual role grant).
- **Do not change a department's slug** — it is the role name; changing it would orphan user permissions.
- **Do not soft-delete a department** — only hard-delete (or `restore()`). Soft-deletes are for data safety, not org changes; test this if you add soft-delete support.

## 9. Gotchas, edge cases & recently-fixed bugs

### Membership idempotency
`syncWithoutDetaching()` is used in `registerMember()` to allow calling the same registration twice without duplicating the pivot row or losing tenure data. However, **pivot fields supplied on the second call overwrite the first call's values**. If you want to preserve assigned_at from the first registration, only pass pivot data if the user is not already a member.

### Message sender labeling
The message label uses `$from->departments()->first()` — it picks the user's **first** department alphabetically (due to no explicit order). If the sender has no departments, the label is just the user's name. This can be unintuitive if a user is in multiple departments and you expect a specific one to appear. If this is an issue, pass the source department explicitly to `DepartmentMessageService::send()`.

### Empty department messaging
If the target department has no members (or only the sender), `send()` returns 0 and fires no notification. This is correct behavior but can be silent. The action toast shows "0 recipients," so the operator knows.

### Soft-delete behavior
When a department is **soft-deleted** (via `deleted_at`), the pivot rows remain intact. When a user is listed, soft-deleted departments still appear (because `belongsToMany` doesn't filter soft-deletes by default). If you need to exclude soft-deleted departments from user views, add `.withoutTrashed()` to the query.

### Pivot cascade on hard-delete
Only a **hard-delete** (`forceDelete()`) cascades the pivot. Soft-delete does not. This is correct (keeps the audit trail).

### Property-scoped department membership
The `department_user` pivot does **not** have an `asset_id` column. A user's membership in a property-scoped department (e.g., a Operations department for property HW) is stored globally; the property scoping is implicit via the department's `asset_id`. If a user belongs to an Operations department scoped to property HW, they have access to all Operations resources at property HW, but not at other properties. This design assumes a user is not in the "same" department at multiple properties (e.g., not in both HW/Operations and DFC/Operations as separate memberships).

### Navigation grouping and translation keys
The sidebar groups resources by `getNavigationGroup()` → `__('admin.groups.{slug}')`. Every seeded department's slug **must** have a matching translation key in `lang/en/admin/navigation.php` (`groups.*`). If you add a department and forget the translation key, the group label will echo the untranslated key (e.g., "admin.groups.services").

### Circular dependency with RoleGatedActions
The `RoleGatedActions` trait checks `Auth::user()->can()` based on the resource's role-name. If the Department role isn't assigned (because `registerMember()` was skipped), the user sees "Not authorized" even if they are in the pivot. Always use `registerMember()` to ensure both the pivot and the role are in sync.

### Missing asset in selectableOptions()
If a department is scoped to an asset that the current user cannot access (based on `TenantScope`), it is excluded from `selectableOptions()`. This is intentional (info security), but can be confusing if the department exists in the database but doesn't appear in a form picker.

## 10. Tests & related modules

**Test files:**
- `tests/Feature/DepartmentTest.php` — slug generation, global flag, seeding idempotence, RBAC permissions.
- `tests/Feature/DepartmentMembershipTest.php` — pivot attachment, inverse relation, cascade behavior, relation-manager rendering.
- `tests/Feature/DepartmentRolesTest.php` — role-per-department naming, permission grants via role, `registerMember()` / `unregisterMember()`.
- `tests/Feature/DepartmentMessageTest.php` — message sending, sender exclusion, recipient count.
- `tests/Feature/DepartmentResourceTest.php` — resource permissions, tenant scoping, Livewire list rendering.
- `tests/Feature/Scenarios/DepartmentScenarioTest.php` — multi-member registration, fixed-set lock, messaging fan-out, nav-group alignment, role persistence.
- `tests/Feature/TenantRequestDepartmentTest.php` — department assignment/redirect on tenant requests.

**Related modules:**
- `docs/modules/02-tenants.md` — tenant model & portal.
- `docs/modules/04-leases.md` — leases & units owned by Leasing dept.
- `docs/modules/05-billing-invoices.md` — invoices owned by Accounting dept.
- `docs/modules/06-payments.md` — payments routed through Accounting.
- `docs/modules/08-cam.md` — CAM processed by Accounting.
- `docs/FUNCTIONAL-REQUIREMENTS.md` — §5 (DEPT requirements) & §1 (ERP framing).

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Department` | **Only while unreferenced** — blocked by `members` | move its members first, then delete the empty department |

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-108


### A derived slug never lands on an existing role
A department's slug IS its access-role name, and `Department::booted()` mints that role with the department — deliberately with no permissions, because membership grants the department SCOPE MARKER and what the role may do stays an act on the roles screen. Until 2026-09-04 the slug was deduped against DEPARTMENTS only, so a department the operator named "Manager" took the slug `manager` and `Role::findOrCreate()` FOUND the functional role instead of minting an empty one: attaching a member ran `assignRole('manager')` — 225 permissions on the QA books, and a role `UserResource::PROTECTED_ROLES` refuses to let a non-super_admin grant on the user form — while DETACHING ran `removeRole('manager')` and stripped the real role from an account that held it in its own right, silently. `Str::slug()` reaches six seeded roles this way (Manager, Viewer, Owner, Technician, Coordinator, Vendor); the underscore names, `super_admin` and `customer_service`, slug to `super-admin`/`customer-service` and cannot be hit. The loop now also asks the roles TABLE — never a list of role names, so the operator's own custom roles are covered by the same clause — and it does so only outside `Department::ADOPTABLE_ROLES`, the five seeded core departments whose role reuse is the documented intent. A caller that STATES a slug keeps it, which is why `DepartmentSeeder` (it passes `slug` in its `updateOrCreate` key) is untouched. `ADepartmentNeverAdoptsAFunctionalRoleTest` holds both directions and fails when `ADOPTABLE_ROLES` and the seeder drift apart.

