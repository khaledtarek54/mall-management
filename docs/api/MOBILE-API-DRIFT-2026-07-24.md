# Mobile ⇄ Backend API drift — audit 2026-07-24

Audit of **jawad-mobile** (Flutter) against the **live** `/api/v1` surface of the Atriom backend.
Every claim below was verified against the running route table (`php artisan route:list`) or proven
with a throwaway Pest probe — nothing here is inferred from docs.

> **Backend status: all backend-side items (B1–B3) are FIXED and on `main`.**
> Full suite green (2889 passed), PHPStan clean. Pull the latest backend before you start —
> the fixes change what the API accepts, and one of them (B1) is a prerequisite for §F.
>
> All `lib/…` file paths in this document are **relative to the jawad-mobile repo root**.
> `app/…`, `docs/…` and `config/…` paths are relative to the backend repo root.

**Root cause of most of it:** the copy of `openapi.json` bundled in the mobile repo is byte-identical
to the backend spec at commit `99c87e3`. Two contract-changing commits have landed since:

| commit | what changed |
|---|---|
| `ca32285` | sales-declaration create → `multipart/form-data`, new attachment endpoint |
| `ea12c3a` | **`/me/maintenance-requests` → `/me/requests`** (all 7 endpoints) |

---

## Summary

| # | Item | Severity | Owner | Status |
|---|---|---|---|---|
| A1 | All 7 request endpoints return **404** | 🔴 Blocker | Mobile | open |
| A2 | Attachment `id` **and** `size` crash JSON decode (both int, DTOs say String) — hits **requests *and* sales** | 🔴 Blocker | Mobile | open |
| A3 | Paymob session response crashes JSON decode (3 fields) | 🔴 Blocker | Mobile | open |
| B1 | multipart bodies not snake-cased → sales-declaration create always 422s; `unitId`/`requestType` silently dropped | 🔴 Blocker | Backend | ✅ **fixed** |
| B2 | `openapi.json` mistyped `reused`/`orderId`/`paymentId`, attachment `id`/`size`, summary+balance counts | 🟠 | Backend | ✅ **fixed** |
| B3 | Backend docs still described the removed `/me/maintenance-requests` | 🟠 | Backend | ✅ **fixed** |
| C1–C3 | 3 endpoints the app calls that don't exist | 🟡 | Both | open |
| D | 4 live endpoints the app never calls | ⚪ Info | Mobile | open |
| E | Every list silently capped at 25 rows (no pagination) | 🟠 | Mobile | open |
| F | Request form is maintenance-only (backend supports 8 types) | 🟡 | Product | unblocked |

**Net: three mobile-side blockers (A1–A3), all in the data layer, all mechanical.**

---

# A. Blockers — the app is broken against the live backend

## A1 · All 7 request endpoints 404

