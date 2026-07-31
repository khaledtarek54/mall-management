# Tenants

> Lessee companies (individuals and corporations) that occupy units under leases and receive invoices for rent and service charges.

## 1. Purpose & business context

Tenants are the **customers** of the mall (Eltizam/operator) — the retailers, service providers, and others who lease space. The Tenant record stores identity (name, tax ID, commercial registration), contact info (email, phone, person), and status (active/inactive/blacklisted). Tenants authenticate to the Tenant Portal to view invoices, make payments, and submit maintenance requests. The mobile API also authenticates tenants. Multiple TenantUsers can belong to a single Tenant company — allowing team members different access levels (admin vs. read-only). Tenant records feed downstream: leases bind tenants to units, invoices/payments/credit-notes are tied to tenant + lease, and maintenance requests are filed by tenants.

## 2. Domain model

| Table | Model | Key columns & types/constraints | Meaning |
|---|---|---|---|
| `tenants` | `Tenant` | `id` pk, `name` varchar(255) NOT NULL, `legal_name` varchar(255), `type` enum(individual\|company) default='company', `email` varchar(255), `password` varchar(255), `phone` varchar(20), `whatsapp` varchar(20), `tax_id` varchar(50) [index], `national_id` varchar(20), `commercial_register` varchar(50), `address` text, `contact_person` varchar(100), `contact_person_phone` varchar(20), `status` enum(active\|inactive\|blacklisted) [index] default='active', `metadata` json, `created_at`, `updated_at`, `deleted_at` (soft-delete) | Core company record. Extends `Authenticatable` for portal/API login. |
| `tenant_users` | `TenantUser` | `id` pk, `tenant_id` fk→tenants(id) cascadeOnDelete, `name` varchar(255) NOT NULL, `email` varchar(255) unique NOT NULL, `password` varchar(255) NOT NULL, `is_admin` bool default=false, `created_at`, `updated_at`, `deleted_at` (soft-delete) | Portal login accounts under a tenant. One or more per Tenant; only `is_admin=true` may submit/write in portal. |
| `device_tokens` | `DeviceToken` | `tenant_id` fk→tenants | Mobile push-notification tokens (one per app install). |

**Relationships:**
- `Tenant → Lease` (hasMany): all leases for this tenant
- `Tenant → activeLeases` (hasMany): only leases with `status='active'`
- `Tenant → Invoice` (hasMany): all invoices raised for this tenant
- `Tenant → Payment` (hasMany): all payments received from this tenant
- `Tenant → CreditNote` (hasMany): all credit notes issued to this tenant
- `Tenant → TenantUser` (hasMany): portal login accounts belonging to this company
- `Tenant → TenantSalesDeclaration` (hasManyThrough Lease): sales declarations (for percentage-rent leases)
- `Tenant → MaintenanceRequest` (hasMany): maintenance tickets filed by/for this tenant
- `Tenant → Note` (morphMany): admin notes attached to the tenant
- `Tenant → DeviceToken` (hasMany): mobile device registration records
- `TenantUser → Tenant` (belongsTo)

## 3. Business rules & invariants

