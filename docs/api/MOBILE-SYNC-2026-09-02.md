# Atriom Mobile API — what the app must do

> **For:** the mobile developer building the tenant app.
> **Verified against the code on 2026-09-02** — `routes/api.php`, `app/Http/{Resources,Controllers,Requests}/Api/V1`.
> **Full contract:** [`MOBILE-API.md`](MOBILE-API.md) · **machine-readable:** [`openapi.json`](openapi.json)
>
> This is the short list: the work, the endpoints, the payloads, and the rules that are not
> obvious. **Nothing here is waiting on the backend.**

> ⚠️ **Two known errors in `MOBILE-API.md`**, which is otherwise correct:
> §4.3 prints invoice line-item keys in **snake_case** (the wire is camelCase — see rule 1), and
> §4.7 lists **7 of 14** maintenance sub-categories (fetch them from `/me/request-types` instead).

---

## 0. Sync status — where the two codebases stand

| | |
|---|---|
| **Backend** | ✅ Complete. 67 live endpoints. **Nothing is pending on our side.** |
| **App** | ⏳ The 18 tasks in §1. Until those land, the two are out of step. |
| **Last backend change** | 2026-09-02 |

**What the backend gained on 2026-09-02** — this is the sync record; each line is an app task above.

| Area | What changed | New / changed |
|---|---|---|
| Lease | Full commercial terms, previously web-only | **+20 fields**: `depositHeld`, `depositOutstanding`, `securityDeposit`, `rentCommencementDate`, `billingFrequency`, the escalation block + collar, `percentageRentThreshold`/`Frequency`, `marketingLevyRate`, `rentableItems[]`, `units[]`, `totalAreaSqm`, `hasDocument` |
| Lease | The signed contract | **`GET /me/leases/{id}/document`** |
| Money | The tenant's own cash on account | **`creditOnAccount`** on `/me/balance` + `/me/summary` |
| Money | `overdue` / `openCount` now net write-offs, like `outstanding` | ⚠️ behaviour change |
| Money | What checkout actually charges | **`payableAmount`** on the invoice |
| Money | Which shop an invoice is for | **`unitCode`**, **`unitOwnership`**, **`notes`** |
| Money | Which line is under argument | **`disputedAt`**, **`disputedReason`** on invoice items |
| Money | What a credit note credited | **`items[]`** on credit notes |
| Money | Chasing a cheque or a card charge | **`chequeNumber`**, **`chequeClearanceDate`**, **`gatewayTransactionId`**, **`notes`** on payments |
| Recoveries | The annual service charge, previously web-only | **`GET /me/cam-allocations`**, `/{id}`, `/{id}/statement` |
| Owners | A unit owner is a first-class user | **`GET /me/unit-ownerships`**; `unitId` on a request now accepts an owned shop |
| Requests | The category catalogue the operator edits | **`GET /me/request-types`** |
| i18n | Every code, both languages, worded as the panel words it | **`GET /me/vocabulary`** |
| Profile | The language push and e-mail are written in | **`locale`** on `GET`/`PATCH /me` |
| Sales | Which shop a declaration is for | **`lease.unit`** |
| Billing | A chase letter no longer goes out for a debt just paid | fixed |

Two gates now keep the surfaces together, so this list should not grow again on its own:
`PortalAndApiAnswerTheSameQuestionsConformanceTest` (the web portal and `/api/v1` must answer the
same questions) and `TheApiSpeaksBothLanguagesConformanceTest` (every code the API emits is
renderable in Arabic).

---

## 1. The work — 18 tasks

Each row links to the detail. **Do 1–7 first: without them the app is showing wrong numbers today.**

