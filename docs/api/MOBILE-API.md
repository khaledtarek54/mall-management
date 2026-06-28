# Atriom Mobile API (`/api/v1`)

> Tenant-facing REST API for the Atriom mall-management mobile app.
> Base URL: `https://<host>/api/v1`
> Auth: Bearer tokens (Laravel Sanctum), `tenants` provider.
> Last updated: 2026-06-28 — added `/me/summary`, credit-notes + notifications inbox; documented Paymob session/pay-demo; invoices carry `paymentLinkUrl` + `creditAppliedAmount`; payments carry `channel` + `receiptAt`.

This document is the single reference a mobile developer needs to build the
app: the business domain, the auth model, every endpoint with request/response
shapes, error handling, and the conventions that hold across the whole surface.

---

## 0. Getting started (read first)

**Environment.** The API currently runs on the team's local/dev machine; there
is no public host yet. To integrate live (not just mocks) you need a reachable
base URL — ask the backend team for a **staging URL** or a **tunnel** to the dev
machine. Until then, the contract below is stable to build against.

**Test accounts** (seeded demo data; password is `password` for all):

| Email | Has | Notes |
|---|---|---|
| `tenant1@haya.test` | invoices, payments, maintenance | Café Crema, unit A-01 — richest data, use this one |
| `tenant2@haya.test` | invoices, maintenance | second tenant |
| `tenant3@haya.test` | invoices | third tenant |

**Smoke test:**
```bash
curl -X POST https://<host>/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"tenant1@haya.test","password":"password"}'
# → { "data": [ {lease...} ], "accessToken": "…", "tokenType": "Bearer", "message": "Login successful" }
```
Then send `Authorization: Bearer <accessToken>` on any `/me/*` endpoint.

**Settled questions** (ignore the "confirm with backend" asides further down —
these are decided): the access token is the top-level `accessToken` field on
login; lease `name` = contact person, `shop` = company/store name. Password
reset is the **two-step** email-link flow (not a single tokenless call) — see 4.1.

---

## 1. The business in five minutes

You are building the app for a **tenant** — a business that rents a unit in a
mall. Here's the world they live in, and it's all they can ever see:

- An **Operator** (e.g. *Jawad Developments*) owns one or more **Assets** (malls, e.g. *Haya Walk*).
- An **Asset** contains **Units** (shops, e.g. `A-01`).
- A **Tenant** signs a **Lease** for a Unit. A tenant usually has exactly one active lease.
- A **Lease** carries **Charges** (base rent, service charge, parking, …). Each month the mall runs billing and every active charge on the lease becomes a line on one monthly **Invoice**.
- A tenant settles invoices with **Payments**. One payment can be split across several invoices — each split is an **allocation** (`allocated_amount`).
- A tenant can raise **Maintenance Requests** against their unit, comment on them, and cancel one that hasn't been started.
- Some retail/F&B leases have **percentage rent**: the tenant must declare their monthly **Sales** (a **Sales Declaration**); when staff *lock* it, the system creates a "percentage rent" charge that lands on next month's invoice.

**The golden rule of this API:** a tenant only ever sees their own data. Every
`/me/*` endpoint is scoped server-side to the authenticated tenant. You never
send a `tenant_id` — the token *is* the identity. Asking for another tenant's
record returns **404**, never their data.

### Key money concepts

| Term | Meaning |
|---|---|
| `total` | The full invoice amount (subtotal + VAT). |
| `paid_amount` | How much has been allocated to the invoice from captured payments. |
| `balance` | `total − paid_amount`. What's still owed. |
| `outstanding` | Across all open invoices: net AR (open balances − unapplied credit notes). |
| `overdue` | The portion of `outstanding` whose `due_date` is in the past. |

VAT in Egypt is 14%. Currency is always **EGP** in the pilot.

---

## 2. Authentication

The API uses **Sanctum personal access tokens**. Flow:

1. `POST /auth/login` with email + password + a device name → you get a `token`.
2. Send it on every authenticated request:
   `Authorization: Bearer <token>`
3. `POST /auth/logout` deletes the current device's token. Other devices stay signed in.

Tokens do not expire by time; they live until logout, password change, or
password reset. Logging in again from the **same `device_name`** revokes the
previous token for that device (so the "manage devices" list stays clean) and
issues a fresh one. Different device names keep independent tokens.

