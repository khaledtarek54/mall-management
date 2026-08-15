# Mobile ↔ Backend sync audit — 2026-08-15

> ## Status (2026-08-16): both halves shipped. Backend on `main`; app in PR #98.
>
> **Every P0 and P1 in this document is closed.** What remains is listed at the bottom of §K and
> is either a product decision or a feature build, not a contract gap.
>
> | | Then | Now |
> |---|---|---|
> | mobile calls hitting a missing route | 8 | **0** |
> | pushes that deep-link nowhere | 2 of 13 | **0** |
> | lists where page 2 was unreachable | 8 of 8 | **0** |
> | `openapi.json` paths (backend / app copy) | 50 / 35 | **51 / 51, byte-identical** |
>
> Three findings in this document were **corrected while implementing** — read these before acting
> on the sections:
>
> 1. **§A2 — `ReceiptPdfService` already existed** (admin + portal both call it). The endpoint was
>    a controller, not a new service.
> 2. **§G3 — `ApiSpecContractTest` already existed** and enforces route→spec completeness, and it
>    was green. The backend spec was never stale; **only the app's copy had forked.**
> 3. **§E1 — the root cause was the CONTRACT DOC, not the app guessing.** `MOBILE-API.md` §4.9
>    documented the push key as `maintenanceId`. Nothing has ever emitted it. The app implemented
>    the written contract faithfully and the contract was wrong. Both halves are fixed.
>
> **Also fixed, and not in the original audit** — each found by reading rather than by a failure:
> a `draft` credit note was being served to tenants; the permit screen printed "Approved on" over a
> refusal; the generic request list painted a refusal green; the mall's validity window was hidden
> by the tenant's own description-parse guard; `decided_by` could be filled from the wrong auth
> guard.


> **What this is.** A field-by-field, route-by-route comparison of the Atriom backend's
> `/api/v1` surface against the Jawad mobile app as it stands **with PR #94 (`feat/mall-news`)
> merged in**. Every finding below was verified by reading both codebases — not by reading
> either side's contract document, because **both contract documents are stale** (§G).
>
> **Sources compared**
> - Backend `main` @ `4f98e4fc` — `routes/api.php` (56 route entries), 52 controllers,
>   18 API resources, 37 notification classes.
> - Mobile `feat/mall-news` @ `327f931` (PR #94, open) — 13 retrofit services (44 distinct
>   path+method declarations), 13 DTO files, 17 features.
>
> **Companion docs.** This supersedes the endpoint-existence half of
> [`MOBILE-API-DRIFT-2026-07-24.md`](MOBILE-API-DRIFT-2026-07-24.md) and answers 21 open
> items in the mobile repo's `JAWAD_MOBILE_MASTER.md` §9 parking lot.

---

## 0. Scorecard

| | Count |
|---|---|
| Backend route entries under `/api/v1` | **56** |
| …consumed by the mobile app | **38** (68%) |
| …**built and never called** | **18** (§B) |
| Mobile path+method declarations | **44** |
| …**pointed at a route that does not exist** → 404/405 on the real backend | **8** (§A) |
| Endpoints both sides have, with a **field-level mismatch** | **9** (§D) |
| Push notifications whose **deep link cannot resolve** | **2 of 13** (§E) |
| List endpoints where **page 2+ is unreachable** | **8 of 8** (§F) |

**The three that actually break a user's screen today:**

1. **The whole visitor/shopper surface is pointed at six URLs that were never built**, while the
   backend has shipped six *different* ones. Zero overlap in path, shape or types. (§C)
2. **A payment receipt download 404s** on the money screen tenants screenshot as proof. (§A2)
3. **A push about a tenant request deep-links to nothing** — the app reads `maintenanceId`, the
   backend has always sent `request_id` → `requestId`. Two of the most frequent tenant pushes. (§E1)

---

## A. Mobile calls endpoints that DO NOT EXIST