| Rule | Enforcement | Test |
|---|---|---|
| **Status gate:** Only a `status='active'` company can access the Portal or the mobile API. `inactive`/`blacklisted`/soft-deleted are blocked — **on every request**, not just at login. | **Portal:** `TenantUser::canAccessPanel()` = `panel->getId()==='portal' && $this->tenant?->status==='active'` (the portal authenticates `TenantUser` since the multi-user migration, so the check lives here — `Tenant::canAccessPanel()` is dead for the portal). **API:** login checks status once, and the `EnsureTenantActive` middleware on the `auth:tenant-api` group re-checks every request + revokes the token if the company is no longer active (a blacklist mid-session cuts access immediately). | `Module02TenantIntegrityTest` (portal gate across active/inactive/blacklisted; API cut-off mid-session) |
| **Outstanding balance = invoices − unapplied credit-notes.** A tenant with a 1000 EGP invoice and a 300 EGP issued credit note owes 700 EGP. | `Tenant::outstandingBalance()`: sums invoices with status in [issued, partially_paid, overdue], subtracts credit notes with status='issued' (only issued = has unapplied balance; applied=0 balance, void excluded). | `TenantFinancialsTest::outstandingBalance_nets_unapplied_credit_notes` — creates a 1000+500 invoice scenario, adds 300 CN, asserts 1200 remaining. |
| **Delinquency = open-balance invoice past due_date.** Not status-based (Payment hooks may be late/broken). Must re-query `balance > 0 AND due_date < now AND status IN [issued, partially_paid, overdue]`. | `Tenant::isDelinquent()` — defensive check ignoring the `status` column for the due-date window. | `TenantFinancialsTest::isDelinquent_flags_issued_but_past_due` — creates issued (not overdue) invoice 30 days in past, asserts delinquency. |
| **Multi-user: at least one portal admin.** Only TenantUser records with `is_admin=true` can create/submit in the Portal. Others see read-only views. | Filament portal resource permission checks (each resource calls `TenantUser->isPortalAdmin()` or checks via policy). | `TenantUserGatingTest` — admin user can create MaintenanceRequest + TenantSalesDeclaration; non-admin cannot. |
| **Identity fields (tax_id, national_id, commercial_register) are collected for ETA/legal filing.** At ETA submission, business tenants must have a valid tax_id. | `tax_id` matches `/^\d{3}-?\d{3}-?\d{3}$/` (Egyptian VAT format) on **both** the form AND the **importer** (import is the primary roster-onboarding path). `Tenant::setTaxIdAttribute` then **normalises to bare digits on save** — the dashed form is accepted for readability, but ETA rejects dashes, so the on-wire receiver id is always digits-only. The importer `type` rule is `in:individual,company` (matches the enum; `foreign` was unstorable). | `Module02TenantIntegrityTest` (tax_id normalisation + importer rules); ETA builder tests assert digits-only receiver id. |
| **No cross-tenant data leak via property scope.** Tenants with no lease yet remain visible in the admin Tenant list (since they're unaffiliated), but filtering by property excludes them if they have any lease in another property. | `TenantResource::getEloquentQuery()`: if a property is in scope, return tenants with leases in that property OR tenants with no leases. | `TenantScopeTest` — scoping filters correctly; unaffiliated tenants are not hidden cross-property. |
| **Soft deletes.** Deleted tenants are not shown by default; can be restored via admin UI. | `SoftDeletes` trait on Tenant and TenantUser. `deleted_at` column. | TenantsTable has TrashedFilter; RestoreBulkAction visible for admins. |
| **Email uniqueness on TenantUser.** Two portal users cannot share an email. Tenant company emails may appear on multiple portal users in the same company (e.g., billing@company.com used by multiple people). | `TenantUser::email` has `unique()` constraint in migration. | PortalUsersRelationManager form enforces `unique(ignoreRecord: true)`. |

## 4. Lifecycle / state machine

### Tenant Status

| Status | Meaning | Can login? | Can lease? | Typical transition |
|---|---|---|---|---|
| `active` | Operational company | ✅ Yes | ✅ Yes | Default on create; set via admin edit |
| `inactive` | Dormant (no new activity) | ❌ No | ❌ No (manual) | Admin marks inactive; remains visible |
| `blacklisted` | Legal/payment dispute | ❌ No | ❌ No | Admin decision; serious consequence |

**Transitions:** Manual via admin TenantsTable → EditTenant form → status select. No auto-flip based on payment history; blacklist is a business decision (not algorithmic). Once a tenant is deleted (soft-delete), they can be restored by super_admin.

### TenantUser Status & Roles

| Role | Permissions in Portal |
|---|---|
| `is_admin=true` | Can CREATE/SUBMIT (MaintenanceRequest, TenantSalesDeclaration, etc.). Can upload documents. |
| `is_admin=false` | Can VIEW all resources (invoices, sales declarations, etc.) but NOT create/edit/delete. |

**Multi-user backfill:** When multi-user portal shipped (migration 2026_06_25_000003), all existing Tenants with a `password` + `email` were backfilled to one admin TenantUser each (preserving login continuity). New tenants created in admin panel do not auto-create a TenantUser; admins must create portal users explicitly in the PortalUsersRelationManager.

## 5. Services, jobs & scheduled commands

### TenantStatementPdfService
**Location:** `app/Services/TenantStatementPdfService`
**Signature:** `build(Tenant $tenant): string` (returns mPDF binary), `filename(Tenant $tenant): string`
**What it does:**
- Generates a 12-month statement PDF for a tenant: outstanding invoices (open balance, by due date), recent invoices (last 12 months, sorted by issue date), recent payments (captured status only), summary (outstanding/overdue/total_billed/total_paid/open_count).
- Loads tenant leases + units + assets for context.
- Uses mPDF library with RTL/LTR rendering based on `app()->getLocale()`.
- Renders via Blade view `tenants.statement`.

**Called by:**
- TenantsTable record action "Statement" (available to all authorized users; downloads the PDF).
- Not scheduled or cached; generated on-demand.

**Idempotency:** Yes — reads only, no side effects.

### TenantScope::selectableTenantOptions() (utility, not a service)
**Location:** `app/Support/TenantScope`
**Signature:** `static selectableTenantOptions(): array<int, string>`
**What it does:**
- Returns a `[id => name]` keyed array of tenants visible in the current Filament context.
- Scopes to the current user's visible properties (via `visibleAssetIds()`).
- Excludes tenants that have no lease in the user's visible properties, **unless** they have no leases at all (unaffiliated tenants are safe to offer).
- Used by Lease form, MaintenanceRequest form, etc. to populate tenant dropdowns.

**Idempotency:** Yes — reads only; no state change.

## 6. Filament resources & key fields

### Admin Resource: TenantResource
**Path:** `app/Filament/Admin/Resources/Tenants/`
**Navigation:** Group "Operations" (via `getNavigationGroup()`), sort order 3, icon `OutlinedUserGroup`.
**Permissions:** Uses `RoleGatedActions` trait — visible/editable based on user role (Filament policy).

**Form** (TenantForm.php):
| Field | Type | Validation | Scoped? | Notes |
|---|---|---|---|---|
| `name` | TextInput | required, maxLength:100 | No | Brand/display name (e.g., "Café Crema"). Global. |
| `legal_name` | TextInput | maxLength:150 | No | Formal company name (e.g., "Crema Coffee Co. LLC"). |
| `type` | Select | in:[individual,company], required | No | Tenant type; defaults to 'company'. |
| `status` | Select | in:[active,inactive,blacklisted], required | No | Operational status. |
| `tax_id` | TextInput | regex:/^\d{3}-?\d{3}-?\d{3}$/, maxLength:50 | No | Egyptian VAT registration (audit M15 F-59). Format: 123-456-789 (dashes optional). |
| `national_id` | TextInput | maxLength:20 | No | Owner/rep ID number (Egypt). |
| `commercial_register` | TextInput | maxLength:50 | No | Segel togary (commercial registration number) — added in migration 2026_06_24_000008. |
| `email` | TextInput | email, maxLength:150 | No | Tenant company email. (Portal users have their own emails in tenant_users table.) |
| `phone` | TextInput | tel, maxLength:20 | No | Company phone. |
| `whatsapp` | TextInput | tel, maxLength:20 | No | WhatsApp contact. |
| `contact_person` | TextInput | maxLength:100 | No | Main point of contact name. |
| `contact_person_phone` | TextInput | tel, maxLength:20 | No | Direct phone for contact person. |
| `address` | Textarea | maxLength:500, rows:2 | No | Full address. |
| `documents` | SpatieMediaLibraryFileUpload | nullable, max 10 MB each, PDF/images/Word | No | Contract/ID/license documents. Collection='documents'. |

**Table** (TenantsTable.php):
| Column | Type | Filterable? | Sortable? | Searchable? | Toggleable? | Notes |
|---|---|---|---|---|---|---|
| `name` | TextColumn | No | Yes | Yes | No | Bold, weight='bold'. Main identifier. |
| `active_leases_count` | TextColumn (counts) | Yes (ternary: has/no/any) | No | No | No | Badge green, shows active lease count. |
| `phone` | TextColumn | No | No | Yes | No | Icon heroicon-m-phone. |
| `whatsapp` | TextColumn | No | No | No | Yes (hidden) | Icon heroicon-m-chat-bubble-left-right, success color. |
| `email` | TextColumn | No | No | Yes | Yes (hidden) | Icon heroicon-m-envelope, copyable. |
| `contact_person` | TextColumn | No | No | Yes | Yes (hidden) | Hidden by default. |
| `status` | TextColumn (badge) | Yes (select) | No | No | No | Badge with color: active→success, inactive→gray, blacklisted→danger. |
| `is_delinquent` | TextColumn (computed) | Yes (ternary) | No | No | Yes | Calls `Tenant::isDelinquent()` on each row. Badge: delinquent→danger (icon exclamation), current→success (icon check). |

**Filters:**
- `status` (SelectFilter)
- `type` (SelectFilter: individual/company)
- `has_active_lease` (TernaryFilter: has/no/any)
- `is_delinquent` (TernaryFilter: delinquent/current/any)
- `created_range` (DatePicker: from/until)
- `trashed` (TrashedFilter: only trashed/no trashed/any)

**Row actions:**
- Edit (visible if user can edit)
- Statement (generates + downloads PDF via `TenantStatementPdfService`)

**Bulk actions:**
- Export (CSV via `TenantExporter`)
- Delete (soft-delete, visible if user can delete)
- ForceDelete (hard delete, super_admin only)
- Restore (from trash, super_admin only)

**Property scoping (TenantScope):**
- `tenantScopeRelation()` = `'leases.unit'` — filters by `leases.unit.asset_id`.
- Includes lease-less tenants (new tenants with no lease yet remain visible; not scoped away).

**Related managers** (appear as tabs on the edit page):
- PortalUsersRelationManager — create/edit/delete TenantUser accounts
- TenantLeasesRelationManager — view tenant's leases (read-only or editable based on policy)
- TenantPaymentsRelationManager — view/record payments
- TenantMaintenanceRelationManager — view maintenance requests
- TenantNotesRelationManager — add/view admin notes
- ActivitiesRelationManager — activity log (Spatie)

### Portal Resources
**Location:** `app/Filament/Portal/Resources/` (auto-discovered; each module owns its own)
- Tenant is the authenticated **user model** for the portal panel (via guard `portal`).
- Portal resources: Invoice, Payment, MaintenanceRequest, TenantSalesDeclaration (each module's responsibility).
- Widgets: AccountBalance (uses `Tenant::outstandingBalance()`), OpenMaintenance.
- Only TenantUser with `is_admin=true` can create/submit; others are read-only.

### API Tenant Resource (serialization, not Filament)
**Location:** `app/Http/Resources/Api/V1/TenantResource`
**Exposes:** id, name, legal_name, type, email, phone, whatsapp, contact_person, status, **tax_id** (explicitly re-added despite model `$hidden`; see audit note in docs/gap-analysis/02-tenants.md F-7).
**Used by:** `GET /api/v1/auth/me` endpoint (mobile app).

## 7. Notifications & integrations

### Notifications sent TO tenants (via Tenant.notifyPortal())

**Tenant::notifyPortal($notification)** broadcasts a notification to:
1. The Tenant record itself (mobile API if using Sanctum),
2. Each TenantUser belonging to the tenant (portal bell/notifications).

| Notification | Triggered by | Recipient | Purpose |
|---|---|---|---|
| `PaymentReceivedNotification` | Payment status flipped to 'captured' + has allocated invoices | Portal users | Confirms receipt and shows amount/invoices paid. Called by Payment.notifyReceiptOnce() (idempotent via `receipt_notified_at`). |
| `InvoiceIssuedNotification` | Invoice created by MonthlyBillingService | Portal users | New invoice for rent/CAM/etc. Reference number, due date, amount. |
| `PercentageRentCalculatedNotification` (inferred) | PercentageRentCalculationService | Portal users | Percentage-rent invoice auto-generated from tenant sales declaration. |
| `MaintenanceRequestStatusChangedNotification` (inferred) | MaintenanceRequestService | Portal users | Maintenance ticket acknowledged, resolved, assigned, etc. |
| `TenantResetPasswordNotification` | Tenant calls `sendPasswordResetNotification($token)` (mobile app forgot-password flow) | Tenant email | Password reset link (deep-link to mobile app via `APP_MOBILE_RESET_URL` config). Token expires in 60 minutes. |

### External integrations

| System | Integration | Initiated by | Flow |
|---|---|---|---|
| **ETA (Egyptian Tax Authority)** | Tax invoice submission | Invoice creation (if tenant is a business and lease has business terms) | Tenant.tax_id is used in the ETA XML payload; Invoice tracks eta_submission_id, eta_submitted_at, eta_status, eta_long_id, eta_response. |
| **Paymob** (Payment gateway, Module 06) | Payment collection | Payment manual entry or gateway callback | Tenant.email is receipt destination. Payment.gateway='paymob', gateway_transaction_id, gateway_response store transaction details. |

## 8. Extension points — how to change SAFELY

### Adding a new tenant field
1. **Create a migration** in `database/migrations/` (e.g., `add_field_to_tenants_table.php`).
2. **Add to `Tenant::$fillable`** if it should be mass-assignable.
3. **Add to TenantForm** schema (TenantForm.php) if it should be editable in admin.
4. **Add to TenantsTable** if it should be visible/filterable.
5. **Update activity log** in `Tenant::getActivitylogOptions()` if it's important (append field name to `->logOnly([...])`).
6. **Add validation rule** in TenantForm if needed.
7. **Update tests** if business rules change (e.g., new constraints on multi-tenancy, scoping, etc.).

**Do NOT:**
- Add to `$hidden` without considering API exposure (see F-7 in gap-analysis).
- Skip the migration — direct DB edits bypass audit log.
- Add to fillable without vetting permissions (use policies/gates to control who can edit).

### Adding a new tenant status
1. **Extend the `status` enum** in migration (or create a new migration that alters the column).
2. **Add the label** to language file (e.g., `resources/lang/en/admin.php` under `statuses.tenant.*`).
3. **Update TenantsTable** badge color logic: add new case to the `color()` closure.
4. **Update login gate** if the new status should block access: edit `Tenant::canAccessPanel()`.
5. **Add filter** to TenantsTable if operators should filter by the new status.
6. **Test:** create a scenario test ensuring the status flows end-to-end.

### Adding portal user roles (beyond is_admin)
1. **Extend `TenantUser::is_admin`** (boolean) to a nullable string or array cast (e.g., `roles` JSON).
2. **Create a policy** for `TenantUser` or modify resource policies to check `$user->tenant->roles` instead of hard-coded `is_admin`.
3. **Update PortalUsersRelationManager** form to offer role selection (Select/Toggle).
4. **Update each portal resource** permission method (e.g., `canCreate()`) to check the new role.
5. **Backfill** existing admin users via migration.
6. **Test:** TenantUserGatingTest scenarios for each role.

**Caution:** The current design is binary (admin vs. read-only). Moving to granular roles (e.g., "can_submit_sales", "can_pay") requires updating Filament policies across multiple resources. Start with tests; don't hardcode role checks in resource methods.

### Adding a new tenant notification
1. **Create the Notification class** in `app/Notifications/` (extend `Notification`, implement `toMail()`/`toDatabase()` as needed).
2. **Fire it** via `$tenant->notifyPortal(new MyNotification(...))` in the triggering service (e.g., InvoiceIssuedNotification in MonthlyBillingService).
3. **Test:** write a feature test that creates the trigger scenario, asserts the notification was queued/sent.
4. **Update portal UI** if the notification type needs a custom bell icon or action link.

### Changing outstanding balance or delinquency logic
1. **DO NOT edit `outstandingBalance()` or `isDelinquent()` in-place** — these are used by widgets and filters.
2. **Write a test first** that captures the new rule (e.g., "a payment in pending status should not reduce balance").
3. **Patch the method** and ensure all existing tests still pass.
4. **Update any dependent widgets/queries** that use the old logic (search codebase for `.outstandingBalance()` and `isDelinquent()`).
5. **Document the change** in a migration comment or CHANGELOG entry.

**Historical note:** `isDelinquent()` was added to fix invoice status being out-of-sync with actual due date (audit M02 F-11). The model method is the source of truth; never rely on the Invoice `status` column alone.

### Changing TenantScope (property filtering)
1. **Review `TenantScope::applyTo()`** — this is the single point of truth for property scoping.
2. **Extend it** (add a new method or parameter) rather than duplicating the logic elsewhere.
3. **Update `TenantResource::getEloquentQuery()`** to use the new scope.
4. **Test:** `TenantScopeTest` covers filtering correctness; add a new scenario if the scope criteria change.
5. **Audit for leaks:** search for `whereHas('leases.unit'` patterns in other resources; ensure they also respect the new scope.

**Warning:** Tenant scoping is asymmetric — lease-less tenants are **not** scoped (they appear everywhere). This is intentional (new tenants must be editable before they get a lease). If you change this, verify that the create→edit→lease flow doesn't break.

## 9. Gotchas, edge cases & recently-fixed bugs

### Delinquency vs. invoice status
- `Tenant::isDelinquent()` **re-queries** the due-date window defensively; it does **not** trust the `status` column.
- Reason: Payment hooks may be delayed, and manually-paid invoices may not auto-flip `status` to 'paid'. Old invoices can stay `status='issued'` indefinitely if never reconciled.
- **Gotcha:** The TenantsTable filter `is_delinquent=true` **also re-queries**, not just checking status='overdue'. Operators see the **true** delinquency state, not the invoice status.
- **Test:** `TenantFinancialsTest::isDelinquent_flags_issued_but_past_due` — asserts delinquency even when status='issued'.

### Outstanding balance and credit-note partial application
- A credit note can have `status='issued'` with `balance > 0` (partially applied, awaiting allocation to more invoices).
- `Tenant::outstandingBalance()` **only counts credit notes with status='issued'** (not applied/void).
- **Gotcha:** If a credit note is partly applied and then voided, the balance is not refunded to the tenant; the void is permanent.
- **Gotcha:** The formula subtracts credit-note balance, not credit-note total. A 1000 EGP CN that has 300 already applied shows as 700 EGP unapplied (balance=700).
- **Test:** `TenantFinancialsTest::outstandingBalance_nets_unapplied_credit_notes` + `outstandingBalance_ignores_fully_applied_credit_notes`.

### Multi-user portal activation (migration 2026_06_25_000003)
- **Backfill logic:** Only existing tenants with **both** `password` **and** `email` were migrated to one admin TenantUser.
- **Gotcha:** If a tenant record had an email but no password (draft state), it was **not** backfilled and has **no portal users**. An admin must create users for it manually.
- **Gotcha:** Once migrated, the `Tenant.password` field is still there but should not be used (all auth goes through TenantUser). Do not encourage tenants to update password on the Tenant record; they should only update in their TenantUser account.
- **Test:** `UserTenantsTest::multi_user_portal` (backfill coverage).

### Soft-deletes and cascade
- `Tenant` and `TenantUser` both have soft-deletes.
- `TenantUser` has `cascadeOnDelete` on `tenant_id` FK — **hard delete only** (no soft-delete cascade for the tenant).
- **Gotcha:** If a Tenant is soft-deleted, its TenantUsers are **not** soft-deleted (they remain visible in trashed filter until hard-deleted). This is intentional — you may restore a tenant without losing user audit records.
- **Gotcha:** Hard-deleting a Tenant **will** hard-delete all its TenantUsers. Soft-delete is safer.

### Tax ID format and ETA
- Tax ID validation: `regex:/^\d{3}-?\d{3}-?\d{3}$/` — exactly 9 digits, dashes optional.
- **Gotcha:** This is enforced in the form (TenantForm.php, line 50) but **not** at the database level. Existing data may contain malformed tax IDs (imported, legacy, etc.).
- **Gotcha:** ETA submission will fail if the tenant is a business and tax_id is missing or invalid. The invoice service checks this late (at ETA submission, not at invoice creation). Invalid tax ID = invoice created but marked ETA-failed, leaving the tenant with an uninvoiced lease month.
- **Best practice:** Run a data-cleaning migration if importing legacy tenants; validate tax_id at import time.
- **Test:** No test yet for ETA validation path (deferred to Module 08 ETA).

### TenantScope and unaffiliated tenants
- `TenantResource::getEloquentQuery()` has a special rule: when a property is in scope, it returns tenants with leases in that property **OR** tenants with **no** leases.
- **Reason:** A just-created tenant with no lease would otherwise vanish from the list after the admin creates it, making the post-create redirect to edit fail (404).
- **Gotcha:** If you query `Tenant::whereHas('leases.unit', ['asset_id' => $id])`, you'll exclude lease-less tenants. Use `TenantScope::selectableTenantOptions()` or the resource's `getEloquentQuery()` instead.
- **Test:** `TenantScopeTest` and `ResourceScopingTest` cover this.

### notifyPortal() broadcasts to both Tenant and TenantUsers
- When a service calls `$tenant->notifyPortal($notification)`, the notification is sent **twice**:
  1. To the Tenant record (SMS/email, or Sanctum API token if present).
  2. To **each** TenantUser's email/database (portal bell notifications).
- **Gotcha:** If a Tenant has many TenantUsers, a single invoice creates N+1 notifications (one per user + one on the tenant). This is intentional for multi-user visibility.
- **Gotcha:** If a Tenant has **no** TenantUsers, the notification goes to the Tenant email only (fallback). So old single-user data doesn't regress.
- **Test:** `TenantUserGatingTest::admin_and_non_admin_see_notifications` (verify both types get notified).

### API tax_id exposure despite model $hidden
- `Tenant::$hidden` includes `tax_id` (and `national_id`), but the API resource `Http/Resources/Api/V1/TenantResource` **explicitly re-adds** `tax_id`.
- **Reason:** Mobile app invoices/receipts need the tenant's tax registration for display.
- **Gotcha:** A future maintainer might "fix" this by removing `tax_id` from the API resource, thinking it's a leak. It's not; it's intentional.
- **Comment in the API resource** (line ~25) should clarify: `// tax_id is intentionally exposed for invoice context, despite being in $hidden`.
- **Audit note:** F-7 in docs/gap-analysis/02-tenants.md.

### Commercial_register column is recent (2026-06-24)
- Added in migration `2026_06_24_000008_add_commercial_register_to_tenants.php`.
- **Gotcha:** Existing tenant records have `NULL` commercial_register. ETA submission may require this; validate at submission time, not tenant creation.
- **Gotcha:** Importer (TenantImporter) does not require commercial_register; it's optional. If you want to enforce it, add `->required()` to the ImportColumn and re-issue backfill.

## 10. Tests & related modules

### Test files
- `/tests/Feature/Models/TenantTest.php` — basic model functionality (activeLeases, relationships).
- `/tests/Feature/Models/TenantFinancialsTest.php` — `outstandingBalance()` and `isDelinquent()` logic.
- `/tests/Feature/Api/V1/Auth/LoginTest.php` — tenant login endpoint (8 scenarios).
- `/tests/Feature/Portal/TenantUserGatingTest.php` — admin vs. read-only permissions in portal.
- `/tests/Feature/Tenancy/UserTenantsTest.php` — property scoping, user access control.
- `/tests/Feature/Tenancy/TenantScopeTest.php` — TenantScope utility correctness.
- `/tests/Feature/Tenancy/TenantScopeApplyToTest.php` — scope application to queries.
- `/tests/Feature/Resources/TenantDelinquencyColumnTest.php` — TenantsTable delinquency badge.

### Related modules (dependencies and cross-refs)
- **Module 01 — Assets** (`docs/modules/01-assets.md`): Property scoping is hierarchical (Tenant → Lease → Unit → Asset); TenantScope queries are `leases.unit.asset_id`.
- **Module 03 — Units** (`docs/modules/03-units.md`): Tenants occupy Units via Leases; Unit.asset_id gates what tenants can be leased.
- **Module 04 — Leases** (`docs/modules/04-leases.md`): Lease.tenant_id binds a tenant to units; lease billing (rent, CAM) drives invoice generation.
- **Module 05 — Invoices** (`docs/modules/05-invoices.md`): Invoice.tenant_id + Invoice.lease_id tie invoices to companies and lease terms; outstanding balance is computed from invoices.
- **Module 06 — Payments** (`docs/modules/06-payments.md`): Payment.tenant_id + Payment.invoices (pivot) allocate payments; notifies tenant via `PaymentReceivedNotification`.
- **Module 07 — CAM** (`docs/modules/07-cam.md`): CAM charges are billed to tenants via invoices; service charge invoices are generated monthly.
- **Module 08 — ETA** (`docs/modules/08-eta.md`): ETA submission uses Tenant.tax_id; invoice ETA metadata stored in Invoice.eta_* columns.
- **Module 09 — Maintenance** (`docs/modules/09-maintenance.md`): MaintenanceRequest.tenant_id ties tickets to tenant; notifies via `MaintenanceRequestStatusChanged`.
- **Module 11 — Tenant Portal** (`docs/modules/11-tenant-portal.md`): Portal panel auth guard uses Tenant model; TenantUser controls admin/read-only access.
- **Module 12 — Tenant Sales** (`docs/modules/12-tenant-sales.md`): TenantSalesDeclaration belongs to Lease (not directly to Tenant); accessed via `Tenant.salesDeclarations()` (hasManyThrough).
- **Module 14 — Credit Notes** (`docs/modules/14-credit-notes.md`): CreditNote.tenant_id + CreditNote.balance factor into `Tenant.outstandingBalance()`.
- **Module 19 — Mobile API** (`docs/modules/19-mobile-api.md`): Mobile endpoints auth via `auth:tenant-api` (Sanctum); TenantResource API serialization.
- **Cross-cutting: Notifications** (`docs/modules/notifications.md`?): Tenant.notifyPortal() broadcasts to TenantUser collection; see PaymentReceivedNotification, InvoiceIssuedNotification.

---

**Last updated:** 2026-06-27
**Author:** Module audit pass
**Status:** Reference complete; extensible for password-reset (F-8), self-service profile (F-9), and mobile API endpoints (F-10) per gap-analysis decisions.

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Tenant` | **Only while unreferenced** — blocked by `leases`, `invoices`, `payments`, `creditNotes`, `salesDeclarations`, `maintenanceRequests`, `postDatedCheques` | set the tenant to inactive — the history stays queryable and the AR still ties out |