**First-time tenants:** the mall admin sets an initial password and shares it
(usually via WhatsApp). Prompt the user to change it on first login via
`POST /auth/change-password`.

---

## 3. Conventions (read once, applies everywhere)

### Field casing

All JSON keys are **camelCase** in both directions (`unitNumber`, `startDate`,
`newPassword`, `deviceName`). Send camelCase; you'll receive camelCase. (The
backend works in snake_case internally and translates at the API boundary, so
either casing is accepted on input, but camelCase is the contract.)

### Response envelope

Successful responses use a consistent envelope:

```json
{ "data": { ... }, "message": "Human readable, localized" }
```

- Single resources → `data` is an object.
- Lists → `data` is an array, plus `meta` and `links` (see Pagination).
- Some write/no-content responses carry only `message`.
- File endpoints (PDFs) stream binary, not JSON.
- **Login** is the one shaped exception: `data` is the leases array and the
  token rides at the top level (see 4.1).

### Pagination

List endpoints return Laravel's paginator shape:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "currentPage": 1, "lastPage": 4, "perPage": 25, "total": 92 }
}
```

Control with `?page=N` and `?perPage=N` (default **25**, max **100**).

### Localization

Send `Accept-Language: ar` or `en`. All `message` fields and PDF documents are
returned in that language (default `en`). Arabic PDFs render right-to-left.

### Errors

Every error uses `{ "message": "...", "statusCode": <int> }`. Validation errors
additionally carry an `errors` map (camelCase field → messages):

```json
{ "message": "The email field is required.", "errors": { "email": ["The email field is required."] }, "statusCode": 422 }
```

| Status | Meaning |
|---|---|
| `400` | Malformed/missing request body (used on **login**) |
| `401` | Missing/invalid/revoked token, or wrong login credentials |
| `403` | Blocked account, or authenticated-but-not-allowed |
| `404` | Not found **or** not yours |
| `422` | Semantic validation failure (carries `errors`) |
| `429` | Rate limited (respect `Retry-After`) |
| `500` | Server error |

### Rate limits

| Scope | Limit |
|---|---|
| `POST /auth/login` | 5 / minute / (email+IP) |
| `POST /auth/forgot-password`, `POST /auth/reset-password` | 3 / minute |
| All authenticated routes | 60 / minute / tenant |

---

## 4. Endpoint reference

Legend: 🔓 public · 🔒 requires `Authorization: Bearer`.

### 4.1 Auth & session

#### 🔓 `POST /auth/login`
```json
// request — deviceName is optional (defaults to the User-Agent)
{ "email": "tenant1@haya.test", "password": "secret", "deviceName": "Khaled's iPhone 16" }
```
```json
// 200 — data is ALWAYS an array of leases; the token is at the top level
{
  "data": [
    { "id": 1, "name": "John Doe", "shop": "Acme Co", "mall": "Haya Walk",
      "unitNumber": "A-01", "startDate": "2026-01-01T00:00:00.000Z",
      "endDate": "2027-12-31T00:00:00.000Z", "isActive": true }
  ],
  "accessToken": "12|0aBcD...plaintext...",
  "tokenType": "Bearer",
  "message": "Login successful"
}
```
- `data` is always an array (0, 1, or many leases) → maps directly to `List<Lease>`.
- The token is `accessToken` at the **top level** (not inside `data`, since `data` is the leases array). *(This resolves the contradiction in the original contract; confirm the field name is what the app reads.)*
- Lease field mapping: `name` = tenant contact person (falls back to company name), `shop` = company/store name, `mall` = asset name, `unitNumber` = unit code. **Confirm `name`/`shop` with the backend if these don't match the design.**

Errors: `400` (missing/malformed body), `401` (wrong email/password), `403`
(blocked / inactive account → app shows the Blocked screen), `429` (throttled).

#### 🔒 `GET /auth/me` — current tenant profile (alias of `GET /me`).
#### 🔒 `POST /auth/logout` — revoke the current token. → `{ "message": "Signed out." }`

#### 🔒 `POST /auth/change-password`
```json
{ "currentPassword": "secret", "password": "NewPass-1", "passwordConfirmation": "NewPass-1" }
```
→ `200 { "message": "Password changed." }`. Revokes all **other** device tokens;
the current one keeps working. `422` if `current_password` is wrong or the new
password is weak / matches the old one.

> **⚠️ Password reset is a two-step, token-verified flow — not the single-call
> tokenless reset in the original contract.** A reset that takes only
> `email + newPassword` lets anyone hijack any account by email, so the backend
> requires proof-of-email-ownership via a token. **The app's Forgot Password
> screen needs to change:** step 1 collects the email and calls
> `forgot-password`; the user then opens the emailed deep link, and step 2
> (a reset screen reached from that link) calls `reset-password` with the token.

#### 🔓 `POST /auth/forgot-password`
```json
{ "email": "tenant1@haya.test" }
```
→ Always `200 { "message": "If that email is registered, a reset link has been sent." }`
(generic by design — no enumeration). `429` if throttled. The email contains a
deep link (`APP_MOBILE_RESET_URL?token=…&email=…`) the app should handle.

#### 🔓 `POST /auth/reset-password`
```json
{ "token": "<from email link>", "email": "tenant1@haya.test", "password": "NewPass-1", "passwordConfirmation": "NewPass-1" }
```
→ `200 { "message": "Password has been reset. You can now sign in." }`. Revokes
**all** the tenant's tokens. `422` if the token is invalid/expired or the
password is weak.

---

### 4.2 Profile & account

#### 🔒 `GET /me` → tenant profile
```json
{ "data": { "id": 1, "name": "Acme Co", "legal_name": "Acme Trading LLC", "type": "company",
  "email": "...", "phone": "...", "whatsapp": "...", "contact_person": "...",
  "status": "active", "tax_id": "100-200-300" } }
