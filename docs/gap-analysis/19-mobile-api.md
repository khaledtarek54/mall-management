# Module 19 — Mobile API

> Date: 2026-05-31
> Status: 🟡 Yellow — auth is shipped + tested; the read+submit endpoints required by MOBILE-APP-BRIEF.md are not yet implemented. This module is "design + endpoint inventory" not "build" — design is the user's pick (D-15 questions answered).
> Surface: [routes/api.php](../../routes/api.php), [Http/Controllers/Api/V1/](../../app/Http/Controllers/Api/V1/), [Http/Resources/Api/V1/](../../app/Http/Resources/Api/V1/), [Http/Requests/Api/V1/](../../app/Http/Requests/Api/V1/), [Actions/Api/Auth/](../../app/Actions/Api/Auth/), Sanctum `tenant-api` guard.

## 1. Current state (shipped)

| Endpoint | Auth | Tested |
|---|---|---|
| `POST /api/v1/auth/login` | none (throttle 5/min per email+ip) | LoginTest (8 cases) |
| `GET /api/v1/auth/me` | `auth:tenant-api` | AuthenticatedRoutesTest |
| `POST /api/v1/auth/logout` | `auth:tenant-api` | AuthenticatedRoutesTest |

- Architecture: LoginTenantAction (single-action class — matches user's standing preference), thin controllers, FormRequest validation, Sanctum tokens with `tenant:*` ability.
- Resource: [TenantResource](../../app/Http/Resources/Api/V1/TenantResource.php) exposes id/name/legal_name/type/email/phone/whatsapp/contact_person/status/tax_id. (Module 02 F-7 flagged the tax_id re-exposure; documented as deliberate.)
- Standard JSON envelope `{data, message}` consistent across endpoints.
- Throttle 5/min on login (prevents credential stuffing).
- 422 on missing fields; 401 on no token; 422 on inactive/blacklisted (no enumeration via different error messages).

## 2. What MOBILE-APP-BRIEF.md says the app needs

From the brief (lines 281-300):

> Today's portal features (mobile parity required):
> - Account Balance widget — outstanding total, overdue total, open invoice count
> - Open Maintenance widget — own open maintenance request count
> - Invoices list — own invoices, status badges, sortable
> - Invoice view — line items, totals, PDF download
> - Pay Now button — stubbed today, will be Paymob when wired
> - Payments list — own payments, allocations, methods
> - Statement of Account — generates a multi-page PDF
> - Maintenance Requests — list, submit, view status timeline, comment, cancel-if-not-started
> - Tenant Sales Declarations — submit, view own history
> - Language switch EN/AR

> Mobile expansion opportunities beyond parity:
> - Push notifications on invoice issued, payment received, maintenance status change
> - Biometric unlock
> - Native camera capture for maintenance photos
> - Share invoice PDF via native share sheet
> - Offline mode for invoice viewing

Plus from Module 02 F-8: no password reset flow.

## 3. Proposed `/api/v1` endpoint shortlist (D-56 — design awaiting approval)

Grouped by parity surface. All endpoints scoped via `$request->user()` (the authenticated Tenant) — no tenant_id in URL.

### 3.1 Auth completion

| Method | URI | Purpose |
|---|---|---|
| `POST` | `/api/v1/auth/forgot-password` | Email reset link (rate-limited 3/min) |
| `POST` | `/api/v1/auth/reset-password` | Apply reset token + new password |
| `POST` | `/api/v1/auth/change-password` | Authenticated change (requires current password) |

### 3.2 Profile / account

| Method | URI | Returns |
|---|---|---|
| `GET` | `/api/v1/me` | Same as `auth/me` (alias for consistency) |
| `PATCH` | `/api/v1/me` | Update phone/whatsapp/contact_person/contact_person_phone/address only — no name/email/status/tax_id changes (admin-managed) |
| `GET` | `/api/v1/me/balance` | `{outstanding, overdue, open_count, currency}` — backed by `Tenant::outstandingBalance()` |
| `GET` | `/api/v1/me/leases` | Active leases (`status='active'`) — small array, typically 1 entry |

### 3.3 Invoices

| Method | URI | Returns |
|---|---|---|
| `GET` | `/api/v1/me/invoices?status=&period_from=&period_until=&page=` | Paginated list (default 25/page) — scoped `where('tenant_id', auth->id())` |
| `GET` | `/api/v1/me/invoices/{id}` | Full detail incl. items + ETA refs + lease.unit context |
| `GET` | `/api/v1/me/invoices/{id}/pdf` | `application/pdf` stream via `InvoicePdfService::build()` |
| `GET` | `/api/v1/me/statement?from=&to=` | Multi-month statement PDF stream via `TenantStatementPdfService::build()` |

### 3.4 Payments

| Method | URI | Returns |
|---|---|---|
| `GET` | `/api/v1/me/payments?method=&status=&page=` | Paginated list — scoped by tenant_id |
| `GET` | `/api/v1/me/payments/{id}` | Detail incl. allocation pivot rows |

### 3.5 Maintenance Requests

| Method | URI | Notes |
|---|---|---|
| `GET` | `/api/v1/me/maintenance-requests?status=&page=` | Paginated, scoped by tenant_id |
| `POST` | `/api/v1/me/maintenance-requests` | Create — body: `{title, category, priority, unit_id, description}` + multipart `attachments[]` |
| `GET` | `/api/v1/me/maintenance-requests/{id}` | Detail incl. status timeline (from activity log) + public comments |
| `POST` | `/api/v1/me/maintenance-requests/{id}/comments` | Public comment (server forces `is_internal=false`) — body: `{body}` |
| `POST` | `/api/v1/me/maintenance-requests/{id}/cancel` | Cancel — only if status ∈ ['submitted', 'acknowledged'] |

### 3.6 Sales Declarations

| Method | URI | Notes |
|---|---|---|
| `GET` | `/api/v1/me/sales-declarations?status=&page=` | Paginated, scoped via `whereHas('lease', tenant_id=…)` |
| `POST` | `/api/v1/me/sales-declarations` | Create — body: `{lease_id, period_start, period_end, declared_sales}`. Server enforces lease.has_percentage_rent + lease.tenant_id ownership. |
| `GET` | `/api/v1/me/sales-declarations/{id}` | Detail incl. calculated_percentage_rent + lock status |

### 3.7 Device tokens (push notifications)

| Method | URI | Notes |
|---|---|---|
| `POST` | `/api/v1/me/devices` | Register FCM/APNS token: `{platform, token, device_name}` — upsert on (tenant_id, platform, device_name) |
| `DELETE` | `/api/v1/me/devices/{id}` | Unregister |

Backend would need: `device_tokens` table + listener firing on Invoice created, Payment captured, MaintenanceRequest status change, SalesDeclaration locked.

### 3.8 Out of scope for v1

- Pay Now / payment initiation — explicit pilot decision (D-33).
- Push notifications themselves — design tied to D-29 (notification stack).
- Profile photo / media uploads.

## 4. Design rules across the surface

- **Auth**: every endpoint except the 3 auth-completion ones requires `auth:tenant-api`.
- **Scoping**: server-derived from `$request->user()->id`. **Never** trust a tenant_id passed in the body or URL.
- **Pagination**: Laravel's default JSON paginator (`data, meta.{current_page, last_page, total}, links`).
- **Errors**: 422 with `{message, errors: {field: [msgs]}}` for validation; 401 for no token; 403 for cross-tenant access attempts; 429 for rate-limited.
- **i18n**: response messages via `__('api.…')` keyed by Accept-Language header (defaults to `en`).
- **Rate limits**: login 5/min; password reset request 3/min; rest of authenticated routes 60/min per tenant.
- **PDFs**: stream as `application/pdf` with `Content-Disposition: attachment; filename=...`.
- **JSON resources**: one per model under `Http/Resources/Api/V1/` — `InvoiceResource`, `PaymentResource`, `MaintenanceRequestResource`, etc. Match the data fields the brief calls out (no PII leaks across tenants).

## 5. Implementation work estimate

| Group | LOC (approx) | Action classes | Tests |
|---|---:|---|---|
| Auth completion (3 endpoints) | 200 | LoginTenantAction is the template | LoginTest pattern |
| Profile + balance + leases (4 endpoints) | 150 | 1 action class | 4 cases |
| Invoices list + view + PDF + statement (4) | 250 | reuses InvoicePdfService / TenantStatementPdfService | 6 cases |
| Payments list + view (2) | 100 | none | 3 cases |
| Maintenance CRUD-ish (5) | 350 | reuses MaintenanceRequestService.create / transition / comment | 8 cases |
| Sales declarations (3) | 200 | reuses PercentageRentCalculationService.recalculate | 4 cases |
| Devices (2) + listeners + jobs | 400 | new ServiceProvider for listeners | 5 cases |
| **Total** | **~1650 LOC + 30 test cases** | — | — |

About 2-3 dev-days of focused work.

## 6. Findings

### 🟢 The current 3 auth endpoints are a high-quality foundation

LoginTenantAction is the single-action pattern (user preference); FormRequest validates + normalizes; tokens have `tenant:*` ability scope; thoughtful "revoke prior token with same device name" to keep "manage devices" screen sane. Anything new should follow this pattern.

### 🟡 F-71. No `/api/v1/me/*` endpoints shipped

Confirmed — `routes/api.php` has only the 3 auth endpoints. Everything else from MOBILE-APP-BRIEF.md is unshipped.

**D-56**: confirm the endpoint shortlist above + give green light to implement. Bulk implementation work, not a small inline fix.

### 🟡 F-72. No password reset flow (cross-ref M02 F-8)

Mobile app needs `forgot/reset/change-password`. Already flagged at Module 02. Bundle here.

### 🟡 F-73. No device-tokens table or push pipeline

Mobile push (FCM / APNS) needs server-side device registration + a listener that fires on key events. Tied to broader notification design (M09 F-37 / D-29).

### 🟢 LoginTest is the reference quality bar

8 cases covering valid login, wrong password, unknown email (same error to prevent enumeration), inactive, blacklisted, missing fields, same-device token revoke, cross-device token isolation. The other endpoints' tests should match this thoroughness.

## 7. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Api/V1'` | **20 passed / 0 failed** | 1.4 s |
| Full Pest | 295/295 | 4.x s |

## 8. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-56 | Approve the endpoint shortlist above; implement post-pilot or pre-pilot per Q2 mobile-app schedule | Pre-pilot for parity items (3.2-3.6); post-pilot for devices (3.7) |
| D-57 | Combine password-reset implementation across web portal (M02 F-8) + mobile API (here) | Apply both at once — single Filament `->passwordReset()` enables web; mobile gets its own endpoints sharing the underlying flow |

## 9. Verdict

**🟡 Yellow.** The auth layer is the cleanest single-action implementation in the repo; the design pattern is established. The work to ship the rest of the surface is bounded (~1650 LOC + 30 test cases) and matches the Module 02 F-10 endpoint list I cross-referenced. This module produces the **spec** rather than the code; the spec is approval-ready.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡 · 15 🟡 · 16 🟢 · 17 🟡 · 18 🟡 · 19 🟡.

## Next

Module 20 — Cross-cutting + production checklist. Surface: i18n (`lang/en` + `lang/ar`), branding/theming, queue infrastructure, storage, scheduling (already verified), env + secrets, monitoring, the deferred D-1..D-57 backlog triage, and the final 999-production-checklist.md.