Eight declarations. All 404 (or 405) against the live backend. `useMockBackend` currently
defaults **true on every flavour including prod** (mobile §9 #44), which is the only reason
none of these has been seen failing yet.

### A1 · The six `/public/*` visitor endpoints — **none exist** ⛔ P0

| Mobile calls | Backend has | Status |
|---|---|---|
| `GET /public/mall` | — | 404 |
| `GET /public/stores` | `GET /public/malls/{code}/stores` | wrong path |
| `GET /public/stores/{id}` | `GET /public/malls/{code}/stores/{store}` | wrong path |
| `GET /public/news` | `GET /public/malls/{code}/posts?type=news` | wrong path + wrong model |
| `GET /public/news/{id}` | `GET /public/malls/{code}/posts/{post}` | wrong path |
| `GET /public/offers` | `GET /public/malls/{code}/posts?type=offer` | wrong path + wrong model |
| — | `GET /public/malls` | never called |
| — | `POST /public/malls/{code}/posts/{post}/click` | never called |

Full treatment in **§C** — this is not six path renames, it is a different contract.

Source: [`public_api_service.dart:27-42`](../../../Desktop/jawad-mobile/lib/features/visitor/data/services/public_api_service.dart) ·
[`routes/api.php:94-125`](../../routes/api.php)

### A2 · `GET /me/payments/{id}/receipt` — not built ⛔ P0

Not a dead declaration: it is **wired all the way to the UI**.
`PaymentDetailCubit.downloadReceipt()` → `GetPaymentReceiptUseCase` →
`PaymentsRepositoryImpl.getReceipt()` → `payments_api_service.dart:28`. The payment-detail
screen offers the button; on a real backend the tap 404s.

`GET /me/invoices/{id}/pdf` and `GET /me/statement` **do** exist. A per-payment receipt PDF
does not.

**Decision needed.** Either:
- **(a) Backend builds it** — `PaymentReceiptPdfService` mirroring `InvoicePdfService`, streamed
  through `ApiController::streamPdf()`, gated on `status = captured`. This is the honest fix:
  the tenant already receives a *"payment received"* notification (`receipt_notified_at` is on
  the wire as `receiptAt`), so the receipt is a document the system already claims exists.
- **(b) Mobile drops it** — remove the button, the use case, the repository method and the
  disk cache. Cheaper, but it removes a feature the design shipped.

Recommendation: **(a)**. The mobile side is already built and cached; the missing half is
~80 lines of backend.

### A3 · `GET /me/devices` (list) — not built 🔶 P2

`POST /me/devices` and `DELETE /me/devices/{id}` exist; there is no index. Mobile has
`DevicesApiService.getDevices()` → `DevicesRepositoryImpl.getDevices()` →
`GetDevicesUseCase` → `DevicesCubit`. Reachable, but no released screen renders it.

**Decision:** either add a four-line `ListDevicesController` (a "signed-in devices" screen is a
normal security affordance, and the resource already exists) or delete the mobile chain.
Recommendation: **build it** — `DeviceTokenResource` already withholds the raw token, so the
endpoint is safe by construction, and a tenant who lost a phone has no other revocation path.

---

## B. Backend serves 18 endpoints the app never calls

Not all are defects — three are genuinely redundant. The rest are **shipped capability the
tenant cannot reach**.

### B1 · Retailer marketing posts — **NOT a defect. Corrected 2026-08-16.** ✅

This was filed as a P1 on the reasoning that *"a retailer receives a push about a post they have no
screen to open"*. **That premise was wrong**, and acting on it would have meant building a
duplicate of a feature that already works.

The **tenant portal has the full surface** — `app/Filament/Portal/Resources/MarketingPosts/`, with
list, create and edit pages, gated on `Modules::enabled('marketing_posts')` and `Portal::isAdmin()`.
A retailer composes and submits there. The mobile push then tells them the outcome, and its body
carries what they need to act:

```
"Your offer needs changes"
'":title" was not approved. Reason: :reason'
```

So the flow is complete: compose in the portal, get told on the phone, fix it in the portal. The
push has no deep link because the app has no such screen — which is correct, not a dead end.

**Building the mobile surface is a feature request, not a sync fix**, and should be judged on
whether retailers want to compose offers from a phone. The eight `/me/marketing-posts` endpoints
exist for whenever that is wanted.

*(This is the fourth premise in this document that turned out to be false on inspection — see the
status block at the top. The pattern is consistent: an "X is missing" finding is usually a
mechanism living in a layer the audit did not look at.)*

### B2 · Endpoints for shipped app features 🔶 P1/P2

| Endpoint | Why it matters | Priority |
|---|---|---|
| `GET /me/requests/{id}/attachments/{media}` | **The request-detail screen cannot show its own photos.** `TenantRequestResource.attachments[].url` is an authenticated Bearer stream, but `request_detail_screen.dart` hands it to `PhotoTile.network` / `openLink`, neither of which can attach the header. The sales feature already has the correct pattern (`@DioResponseType(ResponseType.bytes)`). Mobile §9 #37. | **P1** |
| `GET /public/malls` | The bootstrap the visitor slice needs to turn "which building am I in" into the `code` every other public route takes. Without it the code has to be hard-coded and mall #2 means a store release. | **P1** (with §C) |
| `POST /public/.../click` | The CTA-tap counter. `clickCount` is what a mall marketer compares campaigns on; nothing increments it from the app. | **P2** |
| `GET /me/notifications/unread-count` | Genuinely redundant — `summary.unreadNotifications` is the same number and Home already fetches it. **Keep as-is.** | — |
| `GET /me/balance` | Genuinely redundant — a strict subset of `/me/summary`, and it omits `creditAvailable`. §6 of the mobile spec locks Home to `/me/summary`. **Keep the DTOs, don't wire a caller.** | — |
| `GET /auth/me` | Genuinely redundant — identical to `GET /me`, which also owns the `PATCH`. **Keep as-is.** | — |

**Refactor note (backend):** `MeController` and `ShowProfileController` are byte-identical
except for the class name. Consider pointing both routes at one controller so the two can never
answer differently.

---

## C. The visitor slice — a whole-contract divergence ⛔ P0

This is the single largest gap, and it is not a matter of renaming paths. The app authored a
speculative contract ahead of the backend (its own comments say so:
*"Every path here is `/public/*` and none of them exist yet"*); the backend then shipped module 36
with a **different data model**. Every axis disagrees.

### C1 · Structural: three concepts vs one

The app models **`MallNews`**, **`PublicOffer`** and **mall status** as three separate resources
on three endpoints. The backend has **one** `MarketingPost` model with `type ∈ offer|event|news`,
served from one feed, ordered `featured → priority → newest` — deliberately one query so a
carousel and a list can never disagree about what is on top.

The app has **no concept of `event`** at all. A mall event is currently unrenderable.

### C2 · Field-by-field

**Store / directory entry**

| Mobile `StoreResource` | Backend `PublicStoreResource` | Verdict |
|---|---|---|
| `id: String` (required) | `id: int` | ⛔ **type mismatch — Dart throws on decode.** The exact trap that killed the Paymob response and the attachment lists twice already. |
| `name: String` | `name` (`storeName('en')`) | ✅ |
| — | `nameAr` (`trade_name_ar`) | ⛔ **missing on mobile — the Arabic shop name never renders in an Arabic-first app.** |
| `category: String?` | `retailCategory` | ⛔ **key mismatch** → always null → every shop lists as "other". |
| `location: String?` (pre-composed, e.g. "Ground floor · F-12") | `locations: string[]` (unit codes in *this* mall) | ⛔ **name AND type mismatch.** Backend deliberately does not pre-compose. |
| `hours`, `hoursFriday`, `isOpen` | — | ⛔ backend sends nothing |
| `photoUrl`, `phone`, `whatsApp` | — | ⛔ backend sends nothing (and `phone`/`whatsapp` are confidential tenant columns — `PublicStoreResource`'s allowlist excludes them **on purpose**) |
| `logoUrl` | `logoUrl` | ✅ |
| — | `description`, `descriptionAr`, `websiteUrl`, `instagramHandle` | ⛔ shipped, unrendered |

**Offer / news card**

| Mobile | Backend `PublicMarketingPostResource` | Verdict |
|---|---|---|
| `id: String` | `id: int` | ⛔ type |
| `title`, `subtitle`, `badge`, `imageUrl`, `validUntil`, `storeId`, `storeName` | `title`/`titleAr`, `summary`/`summaryAr`, `body`/`bodyAr`, `terms`/`termsAr`, `discountLabel`/`discountLabelAr`, `startsAt`/`endsAt`, `isFeatured`, `ctaLabel`/`ctaLabelAr`, `ctaUrl`, `heroUrl`, `galleryUrls`, `store{…}` | ⛔ **almost nothing lines up.** `badge` ≈ `discountLabel`; `validUntil` ≈ `endsAt`; `imageUrl` ≈ `heroUrl`; `storeName` is inside a nested `store` object. `terms` (the small print), `galleryUrls`, `ctaUrl` and **every `_ar` field** have no home. |
| `publishedOn: String` (`YYYY-MM-DD`) | `startsAt`/`endsAt` ISO-8601 | ⛔ shape |

**Mall status**

`MallStatusResource{name, photoUrl, storeCount, floorCount, isOpen, hoursToday}` has **no backend
counterpart of any kind**. `GET /public/malls` returns `[{code, name, city, logoUrl}]` — a list,
for bootstrap, with none of those fields.

### C3 · The mall `code` — a missing dimension

Every backend public route is `/public/malls/{code}/…`. The mobile service has **no notion of a
mall code**: it calls flat, portfolio-wide paths. Adding it means a bootstrap step
(`GET /public/malls` → pick/persist a code) before any visitor screen can load. This is
architectural, not cosmetic — it is what makes a second mall possible without a store release.

### C4 · What to do

**Recommended: mobile rewrites the visitor data layer against the shipped contract.** The
backend's shape is the deliberate one — the allowlists (`PublicStoreResource`,
`PublicMarketingPostResource`) are the *security boundary* for the only unauthenticated surface
in the product, and widening them to satisfy a client-side design is exactly the change that
should be hard. Concretely:

1. Rewrite `visitor_dtos.dart` against `PublicMarketingPostResource` + `PublicStoreResource`
   (ints for ids, bilingual pairs, `locations: List<String>`, `store` nested).
2. Collapse `MallNews`/`PublicOffer` into one entity with a `type` discriminator; add `event`.
3. Add the `{code}` dimension: `GET /public/malls` at first run, persist the choice.
4. Wire `POST …/click` on CTA tap; consume the feed's `meta` for paging (§F).
5. Delete `getMallStatus()` and its card, **or** raise `isOpen`/`hoursToday` as a backend ask
   (see §H4) — the app's own comment is right that this must be server-computed.

**Backend asks that fall out of this** — each small, each optional (§H):
- `storeCount` / `floorCount` on `GET /public/malls` (cheap; two counts).
- Trading hours + `isOpen` — needs an `opening_hours` model that does not exist. **Scope it
  before promising it**; Ramadan/holiday handling is a real feature, not a column.
- A shop's own published `phone`/`whatsApp` would need **new opt-in columns**, distinct from the
  confidential `tenants.phone`. Do not widen the allowlist onto the existing ones.

---

## D. Field-level mismatches on endpoints both sides already have

### D1 · `TenantResource` — three fields the app renders and the server never sends 🔶 P1

`GET /me` publishes exactly ten keys. The app's `TenantResource` declares three more:

| Mobile field | Backend | Fix |
|---|---|---|
| `logoUrl` | not sent | **Trivial** — `Tenant::logoUrl()` already exists and is used by `PublicStoreResource`. Add `'logo_url' => $this->logoUrl()`. Closes mobile §9 #21/#66. `JawadAvatar` already resolves file → url → initials, so it lights up with no app change. |
| `contactPersonPhone` | not sent — **but `PATCH /me` accepts and stores it** | **Write-only field.** A tenant edits it, saves, reopens the screen and it is gone. Add to the resource. Closes §9 #15. |
| `address` | same — accepted by `PATCH`, never returned | same |
| `role` | not sent | App-invented (picks the Home layout). Either backend adds it or mobile drops it — it decodes to null today and falls back, so this is low-risk. Recommend **mobile drops it** unless a role concept is actually wanted on `tenants`. |

`UpdateProfileRequest` and `TenantResource` disagreeing about the same five columns is a
one-line-each fix and the clearest "both sides drifted" symptom in the audit.

### D2 · Request sub-categories — the app can only name 9 of ~23 🔶 P2

`TenantRequestType::subcategories()` defines 23 values across five types
(maintenance 7, access 5, document 4, permit 4, complaint 4). The app's `requestCategoryFrom()`
maps **9** and falls back to `RequestCategory.other`.

**Consequence:** a request filed in the admin panel as `document/lease_copy` or
`access/keys_cards` displays in the tenant's app as **"Other"**.

Note also `TenantRequest::CATEGORIES` (backend) still holds only the seven *maintenance* values —
a legacy const superseded by `TenantRequestType::subcategories()`. **Backend refactor:** delete it
or redefine it as the union, so nothing reads a list that answers only for one type.

**Mobile fix:** extend `RequestCategory` to the full 23 for the *read* path. The *write* path
correctly stays at maintenance+access — the app's own comment explains why (`inquiry`/`billing`
**prohibit** a category, so sending one is a 422, and the enum is deliberately unable to spell
that pairing). Keep that rule.

