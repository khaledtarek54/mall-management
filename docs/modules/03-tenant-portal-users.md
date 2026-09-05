# Tenant Portal — Multi-User Logins

> A tenant company (retailer/Eltizam operator) can have multiple portal login accounts; only those flagged as admin may submit forms/payments; the rest are read-only viewers. Replaces the single "Tenant model = login" scheme and is the web portal surface of req #9.

> **⚠️ Fixed 2026-08-11 — the portal's password reset ran against the ADMIN user table.**
> `PortalPanelProvider` called `->passwordReset()` without `->authPasswordBroker(...)`, so Filament
> resolved `Password::broker(null)`, which `config/auth.php:21` defaults to `users` →
> `App\Models\User`. Two live consequences: **a `TenantUser` could never reset their password** —
> their email isn't in `users`, so the page said "we can't find a user with that email address",
> and the feature that exists to remove the operator round-trip *was* the operator round-trip — and
> **an operator's email typed into the public portal form would mail that admin a genuine reset
> link built for the portal panel**, because `User::canAccessPanel()` fell through to `true` for
> any panel that wasn't `admin`, so Filament's own guard never fired. The purpose-built
> `tenant_users` broker (`config/auth.php:129-134`) existed the whole time and nothing used it.
>
> Both halves are closed: the panel declares `->authPasswordBroker('tenant_users')`, and
> `canAccessPanel()` returns `false` for `portal` explicitly rather than by omission. Pinned by
> `PortalPasswordResetBrokerTest`, which asserts the broker resolves a `TenantUser`, does **not**
> resolve an operator, and — the control that gives the second assertion meaning — that the default
> broker *does*.
>
> **The mobile half was broken too, separately**, so at go-live *neither* surface could recover a
> password. `TenantResetPasswordNotification` links to `config('app.mobile_reset_url')`, which falls
> back to `APP_URL/reset-password` — not a route in this application — and `APP_MOBILE_RESET_URL`
> was absent from `.env.example`. `atriom:health` now fails in production while it is unset. The two
> flows are genuinely different and must not be merged: the portal resets a **`TenantUser`** (a
> person), the mobile API resets a **`Tenant`** (the company), on different brokers.

## 1. Purpose & business context

Before this module, a Tenant company had a single password stored on the `Tenant` model itself; the mobile API (Sanctum) and the web portal both authenticated the same entity. **Req #9** decoupled this: the portal now authenticates a distinct `TenantUser` model (a per-tenant login), while the Tenant record remains the company identity and the mobile API surface.

**Real-world usage:**
- A mall retailer (Tenant = Aroma Coffee Co.) hires two staff members to manage operations.
- The Eltizam operator (admin) creates two portal accounts under that retailer via `/admin` → **Leasing → Tenants** → **Portal Users**: `admin@aroma.test` (flagged admin) and `staff@aroma.test` (non-admin/read-only).
- Both log in to `/portal` and see the same lease, invoices, and maintenance requests for Aroma.
- Only the admin can **submit** maintenance requests and **pay invoices**; the staff member can only view.
- The mobile API still authenticates the Tenant record directly (single company login, unchanged).

**Scoping:** Every portal resource (Invoice, Maintenance Request, Payment, CAM Allocation, Sales Declaration) filters to the logged-in user's company (`tenant_id`), ensuring a company A user never leaks company B's data.

**Scoping to the company is not the same question as what that company may SEE (2026-08-16).** The
invoice table filtered on `tenant_id` correctly and still showed **draft** invoices — the column's
> **⚠️ A DRAFT lease was visible to the tenant (fixed 2026-09-02).** The portal's `LeaseResource`
> scoped by `tenant_id` alone — which answers *whose row is this* and not *has it been put to them*
> — so a retailer read their own rent, term and deposit off terms still being written, and would
> reasonably treat them as settled. Registered in `App\Support\TenantVisibility::HIDDEN` rather
> than fixed with a `whereNotIn` on the resource: that registry is the ONE definition, and the
> hand-rolled version would not have covered `LoginTenantAction`, which lists leases from a
> different query and was leaking the same drafts to the **mobile login picker** — id, mall, unit
> number and term dates. *The portal and `/api/v1` are the same surface with different renderers.*
>
> **`pending_approval` is deliberately NOT hidden**, and the reasoning is the useful part. It reads
> like "not agreed yet", but twelve places treat it as a LIVE tenancy: it may be terminated, given
> rent relief, extended, re-priced, space-changed, take a CAM estimate, hold a parking bay *and mark
> it off-market*, it makes the unit `reserved`, and it counts as committed revenue. Nobody grants
> rent relief on terms nobody agreed — so hiding it would leave a retailer holding a bay under a
> lease they cannot see. (`PortalLeaseVisibilityTest`.)

