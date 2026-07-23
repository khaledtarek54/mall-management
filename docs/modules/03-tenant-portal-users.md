# Tenant Portal — Multi-User Logins

> A tenant company (retailer/Eltizam operator) can have multiple portal login accounts; only those flagged as admin may submit forms/payments; the rest are read-only viewers. Replaces the single "Tenant model = login" scheme and is the web portal surface of req #9.

## 1. Purpose & business context

Before this module, a Tenant company had a single password stored on the `Tenant` model itself; the mobile API (Sanctum) and the web portal both authenticated the same entity. **Req #9** decoupled this: the portal now authenticates a distinct `TenantUser` model (a per-tenant login), while the Tenant record remains the company identity and the mobile API surface.

**Real-world usage:**
- A mall retailer (Tenant = Aroma Coffee Co.) hires two staff members to manage operations.
- The Eltizam operator (admin) creates two portal accounts under that retailer via `/admin` → **Leasing → Tenants** → **Portal Users**: `admin@aroma.test` (flagged admin) and `staff@aroma.test` (non-admin/read-only).
- Both log in to `/portal` and see the same lease, invoices, and maintenance requests for Aroma.
- Only the admin can **submit** maintenance requests and **pay invoices**; the staff member can only view.
- The mobile API still authenticates the Tenant record directly (single company login, unchanged).

**Scoping:** Every portal resource (Invoice, Maintenance Request, Payment, CAM Allocation, Sales Declaration) filters to the logged-in user's company (`tenant_id`), ensuring a company A user never leaks company B's data.

## 2. Domain model

### TenantUser table & model

| Column | Type | Constraints/Defaults | Meaning |
|--------|------|----------------------|---------|
| `id` | bigint | PK, auto-increment | Unique login identifier |
| `tenant_id` | bigint | FK → `tenants`, cascade delete | The company this login belongs to |
| `name` | string | Not null | Display name (e.g., "Jane Doe") |
| `email` | string | Not null, **unique** | Login email (unique across all tenants to avoid password-reset collisions) |
| `password` | string | Not null | Bcrypt hash (Laravel `'hashed'` cast, hashed once on save) |
| `is_admin` | boolean | Default: false | True → may submit/pay; false → read-only |
| `remember_token` | string | Nullable | Session token (Laravel standard) |
| `deleted_at` | timestamp | Nullable | Soft-delete timestamp |
| `created_at` | timestamp | Auto-set | Creation time |
| `updated_at` | timestamp | Auto-set | Last edit time |

**Model:** `App\Models\TenantUser` (implements `FilamentUser`, `CanResetPassword`)

**Relationships:**
- `belongsTo(Tenant)` — the company this user logs into.
- No direct relationship to roles (all permission logic flows through `is_admin` boolean).

### Tenant model additions

| Method/Relation | Purpose |
|-----------------|---------|
| `users(): HasMany` | All portal login accounts for this tenant. |
| `notifyPortal($notification)` | Broadcast a notification to the Tenant record (mobile API) AND every portal user; handles multi-user notifications. |

## 3. Business rules & invariants

### Invariant: Single admin minimum (not enforced at DB level, enforced in UI)
A tenant company should have at least one admin user. The system does not prevent deleting all admins, but the Filament relation manager UI discourages it (and tests verify both admin and non-admin paths).

### Invariant: Email uniqueness across all tenants
`tenant_users.email` has a **unique index** — even though two companies could theoretically use the same email (one person works for two retailers), the password-reset table (`tenant_password_reset_tokens`) is keyed by email (shared with the Tenant model). To avoid collisions, emails must be unique. When resetting a password via email, Laravel looks up the email in the `tenant_users` table; if two users had the same email, the reset token would be ambiguous.

**Test:** `PortalUserManagementScenarioTest::rejects a duplicate email on create (uniqueness)` — attempting to create a second TenantUser with an existing email is rejected by the form validation.

### Invariant: Password hashed exactly once
The `password` column uses Laravel's `'hashed'` cast. When you write `'password' => 'plaintext'` in a model, the cast automatically bcrypts it before save. The Filament form is configured to **dehydrate only if filled** (not send empty strings to save), so:
- **Create:** password is required, hashed once, saved.
- **Edit without password field:** the input is left blank, `dehydrated(false)` → the password column is not touched, original hash stays.
- **Edit with password field:** a new plaintext password is hashed once by the cast and saved, replacing the old hash.