```

#### 🔒 `PATCH /me` — update **own contact fields only**
Editable: `phone`, `whatsapp`, `contactPerson`, `contactPersonPhone`,
`address`. Sending `name`, `email`, `status`, `taxId`, etc. is silently
ignored — those are admin-managed. Partial updates are fine (PATCH semantics).
```json
{ "phone": "+20 100 000 0000", "contactPerson": "Sara" }
```
→ `200 { "data": {…updated profile…}, "message": "Profile updated." }`

#### 🔒 `GET /me/balance` — the home-screen "Account Balance" widget
```json
{ "data": { "outstanding": 8000.00, "overdue": 5000.00, "openCount": 2,
  "currency": "EGP", "isDelinquent": true } }
```

#### 🔒 `GET /me/summary` — one-call home-screen rollup
Money owed + open work + things needing the tenant's attention, so the app
doesn't fan out to balance + maintenance + declarations + notifications.
```json
{ "data": { "outstanding": 8000.00, "overdue": 5000.00, "openInvoices": 2,
  "creditAvailable": 1500.00, "isDelinquent": true, "openMaintenance": 1,
  "disputedDeclarations": 0, "canDeclareSales": true,
  "unreadNotifications": 3, "currency": "EGP" } }
```
`canDeclareSales` is true when the tenant has an active percentage-rent lease.

#### 🔒 `GET /me/leases` — active leases (usually one)
```json
{ "data": [ { "id": 9, "reference": "LSE-HW-2026-0007", "status": "active",
  "commencementDate": "2026-01-01", "expiryDate": "2027-12-31",
  "baseRentMonthly": 10000.00, "serviceChargeMonthly": 2000.00,
  "totalMonthlyAmount": 12000.00, "currency": "EGP",
  "hasPercentageRent": true, "percentageRentRate": 5.00,
  "unit": { "id": 4, "code": "A-01", "floor": "G", "category": "retail",
    "areaSqm": 120.00, "asset": { "id": 1, "name": "Haya Walk", "code": "HW" } } } ] }