DEFAULT status — because "whose row is this?" and "has this document been raised?" are two
questions and only the first was being asked. The table now also narrows with `visibleToTenant()`;
the registry is `App\Support\TenantVisibility` and it is shared with the mobile API, because the
portal and `/api/v1` are the same surface with different renderers. See
[module 20](20-mobile-api.md#3-business-rules--invariants).

> **That rule is GATED since 2026-09-02, and it had drifted seven times before it was.**
> `PortalAndApiAnswerTheSameQuestionsConformanceTest` + `App\Support\PortalApiParity` require an
> `/api/v1` counterpart for every portal resource and every field its detail view renders. It had
> been honoured for VISIBILITY — drafts hidden from both, fixed twice, each with a test — and
> silently not for CONTENT, because there was a gate for the first question and none for the
> second. The two worst gaps were not incompleteness but silence: the **deposit shortfall**, which
> is never invoiced, so the portal figure was the ONLY channel by which a tenant was ever told they
> still owed one; and **credit on account**, the tenant's own money, which looked lost in the app
> and then silently part-settled an invoice. Neither was a bug in an endpoint — every endpoint
> returned exactly what it promised. They were commits that landed on the portal and stopped there,
> which only a comparison can see.

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
- `TenantRequestStatusChangedNotification` — operator updated the maintenance request status.
- `TenantSalesDeclarationLockedNotification` — the tenant's sales declaration was locked after the reporting period closed.

### Integrations

**Paymob (payment gateway):**
- Invoice view page has a **Pay Now** action (visible to admin only, and only if `config('integrations.paymob.enabled')` is true and invoice balance > 0).
- Clicking **Pay Now** calls `PaymobPaymentInitiator::start()`, which initiates an iframe session at Paymob.
- After payment, Paymob webhook invokes the capture handler, which creates a Payment record, updates the invoice, and calls `notifyPortal()`.

**Demo mode (when Paymob is disabled):**
- A **Pay Demo** action is shown instead, which simulates a payment: creates a Payment, marks invoice as paid, and calls `notifyPortal()`.

## 8. Extension points — how to change/extend SAFELY

### Branding (EG-22)

The portal is **white-labelled per property**. `App\Support\Filament\PortalBranding` answers "which
mall?" and `App\Support\Filament\PanelBranding` turns an `Asset` into a name, a logo, a favicon and a
`--primary-*` palette — the same seam the admin panel reads, so a colour rule cannot drift between the
two and leave a mall's portal the wrong green.

**The rule is exactly one mall, or the platform.** A tenant trading in ONE property (an active lease,
or a `handed_over` unit ownership — a unit owner is a `tenants` row too and pays their service charge
here) sees that mall's name, logo, favicon and colour. A tenant with shops in three sees `portal.brand`,
because branding their portal as one of the three is a claim about the other two. That is deliberately
the same rule the Statement of Account now applies (it took `leases->first()` and carried one
arbitrary mall's letterhead for a chain): a statement and a
portal must not tell one tenant two different things.

Three things to keep if you touch it:

- **Closures, not values.** A panel builder argument is evaluated once at boot, so `->brandName('…')`
  cannot depend on who is signed in. Same trap that makes `->colors()` and the 2FA condition unusable
  per-user.
- **Memoise per REQUEST, not in a static.** The panel asks four times a page plus the theme hook; a
  static outlives the request in a queue worker or under Octane. `PortalBranding::forget()` exists for
  tests that sign in as a second tenant.
- **A malformed `primary_color` emits nothing.** The field is operator-typed and a broken `<style>`
  would take the panel's whole chrome with it.


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
   This prevents non-admins from seeing and mounting the form. `visible()` is UX, not a gate — a write action must ALSO gate `Portal::isAdmin()` in `action()`/`abort_unless`.

2. **CLAMP every client-supplied foreign key to the tenant's own** (`lease_id`, `unit_id`, …). A Select's `->options()` scope the RENDERING, not the payload — a crafted Livewire submit posts any id. Without a server-side clamp a portal user files/plants a record against **another retailer's** lease/unit (cross-tenant write: a competitor's sales-declaration slot DoS'd, a request misrouted to another mall's staff). Use `Portal::clampLeaseId($data['lease_id'])` (returns null for a foreign lease) and refuse null; for `unit_id`, derive it from the tenant's own lease, never from the raw payload:
   ```php
   $data['lease_id'] = Portal::clampLeaseId($data['lease_id'] ?? null);
   abort_if($data['lease_id'] === null, 403);
   ```
   The mobile API request-validators do this with `Rule::exists('leases', …)->where('tenant_id', …)`; the portal has no such rule, so the page/service MUST clamp. Guarded by `PortalCrossTenantWriteGuardTest`.

   **Clamp to the PIVOT, not `leases.unit_id`.** A multi-unit lease keeps its additional units in
   `lease_unit` and only the master in the column, so a column-only clamp is both too narrow and
   silently wrong — it locked tenants out of their own extra units (mobile: 422; portal: fell
   through to `activeLeases()->first()` and filed against the **wrong unit**). Match either:
   ```php
   $tenant->leases()->where(fn ($q) => $q
       ->where('unit_id', $unitId)
       ->orWhereHas('units', fn ($u) => $u->whereKey($unitId)))
   ```
   …and then use the unit the tenant NAMED (`$lease->units()->whereKey($unitId)->first()`), falling
   back to the master — using `$lease->unit` unconditionally is what mislabelled the request.
   Guarded by `MultiUnitRequestUnitTest`.

3. **In the action handler**, validate and save the record, then call `notifyPortal()` if needed:
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

### Cross-tenant WRITE via an unclamped foreign key (FIXED 2026-07-26)

Two portal create paths trusted a **client-supplied lease_id / unit_id** — the form's `->options()` scope only the rendering, so a crafted Livewire submit could post another retailer's id:
- **Sales declarations (HIGH).** `CreateTenantSalesDeclaration` left `lease_id` raw → mass-assigned. Tenant A could plant a declaration on tenant B's lease: it occupies B's `(lease_id, period_start)` unique slot (**DoS'ing B's own reporting**) and surfaces a fabricated report on that mall's admin queue (**potential misbilling**). The `period_start` `unique` rule *is* clamped via `clampLeaseId` — but that returns null for a foreign lease, so the rule matches nothing and passes, guarding the oracle but **not the write**. Fixed: `mutateFormDataBeforeCreate` clamps `lease_id` via `Portal::clampLeaseId` and 403s on null.
- **Tenant requests (MED).** `TenantRequestService::create` used `$data['unit_id']` raw → a request with `tenant_id=A, unit_id=B's unit`, leaking B's unit code back through A's own request view and **misrouting** to B's property staff / area supervisors. Fixed in the service: unit + lease are derived from the tenant's own lease, never the payload. **Follow-up 2026-07-30:** that clamp resolved the lease via `leases.unit_id` — the MASTER only — so it locked a multi-unit tenant out of their own additional units (real case: Cilantro leases A-01 + C-09, could only report faults for A-01). Now resolved through the `lease_unit` pivot on all three surfaces (service, mobile validator, portal picker), and the request is filed against the unit the tenant actually named rather than the lease's master. The cross-tenant clamp is unchanged and re-asserted.

The mobile API already clamped both (`CreateSalesDeclarationAction`, `CreateTenantRequestRequest`); only the portal skipped it. **Lesson (now in §8):** every portal write must clamp its client-supplied foreign keys server-side — options are rendering, not authorization. Guarded by `PortalCrossTenantWriteGuardTest`.

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
- **04-leases.md** — Leases scoped to tenants; portal resources inherit tenant scoping via leases.
- **05-billing-invoices.md** — Invoices use `notifyPortal()` on issue; Pay Now/Demo actions gate on `Portal::isAdmin()`.
- **11-tenant-requests.md** — Maintenance requests scoped to tenant_id; canCreate gates on `Portal::isAdmin()`.
- **09-tenant-sales-percentage-rent.md** — Sales declarations scoped to lease.tenant_id; canCreate gates on admin.
- **06-payments.md** — Payments scoped to tenant_id; visible to portal users.
- **08-cam.md** — CAM allocations scoped via lease.tenant_id; visible to portal users.
- **Mobile API (Tenant Sanctum)** — Still authenticates the Tenant record directly (not TenantUser); separate session from portal.

---

**Last updated:** 2026-06-27  
**Req reference:** Requirement #9 (multi-user tenant portal)

---

## Sweep fixes — 2026-09-05

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a time.
Each row's full claim is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*

### SW-172

add: "**A file the tenant sent us is shown back to the tenant who sent it (SW-172, 2026-09-04).** The two upload surfaces in the portal — a request's attachments and a sales declaration's report — were WRITE-ONLY. The request View screen did not mention media at all; the declaration View screen rendered a count badge (\"2 files\") with nothing behind it, which is the worst of the three states because it proves the upload arrived and still refuses to say WHICH file. Neither resource has an Edit page, so there was no other door. `/api/v1` has returned both lists with an authenticated per-file URL since it shipped, and the rule for that pair is that the portal and the API are one surface with two renderers. `App\\Support\\Filament\\PrivateAttachments::entry()` is the one renderer for both screens: it lists each file by name, linked to a SHORT-LIVED SIGNED url (`Media::getTemporaryUrl()`, at Filament's own `temporary_file_url_expiry_minutes`, because these collections are `useDisk('local')` and that disk is served only behind a signature) and falls back to naming the file with no link on a driver that cannot sign — losing the filename is worse than losing the link. It adds no vocabulary: both call sites pass an existing `admin.fields.*` label, and the now-unused `admin.tables.tenant_sales.report_count` is removed from both catalogues. It is deliberately NOT an authorization layer — both resources already scope to `Portal::tenantId()`, so another tenant's record is a 404 long before a file is listed."

### SW-029

`:

**A portal status filter offers what the portal SHOWS, and that is derived (SW-029, 2026-09-04).** `App\Support\StatusOptions` is the one home for *what may a status filter offer*: `for()` is every value `ValueSets::allowed()` accepts, labelled from `admin.statuses.{singular table}` through `Translate::orHumanized()`; `forTenant()` is that set narrowed by `TenantVisibility`, composed from `for()` rather than derived again so the two cannot disagree about what a status is or what it is called. All three portal money filters read it. Before this, the invoice filter offered **4 of the 8** statuses a tenant may be shown and the payment filter **5 of the 9** — `disputed`, `cancelled`, `credited`, `written_off`, `initiated`, `authorized`, `bounced` and `voided` were each rendered by their own table's `status` column, in colour, and unreachable from the filter beside it. A tenant chasing a number they remember off a statement could read the word and not select it. **The correct derivation already existed on ONE of the three** — the credit-note filter, inline, under a five-line comment saying why a hand-written list is wrong — and its two neighbours never got it; that is the whole finding, and the reason the fix is a seam rather than two edited lists. A status added to `ValueSets` is now filterable **by existing**, the same safe direction `TenantVisibility` chose for the scope: what is withheld has to be withheld deliberately. An unregistered column throws `InvalidArgumentException` rather than rendering an empty dropdown, because an empty picker reads as "there are none of those" and not as a broken filter. Note the testing trap the regression test records: `SelectFilter::apply()` filters on whatever value it is handed, so driving `filterTable('status','written_off')` passes with the fix reverted — the option LIST is the defect, so membership must be asserted as well as selection. **SW-027 was the same defect through the admin door and is CLOSED (2026-09-05)** — the invoice, payment and lease registers each became one line, `StatusOptions::for('invoices')` / `::for('payments')` / `::for('leases')`. The admin half is the wider one, because an admin list shows every row the column can carry rather than the visible subset: invoices offered **5 of 9** and payments **4 of 9**, so `disputed`, `cancelled`, `credited`, `written_off`, `initiated`, `authorized`, `settled`, `bounced` and `voided` were unfilterable on the operator's own register — and `voided`, which shipped on 2026-08-28 to say money was NOT returned, is in no worklist tab either, so nothing on the screen could name it. The lease register's `->except('cancelled')` arrived in `bcca5b17` with no comment and no reason in the commit message — incidental rather than a decision — while `LeaseForm:295` lets a lease be saved `cancelled`; that one was still reachable through the **Ended** tab, so it is the weakest of the three and is stated that way, whereas `cancelled`/`credited`/`written_off` are in no invoice tab and `voided` in no payment tab. **A TAB set is a curated worklist and legitimately not exhaustive; the FILTER is the exhaustive tool**, which is why the fix belongs on the filter and the tabs were left alone.

### SW-016

**THE WORD ON A PORTAL FILTER IS THE QUERY BEHIND IT (SW-016, fixed 2026-09-05).** The filter's KEY
said `unpaid_only`, its QUERY was unpaid, and the only thing the tenant could read said *"Overdue
Only"* — and the dashboard's headline OUTSTANDING stat deep-linked into it, so clicking an
outstanding figure landed on a list captioned Overdue. On the QA baseline that was **108 rows under
a word that describes 11 of them**, while the dashboard's own overdue count read the raw
`status = 'overdue'` STAMP — which the nightly sweep lags a day behind and which `partially_paid`
can never carry — and said **4**. Three answers to one question, on the screen whose reader is the
person being asked for the money. `Invoice::scopeOverdue()` is now the ONE definition (past due AND
still owed — the pair was written out six times under comments asking each other to stay identical),
routed through the admin filter, the sidebar badge, the dashboard card, `Tenant::isDelinquent()`,
`TenantBalances` and both new portal filters; `/api/v1/me/balance` already computed it correctly, so
the portal was brought to the API rather than the other way round.