**Test:** `PortalUserManagementScenarioTest::editing WITHOUT a password keeps the existing hash untouched` and `editing WITH a new password rotates it and hashes exactly once` verify this cycle.

### Invariant: is_admin toggles the portal write gate
- `is_admin = true` → `Portal::isAdmin()` returns `true` → resource `canCreate()` returns `true` → forms + actions are visible.
- `is_admin = false` → `Portal::isAdmin()` returns `false` → resource `canCreate()` returns `false` → forms + actions are hidden and hard-gated (403 on page mount).

**Test:** `PortalUserManagementScenarioTest::toggling is_admin on edit flips the portal write access` + `PortalGatingScenarioTest::` suite verify this.

### Invariant: No edit/delete of existing records in portal
Portal resources for invoice, maintenance request, sales declaration all have `canEdit() => false` and `canDelete() => false`. Portal users can only **view and create new**; they cannot edit or delete once submitted.

### Invariant: Notification fan-out to both Tenant and portal users
When an invoice is issued, a maintenance request is submitted, etc., the system calls `Tenant::notifyPortal($notification)`, which:
1. Notifies the Tenant record (mobile API consumers see it via database notifications).
2. Iterates each portal user and notifies them (web portal bell in Filament).

A tenant with zero portal users still notifies the Tenant record (no error); a tenant with many portal users sends the same notification to each.

**Test:** `PortalUserManagementScenarioTest::notifyPortal notifies the Tenant record AND every portal user` and `notifyPortal still notifies a tenant that has NO portal users`.

## 4. Lifecycle / state machine

TenantUser has no explicit status column; the only state is:
- **Active (soft-delete null):** the user can log in.
- **Archived (soft-delete set):** the user is logically deleted and cannot authenticate. Cascades from Tenant deletion.

| Transition | Trigger | Immutable? |
|-----------|---------|-----------|
| Create TenantUser | Operator creates via admin relation manager | No, can edit name/email/password/is_admin |
| Soft-delete TenantUser | Operator clicks "Delete"; super_admin-only | Yes, soft-delete is recorded; restore() is possible but not exposed in UI |
| Cascade-delete on Tenant deletion | Tenant is deleted (soft or hard) | Yes, cascade rule is automatic |

### Backfill migration (2026-06-25)
The migration `2026_06_25_000003_create_tenant_users_table.php` runs once at deployment:
1. Loops through all existing Tenants with `email` and `password` set.
2. Creates one admin TenantUser per tenant with the same email/password/name (or contact_person if available).
3. Uses `insertOrIgnore()` to skip any that already exist (idempotent if re-run).

This ensures existing portal logins keep working immediately after deployment.

## 5. Services, jobs & scheduled commands

**No dedicated services or scheduled commands.** Portal user lifecycle is managed entirely through:
- Filament relation manager (create, edit, soft-delete).
- Model casts and authentication middleware (no separate validation service).
- Notifications use the standard `Tenant::notifyPortal()` method (inline, not a job).

## 6. Filament resources & key fields

### Admin panel: PortalUsersRelationManager

**Location:** `app/Filament/Admin/RelationManagers/PortalUsersRelationManager.php`

**Where it appears:** Under **Leasing → Tenants** → select a tenant → **Edit Tenant** page, in a relation manager tab labeled "Portal Users."

**Permissions:** No explicit Filament policy; accessible to any admin user. **Delete action visibility is role-gated:** `Auth::user()?->hasRole('super_admin')` — only super_admin sees the delete button; managers see only create/edit.

**Form fields:**
| Field | Type | Validation | Notes |
|-------|------|-----------|-------|
| `name` | Text | Required, max 150 | Display name |
| `email` | Email | Required, unique (ignoreRecord on edit), max 255 | Unique across all TenantUsers; password-reset key |
| `password` | Password (revealable) | Dehydrated only if filled; required on create; optional on edit | Blank on edit means keep the old hash |
| `is_admin` | Toggle | None | True = can submit/pay; false = read-only |

**Table columns:**
- `name` — searchable
- `email` — searchable, copyable (for quick login reference)
- `is_admin` — boolean icon
- `created_at` — sortable, human-readable date format

**Default sort:** `is_admin DESC` (admins first in the list).

### Portal panel: none (portal users are read-only from the web portal)