### D3 · `calculatedPercentageRent` nullability — the contract and the app disagree ⚠️ P2

Backend `TenantSalesDeclarationResource`:
```php
'declared_sales'              => $this->declared_sales !== null ? (float) … : null,   // nullable
'calculated_percentage_rent'  => (float) $this->calculated_percentage_rent,           // NEVER null
```
The app's DTO and both entity docstrings assert **both** are null until staff lock the period,
and the app's §6 rule distinguishes *"null ⇒ Pending review, never 0"* from *"below threshold = 0,
never a credit"*. On the wire a pre-lock declaration sends **`0`**, which is indistinguishable
from a locked below-threshold `0` on that field alone.

Nothing renders wrong today (the screens key off `isLocked`), but the two written rules
contradict each other. **Settle it:** either the backend emits `null` before locking (matching the
app's rule and `declaredSales`' own treatment), or the app's docs are corrected to name `isLocked`
as the only safe discriminator. Recommend the **backend change** — a derived figure over a null
input should be null, and it makes the two money fields consistent.

### D4 · `creditStatusFrom` fails CLOSED — money can vanish from the UI ⚠️ P1 (mobile-only)

`credit_note_mapper.dart:34` falls back `_ => CreditNoteStatus.voided`. Every sibling mapper
fails **open** (`invoiceStatusFrom → issued`, `paymentStatusFrom → initiated`, …). The backend
publishes `status` as a bare string with no enum, so an unrecognised literal is a live
possibility — and it renders a **live credit note as void**, i.e. the tenant's money disappears
from the screen. Change the fallback to `issued`.

