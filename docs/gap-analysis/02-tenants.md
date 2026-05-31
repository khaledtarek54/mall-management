# Module 02 — Tenants

> Date: 2026-05-31
> Status: 🟢 Green — code clean, demo path works; 5 Yellow extensibility findings (no inline fixes)
> Surface: [Tenant model](../../app/Models/Tenant.php), [Admin TenantResource](../../app/Filament/Admin/Resources/Tenants/), Portal panel (`Filament/Portal/`), Mobile API auth (`Http/Controllers/Api/V1/Auth/`), and the three migrations that own the tenants table.

## 1. Inventory

### 1.1 Model — [app/Models/Tenant.php](../../app/Models/Tenant.php) (137 LOC)

- Extends `Illuminate\Foundation\Auth\User as Authenticatable` and implements `FilamentUser`, `HasMedia`.
- Traits: `HasApiTokens` (Sanctum), `HasFactory`, `InteractsWithMedia` (Spatie), `LogsActivity` (Spatie), `Notifiable`, `SoftDeletes`.
- `$fillable`: 14 columns (`name`, `legal_name`, `type`, `email`, `password`, `phone`, `whatsapp`, `tax_id`, `national_id`, `address`, `contact_person`, `contact_person_phone`, `status`, `metadata`).
- `$hidden`: `password`, `remember_token`, `national_id`, `tax_id`.
- `casts`: `metadata`→array, `email_verified_at`→datetime, `password`→hashed.
- `canAccessPanel`: gate is `panel->getId() === 'portal' && $this->status === 'active'`.
- Activity log: `name|legal_name|type|status|email|phone` only, dirty-only, empty-changes skipped, log name `tenant`.
- Relations: `leases`, `activeLeases` (`->where('status','active')`), `invoices`, `payments`, `notes` (MorphMany), `maintenanceRequests`, `creditNotes`.
- Computed: `outstandingBalance(): float` nets unapplied credit-note balances against open invoices; `isDelinquent(): bool` defensively re-queries `balance>0 && due_date<now` instead of trusting the `status` column.

### 1.2 Migrations

| File | Effect |
|---|---|
| [2024_01_01_000003_create_tenants_table.php](../../database/migrations/2024_01_01_000003_create_tenants_table.php) | Base table. `type` enum [individual,company] default `company`; `status` enum [active, inactive, blacklisted] default `active`; soft deletes; index on `status`. |
| [2026_05_12_125617_add_auth_columns_to_tenants_table.php](../../database/migrations/2026_05_12_125617_add_auth_columns_to_tenants_table.php) | Adds `password`, `email_verified_at`, `remember_token` (positioned after `email`). |
| 2026_05_23_160330_create_tenant_sales_declarations_table | Owned by Module 12; not tenant-auth. Listed here for cross-ref. |

### 1.3 Admin Resource — `app/Filament/Admin/Resources/Tenants/`

| File | LOC | Purpose |
|---|---:|---|
| [TenantResource.php](../../app/Filament/Admin/Resources/Tenants/TenantResource.php) | 103 | Resource config; uses `RoleGatedActions` + `ScopesViaProperty` traits; `tenantScopeRelation()` = `'leases.unit'` (scopes by asset via leases); navigation group "Operations", sort 3, icon `OutlinedUserGroup`. |
| Schemas/TenantForm.php | 95 | Form sections: tenant info + contact + media (contract/ID uploads). |
| [Tables/TenantsTable.php](../../app/Filament/Admin/Resources/Tenants/Tables/TenantsTable.php) | 163 | 7 columns, 5 filters (status, type, has_active_lease, created_range, trashed), header export, per-row Edit + Statement PDF download, bulk export/delete/restore/forceDelete. |
| Pages/{List,Create,Edit}Tenant.php | thin | Standard Filament CRUD pages. |