Portal users cannot create or manage other portal users from the `/portal` app; that is an operator-only action in `/admin`. The portal only shows:
- A **Dashboard** with account balance and open maintenance requests.
- Read-only views of the tenant's own **Lease(s)**, **Invoices**, **Payments**, **Maintenance Requests**, **CAM Allocations**, **Sales Declarations**.
- **Create/Edit** forms for Maintenance Requests and Sales Declarations (if admin).
- **Profile editing** via the top-bar avatar (password change, personal details).

**Lease visibility (module 03 MVP pass).** A tenant can now see their **own lease** — a read-only
`LeaseResource` scoped `where('tenant_id', Portal::tenantId())` with a full-terms infolist (reference,
unit, property, status, dates, base rent, service charge, deposit, marketing levy, escalation, and a
percentage-rent section shown only to the tenants it applies to) and a **Download lease** action that
streams the signed document from the private `documents` media collection (`$media->toResponse()`).
Until this, the portal claimed lease visibility it did not have. **Deferred (trigger):** a browsable
**Announcements/Notices** page — operator broadcasts already reach the tenant as an in-app bell
notification (`BroadcastAnnouncement` → `notifyPortal`), so the deliverable *arrives*; a persistent,
scrollable list is polish. *Trigger: a tenant asking to re-read a dismissed notice.*

## 7. Notifications & integrations

### Outbound notifications via Tenant::notifyPortal()

When the system issues an invoice, receives a payment, or changes a maintenance request status, the Tenant record calls:
```php
$tenant->notifyPortal(new InvoiceIssuedNotification($invoice));
```

This broadcasts to:
1. **The Tenant record** (mobile API consumers; stored in `notifications` table, polled by the mobile app).
2. **Each TenantUser** (web portal consumers; displayed in the Filament bell icon, polled every 30 seconds).

**Notifications fired (non-exhaustive):**
- `InvoiceIssuedNotification` — a new invoice for one of the tenant's leases.
- `PaymentReceivedNotification` — a payment was applied to the tenant's account.
- `MaintenanceRequestStatusChangedNotification` — operator updated the maintenance request status.
- `TenantSalesDeclarationLockedNotification` — the tenant's sales declaration was locked after the reporting period closed.

### Integrations

**Paymob (payment gateway):**
- Invoice view page has a **Pay Now** action (visible to admin only, and only if `config('integrations.paymob.enabled')` is true and invoice balance > 0).
- Clicking **Pay Now** calls `PaymobPaymentInitiator::start()`, which initiates an iframe session at Paymob.
- After payment, Paymob webhook invokes the capture handler, which creates a Payment record, updates the invoice, and calls `notifyPortal()`.

**Demo mode (when Paymob is disabled):**
- A **Pay Demo** action is shown instead, which simulates a payment: creates a Payment, marks invoice as paid, and calls `notifyPortal()`.

## 8. Extension points — how to change/extend SAFELY

### Adding a new portal resource (e.g., a new form tenants must fill)

1. **Create the model** (in `app/Models/`):
   - Add a `tenant_id` foreign key (or join via a lease if multi-unit; see `TenantSalesDeclaration`).
   - Run migration to create the table.

2. **Create the Filament resource** (in `app/Filament/Portal/Resources/`):
   - Extend `Resource`.
   - In `getEloquentQuery()`, filter by `Portal::tenantId()`:
     ```php
     return parent::getEloquentQuery()->where('tenant_id', Portal::tenantId());
     ```
   - If multi-unit/via-lease, use a `whereHas()` instead:
     ```php
     return parent::getEloquentQuery()
         ->whereHas('lease', fn ($q) => $q->where('tenant_id', Portal::tenantId()));
     ```
   - Set `canCreate()` to `return Portal::isAdmin();` (read-only if not admin).
   - Set `canEdit()` and `canDelete()` to false (never allow edit/delete in portal).
   - Register the resource in `PortalPanelProvider::discoverResources()`.

3. **Test the resource:**
   - Add a test in `tests/Feature/Scenarios/PortalMultiUserScopingScenarioTest.php` (parameterized by admin/non-admin) to verify scoping.
   - Add a test in `tests/Feature/Portal/TenantUserGatingTest.php` to verify the `canCreate()` gate.
   - Test that a company A user never sees company B's records.

4. **Wire up notifications (if needed):**
   - After creating/updating the resource, call `$tenant->notifyPortal(new YourNotification($record))` in the action or observer.
   - Test that both the Tenant record and each TenantUser receive the notification.

### Adding a new write-capable action/form to an existing portal resource