### D5 · Announcement `property` block dropped 🔷 P3

`AnnouncementResource` ships `property: {code, name}` when the asset is loaded (it always is).
The mobile DTO does not model it. Harmless (json_serializable ignores unknown keys) but a
multi-property tenant cannot tell which mall a notice came from.

### D6 · Announcement query params never sent 🔶 P2

Backend supports `?unread=1`, `?page=`, `?per_page=` on `GET /me/announcements`.
`MallNewsApiService.getMallNews()` takes no parameters — so the app cannot request unread-only
and cannot reach page 2 (§F).

### D7 · `summary.unreadAnnouncements` — added by the backend, consumed by nobody 🔶 P2

The backend added a dedicated counter for exactly the band PR #94 builds:

```php
'unread_announcements' => Announcement::query()->liveFor($tenant)
    ->whereHas('recipients', fn ($q) => $q->where('tenant_id', …)->whereNull('read_at'))->count(),
```

It appears **nowhere** in the mobile codebase — not in `SummaryData`, not in the mapper, not in a
test. The mall-news entry has no badge. One field on the DTO + one line in the mapper.

### D8 · Attachment `media` path param typed inconsistently 🔷 P3

`SalesApiService.getAttachment(@Path('media') String media)` vs
`MallNewsApiService.getHero(@Path('media') int media)`. Both backend routes are
`->whereNumber('media')`. Works either way; make it `int` in both for consistency.

### D9 · Fake notification types no longer exist server-side 🔷 P3

