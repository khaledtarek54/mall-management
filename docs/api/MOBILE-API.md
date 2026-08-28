# Atriom Mobile API (`/api/v1`)

> Tenant-facing REST API for the Atriom mall-management mobile app.
> Base URL: `https://<host>/api/v1`
> Auth: Bearer tokens (Laravel Sanctum), `tenants` provider.
> Last updated: 2026-08-22 — ⚠️ **breaking:** `etaStatus`, `etaSubmissionId` and `etaLongId` are **GONE from the invoice payload**. Module 16 (ETA e-invoicing) is frozen in code, so nothing ever files an invoice and the three keys were permanently null — which the app would have had to read as a real "not filed" answer. They are removed from `InvoiceResource` rather than gated at runtime, because `openapi.json` is generated from that method and every gated form corrupts it — a conditional spread becomes a property with an empty name, a post-return `if` becomes three REQUIRED keys the endpoint never sends. A generated spec has to describe what the endpoint actually returns. They come back with the same names and shapes when e-invoicing ships. *Previously, 2026-07-24 — ⚠️ **breaking:** `/me/maintenance-requests` → `/me/requests` (no alias, old paths `404`). Sales declarations are now a **file upload** (multipart, no `declaredSales`) with a new attachment stream. camelCase now works on **multipart** bodies too (it silently didn't before — `leaseId`/`unitId`/`requestType` were dropped). Attachment `id`/`size` and the summary/balance counts are typed correctly in the spec at last. Demo logins corrected to `@atriomwalk.test`.*

This document is the single reference a mobile developer needs to build the
app: the business domain, the auth model, every endpoint with request/response
shapes, error handling, and the conventions that hold across the whole surface.

> **Machine-readable spec:** a generated **OpenAPI 3.1** document lives at
> [`openapi.json`](openapi.json) — import it into Postman/Insomnia or feed it to
> a client codegen. It's **camelCase-accurate** (matches the wire convention,
> not the backend's snake_case). Regenerate after any API change with
> `composer api-spec` (`php artisan api:export-spec`); a contract test fails CI
> if a live `/api/v1` route is missing from it. This prose doc remains the
> human-friendly companion.

---

## 0. Getting started (read first)

**Environment.** The API currently runs on the team's local/dev machine; there
is no public host yet. To integrate live (not just mocks) you need a reachable
base URL — ask the backend team for a **staging URL** or a **tunnel** to the dev
machine. Until then, the contract below is stable to build against.

**Test accounts** (seeded demo data; password is `password` for all):

| Email | Has | Notes |
|---|---|---|
| `tenant1@atriomwalk.test` | invoices, payments, maintenance | Café Crema, unit A-01 — richest data, use this one |
| `tenant2@atriomwalk.test` | invoices, maintenance | second tenant |
| `tenant3@atriomwalk.test` | invoices | third tenant |

**Smoke test:**
```bash
curl -X POST https://<host>/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"tenant1@atriomwalk.test","password":"password"}'
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

- An **Operator** (e.g. *Jawad Developments*) owns one or more **Assets** (malls, e.g. *Atriom Walk*).
- An **Asset** contains **Units** (shops, e.g. `A-01`).
- A **Tenant** signs a **Lease** for a Unit. A tenant usually has exactly one active lease.
- A **Lease** carries **Charges** (base rent, service charge, parking, …). Each month the mall runs billing and every active charge on the lease becomes a line on one monthly **Invoice**.
- A tenant settles invoices with **Payments**. One payment can be split across several invoices — each split is an **allocation** (`allocated_amount`).
- A tenant can raise **Requests** of any type (maintenance, complaint, inquiry, access, billing query, document request, …) against their unit, comment on them, cancel one that hasn't been started, **confirm or dispute one the operator has resolved**, and rate one once it's resolved. (⚠️ **Breaking, 2026-07-19:** these endpoints moved from `/me/maintenance-requests` to `/me/requests`. There is **no alias** — the old paths `404`.)
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
  "meta": { "currentPage": 1, "lastPage": 4, "perPage": 25, "total": 92, "from": 1, "to": 25 }
}
```

Control with `?page=N` and `?perPage=N` (default **25**, max **100**).

**Every list uses this same `meta` shape**, including the endpoints that build their own payload
(`/me/feed`, `/me/marketing-posts`, `/public/malls/{code}/posts`) — those emitted four of the six
keys until 2026-08-15, so a client had two shapes to model depending on which list it read.
`links` is present only on the resource-collection endpoints; **key your paging off `meta`, never
`links`.**

> ⚠️ **`meta` is the whole reason a list is complete.** The default page is 25 and the money lists
> are newest-first, so a client that ignores `meta` silently truncates the OLDEST rows — exactly
> where long-unpaid invoices live — and any total it computes over `data` is wrong with no
> indication anything is missing. Drive paging off `currentPage < lastPage`, and treat an **absent**
> `meta` as "one page" rather than looping.
>
> The two endpoints that are deliberately **not** paginated are `GET /me/leases` and
> `GET /me/devices` — both a handful of rows, both returning a bare `{ data: [...] }`.

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
{ "email": "tenant1@atriomwalk.test", "password": "secret", "deviceName": "Khaled's iPhone 16" }
```
```json
// 200 — data is ALWAYS an array of leases; the token is at the top level
{
  "data": [
    { "id": 1, "name": "John Doe", "shop": "Acme Co", "mall": "Atriom Walk",
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
{ "email": "tenant1@atriomwalk.test" }
```
→ Always `200 { "message": "If that email is registered, a reset link has been sent." }`
(generic by design — no enumeration). `429` if throttled. The email contains a
deep link (`APP_MOBILE_RESET_URL?token=…&email=…`) the app should handle.

#### 🔓 `POST /auth/reset-password`
```json
{ "token": "<from email link>", "email": "tenant1@atriomwalk.test", "password": "NewPass-1", "passwordConfirmation": "NewPass-1" }
```
→ `200 { "message": "Password has been reset. You can now sign in." }`. Revokes
**all** the tenant's tokens. `422` if the token is invalid/expired or the
password is weak.

---

### 4.2 Profile & account

#### 🔒 `GET /me` → tenant profile
```json
{ "data": { "id": 1, "name": "Acme Co", "legalName": "Acme Trading LLC", "type": "company",
  "email": "...", "phone": "...", "whatsapp": "...", "contactPerson": "...",
  "contactPersonPhone": "+20 100 555 0000", "address": "12 Corniche El Nil, Cairo",
  "status": "active", "taxId": "100-200-300",
  "logoUrl": "https://…/storage/…/logo.png" } }
```
**Whatever `PATCH /me` accepts, `GET /me` gives back.** `contactPersonPhone` and `address` were
accepted and stored but never returned until 2026-08-15, which made them write-only — the tenant's
own edit form could not show what it had just saved.

`logoUrl` is the store's brand mark, or `null`. Safe to hand straight to an image widget: the
`logo` collection is on the **public** disk (the shopper directory renders it unauthenticated),
unlike `documents`, which shares the model and stays private.

#### 🔒 `GET /auth/me` — identical to `GET /me`
The same controller answers both, so the two can never drift. Prefer `GET /me`; it also owns the
`PATCH`.

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
  "unreadNotifications": 3, "unreadAnnouncements": 2, "currency": "EGP" } }
```
`canDeclareSales` is true when the tenant has an active percentage-rent lease.
`unreadAnnouncements` badges the **Mall news** entry (§4.12) — it counts notices the
tenant has not opened, which is a different question from the bell's unread count.

#### 🔒 `GET /me/leases` — active leases (usually one)
`parkingSpots` is the count of parking bays let alongside the premises. It is modelled separately
from the unit (a bay is not lettable *area*), which is why it is its own field rather than part of
`unit`. Absent/`0` when the lease has none.
```json
{ "data": [ { "id": 9, "reference": "LSE-HW-2026-0007", "status": "active",
  "commencementDate": "2026-01-01", "expiryDate": "2027-12-31",
  "baseRentMonthly": 10000.00, "serviceChargeMonthly": 2000.00,
  "totalMonthlyAmount": 12000.00, "currency": "EGP", "parkingSpots": 2,
  "hasPercentageRent": true, "percentageRentRate": 5.00,
  "unit": { "id": 4, "code": "A-01", "floor": "G", "category": "retail",
    "areaSqm": 120.00, "asset": { "id": 1, "name": "Atriom Walk", "code": "AW" } } } ] }
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
  "paidAt": null,
  "isOverdue": true, "daysOverdue": 22,
  "paymentLinkUrl": "https://app.../pay/abc123",
  "lease": { "id": 9, "reference": "LSE-HW-2026-0007", "unit": { "id": 4, "code": "A-01", "floor": "G" } } } ],
  "meta": { "currentPage": 1, "lastPage": 4, "perPage": 25, "total": 92 }, "links": { ... } }
```
Invoice `status` ∈ `draft`, `issued`, `partially_paid`, `overdue`, `paid`,
`cancelled`, `credited`, `disputed`.
> **`etaStatus` / `etaSubmissionId` / `etaLongId` are ABSENT** (2026-08-22). They carried the
> Egyptian Tax Authority e-invoice references for a "tax-registered" badge. Module 16 is frozen
> (`App\Support\Modules::FROZEN`), so no invoice is ever submitted and the keys could only ever be
> null — a value the app would reasonably read as "filed and rejected" or "not filed yet" rather
> than "this system does not file". Omitted rather than nulled for exactly that reason, and removed
> from the resource rather than gated at runtime so that `openapi.json`, which is generated from it,
> stays a true description of the endpoint. Do not build the badge; the keys reappear with the same
> names and shapes when e-invoicing ships.

#### 🔒 `GET /me/invoices/{id}` — detail with line items
Adds `items: [{ id, description, type, amount, vat_rate, vat_amount, total }]`.
`type` ∈ `base_rent`, `service_charge`, `utility`, `parking`, `percentage_rent`,
`marketing`, `late_fee`, `other`.
`creditAppliedAmount` is the portion of `paidAmount` covered by applied credit
notes (vs cash). `paymentLinkUrl` is a shareable no-login Paymob link (null once
nothing is owed) — the app can share it or open it in a WebView.

#### 🔒 `GET /me/invoices/{id}/pdf` — streams `application/pdf`
Bilingual, `Content-Disposition: attachment`. Ideal for the native share sheet / WhatsApp share.

**Language (CHANGED 2026-08-27).** Follows `Accept-Language`, as before — and now accepts an
explicit **`?lang=en|ar`** override. Use it when the user asks for one document in the other
language without changing the app's locale: a tenant whose accountant files in English, a landlord's
lawyer who asked for the English copy. An unsupported value falls back to `Accept-Language` rather
than failing — an unreadable parameter should not cost the caller their invoice.

Deliberately, the REQUEST wins here over the `locale` now stored on the tenant record. On the admin
panel a document defaults to the recipient's stored language, because an operator is producing it
for somebody else; on the API the caller **is** the recipient and has already said what they read.
Letting the column override that would mean a tenant who switches the app to English keeps receiving
Arabic PDFs with no way to change it from inside the app.

#### 🔒 `GET /me/statement`
Query: **`from`**, **`to`** (YYYY-MM-DD), **`lang`** (`en|ar`, CHANGED 2026-08-27 — see the invoice
PDF above for the rule). Omit both for the documented **12-month trailing**
window. State the window if you intend to print it: the endpoint used to hard-code the period and
report nothing about what it covered, so a client printing a range beside the PDF was printing a
device-clock guess. — Statement of Account PDF
12-month trailing window of invoices + payments + summary. Streams
`application/pdf`.

---

### 4.4 Payments

#### 🔒 `GET /me/payments` — paginated, newest first
Query: `method`, `status`, **`from`**, **`to`** (YYYY-MM-DD, against `payment_date`), `page`,
`per_page`.

`from`/`to` exist so "cleared this period" can be a real period. Without them the query set was
`method`/`status`/`page` only — there was no date to pass, so a client either invented one from the
device clock or softened the label.
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

#### 🔒 `GET /me/payments/{id}/receipt` — the receipt voucher (سند قبض) as a PDF
The same document the admin table and the portal hand out — one service, so all three surfaces
give the tenant a byte-identical file. RTL follows `Accept-Language`, or **`?lang=en|ar`**
(CHANGED 2026-08-27 — see the invoice PDF above for the rule).

Only for a payment whose money actually **arrived** (`captured` / `reconciled` / `settled`):
anything else returns **`422`** with a message you can show, because a receipt asserts cash was
received. `404` if the payment is not yours. Gate the button on `status` — or simply on
`receiptAt` being non-null, which is the same moment.

#### 🔒 `POST /me/invoices/{id}/paymob-session` — start an in-app payment
Returns a Paymob session (`paymentToken` + `iframeUrl`) tagged with the
`mobile_api` channel. Hand the token to the Paymob Flutter SDK (native card form,
Apple/Google Pay) or open `iframeUrl` in a WebView. The authoritative result
comes from the S2S webhook — **poll `GET /me/invoices/{id}`** for the invoice to
flip to `paid`; don't trust the SDK's local result. Errors: `404` not yours,
`409` Paymob disabled, `422` nothing payable, `429` throttled (the surface's
60/min), `502` upstream. Idempotent — repeat taps inside 45 min return the same
session (`reused: true`), so no double-tap guard is needed. (Alternatively,
share `invoice.paymentLinkUrl` — a no-login web link.) Full contract +
server-side rules: [docs/integrations/PAYMOB.md](../integrations/PAYMOB.md).

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
  "link": { "target": "payment", "id": 31 },
  "read": false, "readAt": null, "createdAt": "2026-05-09T11:30:00+00:00" } ],
  "meta": { ... }, "links": { ... } }
```
`type` is the short notification class name — branch on it for the ICON and the wording.

**`link` is where it opens. Do not infer a destination from `type`.** Added 2026-08-15: the app was
matching substrings of the class name against its own table, which failed silently — it looked for
a `maintenanceId` that has never existed (the payload has always carried `request_id`), so the two
most frequent tenant alerts deep-linked nowhere, and `LateFeeApplied…` / `LeaseExpiryApproaching…`
matched no keyword at all and fell through while carrying a perfectly good id.

`target` ∈ `invoice` · `payment` · `request` · `sales` · `announcement` → `/invoices/{id}`,
`/payments/{id}`, `/requests/{id}`, `/sales/{id}`, `/news/{id}`.

`link` is **`null` when there is nowhere to go** — a staff-only record (a work order, a vendor
document) or a record the app has no screen for. Render the row unclickable; never invent a route.
The identical key rides on the **push** payload, so a push tap and an inbox tap resolve to the same
screen through the same code path.

#### 🔒 `GET /me/notifications/unread-count` → `{ "data": { "unreadCount": 3 } }` (badge)
#### 🔒 `POST /me/notifications/{id}/read` — mark one read (`404` if not yours).
#### 🔒 `POST /me/notifications/read-all` — mark every unread read.

---

### 4.12 Mall news (announcements)

Notices the mall office sent to this tenant — works, trading hours, events. Delivered as a push +
an inbox row (`AnnouncementNotification`, §4.6) **and** kept here as a post the tenant can re-read.
A push tap should deep-link to `announcementId` from the notification payload.

**Both languages ship on every row and the client picks.** `titleAr`/`bodyAr` are null when the
operator wrote only English — render `title`/`body` in that case rather than a blank.

#### 🔒 `GET /me/announcements` — paginated; pinned first, then newest
Pass `?unread=1` for unopened only.
```json
{ "data": [ { "id": 42, "category": "operations",
  "title": "Loading bay closed", "titleAr": "إغلاق منطقة التحميل",
  "body": "Friday, all day.", "bodyAr": "الجمعة، طوال اليوم.",
  "heroUrl": "https://…/api/v1/me/announcements/42/hero/91",
  "isPinned": false, "sentAt": "2026-08-15T09:00:00+00:00",
  "expiresAt": "2026-08-22T00:00:00+00:00",
  "read": false, "readAt": null,
  "property": { "code": "AW", "name": "Atriom Walk" } } ],
  "meta": { … }, "links": { … } }
```
`category` ∈ `general` · `operations` · `event` · `emergency` · `hours` — render a chip, and
colour `emergency` differently. `expiresAt` may be null (a standing notice); a notice past its
expiry is already gone from this list.

#### 🔒 `GET /me/announcements/{id}` — one notice in full
`404` if it was never sent to you. **Does not** mark it read — that is deliberate, so a push
preview or a prefetch never counts as somebody having seen it.

#### 🔒 `POST /me/announcements/{id}/read` — record that this tenant opened it
```json
{ "data": { "id": 42, "read": true, "readAt": "2026-08-15T10:12:00+00:00" } }
```
Idempotent: the FIRST read is what stays recorded. Call it when the detail screen is actually
shown to a person, not on prefetch. `404` if not yours.

#### 🔒 `GET /me/announcements/{id}/hero/{media}` — the artwork
Streamed from a **private** disk (a notice can carry an evacuation map), so it needs the
`Authorization` header like any other endpoint — it is not a public CDN URL. `heroUrl` above is
already this route; it is null when the notice has no image.

---

### 4.7 Requests (tenant requests — any type)

#### 🔒 `GET /me/requests` — paginated, newest first
Query: `status`, `page`, `per_page`.
```json
{ "data": [ { "id": 12, "reference": "MR-AW-2026-0012", "requestType": "maintenance",
  "title": "AC not cooling", "description": "...", "status": "in_progress",
  "priority": "high", "category": "hvac", "channel": "portal",
  "isOpen": true, "isOverdue": false, "canCancel": false,
  "canRate": false, "canConfirm": false, "confirmedAt": null,
  "csatRating": null, "csatComment": null,
  "submittedAt": "2026-05-20T09:00:00+00:00",
  "acknowledgedAt": "...", "resolvedAt": null, "closedAt": null,
  "targetResolutionAt": "...", "resolutionNotes": null,
  "requiresDecision": false, "decision": null, "decisionReason": null, "decidedAt": null,
  "validFrom": null, "validTo": null, "scheduledFrom": null, "scheduledTo": null,
  "unit": { "id": 4, "code": "A-01", "floor": "G" } } ],
  "meta": { ... }, "links": { ... } }
```
`requestType` ∈ `maintenance`, `complaint`, `inquiry`, `access`, `billing`,
`document`, `permit`, `other`. `status` ∈ `submitted`, `acknowledged`, `in_progress`,
`awaiting_tenant`, `resolved`, `closed`, `cancelled`. `priority` ∈ `low`,
`medium`, `high`, `urgent`. `category` is the **type's sub-category** (e.g.
maintenance → `electrical`…`other`; access → `parking`…; `null` for types with
none). Use **`canCancel`** to show/hide the cancel button (true only while
`submitted`/`acknowledged`), **`canRate`** to show the rating prompt (true once
`resolved`/`closed`), and **`canConfirm`** to show the *confirm / not fixed* pair
(true only while `resolved` — see below).

#### The outcome — `requiresDecision` · `decision` · `decisionReason`

> 🚨 **Never infer approval from `status`.** Added 2026-08-15 because a client did, and had to:
> `resolved`/`closed` was read as "Approved", so **a staff rejection displayed to the tenant as an
> approval** on a permit card. The status is identical either way — that is the whole point.

- **`requiresDecision`** — whether this type is a *question*. True for `permit`, `access` and
  `document` (the tenant is asking for permission or for a thing); false for everything else.
- **`decision`** ∈ `approved` · `rejected` · `null`.
- **`decisionReason`** — why it was refused. Present on rejections; **show it**, or the tenant
  resubmits the same request unchanged.
- **`decidedAt`** — when the mall answered.

**`null` has two meanings and `requiresDecision` is how you tell them apart:**

| `requiresDecision` | `decision` | Render |
|---|---|---|
| `false` | `null` | Never a question — show the status normally |
| `true` | `"approved"` | **Approved** |
| `true` | `"rejected"` | **Rejected** + the reason |
| `true` | `null` | **Outcome unknown** — a row predating this field. *Not* an approval. |

Only the last row should ever be rare: the server refuses to resolve a decidable request without
an answer, so a new one cannot be created.

#### The permit window — `validFrom` · `validTo` · `scheduledFrom` · `scheduledTo`

`validFrom`/`validTo` (dates) are the permit's **validity**; `scheduledFrom`/`scheduledTo`
(datetimes) are when the work or visit is booked. These columns have existed since 2026-07-18 and
were operator-editable in admin, but were not on the wire until 2026-08-15 — so a client had to
derive a validity from what the tenant typed while the mall's authoritative answer sat unread.
**Render the server's window; do not compute one.** All four are null for request types that have
no window.

#### 🔒 `POST /me/requests` — submit (any request type)
```json
{ "requestType": "complaint", "title": "Loud music next door",
  "description": "...", "category": "noise", "priority": "high", "unitId": 4 }
```
Send as **`multipart/form-data`** when attaching photos/PDFs (`attachments[]`,
1–5 files, image or PDF, ≤10 MB each); plain JSON is fine when there are none.
camelCase field names work in **both** encodings.

`requestType` is optional and defaults to `maintenance` (so older builds that
only send `category` keep working). `category` is required for types that define
sub-categories (maintenance, access, document, complaint) and must be one of
that type's values; types without sub-categories (inquiry, billing) omit it.
`unitId` is optional — if omitted the server uses your active lease's unit; if
provided it must be a unit on one of *your* leases (else `422`). → `201` with
the created request, auto-routed to the type's default team, which is notified.

#### 🔒 `GET /me/requests/{id}` — detail with public comment thread
Adds `comments: [{ id, body, authorKind: "tenant"|"staff", authorName, createdAt }]`.
**Internal staff notes are never returned.** Staff identities are shown
generically as "Property team".

#### 🔒 `POST /me/requests/{id}/comments`
```json
{ "body": "Any update on this?" }
```
→ `201` with the created comment.

#### 🔒 `POST /me/requests/{id}/cancel`
No body. → `200` with the cancelled request. `422` if work has already started
(status past `acknowledged`).

#### 🔒 `POST /me/requests/{id}/rate` — satisfaction (CSAT)
```json
{ "rating": 5, "comment": "Fast and tidy, thank you." }
```
`rating` is required (integer 1–5); `comment` optional (≤1000 chars). → `200`
with the updated request (`csatRating`/`csatComment` populated). `422` if the
request isn't `resolved`/`closed` yet (check `canRate` first). Re-rating
overwrites the previous score.

#### 🔒 `POST /me/requests/{id}/confirm` — the tenant accepts the resolution *(new 2026-08-20)*
No body. → `200` with the updated request: `status` becomes `closed` and
`confirmedAt` is populated. `422` if the request isn't `resolved` (check
**`canConfirm`** first).

#### 🔒 `POST /me/requests/{id}/dispute` — "it isn't fixed" *(new 2026-08-20)*
```json
{ "reason": "It flooded again the next morning." }
```
`reason` is **required** (≤1000 chars) — a bare "not fixed" sends an engineer back
knowing no more than the first time, and the text is posted to the request's
comment thread where the operator reads it. → `200`; `status` returns to
`in_progress` and `confirmedAt` is cleared. `422` if the request isn't `resolved`,
or if `reason` is missing/blank.

> **⚠️ This is a control, not a courtesy.** Until now the operator (or a
> seven-day auto-close timer) closed a resolved request and the tenant had no
> say. Treat `canConfirm` as a **prompt**: when it is true the app should ask
> *"Is this resolved?"* with **two** buttons — confirm and "not fixed" — rather
> than burying them in a menu. Confirming and disputing are the same decision;
> showing only one of them is worse than showing neither.
>
> Note `canConfirm` is **narrower than `canRate`**: rating stays available on a
> `closed` request (feedback after the fact), confirming does not (a control
> before closure). Do not reuse one flag for both.
>
> If the tenant does nothing, `requests:auto-close` still closes the request
> after the configured window — silence is treated as consent — and `confirmedAt`
> stays `null`, which is how the operator can tell the two apart.

---

### 4.8 Sales declarations (percentage-rent leases only)

Only relevant for leases where `has_percentage_rent = true`. For other tenants,
hide this section of the app.

The tenant **uploads their sales report file** (image/PDF) for the period rather
than typing a figure; the property team reads the number off the report, enters
it, and **locks** the declaration to bill any percentage rent. So **both** `declaredSales` and
`calculatedPercentageRent` are **`null` until staff enter the turnover** — show "Pending review".

⚠️ **`calculatedPercentageRent` used to arrive as `0` in that state, and that was a real
ambiguity**, corrected 2026-08-15: a pre-review `0` was indistinguishable from a *reviewed* period
that came in below the threshold and genuinely owes `0.00`. Those are opposite facts. The rule is
now the same on both figures — **`null` means nobody has looked yet; `0` is an answer.**

#### 🔒 `GET /me/sales-declarations` — paginated, newest period first
Query: `status`, `page`, `per_page`.
```json
{ "data": [ { "id": 7, "periodStart": "2026-05-01", "periodEnd": "2026-05-31",
  "periodLabel": "May 2026", "declaredSales": null,
  "calculatedPercentageRent": null, "status": "submitted",
  "isLocked": false, "declaredAt": "2026-06-01T08:00:00+00:00", "lockedAt": null,
  "hasReport": true,
  "attachments": [ { "id": 12, "name": "may-sales.pdf", "mimeType": "application/pdf",
    "size": 84213, "url": "https://…/api/v1/me/sales-declarations/7/attachments/12" } ],
  "lease": { "id": 9, "reference": "LSE-HW-2026-0007" } } ],
  "meta": { ... }, "links": { ... } }
```
`status` ∈ `submitted`, `locked`, `disputed`. Once staff lock it, `declaredSales`
and `calculatedPercentageRent` are populated and `status` becomes `locked`.

#### 🔒 `POST /me/sales-declarations` — submit (multipart/form-data)
Send as `multipart/form-data` — it carries file uploads:
```
leaseId=9
periodStart=2026-05-01
periodEnd=2026-05-31
attachments[]=<file>            # required, 1–5 files, image/* or application/pdf, ≤10 MB each
```
Server enforces: the lease is yours **and** has percentage-rent terms (`422` →
`leaseId`), one declaration per `(lease, periodStart)` (`422` → `periodStart`),
and at least one valid report file (`422` → `attachments`). On success it returns
`201`; the figure is entered and finalised only once staff **lock** it (which
then creates the charge on next month's invoice).

#### 🔒 `GET /me/sales-declarations/{id}/attachments/{media}` — stream a report file
Streams the uploaded sales-report file inline from the private disk, scoped to
your own declarations. A declaration id that isn't yours → `404` (no
cross-tenant disclosure). Use the `url` returned in `attachments[]` above.

---

### 4.9 Push device tokens

Register the device's FCM (Android) / APNS (iOS) token so the backend can target
pushes. **Push delivery is wired** (FCM): once the backend's Firebase creds are
set (`PUSH_ENABLED`), every tenant-facing notification — invoice issued, payment
received, request status/comment, sales declaration locked, mall news — is pushed
to the registered tokens, carrying the same title/body as the in-app inbox. Until
creds are set the backend no-ops gracefully (inbox + email still deliver), so
registering now is safe.

> ⚠️ **This section used to name the push deep-link keys as `invoiceId`,
> `maintenanceId`, `declarationId`. `maintenanceId` was never sent by anything** — every
> tenant-request notification has always emitted `request_id` → `requestId`. A client that
> implemented this paragraph faithfully got a dead deep link on the two most frequent tenant
> alerts, which is exactly what happened. **Do not read ids out of the payload at all now:** the
> push carries the same `link: { target, id }` object the inbox does (§4.6), and that is the only
> supported way to resolve a destination.

#### 🔒 `POST /me/devices`
```json
{ "platform": "ios", "token": "<apns-or-fcm-token>", "deviceName": "Khaled's iPhone 16" }
```
`platform` ∈ `ios`, `android`. Upserts on `(tenant, platform, deviceName)` —
calling it again with a refreshed token replaces, never stacks.
→ `201 { "data": { "id": 12, "platform": "ios", "deviceName": "…", "createdAt": "…" },
"message": "Device registered for push notifications." }` — keep the `id`, it is what the sign-out
`DELETE` takes.

#### 🔒 `GET /me/devices` — the devices currently registered
```json
{ "data": [ { "id": 12, "platform": "ios", "deviceName": "Khaled's iPhone 16",
  "createdAt": "2026-08-15T09:00:00+00:00" } ] }
```
Not paginated (a handful of rows). **The raw push token is never echoed back** — it is a
write-only credential from the client's side. This is what makes the `DELETE` reachable by a
client that did not perform the registration: a tenant who lost a phone can list and revoke it.

#### 🔒 `DELETE /me/devices/{id}` — unregister. → `200`, or `404` if not yours.

---

### 4.10 Marketing posts — the retailer's own offers

A retailer composes an offer/event/news card and sends it to the mall for review.
**Nothing here publishes.** The furthest a tenant call can move a post is
`pending`; only the mall's marketing team approves, and only then does a shopper
see it. Full rules: [module 36](../modules/36-marketing-posts.md).

`status` ∈ `draft` · `pending` · `published` · `rejected` · `archived`.
`type` ∈ `offer` · `event` · `news`. `audience` ∈ `visitors` · `tenants` · `both`.

Every text field has an `_ar` sibling (`titleAr`, `summaryAr`, …) — both
languages ship on every response and the client picks, so switching locale needs
no round trip.

**Two date pairs, and only one is yours.** You send `startsAt`/`endsAt` — when the
offer is *valid*, which is what the card promises a shopper. When the card is
*shown* is the mall's lever and is neither sent nor returned to you.

#### 🔒 `GET /me/marketing-posts` — your posts, newest first. `?status=` filters.
Returns the workflow fields a submitter needs: `status`, `isEditable`,
`isAwaitingReview`, **`reviewNotes`** (why the mall returned it — show this
prominently), `reviewedAt`, `publishedAt`, `viewCount`, `clickCount`.

#### 🔒 `POST /me/marketing-posts` — create a draft (multipart)
```
assetId=12  title="20% off everything"  titleAr="خصم ٢٠٪ على كل شيء"
discountLabel="20% OFF"  startsAt=2026-09-01T00:00:00Z  endsAt=2026-09-07T23:59:59Z
hero=<file>   gallery[]=<file>…
```
`assetId` must be a mall you hold an **active lease** in — otherwise `422` with the reason in
`message` (e.g. *"You have no active lease in that property, so you cannot post there."*). Every
refusal on these endpoints answers that way; show `message` to the user rather than a generic error.
`hero` is jpeg/png/webp ≤5 MB; `gallery` ≤6 files. → `201`.

`status`, `isFeatured` and `priority` are **ignored if sent** — they are not
yours to set, so don't build a UI for them.

#### 🔒 `POST /me/marketing-posts/{id}` — update (multipart)
**POST, not PATCH** — a multipart body does not survive PHP's PATCH handling
(see §3). Allowed only while `isEditable` is true (`draft` or `rejected`); a post
already with the mall returns `422` with an explanation. Re-uploading `hero`
replaces it.

#### 🔒 `POST /me/marketing-posts/{id}/submit` — send for review → `status: pending`.
#### 🔒 `POST /me/marketing-posts/{id}/withdraw` — pull it back → `status: draft`. Only while pending.
#### 🔒 `DELETE /me/marketing-posts/{id}` — bin a draft. `422` once it is with the mall or live.

You are notified (inbox + push, `type: marketing_post_reviewed`) when the mall
approves or returns a post; the payload carries the reason on a rejection.

#### 🔒 `GET /me/feed` — what's on at the malls you trade in
Everything currently running near you — other retailers' shopper offers **and**
retailer-only notices (staff discounts, trading-hours changes). Same card shape
as the public feed below: no workflow state, no one else's numbers.

---

### 4.11 Public feed — the VISITOR app (no auth)

> ⚠️ A different audience from everything above. These endpoints take **no
> token** and identify a mall by its public `code`, not by your leases. Build the
> shopper app against these; never against `/me/*`.

Rate limits are separate: **120/min** for reads, **30/min** for the click.
Everything unservable is a `404` — unknown or inactive mall, an offer outside its
window, an id from another mall. There is no `403` on this surface.

#### `GET /public/malls`
`[{ code, name, city, logoUrl }]` — how the app turns "which building am I in"
into the `code` every route below takes.

#### `GET /public/malls/{code}/posts`
The feed. `?type=offer|event|news`, `?featured=1`, `?page=`, `?perPage=`.

**The carousel and the list are one query.** Results come back featured-first;
render the leading `isFeatured` run as your carousel and the rest as the list.
There is deliberately no separate carousel endpoint — two endpoints would
eventually disagree about what's at the top.

Card shape: `id`, `type`, `title`/`titleAr`, `summary`/`summaryAr`,
`body`/`bodyAr`, `terms`/`termsAr` (the small print), `discountLabel`/`Ar` (the
badge — "20% OFF"), `startsAt`/`endsAt` (show as "valid until"), `isFeatured`,
`ctaLabel`/`Ar`, `ctaUrl`, `heroUrl`, `galleryUrls`, and `store`
(`{ id, name, nameAr, retailCategory, description, logoUrl, locations }`) or
`null` for a mall-wide post.

#### `GET /public/malls/{code}/posts/{id}` — the detail screen. Counts a view.
#### `POST /public/malls/{code}/posts/{id}/click` — the shopper tapped the CTA. → `204`.

#### `GET /public/malls/{code}/stores` — the directory. `?category=fashion|food_beverage|…`
#### `GET /public/malls/{code}/stores/{id}` — one shop plus everything it is running.

`locations` is the shop's unit code(s) **in the mall being browsed** only.

---

## 5. Suggested screen → endpoint map

| Screen | Endpoint(s) |
|---|---|
| Login / first-run | `POST /auth/login`, `POST /auth/change-password` |
| Forgot password | `POST /auth/forgot-password` → `POST /auth/reset-password` |
| Home / dashboard | **`GET /me/summary`** — one rollup, no fan-out. ⚠️ Not `/me/balance`: that is a strict subset and omits `creditAvailable`. |
| Invoices list / detail | `GET /me/invoices`, `GET /me/invoices/{id}` |
| Invoice PDF / share | `GET /me/invoices/{id}/pdf` |
| Statement | `GET /me/statement` |
| Payments | `GET /me/payments`, `GET /me/payments/{id}`, `GET /{id}/receipt` (PDF) |
| Maintenance | `GET/POST /me/requests`, `GET /{id}`, `POST /{id}/comments`, `POST /{id}/cancel` |
| Sales declarations | `GET/POST /me/sales-declarations`, `GET /{id}`, `GET /{id}/attachments/{media}` |
| Profile / settings | `GET /me`, `PATCH /me`, `GET /me/leases` |
| App launch (push) | `POST /me/devices`; on logout: `DELETE /me/devices/{id}` |
| Signed-in devices | `GET /me/devices` → `DELETE /me/devices/{id}` |
| Any notification tap | follow `link.target` + `link.id` (§4.6) — never infer from `type` |
| My offers (retailer) | `GET/POST /me/marketing-posts`, `POST /{id}`, `POST /{id}/submit`, `POST /{id}/withdraw` |
| **Mall news list / detail** | `GET /me/announcements`, `GET /{id}`, `POST /{id}/read` |
| What's on at my mall | `GET /me/feed` |
| **Visitor app — home / carousel** | `GET /public/malls/{code}/posts` (featured-first; no auth) |
| **Visitor app — offer detail** | `GET /public/malls/{code}/posts/{id}`, `POST /{id}/click` |
| **Visitor app — directory** | `GET /public/malls/{code}/stores`, `GET /stores/{id}` |

---

## 6. Not in v1 (so you don't build against them)

- **Pay Now / payment initiation** (Paymob) — deferred (D-33).
- **Push delivery** — token registration exists; the server-side fan-out lands post-pilot.
- **Media uploads** on maintenance requests (native camera capture) — text-only for now.
- **Profile photo / KYC document upload.**
- **ETA e-invoice references on invoices** (`etaStatus`, `etaSubmissionId`, `etaLongId`) — module 16
  is frozen; see the invoice-list note in §4.

---

## 7. For backend maintainers

The implementation lives under `app/Http/Controllers/Api/V1/` (thin invokable
controllers grouped by domain), `app/Http/Requests/Api/V1/` (validation +
typed accessors), `app/Http/Resources/Api/V1/` (JSON shaping), and
`app/Actions/Api/V1/` (one single-action class per write use-case; actions
delegate to the shared domain services — `TenantRequestService`,
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