| # | Do this | Where | Detail |
|---|---|---|---|
| 1 | Decode invoice lines as **`vatRate` / `vatAmount`** (the old doc printed snake_case) | Invoice detail | [rule 1](#4-the-rules-that-are-not-obvious) |
| 2 | Decode **every money field** as `(x as num).toDouble()` — a whole value arrives as an `int` | Everywhere | [rule 2](#4-the-rules-that-are-not-obvious) |
| 3 | Put **`payableAmount`** on the Pay button, not `balance` | Invoice, Pay | [rule 3](#4-the-rules-that-are-not-obvious) |
| 4 | Gate Pay on **`paymentLinkUrl != null`**, never on `balance > 0` | Invoice list | [rule 4](#4-the-rules-that-are-not-obvious) |
| 5 | On **403**: wipe the token, go to Blocked. Never retry | HTTP layer | [§5](#5-errors) |
| 6 | Make **`errors` optional** on a 422 — business refusals send only `message` | HTTP layer | [§5](#5-errors) |
| 7 | Treat **`data: []` on login** as a valid signed-in state | Login | [rule 6](#4-the-rules-that-are-not-obvious) |
| 8 | Fetch **`GET /me/vocabulary`** on launch, cache on `version`, **delete your own EN/AR label tables** | App start | [rule 5](#4-the-rules-that-are-not-obvious) |
| 9 | Fetch **`GET /me/request-types`** on launch — stop hardcoding categories | App start | [rule 5](#4-the-rules-that-are-not-obvious) |
| 10 | Show **`depositOutstanding`** when `> 0`. Nothing else ever asks the tenant for it | Lease | [§3](#3-what-the-payloads-look-like) |
| 11 | Show **`creditOnAccount`** as its own row — never summed with `creditAvailable` | Home | [§3](#3-what-the-payloads-look-like) |
| 12 | Build the **CAM screen** — 3 endpoints + statement PDF | New screen | [§3](#3-what-the-payloads-look-like) |
| 13 | Build **unit-owner support** — empty leases + non-empty ownerships | New screen | [§3](#3-what-the-payloads-look-like) |
| 14 | Show **`units[]` + `totalAreaSqm`** on a multi-unit lease | Lease | [§3](#3-what-the-payloads-look-like) |
| 15 | Show **`rentableItems[]`** (bay + rate) under the parking invoice line | Lease | [§3](#3-what-the-payloads-look-like) |
| 16 | Route **every notification tap through `link`** — never infer from `type` | Notifications | [§6](#6-notifications) |
| 17 | Wire the language toggle to **`PATCH /me {locale}`** as well as `Accept-Language` | Settings | [rule 9](#4-the-rules-that-are-not-obvious) |
| 18 | Add the missing screens: signed lease, receipt PDF, mall news, devices, confirm/dispute | Various | [§2](#2-every-endpoint-67) |

**Do NOT build:** an ETA / tax-filing badge (module 16 is frozen), an in-app "dispute this charge"
form (operator-only — use `POST /me/requests` with `requestType: "billing"`), or a demo-pay button
in a build you would show the client ([rule 10](#4-the-rules-that-are-not-obvious)).

## 2. Every endpoint (67)

`🔒` needs `Authorization: Bearer <token>` · `🔓` public · **bold = new on 2026-09-02**

### Auth & profile
| | Endpoint | What |
|---|---|---|
| 🔓 | `POST /auth/login` | → `{data:[leases], accessToken, tokenType}`. `data` may be `[]` |
| 🔓 | `POST /auth/forgot-password` · `POST /auth/reset-password` | Two-step, token-verified |
| 🔒 | `POST /auth/logout` · `POST /auth/change-password` | |
| 🔒 | `GET /auth/me` | Alias of `GET /me` |
| 🔒 | `GET /me` · `PATCH /me` | Profile. PATCH takes `phone, whatsapp, contactPerson, contactPersonPhone, address,` **`locale`** |
| 🔒 | `GET /me/balance` | `outstanding, overdue, openCount,` **`creditOnAccount`**`, isDelinquent` |
| 🔒 | `GET /me/summary` | **The home screen — one call.** Balance + open work + badges |
| 🔒 | `GET /me/leases` | Full lease terms. Not paginated |
| 🔒 | **`GET /me/leases/{id}/document`** | Signed lease PDF. Gate on `hasDocument` |
| 🔒 | **`GET /me/unit-ownerships`** | Shops the party OWNS. Not paginated. `[]` for a retailer |

### Money
| | Endpoint | What |
|---|---|---|
| 🔒 | `GET /me/invoices` | `?status= &period_from= &period_until= &page= &perPage=` |
| 🔒 | `GET /me/invoices/{id}` | + `items[]`, **`payableAmount`**, **`unitCode`**, **`notes`**, **`unitOwnership`** |
| 🔒 | `GET /me/invoices/{id}/pdf` | `?lang=en\|ar` |
| 🔒 | `GET /me/statement` | `?from= &to= &lang=` — the range is now honoured |
| 🔒 | `POST /me/invoices/{id}/paymob-session` | Card payment. Idempotent 45 min |
| 🔒 | `POST /me/invoices/{id}/pay-demo` | Debug builds only — 409s on staging |
| 🔒 | `GET /me/payments` | `?method= &status= &from= &to=` |
| 🔒 | `GET /me/payments/{id}` | + **`chequeNumber`**, **`chequeClearanceDate`**, **`gatewayTransactionId`**, **`notes`** |
| 🔒 | `GET /me/payments/{id}/receipt` | Receipt PDF. Gate on `receiptAt != null`. `?lang=` |
| 🔒 | `GET /me/credit-notes` · `GET /me/credit-notes/{id}` | `?status=`. Detail adds **`items[]`** |
| 🔒 | **`GET /me/cam-allocations`** | `?status= &period_year=` |
| 🔒 | **`GET /me/cam-allocations/{id}`** | One year's share |
| 🔒 | **`GET /me/cam-allocations/{id}/statement`** | Service-charge statement PDF. `?lang=` |

### Requests
| | Endpoint | What |
|---|---|---|
| 🔒 | **`GET /me/request-types`** | The type + sub-category catalogue, both languages |
| 🔒 | `GET /me/requests` · `POST /me/requests` | `?status=`. POST is multipart when attaching |
| 🔒 | `GET /me/requests/{id}` | + `comments[]` |
| 🔒 | `GET /me/requests/{id}/attachments/{media}` | Photo/PDF stream — **needs the auth header** |
| 🔒 | `POST /me/requests/{id}/comments` · `/cancel` · `/rate` | Gate on `canCancel` / `canRate` |
| 🔒 | `POST /me/requests/{id}/confirm` · `/dispute` | Gate on `canConfirm`. Show **both** or neither |

### Sales, news, notifications, devices
| | Endpoint | What |
|---|---|---|
| 🔒 | `GET /me/sales-declarations` · `POST` | `?status=`. POST is multipart, 1–5 files |
| 🔒 | `GET /me/sales-declarations/{id}` · `/attachments/{media}` | |
| 🔒 | `GET /me/announcements` · `/{id}` · `POST /{id}/read` · `GET /{id}/hero/{media}` | `?unread=1`. Hero needs the auth header |
| 🔒 | `GET /me/notifications` · `/unread-count` · `POST /{id}/read` · `POST /read-all` | `?unread=1` |
| 🔒 | `GET /me/devices` · `POST /me/devices` · `DELETE /me/devices/{id}` | Register on launch, revoke on sign-out |
| 🔒 | **`GET /me/vocabulary`** | Every code, both languages. Cache on `version` |

### Retailer's own offers · visitor app
| | Endpoint | What |
|---|---|---|
| 🔒 | `GET /me/feed` | What's on at the malls you trade in |
| 🔒 | `GET /me/marketing-posts` · `POST` · `GET/POST/DELETE /{id}` · `/{id}/submit` · `/{id}/withdraw` | 404 = module off |
| 🔓 | `GET /public/malls` · `/{code}/posts` · `/{code}/posts/{id}` · `POST /{id}/click` | Visitor app. 120/min |
| 🔓 | `GET /public/malls/{code}/stores` · `/stores/{id}` | `?category=` |

## 3. What the payloads look like

Every key is **camelCase**. Every money value is a JSON number that is an **`int` when whole**.
`null` is used and means *unknown / not applicable* — never a sentinel.

```jsonc
// GET /me                                          ← profile screen
{ id, code, name, legalName, type, email, phone, whatsapp,
  contactPerson, contactPersonPhone, address, status, taxId, logoUrl, locale }

// GET /me/summary                                  ← the WHOLE home screen, one call
{ outstanding, overdue, openInvoices, creditAvailable, creditOnAccount, isDelinquent,
  openMaintenance, disputedDeclarations, canDeclareSales,
  unreadNotifications, unreadAnnouncements, currency }

// GET /me/leases[]                                 ← lease card
{ id, reference, status, commencementDate, expiryDate, rentCommencementDate, termMonths,
  billingFrequency, baseRentMonthly, serviceChargeMonthly, totalMonthlyAmount, currency,
  securityDeposit, depositHeld, depositOutstanding,
  hasMarketingLevy, marketingLevyRate,
  escalationType, escalationRate, escalationAmount,
  escalationFloorRate, escalationCeilingRate, nextEscalationDate,
  hasPercentageRent, percentageRentRate, percentageRentThreshold, percentageRentFrequency,
  parkingSpots, rentableItems:[{id,code,type,monthlyRate,effectiveFrom}],
  unit:{id,code,floor,category,areaSqm,asset:{id,name,code}},
  units:[{id,code,floor,category,areaSqm,isMaster}], totalAreaSqm, hasDocument }

// GET /me/unit-ownerships[]                        ← owner screen
{ id, reference, status, tenureType, managementMode, ownershipSharePct,
  assessmentBasis, participationPct, purchaseDate, handoverDate, startedAt, endedAt,
  currency, unit:{id,code,floor,category,areaSqm}, property:{id,code,name} }

// GET /me/invoices[] / {id}                        ← invoice
{ id, number, status, issueDate, dueDate, periodStart, periodEnd,
  subtotal, vatAmount, total, paidAmount, creditAppliedAmount,
  balance,            // what was OWED — reconcile against this
  payableAmount,      // what checkout takes — put THIS on the button
  currency, paidAt, isOverdue, daysOverdue, paymentLinkUrl, notes, unitCode,
  lease:{id,reference,unit:{id,code,floor}} | null,
  unitOwnership:{id,reference,unit:{id,code,floor}} | null,
  items:[{ id, description, type, amount, vatRate, vatAmount, total,
           disputedAt, disputedReason }] }        // items only on {id}

// GET /me/payments[] / {id}                        ← payment
{ id, reference, amount, currency, method, status, paymentDate, gateway, channel, receiptAt,
  chequeNumber, chequeClearanceDate, gatewayTransactionId, notes,
  allocations:[{invoiceId, invoiceNumber, allocatedAmount}] }

// GET /me/credit-notes[] / {id}                    ← credit note
{ id, number, status, reason, subtotal, vatAmount, total, appliedAmount, balance,
  currency, issueDate, appliedAt, invoice:{id,number}|null,
  items:[{id, description, amount, vatRate, vatAmount, total}] }

// GET /me/cam-allocations[]                        ← service charge
{ id, status, periodYear, totalActualExpense, proRataSharePct,
  allocatedAmount, estimatedPaid, trueUpAmount,   // trueUp>0 owed, <0 credit coming
  currency, property:{id,code,name}, unit:{id,code,floor},
  agreement:{kind:"lease"|"ownership", reference} }

// GET /me/requests[] / {id}                        ← request
{ id, reference, requestType, title, description, status, priority, category, channel,
  isOpen, isOverdue, canCancel, canRate, canConfirm, confirmedAt,
  csatRating, csatComment, submittedAt, acknowledgedAt, resolvedAt, closedAt,
  targetResolutionAt, resolutionNotes,
  requiresDecision, decision, decisionReason, decidedAt,   // NEVER infer approval from status
  validFrom, validTo, scheduledFrom, scheduledTo,
  unit:{id,code,floor},
  attachments:[{id,name,mimeType,size,url}],
  comments:[{id,body,authorKind,authorName,createdAt}] }   // comments only on {id}

// GET /me/sales-declarations[]                     ← turnover
{ id, periodStart, periodEnd, periodLabel,
  declaredSales,              // null = nobody has looked yet. 0 = an answer.
  calculatedPercentageRent,   // same rule
  status, isLocked, declaredAt, lockedAt, hasReport,
  lease:{id,reference,unit:{id,code}},
  attachments:[{id,name,mimeType,size,url}] }

// GET /me/announcements[]                          ← mall news
{ id, category, title, titleAr, body, bodyAr, heroUrl, isPinned,
  sentAt, expiresAt, read, readAt, property:{code,name} }

// GET /me/notifications[]                          ← inbox
{ id, type, data:{title, body, ...}, link:{target,id}|null, read, readAt, createdAt }

// GET /me/devices[]        { id, platform, deviceName, createdAt }
// POST /me/invoices/{id}/paymob-session
{ paymentToken, iframeUrl, iframeId, orderId, paymentId, expiresAt, reused }

// GET /me/vocabulary                               ← fetch once, cache on version
{ version, openCatalogues:[...],
  vocabularies:{ "invoice.status": { "overdue": {en, ar}, ... }, ... } }

// GET /me/request-types                            ← fetch once
{ types:[{ code, label, labelAr, requiresDecision, hasSla,
           subcategories:[{code,label,labelAr}] }],
  priorities:["low","medium","high","urgent"] }
```

**Lists** return `{data:[...], meta:{currentPage,lastPage,perPage,total,from,to}, links:{...}}`.
Page off `meta`, never `links`. Default 25, max 100. `GET /me/leases`, `/me/unit-ownerships` and
`/me/devices` are **not** paginated — bare `{data:[...]}`.

**Errors** are `{message, statusCode}` — plus `errors:{field:[...]}` on *field* validation only.

## 4. The rules that are not obvious

Each of these produces a **silently wrong screen** rather than an error.

1. **Invoice line keys are camelCase: `vatRate`, `vatAmount`.** `MOBILE-API.md` §4.3 prints them
   snake_case — that is a typo in the doc. Decoding the wrong ones shows every line's VAT as 0,
   or throws on a non-nullable `double`.

2. **A whole money value arrives as a JSON `int`.** `json_encode(180000.0)` emits `180000`, so this
   is true of every money field the API has ever sent. Decode with `(x as num).toDouble()` —
   `as double` throws on an int.

3. **`balance` ≠ `payableAmount`.** `balance` is what was **owed** and is what an accountant
   reconciles against. `payableAmount` is what may still be **collected** — `balance` net of
   anything the operator wrote off — and it is what checkout actually takes. **Put
   `payableAmount` on the Pay button.** Label your other figure *Invoice balance*, never
   *Amount due*.

4. **Gate Pay on `paymentLinkUrl != null`**, never on `balance > 0` or on `status`. It is the same
   predicate the server uses, so your button and its answer cannot disagree. It is null for a
   draft, a cancelled/credited/written-off invoice, and anything fully forgiven.

5. **Never hardcode a code list.** Three vocabularies are **rows the operator edits between
   releases** — `invoiceItem.type` (the accountant adds a charge code), `payment.method` (the mall
   adds a rail), `publicStore.retailCategory`. Request sub-categories moved 7 → 14 once already.
   Fetch `GET /me/vocabulary` and `GET /me/request-types` on launch; cache the first on `version`.
   Falling back to a stale copy is safe — **render an unknown code as itself, never as a blank.**
   The vocabulary's **keys are also the complete value set** for each field, so branch on those
   rather than on a list you maintain.

6. **`data: []` on login is a valid signed-in state.** A tenant whose only lease is still a draft
   gets a valid token and an empty list. Show an empty state, not an error and not a logout.
   `GET /me/leases` can be `[]` for the same reason — and for a **unit owner**, who holds none.

7. **Never infer an approval from `status`.** `resolved` looks identical whether staff approved or
   refused. Use `requiresDecision` (is this type a *question*?) + `decision` + `decisionReason`.
   A `closed` request with `confirmedAt: null` was closed by the operator or the timer — **not**
   by the tenant, so do not render it as "you confirmed this".

8. **Never infer a notification's destination from `type`.** Use `link: {target, id}`. `null` means
   render the row unclickable — four alert types legitimately have no mobile screen ([§6](#6-notifications)).

9. **`Accept-Language` does not reach push or e-mail.** A push is not a request and has no header;
   Laravel renders it from the tenant's stored `locale`. Wire your language toggle to **both**
   `Accept-Language` **and** `PATCH /me {locale}`.

10. **`pay-demo` is a debug affordance.** It 409s (`use_real_payment`) on anything that is not
    `local`/`testing` — staging included. Do not ship it in a build you would show the client;
    use `paymob-session`.

11. **`null` is information.** `declaredSales: null` means *nobody has reviewed the report yet*;
    `0` means *reviewed, nothing due*. Rendering both as `0` states the opposite of one of them.
    Same rule on `calculatedPercentageRent`.

12. **An invoice line's `description` is stored English prose** — it is never translated, and it
    carries the period and the *(50% pro-rated)* / *(in arrears)* qualifiers nothing else tells
    you. In an Arabic layout, render your own label from `type` as the primary line and keep
    `description` as a secondary one. Do not parse it.

13. **Expect repeats in the inbox.** The overdue reminder is a **ladder** — the same `invoiceId`
    arrives several times with `notice: 1,2,3…` and `isFinal`. An operator can also re-send an
    invoice on demand, producing a second `InvoiceIssued`. **Key your list on the notification
    `id` (a UUID), never on `data.invoiceId`.** Render `isFinal: true` distinctly.

14. **`parkingSpots` counts only bays the lease still holds.** It previously counted every holding
    ever recorded, so the number may go *down* for some tenants. That is the fix.

15. **Attachments and hero images need the `Authorization` header.** They stream from a private
    disk — they are not CDN URLs, and an anonymous image widget gets a 401 and a broken tile.

---

## 5. Errors

Every failure is `{ "message": "…", "statusCode": <int> }`. **`errors` is optional** — it is present
only on *field* validation. Always render `message`; it is localised to `Accept-Language` and
written for a human.

| Status | Means | Do |
|---|---|---|
| `400` | Malformed body (login only) | Form error |
| `401` | Missing / invalid / revoked token | Clear token → Login |
| `403` | Company blocked — **and the token has just been destroyed** | Clear token → Blocked. **Never retry** |
| `404` | Not found **or not yours**. On `/me/feed` + marketing posts: module off | Treat as gone / hide the section |
| `409` | `paymob-session`: gateway disabled · `pay-demo`: not available here | Hide the affordance |
| `422` | Field validation **or** a business refusal (no `errors` key) | Show `message`; attach `errors` when present |
| `429` | Throttled | Respect `Retry-After` |
| `502` | Paymob upstream failed | Offer retry, or share `paymentLinkUrl` |

The two money endpoints also return a stable `error` code — branch on it rather than parsing
`message`: `paymob_disabled` · `use_real_payment` · `invoice_not_payable` (+ `status`) ·
`no_balance` (+ `balance`, which is `payableAmount`) · `paymob_upstream_error`.

**Rate limits:** login 5/min · forgot/reset 3/min · **all `/me/*` 60/min** · public reads 120/min ·
public click 30/min. `GET /me/summary` exists so the home screen is one call — use it.

---

## 6. Notifications

**Two `type` fields, and they differ.** In the **inbox**, the top-level `type` is the class name and
`data.type` is a slug. On **push**, `data.type` is overwritten with the class name. So: read the
top-level `type` in the inbox, `data.type` on a push — both then speak the same vocabulary.
`link` is `null` in the inbox and **absent** on push when there is nowhere to go.

| `type` | Opens | Fires when |
|---|---|---|
| `InvoiceIssuedNotification` | `invoice` | Monthly run, or an operator sends one on demand |
| `PaymentReceivedNotification` | `payment` | A payment is captured |
| `InvoiceOverdueTenantNotification` | `invoice` | Dunning ladder — carries `notice` + `isFinal` |
| `LateFeeAppliedNotification` | `invoice` | A late fee is charged |
| `TenantRequestStatusChangedNotification` | `request` | Staff move the request |
| `TenantRequestCommentAddedNotification` | `request` | Staff post a public comment |
| `SalesDeclarationLockedNotification` | `sales` | Staff lock the turnover figure |
| `AnnouncementNotification` | `announcement` | Mall news broadcast |
| `SalesDeclarationReminderNotification` | **`null`** | Monthly nudge — no record to open |
| `LeaseExpiryApproachingNotification` | **`null`** | No mobile screen |
| `ViolationNoticeNotification` | **`null`** | No mobile screen |
| `MarketingPostReviewedNotification` | **`null`** | No mobile screen yet |

`link.target` → `invoice` `/invoices/{id}` · `payment` `/payments/{id}` · `request` `/requests/{id}` ·
`sales` `/sales/{id}` · `announcement` `/news/{id}`.

`data.title` / `data.body` are **already resolved** into the request's `Accept-Language` — render
them directly; do not compose your own sentence from the id fields.

---

## 7. Query parameters

`openapi.json` publishes **no** query parameters for authenticated lists (Scramble cannot see
`$request->query()`), so codegen will not produce them. Take them from here.

| Endpoint | Parameters |
|---|---|
| `/me/invoices` | `status`, `period_from`, `period_until` (vs `issue_date`) |
| `/me/payments` | `method`, `status`, `from`, `to` (vs `payment_date`) |
| `/me/credit-notes`, `/me/requests`, `/me/sales-declarations`, `/me/marketing-posts` | `status` |
| `/me/cam-allocations` | `status`, `period_year` |
| `/me/notifications`, `/me/announcements` | `unread=1` |
| `/me/statement` | `from`, `to`, `lang` — the range is honoured, but the **balances are as at today**, not as at `to`: a payment made after the window still shows against an invoice inside it |
| `/me/invoices/{id}/pdf`, `/me/payments/{id}/receipt`, `/me/cam-allocations/{id}/statement` | `lang=en\|ar` |
| `/public/malls/{code}/posts` | `type`, `featured` |
| `/public/malls/{code}/stores` | `category` |
| every list | `page`, `perPage` (default 25, max 100) |

> **`meta` is why a list is complete.** The money lists are newest-first, so ignoring it silently
> truncates the **oldest** rows — where long-unpaid invoices live — and any total computed over
> `data` is wrong with nothing to say so. Page off `meta.currentPage < meta.lastPage`.

---

## 8. How to verify you are in sync

1. Regenerate the client from `openapi.json` (schemas are current), then hand-add the query
   parameters from §7.
2. Smoke each rule against a real box — one call each:
   - `GET /me/invoices/{id}` → the response says `vatRate`, not `vat_rate`
   - `GET /me/invoices/{id}` → `payableAmount` is present
   - `POST /me/requests` with `category=elevator` → **201**, not 422
   - `POST /me/invoices/{id}/pay-demo` on staging → **409** `use_real_payment`
   - log in as a tenant with no active lease → **200** with `data: []`
   - after a `403`, the same token now gives `401`
   - `GET /me/vocabulary` → `version` + 32 vocabularies, each with `en` and `ar`
3. Delete your own EN/AR label tables once `/me/vocabulary` is wired.

**Test accounts** (password `password`): `tenant1@atriomwalk.test` (richest data),
`tenant2@`, `tenant3@atriomwalk.test`.

---

*Why each of these is the way it is — the defects behind them, and the gates that keep them true —
is in [`docs/modules/20-mobile-api.md`](../modules/20-mobile-api.md) §3, not repeated here. If this
document ever disagrees with the code, the code is right and this needs the fix.*