Related managers (declared but live under `app/Filament/Admin/RelationManagers/`, shared across resources): `TenantLeases`, `TenantPayments`, `TenantMaintenance`, `TenantNotes`, `Activities`.

### 1.4 Tenant Portal panel — [PortalPanelProvider.php](../../app/Providers/Filament/PortalPanelProvider.php) (62 LOC)

- Panel id `portal`, path `/portal`, auth guard `portal` (session driver, `tenants` provider).
- Branding: `Atriom · Tenant Portal`, custom logo + favicon.
- Middleware stack ends with `SetLocale` (i18n consistency with `/admin` panel).
- 4 auto-discovered resources: `Invoice`, `Payment`, `MaintenanceRequest`, `TenantSalesDeclaration` (each module owns its own portal resource — gap-checked in their respective modules).
- 2 widgets: `AccountBalance` (uses `Tenant::outstandingBalance()`), `OpenMaintenance`.

### 1.5 Mobile API — [routes/api.php](../../routes/api.php) + `Http/Controllers/Api/V1/Auth/`

Current surface (3 endpoints):

| Method + URI | Controller | Auth | Throttle |
|---|---|---|---|
| `POST /api/v1/auth/login` | LoginController (invokable) | none | `throttle:5,1` per email+ip |
| `GET /api/v1/auth/me` | MeController (invokable) | `auth:tenant-api` | none |
| `POST /api/v1/auth/logout` | LogoutController (invokable) | `auth:tenant-api` | none |