Example: allow admins to upload a document to an invoice.

1. **In the resource**, add a Form component or Header Action that is visible only to admins:
   ```php
   ->visible(fn () => Portal::isAdmin())
   ```
   This prevents non-admins from seeing and mounting the form.

2. **In the action handler**, validate and save the record, then call `notifyPortal()` if needed:
   ```php
   $invoice->update($data);
   $invoice->tenant->notifyPortal(new InvoiceUpdatedNotification($invoice));
   ```

3. **Test:**
   - Verify the action is hidden for non-admin (via `assertActionHidden`).
   - Verify the action succeeds and notifies both Tenant and portal users (admin).
   - Test that a non-admin cannot POST to the endpoint if they guess the URL (403 via Filament's `authorizeAccess()`).

### Promoting a non-admin to admin (or vice versa)

1. **Edit the TenantUser** via the relation manager.
2. Toggle the **Portal Admin** field.
3. Save.
4. The user's next browser action checks `Portal::isAdmin()` and shows/hides forms accordingly.

**No migration or service call needed** — the change is immediate because `Portal::isAdmin()` reads the current `is_admin` flag on every page load.

### Changing the password-reset flow

Currently, password reset is built into the Filament profile editor (avatar → **Edit Profile**) and the login page (**Forgot Password**).

- The Filament panel is configured with `.passwordReset()` → enables the "Forgot Password" link on the login page and the email-based reset flow.
- The profile editor (`.profile(isSimple: false)`) allows users to change their own password from the top bar.
- Reset tokens are stored in `tenant_password_reset_tokens` table (shared with the Tenant model; keyed by email).

To add custom reset logic, intercept the password-reset notification:

```php
// In a service provider or listener:
Event::listen('Illuminate\Auth\Events\PasswordReset', function (PasswordReset $event) {
    if ($event->user instanceof TenantUser) {
        // Custom logic, e.g., log to audit trail
    }
});
```

### Multi-step tenant admin workflows (future)

If the business later requires role-based permissions within a tenant (e.g., "Accounting Officer", "Maintenance Staff"), do NOT add roles to TenantUser. Instead:

1. Create a separate `TenantUserRole` pivot table.
2. Add a `roles(): BelongsToMany` relation to TenantUser.
3. Update `Portal::isAdmin()` to check roles, or create `Portal::canXyz()` helpers per permission.
4. Update all `visible()` and `canCreate()` gates in portal resources to check the new role.
5. Keep the `is_admin` boolean as a **shortcut** for "has any admin role" (avoid logic duplication).

**Do NOT break:** the scoping in `getEloquentQuery()` (always filter by tenant_id), the soft-delete cascade, or the password-hashing cast.

## 9. Gotchas, edge cases & recently-fixed bugs

### Email uniqueness and password-reset collisions

TenantUser emails **must be unique** across all tenants because the password-reset flow uses email as the lookup key. If two users in different companies shared an email, the reset token would be ambiguous. The migration adds a unique index on `email`; Filament validation enforces it on create/edit.

**Gotcha:** If a user accidentally creates a TenantUser with an email address that matches a company owner's email (rare), the password-reset table lookup will return the TenantUser, not the Tenant. This is correct behavior (TenantUsers take precedence), but it can be confusing in support scenarios. Recommend a **unique email policy** across the entire system (staff, operators, tenants).

### Soft-delete cascade and reactivation

When a Tenant is soft-deleted, all its TenantUsers are soft-deleted too (via the FK constraint `cascadeOnDelete()`). If the Tenant is restored, the users are **not automatically restored** (soft-deletes are independent).

**Implication:** If you restore a Tenant, you must manually restore its portal users:
```php
$tenant->restore();
$tenant->users()->onlyTrashed()->restore();
```

Currently, there is no UI for restoring soft-deleted tenants or users, so this is rarely hit. If restore functionality is added later, remember to cascade.

### Notifications and empty user lists

The `notifyPortal()` method loops through `$this->users`. If the relation is not eager-loaded, it queries the DB for every notification. If the list is empty, the loop simply doesn't execute (no error).

**Implication:** A tenant with **zero portal users** still receives a notification on the Tenant record (mobile API surface). This is intentional — the single-user mobile app still works. But if a Tenant is created before any TenantUser is added, notifications will only appear on the Tenant record until an admin creates a portal user.

**Test:** `PortalUserManagementScenarioTest::notifyPortal still notifies a tenant that has NO portal users (no error)` ensures this path doesn't break.

### Backfill migration idempotency

The migration uses `insertOrIgnore()`, so it is safe to re-run (e.g., on a rollback + re-apply). However, if you re-run it and a TenantUser's password has been changed since the first migration, the new user's password will still be the old Tenant password (because `insertOrIgnore()` skips on conflict).

**Implication:** Do not re-run this migration in production after initial deployment. If you need to reset portal passwords, do so manually via the Filament UI.

### Password field visibility and Filament state

The `password` field in the relation manager is revealable (user can toggle visibility). The `dehydrated(fn ($state) => filled($state))` logic means:

- If the user leaves it blank, it is not sent to save (correct).
- If the user fills it with "•••••" (the show/hide toggle), the string "•••••" is sent, which bcrypt will hash (incorrect, but Filament's input clearing should prevent this).

**Test:** `PortalUsersRelationManagerTest::creates a portal user under a tenant from the relation manager (password hashed once)` verifies create behavior; edit tests use empty + filled paths.

### Portal vs. Admin panel and soft-delete

The portal is served from Filament's **portal panel** (`PortalPanelProvider`). The admin panel and portal panel are separate Filament panels with separate middlewares and contexts.

- A TenantUser can access the **portal** panel only (guard: `'portal'`).
- An admin User can access the **admin** panel only (guard: `'web'`, implicit).
- Soft-deleted TenantUsers cannot access the portal (the `canAccessPanel()` method in TenantUser does not check soft-delete, but Laravel's auth middleware won't find a deleted user).

**Gotcha:** If you soft-delete a TenantUser and the user is currently logged in, their session is still valid until it expires or they log out. Reloading the page will not re-authenticate them. To force logout, clear the session cookie or use a middleware hook (not currently implemented).

### Config-driven gating (Paymob enabled/disabled)

The **Pay Now** action visibility depends on `config('integrations.paymob.enabled')`. In testing, we toggle this via `config(['integrations.paymob.enabled' => true/false])`.

**Gotcha:** If the config is changed in production mid-session (e.g., Paymob integration is disabled for an outage), the portal page must be reloaded for the action to disappear. Filament does not re-evaluate visibility on every keystroke.

## 10. Tests & related modules

### Test files

| Test file | Coverage |
|-----------|----------|
| `tests/Feature/PortalUsersRelationManagerTest.php` | Create, password hashing (once), email uniqueness, table scoping, delete visibility |
| `tests/Feature/Scenarios/PortalUserManagementScenarioTest.php` | Password edit (keep/rotate), is_admin toggle, email uniqueness, table scoping, delete visibility (super_admin vs. manager), notifyPortal() fan-out, empty user list |
| `tests/Feature/Portal/TenantUserGatingTest.php` | canCreate/canViewAny gates: admin (true/true), non-admin (true/false) |
| `tests/Feature/Scenarios/PortalGatingScenarioTest.php` | canCreate across resources, Pay Now/Pay Demo visibility (admin vs. non-admin), Paymob config toggle, hard-gate on create page (403 for non-admin) |
| `tests/Feature/Scenarios/PortalMultiUserScopingScenarioTest.php` | Cross-tenant scoping (A never sees B) across all portal resources; Portal::tenantId/tenant/isAdmin/user helpers; two users same tenant see same records; logged-out resolves null |

**All tests pass:** 1043 passing tests (as of 2026-06-27).

### Related modules

- **02-tenants.md** — The Tenant model, company identity, Jawad owner vs. Eltizam operator.
- **05-leases.md** — Leases scoped to tenants; portal resources inherit tenant scoping via leases.
- **06-invoices.md** — Invoices use `notifyPortal()` on issue; Pay Now/Demo actions gate on `Portal::isAdmin()`.
- **07-maintenance-requests.md** — Maintenance requests scoped to tenant_id; canCreate gates on `Portal::isAdmin()`.
- **08-sales-declarations.md** — Sales declarations scoped to lease.tenant_id; canCreate gates on admin.
- **09-payments.md** — Payments scoped to tenant_id; visible to portal users.
- **10-cam-allocations.md** — CAM allocations scoped via lease.tenant_id; visible to portal users.
- **Mobile API (Tenant Sanctum)** — Still authenticates the Tenant record directly (not TenantUser); separate session from portal.

---

**Last updated:** 2026-06-27  
**Req reference:** Requirement #9 (multi-user tenant portal)