```

---

### 4.3 Invoices

#### 🔒 `GET /me/invoices` — paginated, newest first
Query: `status`, `period_from`, `period_until` (YYYY-MM-DD, against `issue_date`),
`page`, `per_page`.
```json
{ "data": [ { "id": 50, "number": "INV-HW-202605-0001", "status": "overdue",
  "issueDate": "2026-05-01", "dueDate": "2026-05-10",
  "periodStart": "2026-05-01", "periodEnd": "2026-05-31",
  "subtotal": 12000.00, "vatAmount": 1680.00, "total": 13680.00,
  "paidAmount": 0.00, "creditAppliedAmount": 0.00, "balance": 13680.00, "currency": "EGP",
  "isOverdue": true, "daysOverdue": 22,
  "paymentLinkUrl": "https://app.../pay/abc123",
  "etaStatus": "valid", "etaSubmissionId": "...", "etaLongId": "...",
  "lease": { "id": 9, "reference": "LSE-HW-2026-0007", "unit": { "id": 4, "code": "A-01", "floor": "G" } } } ],
  "meta": { "currentPage": 1, "lastPage": 4, "perPage": 25, "total": 92 }, "links": { ... } }
```
Invoice `status` ∈ `draft`, `issued`, `partially_paid`, `overdue`, `paid`,
`cancelled`, `credited`, `disputed`.
`eta_*` are the Egyptian Tax Authority e-invoice references — present once the
invoice is accepted; use them to show a "tax-registered" badge.

#### 🔒 `GET /me/invoices/{id}` — detail with line items
Adds `items: [{ id, description, type, amount, vat_rate, vat_amount, total }]`.
`type` ∈ `base_rent`, `service_charge`, `utility`, `parking`, `percentage_rent`,
`marketing`, `late_fee`, `other`.
`creditAppliedAmount` is the portion of `paidAmount` covered by applied credit
notes (vs cash). `paymentLinkUrl` is a shareable no-login Paymob link (null once
nothing is owed) — the app can share it or open it in a WebView.

#### 🔒 `GET /me/invoices/{id}/pdf` — streams `application/pdf`
Bilingual (follows `Accept-Language`), `Content-Disposition: attachment`.
Ideal for the native share sheet / WhatsApp share.

#### 🔒 `GET /me/statement` — Statement of Account PDF
12-month trailing window of invoices + payments + summary. Streams
`application/pdf`.

---

### 4.4 Payments

#### 🔒 `GET /me/payments` — paginated, newest first
Query: `method`, `status`, `page`, `per_page`.
```json
{ "data": [ { "id": 31, "reference": "PAY-202605-0004", "amount": 13680.00,
  "currency": "EGP", "method": "instapay", "status": "captured",
  "paymentDate": "2026-05-09", "gateway": null,
  "channel": "mobile_api", "receiptAt": "2026-05-09T11:30:00+00:00",
  "allocations": [ { "invoiceId": 50, "invoiceNumber": "INV-HW-202605-0001", "allocatedAmount": 13680.00 } ] } ],
  "meta": { ... }, "links": { ... } }
```
`method` ∈ `card`, `bank_transfer`, `instapay`, `wallet`, `cash`, `cheque`,
`other`. `status` ∈ `initiated`, `authorized`, `captured`, `reconciled`,
`settled`, `failed`, `refunded`, `bounced`. **Only `captured` payments reduce a
balance** — show others as informational.

#### 🔒 `GET /me/payments/{id}` — detail with allocations.
`channel` ∈ `mobile_api`, `portal`, `payment_link`, `admin` (how the payment was
taken). `receiptAt` is when the captured-payment receipt fired (null until captured).

#### 🔒 `POST /me/invoices/{id}/paymob-session` — start an in-app payment
Returns a Paymob session (`paymentToken` + `iframeUrl`) tagged with the
`mobile_api` channel. Hand the token to the Paymob Flutter SDK (native card form,
Apple/Google Pay) or open `iframeUrl` in a WebView. The authoritative result
comes from the S2S webhook — **poll `GET /me/invoices/{id}`** for the invoice to
flip to `paid`; don't trust the SDK's local result. Errors: `404` not yours,
`409` Paymob disabled, `422` nothing payable, `429` throttled (5/min), `502`
upstream. (Alternatively, share `invoice.paymentLinkUrl` — a no-login web link.)

#### 🔒 `POST /me/invoices/{id}/pay-demo` — simulate a payment (Paymob disabled only)
Marks the invoice paid through the real capture path (no gateway). Returns `409`
once Paymob is live. For demo/staging environments without a live PSP.

---

### 4.5 Credit notes

#### 🔒 `GET /me/credit-notes` — paginated, newest first
Operator-issued credits on the tenant's account. Query: `status` (`issued` /
`applied` / `void`), `page`, `per_page`.
```json
{ "data": [ { "id": 7, "number": "CN-HW-2026-0003", "status": "issued",
  "reason": "adjustment", "subtotal": 1500.00, "vatAmount": 0.00, "total": 1500.00,
  "appliedAmount": 0.00, "balance": 1500.00, "currency": "EGP",
  "issueDate": "2026-05-12", "appliedAt": null } ],
  "meta": { ... }, "links": { ... } }