`fake_notifications_api_service.dart` emits `MaintenanceCommentNotification` and
`MaintenanceStatusChangedNotification`. Those classes were renamed to `TenantRequest*` in the
2026-08-15 backend rename. Every flavour currently runs on the fakes (§9 #44), so **the fakes are
what the team actually sees** — they should speak the live vocabulary or the drift stays invisible.

---

## E. Push & notification deep links

Thirteen notification classes send `via: [… 'push']`. `PushChannel::wireData()` correctly
camelCases the payload (push is outbound and never passes through `CamelCaseResponseKeys`) and
sets `type` to the short class name, so the app routes a push tap through the same mapper as an
inbox tap. That plumbing is sound. The **vocabulary** is not.

### E1 · `requestId` vs `maintenanceId` — two live pushes deep-link to nothing ⛔ P0

```php
// TenantRequestStatusChangedNotification / TenantRequestCommentAddedNotification
'request_id' => $this->request->id,     →  wire: requestId
```
```dart
// notification_mapper.dart — categories `status` and `comment`
NotificationCategory.status || NotificationCategory.comment => NotificationLink(
  target: NotifTarget.request, id: sid('maintenanceId'),   // ← always null
),
```

`AppNotification.route` returns `null` when `id` is null, so **the tap does nothing** — in the
inbox *and* on a push. Verified against git history: `app/Notifications/` has **never** contained
a `maintenance_id` key (introduced as `request_id` in `01454f8d`), so this never worked; it is not
fallout from the rename.

These are the two highest-frequency tenant notifications in the system.

**Fix (mobile, one line):** read `requestId`, with `maintenanceId` as a tolerated alias if you
want belt-and-braces.

### E2 · Notification → category coverage 🔶 P2

`notificationCategoryFrom()` matches on substrings of the class name. Of the 13 push-enabled
classes, **4 fall through to `other`** and therefore have no deep link, three of them while
carrying a perfectly good id:

| Class | Payload id | App category | Link |
|---|---|---|---|
| `AnnouncementNotification` | `announcementId` | announcement | ✅ `/news/{id}` |
| `InvoiceIssuedNotification` | `invoiceId` | invoice | ✅ |
| `InvoiceOverdueTenantNotification` | `invoiceId` | invoice | ✅ |
| `PaymentReceivedNotification` | `paymentId` | payment | ✅ |
| `SalesDeclarationLockedNotification` | `declarationId` | sales | ✅ |
| `TenantRequestStatusChangedNotification` | `requestId` | status | ⛔ **E1** |
| `TenantRequestCommentAddedNotification` | `requestId` | comment | ⛔ **E1** |
| `LateFeeAppliedNotification` | `invoiceId`, `feeInvoiceId` | **other** | ⛔ no match — "latefee" contains none of the keywords |
| `LeaseExpiryApproachingNotification` | `leaseId` | **other** | ⛔ no match |
| `ViolationNoticeNotification` | `violationId` | **other** | ⛔ no app surface for violations at all |
| `MarketingPostReviewedNotification` | `marketingPostId` | **other** | ⛔ no app surface (§B1) |
| `AreaRequestRaisedNotification` | — | other | staff-facing; correctly inert |
| `AreaWorkOrderRaisedNotification` | — | other | staff-facing; correctly inert |

**The deeper problem is the mechanism.** Inferring a route from substrings of a PHP class name is
a contract that no test on either side can pin, and it has already failed twice (`maintenanceId`,
and every `Late…`/`Lease…` class). **Recommended refactor — backend:** every `toDatabase()`
already writes a `'type' => 'payment_received'` style **slug**. Publish that slug plus an
explicit link on the wire:

```php
'link' => ['target' => 'invoice', 'id' => $this->invoice->id],
```

One key, set once per notification class, read once in the app. It replaces nine lines of
guessing with a value, and a new notification declares its own destination instead of hoping the
app's substring table happens to catch it. (This mirrors what `BellChannel` already does for the
web panels — the app is the one consumer still inferring.)

### E3 · Push fan-out is built but not live 🔶