`/me/maintenance-requests` no longer exists. There is **no back-compat alias** — the URL was renamed,
not aliased. (Your repo's `MOBILE-API.md:63` claims the old paths were kept "for back-compat". That
line was wrong; it's corrected in the backend copy — see B3.)

**Proven:**
```
GET /api/v1/me/maintenance-requests  →  404
```

**Fix — [lib/features/requests/data/services/request_api_service.dart](lib/features/requests/data/services/request_api_service.dart):**

| line | from | to |
|---|---|---|
| 18 | `@GET('/me/maintenance-requests')` | `@GET('/me/requests')` |
| 21 | `@GET('/me/maintenance-requests/{id}')` | `@GET('/me/requests/{id}')` |
| 25 | `@POST('/me/maintenance-requests')` | `@POST('/me/requests')` |
| 36 | `@POST('/me/maintenance-requests/{id}/comments')` | `@POST('/me/requests/{id}/comments')` |
| 42 | `@POST('/me/maintenance-requests/{id}/cancel')` | `@POST('/me/requests/{id}/cancel')` |
| 47 | `@POST('/me/maintenance-requests/{id}/rate')` | `@POST('/me/requests/{id}/rate')` |

Plus the attachment stream (currently not wired at all — see D4):
`GET /me/requests/{id}/attachments/{media}`

⚠️ The doc comment at `request_api_service.dart:12-14` — *"The wire is still `/me/maintenance-requests`
… the backend contract is unchanged"* — is now false. Please delete it so it doesn't mislead the next reader.

**Response DTOs / field names are unchanged.** `MaintenanceRequestResource` etc. still match
`TenantRequestResource` field-for-field. Only the URLs moved. You do **not** need to rename the DTOs.

---

## A2 · Attachment `id` **and** `size` crash the JSON decode — requests *and* sales

Both fields are **`int`** on the wire. Both DTOs declare them `String`. This affects **two** features,
not one — the sales attachment DTO has the same `id` defect.

**Proven** — live response from `GET /api/v1/me/requests`:
```json
{ "id": 1, "name": "photo.jpg", "mimeType": "image/jpeg",
  "size": 695,
  "url": "https://…/api/v1/me/requests/1/attachments/1" }
```
`id` PHP type: `int`. `size` PHP type: `int`.

Generated code:
```dart
// maintenance_dtos.g.dart:120, :123
id:   json['id']   as String,       // 1   as String  → TypeError
size: json['size'] as String? ?? '',// 695 as String? → TypeError

// sales_dtos.g.dart:62   (its `size` is already Object? — only `id` is wrong)
id:   json['id']   as String,       // 1   as String  → TypeError
```

**Impact:** inside `guardApi`, so it surfaces as an `UnknownFailure` rather than a hard crash — but
the **entire requests list fails to load** as soon as *any* request has an attachment, and likewise
for a sales declaration with its report attached. The list endpoints eager-load media
(`->with(['unit','media'])` / `->with('lease','media')`), so this is **not** detail-only.

**Fix:**

| file:line | from | to |
|---|---|---|
| [maintenance_dtos.dart:72](lib/core/api/dtos/maintenance_dtos.dart#L72) | `required String id,` | `required int id,` |
| [maintenance_dtos.dart:75](lib/core/api/dtos/maintenance_dtos.dart#L75) | `@Default('') String size,` | `@Default(0) int size,` |
| [sales_dtos.dart:46](lib/core/api/dtos/sales_dtos.dart#L46) | `required String id,` | `required int id,` |
| [request.dart:105](lib/features/requests/domain/entities/request.dart#L105) | `required String id,` | `required int id,` |
| [request.dart:109](lib/features/requests/domain/entities/request.dart#L109) | `@Default('') String size,` | `@Default(0) int size,` + format for display in the mapper |

`RequestAttachmentMapper` ([request_mapper.dart:53-60](lib/features/requests/data/mappers/request_mapper.dart#L53-L60))
passes `id` and `size` straight through, so both ends must move together.

`SalesAttachmentResource.size` is already `Object?` — that one is fine, and can now be narrowed to
`int` too if you prefer. The `Object?`-tolerance comment there ("num or a preformatted string") was a
correct workaround for a wrong spec; **the spec is now authoritative** (see B2), so plain `int` is safe.

> ⚠️ Note the two attachment `url`s are `route(...)`-generated absolute URLs pointing at the
> **authenticated** stream. They need the Bearer header — see D4.

---

## A3 · Paymob session response crashes the JSON decode (3 fields)

Three fields are mistyped in the DTO. `PAYMOB_ENABLED=true` in the environment I audited, which means
this is the live payment path **and** `pay-demo` returns **409** — so there is no working fallback.
Check the flag on whichever environment you test against; with it off, `pay-demo` works and A3 stays
hidden until you go live.

Backend runtime values (`app/Services/Paymob/PaymobPaymentInitiator.php:93-98`):
```php
'order_id'   => (int) $session['order_id'],   // int
'payment_id' => (int) $payment->id,           // int
'reused'     => false,                        // bool
```

Mobile DTO — [lib/core/api/dtos/payment_dtos.dart:52-55](lib/core/api/dtos/payment_dtos.dart#L52-L55):
```dart
@Default('') String orderId,     // json['orderId']   as String? → TypeError on int
@Default('') String paymentId,   // json['paymentId'] as String? → TypeError on int
String? expiresAt,               // ✅ correct — ISO-8601 string
String? reused,                  // json['reused']    as String? → TypeError on bool
```

**Impact:** `POST /me/invoices/{id}/paymob-session` decodes to a failure every time. Card payment is
dead end-to-end.

**Fix:**
```dart
@Default(0) int orderId,
@Default(0) int paymentId,
@Default(false) bool reused,
```
`paymentToken`, `iframeUrl`, `iframeId` are genuinely strings — leave them.

> You followed the spec correctly here. **The spec was wrong.** It has been corrected (B2), and the
> refreshed `openapi.json` now publishes `integer`/`integer`/`boolean`.

---

# B. Backend-side — ✅ all fixed, nothing for you to do

Recorded so you know why the contract behaves differently from what you may have already worked around.
Pull the latest backend and these are simply true.

## B1 · multipart bodies were not snake-cased — ✅ fixed

The camelCase contract is implemented by `App\Http\Middleware\SnakeCaseRequestKeys`, which rewrote only
**JSON bodies** and **query strings** — never `multipart/form-data` fields. Both multipart endpoints were
therefore broken for the camelCase client we publish. It now covers all four input bags (JSON, request,
query, **files**).

**B1a — `POST /me/sales-declarations` always 422'd.** Proven before the fix — sending exactly what
`docs/api/SALES-DECLARATION-FILE-UPLOAD.md` instructs (`leaseId`, `periodStart`, `periodEnd` + `attachments[]`):
```
422  "The lease id field is required."
errors: { leaseId: […], periodStart: […], periodEnd: […] }
```
Your [sales_api_service.dart:39-46](lib/features/sales/data/services/sales_api_service.dart#L39-L46) was
**correct per the published contract all along** — do not change it. It now works as written.

**B1b — `unitId` on `POST /me/requests` was silently dropped.** Proven, tenant with leases on units 1 and 2:
```
before:  multipart unitId=2  → request filed against unit 1   ← wrong unit, NO error
after:   multipart unitId=2  → request filed against unit 2   ✅
```
No validation error was raised — the field was dropped and the backend fell back to deriving the unit from
the active lease. Silent wrong data. If you have live requests filed since the app shipped, their `unitId`
may be wrong.

**B1c — `requestType` was dropped too.** Proven: `requestType=document` + `category=lease_copy` →
`422 "The selected category is invalid."` (the type never arrived, so `category` was validated against
the *maintenance* sub-category set). This is what unblocks §F1.

Locked in by `tests/Feature/Regression/MultipartCamelCaseKeysTest.php` — 5 tests covering camelCase
sales create, `unitId`, `requestType`, camelCase file field names, and a snake_case no-regression case.

## B2 · `openapi.json` type fidelity — ✅ fixed

Scramble infers the published type from the array literal and falls back to `string` for anything it
can't resolve. Every field it got wrong is now cast explicitly at the source and the spec regenerated:

| schema / path | field | was | now |
|---|---|---|---|
| `PaymobSessionResource` | `orderId`, `paymentId` | `string` | **`integer`** |
| `PaymobSessionResource` | `reused` | `string` | **`boolean`** |
| `TenantRequestResource.attachments[]` | `id`, `size` | `string` | **`integer`** |
| `TenantSalesDeclarationResource.attachments[]` | `id`, `size` | `string` | **`integer`** |
| `/me/summary` | `openInvoices`, `openMaintenance`, `disputedDeclarations`, `unreadNotifications` | `string` | **`integer`** |
| `/me/summary` | `isDelinquent`, `canDeclareSales` | `string` | **`boolean`** |
| `/me/balance` | `openCount` | `string` | **`integer`** |
| `/me/balance` | `isDelinquent` | `string` | **`boolean`** |

**This means the `Object?`-and-coerce workaround in `dashboard_dtos.dart` is no longer needed.** It is
harmless — keep it if you prefer defensive decoding — but you can now narrow those fields to `int`/`bool`
and delete the coercion in the home mapper. Your instinct there was right; the spec has caught up.

## B3 · backend docs described removed endpoints — ✅ fixed

`docs/api/MOBILE-API.md` (9 places), `docs/api/v1.md`, `docs/modules/20-mobile-api.md`,
`docs/modules/11-maintenance.md`, `docs/qa/test-cases/11-tenant-requests.md` and `docs/PROJECT-MAP.md`
now say `/me/requests`. The false *"still under `/me/maintenance-requests` for back-compat"* claim is
replaced with an explicit breaking-change notice.

Also corrected while in there: the **demo logins in `MOBILE-API.md` were wrong** — `tenant1@haya.test`
does not exist. The seeded accounts are **`tenant1/2/3@atriomwalk.test`**, password `password`, mall
"Atriom Walk". If your smoke test never authenticated, that's why.

> Your repo's own copies (`jawad-mobile/MOBILE-API.md`, `BACKEND.md:48`) are still the stale versions —
> re-copy `docs/api/MOBILE-API.md` from the backend when you pull.

---

# C. Endpoints the app calls that do not exist

**Complete list — exactly three** (same set-difference method as §D). All three are already flagged
in-repo as `// NOT in openapi.json — app-assumed, pending backend` (`JAWAD_MOBILE_MASTER.md` #16 / #20).
Confirming their status:

| # | Call | Site | Status |
|---|---|---|---|
| C1 | `GET /me/offers` | [offers_api_service.dart:20](lib/features/offers/data/services/offers_api_service.dart#L20) | **Not built.** No backend ticket. 404 on live; `HomeCubit` treats it as empty, so no visible breakage — but the band is permanently empty in prod. Needs a product decision before any backend work. |
| C2 | `GET /me/payments/{id}/receipt` | [payments_api_service.dart:28](lib/features/payments/data/services/payments_api_service.dart#L28) | **Not built.** 404. Note `GET /me/invoices/{id}/pdf` and `GET /me/statement` *do* exist — a payment receipt PDF does not. Either drop the call or file a backend request. |
| C3 | `GET /me/devices` | [devices_api_service.dart:16-17](lib/features/devices/data/services/devices_api_service.dart#L16-L17) | **Not built.** `POST` and `DELETE /me/devices/{id}` exist; there is no list. 405/404. |

Recommend: keep C1 (cheap, degrades to empty), drop C2 and C3 unless there's a screen that needs them.

---

# D. Live endpoints the app never calls

**This list is complete — it is the full set difference, not a sample.** Computed programmatically:
every `@GET/@POST/@PATCH/@DELETE` annotation in `lib/` normalised against `php artisan route:list`,
with `/me/maintenance-requests` mapped onto `/me/requests` so the rename isn't double-counted. There
are **no raw `dio.get/post/...` calls anywhere in `lib/`** — every network call goes through a
Retrofit service — so the annotation scan captures 100% of the app's API surface.

**Reconciliation:** 38 backend endpoints − 4 never called = 34 covered; 34 + 3 phantom calls (§C) = 37
declared mobile endpoints. Both sides balance.

Not defects — recording them so the decision is explicit. `JAWAD_MOBILE_MASTER.md` #19 already judged
D1–D3 redundant; that judgement still holds.

| # | Endpoint | Note |
|---|---|---|
| D1 | `GET /auth/me` | Identical to `GET /me`. Genuinely redundant. |
| D2 | `GET /me/balance` | Subset of `/me/summary` (and omits `creditAvailable`). `BalanceResponse`/`BalanceData` DTOs exist but are dead code. |
| D3 | `GET /me/notifications/unread-count` | ≡ `summary.unreadNotifications`. `UnreadCountResponse` DTO exists, intentionally unwired. Wire it only if the bell must live-update outside a Home reload. |
| D4 | `GET /me/requests/{id}/attachments/{media}` | ⚠️ **Not redundant.** #19 says the resource's `url` "already IS the authenticated stream" — true, it *is* that route — but it needs the `Authorization: Bearer` header, so it must go through Dio, exactly like the sales attachment you already wired. Add a typed method mirroring `SalesApiService.getAttachment` rather than handing `url` to `Image.network` or the system browser. |

---

# E. Pagination — every list is silently capped at 25 rows

All six list endpoints are paginated server-side (`per_page` default **25**, hard cap **100**) and return:

```json
{ "data": [ … ],
  "links": { … },
  "meta": { "currentPage": 1, "lastPage": 4, "perPage": 25, "total": 87, "from": 1, "to": 25, … } }
```

No mobile DTO models `meta` or `links` — every `*ListResponse` decodes `data` only. And although
`getInvoices`, `getPayments`, `getCreditNotes` and `getNotifications` accept a `page` argument,
**no caller ever passes one**:

- [invoice_repository_impl.dart:57](lib/features/invoices/data/repositories/invoice_repository_impl.dart#L57) — `_api.getInvoices()`
- [payments_repository_impl.dart:23](lib/features/payments/data/repositories/payments_repository_impl.dart#L23) — `_api.getPayments()`
- [payments_repository_impl.dart:43](lib/features/payments/data/repositories/payments_repository_impl.dart#L43) — `_api.getCreditNotes()`
- [notifications_repository_impl.dart:20](lib/features/notifications/data/repositories/notifications_repository_impl.dart#L20) — `_api.getNotifications()`

`getRequests()` and `getDeclarations()` don't even expose the parameter.

**Result:** a tenant with more than 25 invoices/payments/requests can never see the 26th, with no
indication anything is missing. This is silent data loss, not a cosmetic gap.

**Fix:** add a `meta` DTO (`currentPage`, `lastPage`, `perPage`, `total`), thread `page` through the
repositories, and drive infinite scroll off `currentPage < lastPage`. `per_page` is also accepted if
you prefer larger pages (max 100).

---

# F. Feature gaps (product decisions, not bugs)

## F1 · The request form is maintenance-only

The backend supports **8** request types, each with its own subcategory set:

| `requestType` | valid `category` values |
|---|---|
| `maintenance` *(default)* | `electrical` `plumbing` `hvac` `structural` `cleaning` `safety` `other` |
| `complaint` | `noise` `cleanliness` `conduct` `other` |
| `access` | `keys_cards` `parking` `after_hours` `visitor` `delivery` |
| `document` | `lease_copy` `renewal` `termination_notice` `noc_certificate` |
| `permit` | `fit_out` `temporary_installation` `signage` `other` |
| `inquiry` | *(none — `category` is **prohibited**, sending one → 422)* |
| `billing` | *(none — prohibited)* |
| `other` | *(none — prohibited)* |

The app never sends `requestType` (so everything is filed as `maintenance`), and
`RequestCategory` ([request.dart:19-27](lib/features/requests/domain/entities/request.dart#L19-L27))
models only the 7 maintenance subcategories.

**Unblocked** — B1c is fixed, so `requestType` now survives a multipart create. To build it: send
`requestType`, make the category picker reactive to it, and **omit `category` entirely** for
`inquiry`/`billing`/`other` (it's `prohibited` — sending one is a 422, not merely ignored).

## F2 · Non-maintenance requests display as "Other"

Staff and the tenant portal can already file the other 7 types. `requestCategoryFrom`
([request_mapper.dart:80-90](lib/features/requests/data/mappers/request_mapper.dart#L80-L90)) falls
through to `RequestCategory.other`, so an `access`/`parking` request shows as **"Other"** in the app
today. No crash — the fallback is correct — but the label is wrong. Fixed by F1's enum widening.

## F3 · `logoUrl` is never sent

[tenant_dtos.dart:26](lib/core/api/dtos/tenant_dtos.dart#L26) declares `String? logoUrl`.
`TenantResource` does not emit it, so it is always `null`. Drop it, or file a backend request.

Everything else on `TenantResource` matches exactly: `id name legalName type email phone whatsapp
contactPerson status taxId`.

---

# G. Refresh the bundled spec

Replace `jawad-mobile/openapi.json` with the current backend `docs/api/openapi.json` (35 paths).

**The refreshed spec is now type-accurate** — no known mistypes remain (B2 fixed every one found).
Treat it as authoritative and codegen against it directly. The only place it still under-describes
reality is the request/response *envelope* around paginated lists (§E), which Scramble models but the
DTOs ignore.

While you're there, delete the two now-false comments in the mobile source:
- `request_api_service.dart:12-14` — *"The wire is still `/me/maintenance-requests` … the backend
  contract is unchanged"*.
- `sales_api_service.dart:13-14` and `sales_dtos.dart:8-10` — *"openapi.json is STALE for this feature"*.
  It no longer is; your implementation and the spec now agree.

---

# H. Verified-correct (no action)

Confirmed field-for-field against the live resources — these are in good shape:

- **Sales declarations.** You were correctly *ahead* of the stale spec: multipart create with no
  `declaredSales`, `hasReport`, the attachment stream, and the "Pending review" null-handling all match
  `ca32285`, and the backend now accepts the camelCase multipart you send (B1a). The one defect is the
  attachment `id` type — see A2.
- **Auth.** `accessToken` / `tokenType` / `data[]` leases envelope, change/forgot/reset password.
- **Invoices**, **Payments**, **Credit notes**, **Leases**, **Notifications**, **Devices** — all DTO
  fields match their backend resources.
- **Status + priority literals** — `submitted` `acknowledged` `in_progress` `awaiting_tenant`
  `resolved` `closed` `cancelled`; `low` `medium` `high` `urgent`. Exact match, with safe fallbacks.
- **Notification `id` as `String`** — correct, it's a UUID.
- **Error envelope** — `{ message, statusCode }`, plus `errors` (camelCased keys) on 422.
- **403 on a blocked tenant** — `EnsureTenantActive` re-checks status on *every* request and revokes
  the token, so a mid-session blacklist yields 403. Make sure the app's Blocked screen handles a 403
  arriving on any endpoint, not just login.

---

# I. Suggested order

1. **G** — pull the backend, refresh `openapi.json`, re-copy `MOBILE-API.md`. *(Do this first: it's the
   ground truth for everything below, and the demo logins now actually work.)*
2. **A1** — repoint the 7 request URLs. *(Unblocks the entire requests feature.)*
3. **A2** — fix attachment `id` + `size` types in **both** DTOs. *(Ships with A1 — A1 alone still fails
   on any request that has an attachment, and sales attachments break independently.)*
4. **A3** — fix the 3 Paymob field types. *(Unblocks card payment; `PAYMOB_ENABLED=true`, so `pay-demo`
   returns 409 and there is no fallback path.)*
5. **E** — pagination. *(Silent data loss; worth doing before go-live.)*
6. **C** — decide keep/drop on `offers`, `receipt`, `devices` list.
7. **D4** — wire the request-attachment stream through Dio.
8. **F1/F2** — request types beyond maintenance. *(Now unblocked.)*

Nothing on this list is blocked on the backend. If something doesn't behave as described here, say so
rather than working around it — every claim above was proven against a running instance, so a
disagreement means either the environment is stale or I got something wrong.

---

## Verification recipe

Reproduce any of this against a local backend (`php artisan migrate:fresh --seed`):

```bash
BASE=https://mall-management.test/api/v1

TOKEN=$(curl -s -X POST $BASE/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"tenant1@atriomwalk.test","password":"password","deviceName":"audit"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["accessToken"])')

curl -s -o /dev/null -w '%{http_code}\n' $BASE/me/maintenance-requests -H "Authorization: Bearer $TOKEN"  # 404
curl -s -o /dev/null -w '%{http_code}\n' $BASE/me/requests             -H "Authorization: Bearer $TOKEN"  # 200

# camelCase multipart now accepted (was 422 on lease_id)
curl -s -X POST $BASE/me/sales-declarations -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -F leaseId=1 -F periodStart=2026-05-01 -F periodEnd=2026-05-31 -F "attachments[]=@report.pdf"
```