```

#### 🔒 `GET /me/credit-notes/{id}` — detail (adds the linked `invoice`).
`404` if not yours. Read-only — credits are issued by the operator.

---

### 4.6 Notifications (in-app inbox)

#### 🔒 `GET /me/notifications` — paginated, newest first
Pass `?unread=1` for unread only.
```json
{ "data": [ { "id": "9b1c...", "type": "PaymentReceivedNotification",
  "data": { "title": "Payment received", "invoiceNumber": "INV-HW-202605-0001" },
  "read": false, "readAt": null, "createdAt": "2026-05-09T11:30:00+00:00" } ],
  "meta": { ... }, "links": { ... } }
```
`type` is the short notification class name — branch on it in the app.

#### 🔒 `GET /me/notifications/unread-count` → `{ "data": { "unreadCount": 3 } }` (badge)
#### 🔒 `POST /me/notifications/{id}/read` — mark one read (`404` if not yours).
#### 🔒 `POST /me/notifications/read-all` — mark every unread read.

---

### 4.7 Maintenance requests

#### 🔒 `GET /me/maintenance-requests` — paginated, newest first
Query: `status`, `page`, `per_page`.
```json
{ "data": [ { "id": 12, "reference": "MR-HW-2026-0012", "title": "AC not cooling",
  "description": "...", "status": "in_progress", "priority": "high",
  "category": "hvac", "channel": "portal", "isOpen": true, "isOverdue": false,
  "canCancel": false, "submittedAt": "2026-05-20T09:00:00+00:00",
  "acknowledgedAt": "...", "resolvedAt": null, "closedAt": null,
  "targetResolutionAt": "...", "resolutionNotes": null,
  "unit": { "id": 4, "code": "A-01", "floor": "G" } } ],
  "meta": { ... }, "links": { ... } }
```
`status` ∈ `submitted`, `acknowledged`, `in_progress`, `awaiting_tenant`,
`resolved`, `closed`, `cancelled`. `priority` ∈ `low`, `medium`, `high`,
`urgent`. `category` ∈ `electrical`, `plumbing`, `hvac`, `structural`,
`cleaning`, `safety`, `other`. Use **`can_cancel`** to show/hide the cancel
button (true only while `submitted`/`acknowledged`).

#### 🔒 `POST /me/maintenance-requests` — submit
```json
{ "title": "AC not cooling", "description": "Stopped cooling yesterday.",
  "category": "hvac", "priority": "high", "unitId": 4 }
```
`unitId` is optional — if omitted the server uses your active lease's unit. If
provided, it must be a unit on one of *your* leases (else `422`). → `201` with
the created request. The team is notified automatically.

#### 🔒 `GET /me/maintenance-requests/{id}` — detail with public comment thread
Adds `comments: [{ id, body, authorKind: "tenant"|"staff", authorName, createdAt }]`.
**Internal staff notes are never returned.** Staff identities are shown
generically as "Property team".

#### 🔒 `POST /me/maintenance-requests/{id}/comments`
```json
{ "body": "Any update on this?" }
```
→ `201` with the created comment.

#### 🔒 `POST /me/maintenance-requests/{id}/cancel`
No body. → `200` with the cancelled request. `422` if work has already started
(status past `acknowledged`).

---

### 4.8 Sales declarations (percentage-rent leases only)

Only relevant for leases where `has_percentage_rent = true`. For other tenants,
hide this section of the app.

#### 🔒 `GET /me/sales-declarations` — paginated, newest period first
Query: `status`, `page`, `per_page`.
```json
{ "data": [ { "id": 7, "periodStart": "2026-05-01", "periodEnd": "2026-05-31",
  "periodLabel": "May 2026", "declaredSales": 200000.00,
  "calculatedPercentageRent": 5000.00, "status": "submitted",
  "isLocked": false, "declaredAt": "2026-06-01T08:00:00+00:00", "lockedAt": null,
  "lease": { "id": 9, "reference": "LSE-HW-2026-0007" } } ],
  "meta": { ... }, "links": { ... } }
