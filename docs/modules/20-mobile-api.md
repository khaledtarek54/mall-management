# Mobile API (v1, Sanctum)

> RESTful JSON API for tenant-facing mobile app (Android/iOS) with Sanctum token authentication, invoice/payment management, maintenance requests, and sales declarations.

## 1. Purpose & business context

The Mobile API (v1) provides a dedicated entry point for the tenant-facing mobile application. Unlike the web/admin flows (`/admin`, `/portal`), which are session-based and use Filament, the mobile API is **stateless token-based** and returns **JSON exclusively**. It's scoped to a single tenant per request (the authenticated user) and enforces **strict tenant isolation**: IDs from one tenant never resolve to data belonging to another, and cross-tenant enumeration is prevented by returning 404 (not 403) for unauthorized access.

The API powers:
- **Authentication**: login (issuing Sanctum tokens), logout, password reset/change
- **Profile & Balance**: tenant company details, account balance, `GET /me/summary` home-screen rollup, leases
- **Invoices & Payments**: full invoice history (incl. `paymentLinkUrl`, ETA refs, `creditAppliedAmount`), line items, PDF statements, payment history + allocations (incl. `channel` + `receiptAt`)
- **Paymob Integration**: initiate card payment sessions (iframe + mobile SDK flows, `mobile_api` channel), validate HMAC callbacks
- **Credit Notes**: read-only list/detail of operator-issued credits (`GET /me/credit-notes`)
- **Notifications**: in-app inbox — list, unread-count, mark-read, mark-all-read (`GET/POST /me/notifications`)
- **Maintenance**: submit maintenance requests, comment, cancel, track status
- **Sales Declarations**: declare monthly/quarterly sales for percentage-rent leases
- **Device Registration**: push-notification token management (FCM/APNS)

> The full endpoint reference (request/response shapes) lives in [`docs/api/MOBILE-API.md`](../api/MOBILE-API.md).

All routes are versioned under `/api/v1` and are protected by the `auth:tenant-api` Sanctum guard (except public endpoints: login, forgot/reset password). Responses follow a standard JSON envelope: `{ data, message?, meta?, links? }`. Validation errors return 422. Rate limiting is enforced: login 5/min, password reset 3/min, authenticated endpoints 60/min.

## 2. Domain model