Token registration works end-to-end (§D confirms `POST /me/devices` returns
`{data: {id, platform, deviceName, createdAt}}` — **mobile §9 #33 can be closed**). The FCM
delivery job exists. It is blocked on a Firebase project + APNs key, not on code. Neither side
should treat push deep links as verified until that lands.

---

## F. Pagination — page 2 is unreachable on every list ⛔ P0

`ApiController::perPage()` defaults to **25**, caps at **100**. Every list endpoint paginates.
Laravel's resource collections return `{data, links, meta:{currentPage, lastPage, perPage, total, from, to}}`;
the hand-rolled ones (`/me/feed`, `/me/marketing-posts`, `/public/…/posts`) return `{data, meta}`.

**No mobile `*ListResponse` models `meta` or `links`.** Every one decodes `data` only. And
although `getInvoices`/`getPayments`/`getCreditNotes`/`getNotifications` declare `@Query('page')`,
**no caller ever passes one**; `getRequests()`, `getDeclarations()` and `getMallNews()` do not even
expose the parameter.

| List | Capped at | Money shown? |
|---|---|---|
| `/me/invoices` | 25 | **yes — the header's "Outstanding" folds over one page** |
| `/me/payments` | 25 | **yes** |
| `/me/credit-notes` | 25 | **yes** |
| `/me/requests` | 25 | no |
| `/me/sales-declarations` | 25 | no |
| `/me/notifications` | 25 | no |
| `/me/announcements` | 25 | no |
| `/me/leases` | *not paginated* | — |

**Why this is a money bug, not a cosmetic one.** The invoice list is newest-first, so the
truncated tail is the **oldest** invoices — exactly where long-unpaid ones live. The list header
sums "Outstanding" over what it fetched, so a tenant with 26+ invoices is shown a total that is
**quietly wrong**, with no indication anything is missing. The "Paid" filter is client-side over
the same truncated page.

**Fix (mobile).** Add a `PageMeta` DTO (`currentPage`, `lastPage`, `perPage`, `total`) to every
`*ListResponse`, thread `page` through the repositories, drive infinite scroll off
`currentPage < lastPage`.

> ⚠️ **The trap, stated so it isn't rediscovered:** `meta` must be **optional, and absent must
> mean "one page"**. Four of the five fakes emit none, so a loop on `currentPage == lastPage`
> against a missing `meta` either spins forever or silently fetches once. Teach the fakes to
> paginate *before* writing the loop.
>
> `perPage=100` turns a 92-invoice tenant into one request rather than four — use it for the
> money lists.

**Fix (backend), optional but cheap.** The three hand-rolled `meta` blocks omit `from`/`to` that
the resource-collection ones include. Harmless, but making them identical means the app has one
shape to model, not two. Also: `GET /me/leases` returns an unpaginated collection while
everything else paginates — fine (a tenant has ~1 lease), but it should be *stated*, because a
client that models `meta` everywhere will find it missing here.

---

## G. Contract artifacts have forked — and both copies are wrong ⛔ P1

This is the root cause of about half the findings above, so fix it first or the audit repeats.

### G1 · `openapi.json` — the mobile copy is 15 paths behind

| | Backend `docs/api/openapi.json` | Mobile `openapi.json` |
|---|---|---|
| paths | **50** | **35** |

Present in the backend spec, absent from the mobile's:
```
/v1/me/announcements  (+ /{id}, /{id}/read, /{id}/hero/{media})     ← PR #94's own feature
/v1/me/feed
/v1/me/marketing-posts  (+ /{id}, /submit, /withdraw)
/v1/public/malls  (+ /{code}/posts, /{code}/posts/{post},
                     /{code}/posts/{post}/click,
                     /{code}/stores, /{code}/stores/{store})
```

**PR #94 builds mall news against a spec that does not publish it.** The DTOs were hand-authored
from the prose instead — which worked, but it is the same "app leads the spec" pattern that
produced the visitor mess.

The mobile copy also declares no `securitySchemes` despite every `/me/*` route requiring a Sanctum
bearer (§9 #12).

### G2 · `MOBILE-API.md` — the mobile copy is a stale fork

The mobile repo's copy is 680 lines against the backend's 737, and diverges on substance:

| Backend `docs/api/MOBILE-API.md` | Mobile `MOBILE-API.md` |
|---|---|
| §4.10 **Marketing posts** (the retailer surface) | ❌ absent |
| §4.11 **Public feed** — the real `/public/malls/{code}/…` contract | ❌ absent; instead carries a §4.10 *"🔓 Public guest surface — **NOT BUILT SERVER-SIDE**"* listing the six imaginary paths |
| §4.12 Mall news, full | present (renumbered) |
| Paymob throttle **60/min**, idempotent within 45 min (`reused: true`) | says **5/min**, no idempotency note |
| `summary.unreadAnnouncements` documented | ❌ absent |

The mobile copy also carries a hand-added banner declaring §6 "Not in v1" stale — correct, but it
means the file is now **edited on both sides**, which is precisely what makes it a fork rather
than a copy.

### G3 · The fix

1. **Backend:** regenerate `docs/api/openapi.json` from Scramble as part of the API change
   checklist, the way `atriom:dump-*` regenerates the census and the registries. *"Never
   hand-type a registry into a doc"* already applies here — the API contract is a registry.
2. **One source, copied not edited.** `MOBILE-API.md` + `openapi.json` live in the backend; the
   mobile repo takes a **verbatim** copy. Mobile-side commentary belongs in
   `JAWAD_MOBILE_MASTER.md` §9, not inline in the contract.
3. **Add a drift check.** A `MobileContractConformanceTest` that asserts every route in
   `routes/api.php` appears in `openapi.json` (and vice versa) would have caught 15 missing paths
   and the entire visitor divergence at the moment it opened. This is the same shape as
   `GeneratedDocsConformanceTest`, which already exists for the doc registries — the API surface
   is the one registry not covered by it.

---

## H. Backend fields the app needs and the contract has never had

Each is a small, self-contained ask. Grouped by how much design they need.

### H1 · Trivial (a line each) — do with §D1
- `logoUrl`, `contactPersonPhone`, `address` on `TenantResource`.

### H2 · Small, well-specified
| Ask | Why | Notes |
|---|---|---|
| **`paidAt` on the polled invoice** (or on the checkout result) | The payment-success screen stamped `DateTime.now()` next to a server-polled amount and balance — on the screen tenants screenshot as proof of payment. The app has **dropped** the line rather than fake it (§9 #63a). | A server-issued instant. `payments.payment_date` exists; expose the captured instant on the invoice the app polls. |
| **`from`/`to` on `GET /me/statement`**, or the covered period in the response | The statement card printed a device-clock-derived `MM/YYYY – MM/YYYY` next to a PDF the server builds. Now blank. `TenantStatementPdfService` already builds a 12-month trailing window — it just doesn't say so. | Either accept a range or state the one used. |
| **A cleared total for a period** on `/me/payments` | "Cleared this period" was neither. The endpoint's whole query set is `method, status, page, per_page` — no date filter exists to pass. Relabelled "Cleared · payments shown". | Best: a server-computed total. Second best: `from`/`to` filters. |
| **`parkingSpots: int` on `LeaseResource`** | The design's parking-allocation card ("2 spots, from your lease") is **omitted** because no field carries it. `/me/leases` carries rent and service-charge figures only. | A lease term. |
| **`storeCount` / `floorCount` on `GET /public/malls`** | Two counts the visitor header renders. | Cheap. |

### H3 · Needs a decision, not just a field
| Ask | The problem |
|---|---|
| **`decision: approved\|rejected` (+ `decisionReason`) on a request** | The seven request statuses carry **no approval outcome**. The parking-permit screen maps `resolved`/`closed` → **"Approved"**, so **a staff rejection currently reads to the tenant as approved once the ticket is closed.** `Rejected` and `Expired` are unreachable states in the app. This is a correctness bug with a user-visible wrong answer, and it cannot be fixed client-side — §6 forbids computing an approval state locally. **P1.** |
| **Typed permit fields** — plate, driver, vehicle size, entry windows | A truck permit's real data rides inside the request `description` as an encoded block (`permit_encoding.dart`), readable by whoever opens the ticket in Filament and parsed back for the gate card. It works, and it is the feature's weakest joint. A `tenant_request_meta` JSON column, or per-type fields on the Phase-2 `request_types` table, would end the encoding. |
| **A signed QR payload for an approved permit** | The app encodes the request's own `reference` — honest, but unsigned: anyone can generate a QR of a reference they have seen. If gate hardware is to *trust* the code, the server must issue a signed token or one-time code. Needs a product decision (is there gate hardware?). |
| **`POST /me/avatar`** (multipart, one image) + `DELETE /me/avatar` | The app has the full pick/crop/store UI; the photo lives **on the device only**. Precedent exists twice (`attachments[]` on requests and sales declarations). The app sends a 256×256 PNG under 1 MB. ⚠️ When this lands, the honesty copy must change with it — `avatarLocalOnly` ("Saved on this device only — the mall cannot see it") becomes false, and a test asserts that string contains no upload wording in either locale. |
| **Mall opening hours + `isOpen`** | Not a column. "Open until 02:00" wraps midnight, moves with Ramadan and public holidays, and differs per mall. The app is right to demand it server-computed. **Scope it as a feature or drop the card** — do not add a string field and call it done. |
| **Parking availability (`free`, `total`)** | No endpoint. The app returns `unknown` on any real backend and renders the designed dashed card; the mock serves a demo 42/120. Wiring is one line in `ParkingRepositoryImpl` when an endpoint exists. Scope fence: a **count**, not a per-spot map — self-selection is a decision mall staff make. |

### H4 · Explicitly out of scope until asked
Public shop `phone` / `whatsApp` / `photoUrl` — these would need **new opt-in columns**, not the
existing confidential `tenants` ones. `PublicStoreResource`'s allowlist excludes them by design.

---

## I. PR #94 (`feat/mall-news`) — review against the backend

**Verdict: the endpoint work is correct.** The DTO matches `AnnouncementResource` field for field
and type for type; `id` is `int` (not the `String` that broke three earlier features); both
languages ride and the client picks with fallback; the hero goes through authenticated Dio as
bytes rather than `Image.network`, which is right because `Announcement`'s hero collection is
`useDisk('local')` (an evacuation map is not public). The read receipt is posted after load and
never on prefetch, matching `ShowAnnouncementController`'s deliberate refusal to mark-on-read.
Killing `/me/offers` is correct — it never existed.

**Left on the table (all small, all listed above):**

| # | Gap | Ref |
|---|---|---|
| 1 | `unreadAnnouncements` badge not consumed — the backend added the counter *for this band* | D7 |
| 2 | `?unread=1` not sent | D6 |
| 3 | `meta` not modelled → announcements list capped at 25 | F |
| 4 | `property{code,name}` dropped | D5 |
| 5 | `openapi.json` not regenerated — the feature is invisible in the machine-readable contract | G1 |
| 6 | **Deep link from the push does not work end to end** — `AnnouncementNotification` sends `announcementId` and the mapper reads it correctly ✅, but it is unverifiable while FCM fan-out is dark (§E3) | E3 |

The naming call in the PR body (`MallNews` for the tenant slice, keeping `/public/*` names for the
shopper slice) is the right one and matches the backend's own module split — module 27
(announcements, tenant audience, private artwork) vs module 36 (marketing posts, visitor audience,
public artwork). Two audiences, two payloads, no shared model. **§C should preserve that
distinction, not collapse it.**

---

## J. Refactors — things that are not broken but will break

### Backend
| # | Refactor | Why |
|---|---|---|
| J1 | Publish an explicit `link: {target, id}` on every notification payload | Kills the substring-guessing contract that has already failed twice (§E2) |
| J2 | Regenerate `openapi.json` in CI / as a `dump-*` step; add a route↔spec conformance test | The API surface is the one registry not covered by `GeneratedDocsConformanceTest` (§G3) |
| J3 | Collapse `MeController` + `ShowProfileController` | Byte-identical; two files can drift |
| J4 | Delete or redefine `TenantRequest::CATEGORIES` | Holds only maintenance's 7 values; superseded by `TenantRequestType::subcategories()`'s 23 (§D2) |
| J5 | Make the three hand-rolled `meta` blocks identical to the resource-collection shape (`from`/`to`) | One shape for the client to model, not two (§F) |
| J6 | `calculatedPercentageRent` → nullable before lock | A derived figure over a null input should be null (§D3) |
| J7 | Consider `mall_news` naming parity | The backend calls it `announcements`, the app calls it `mall_news`, the *contract* calls it §4.12 "Mall news (announcements)". Not worth a wire change — but say so in one place so nobody "fixes" it. |

### Mobile
| # | Refactor | Why |
|---|---|---|
| J8 | Rename the `Maintenance*` DTO family → `TenantRequest*` | The backend renamed the schemas 2026-08-15; the Dart names no longer mirror the spec (§9 #41). `openMaintenance` on `/me/summary` is a **frozen wire key** and must NOT be renamed — it is a released contract. |
| J9 | Teach the fakes the live vocabulary | Fake notification types are pre-rename; fakes don't paginate. Every flavour runs on fakes, so the fakes *are* what the team sees (§D9, §F) |
| J10 | Narrow `SummaryData`'s `Object?` fields to `int`/`bool` | The spec has published correct types since 2026-07-25; the tolerance is now belt-and-braces, and it hides real drift |
| J11 | Assert on `RequestOptions.queryParameters` in tests | **No test in the repo asserts on query params** — which is exactly why §F shipped. Hoist `_RecordingAdapter` from `public_api_service_test.dart` into `test/support/` |
| J12 | `getAttachment(media)` → `int` | Consistency with `getHero` (§D8) |

---

## K. Prioritized worklist

### P0 — before any real-backend build ships
| | Owner | Item | Ref | Status |
|---|---|---|---|---|
| 1 | **backend** | Ship a real `link {target,id}` on inbox + push so nothing infers a route from `type` | E1, J1 | ✅ **done** — `MobileNotificationLink`, derived from `NotificationTargets` |
| 2 | **mobile** | Follow `link`; delete the substring-matching `notificationCategoryFrom` routing | E1 | ⬜ |
| 3 | **mobile** | Model `meta` on all 8 lists; thread `page`; `perPage=100` on the money lists; teach the fakes to paginate first | F | ⬜ |
| 4 | **mobile** | Rewrite the visitor data layer against the shipped `/public/malls/{code}/…` contract (ints, bilingual, `locations[]`, mall `code` bootstrap) | C | ⬜ |
| 5 | **backend** | `GET /me/payments/{id}/receipt` | A2 | ✅ **done** — reuses the existing `ReceiptPdfService`; `422` unless received |
| 6 | **both** | Un-fork the contract: copy `openapi.json` (51 paths) + `MOBILE-API.md` verbatim to the app repo | G | ✅ backend regenerated · ⬜ mobile copy |

### P1 — before go-live
| | Owner | Item | Ref | Status |
|---|---|---|---|---|
| 7 | **backend** | `logoUrl` + `contactPersonPhone` + `address` on `TenantResource` | D1 | ✅ **done** |
| 8 | **backend** | `decision` / `decisionReason` on tenant requests — **a rejected permit currently reads as approved** | H3 | ⬜ **the one open correctness bug** |
| 9 | **mobile** | Route request attachments through authenticated Dio as bytes (copy the sales pattern) | B2 | ⬜ |
| 10 | **mobile** | `creditStatusFrom` → fail open to `issued` | D4 | ⬜ |
| 11 | **mobile** | Consume `unreadAnnouncements`; send `?unread=1` | D6, D7 | ⬜ |
| 12 | — | ~~Retailer marketing-post surface~~ — **withdrawn**: the portal already has it, so the push is a notification about work done there, not a dead end | B1 | ✅ n/a |

### P2 — next cycle
| | Owner | Item | Ref | Status |
|---|---|---|---|---|
| 13 | **backend** | `GET /me/devices` (list) | A3 | ✅ **done** — token never echoed |
| 14 | **backend** | `calculatedPercentageRent` → `null` before review | D3 | ✅ **done** — keyed off `declaredSales`, the input it derives from |
| 15 | **backend** | One `meta` shape on the hand-built payloads | J5 | ✅ **done** — `ApiController::paginationMeta()` |
| 16 | **backend** | Merge the duplicate `/me` controllers; drop dead `TenantRequest::CATEGORIES` | J3, J4 | ✅ **done** |
| 17 | **mobile** | Full 23-value request sub-category map | D2 | ⬜ |
| 18 | **mobile** | `POST …/click` on the visitor CTA | B2 | ⬜ |
| 19 | **backend** | `paidAt`, statement date range, cleared total, `parkingSpots` | H2 | ⬜ |
| 20 | **mobile** | Fakes speak the live vocabulary; query-param assertions in tests | J9, J11 | ⬜ |

### P3 — carry
21. `property` block on announcements (mobile) · D5
22. `int` media path param (mobile) · J12
23. `MeController`/`ShowProfileController` merge, `TenantRequest::CATEGORIES` (backend) · J3, J4
24. Avatar upload endpoint (backend + the honesty-copy change that must ride with it) · H3
25. Mall opening hours / `isOpen`, parking availability, signed permit QR — **scope as features, not fields** · H3

### Closed by this audit
- **mobile §9 #33** — `POST /me/devices` **does** return `{data: {id, platform, deviceName, createdAt}}` with 201. The logout `DELETE` has its id. Confirmed in `RegisterDeviceController`.
- **mobile §9 #16 / #36 C2, C3** — resolved into A2 and A3 above with a decision each.
- **mobile §9 #51, #52, #53** — the endpoints now exist, at different paths and in a different shape. Superseded by §C.

---

*Compiled 2026-08-15 against backend `4f98e4fc` and mobile `327f931` (PR #94, open).
Every claim was verified by reading source on both sides; where a mobile-side note in
`JAWAD_MOBILE_MASTER.md` §9 disagreed with the code, the code was taken as the truth and the
disagreement is stated.*