- LoginController delegates to [LoginTenantAction](../../app/Actions/Api/Auth/LoginTenantAction.php) (single-action class — matches user's standing preference).
- LoginTenantAction: looks up tenant by email, `Hash::check`, status-active check, revokes prior token with same device name, mints new token with `abilities: ['tenant:*']`.
- LogoutController correctly skips non-`PersonalAccessToken` cases (e.g. transient session tokens), so it's safe to call from any context where `auth:tenant-api` is the guard.
- [LoginRequest](../../app/Http/Requests/Api/V1/Auth/LoginRequest.php): `email|email|max:255`, `password|string|min:1`, `device_name|string|max:100`; normalizes email to `strtolower(trim(...))` to avoid case-sensitivity friction.
- [TenantResource (API)](../../app/Http/Resources/Api/V1/TenantResource.php): exposes `id|name|legal_name|type|email|phone|whatsapp|contact_person|status|tax_id`. **NB: `tax_id` is in the model's `$hidden` but re-added explicitly here** — see F-7.

## 2. Spec map

| Source | Verbatim claim | Verified |
|---|---|---|
| FEATURES.md §Auth | "Tenant model extends `Authenticatable` for portal login + implements `FilamentUser`" | ✅ both true |
| FEATURES.md §Tenant | "`Tenant::isDelinquent()` — flags invoices past due_date (audit fix)" | ✅ tested in `TenantFinancialsTest` + `TenantTest` |
| FEATURES.md §Tenant | "`Tenant::outstandingBalance()` — nets credit-note balances" | ✅ tested |
| FEATURES.md §Media | "Lease, Tenant, and MaintenanceRequest implement `HasMedia`" | ✅ true on Tenant |
| FEATURES.md §Audit | "LogsActivity trait on Tenant" | ✅ true, 6-field allowlist |
| DEMO.md line 21 | "Tenant Portal | Switch to phone, /portal | Same data, tenant-side" | ✅ portal e2e 4/4 green |
| DEMO-ELTIZAM.md L216–228 | Tenant portal "WhatsApp moment" — balance, invoices, PDFs, tickets | ✅ all 4 routes exist in `Filament/Portal/Resources/` |
| DEMO-ELTIZAM.md L272 | "Mobile tenant app — Q2 roadmap; login auth already shipped against the new Sanctum API" | ✅ 3 endpoints shipped; remaining endpoints explicitly Q2 |

## 3. Findings

### 🟡 F-7. API `TenantResource` exposes `tax_id` despite model `$hidden`

- `Tenant` model puts `tax_id` (and `national_id`) in `$hidden` — so default Eloquent serialization redacts both.
- The API resource [Http/Resources/Api/V1/TenantResource.php:25](../../app/Http/Resources/Api/V1/TenantResource.php#L25) explicitly re-adds `tax_id` (but **not** `national_id`).
- Plausible reason: invoice PDFs/displays in the mobile app need the company tax number. Documented? No.
- **No action required if intentional.** Worth a one-line comment in the API resource explaining the deliberate exposure so a future maintainer doesn't "fix" it by removing the field.

### 🟡 F-8. No password reset flow for tenants

- `PortalPanelProvider::panel()` calls `->login()` but not `->passwordReset()` (Filament 4 ships with a built-in password-reset flow that this could enable in one line).
- No `Auth\PasswordResetController` or equivalent under `app/Http/Controllers/Api/V1/Auth/` for the mobile API.
- Operational consequence: a tenant who forgets their password must contact an operator, who edits the tenant in the admin panel and types a new password into the form. There is no email/SMS reset link.
- For demo: not a blocker (demo tenants use known passwords from DEMO.md).
- For production: required before any non-pilot rollout.

### 🟡 F-9. No tenant self-service profile update

- Tenants cannot update their own `phone`, `whatsapp`, `contact_person`, `contact_person_phone`, or `address` from either the portal or the API.
- The Filament portal panel discovers resources but doesn't expose a `MyAccount` page; there's no `PATCH /api/v1/tenants/me` either.
- Operational consequence: every contact-detail change goes through an operator. Fine at small scale; friction at enterprise.

### 🟡 F-10. Mobile API has only `/auth/*` endpoints

- Today: login + me + logout. Nothing else.
- DEMO-ELTIZAM.md explicitly parks the mobile app at Q2, so this is documented-and-deferred, not a surprise.
- Caveat: the audit at Module 19 (Mobile API) will design the missing endpoints. Listing here so the Module 02 → Module 19 cross-ref is explicit:
  - `GET /api/v1/me/invoices` — invoice list
  - `GET /api/v1/me/invoices/{id}` — single invoice incl. PDF URL
  - `GET /api/v1/me/balance` — outstanding + delinquency
  - `GET /api/v1/me/maintenance-requests` + `POST` + comment thread
  - `GET /api/v1/me/sales-declarations` + `POST` (for percentage-rent tenants)
  - `PATCH /api/v1/me` (profile)

### 🟡 F-11. `Tenant::isDelinquent()` is tested but not surfaced anywhere in UI

- `outstandingBalance()` is used by `Filament/Portal/Widgets/AccountBalance.php`.
- `isDelinquent()` is referenced **only** by two test files ([TenantFinancialsTest](../../tests/Feature/Models/TenantFinancialsTest.php), [TenantTest](../../tests/Feature/Models/TenantTest.php)). No production code calls it.
- The TenantsTable has filters for status, type, `has_active_lease`, and a date range — but no "delinquent" filter or column badge.
- Two reasonable resolutions:
  - **A**: Add a "Delinquent" badge column + filter to `TenantsTable` (≤30 LOC; trivial).
  - **B**: Remove `isDelinquent()` if delinquency is intentionally tracked at the invoice/AR-aging level, not the tenant level.

### 🟢 No security findings

- Login error message is generic for both unknown-email and wrong-password cases (no enumeration; verified by `LoginTest::test_rejects_*`).
- Login is throttled to `5/min` per email+ip in `routes/api.php`.
- Token has `tenant:*` ability scope — gives a clean migration path to fine-grained abilities later.
- Logout deletes only the current device's token (verified by `test_keeps_tokens_separate_across_devices`).
- Session-driven portal panel uses `AuthenticateSession` and `PreventRequestForgery` middleware.
- `password` cast as `hashed` — auto-hashes on assignment.
- `$hidden` correctly excludes `password`, `remember_token`, `national_id`.

### 🟢 No business-logic findings

- `outstandingBalance()` correctly nets `partially_applied` credit-note balances.
- `isDelinquent()` correctly handles invoices that were never auto-flipped to `overdue` by Payment hooks (the docstring is on point).
- Three statuses (`active|inactive|blacklisted`) flow through the model → migration enum → form select → table badge → login gates without divergence.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter=Tenants|Tenant|Api/V1/Auth|PanelAccess|TenantScope|ResourceScoping` | **75 passed / 0 failed** | 1.73 s |
| `npx playwright test tests/e2e/04-portal.spec.js` | **4 passed / 0 failed** | 7.1 s |

**Notable Pest coverage:**
- `Tests\Feature\Api\V1\Auth\LoginTest`: 8 cases — valid login, wrong password, unknown email (same error), inactive blocked, blacklisted blocked, missing-fields validation, same-device token revocation, cross-device token isolation.
- `Tests\Feature\Models\TenantFinancialsTest`: covers `isDelinquent()` past-due flagging and `outstandingBalance()` netting.
- `Tests\Feature\Tenancy\TenantScopeTest` + `TenantScopeApplyToTest` + `ResourceScopingTest`: per-property scoping correctness.

**Coverage gaps worth filling at a calmer pass (not blocking demo):**
- No test covers the admin "Statement PDF" record action on TenantsTable (it streams a PDF; failure mode is silent at runtime).
- No test asserts that a `blacklisted` tenant in active session is auto-logged-out (only login is gated, but session may persist).

## 5. Manual UX pass

- Portal e2e covers: Dashboard load, Invoices index, viewing own invoice, cross-panel block (cannot access `/admin`). All green.
- Per-locale rendering not exercised in this pass; `99-system-smoke.spec.js` AR group covers admin pages but not the portal specifically. Logged for end-state gate.

## 6. No inline fixes this module

Every Yellow finding is either:
- A documentation question (F-7 — tax_id comment),
- A production-readiness feature (F-8/F-9/F-10),
- A scope decision (F-11 — surface delinquency or remove it).

None are bugs. Per the "fix small, batch large" rule, all five go to the deferred backlog for explicit decisions.

## 7. Deferred decisions

| # | Decision | Default if not raised |
|---|---|---|
| D-5 | F-7: add a one-line comment in API `TenantResource` confirming the deliberate `tax_id` exposure | Yes — apply at end of sweep |
| D-6 | F-8: enable Filament `->passwordReset()` on the portal + add `POST /api/v1/auth/forgot-password` to mobile API | Defer to Module 19 mobile-API design; for production, must ship |
| D-7 | F-9: add tenant self-service profile page + `PATCH /api/v1/me` endpoint | Defer to Module 19 |
| D-8 | F-10: confirm the mobile-API endpoint list above before Module 19 implementation | Confirm at Module 19 kickoff |
| D-9 | F-11: surface delinquency in TenantsTable (Option A) or remove the method (Option B) | Option A — small UI lift, real operator value |

## 8. Verdict

**🟢 Green.** The Tenant module is one of the cleanest in the codebase: model is small and tested; auth flow is correctly gated, error-uniform, and rate-limited; admin CRUD is feature-complete with bulk + export + soft-delete + statement PDF; portal panel uses a dedicated guard with proper middleware. The five Yellow findings are extensibility items (password reset, self-service, mobile API depth, isDelinquent UI exposure) — none block the demo path or production for a pilot deployment.

## Next

Module 03 — Units. Surface: [app/Filament/Admin/Resources/Units/](../../app/Filament/Admin/Resources/Units/), [Unit model](../../app/Models/Unit.php), [OccupancyMap page](../../app/Filament/Admin/Pages/OccupancyMap.php), unit status enum vs leases, and the unit ↔ asset ↔ tenant scoping graph.