| Table | Model | Key columns | Constraints & meaning |
|-------|-------|-------------|----------------------|
| `tenants` | `Tenant` | `id`, `name`, `legal_name`, `email` (unique), `password` (hashed), `phone`, `whatsapp`, `tax_id`, `national_id`, `status` enum(`active`/`inactive`/`blacklisted`), `metadata` (json), `deleted_at` (soft) | Merchant/store entity. Authenticates via mobile API. Password reset tokens stored in `tenant_password_reset_tokens`. |
| `personal_access_tokens` | `PersonalAccessToken` (Laravel Sanctum) | `id`, `tokenable_type`, `tokenable_id`, `name`, `token` (hashed), `abilities` (json), `last_used_at`, `created_at`, `expires_at` | Sanctum tokens issued by login. `abilities` contains `['tenant:*']`. One token per device. |
| `invoices` | `Invoice` | `id`, `tenant_id`, `lease_id`, `number`, `status` enum, `issue_date`, `due_date`, `period_start`, `period_end`, `subtotal`, `vat_amount`, `total`, `paid_amount`, `credit_applied_amount`, `balance`, `currency`, `eta_status`, `deleted_at` (soft) | Core receivable. Fetched by tenant via `/invoices`. `balance = total - paid_amount - credit_applied_amount`. |
| `payments` | `Payment` | `id`, `tenant_id`, `amount`, `currency`, `method` (enum: cash/card/cheque/etc), `status` enum(`initiated`/`captured`/`failed`/`refunded`), `payment_date`, `gateway` (paymob/demo/etc), `gateway_transaction_id`, `gateway_response` (json), `receipt_notified_at`, `deleted_at` (soft) | Payment transaction. Allocated to invoices via `payment_invoice` pivot (stores `allocated_amount`). Captured payments fire `PaymentReceivedNotification`. |
| `payment_invoice` | — | `payment_id`, `invoice_id`, `allocated_amount` | Many-to-many: one payment can cover multiple invoices. |
| `leases` | `Lease` | `id`, `tenant_id`, `unit_id`, `reference`, `status` enum(`active`/`expired`/`etc`), `commencement_date`, `expiry_date`, `base_rent_monthly`, `service_charge_monthly`, `has_percentage_rent` (bool), `percentage_rent_rate`, `percentage_rent_threshold`, `deleted_at` (soft) | Tenant's occupancy contract. The mobile app shows leases on login. |
| `units` | `Unit` | `id`, `code`, `floor`, `asset_id` | Physical space. Identified by `code` (e.g., "B-214"). |
| `assets` | `Asset` | `id`, `name` | Mall/building. Shown in mobile login (mall name). |
| `maintenance_requests` | `MaintenanceRequest` | `id`, `reference`, `tenant_id`, `unit_id`, `status` enum, `priority`, `category`, `title`, `description`, `submitted_at`, `channel` ('portal'/etc), `deleted_at` (soft) | Tenant-reported issues. Accepted via `/maintenance-requests`. Attachments stored via Spatie Media. |
| `maintenance_comments` | `MaintenanceComment` | `id`, `request_id`, `author_id`, `body`, `created_at` | Comments on requests (tenant + staff). |
| `tenant_sales_declarations` | `TenantSalesDeclaration` | `id`, `lease_id`, `period_start`, `period_end`, `declared_sales`, `calculated_percentage_rent`, `status` enum(`submitted`/`approved`/`etc`), `declared_at` | Monthly sales for percentage-rent leases. Percentage rent = `(declared_sales - threshold) * rate` (if declared_sales > threshold, else 0). |
| `device_tokens` | `DeviceToken` | `id`, `tenant_id`, `platform` (fcm/apns), `token`, `device_name`, `last_used_at` | Push token. Upserted on register (deduped by tenant + platform + device_name). |
| `tenant_password_reset_tokens` | — | `email`, `token` (hashed), `created_at` | One-time reset tokens (separate table so tenant + user emails don't collide). Expires in 60 minutes. |

**Key relationships** (from Tenant):
- `Tenant::invoices()` — HasMany
- `Tenant::payments()` — HasMany
- `Tenant::leases()` — HasMany (includes expired/historical)
- `Tenant::activeLeases()` — HasMany filtered to `status = 'active'`
- `Tenant::maintenanceRequests()` — HasMany
- `Tenant::salesDeclarations()` — HasManyThrough Lease
- `Tenant::users()` — HasMany (portal login accounts; mobile uses only Tenant itself)
- `Tenant::deviceTokens()` — HasMany
- `Invoice::lease()` — BelongsTo
- `Invoice::items()` — HasMany (line-item breakdown)
- `Payment::invoices()` — BelongsToMany (via `payment_invoice` pivot with `allocated_amount`)
- `Lease::unit()` — BelongsTo (master unit)

## 3. Business rules & invariants

**Authentication & Authorization:**
- Only `status = 'active'` tenants can log in. Inactive/blacklisted users receive 403 + message "account_blocked". (See `LoginTenantAction::handle`.)
- Each token has `abilities: ['tenant:*']` (no granular per-endpoint scoping; all authenticated endpoints treat `:*` as "allowed").
- Token revocation is explicit: logout deletes the current token, password reset/change revokes all *other* tokens (keeping the current session alive for UX).
- A tenant cannot access another tenant's data; all show/list endpoints are scoped via `$request->user()->invoices()`, etc. (not a global query). Cross-tenant access returns **404** (not 403) to prevent enumeration. (See `ShowInvoiceController`, `InitiatePaymobSessionController`.)

**Invoice & Payment:**
- Invoice statuses: `issued`, `partially_paid`, `overdue`, `paid`, `cancelled`, `credited`. Only invoices with `balance > 0` and status NOT IN (`cancelled`, `credited`) are payable.
- Payment statuses: `initiated`, `captured`, `failed`, `refunded`. Only `captured` payments increment the invoice's `paid_amount`.
- A payment is allocated to invoices via the `payment_invoice` pivot. Invoice `balance = total - paid_amount - credit_applied_amount`. The balance is recomputed on every payment save.
- Allocation is idempotent: `PaymentReceivedNotification` is fired once per payment (guarded by `receipt_notified_at`).

**Paymob Session Initiation:**
- Idempotent within a `REUSE_WINDOW_SECONDS` (2700s / 45 min) window. If an 'initiated' Payment exists for the invoice created within that window, its stored session is returned without a fresh Paymob API call. This avoids burning the upstream budget on retries. (See `PaymobPaymentInitiator::start`, `PaymobPaymentInitiator::findReusableSession`.)
- Session requires `integrations.paymob.enabled = true` (env-gated). If false, returns 409 and directs the client to use the demo-pay endpoint instead.
- Paymob payment tokens expire in 3600s (PaymobClient::PAYMENT_TOKEN_TTL_SECONDS); the reuse window is shorter so tokens never expire mid-checkout.
- S2S callback (POST `/paymob/callback`) is HMAC-verified using the payload + signature from Paymob; invalid HMAC logs a warning and returns 401. Callback is idempotent: if a payment is already captured/failed, it skips processing and returns 200 (so Paymob stops retrying).

**Demo Payment (dev-only):**
- Only active when `PAYMOB_ENABLED = false`. When true, returns 409 and instructs the app to use the real Paymob flow.
- Demo payment goes through the *exact* capture path as Paymob (creates 'initiated' Payment → allocates → flips to 'captured'), so testing invoice/balance behavior is byte-for-byte identical to production.

**Password Reset:**
- Public endpoint (no auth required). Rate-limited to 3 requests per minute per IP.
- Never reveals whether an email is registered (anti-enumeration). Unknown email returns 200 + generic message, no notification sent.
- Reset links are sent via email using a mobile app deep-link (from `APP_MOBILE_RESET_URL` env or fallback). Link includes `token` + `email` query params; app captures and POSTs to `/api/v1/auth/reset-password`.
- Reset tokens expire in 60 minutes and are stored in `tenant_password_reset_tokens` (separate from the user/staff reset table to prevent collisions).

**Maintenance Requests:**
- Tenant can submit, comment, view, and cancel their own requests. Cancellation is only allowed on certain statuses (guarded by action logic, not the endpoint).
- Attachments (images + PDFs) are validated on upload; invalid MIME types are rejected (422). URLs are returned in the 201 response for immediate rendering.
- Each request gets a unique `reference` (auto-generated) for tracking.

**Sales Declarations:**
- Only valid for leases with `has_percentage_rent = true`. Posting to a lease without percentage rent returns 422.
- Duplicate check: one declaration per lease per period (period_start + period_end). Re-declaring the same period is rejected (422).
- Percentage rent is calculated as: `if (declared_sales > percentage_rent_threshold) then (declared_sales - threshold) * rate else 0`.
- A tenant can only declare on their own leases; cross-tenant attempts return 422.

**Account Balance & Delinquency:**
- Outstanding balance = sum of invoice balances (where status IN `issued`, `partially_paid`, `overdue`) minus credit-note balances.
- Delinquent: a tenant with at least one invoice where `balance > 0`, `due_date < now()`, and status IN (`issued`, `partially_paid`, `overdue`).
- Balance endpoint returns: `outstanding`, `overdue` (sum of past-due portions), `open_count` (invoices with balance > 0).

**Rate Limiting:**
- Login: 5 requests per 1 minute.
- Password reset: 3 requests per 1 minute.
- All authenticated endpoints: 60 requests per 1 minute (configurable via `throttle:60,1` middleware).
- Throttled requests return 429.

## 4. Lifecycle / state machine

**Tenant Account:**
- Created by admin → `status = active` (default).
- Can transition: `active` → `inactive` (manually by admin via Filament) or `active` → `blacklisted` (admin action).
- Only `active` tenants can authenticate. Login attempt on `inactive`/`blacklisted` returns 403.
- Deleted tenants are soft-deleted; their data (invoices, payments, etc.) remains queryable but flagged with `deleted_at`.

**Token Lifecycle:**
- Issued at login with `abilities = ['tenant:*']`, named after the device (e.g., "iPhone" or User-Agent if omitted).
- Multiple tokens per tenant allowed (e.g., user logs in on two devices). Each token is independent.
- Logged-out explicitly via DELETE `/api/v1/auth/logout` (deletes the current token).
- Password change/reset revokes all *other* tokens; the current session remains valid.
- Tokens are soft-deleted (SoftDeletes on PersonalAccessToken in Sanctum), so historical tokens are queryable for audit.

**Invoice:**
- Created by the accounting system: `status = issued`.
- Transitions:
  - `issued` → `partially_paid` (payment received but balance > 0).
  - `partially_paid` / `issued` → `paid` (balance = 0).
  - Any status → `overdue` (automatic, based on due_date < now in queries; no status change, just a computed field).
  - Any status → `cancelled` (if not paid; may revert if re-invoiced).
  - Any status → `credited` (if credit note issued).
- Immutable once paid/cancelled. The app displays these states and filters by status.

**Payment:**
- Created in `initiated` status (via Paymob initiator or demo action).
- Paymob callback (S2S) flips: `initiated` → `captured` (success) or `initiated` → `failed`.
- Demo payment flows: `initiated` → `captured` immediately (no gateway call).
- Once `captured`, the payment is final and fires `PaymentReceivedNotification`.
- Refunds are possible but rare (manual via Filament); status flips to `refunded`.

**Maintenance Request:**
- Submitted by tenant: `status = submitted`.
- Transitions: `submitted` → `in_progress` (staff), → `resolved`, → `closed`, or → `cancelled` (tenant, under conditions).
- Comments accumulate on the request. Tenant can view all comments (their own + staff responses).

**Sales Declaration:**
- Submitted by tenant: `status = submitted`.
- Transitions: `submitted` → `approved` (admin), or `submitted` → `disputed`.
- Once approved, a corresponding invoice line item may be generated (out-of-scope for mobile API).

## 5. Services, jobs & scheduled commands

**Key action classes** (in `app/Actions/Api/V1/*`):

| Action | Signature | Behavior | Transaction | When called |
|--------|-----------|----------|-------------|-------------|
| `LoginTenantAction` | `handle(string $email, string $password, string $deviceName): array{tenant, token, leases}` | Validates email/password, checks status, issues Sanctum token, revokes prior token for same device, returns tenant + token + leases with eager-loaded unit/asset. | Within the controller; no DB transaction. | POST `/api/v1/auth/login` |
| `ChangeTenantPasswordAction` | `handle(Tenant $tenant, string $current, string $new): void` | Verifies current password, updates to new (hashed on assign via cast), revokes all OTHER tokens (keeps current). | No explicit transaction; `update()` is atomic. | POST `/api/v1/auth/change-password` |
| `SendTenantPasswordResetLinkAction` | `handle(string $email): string` (returns `Password::*` status) | Looks up email, creates reset token, sends `TenantResetPasswordNotification` (email + deep-link). Never leaks whether email is registered. | No transaction. | POST `/api/v1/auth/forgot-password` |
| `ResetTenantPasswordAction` | `handle(array $credentials): string` (returns Password::* status) | Validates token + email, resets password, revokes ALL tokens (fresh start). | No explicit transaction. | POST `/api/v1/auth/reset-password` |
| `RegisterDeviceTokenAction` | `handle(Tenant $tenant, array $data): DeviceToken` | Upserts on (tenant, platform, device_name); updates token + last_used_at. | No transaction. | POST `/api/v1/me/devices` |
| `UnregisterDeviceTokenAction` | `handle(Tenant $tenant, int $id): void` | Deletes the device token (soft-delete). | No transaction. | DELETE `/api/v1/me/devices/{id}` |
| `CreateMaintenanceRequestAction` | `handle(Tenant $tenant, array $payload, UploadedFile[] $attachments): MaintenanceRequest` | Creates request, stores attachments via Spatie Media, returns with media URLs. | DB transaction (request + media). | POST `/api/v1/me/maintenance-requests` |
| `AddMaintenanceCommentAction` | `handle(MaintenanceRequest $req, Tenant $tenant, string $body): MaintenanceComment` | Adds comment from tenant, scoped to tenant's own request. | No transaction. | POST `/api/v1/me/maintenance-requests/{id}/comments` |
| `CancelMaintenanceRequestAction` | `handle(MaintenanceRequest $req, Tenant $tenant): void` | Cancels request if in cancellable status, checks ownership. | No transaction. | POST `/api/v1/me/maintenance-requests/{id}/cancel` |
| `CreateSalesDeclarationAction` | `handle(Tenant $tenant, array $payload): TenantSalesDeclaration` | Validates lease ownership + percentage-rent eligibility + no-duplicate-period, calculates percentage rent, creates declaration. | DB transaction. | POST `/api/v1/me/sales-declarations` |
| `RecordDemoPaymentAction` | `handle(Invoice $invoice): Payment` | Creates `initiated` Payment with full invoice balance, allocates, flips to `captured` (via Status cast triggers Payment::saved). Bypasses Paymob. | DB transaction (payment + allocation). | POST `/api/v1/me/invoices/{id}/pay-demo` |

**PaymobPaymentInitiator** (in `app/Services/Paymob/`):

| Method | Behavior | Idempotency |
|--------|----------|------------|
| `start(Invoice $invoice): array{payment_token, iframe_url, order_id, payment_id, expires_at, reused}` | Checks for reusable 'initiated' session within REUSE_WINDOW_SECONDS (2700s). If found, returns cached session. Else calls PaymobClient to authenticate → create order → request payment key, stores 'initiated' Payment, allocates full invoice balance, returns session. | Idempotent within reuse window: retries within 45min return the same session without a new Paymob call. |

**PaymobClient** (in `app/Services/Paymob/`):

| Method | Behavior | Throws |
|--------|----------|--------|
| `authenticate(): string` | POST `/api/auth/tokens` with API key, returns bearer token. | RuntimeException if API fails. |
| `createOrder(Invoice $invoice): int` | POST `/api/ecommerce/orders` with amount + merchant metadata, returns Paymob order_id. | RuntimeException. |
| `requestPaymentKey(int $orderId, array $billing): string` | POST `/api/acceptance/payment_keys` with order + billing info, returns payment_token. | RuntimeException. |
| `buildPaymentSession(Invoice $invoice): array{payment_token, iframe_url, order_id}` | Chains all 3 steps, constructs iframe URL. | RuntimeException if any step fails. |
| `verifyHmac(array $payload, string $signature): bool` | HMAC-SHA256 verification: payload → JSON → HMAC(secret) == signature. Used by callback controller. | N/A (returns false on mismatch). |
| `fromConfig(): self` | Factory from config; throws RuntimeException if credentials missing. | RuntimeException. |

**Paymob Callback** (POST `/paymob/callback` in `app/Http/Controllers/Paymob/CallbackController`):

| Method | Behavior | Idempotency |
|--------|----------|------------|
| `processed(Request $request): JsonResponse` | Verifies HMAC signature (401 if invalid). Extracts `obj.order.id` + `obj.id` (txn id), finds matching `initiated` Payment by `gateway_transaction_id = paymob:order:{orderId}`. If not found or already processed (status in `captured`/`failed`/`refunded`), returns 200 (idempotent). Else: parses `obj.success` + `obj.is_voided`, flips Payment status to `captured` (if success && !voided) or `failed`, saves. Payment::saved hook recomputes Invoice + fires PaymentReceivedNotification. | Idempotent: if payment already processed, returns 200 + "skipped". |
| `returned(Request $request): RedirectResponse` | Browser redirect after iframe close. Extracts `?success=true\|false` (UX-only; not authoritative). Redirects to `/portal/invoices` with flash message. S2S callback is the source of truth. | N/A (idempotent redirect). |

**No scheduled commands** in scope for the mobile API. Invoice aging, ETA submissions, and overdue notifications are handled by admin-facing cron jobs outside this module.

## 6. Filament resources & key fields

**The mobile API has no Filament resources** — it is entirely decoupled from the admin panel. Filament resources exist for admin management of Tenants, Invoices, Payments, Maintenance, etc., but the mobile API uses only **Models + Actions + Controllers**.

However, **key validation & business logic** is shared via:
- **Form Requests** (in `app/Http/Requests/Api/V1/*`): Validate incoming payloads.
  - `LoginRequest`: email, password, device_name (optional).
  - `ChangePasswordRequest`: current_password, password, password_confirmation.
  - `ForgotPasswordRequest`: email (exists validation).
  - `ResetPasswordRequest`: token, email, password, password_confirmation.
  - `RegisterDeviceRequest`: platform (enum: fcm/apns), token, device_name (optional).
  - `CreateMaintenanceRequestRequest`: title, description, category, priority, attachments (files, optional).
  - `CreateSalesDeclarationRequest`: lease_id, period_start, period_end, declared_sales (numeric).

- **Resources** (in `app/Http/Resources/Api/V1/*`): Format response data.
  - `TenantResource`: id, name, legal_name, type, email, phone, whatsapp, contact_person, status, tax_id (re-exposed for ETA).
  - `InvoiceResource`: id, number, status, issue_date, due_date, period_start, period_end, subtotal, vat_amount, total, paid_amount, balance, currency, is_overdue, days_overdue, eta_status, eta_submission_id, items (when eager-loaded), lease (when eager-loaded).
  - `PaymentResource`: id, reference, amount, method, status, payment_date, allocations (pivot data with invoice numbers + amounts).
  - `MaintenanceRequestResource`: id, reference, status, priority, category, title, description, submitted_at, attachments (media URLs).
  - `TenantSalesDeclarationResource`: id, lease_id, period_start, period_end, declared_sales, calculated_percentage_rent, status, declared_at.
  - `PaymobSessionResource`: payment_token, iframe_url, order_id, payment_id, expires_at, reused, iframe_id.
  - `DeviceTokenResource`: id, platform, device_name, last_used_at.
  - `LoginLeaseResource`: id, name (contact_person | tenant name), shop (tenant name), mall (asset name), unit_number (unit code), start_date, end_date (ISO 8601 Zulu), is_active.

- **No tenant-scoping or RBAC permissions** on the mobile API (unlike Filament). Auth is token-based (Sanctum) + code-level scoping in controllers (e.g., `$request->user()->invoices()->findOrFail($id)`). A token is either valid (and grants all abilities) or invalid (401).

## 7. Notifications & integrations

**Notifications sent to Tenant:**

| Notification | Trigger | Channels | Via |
|--------------|---------|----------|-----|
| `TenantResetPasswordNotification` | POST `/api/v1/auth/forgot-password` | mail | SendTenantPasswordResetLinkAction. Sends deep-link (from APP_MOBILE_RESET_URL env) with token + email query params. |
| `PaymentReceivedNotification` | Payment status flips to `captured` (via Payment::saved hook or manual Payment::notifyReceiptOnce) | mail, database | Fired once per payment (guarded by receipt_notified_at). Includes invoice numbers + amounts. Database channel for portal bell notifications. |

**Paymob Integration:**

- **Enabled via env:** `PAYMOB_ENABLED` (bool), `PAYMOB_BASE_URL`, `PAYMOB_API_KEY`, `PAYMOB_INTEGRATION_ID`, `PAYMOB_IFRAME_ID`, `PAYMOB_CURRENCY`, `PAYMOB_HMAC_SECRET`.
- **Flow:** InitiatePaymobSessionController → PaymobPaymentInitiator::start → PaymobClient (3-step auth → order → payment key) → returned payment_token + iframe_url.
- **Callback:** Server-to-server webhook at POST `/paymob/callback` (HMAC-verified) → CallbackController::processed → flips Payment status → recomputes Invoice → fires PaymentReceivedNotification.
- **Error handling:** Paymob API errors are caught and returned as 502 to the client (InitiatePaymobSessionController line 74).
- **Demo fallback:** If PAYMOB_ENABLED=false, the demo-pay endpoint bypasses Paymob entirely and marks the invoice paid directly (for testing/sandbox).

**Media & File Storage:**

- Maintenance request attachments (images + PDFs) are stored via Spatie MediaLibrary under the 'public' disk. Validated on upload (MIME types: image/*, application/pdf). Media URLs are returned in API responses.
- Invoice PDFs are generated on-the-fly via TenantStatementPdfService and streamed (not stored).

**Localization:**

- API supports locale switching via query param `?locale=ar` (Arabic) or `?locale=en` (English). Middleware SetApiLocale applies it.
- Response messages are localized (e.g., `__('auth.login_success')`, `__('api.payment_changed')`). See `lang/en/api.php` and `lang/ar/api.php`.

## 8. Extension points — how to change/extend SAFELY

**Adding a new authenticated endpoint:**

1. Define the route in `routes/api.php` under the `auth:tenant-api, throttle:60,1` middleware group.
2. Create a controller in `app/Http/Controllers/Api/V1/{Feature}/` extending `ApiController`.
3. Scope queries to the authenticated tenant: `$request->user()->invoices()->...` (never `Invoice::where(...)`).
4. Return data via a Resource class (in `app/Http/Resources/Api/V1/`) for consistent casing/structure.
5. **Add tests** to `tests/Feature/Api/V1/{Feature}Test.php` covering: happy path, 401 (missing auth), 404 (cross-tenant access), and edge cases.

**Modifying authentication or token handling:**

1. Changes to LoginTenantAction flow require updating tests/Feature/Api/V1/Auth/LoginTest.php and MobileApiScenarioTest.php.
2. Token abilities (currently all `tenant:*`) are hardcoded in LoginTenantAction::handle. Adding granular abilities requires:
   - Updating the token creation: `$tenant->createToken(..., abilities: ['tenant:invoices', 'tenant:payments'])`.
   - Adding ability checks in individual controllers (e.g., `$request->user()->tokenCan('tenant:invoices')` before returning invoices).
   - Update tests to verify ability gates.
3. Never skip Sanctum's built-in authentication; avoid custom guards without documented security review.

**Modifying Paymob integration:**

1. PaymobClient is tested via fake HTTP responses (see InitiatePaymobSessionTest). Any changes to API calls or HMAC verification require:
   - Update PaymobClient method signatures.
   - Update PaymobPaymentInitiator to match (esp. order_ref format if changing).
   - Update CallbackController::processed to match the new gateway_transaction_id format.
   - Re-fake Paymob responses in tests; verify HMAC mock logic still holds.
2. Paymob configuration is env-driven. Adding new config keys (e.g., PAYMOB_API_VERSION) requires:
   - Add to `config/integrations.php` (new file or extend existing).
   - Reference via `config('integrations.paymob.key')` in PaymobClient.
   - Document the env var in `README.md` or `.env.example`.
3. Callback signature verification (HMAC) is security-critical. Never weaken it; always test with both valid and invalid signatures.

**Modifying invoice/payment scoping:**

1. All queries in controllers are scoped via `$request->user()->invoices()`. This is the single source of tenant isolation.
   - Changing to `Invoice::where('tenant_id', $tenant->id)` is equivalent but less readable; prefer the relationship.
   - **Never** query `Invoice::find($id)` without scoping; this leaks cross-tenant data.
2. When adding a new invoice endpoint, ensure it uses `findOrFail()` (which throws ModelNotFoundException → 404) on the tenant relationship, **not** on the global Query.
3. Test cross-tenant access in MobileApiScenarioTest or per-endpoint test files (e.g., `test_returns_404_for_another_tenants_invoice()`).

**Modifying rate limiting:**

1. Rate limits are defined in routes/api.php as middleware parameters: `throttle:5,1` (5/minute), `throttle:60,1` (60/minute).
2. To change limits:
   - Update the throttle value in the route definition.
   - Document the new limit in comments.
   - No config file or database table change needed (middleware reads the route definition).
3. To add per-tenant rate limiting (e.g., premium tenants get 120/minute):
   - Implement a custom middleware that reads tenant metadata and applies a dynamic limit.
   - Use `Rate::hit()` / `Rate::reset()` from Illuminate\Support\Facades\RateLimiter.
   - Add tests to verify the limit is applied correctly.

**Adding a new payment gateway (e.g., Stripe, PayPal):**

1. Create a new service class parallel to PaymobClient (e.g., `app/Services/Stripe/StripeClient.php`).
2. Implement the same interface: `authenticate()`, `createOrder()`, `requestPaymentKey()`, `buildPaymentSession()`, `verifyHmac()`.
3. In InitiatePaymobSessionController, add a conditional:
   ```php
   if (config('integrations.stripe.enabled')) {
       $session = $initiator->start($invoice, gateway: 'stripe'); // or new StripePaymentInitiator
   } else { /* paymob logic */ }
   ```
4. Add a CallbackController method for the new gateway (e.g., `stripeCallback()`, registered in routes/web.php).
5. Update RecordDemoPaymentAction to accept a gateway parameter.
6. Add comprehensive tests (InitiatePaymobSessionTest as template).
7. **Security critical:** HMAC verification is non-negotiable; implement it for the new gateway (not a fallback).

**Changing the JSON response envelope:**

1. The envelope is defined in ApiController::ok() (lines 22–35). Responses are:
   - `{ data: ..., message: ... }` for success.
   - `{ message: ... }` for message-only (e.g., logout).
   - Validation errors (422) are Laravel's native format: `{ message: "", errors: { field: [...] } }`.
2. Changing the envelope structure requires:
   - Update ApiController::ok() to return the new shape.
   - Update all Resource classes (they inherit the shape via `toArray()`).
   - Update the exception handler to re-case the new envelope via KeyCase (see below).
   - Update mobile app contract + test all response paths (happy path, 404, 422, 429, etc.).
3. **Metadata/pagination:** Paginated resources automatically include `meta` + `links` keys (Laravel's default); don't duplicate in ApiController.

**Changing response key casing (snake_case ↔ camelCase):**

1. Responses are **re-cased to camelCase** via CamelCaseResponseKeys middleware (outgoing) and SnakeCaseRequestKeys middleware (incoming).
2. Resources define keys in snake_case; middleware does the conversion.
3. To disable casing for a specific endpoint:
   - Return a Response object (not JsonResponse) from the controller.
   - The middleware only touches JsonResponse.
4. To change casing (e.g., PascalCase):
   - Update KeyCase class (support/KeyCase.php) to implement the new transformer.
   - Update middleware to call the new method.
   - Carefully test all response paths (success, error, pagination).

**Adding a new field to an existing resource:**

1. Edit the Resource class (e.g., `InvoiceResource::toArray()`).
2. Add the field with its key.
3. If adding a relation, use `$this->whenLoaded('relation')` to avoid N+1 queries (only include if eager-loaded).
4. Update tests to assert the new field is present (or omitted if conditionally loaded).
5. Increment the resource/API contract version if it's a breaking change (optional for this v1, but document it).

**Adding validations or business rule checks:**

1. Form Request classes (in `app/Http/Requests/Api/V1/*`) define validation rules.
   - Add rules in the `rules()` method.
   - Implement custom rules via `Rule::` helpers or custom Rule classes.
   - Custom messages go in `messages()` method.
2. Business logic checks (e.g., "lease must have percentage rent") go in Action classes, not Form Requests.
   - Throw `ValidationException::withMessages()` for 422 responses from actions.
3. Test both the Form Request validation + the Action business logic:
   - Form Request test: invalid input → 422 with specific validation error.
   - Action test: valid input but failed business check → ValidationException thrown, caught by controller.

## 9. Gotchas, edge cases & recently-fixed bugs

**Tenant Status & Login:**
- Blocked tenants (status != 'active') get 403, not 401. This drives a specific "Account Blocked" screen in the app. Don't confuse with password failure (401).
- Inactive tenants can still view invoices/payments via API if they somehow have a token (the routes don't re-check status). This is intentional: a session shouldn't be invalidated mid-request if status changes. Password reset/change does revoke tokens, so a re-login is required.

**Cross-Tenant Enumeration Prevention:**
- The API returns **404 (not 403)** for cross-tenant access. This is deliberate: a 403 would confirm "row exists but you can't access it," whereas 404 is indistinguishable from "row never existed."
- All show/detail endpoints must scope queries via `$request->user()->relation()->findOrFail($id)` to ensure 404 is thrown for unauthorized access.
- List endpoints are naturally scoped via the tenant relationship, so this is less critical, but verify with tests.

**Payment Allocation & Invoice Balance:**
- Invoice `balance = total - paid_amount - credit_applied_amount`. This is denormalized (not recomputed on every query), so it must be updated whenever a payment is captured or a credit note is issued.
- The Payment::saved() hook calls Invoice::recomputeTotals(). If you create/modify payments without going through the save lifecycle (e.g., raw update), balances will be stale.
- Test: create a payment, allocate to an invoice, capture it, then verify the invoice's balance decreased.

**Paymob Session Reuse & Idempotency:**
- Retries within the REUSE_WINDOW_SECONDS (2700s / 45 min) return the cached session. This is safe because the payment_token TTL (3600s) exceeds the reuse window.
- If a client retries after 45 minutes, a *new* session is generated (new order_id, new payment_token). The old 'initiated' Payment row is not cleaned up; it remains in the DB (status='initiated') forever unless the callback flips it or an admin voids it.
- Test: create a session, wait 46 minutes, create another. Verify the order_ids are different and both are recorded.

**Device Token Upsert:**
- Device tokens are upserted on `(tenant_id, platform, device_name)`. If a tenant logs in on the same phone twice (e.g., app restart), the token is updated (not duplicated).
- If the OS rotates the token (FCM/APNS), the tenant must re-register via POST `/me/devices` with the new token. The old token is replaced (upserted).
- Test: register a device, then register the same device with a new token. Verify only one row exists + token is updated.

**Maintenance Request Cancellation State:**
- The action enforces which statuses can be cancelled (not all). This is not a route-level gate, but enforced inside CancelMaintenanceRequestAction::handle.
- If you add a new status to the enum, remember to update the action's cancellation logic.
- Test: try cancelling a request in each status; some should throw ValidationException (422).

**Sales Declaration Period Uniqueness:**
- The check is `lease_id + period_start + period_end`. If two declarations have overlapping but not identical periods (e.g., April 1-30 vs April 15-May 15), they're allowed (no overlap check, only exact-match check).
- This is by design (monthly declarations don't block quarterly ones), but document it.

**Password Reset & Token Revocation:**
- Password reset (POST `/auth/reset-password`) revokes **all** tokens for the tenant (fresh start).
- Password change (POST `/auth/change-password`) revokes all **other** tokens (keeps the current session alive).
- This is intentional: reset is a recovery flow (session may be compromised); change is a routine update (session is trusted).
- Test: after change-password, the current bearer still works, but other tokens don't.

**Notification Delivery & receipt_notified_at:**
- PaymentReceivedNotification is queued (uses Queueable). If the queue fails, the notification isn't sent, but receipt_notified_at is still set (via saveQuietly).
- This can lead to lost notifications if the queue is down. Monitor queue health; consider re-queueing failed notifications.
- Test: dispatch the notification, verify it's in the queue, then process the queue and check the database/mail.

**ETA Submission Fields:**
- The Invoice model has fields: `eta_status`, `eta_submission_id`, `eta_long_id`, `eta_submitted_at`, `eta_response`.
- These are populated by a separate ETA service (Egyptian Tax Authority integration), not by the mobile API.
- The mobile API *returns* these fields in InvoiceResource (for display), but never writes them. Don't confuse ETA submission (admin-only) with payment/invoice creation (mobile API).

**Soft Deletes:**
- Invoices, Payments, Leases, MaintenanceRequests are soft-deleted. Queries in the controllers don't filter them out explicitly (Laravel's Illuminate\Database\Eloquent\SoftDeletes automatically excludes them via `withoutTrashed()` on the default query).
- If you need to query *only* non-deleted records, use `->withoutTrashed()` explicitly. If you want to include soft-deleted rows, use `->withTrashed()`.
- Test: soft-delete an invoice, verify it doesn't appear in the list endpoint.

**Locale Switching & Message Keys:**
- The mobile API supports `?locale=ar` and `?locale=en` (middleware SetApiLocale applies it).
- All messages are localized via `__()` helper and keys in `lang/en/api.php` and `lang/ar/api.php`.
- If a key is missing from the Arabic file, `__()` falls back to English. Don't assume fallback; verify all keys exist in both files.

## 10. Tests & related modules

**Test structure** (tests/Feature/Api/V1/):
- `Auth/LoginTest.php`: Login flow, token issuance, invalid credentials (401), account blocked (403).
- `Auth/AuthenticatedRoutesTest.php`: Unauthenticated access (401), malformed bearers.
- `PasswordTest.php`: Forgot-password (generic message, anti-enumeration), reset-password (token validation), change-password (current password check, token revocation).
- `InvoicesTest.php`: List (pagination, status filter), show, PDF, statement, cross-tenant isolation (404).
- `PaymentsTest.php`: List allocations, show, cross-tenant isolation.
- `MaintenanceTest.php`: List, create (with attachments), comment, cancel.
- `SalesDeclarationsTest.php`: List, create (percentage-rent validation, period uniqueness), cross-tenant isolation.
- `DevicesTest.php`: Register, unregister, upsert.
- `Tenant/InitiatePaymobSessionTest.php`: Session initiation, HMAC verification, invoice validation, rate-limiting, reuse.
- `Tenant/DemoPayInvoiceTest.php`: Demo payment (Paymob disabled gate), payment capture, balance update.
- `Scenarios/MobileApiScenarioTest.php`: **Cross-cutting scenarios:** full token round-trip (login → protected endpoint), token isolation (tenant-api vs admin User), token lifecycle (revocation), scoping leaks, pagination clamps, ability tests.

**Helpers** (tests/Feature/Api/V1/Scenarios/MobileApiScenarioTest.php or tests/Helpers/ApiTestHelpers.php):
- `apiHeaders(Tenant $tenant, string $device = 'test'): array` — Returns `['Authorization' => "Bearer {token}"]` by creating a token directly.
- `loginAndGetToken(Tenant $tenant, string $device): string` — Exercises the login endpoint, returns the bearer from the response.
- `makeTenant(array $overrides): Tenant` — Factory for test tenants.
- `makeInvoice(Lease $lease, array $overrides): Invoice` — Factory.
- `makeLease(Unit $unit, Tenant $tenant, array $overrides): Lease` — Factory.
- `makeUnit(Asset $asset, array $overrides): Unit` — Factory.
- `makeAsset(array $overrides): Asset` — Factory.

**Test coverage** (1043 passing tests as of baseline):
- Authentication (login, logout, password flows): 100+ tests.
- Invoice list/show/filter: 50+ tests.
- Payment list/show: 30+ tests.
- Paymob session + callback: 80+ tests (including HMAC verification, reuse, error handling).
- Demo payment: 20+ tests.
- Maintenance: 60+ tests (create, attach, comment, cancel, cross-tenant).
- Sales declarations: 40+ tests (validation, calculation, uniqueness, cross-tenant).
- Scenario tests: 50+ tests (token round-trip, isolation, revocation, pagination, abilities).

**Related modules:**
- **Invoice Module** (docs/modules/10-invoicing.md): Core invoice generation, line-item breakdown, balance recomputation, ETA submission. Mobile API consumes this.
- **Payment Module** (docs/modules/11-payments.md): Payment creation, allocation, capture, refund. Mobile API initiates Paymob payments and consumes captured payments.
- **Paymob Integration** (docs/modules/30-paymob.md, if exists): Detailed Paymob API docs, HMAC, callback handling. Mobile API references this.
- **Tenant Module** (docs/modules/01-core-models.md): Tenant model, status enum, relationships. Mobile API authenticates against this.
- **Lease Module** (docs/modules/05-leases.md): Lease model, percentage-rent config, statuses. Mobile API lists leases on login and handles percentage-rent declarations.
- **Device Tokens** (mobile push integration, TBD): Future feature. Mobile API stores tokens; push fan-out is a separate job.

