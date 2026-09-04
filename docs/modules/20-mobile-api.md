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

> **`/api/v1/public/*` is the one exception, and it is a different kind of surface.** Added by
> [module 36](36-marketing-posts.md), it serves the **visitor** app — shoppers with no account, by
> design — with a mall's offer feed and store directory. It authenticates nothing, so none of this
> module's tenant-scoping applies to it; what keeps it safe is a module-flag 404, hand-written
> field allowlists (never model serialization), and a single shared visibility predicate. Read
> module 36 §6 before adding anything to it. Throttled 120/min for reads, 30/min for the one write.
>
> The tenant-authenticated half of module 36 (`me/marketing-posts`, `me/feed`) follows this
> module's conventions exactly, with one wire-level exception noted in §9: the update is a **POST**,
> not a PATCH, because a multipart body carrying the hero image does not survive PHP's PATCH
> handling.

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
| `tenant_requests` | `TenantRequest` | `id`, `reference`, `tenant_id`, `unit_id`, `status` enum, `priority`, `category`, `title`, `description`, `submitted_at`, `channel` ('portal'/etc), `deleted_at` (soft) | Tenant-reported issues. Accepted via `/me/requests`. Attachments stored via Spatie Media. |
| `tenant_request_comments` | `TenantRequestComment` | `id`, `tenant_request_id`, `author_id`, `body`, `created_at` | Comments on requests (tenant + staff). |
| `tenant_sales_declarations` | `TenantSalesDeclaration` | `id`, `lease_id`, `period_start`, `period_end`, `declared_sales` (**nullable**), `calculated_percentage_rent`, `status` enum(`submitted`/`locked`/`disputed`), `declared_at` | Monthly sales for percentage-rent leases. Tenant uploads a **sales report file** (Spatie `sales_report` collection, private disk) — `declared_sales` is null at submission and entered by staff on review. Percentage rent = `(declared_sales - threshold) * rate` (if declared_sales > threshold, else 0). |
| `announcements` | `Announcement` | `id`, `asset_id`, `title`/`title_ar`, `body`/`body_ar`, `category`, `status`, `sent_at`, `expires_at`, `is_pinned`, `hero` media (**private disk**) | Mall news. Served read-only at `/me/announcements`; see [modules/27](27-announcements.md). |
| `announcement_recipients` | `AnnouncementRecipient` | `announcement_id`, `tenant_id`, `notified_at`, `read_at`, `read_by_tenant_user_id` | Who a notice went to and whether they opened it. **The recipient row is what makes a notice visible** — `Announcement::liveFor()` asks whether one exists, never whether the tenant is currently in that property. |
| `device_tokens` | `DeviceToken` | `id`, `tenant_id`, `platform` (fcm/apns), `token`, `device_name`, `last_used_at` | Push token. Upserted on register (deduped by tenant + platform + device_name). |
| `tenant_password_reset_tokens` | — | `email`, `token` (hashed), `created_at` | One-time reset tokens (separate table so tenant + user emails don't collide). Expires in 60 minutes. |

**Key relationships** (from Tenant):
- `Tenant::invoices()` — HasMany
- `Tenant::payments()` — HasMany
- `Tenant::leases()` — HasMany (includes expired/historical)
- `Tenant::activeLeases()` — HasMany filtered to `status = 'active'`
- `Tenant::tenantRequests()` — HasMany
- `Tenant::salesDeclarations()` — HasManyThrough Lease
- `Tenant::users()` — HasMany (portal login accounts; mobile uses only Tenant itself)
- `Tenant::deviceTokens()` — HasMany
- `Announcement::recipients()` — HasMany `AnnouncementRecipient` (and `reads()`, the opened subset)
- `Invoice::lease()` — BelongsTo
- `Invoice::items()` — HasMany (line-item breakdown)
- `Payment::invoices()` — BelongsToMany (via `payment_invoice` pivot with `allocated_amount`)
- `Lease::unit()` — BelongsTo (master unit)

## 3. Business rules & invariants

> **The mobile developer's copy of all this is [`docs/api/MOBILE-SYNC-2026-09-02.md`](../api/MOBILE-SYNC-2026-09-02.md)** —
> short, task-shaped, derived from the code. This section is the REASONING and is deliberately not
> repeated there. Per the standing rule, an `/api/v1` change updates the contract
> ([`MOBILE-API.md`](../api/MOBILE-API.md)), that brief, this section and `api:export-spec` in the
> SAME commit — the app is a second codebase in someone else's hands, so these documents ARE the
> sync.

**Authentication & Authorization:**
- Only `status = 'active'` tenants can log in. Inactive/blacklisted users receive 403 + message "account_blocked". (See `LoginTenantAction::handle`.)
- Each token has `abilities: ['tenant:*']` (no granular per-endpoint scoping; all authenticated endpoints treat `:*` as "allowed").
- Token revocation is explicit: logout deletes the current token, password reset/change revokes all *other* tokens (keeping the current session alive for UX).
- A tenant cannot access another tenant's data; all show/list endpoints are scoped via `$request->user()->invoices()`, etc. (not a global query). Cross-tenant access returns **404** (not 403) to prevent enumeration. (See `ShowInvoiceController`, `InitiatePaymobSessionController`.)

**A draft is not a document — the tenant never sees one (`App\Support\TenantVisibility`, 2026-08-16):**
- `invoices.status` and `credit_notes.status` both **DEFAULT to `'draft'` at the column**, so a draft is not an exotic state: it is what any create that doesn't set the status explicitly produces (`CreditUnearnedBillingService` does exactly that). The status list below was written as though `draft` could never arrive here, and that assumption was the bug.
- Every tenant-facing read narrows with the `visibleToTenant()` scope, and both payment initiations refuse a draft as `invoice_not_payable`. It leaked on **seven surfaces at once** — list, show, invoice PDF, the Statement of Account, `pay-demo`, `paymob-session` and the portal invoice table — and `?status=draft` let a tenant enumerate them directly.
- The **visible** set is derived (`ValueSets::allowed()` minus `HIDDEN`), never listed: a new status is visible by default and must be hidden deliberately. The scope EXCLUDES rather than allowlists, so a legacy or imported status still reaches its tenant — losing a real document from someone's history is the worse failure.
- Only `draft` is hidden, deliberately. A `cancelled` or `written_off` invoice still explains a number the tenant remembers; withholding is for documents that never existed to them, not for ones that ended badly.
- Gated by `TenantNeverSeesADraftTest` (13 cases) + a portal case. Every refusal is paired with a control that must succeed — a scope that hid everything would satisfy the refusals alone and read as a pass.

**The portal and `/api/v1` are the same surface with different renderers — and that had been honoured for VISIBILITY only (2026-09-02):**
- The rule is stated in this module and in `ConfirmTenantRequestAction`, and **nothing enforced it**. Drafts were hidden from both (twice — `InvoiceResource`, then `LoginTenantAction`); *content* drifted apart unnoticed, because a gate existed for the first question and not the second.
- `LeaseResource` published **13** fields while `Filament\Portal\Resources\Leases\Schemas\LeaseInfolist` rendered those plus **fifteen** more. Three of the omissions were worse than incompleteness:
  - **`deposit_outstanding`.** A deposit shortfall is **never invoiced** — so `Lease::depositShortfall()` is the ONLY channel by which a tenant is ever told they still owe one. Absent from the API, an app-only tenant could not be told at all. The contracted figure alone is unreadable: 180,000 beside nothing else is indistinguishable from a bill, a receipt, or a line copied off the contract, which is why it ships as three numbers (agreed · held · outstanding).
  - **`rentable_items`.** The API sent a COUNT (`parking_spots`) while the invoice carries a *"Parking & rentable items"* line. Which bay, at what rate, is the most common billing query there is. **The count was itself wrong** and the fix made it visible: it counted every holding ever recorded, released ones included, so a released bay would have rendered `parking_spots: 1` beside an empty `rentable_items` — one payload contradicting itself. A release CLOSES a holding (`effective_to`), never detaches it, so `openHoldings()` is the one predicate both keys read.
  - **`units` / `total_area_sqm`.** `unit` is `belongsTo` — the MASTER only — so a lease over two shops showed one, and `unit.area_sqm` was the master's while the rent on the same card had been priced on the COMBINED area (`deriveBaseRentFromRate()` = rate × `totalAreaSqmOn()`). Two figures on one screen that could not reconcile. `unit` is KEPT unchanged, because a released client reads it.
- Also now published: `rent_commencement_date` (when rent starts, which on any fit-out lease is later than the term), `billing_frequency`, the marketing levy as a rate, the full escalation block **including the fixed-AMOUNT shape** (the portal's sibling bug was keying visibility on `escalation_rate > 0`, which is zero on such a lease — so a tenant whose rent stepped by EGP 5,000 a year was shown nothing), the escalation collar, and `percentage_rent_threshold` + `_frequency` (a rate alone cannot tell a tenant whether they owe anything).
- `GET /me/leases/{id}/document` is the portal's `downloadDocument` action on the surface the shop manager is actually on. `visibleToTenant()`, so a DRAFT lease's paperwork is unreachable; 404 (never 403) for someone else's; PRIVATE disk, so it needs the `Authorization` header; the LAST upload wins, exactly as the portal picks it.
- **A whole money figure reaches the client as a JSON INTEGER.** `json_encode(180000.0)` emits `180000`, so `total`, `balance`, `amount` and every new money field here have always done this. Invisible from PHP, fatal in Dart (`as double` throws on an int; the client needs `(x as num).toDouble()`). Pinned by `LeaseCarriesItsOwnTermsTest`.
- (`LeaseCarriesItsOwnTermsTest`, 10 cases.)

**Three money figures the app could not see, or was told wrongly (2026-09-02):**
- **`overdue` and `open_count`/`open_invoices` now net WRITE-OFFS**, as `outstandingBalance()` was taught to on 2026-09-01 and its neighbours on the same two payloads were not. A write-off deliberately leaves `invoices.balance` standing — it is not one of the four settlement channels — so a partly forgiven tenant was handed an `overdue` LARGER than their `outstanding`: the home screen contradicting itself on the two numbers it exists to show, and chasing money the operator had already forgiven. A fully written-off invoice also stopped counting as open, which had put a *"2 invoices to pay"* badge on a screen with nothing to pay.
- **`credit_on_account`** (`Tenant::creditBalance()`) is new on `/me/balance` and `/me/summary`. Two different credits exist and this API mentioned one: `credit_available` counts credit NOTES the operator issued, while this is cash the tenant has already **paid** that is not yet applied — a received payment's unallocated remainder, on the books as unearned revenue and spendable through `ApplyTenantCreditService`. The portal's `AccountBalance` widget has always shown it and four admin surfaces read it; to an app-only tenant an overpayment simply looked lost, and then an invoice was silently part-settled from it with nothing in the payment history to explain why. Kept as a SEPARATE key: summing the two would tell the tenant they have twice what they have.
- **`payable_amount`** on `InvoiceResource` is what the gateway will actually charge — `balance` net of anything forgiven, i.e. the `payableAmount()` every money path already uses (Paymob session, pivot allocation, session-reuse comparison, demo capture, public pay page). The app printed `balance` on its Pay button, so a 10,000 invoice with 6,000 written off said 10,000 and the checkout took 4,000, with nothing on screen explaining the difference. `balance` STAYS and is a different question — what was owed, which is what an accountant reconciles against.
- All three read through an eager-loaded `writeOffs`, because `collectableBalance()` prefers a loaded relation over an aggregate per row. (`TheAppSeesTheMoneyTheTenantHasTest`, mutation-proved — four mutations, four dead.)

**CAM reaches the app (module 08 · 2026-09-02):** `GET /me/cam-allocations`, `/{id}`, `/{id}/statement`.
- CAM had **no API surface at all** — a full portal resource (list, detail, statement PDF) with nothing opposite it — while the annual reconciliation puts `cam_recovery` and `cam_admin_fee` lines straight onto the invoice. So the app showed a large once-a-year charge with no way to see the pool, the share, the estimates already paid, or the statement explaining it.
- **`CamAllocation::ownedBy()` is the ONE predicate**, shared by all three endpoints rather than copied. Its grouped **OR** is the whole thing: an allocation belongs to a lease **or** to a unit ownership, and scoping through `lease` alone returns nothing for a unit owner — who is a CAM participant in his own right and was therefore billed a true-up whose basis he could not see. Grouped inside one closure because `AND` binds tighter than `OR`, so written flat the ownership branch escapes the tenant scope entirely.
- The payload carries **`total_actual_expense`** as well as the share: *"your share is 60%"* is a number the tenant cannot check against anything without the denominator. `unit` resolves from **either** parent, and `agreement.kind` says which — so an owner's assessment is labelled as one rather than showing a blank lease reference.
- The statement is the same `CamStatementPdfService` the portal downloads, so all three surfaces hand over a byte-identical file. **A service-charge audit right only the operator can print is one the tenant has to ask for**, and the app is where the shop manager is.
- (`TheAppCanSeeItsServiceChargeTest`, 5 cases; the ownership branch mutation-proved.)

**A unit owner is a first-class user of this API (module 37 · 2026-09-02):** `GET /me/unit-ownerships`, plus `unit_ownership` on `InvoiceResource`.
- Module 37's rule is that an owner **IS a `tenants` row** — same credentials, same portal, same invoices — and every surface treated them as one except this API, where `unitOwnership` appeared **nowhere** under `app/Http/{Controllers,Resources}/Api` or `app/Actions/Api`. So an owner signed in to an empty lease list (correct — they hold none), was billed monthly by `billing:run-assessments`, and read that invoice with `lease: null`: no unit, no floor, no property. Nothing crashed — `whenLoaded()` guards a null relation — an owner of three shops simply saw three identical-looking bills.
- The list is **not paginated** (a handful of rows, same as `/me/leases`) and returns **every** ownership, not only `handed_over`: a `contracted` shop is one they have bought and not yet received, which is the state they most want a screen for, and filtering it out would answer an empty list to somebody mid-purchase. `status` says which.
- `assessment_basis` + `participation_pct` are published because they are the WHY behind the figure on the invoice — an owner assessed on AREA and one on a stated share are billed by different rules. `management_mode` tells the app whether to expect a lease and rent alongside, so an empty screen can be correct rather than looking broken.
- **`unit_id` validation on `POST /me/requests` was the other half and had not moved.** `TenantRequestService` learnt to resolve a shop from `handed_over` ownerships that same day; `CreateTenantRequestRequest` still checked leases alone, so an owner NAMING their own shop was refused *"the selected unit id is invalid"* while the identical request with `unit_id` omitted succeeded. It now asks the service's own predicate — `handed_over` AND covering today — so the two cannot disagree; the clamp is unchanged in kind (the tenant's own rows, never the client's word), and a `contracted` shop is still refused.
- (`AUnitOwnerIsAFirstClassUserTest`, 6 cases; both fixes mutation-proved.)

**`GET /me/request-types` — the catalogue the app was hardcoding (2026-09-02):**
- Since EG-14 `POST /me/requests` validates `category` against `TenantRequestSubcategory::optionsFor()` — database ROWS, with the PHP enum as their floor — so the maintenance set went 7 → 14 (a tenant could not report a stuck lift, a generator fault or a fire-safety problem) and can move again with **no deploy on either side**. A client shipping its own copy is one release behind by construction, and the symptom is a 422 on a picker the tenant was offered.
- **Both languages on every row**, same convention as `AnnouncementResource`/`MarketingPostResource`: switching locale must not need a round trip, and a cached catalogue must not go monolingual when the reader changes their mind. Labels come from `optionsFor()` itself — the same resolution the panel and the portal use — so an operator-added code with no lang key reads as ITSELF rather than as `admin.enums.…glazing`. `inLocale()` restores the request's locale in a `finally`, exactly as `DocumentLocale::in()` does.
- Carries `requires_decision` (which types are QUESTIONS — the distinction that let a staff rejection render to a tenant as an approval) and `has_sla` (so the app shows a countdown or omits it, rather than rendering an empty one). An **empty** `subcategories` is the answer for `inquiry`/`billing`, whose `category` is `prohibited` on create: render no picker, not an empty one.
- A retired type is filtered by `isActive()`, so old rows still resolve while nobody is offered it.

**Three more parity fields (2026-09-02):**
- **`invoice_items.disputed_at` / `disputed_reason`.** `invoices.status` could say `disputed` and nothing said WHICH line or why, while the portal's invoice view has rendered the line's own reason all along. The tenant cannot raise a dispute on any surface — `DisputeInvoiceItemService` is admin-only, an operator recording what the tenant said by telephone — so the app's route for a billing argument is `POST /me/requests` with `request_type: billing`, and this pair is how the tenant sees it was heard.
- **`invoices.notes`** — the operator's note on the document, on the portal since it shipped, never on the wire.
- **`tenants.locale` is readable and writable at last.** `Accept-Language` governs a JSON response and a PDF the app downloads because there the caller IS the recipient — but a **push is not a request and has no header**, so Laravel renders it under `HasLocalePreference`, which reads this column; so do e-mail and any document the OPERATOR produces. The column has been fillable and `preferredLocale()` has read it since 2026-08-12 and **no screen or endpoint ever wrote it**, so it answered null for every tenant that has ever existed and the app's language toggle reached none of those three channels. Validated against `SetApiLocale::SUPPORTED`, never a copy: an unsupported locale does not throw, `__()` falls silently through to the fallback, so a typo'd `fr-CA` leaves the column looking set and every document arriving in English.
- (`TheAppKnowsWhatItMayAskForTest`, 8 cases.)

**The rule is GATED now: `PortalAndApiAnswerTheSameQuestionsConformanceTest` + `App\Support\PortalApiParity` (2026-09-02).**
- Every portal resource must have an `/api/v1` counterpart route, and every field the portal's own detail view renders must be published by the matching API resource — or be registered in `FIELD_EXEMPT` **with a reason**, which a second test rejects when it goes stale in either direction (the portal stopped rendering it, or the API caught up).
- Resources are discovered **from disk**, never from the registry: a gate that reads only the list it guards cannot see what that list omits. A tenth portal screen fails on the day it lands.
- `PAIRS` maps to a **list** of API resources, because a screen's detail view spans more than one payload — an invoice's lines are `InvoiceItemResource` — and the first version reported `description`/`disputed_reason` as missing when both ship.
- `NON_RESOURCE_SURFACES` is a second list for the portal's Pages and Widgets, and it earned its place immediately: **`credit_on_account` hid on a WIDGET**, so a resource-only sweep would have missed the very gap that prompted the gate.
- **It reads SOURCE**, so it proves the weaker property that a field is DECLARED — the behavioural half stays each endpoint's own regression test. Stated rather than implied, because that is this project's most repeated gate defect.
- **It found four real gaps on its first run**, all now fixed and covered behaviourally: credit-note LINE ITEMS (the app showed a total and a one-word `reason`, so nobody could tell which charge was credited); a payment's `cheque_number`, `cheque_clearance_date`, `gateway_transaction_id` and `notes` (*"did you get my cheque?"* is the most common call a mall office takes, and the tenant could not see which cheque had been recorded); `invoices.unit_code` through `Invoice::unitCode()`, so the client does not branch across `lease` and `unit_ownership` — the portal learnt that one the hard way, where reading `lease.unit.code` directly *"rendered every owner assessment with a blank unit"*; and the UNIT on a sales declaration, without which a tenant trading from two shops cannot tell their declarations apart.

**The API is bilingual to the PANEL's standard — `GET /me/vocabulary` (2026-09-02):**
- `ArabicPanelHasNoEnglishChromeConformanceTest` holds that line for the three Filament panels; the API had no counterpart, and it **fails differently**. A panel renders WORDS; an API sends CODES and leaves the words to the client. So the question is two questions: is every SENTENCE bilingual (it already was — `lang/en` and `lang/ar` are at file parity and `SetApiLocale` resolves `Accept-Language`), and can the client render every CODE in Arabic **without maintaining its own table**?
- It could not. The app carried an EN+AR table for **25 vocabularies across 16 resources**, and **for five of them a client-side table cannot work at all**: `invoiceItem.type`, `payment.method`, `publicStore.retailCategory` and the request sub-categories are OPERATOR-EDITABLE CATALOGUES — the accountant adds a charge code and the mall adds a payment rail with no deploy on either side. That is the exact failure `IsCodeCatalogue` prevents in the panel (*"an operator-added code has no lang key and would render `admin.enums.method.fawry` on the very screen whose filter lists Fawry"*), reproduced on the surface the retailer reads.
- **`App\Support\ApiVocabulary` is not a second vocabulary.** A closed set takes its VALUES from `ValueSets` — the registry the column is enforced against — and its WORDS from the lang group the PANEL labels from; an open catalogue takes both from the same public option method the panel's own picker calls. So a widened set appears the day it is widened and a renamed row is renamed here.
- The response carries `version` (a hash of the rendered LABELS, so a renamed charge code changes it and a refactor does not) and `open_catalogues` (the ones a shipped table can never be right about). `inLocale()` moved onto `ApiController` — it renders under one locale and restores the request's own in a **`finally`**, so a catalogue read that throws cannot leave every later response on a worker in the wrong language.
- `TheApiSpeaksBothLanguagesConformanceTest` discovers the classification fields the RESOURCES emit and fails on one the registry neither covers nor explains — a registry checked only against itself cannot see what it omits. It checks both languages with **`Lang::has(fallback: false)`** (the default falls back to English, so the obvious form only catches keys missing from BOTH), and separately fails an Arabic label byte-identical to its English with no Arabic script — `Lang::has()` proves a key exists and never that somebody put Arabic in it.
- **Two real defects found while writing it.** `credit_notes.reason` and `marketing_posts.audience` were **in no value set at all**: the admin form offers `reason` as a Select over six values while the column accepted anything, and `audience` decides who sees a post (`MarketingPost::liveFor()` branches on it), so a typo showed it to nobody. `audience` joined `CLASSIFICATION_SUFFIXES`; `credit_notes.reason` is exempted BY NAME, because `reason` is free text in twelve other columns and a suffix rule would demand a value set for all of them. And `invoiceItem.type` served an **EMPTY** vocabulary on an unseeded install — `ChargeCode::options()` is rows-only, unlike its `IsCodeCatalogue` twins — so the floor is `InvoiceItemType` labelled through `ChargeCode::labelFor()`.

**Invoice & Payment:**
- Invoice statuses: `issued`, `partially_paid`, `overdue`, `paid`, `cancelled`, `credited`. Only invoices with `balance > 0` and status NOT IN (`draft`, `cancelled`, `credited`) are payable.
- Payment statuses: `initiated`, `captured`, `failed`, `refunded`. Only `captured` payments increment the invoice's `paid_amount`.
- A payment is allocated to invoices via the `payment_invoice` pivot. Invoice `balance = total - paid_amount - credit_applied_amount`. The balance is recomputed on every payment save.
- Allocation is idempotent: `PaymentReceivedNotification` is fired once per payment (guarded by `receipt_notified_at`).

**Paymob Session Initiation:**
- Idempotent within a `REUSE_WINDOW_SECONDS` (2700s / 45 min) window. If an 'initiated' Payment exists for the invoice created within that window, its stored session is returned without a fresh Paymob API call. This avoids burning the upstream budget on retries. (See `PaymobPaymentInitiator::start`, `PaymobPaymentInitiator::findReusableSession`.)
- Session requires `integrations.paymob.enabled = true` (env-gated). If false, returns 409 and directs the client to use the demo-pay endpoint instead.
- Paymob payment tokens expire in 3600s (PaymobClient::PAYMENT_TOKEN_TTL_SECONDS); the reuse window is shorter so tokens never expire mid-checkout.
- S2S callback (POST `/paymob/callback`) is HMAC-verified using the payload + signature from Paymob; invalid HMAC logs a warning and returns 401. Callback is idempotent: if a payment is already captured/failed, it skips processing and returns 200 (so Paymob stops retrying).

**Demo Payment (dev-only — enforced, not merely intended, since 2026-08-11):**

> The "dev-only" in this heading was aspirational until 2026-08-11. The endpoint's only gate was
> `PAYMOB_ENABLED=false`, which is the shipped default and the documented incident posture — so it
> was **live on production**, and any authenticated tenant could zero their own AR through the real
> capture path. It is now gated by `App\Support\DemoPayments::enabled()`: never on production,
> explicit opt-in elsewhere, and `atriom:health` fails if the flag is set in production. See
> [module 06](06-payments.md#recorddemopaymentactionhandleinvoice--payment).
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
- **File-first:** the tenant uploads their sales report (1–5 image/PDF files, ≤10 MB each) via `multipart/form-data`; they do **not** send a figure. At least one file is required (422 → `attachments`). Files land in the private `sales_report` media collection and are streamed via `GET /me/sales-declarations/{id}/attachments/{media}` (foreign id → 404, no cross-tenant disclosure).
- `declared_sales` and `calculated_percentage_rent` are **null/0 at submission** — staff read the figure off the report, enter it in the admin panel, and lock. The app should show "Pending review", not 0.
- Only valid for leases with `has_percentage_rent = true`. Posting to a lease without percentage rent returns 422.
- Duplicate check: one declaration per lease per period (period_start + period_end). Re-declaring the same period is rejected (422).
- Percentage rent (computed on lock) is: `if (declared_sales > percentage_rent_threshold) then (declared_sales - threshold) * rate else 0`.
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
- Submitted by tenant (uploads a sales report file): `status = submitted`, `declared_sales = null`.
- Transitions: `submitted` → `locked` (admin reviews the report, enters `declared_sales`, and locks — creating the percentage-rent charge), or `submitted` → `disputed`.
- Once locked, the charge flows into the next monthly billing run (out-of-scope for the mobile API).

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
| `CreateTenantRequestAction` | `handle(Tenant $tenant, array $payload, UploadedFile[] $attachments): TenantRequest` | Creates request, stores attachments via Spatie Media, returns with media URLs. | DB transaction (request + media). | POST `/api/v1/me/requests` |
| `AddTenantRequestCommentAction` | `handle(TenantRequest $req, Tenant $tenant, string $body): TenantRequestComment` | Adds comment from tenant, scoped to tenant's own request. | No transaction. | POST `/api/v1/me/requests/{id}/comments` |
| `CancelTenantRequestAction` | `handle(TenantRequest $req, Tenant $tenant): void` | Cancels request if in cancellable status, checks ownership. | No transaction. | POST `/api/v1/me/requests/{id}/cancel` |
| `CreateSalesDeclarationAction` | `handle(Tenant $tenant, array $payload, UploadedFile[] $attachments): TenantSalesDeclaration` | Validates lease ownership + percentage-rent eligibility + no-duplicate-period, creates the declaration with `declared_sales=null`, then attaches the uploaded report file(s) to the `sales_report` collection. Does **not** calculate or lock (staff enter the figure later). | No transaction (media moves files on disk). | POST `/api/v1/me/sales-declarations` |
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
  - `CreateTenantRequestRequest`: title, description, category, priority, attachments (files, optional).
  - `CreateSalesDeclarationRequest`: lease_id, period_start, period_end, attachments (required 1–5 image/PDF files). No `declared_sales` — staff enter it later.

- **Resources** (in `app/Http/Resources/Api/V1/*`): Format response data.
  - `TenantResource`: id, name, legal_name, type, email, phone, whatsapp, contact_person, status, tax_id (re-exposed for ETA).
  - `InvoiceResource`: id, number, status, issue_date, due_date, period_start, period_end, subtotal, vat_amount, total, paid_amount, balance, currency, is_overdue, days_overdue, eta_status, eta_submission_id, items (when eager-loaded), lease (when eager-loaded).
  - `PaymentResource`: id, reference, amount, method, status, payment_date, allocations (pivot data with invoice numbers + amounts).
  - `TenantRequestResource`: id, reference, status, priority, category, title, description, submitted_at, attachments (media URLs).
  - `TenantSalesDeclarationResource`: id, period_start, period_end, period_label, declared_sales (**null until reviewed**), calculated_percentage_rent, status, is_locked, declared_at, locked_at, `attachments` (streamed report URLs), `has_report`, lease (when loaded).
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
2b. **`SnakeCaseRequestKeys` must cover all four input bags** — the JSON bag,
   the request bag (`multipart/form-data` + urlencoded), the query string, and
   the *files* bag. It once covered only JSON + query, which silently broke
   every multipart endpoint for the camelCase contract we publish: `leaseId`
   422'd `POST /me/sales-declarations` on `lease_id` every time, and `unitId` /
   `requestType` were dropped **without an error** so a request was filed
   against the wrong unit. The pre-existing API tests all POSTed snake_case, so
   the suite stayed green over a contract the app could not satisfy — any new
   multipart endpoint needs a camelCase test
   (`tests/Feature/Regression/MultipartCamelCaseKeysTest.php`).
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
6. **Cast scalars explicitly — `(int)`, `(bool)`, `(float)`.** Scramble infers
   the published type from the array literal, and anything it can't resolve
   (an untyped array element, a Spatie media prop, a `count()` off a builder)
   falls back to `string`. The generated spec is what the Flutter client codegens
   against, so a `size` that's really an int published as `string` makes the app
   decode `as String` and throw — that took down the whole request list, and the
   same artifact mistyped `PaymobSessionResource.reused`/`orderId`/`paymentId`
   and the `/me/summary` counts. Regenerate with `php artisan api:export-spec`
   and **check the emitted types**, not just that the route is documented — the
   `ApiSpecContractTest` gate only asserts presence, never type fidelity.

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

**The mobile token belongs to the COMPANY, not to a person — so `/me` can never report a role.**
Writes are gated on `tenant_users.is_admin` by `EnsurePortalAdminForWrites`, which gates by DEFAULT:
safe methods pass, the self-scoped routes it names pass, and anything else needs an admin — so a
write route added later is covered by existing rather than by being remembered.

`LoginTenantAction` authenticates against `tenant_users.email` + `tenant_users.password` — the same
row the web portal authenticates, unified 2026-09-05; it was `tenants.*` (the company) until then —
resolves the company as `$user->tenant`, and issues
`$tenant->createToken(...)`. No `TenantUser` is involved at any point, which is the opposite of
`/portal` (multi-user `TenantUser`, `is_admin` decides who may write). Consequences, all of them
deliberate rather than missing:

- The app models an owner-vs-staff Home split and reads `TenantResource.role` to pick it. **The
  server has no person to name**, so the key is absent, the app decodes null, and
  `homeVariantFor(null)` falls back to the full owner Home. That is the safe direction: one shared
  company credential already implies full access, so nothing is being over-exposed.
- The staff Home layout is therefore unreachable against a real backend — it is exercised only by
  the in-app mock. A dev-only banner ("Role unknown — the full home is shown") makes that visible
  in non-production builds rather than letting it read as a bug.
- **This is a product decision, not a patch.** Closing it means moving mobile auth to `TenantUser`
  so the API knows which person signed in — matching the portal, and a breaking change for a
  shipped app. The alternative is to drop the staff variant. Either way the choice belongs to the
  operator, and `role` should not be faked from `is_admin` on some other row in the meantime.

**`/me/balance` and `/me/notifications/unread-count` exist but the app calls neither, on purpose.**
`outstanding` and `unreadNotifications` both arrive on `/me/summary`, which Home loads anyway, and
the bell badge reloads that summary on return from the inbox. Calling either would be a second
round trip for a number already in hand.


**Tenant Status & Login:**
- Blocked tenants (status != 'active') get 403, not 401. This drives a specific "Account Blocked" screen in the app. Don't confuse with password failure (401).
- Inactive tenants can still view invoices/payments via API if they somehow have a token (the routes don't re-check status). This is intentional: a session shouldn't be invalidated mid-request if status changes. Password reset/change does revoke tokens, so a re-login is required.

**Scoping to the tenant is not the same question as scoping to what they may SEE (fixed 2026-08-16):**
- `$request->user()->invoices()` answers "whose row is this?" and nothing else. Every controller did that correctly, and every one of them still handed over drafts — because the relationship is also what the admin and the GL read, so it must return everything.
- That is why the narrowing lives at the **call site** (`->visibleToTenant()`), not on the relationship. Two different readers, two different entitlements, one relationship.
- The tell that this was never considered: the documented status list in §3 simply omitted `draft`. The column's DEFAULT is `draft`.

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
- The action enforces which statuses can be cancelled (not all). This is not a route-level gate, but enforced inside CancelTenantRequestAction::handle.
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
- Invoices, Payments, Leases, TenantRequests are soft-deleted. Queries in the controllers don't filter them out explicitly (Laravel's Illuminate\Database\Eloquent\SoftDeletes automatically excludes them via `withoutTrashed()` on the default query).
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

**Helpers** (`tests/Feature/Scenarios/MobileApiScenarioTest.php`; shared helpers live in `tests/Pest.php` or a class under `Tests\Support`):
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
- **Invoice Module** (`docs/modules/05-billing-invoices.md`): Core invoice generation, line-item breakdown, balance recomputation, ETA submission. Mobile API consumes this.
- **Payment Module** (`docs/modules/06-payments.md`): Payment creation, allocation, capture, refund. Mobile API initiates Paymob payments and consumes captured payments.
- **Paymob Integration** (`docs/integrations/PAYMOB.md`): Detailed Paymob API docs, HMAC, callback handling. Mobile API references this.
- **Tenant Module** (`docs/modules/02-tenants.md`): Tenant model, status enum, relationships. Mobile API authenticates against this.
- **Lease Module** (`docs/modules/04-leases.md`): Lease model, percentage-rent config, statuses. Mobile API lists leases on login and handles percentage-rent declarations.
- **Device Tokens** (mobile push integration, TBD): Future feature. Mobile API stores tokens; push fan-out is a separate job.

---

## Sweep fixes — 2026-09-05

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a time.
Each row's full claim is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*

### SW-188

, under "## 9. Gotchas, edge cases & recently-fixed bugs":

**`SetApiLocale::SUPPORTED` is gone — `SetLocale::SUPPORTED` is the ONE list (SW-188, 2026-09-04).** The API middleware carried its own copy under a docblock promising the two would "stay in lock-step", which is a promise nothing kept and nothing could check; `UpdateProfileRequest` then validated the tenant's own `locale` against it, under a comment claiming it was checked against "the ONE supported list rather than a copy". (The sweep row calling the const *unreferenced* is therefore wrong; the drift is real and is what was fixed.) A comment-stripped sweep found **five** files under `app/` stating the pair beside the one list: this const, both branches of `PaymentLinkController::locale()`, `ChargeCode::flushLookupCaches()`, `Health::checkTranslations()`, and `IsCodeCatalogue::catalogueLocales()` — that last one reading `config('app.supported_locales', …)`, a key `config/app.php` does not define, so its configurable branch had never been taken and an operator who *did* define it would have taught one method a language nothing else in the app knew. All six agreed, so nothing was wrong; what was wrong is the failure shape. A third language would have reached `ValueSets`, `DocumentLocale`, `NotificationLocale` and the web switcher and stopped at the mobile app and the public pay link — **silently**, because `__()` falls through an unknown locale into the fallback, so the tenant's column looks set and every document arrives in English. All five now read `SetLocale::SUPPORTED`, and `TheLanguagesThisSystemSpeaksAreOneListTest` fails on a sixth copy appearing anywhere under `app/`.