```
`status` ∈ `submitted`, `locked`, `disputed`.

#### 🔒 `POST /me/sales-declarations` — submit
```json
{ "leaseId": 9, "periodStart": "2026-05-01", "periodEnd": "2026-05-31", "declaredSales": 200000 }
```
Server enforces: the lease is yours **and** has percentage-rent terms (`422` →
`leaseId`), and one declaration per `(lease, periodStart)` (`422` →
`periodStart`). On success it returns `201` with `calculatedPercentageRent`
already computed so you can show the estimated liability. The figure is final
only once staff **lock** it (which then creates the charge on next month's
invoice).

---

### 4.9 Push device tokens

Register the device's FCM (Android) / APNS (iOS) token so the backend can target
pushes. (The push *delivery* pipeline ships after the pilot; registering now is
safe and forward-compatible.)

#### 🔒 `POST /me/devices`
```json
{ "platform": "ios", "token": "<apns-or-fcm-token>", "deviceName": "Khaled's iPhone 16" }
```
`platform` ∈ `ios`, `android`. Upserts on `(tenant, platform, deviceName)` —
calling it again with a refreshed token replaces, never stacks. → `201`.

#### 🔒 `DELETE /me/devices/{id}` — unregister. → `200`, or `404` if not yours.

---

## 5. Suggested screen → endpoint map

| Screen | Endpoint(s) |
|---|---|
| Login / first-run | `POST /auth/login`, `POST /auth/change-password` |
| Forgot password | `POST /auth/forgot-password` → `POST /auth/reset-password` |
| Home / dashboard | `GET /me/balance`, `GET /me/maintenance-requests?status=…` |
| Invoices list / detail | `GET /me/invoices`, `GET /me/invoices/{id}` |
| Invoice PDF / share | `GET /me/invoices/{id}/pdf` |
| Statement | `GET /me/statement` |
| Payments | `GET /me/payments`, `GET /me/payments/{id}` |
| Maintenance | `GET/POST /me/maintenance-requests`, `GET /{id}`, `POST /{id}/comments`, `POST /{id}/cancel` |
| Sales declarations | `GET/POST /me/sales-declarations`, `GET /{id}` |
| Profile / settings | `GET /me`, `PATCH /me`, `GET /me/leases` |
| App launch (push) | `POST /me/devices`; on logout: `DELETE /me/devices/{id}` |

---

## 6. Not in v1 (so you don't build against them)

- **Pay Now / payment initiation** (Paymob) — deferred (D-33).
- **Push delivery** — token registration exists; the server-side fan-out lands post-pilot.
- **Media uploads** on maintenance requests (native camera capture) — text-only for now.
- **Profile photo / KYC document upload.**

---

## 7. For backend maintainers

The implementation lives under `app/Http/Controllers/Api/V1/` (thin invokable
controllers grouped by domain), `app/Http/Requests/Api/V1/` (validation +
typed accessors), `app/Http/Resources/Api/V1/` (JSON shaping), and
`app/Actions/Api/V1/` (one single-action class per write use-case; actions
delegate to the shared domain services — `MaintenanceRequestService`,
`PercentageRentCalculationService`, `InvoicePdfService`,
`TenantStatementPdfService` — so mobile and the web portal share one code path).
Controllers extend `ApiController` for the `{data, message}` envelope, pagination
clamping, and PDF streaming helpers. Routes + throttles are in `routes/api.php`.
Tests: `tests/Feature/Api/V1/` (53 cases). Locale resolves from
`Accept-Language` via `App\Http\Middleware\SetApiLocale`. The camelCase contract
is bridged by `SnakeCaseRequestKeys` + `CamelCaseResponseKeys` middleware (using
`App\Support\KeyCase`); the `{message, statusCode}` error envelope is produced by
the `render` callback in `bootstrap/app.php`. Login error codes (400/401/403)
live in `LoginRequest::failedValidation` + `LoginTenantAction`.
