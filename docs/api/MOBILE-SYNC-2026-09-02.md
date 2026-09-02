# Atriom Mobile — Backend ↔ App Sync Brief

> **For:** the mobile developer building the tenant app.
> **Companion to:** [`MOBILE-API.md`](MOBILE-API.md) — that document is the *contract*; this one is the
> **diff**: everything the backend does today that the version of `MOBILE-API.md` you were given
> either states wrongly, does not state at all, or has since changed.
> **Backend state captured:** 2026-09-02, from the code (`routes/api.php`,
> `app/Http/Resources/Api/V1/*`, `app/Http/Controllers/Api/V1/*`, `app/Http/Requests/Api/V1/*`),
> not from the prose doc.
>
> ### ✅ Every backend gap in this document is now CLOSED — the API shipped 2026-09-02
>
> Part 11 originally listed seven capabilities the web portal had and `/api/v1` did not, and
> §8 listed eight backend gaps. **All seven of Part 11 and all eight of §8 are built, tested and on the
> wire**, across nine commits — plus one thing this document did not ask for and should have:
> the API is now **bilingual to the panel's own standard** (Part 13). What changed on the backend is summarised in **[Part 12](#12--what-shipped-on-2026-09-02)**
> with the exact new payloads; **Part 11 and §8 are kept as written** so you can see what was wrong
> and why, but every ⚪ in them now reads ✅.
>
> **You can build against everything in this document. Nothing here is waiting on us.**
> **Nothing in `MOBILE-API.md` was deleted.** Everything below either *corrects* a line in it,
> *adds* something it never said, or flags a **backend gap** that is ours to close, not yours to
> work around.

**When this document is fully implemented, the app is in sync with the backend.** Work through
Parts 1 → 4 in order; Part 9 is the same list re-cut by screen so you can hand it to a sprint board.

---

## 0. How to read this

| Tag | Meaning | What it costs you |
|---|---|---|
| 🔴 **BREAKING** | The app is **wrong today** — a field it decodes is absent/renamed, or a call it makes now fails. | Ship a fix. |
| 🟠 **BEHAVIOUR** | Same endpoint, same shape, **different answer**. Nothing throws; the screen is just wrong. | Re-read the rule, adjust the UI logic. |
| 🟢 **NEW** | Additive field or endpoint. Nothing breaks if you ignore it, but the app is poorer. | Adopt when convenient. |
| ⚪ **BACKEND GAP** | The backend is missing something the app needs. **Do not build a workaround** — raise it. | Nothing, except knowing not to guess. |

**Counts:** 7 breaking · 10 behaviour · 5 new fields · **32 endpoints** across 12 areas that the app is probably not calling yet · **0 open backend gaps** (Part 12) · **the API is bilingual to the panel's standard** (Part 13).

> **Parts 1–10 answer *"does the app match the contract?"*. [Part 11](#11--the-api-vs-the-business-the-system-now-runs--portal--api-parity)
> answered *"does the contract match the system?"* — it did not, in 7 places, and all seven have
> since been built. **[Part 12](#12--what-shipped-on-2026-09-02) is the new surface**: 6 new
> endpoints, 30-odd new fields, and one conformance gate that stops the two surfaces drifting apart
> again. Read Part 12 for what to build; read Part 11 for why it was missing.

### The one structural warning, before anything else

`docs/api/openapi.json` is **fresh on response schemas** (regenerated after the last change — it
already carries `code`, `termMonths`, `paidAt`, `canConfirm`, and correctly omits the ETA keys), but
it documents **zero query parameters for every authenticated list endpoint**. Scramble infers
schemas from resource classes; it cannot see `$request->query('status')`. So codegen will hand you
list endpoints with **no** `page`, `perPage`, `status`, `unread`, `from`, `to`, `period_from`,
`period_until` or `lang`.

**Take response shapes from the spec. Take query parameters from `MOBILE-API.md` and from Part 6
below.** Only `/public/malls/{code}/posts` has its parameters published (`code`, `type`, `featured`).

---

## 1. 🔴 BREAKING — the app is wrong today

### B1 · Invoice line items are **camelCase**. The doc printed them snake_case.

`MOBILE-API.md` §4.3 says:

```
Adds `items: [{ id, description, type, amount, vat_rate, vat_amount, total }]`
```

That is a **typo in the prose doc**. Every key on this API is camelCased on the way out by
`CamelCaseResponseKeys` — there is no exception for nested arrays. The wire is:

```json
"items": [
  { "id": 811, "description": "Base rent - August 2026", "type": "base_rent",
    "amount": 10000.00, "vatRate": 0.00, "vatAmount": 0.00, "total": 10000.00 }
]
```

`openapi.json` → `InvoiceItemResource` confirms: `amount, description, id, total, type, vatAmount, vatRate`.

**Symptom if unfixed:** the invoice-detail screen shows every line's VAT as `0.00` / `null`, and the
line totals will not reconcile against the invoice header. A Dart model with a non-nullable
`double vatRate` throws on decode and takes the whole detail screen down.

**Fix:** decode `vatRate` / `vatAmount`. `MOBILE-API.md` §4.3 should be corrected in the same pass.

---

### B2 · `items[].type` is an **OPEN vocabulary**. There are 13 shipped codes, not 8 — and the operator adds more with no deploy.

`MOBILE-API.md` §4.3 lists eight: `base_rent`, `service_charge`, `utility`, `parking`,
`percentage_rent`, `marketing`, `late_fee`, `other`.

Actually shipped (`app/Enums/InvoiceItemType.php`) — **thirteen**:

| Code | What it is |
|---|---|
| `base_rent` | Rent. VAT-exempt in the shipped catalogue. |
| `service_charge` | Service charge. VAT-bearing. |
| `utility` | Metered electricity/water reconciliation. |
| `parking` | Parking bays let alongside the shop. |
| `percentage_rent` | Turnover rent, billed after a declaration is locked. |
| `marketing` | Marketing levy (5% of base rent). |
| `late_fee` | Late-payment penalty. |
| **`cam_recovery`** | **NEW to you** — the annual common-area true-up. |
| **`cam_admin_fee`** | **NEW** — the operator's admin fee on that recovery. |
| **`violation_fine`** | **NEW** — a billed fine (lease breach, signage, waste). |
| **`security_deposit`** | **NEW** — the deposit billed as an invoice line. |
| **`nsf_fee`** | **NEW** — bounced-cheque fee. |
| `other` | Catch-all. |

**And the set is deliberately not closed.** `App\Support\ValueSets` records `invoice_items.type`
as unregistered *on purpose*:

> *"The CHARGE CODE the line was raised under, and the accountant adds one with no deploy…
> `InvoiceItemType` names the codes that SHIP — it is not the set the column may hold, and
> registering it refused every code the operator created."*

So `key_money`, `fit_out_fee`, or anything else the mall's accountant creates on
`/admin/charge-codes` will arrive on this field tomorrow with no release on either side.

**Fix:**
1. Add the five new codes to your icon/label map.
2. **Never `switch` on `type` without a default branch.** An unknown code must render, not crash and
   not vanish.
3. **Render `description` as the line label.** It is the human sentence
   (`"Service charge - August 2026 (50% pro-rated)"`); `type` is for the icon and for grouping only.

---

### B3 · Maintenance sub-categories went **7 → 14**, and the list is now an operator-editable catalogue.

`MOBILE-API.md` §4.7 says maintenance → `electrical`…`other`. That was the PHP enum. Since EG-14 the
server validates `category` against **`TenantRequestSubcategory::optionsFor($type)`** — a database
catalogue whose *floor* is the enum.

The seven that were missing are exactly the trades the mall dispatches every week, so a tenant
literally could not report a stuck lift; they picked "Other" and the work order was raised with no
trade at all.

**`maintenance` — the full shipped list (14):**

| Code | EN label |
|---|---|
| `electrical` | Electrical |
| `plumbing` | Plumbing |
| `hvac` | Air conditioning |
| `structural` | Structural |
| `cleaning` | Cleaning |
| `safety` | Safety |
| **`elevator`** | Lift / escalator |
| **`generator`** | Generator / power |
| **`fire_safety`** | Fire safety |
| **`pest_control`** | Pests |
| **`security`** | Security |
| **`landscaping`** | Landscaping |
| **`waste`** | Waste |
| `other` | Other |

> ⚠️ The code is **`pest_control`**, not `pest`. And **`fire_safety`** (underscore) — it maps to the
> `fire-safety` trade (hyphen) through a foreign key, so do not derive one from the other.

**Unchanged for the other types:**

- `access` → `keys_cards`, `parking`, `after_hours`, `visitor`, `delivery`
- `document` → `lease_copy`, `renewal`, `termination_notice`, `noc_certificate`
- `permit` → `fit_out`, `temporary_installation`, `signage`, `other`
- `complaint` → `noise`, `cleanliness`, `conduct`, `other`
- `inquiry`, `billing`, `other` → **no sub-category**. Sending one is `prohibited` → **422**.

**Symptom if unfixed:** a tenant cannot report seven common faults; and any code the operator adds or
retires later produces a 422 the app cannot explain.

**Fix now:** ship the 14, and treat a 422 on `category` as a recoverable field error (show
`errors.category[0]`), never as a crash.

**Fix properly:** this list belongs on the wire — see ⚪ **G1**. Until that endpoint exists the app is
structurally one deploy behind the operator.

---

### B4 · `POST /me/invoices/{id}/pay-demo` now **409s on staging**, not just when Paymob is live.

`MOBILE-API.md` §4.4: *"Returns `409` once Paymob is live."* That is no longer the whole rule.

`App\Support\DemoPayments::enabled()` requires **all three**:

1. environment is **not** `production` — checked first, and the config flag cannot override it;
2. Paymob is **off**;
3. an explicit opt-in flag is set — and when unset it defaults to *on only in `local` / `testing`*.

> The old gate was `! paymob.enabled`, which is **inverted with respect to safety**: Paymob-off is
> the shipped default and the documented incident response, so the shortcut was live exactly when
> the system was most exposed. An authenticated tenant could mark their own invoice paid, and
> `billing:reconcile` stayed green because every internal relationship really was consistent — the
> money simply never existed.

**Symptom if unfixed:** on the staging box you are handed, the demo-pay button returns
`409 { "error": "use_real_payment" }` and the app looks broken.

**Fix:** gate the demo-pay affordance on a build flag of your own (debug builds only), or hide it on
the first `409`. Do not surface it in a build you would put in front of the client.

---

### B5 · A `draft` lease no longer appears in the login lease picker — `data` can legitimately be `[]`.

`LoginTenantAction` now filters through `visibleToTenant()`:

```php
$leases = $tenant->leases()->visibleToTenant()->with('unit.asset')->get()
```

A `draft` lease is terms still being written and unsigned; the portal already hid it and the API was
the surface that still leaked it.

**Symptom if unfixed:** a tenant whose only lease is still in drafting logs in successfully — valid
token, `200` — and gets `{"data": [], "accessToken": "…"}`. An app that assumes `data[0]` exists
crashes on the first screen after login, with no error the user can act on.

**Fix:** `data == []` is a **valid authenticated state**. Sign the user in, then show an empty-state
("Your lease is being prepared — contact the mall office") rather than an error or a logout.

> Same rule applies to `GET /me/leases`, which returns only *active* leases and has always been able
> to return `[]`.

---

### B6 · A `403` now **revokes the token**. Retrying is permanent failure.

`EnsureTenantActive` runs on every authenticated request:

```php
if (! $tenant || $tenant->status !== 'active') {
    $request->user()?->currentAccessToken()?->delete();
    abort(403, __('auth.account_blocked'));
}
```

A company blocked or deactivated *during* a live session used to keep full API access because its
Sanctum token was never re-validated. Now the window is closed — and the token is destroyed on the
way out.

**Symptom if unfixed:** the app retries, gets `403` again (now because the token is gone, i.e. it
would 401 if it re-authenticated), and either loops or sits on a spinner.

**Fix:** on **any** `403` from an authenticated route: **clear the stored token**, drop to the
Blocked screen, and offer sign-in. Never retry. Distinguish it from `401` in copy — `403` means *your
company's account is blocked*, `401` means *sign in again*.

---

### B7 · A `422` does **not** always carry `errors`.

`MOBILE-API.md` §3 says: *"`422` — Semantic validation failure (carries `errors`)"*. Two different
things produce a 422 and only one of them has an `errors` map:

| Source | Body |
|---|---|
| `ValidationException` (field rules) | `{ "message": "...", "errors": { "field": ["..."] }, "statusCode": 422 }` |
| **`DomainException`** (a business refusal) | `{ "message": "...", "statusCode": 422 }` — **no `errors` key** |

`bootstrap/app.php` maps `DomainException` → 422 deliberately: *"both are 'your request was
well-formed but I will not do it', and the client already handles that shape."* Business refusals
arriving this way include *"You have no active lease in that property, so you cannot post there"*,
*"This request has no unit to file against"*, and every marketing-post workflow refusal.

**Symptom if unfixed:** a non-nullable `errors` field throws, and a perfectly actionable sentence
becomes a crash. This exact bug already shipped once on this API — the endpoint was returning a 500
and the app showed a crash for a sentence the user could have acted on.

**Fix:** `errors` is **optional**. Always render `message` — it is localized to `Accept-Language` and
written for a human. Use `errors` to attach field-level highlights *when present*.

---

## 2. 🟠 BEHAVIOUR — same shape, different answer

### C1 · `outstanding` is now net of write-offs. `overdue` and `openCount` are **not**.

A **write-off** is the operator forgiving part of a debt. It deliberately does **not** move
`invoices.balance` (a write-off is not one of the four settlement channels), so a third term exists:
`collectableBalance() = max(0, balance − writtenOff)`.

`Tenant::outstandingBalance()` now sums the collectable figure. `BalanceController` and
`SummaryController` still compute `overdue` and the open-invoice count from the **raw** `balance`.

| Field | Endpoint | Netted of write-offs? |
|---|---|---|
| `outstanding` | `/me/balance`, `/me/summary` | ✅ yes |
| `overdue` | `/me/balance`, `/me/summary` | ❌ no |
| `openCount` / `openInvoices` | `/me/balance`, `/me/summary` | ❌ no |
| `invoice.balance` | `/me/invoices` | ❌ no (raw, by design) |

**Consequences for the UI:**
- `overdue > outstanding` is now **possible**. Do not assert otherwise; do not draw a progress bar
  that assumes `overdue ⊆ outstanding`.
- **Do not compute "current = outstanding − overdue".** It can go negative.
- Show `outstanding` as the headline. Show `overdue` as a separate warning figure, not as a slice.

This inconsistency is on our side to resolve — see ⚪ **G3**.

---

### C2 · The gateway charges **less** than the `balance` you display, when part of the invoice is written off.

`Invoice::payableAmount()` = `balance` net of write-offs, and it is now what **every** money path
uses: the Paymob session, the pivot allocation, the session-reuse comparison, the demo capture, and
the public pay link.

> *"a 10,000 invoice with 6,000 forgiven asked a tenant for 10,000 — measured on the real page. Paying
> it drives AR negative for that debt and leaves bad-debt expense standing for money that was
> collected."*

**`payableAmount` is not on the invoice payload.** So the app can show `balance: 10000.00`, open
checkout, and have the tenant charged `4000.00` with no explanation on screen.

**Interim rule:** on the payment screen, **do not print your own "Amount to pay" from `balance`.**
Let the gateway/pay page state the amount, or label your figure "Invoice balance" rather than
"Amount due". This is ⚪ **G2** — ask for the field.

---

### C3 · `paymentLinkUrl` is `null` for more invoices than before. Gate the Pay button on it.

`isPayable()` was a hand-rolled denylist of three statuses. It is now
`InvoiceSettlement::accepts($invoice) && payableAmount() > 0`, which:

- **excludes `draft`** (it had been missing — a draft answered `200` on the public pay link to an
  unauthenticated visitor, naming the tenant and the amount, and would have taken the money);
- **nets write-offs**, so a fully-forgiven invoice is no longer payable even though `balance > 0`.

**Fix:** show *Pay now* / *Share payment link* **iff `paymentLinkUrl != null`**. Never derive it from
`balance > 0` or from `status`. The same predicate now guards `paymob-session` and `pay-demo`, so
your button and the server's answer cannot disagree.

---

### C4 · `GET /me/statement?from=&to=` now actually **bounds** the document.

Until 2026-09-02 the service derived an "as at" date from `to` and **bounded nothing with it** — the
invoice list, the payments, the credit notes and both settlement queries had no upper bound. A
statement requested to 31 March rendered *"as at 31 March"* over rows dated April, May and June, on
the document a tenant's accountant reconciles a quarter from.

- `to` is now inclusive to **end of day** (`payments.payment_date` carries a time, so a bound at
  00:00 would silently drop everything received that day).
- Open invoices are selected on `collectableBalance()`, so a partly forgiven debt is not chased.
- **Still true, and worth putting in your UI copy:** the *balances* are as they stand **today**, not
  as they stood on the end date. A payment made after the window still shows against an invoice
  inside it. What the statement claims is *which transactions fall in the window*.

**Fix:** you may now trust the range. Label the screen with the range you sent.

---

### C5 · A **unit owner** can now file a request — but only if you **omit** `unitId`.

Module 37's rule is that a unit owner *is* a `tenants` row. The request service now resolves a unit
from `handed_over` ownerships when the tenant holds no lease. Before this, an owner got a NOT NULL
violation (a 500) or an empty picker.

**But the API validator was not widened with it.** `CreateTenantRequestRequest` still checks
`unitId` against **leases only**:

```php
$ownsUnit = $this->user()->leases()
    ->where(fn ($q) => $q->where('unit_id', $value)
        ->orWhereHas('units', fn ($u) => $u->whereKey($value)))
    ->exists();
```

**Fix:** if `GET /me/leases` returns `[]` (an owner, not a lessee), **do not send `unitId`** — the
server derives the shop. Sending it yields `422 { errors: { unitId: [...] } }`. This is ⚪ **G5**.

**Also new:** a party with neither a lease nor a handed-over shop now gets a clean
`422` refusal ("this request has no unit to file against") instead of a 500.

---

### C6 · Invoice line `description` is **stored English prose**. It is never translated.

`MonthlyBillingService` writes the label as a literal, deliberately:

> *"`invoice_items.description` is stored prose and everything already in it is English… Translating
> this one clause would freeze the BILLING RUN's locale into the row — a queue worker running in
> Arabic would store an Arabic word beside an English month on the same line."*

So in an Arabic UI you will get `"Service charge - August 2026 (in arrears) (50% pro-rated)"` on a
line inside an otherwise fully Arabic screen.

**Fix:** for an Arabic-first layout, render your **own** localized label from `type` as the primary
line, and keep `description` as a smaller secondary line (it carries the *period* and the *pro-rated*
/ *in arrears* qualifiers, which nothing else on the payload tells you). Do not attempt to translate
or parse it. Do not right-align it as if it were Arabic — wrap it so the bidi algorithm does not
reorder the date.

---

### C7 · Notification `title` / `body` are already resolved into `Accept-Language`.

A stored bell row carries **both** languages (`BellChannel` renders the whole payload once per
supported locale). `NotificationResource` resolves it for *this* request through
`NotificationLocale::forDisplay()` and **strips** the `i18n` block, along with the panel's render
hints (`format`, `duration`, `icon`, `color`, `actions`).

`actions` is stripped for a stronger reason than tidiness: it holds an absolute `/admin` or `/portal`
URL for a web panel the app has no session in.

**Fix:** render `data.title` / `data.body` directly — **but you must send `Accept-Language` on the
inbox call**, or you get the app's default language regardless of the user's setting. Do not compose
your own sentence from the id fields.

---

### C8 · **Push** language follows `tenants.locale`, not `Accept-Language`.

A push is not a request — there is no header. Laravel wraps each recipient's dispatch in
`withLocale()` from `HasLocalePreference`, which reads the `tenants.locale` column.

**The app cannot read or write that column** — it is on neither `GET /me` nor `PATCH /me`.

**Consequence:** a tenant switches the app to Arabic; the inbox is Arabic (C7), and every **push**
keeps arriving in whatever `tenants.locale` says — which for most rows is unset, i.e. the app
default. Same for e-mailed invoices and the dunning letters.

This is ⚪ **G4**. Do not work around it (there is nothing to work around it *with*). Flag it in the
UI if you must: the language toggle should not promise it changes notifications.

---

### C9 · Dunning is now a **ladder**. The overdue reminder repeats with an escalating level.

`InvoiceOverdueTenantNotification.toDatabase()` payload (camelCased on the wire):

```json
{
  "type": "invoice_overdue_reminder",
  "invoiceId": 50, "invoiceNumber": "INV-AW-0417",
  "balance": 4000.00,          // COLLECTABLE, net of write-offs — not invoice.balance
  "daysOverdue": 22,
  "notice": 2,                 // NEW — which reminder this is; 1 = first
  "isFinal": false,            // NEW — the notice at the configured ceiling: a final demand
  "title": "...", "body": "..."
}
```

The wording is now **the operator's own**, resolved per property and per language, so two malls under
one operator chase differently and the sentence is not a system string.

**Fix:** the same `invoiceId` will now produce **several** inbox rows over time. Do not de-duplicate
by `invoiceId`. Escalate the treatment: render `isFinal: true` distinctly (red, non-dismissible
banner on the invoice), and consider showing `notice` as "Reminder 2 of 3".

---

### C10 · An operator can now **send an invoice on demand** — expect duplicate `InvoiceIssued` rows.

`SendInvoiceToTenantService` lets the mall re-send an invoice from the collections worklist. It fires
the same `InvoiceIssuedNotification` the monthly billing run does.

**Fix:** the inbox must tolerate two or more `InvoiceIssuedNotification` rows for one `invoiceId`.
Key your list on the notification `id` (a UUID), never on `data.invoiceId`.

---

## 3. 🟢 NEW fields to render

| # | Endpoint | Field | Type | What it is |
|---|---|---|---|---|
| **N1** | `GET /me`, `GET /auth/me` | **`code`** | `string?` | The retailer's own account number, e.g. `TN-0000042`. **They are the party asked to quote it** — the mall asks for it on the phone. Withholding it left the app unable to show the one identifier the mall uses. Put it on the profile header. Nullable on rows predating the code. |
| **N2** | `GET /me/leases[]` | **`termMonths`** | `int?` | Contracted term length. Pairs with `commencementDate`/`expiryDate` on the lease card. |
| **N3** | `GET /public/malls/{code}/stores[]`, `…/stores/{id}` | **`descriptionAr`**, **`websiteUrl`**, **`instagramHandle`** | `string?` | The doc's card shape omitted all three. `descriptionAr` completes the bilingual pair (you already have `nameAr`); the other two are the store's own links for the visitor app's directory detail. |
| **N4** | `POST /me/invoices/{id}/paymob-session` | **`iframeId`**, **`orderId`**, **`paymentId`**, **`expiresAt`** | `string`, `int`, `int`, ISO8601 | The doc named only `paymentToken` / `iframeUrl` / `reused`. `iframeId` is what the Paymob **Flutter SDK** needs alongside the token; `orderId`/`paymentId` are for support/reconciliation when a tenant rings about a charge; `expiresAt` is when the session dies — surface a countdown or refresh rather than letting the sheet go stale. All four are explicitly cast (`int`/`bool`), so decode them as such — an earlier build of this endpoint published them all as `string` and the Dart client threw on decode. |
| **N5** | `GET /me/requests[]` | **`attachments[]`** | array | The doc showed attachments only on the POST. The **list** eager-loads media too, so thumbnails are available without opening the detail. Each item: `{ id: int, name, mimeType, size: int, url }`. |

---

## 4. Endpoints the app is probably not calling yet

These are all built, tested and live. Ordered by how much they are worth.

### 4.1 · `GET /me/requests/{id}/attachments/{media}` — **undocumented in `MOBILE-API.md` entirely**

The prose doc never mentions this route, yet it is the **only** way to render a photo attached to a
request. It is an authenticated, tenant-scoped stream from the **private** disk (it replaced an
enumerable public URL). The `url` inside each `attachments[]` item already points here.

**Send the `Authorization` header when loading these images.** They are not CDN URLs — an image
widget configured for anonymous fetch gets a `401` and renders a broken tile. Same rule for
`…/sales-declarations/{id}/attachments/{media}` and `…/announcements/{id}/hero/{media}`.

### 4.2 · `POST /me/requests/{id}/confirm` and `/dispute` — the tenant's sign-off

Gate on **`canConfirm`**, which is **narrower than `canRate`**: confirming is a control *before*
closure (`resolved` only); rating is feedback *after the fact* (`resolved` or `closed`). Do not reuse
one flag for both.

When `canConfirm` is true, ask *"Is this resolved?"* with **two buttons** — confirm and "not fixed".
Showing only one of them is worse than showing neither. `dispute` **requires** a `reason` (≤1000
chars); it is posted into the comment thread the operator reads, and a bare "not fixed" sends an
engineer back knowing no more than the first time.

Silence is consent: `requests:auto-close` still closes the request after the configured window, and
`confirmedAt` stays `null` — which is how the operator tells "the tenant agreed" from "the timer ran
out". So `status: closed` with `confirmedAt: null` must **not** render as "you confirmed this".

### 4.3 · `GET /me/payments/{id}/receipt` — the receipt voucher PDF

The same document the admin table and the web portal hand out. Only for a payment whose money
actually arrived (`captured` / `reconciled` / `settled`); anything else is **`422`** with a message
you can show, because a receipt asserts cash was received.

**Gate the button on `receiptAt != null`** — that is exactly the same moment, and it is one field
instead of a status list you would have to keep in step.

### 4.4 · Mall news — `GET /me/announcements` (+ `/{id}`, `POST /{id}/read`, `GET /{id}/hero/{media}`)

Four endpoints; if the app has no Mall-news screen, this whole module is invisible to the tenant even
though the operator is sending notices. `unreadAnnouncements` on `/me/summary` is its badge — a
**different** number from the bell's `unreadNotifications`.

- Pinned first, then newest. `?unread=1` filters.
- Both languages ship on every row (`titleAr`/`bodyAr`, null when the operator wrote only English —
  fall back to `title`/`body`, never render a blank).
- `GET /{id}` deliberately **does not** mark it read, so a push preview or a prefetch never counts as
  someone having seen it. Call `POST /{id}/read` when the detail is actually on screen.
- `category` ∈ `general` · `operations` · `event` · `emergency` · `hours` — colour `emergency`
  differently.
- The hero image is on a **private** disk (a notice can carry an evacuation map) — see 4.1.

### 4.5 · Device management — `GET /me/devices`, `DELETE /me/devices/{id}`

You almost certainly call `POST /me/devices`. The list + delete are what make "sign out my lost
phone" possible. The raw push token is **never** echoed back, which is precisely what makes the
`DELETE` usable by a client that did not perform the registration.

### 4.6 · `?lang=en|ar` on all three PDF endpoints

`GET /me/invoices/{id}/pdf`, `GET /me/statement`, `GET /me/payments/{id}/receipt`.

Use it for *"give me the English copy"* without changing the app's locale — a tenant whose accountant
files in English, a landlord's lawyer who asked for the other copy. An unsupported value falls back
to `Accept-Language` rather than failing.

On the API the **request wins** over the tenant's stored locale, deliberately: the caller *is* the
recipient and has already said what they read.

### 4.7 · `from` / `to` on `GET /me/payments`

Against `payment_date`, `YYYY-MM-DD`. Without them there is no date to pass, so a "cleared this
period" filter has to be invented from the device clock.

### 4.8 · Marketing posts (8 endpoints) + `GET /me/feed`

The retailer composes their own offer/event/news card and sends it to the mall for review. **Nothing
here publishes** — the furthest a tenant call can move a post is `pending`.

- `POST /me/marketing-posts` (multipart, `hero` ≤5 MB jpeg/png/webp, `gallery[]` ≤6 files)
- `POST /me/marketing-posts/{id}` — update. **POST, not PATCH** (a multipart body does not survive
  PHP's PATCH handling). Only while `isEditable`.
- `POST /{id}/submit` → `pending` · `POST /{id}/withdraw` → back to `draft` · `DELETE /{id}` (drafts only)
- **`reviewNotes` is the whole point of a rejection** — show it prominently, or the retailer
  resubmits the same artwork.
- `status`, `isFeatured` and `priority` are **ignored if sent** — do not build a UI for them.
- `GET /me/feed` — what is on at the malls you trade in, retailer-visible notices included.

> This whole group (including `/me/feed`) sits behind `EnsureMarketingPostsEnabled`. If the operator
> switches the module off, **all nine routes 404** — not 403. Handle a 404 on `/me/feed` as "feature
> off", and hide the section, rather than showing an error.

### 4.9 · The visitor/shopper surface (6 unauthenticated endpoints)

`GET /public/malls`, `…/{code}/posts`, `…/posts/{id}`, `POST …/posts/{id}/click`, `…/stores`,
`…/stores/{id}`. A different audience and a different app — build the shopper experience against
these, never against `/me/*`. Separate throttles (120/min reads, 30/min the click). Everything
unservable is a `404`; there is no `403` on this surface.

---

## 5. Notifications — the complete inventory

`MOBILE-API.md` §4.6 tells you to branch on `type` but never lists the values. Here is the whole set
a **tenant** can receive, with where each one opens.

**Two `type` fields exist and they are different strings. This matters.**

| | Inbox (`GET /me/notifications`) | Push (FCM `data`) |
|---|---|---|
| Top-level `type` | short **class name** — `PaymentReceivedNotification` | — |
| `data.type` | the **bell slug** — `payment_received` | **overwritten** with the class name |
| `link` | present, **`null`** when nowhere to go | key is **absent** when nowhere to go |

`PushChannel::wireData()` overwrites `data.type` so *"a push tap and an inbox tap route through the
same mapper"*. So: **on push, read `data.type`; in the inbox, read the top-level `type`.** Both then
speak the same vocabulary — the class name.

### The tenant-facing set

| Class name (`type`) | `data.type` | `link.target` | Fires when |
|---|---|---|---|
| `InvoiceIssuedNotification` | `invoice_issued` | `invoice` | Monthly run, **or** an operator sends one on demand (C10) |
| `PaymentReceivedNotification` | `payment_received` | `payment` | A payment is captured |
| `InvoiceOverdueTenantNotification` | `invoice_overdue_reminder` | `invoice` | Daily dunning sweep — now a ladder (C9) |
| `LateFeeAppliedNotification` | `late_fee_applied` | `invoice` | A late fee is charged |
| `TenantRequestStatusChangedNotification` | `request_status_changed` | `request` | Staff move the request |
| `TenantRequestCommentAddedNotification` | `request_comment_added` | `request` | Staff post a **public** comment |
| `SalesDeclarationLockedNotification` | `sales_declaration_locked` | `sales` | Staff lock the turnover figure |
| `SalesDeclarationReminderNotification` | `sales_declaration_reminder` | **`null`** | Monthly nudge — *no record to open* |
| `AnnouncementNotification` | `announcement` | `announcement` | Mall news broadcast |
| `LeaseExpiryApproachingNotification` | `lease_expiry_approaching` | **`null`** | Lease nearing its end — **no app screen** |
| `ViolationNoticeNotification` | `violation_notice` | **`null`** | A lease-breach notice — **no app screen** |
| `MarketingPostReviewedNotification` | `marketing_post_reviewed` | **`null`** | The mall approved/returned your offer — **no app screen yet** |

`link.target` → route mapping: `invoice` → `/invoices/{id}` · `payment` → `/payments/{id}` ·
`request` → `/requests/{id}` · `sales` → `/sales/{id}` · `announcement` → `/news/{id}`.

**Four of the twelve have `link: null` and that is the honest answer, not a bug.** Render the row
**unclickable**. Never invent a route. Three of them (`lease_expiry`, `violation_notice`,
`marketing_post_reviewed`) become clickable the day the app grows a screen — one line changes on our
side and every historic row lights up.

> ⚠️ **Never infer a destination from the class name.** The app used to match substrings — it looked
> for a `maintenanceId` that has never existed (the payload has always carried `request_id`), so the
> two most frequent tenant alerts deep-linked nowhere; and `LateFeeApplied…` /
> `LeaseExpiryApproaching…` matched no keyword at all and fell through while carrying a perfectly
> good id. **`link` is the only supported way to resolve a destination.**

---

## 6. Open vocabularies — never hardcode these as closed enums

The backend deliberately widened four value sets into **operator-editable catalogues**. A `switch`
with no default is a crash waiting for the day the mall's accountant adds a row.

| Field | Where | Why it is open |
|---|---|---|
| `invoiceItem.type` | `/me/invoices/{id}` | Charge codes are added by the accountant with **no deploy** (B2) |
| `request.category` | `/me/requests` | `tenant_request_subcategories` is a catalogue; the enum is only its floor (B3) |
| `payment.method` | `/me/payments` | `payment_methods` is a catalogue — the operator adds a rail (Fawry, a new wallet) as a row |
| `tenant.retailCategory` | public store cards | `retail_categories` is a catalogue |

**Rule for all four:** unknown value → render the raw code (or a neutral icon), never blank, never
crash. Where a human label exists on the payload (`description` on an invoice line), prefer it.

### Values that ARE closed (safe to switch on)

| Field | Values |
|---|---|
| `invoice.status` | `draft` · `issued` · `partially_paid` · `overdue` · `paid` · `cancelled` · `credited` · `disputed` · **`written_off`** — ⚠️ `MOBILE-API.md` §4.3 lists eight and **omits `written_off`**. You *will* receive it: only `draft` is hidden from tenants, and a written-off invoice still explains a number the tenant remembers. It is not payable (`paymentLinkUrl: null`) but it is visible. **You will never see `draft`** |
| `payment.status` | `initiated` · `authorized` · `captured` · `reconciled` · `settled` · `failed` · `refunded` · `bounced` — **only `captured`+ reduce a balance** |
| `payment.channel` | `admin` · `portal` · `mobile_api` · `payment_link` |
| `request.requestType` | `maintenance` · `complaint` · `inquiry` · `access` · `billing` · `document` · `permit` · `other` |
| `request.status` | `submitted` · `acknowledged` · `in_progress` · `awaiting_tenant` · `resolved` · `closed` · `cancelled` |
| `request.priority` | `low` · `medium` · `high` · `urgent` |
| `request.channel` | `portal` · `whatsapp` · `phone` · `email` · `walk_in` · `admin` — ⚠️ **there is no `mobile_api`**; an app-submitted request records `portal`, so you cannot tell your own submissions apart by this field |
| `creditNote.status` | `draft` · `issued` · `applied` · `void` |
| `salesDeclaration.status` | `submitted` · `locked` · `disputed` |
| `announcement.category` | `general` · `operations` · `event` · `emergency` · `hours` |
| `marketingPost.status` | `draft` · `pending` · `published` · `rejected` · `archived` |
| Locales | `en` · `ar` — anything else falls back silently |

### Query parameters (the spec does not publish these — see §0)

| Endpoint | Parameters |
|---|---|
| `GET /me/invoices` | `status`, `period_from`, `period_until` (vs `issue_date`), `page`, `per_page` |
| `GET /me/payments` | `method`, `status`, `from`, `to` (vs `payment_date`), `page`, `per_page` |
| `GET /me/credit-notes` | `status`, `page`, `per_page` |
| `GET /me/requests` | `status`, `page`, `per_page` |
| `GET /me/sales-declarations` | `status`, `page`, `per_page` |
| `GET /me/notifications` | `unread=1`, `page`, `per_page` |
| `GET /me/announcements` | `unread=1`, `page`, `per_page` |
| `GET /me/marketing-posts` | `status`, `page`, `per_page` |
| `GET /me/statement` | `from`, `to`, `lang` |
| `GET /me/invoices/{id}/pdf`, `GET /me/payments/{id}/receipt` | `lang` |
| `GET /public/malls/{code}/posts` | `type`, `featured`, `page`, `perPage` |
| `GET /public/malls/{code}/stores` | `category` |

`per_page` defaults to **25**, hard-capped at **100**. `perPage` also works (camelCase is accepted on
input).

> **`meta` is the whole reason a list is complete.** The money lists are newest-first, so a client
> that ignores `meta` silently truncates the **oldest** rows — exactly where long-unpaid invoices
> live. Page off `meta.currentPage < meta.lastPage`. `links` is present only on resource-collection
> endpoints; **key your paging off `meta`, never `links`.** The two deliberately unpaginated
> endpoints are `GET /me/leases` and `GET /me/devices`.

---

## 7. Error handling — the consolidated rules

| Status | Meaning | What the app must do |
|---|---|---|
| `400` | Malformed/missing body — **login only** | Show a form error |
| `401` | Missing / invalid / revoked token, or bad credentials | Clear token → Login |
| `403` | Company blocked **— and the token has just been destroyed** (B6) | Clear token → Blocked screen. **Never retry** |
| `404` | Not found **or not yours** — never a leak | Treat as "gone". On `/me/feed` + marketing posts, also means *module off* (4.8) |
| `409` | `paymob-session`: Paymob disabled · `pay-demo`: shortcut not available here (B4) | Hide the affordance |
| `422` | Field validation **or** a business refusal. **`errors` is optional** (B7) | Always show `message`; attach `errors` when present |
| `429` | Throttled | Respect `Retry-After` |
| `502` | Paymob upstream failed | Offer retry / the shareable link |
| `500` | Server error | Generic; report it — a 500 on this API is a bug, not a state |

### The machine-readable `error` codes on the two money endpoints

`MOBILE-API.md` documents only the status codes. Both payment endpoints also return a stable `error`
string you should branch on rather than parsing `message`:

```json
// 409
{ "message": "...", "error": "paymob_disabled" }      // paymob-session
{ "message": "...", "error": "use_real_payment" }     // pay-demo

// 422
{ "message": "...", "error": "invoice_not_payable", "status": "written_off" }
{ "message": "...", "error": "no_balance", "balance": 0.0 }

// 502
{ "message": "...", "error": "paymob_upstream_error" }
```

Note `balance` in the `no_balance` body is **`payableAmount()`**, not `invoice.balance` — which is
the clearest illustration of C2.

### Rate limits

| Scope | Limit |
|---|---|
| `POST /auth/login` | 5 / min / (email+IP) |
| `POST /auth/forgot-password`, `/auth/reset-password` | 3 / min |
| All authenticated `/me/*` | **60 / min / tenant** |
| Public feed reads | 120 / min / IP |
| `POST …/posts/{id}/click` | 30 / min / IP |

> 60/min covers a home screen fan-out comfortably, but **not** a naive "refresh everything on every
> screen focus". `GET /me/summary` exists so the home screen is **one** call — use it, not
> `/me/balance` (a strict subset that omits `creditAvailable`) plus four others.

---

## 8. ✅ Backend gaps — **all eight closed on 2026-09-02**

> Every row here is shipped. G7 was a briefing document that had contradicted the contract for ten
> days; G8 was a scheduling race that could put a chase letter in the app's inbox for a debt the
> tenant had just paid — see the note under the table.

| # | Gap | Why it mattered | **What shipped** |
|---|---|---|---|
| **G1** ✅ | **No endpoint for request types + sub-categories.** The catalogue is operator-editable and the app must hardcode it (B3). | Every category the operator adds or retires needs an app release. A retired code also makes the picker offer something the server will 422. | `GET /me/request-types` → `[{ type, label, labelAr, requiresDecision, hasSla, subcategories: [{ code, label, labelAr }] }]`. Cache it; refresh on app start. |
| **G2** ✅ | **`payableAmount` was not on the invoice payload.** | The app displays `balance` and the gateway charges something else (C2). | Add `payableAmount` to `InvoiceResource` (`writeOffs` is already eager-loaded on both endpoints, so it is free). |
| **G3** ✅ | **`overdue` / `openCount` were not netted of write-offs** while `outstanding` is (C1). | `overdue > outstanding` is reachable; the app cannot derive "current". | Sum `collectableBalanceSql()` in `BalanceController` + `SummaryController`, as `outstandingBalance()` already does. |
| **G4** ✅ | **`locale` was neither readable nor writable via `/me`.** | The app's language toggle cannot reach push, e-mail, or an operator-sent PDF (C8). The column is fillable on the model and written by no API. | Add `locale` to `TenantResource` and to `UpdateProfileRequest` (`Rule::in(['en','ar'])`). |
| **G5** ✅ | **`unitId` validation accepted leased units only**, though the service now resolves owned ones (C5). | A unit owner naming their own shop gets a 422. | Widen the closure to `unitOwnerships()->where('status', HandedOver)->covering()`. |
| **G6** ✅ | **`MOBILE-API.md` §6 "Not in v1" is stale.** | It says Paymob and push are deferred; §4.4 and §4.9 document both as built, and attachments too. Contradiction inside one doc. | Rewrite §6 to: ETA references only. |
| **G7** ✅ | **`MOBILE-APP-BRIEF.md` was stale (2026-06-28).** | It said *"ETA e-invoicing is wired (mock mode)"* for ten days after module 16 was **frozen in code** — a briefing that contradicts the contract is worse than one that says less, because the stale half is the one a reader trusts. | Refreshed: the *why* stays, every factual claim now points at `MOBILE-API.md` and this brief rather than restating it. |
| **G8** ✅ | **SW-156 (fixed 2026-09-02):** the overdue sweep re-checked its stamp under the lock but not the balance. | A payment landing mid-run produced a dunning notice on an already-settled invoice — a chase letter in the app's inbox for money the tenant had just paid, which is the one notification guaranteed to be read saying the one thing guaranteed to be wrong. | The guard re-reads `collectableBalanceForUpdate()` inside the lock — a LOCKING read, because on MySQL's REPEATABLE READ a plain one is answered from a snapshot taken before the wait, and the stale direction is the harmful one. Nothing is stamped when nothing is sent, so the ladder resumes rather than skipping a rung. |

---

## 9. The same list, cut by screen

| Screen | Do this |
|---|---|
| **Login** | B5 — `data: []` is a valid signed-in state, not an error |
| **Any authenticated call** | B6 — `403` ⇒ wipe the token, go to Blocked, never retry · B7 — `422` may have no `errors` |
| **Home / dashboard** | C1 — never compute `outstanding − overdue` · badge `unreadAnnouncements` separately from `unreadNotifications` · one call: `/me/summary` |
| **Invoice list** | C3 — gate Pay on `paymentLinkUrl != null` |
| **Invoice detail** | **B1 — `vatRate` / `vatAmount`** · B2 — 13+ open `type` codes, render `description` as the label · C6 — the label is English prose |
| **Pay** | C2 — do not print your own "Amount to pay" from `balance` · B4 — demo-pay 409s on staging · N4 — `iframeId`, `expiresAt` · §7 — branch on the `error` code |
| **Statement** | C4 — `from`/`to` now bound the document · 4.6 — `?lang=` |
| **Payments** | 4.3 — receipt PDF, gated on `receiptAt != null` · 4.7 — `from`/`to` filters |
| **Requests — new** | **B3 — 14 maintenance sub-categories**, `pest_control` not `pest` · C5 — omit `unitId` for a unit owner |
| **Requests — detail** | 4.2 — confirm/dispute pair on `canConfirm` · `closed` + `confirmedAt: null` ≠ "you confirmed" · **never infer approval from `status`** — use `requiresDecision` + `decision` · 4.1 — attachments need the auth header · N5 — thumbnails on the list too |
| **Sales declarations** | `declaredSales: null` ⇒ "Pending review"; `0` is an answer. Same rule for `calculatedPercentageRent` |
| **Notifications** | §5 — full type table · `link` is the only destination · four types have `link: null` ⇒ unclickable · C7 — send `Accept-Language` · C9/C10 — duplicates are normal, key on the UUID |
| **Push** | §5 — `data.type` is the **class name** on push, the slug in the inbox · `link` key is **absent**, not null, when there is nowhere to go · C8 — language follows the server column, not the app |
| **Mall news** *(likely missing)* | 4.4 — 4 endpoints; `GET /{id}` does not mark read; private hero image |
| **Profile** | N1 — show `code` · G4 — the language toggle does **not** reach push/e-mail yet |
| **Settings / devices** | 4.5 — list + revoke, for a lost phone |
| **My offers** *(likely missing)* | 4.8 — 8 endpoints; `reviewNotes` is the point; POST-not-PATCH; 404 = module off |
| **Visitor app** *(separate)* | 4.9 — 6 unauthenticated endpoints; N3 — three more store fields |

---

## 10. How to verify you are in sync

1. **Regenerate the client** from `docs/api/openapi.json` — schemas are current. Then hand-add the
   query parameters from §6; codegen will not produce them.
2. **Smoke each breaking item against a real box**, in this order — every one is a single call:
   - B1: `GET /me/invoices/{id}` → assert the response contains `vatRate`, not `vat_rate`.
   - B2: assert your renderer survives `"type": "cam_recovery"`.
   - B3: `POST /me/requests` with `category=elevator` → expect `201`, not `422`.
   - B4: `POST /me/invoices/{id}/pay-demo` on staging → expect `409 use_real_payment`.
   - B5: log in as a tenant with no active lease → expect `200` with `data: []`.
   - B6: after a `403`, confirm the same token now yields `401`.
   - B7: `POST /me/requests` as a party with no unit → `422` with **no** `errors` key.
3. **Ask the backend team for G1–G5** before designing around them. G1 (the categories endpoint) is
   the one that otherwise leaves the app permanently one release behind the operator.

**Test accounts** (password `password` for all): `tenant1@atriomwalk.test` (richest data — invoices,
payments, requests), `tenant2@atriomwalk.test`, `tenant3@atriomwalk.test`.

---

*Backend contact: this document is generated from the code, not from the prose doc. If something
here disagrees with `MOBILE-API.md`, this document is right and `MOBILE-API.md` needs the fix.*

---

## 11. ⚫ The API vs the business the system now runs — **portal ↔ API parity**

> Parts 1–10 answer *"does the app match the contract?"*. This part answers a harder question:
> **"does the contract match the system?"** It does not, in six places. None of these is a bug in
> the API — every endpoint returns exactly what it promises. They are **capabilities the operator
> has shipped, the web portal exposes, and the mobile API was never extended to carry.**
>
> **All seven were ⚪ BACKEND work and all seven are now ✅ shipped** (2026-09-02). This part is kept
> as originally written — the diagnosis, not just the fix — because the *reasoning* is what tells
> you which screens matter and why. For the resulting contract, go to
> [Part 12](#12--what-shipped-on-2026-09-02).

### The root cause, stated once

`docs/modules/03-tenant-portal-users.md` and `ConfirmTenantRequestAction` both state the rule:

> *"The portal and `/api/v1` are the same surface with different renderers."*

**Nothing enforces it.** There is no conformance gate comparing the two, so the rule has been
honoured for *visibility* (drafts are hidden from both — that was fixed twice, in
`InvoiceResource` and again in `LoginTenantAction`) and quietly not honoured for *content*. Every
gap below is one commit that landed on the portal and stopped there.

---

### P1 ✅ *(shipped)* · The lease card was missing **15 commercial terms** the portal shows

`LeaseResource` publishes 13 fields. `Filament/Portal/Resources/Leases/Schemas/LeaseInfolist`
renders those plus the following — the tenant's own contract, readable on the web, invisible in the app:

| Missing | Why the tenant needs it |
|---|---|
| `rentCommencementDate` | **When rent starts**, which is later than the term on any fit-out lease. A tenant in fit-out cannot see when they begin paying without ringing the office. |
| `billingFrequency` | Monthly / quarterly — whether next month has an invoice in it. |
| **`securityDeposit`** (agreed) | The contracted figure. |
| **`depositHeld`** (`Lease::depositHeld()`) | What they have actually paid. |
| **`depositOutstanding`** (`Lease::depositShortfall()`) | ⚠️ **The single most important omission on this list.** The portal comment is explicit: *"a deposit is never invoiced — nothing else in the portal will ever ask them for it."* There is **no invoice** for the shortfall, so this figure is the **only** way a tenant learns they still owe a deposit. It does not exist on the API at all, so an app-only tenant can never be told. |
| `hasMarketingLevy`, `marketingLevyRate` | The 5%-of-rent levy, shown as a rate they can check against the invoice line. |
| `escalationType`, `escalationRate`, `escalationAmount`, `nextEscalationDate`, `escalationFloorRate`, `escalationCeilingRate` | When and by how much the rent steps. The portal fixed a related bug here: keying visibility on `escalationRate > 0` hid it entirely from a **fixed-amount** lease, so *"a tenant whose rent steps by EGP 5,000 every year was shown nothing at all about it — their own contract, invisible on their own portal."* The API shows nothing to anybody. The collar (floor/ceiling) matters because *"a cap they negotiated is worth more to them than the headline rate."* |
| `percentageRentThreshold`, `percentageRentFrequency` | The API sends `percentageRentRate` only — so the app can say *"5%"* but not *"5% above EGP 800,000, quarterly"*, which is the part that decides whether they owe anything. |
| **`rentableItems[]`** — `code`, `type`, `pivot.monthlyRate` | The API sends a **count** (`parkingSpots`) and nothing else. The portal's own note: *"Without it a tenant sees a 'Parking & rentable items' line on the invoice with no way to check WHICH bays they are paying for or at what rate — **the most common billing query there is**."* The app is in exactly that state. |
| **Signed lease document download** | A portal row action (`downloadDocument`, private disk). On the app the tenant must raise a `document` request and wait for a human. |

**Ask for:** these fields on `LeaseResource`, plus `GET /me/leases/{id}/document` for the signed PDF.

---

### P2 ✅ *(shipped)* · `creditAvailable` counted **credit notes only** — the tenant's money on account was invisible

There are two different credits and the app is told about one:

| | What it is | On the API? |
|---|---|---|
| **Credit notes** | The operator issued a credit document. | ✅ `/me/summary.creditAvailable`, `/me/credit-notes` |
| **Credit on account** (`Tenant::creditBalance()`) | **Money the tenant has already paid** that is not yet applied to an invoice — a received payment's unallocated remainder. It sits on the books as unearned revenue. | ❌ **nowhere** |

`SummaryController` computes `creditAvailable` as `creditNotes()->where('status','issued')->sum('balance')`.
The **portal's** `AccountBalance` widget shows `$tenant->creditBalance()` beside it, as its own stat —
*"only shown when there IS a credit."* Four admin surfaces read it too, and
`ApplyTenantCreditService` spends it as one of the **four settlement channels**.

**Consequence:** a tenant who overpaid, or paid before an invoice was raised, sees that money
**nowhere in the app** — not in the balance, not as credit, not against an invoice. It looks lost.
Then an invoice is silently part-settled from it and the payment history does not explain why.

**Ask for:** `creditOnAccount` on `/me/summary` and `/me/balance` (`Tenant::creditBalance()`), kept
distinct from `creditAvailable`.

---

### P3 ✅ *(shipped)* · **CAM had no API surface at all** — while it bills the tenant real money

`Filament/Portal/Resources/CamAllocations` is a full portal resource — list, detail, and a
**CAM statement PDF** download (`CamStatementPdfService`). There is **no `/api/v1` counterpart**:
no endpoint, no resource, nothing.

Meanwhile the annual reconciliation puts **`cam_recovery`** and **`cam_admin_fee`** lines straight
onto the tenant's invoice (see B2). So the app shows a tenant a large, once-a-year charge with
*no way whatsoever* to see the pool, their share, the basis, their estimates already paid, or the
statement that explains it. Every one of those becomes a phone call.

**Ask for:** `GET /me/cam-allocations`, `GET /me/cam-allocations/{id}`, and
`GET /me/cam-allocations/{id}/statement` (PDF) — the API twin of the portal resource.

---

### P4 ✅ *(shipped)* · A **multi-unit lease** showed one shop, and the wrong area

`lease_unit` is a dated many-to-many: a lease holds a **master** unit plus additional ones, each with
its own `effective_from` / `effective_to` window. `Lease::units()`, `unitsOn($date)` and
`totalAreaSqm()` are the real accessors, and rent is derived from the **total** area
(`deriveBaseRentFromRate()` = rate × `totalAreaSqmOn()`).

`LeaseResource` sends:

```php
'unit' => $this->whenLoaded('unit', fn () => [ … 'area_sqm' => (float) $this->unit->area_sqm … ])
```

`$this->unit` is `belongsTo(Unit::class)` — the **master only**. So for a tenant who has taken a
second shop:

- the app shows **one** unit code where they hold two or more;
- `unit.areaSqm` is the **master's** area, while the rent on the same card was priced on the
  **combined** area. The two figures do not reconcile, and the tenant cannot tell why.

**Ask for:** `units[]` (code, floor, areaSqm, isMaster, effectiveFrom/To) and `totalAreaSqm` on
`LeaseResource`, keeping `unit` as the master for backward compatibility.

---

### P5 ✅ *(shipped)* · A **unit owner** could sign in, be billed, and not be told which shop it was for

Module 37's rule is that a unit owner **is a `tenants` row** — they sign in with the same
credentials, receive maintenance assessments, pay them and read their own statement. `invoices.lease_id`
was made **nullable** and `invoices.unit_ownership_id` added precisely so an owner with no lease can
be billed, and `billing:run-assessments` bills them monthly.

**The word `unitOwnership` does not appear anywhere under `app/Http/Controllers/Api`,
`app/Http/Resources/Api` or `app/Actions/Api`.** So for an owner:

| Call | What they get |
|---|---|
| `POST /auth/login` | `data: []` — no lease (see B5) |
| `GET /me/leases` | `[]` |
| `GET /me/invoices` | ✅ their assessments — but **`lease: null`**, so **no unit, no floor, no property** on the card |
| `GET /me/summary` | `canDeclareSales: false`, correct; `openMaintenance` works |
| Anything about the shop they own | **nothing** |

Laravel's `whenLoaded()` guards the null, so this does not crash — it just renders an invoice with
no context. An owner of three shops sees three identical-looking bills.

**Ask for:** `GET /me/unit-ownerships` (unit, property, handover date, status, share) and a
`unitOwnership` block on `InvoiceResource` for the invoices that carry one.

Note the related half is already fixed: an owner **can** now raise a tenant request (C5) — the
service resolves handed-over shops. So the app is half-ready for owners already.

---

### P6 ✅ *(shipped)* · Invoice-line **dispute state** was not on the wire

`invoice_items` carries `disputed_at`, `disputed_reason`, `disputed_by_id`, and the portal's invoice
detail renders `disputed_reason` under the line. `InvoiceItemResource` publishes seven fields and
none of them is the dispute.

`invoices.status` **can** be `disputed`, so the app can see that *something* on the invoice is under
argument — and cannot show **which line**, or what was said. `invoices.notes` is on the portal
infolist and not on the API either.

Note the tenant **cannot raise a dispute themselves** on any surface — `DisputeInvoiceItemService` is
called only from `Filament/Admin/Actions/InvoiceActions`, i.e. an operator records the dispute the
tenant raised by phone. That is a deliberate design, not a gap. **The app's route for a billing
argument is `POST /me/requests` with `requestType: "billing"`** — build the "Query this charge"
button that way, and expect the operator to mark the line disputed afterwards.

**Ask for:** `disputedAt` + `disputedReason` on `InvoiceItemResource`, and `notes` on `InvoiceResource`.

---

### P7 ✅ *(shipped)* · Nothing kept the two surfaces in step

This codebase gates almost everything with a conformance test — property isolation, GL sources,
deletion policy, posting dates, action authorisation, navigation, screen guides. **There is no gate
comparing the portal to `/api/v1`**, which is why a rule both files state in prose has drifted six
times without a single red test.

**Ask for:** a `PortalAndApiAnswerTheSameQuestionsConformanceTest` — for each of the nine portal
resources, assert an `/api/v1` counterpart exists, and for the shared models assert the API resource
publishes every field the portal infolist renders (or names it in an `EXEMPT` list with a reason).
That is the shape every other registry in this project already uses, and it is the only thing that
stops this list regrowing.

---

### Build order — the same ranking, now that all of it exists

| Rank | Item | Why first |
|---|---|---|
| **1** | **P1 — `depositOutstanding`** ✅ | The only channel by which a tenant is ever told they owe a deposit. No invoice exists for it. Money the operator is not collecting. |
| **2** | **P2 — credit on account** ✅ | The tenant's own money, currently invisible to them. Generates support calls and looks like theft. |
| **3** | **P3 — CAM** ✅ | A large annual charge the app shows with no explanation. The single biggest source of billing queries. |
| **4** | **P1 — rentable items detail** ✅ | The portal's own note calls it *"the most common billing query there is"*. |
| **5** | **P4 — multi-unit leases** ✅ | Wrong area shown against a rent priced on a different area. |
| **6** | **P5 — unit owners** ✅ | A whole class of user with a contextless app. Growing as handovers complete. |
| **7** | **P6 — dispute state** ✅ | Small; mostly a completeness fix. |
| **8** | **P7 — the gate** ✅ | Stops the list coming back. |

---

## 12. ✅ What shipped on 2026-09-02

Everything Part 11 and §8 asked for. **Six commits, six new endpoints, ~30 new fields, one
conformance gate.** This part is the contract — build from here.

Regenerate your client from [`openapi.json`](openapi.json) first; every schema below is in it.
Query parameters still are not (§0), so take those from the tables here.

### 12.1 New endpoints

| Method | Path | What it is |
|---|---|---|
| `GET` | **`/me/leases/{id}/document`** | The tenant's **signed lease** PDF. `has_document` on the lease tells you whether to show the button. Private disk → send `Authorization`. 404 if the operator uploaded nothing, if it is not yours, or if the lease is a `draft`. |
| `GET` | **`/me/cam-allocations`** | Their share of each year's common-area cost. `?status=`, `?period_year=`, `?page=`, `?perPage=`. Paginated, newest year first. |
| `GET` | **`/me/cam-allocations/{id}`** | One year in full. |
| `GET` | **`/me/cam-allocations/{id}/statement`** | The **service-charge statement PDF** — the same file the portal hands out. `?lang=en\|ar`. |
| `GET` | **`/me/unit-ownerships`** | The shops this party **owns**. Not paginated. Empty for an ordinary retailer — that is the cheapest way to tell the two apart. |
| `GET` | **`/me/request-types`** | The request-type + sub-category **catalogue**, both languages. **Fetch on app start and cache; do not hardcode.** |

### 12.2 `GET /me/leases` — 20 new fields

```jsonc
{
  "id": 9, "reference": "LSE-AW-2026-0007", "status": "active",
  "commencementDate": "2026-01-01", "expiryDate": "2027-12-31",
  "rentCommencementDate": "2026-04-01",   // NEW — when RENT starts. Later than the term on any fit-out lease.
  "termMonths": 24,
  "billingFrequency": "monthly",          // NEW — monthly | quarterly | semiannual | annual
  "baseRentMonthly": 10000, "serviceChargeMonthly": 2000,
  "totalMonthlyAmount": 12000, "currency": "EGP",

  // ── The deposit, in the three numbers it takes to read one ─────────────────── NEW
  "securityDeposit": 180000,       // agreed
  "depositHeld": 150000,           // actually received
  "depositOutstanding": 30000,     // ⚠️ NEVER INVOICED. This is the ONLY way a tenant is told.

  // ── The levy ──────────────────────────────────────────────────────────────── NEW
  "hasMarketingLevy": true, "marketingLevyRate": 5,

  // ── Escalation. BOTH shapes ship; branch on escalationType ─────────────────── NEW
  "escalationType": "fixed_amount",   // or "percentage" / index-based
  "escalationRate": null, "escalationAmount": 5000,
  "escalationFloorRate": null, "escalationCeilingRate": null,
  "nextEscalationDate": "2027-01-01",

  // ── Percentage rent: the rate alone answers nothing ───────────────────────── NEW
  "hasPercentageRent": true, "percentageRentRate": 5,
  "percentageRentThreshold": 800000,     // NEW
  "percentageRentFrequency": "annual",   // NEW — annual accumulates, monthly resets

  // ── The bays, not just how many ───────────────────────────────────────────── NEW
  "parkingSpots": 1,                     // ⚠️ now counts OPEN holdings only (was: every one ever recorded)
  "rentableItems": [
    { "id": 3, "code": "P-14", "type": "parking", "monthlyRate": 750, "effectiveFrom": "2026-01-01" }
  ],

  // ── Premises. `unit` is unchanged (the MASTER); `units` is all of them ─────── NEW
  "unit":  { "id": 4, "code": "A-01", "floor": "G", "category": "retail", "areaSqm": 120, "asset": {…} },
  "units": [ { "id": 4, "code": "A-01", "floor": "G", "areaSqm": 120, "isMaster": true },
             { "id": 7, "code": "A-02", "floor": "G", "areaSqm": 80,  "isMaster": false } ],
  "totalAreaSqm": 200,        // NEW — the area the RENT was priced on. Do not sum `units` yourself.

  "hasDocument": true         // NEW — gate the signed-lease download on this
}
```

**Screen guidance.** Render `depositOutstanding` prominently when `> 0` with a "how to pay" line —
there is no invoice for it and nothing else will ever ask. Show `rentableItems` under the invoice's
*Parking & rentable items* line, which is what the tenant is trying to reconcile. When
`units.length > 1`, show all of them and label the area `totalAreaSqm`, or the rent will not
reconcile with the area beside it.

### 12.3 Money — `/me/balance`, `/me/summary`, `/me/invoices`

```jsonc
// GET /me/balance   and   GET /me/summary
{
  "outstanding": 4000,        // net of write-offs (unchanged)
  "overdue": 4000,            // ⚠️ CHANGED — now also net of write-offs
  "openCount": 1,             // ⚠️ CHANGED — a fully written-off invoice no longer counts
  "creditAvailable": 1500,    // credit NOTES the operator issued
  "creditOnAccount": 2500,    // NEW — cash the TENANT paid that is not yet applied
  "currency": "EGP", "isDelinquent": true
}
```

`overdue > outstanding` is **no longer reachable**, so the C1 warning is retired — but keep the
two figures as separate lines rather than a slice, and **show `creditOnAccount` as its own row**.
Never sum it with `creditAvailable`: they are different things, and adding them tells the tenant
they have twice what they have.

```jsonc
// GET /me/invoices/{id}
{
  "balance": 10000,           // what was OWED — reconcile against this
  "payableAmount": 4000,      // NEW — what checkout will actually take. Put THIS on the Pay button.
  "unitCode": "A-01",         // NEW — the shop, through whichever agreement raised the invoice
  "notes": "…",               // NEW — the operator's note on the document
  "lease": { … } | null,
  "unitOwnership": { "id": 2, "reference": "UO-…", "unit": { "id": 9, "code": "B-07", "floor": "G" } },  // NEW
  "items": [
    { "id": 811, "description": "Service charge - August 2026", "type": "service_charge",
      "amount": 2000, "vatRate": 14, "vatAmount": 280, "total": 2280,
      "disputedAt": "2026-08-20T09:00:00+00:00",                    // NEW
      "disputedReason": "Common area was closed for three weeks." } // NEW
  ]
}
```

**`payableAmount` replaces `balance` on every money affordance.** Keep showing `balance` labelled
*Invoice balance*; the amount you charge, quote in a confirmation, or print on a button is
`payableAmount`. They differ only when the operator has forgiven part of the debt — and that is
exactly when getting it wrong takes money that was never owed.

### 12.4 `GET /me/payments/{id}` — four new fields

```jsonc
{
  "chequeNumber": "000451",             // NEW
  "chequeClearanceDate": "2026-09-07",  // NEW
  "gatewayTransactionId": "pm_…",       // NEW
  "notes": "Credited against August, not September."  // NEW
}
```

*"Did you get my cheque?"* is the most common call a mall office takes. Show `chequeNumber` on a
cheque payment and `gatewayTransactionId` on a card one — the tenant quotes it to their own bank.

### 12.5 `GET /me/credit-notes/{id}` — line items

```jsonc
{ "items": [ { "id": 5, "description": "Service charge — three weeks the mall was shut",
               "amount": 1500, "vatRate": 0, "vatAmount": 0, "total": 1500 } ] }
```

Same shape as an invoice line, so reuse the widget. Before this the app showed a total and the
one-word `reason`, which never says *which charge* was credited.

### 12.6 `GET /me/cam-allocations`

```jsonc
{
  "id": 12, "status": "billed", "periodYear": 2025,
  "totalActualExpense": 100000,   // what the mall spent — the denominator the share is OF
  "proRataSharePct": 60,
  "allocatedAmount": 60000,       // their share
  "estimatedPaid": 54000,         // billed monthly on account across the year
  "trueUpAmount": 6000,           // the difference. POSITIVE = owed. NEGATIVE = a credit is coming.
  "currency": "EGP",
  "property": { "id": 1, "code": "AW", "name": "Atriom Walk" },
  "unit": { "id": 4, "code": "A-01", "floor": "G" },
  "agreement": { "kind": "lease", "reference": "LSE-AW-2026-0007" }   // or kind: "ownership"
}
```

**Do not compute `trueUpAmount` client-side** — it is what the operator actually billed, and a
subtraction that disagrees with it is a support call. Colour it: positive = owed, negative = credit.
Link it from the `cam_recovery` invoice line, which is the moment the tenant asks.

### 12.7 `GET /me/unit-ownerships`

```jsonc
{ "id": 2, "reference": "UO-AW-0004", "status": "handed_over",
  "tenureType": "freehold", "managementMode": "vacant",
  "ownershipSharePct": 100, "assessmentBasis": "area", "participationPct": null,
  "purchaseDate": "2025-01-01", "handoverDate": "2025-02-01",
  "startedAt": "2025-02-01", "endedAt": null, "currency": "EGP",
  "unit": { "id": 9, "code": "B-07", "floor": "G", "category": "retail", "areaSqm": 95 },
  "property": { "id": 1, "code": "AW", "name": "Atriom Walk" } }
```

`status` ∈ `reserved` · `contracted` · `handed_over` · `transferred`. **Every** ownership is
returned, not just `handed_over` — a `contracted` shop is one they have bought and not yet
received, which is the state they most want a screen for.

**Detecting an owner:** `/me/leases` empty **and** `/me/unit-ownerships` non-empty. They can be
both. On a request for an owned shop **you may now send `unitId`** (C5 is fixed) — but only for a
`handed_over` one; a `contracted` shop is still refused, deliberately.

### 12.8 `GET /me/request-types` — stop hardcoding the categories

```jsonc
{ "types": [
    { "code": "maintenance", "label": "Maintenance", "labelAr": "…",
      "requiresDecision": false, "hasSla": true,
      "subcategories": [ { "code": "elevator", "label": "Lift / escalator", "labelAr": "…" }, … ] },
    { "code": "inquiry", "label": "Enquiry", "labelAr": "…",
      "requiresDecision": false, "hasSla": false, "subcategories": [] }
  ],
  "priorities": ["low", "medium", "high", "urgent"] }
```

- **Fetch on app start, cache, refresh on launch.** The catalogue is operator-editable: they add a
  sub-category and it must appear without an app release. That is the whole point of the endpoint.
- **Both languages on every row** — switch locale with no round trip.
- `subcategories: []` means **render no picker**: sending `category` for such a type is
  `prohibited` and returns 422.
- `requiresDecision` tells you which types are *questions* — pair it with `decision` on the request
  (§4.7). Never infer approval from `status`.
- Sub-categories the app is missing today are listed in **B3**; after wiring this endpoint you no
  longer maintain that list at all.

### 12.9 `GET /me` · `PATCH /me` — `locale`

```jsonc
{ "locale": "ar" }   // NEW, readable and writable. "en" | "ar" | null.
```

This is the language the tenant is **written to** in: **push notifications**, e-mail, and any
document the *operator* produces for them. `Accept-Language` cannot reach those — a push is not a
request and has no header.

**Wire your language toggle to `PATCH /me { locale }` as well as to `Accept-Language`.** Until now
the column answered null for every tenant that has ever existed, because nothing could write it.
An unsupported value is refused with 422 rather than accepted-and-ignored.

### 12.10 What did NOT change

Everything in Parts 1–7 still applies exactly as written — **B1 (`vatRate`/`vatAmount`), B4
(demo-pay 409s on staging), B5 (`data: []` is a valid login), B6 (403 destroys the token) and B7
(a 422 may carry no `errors`) are unaffected by this release.** So is the JSON-integer money rule
(pinned in `LeaseCarriesItsOwnTermsTest`): every new money field above arrives as an `int` when the
value is whole, so decode with `(x as num).toDouble()`.

The one behaviour that changed underneath you: **`parkingSpots` now counts only bays the lease still
holds.** It previously counted every holding ever recorded, so a tenant who gave a bay back went on
being told they had it. If your UI shows that number, it may go *down* for some tenants — that is
the fix, not a regression.

### 12.11 And it cannot drift again

`PortalAndApiAnswerTheSameQuestionsConformanceTest` + `App\Support\PortalApiParity` now fail the
build when a portal resource has no `/api/v1` counterpart, or when the portal renders a field the
matching API resource does not publish and nobody has written down why. Resources are discovered
**from disk**, so a tenth portal screen fails on the day it lands rather than the day somebody
compares them.

It found four more gaps on its very first run — credit-note line items, the payment references,
`unitCode`, and the unit on a sales declaration — all four of which are in 12.3–12.6 above. That is
the argument for the gate in one sentence: a rule nobody checks is a rule that has already drifted.


---

## 13. 🌍 The API is bilingual to the panel's standard

> **`GET /me/vocabulary` — fetch once on launch, cache on `version`.**
> Added 2026-09-02, with `TheApiSpeaksBothLanguagesConformanceTest` to keep it honest.

### Why this exists

The Filament panels have been held to a hard line by
`ArabicPanelHasNoEnglishChromeConformanceTest`: no English chrome anywhere, in any of the three
panels. **The API had no counterpart, and it fails differently** — a panel renders *words*, while an
API sends *codes* and leaves the words to you:

```json
{ "status": "overdue", "method": "instapay", "type": "cam_recovery", "priority": "urgent" }
```

So "is the API bilingual?" is really two questions:

1. **Is every sentence it sends present in both languages?** — `message`, refusals, validation.
   ✅ It already was: `lang/en` and `lang/ar` are at file-for-file parity and
   `SetApiLocale` resolves `Accept-Language` on every request.
2. **Can you render every CODE it sends in Arabic without maintaining your own table?**
   ❌ You could not. That is what shipped today.

The app was carrying an EN+AR table for **25 vocabularies across 16 resources**, kept in step with a
backend it cannot see. **For five of them a client-side table cannot work at all:**

| Vocabulary | Why a shipped table is structurally wrong |
|---|---|
| `invoiceItem.type` | The accountant adds a charge code on `/admin/charge-codes` — **no deploy**. It is on an invoice line the next billing night. |
| `payment.method` | The operator adds a payment rail (Fawry, a new wallet) as a **row**. |
| `publicStore.retailCategory` | The shopper directory's own filter is built from these rows. |
| request sub-categories | Already 7 → 14 once; served by `/me/request-types` (§12.8). |
| charge-code labels | An operator **renaming** a shipped code changes what every screen calls it. |

This is the exact failure `IsCodeCatalogue` exists to prevent in the panel — *"an operator-added
code has no lang key and would render `admin.enums.method.fawry` on the very screen whose filter
lists Fawry"* — reproduced on the surface the retailer actually reads.

### `GET /me/vocabulary`

```jsonc
{
  "data": {
    "version": "9f2c1ab4e7d05e83",          // hash of the rendered LABELS — cache on this
    "openCatalogues": [                      // the ones a shipped table can never be right about
      "invoiceItem.type", "payment.method", "publicStore.retailCategory"
    ],
    "vocabularies": {
      "invoice.status": {
        "issued":         { "en": "Issued",         "ar": "…" },
        "partially_paid": { "en": "Partially paid", "ar": "…" },
        "overdue":        { "en": "Overdue",        "ar": "…" },
        "written_off":    { "en": "Written off",    "ar": "…" }
      },
      "payment.method":  { "instapay": { "en": "InstaPay", "ar": "…" }, … },
      "invoiceItem.type":{ "cam_recovery": { "en": "CAM recovery", "ar": "…" }, … },
      "lease.status": {…}, "request.status": {…}, "unitOwnership.status": {…}, …
    }
  }
}
```

**28 vocabularies**, keyed `resource.field` in camelCase — exactly the path you already hold. Where
two fields share a set the entry is repeated rather than aliased, so you never join.

### How to use it

1. **Fetch on launch.** One call, small, cacheable.
2. **Cache on `version`.** It hashes the rendered *labels*, so a renamed charge code changes it and
   a backend refactor does not. Re-fetch when it differs; skip re-parsing when it does not.
3. **Look up `vocabularies["invoice.status"][invoice.status][locale]`.**
4. **Falling back to a stale copy is safe.** A code you cannot label should render **as itself**,
   never as a blank cell — that is what the panel does, and a blank cell reads as "no such status"
   rather than "I don't have that word yet".
5. **Delete your own EN/AR label tables.** Keeping them is how you end up one release behind on the
   five open catalogues, and silently wrong on the twenty-three closed ones.

### Which fields it covers

Money — `invoice.status` · `invoiceItem.type` · `payment.status` · `payment.method` ·
`payment.channel` · `creditNote.status` · `creditNote.reason` · `camAllocation.status`.
Leasing — `lease.status` · `lease.billingFrequency` · `lease.escalationType` ·
`lease.percentageRentFrequency` · `lease.type` (rentable items) · `lease.category` (unit).
Ownership — `unitOwnership.status` · `.tenureType` · `.managementMode` · `.assessmentBasis` ·
`.category`. Requests — `tenantRequest.requestType` · `.status` · `.priority` · `.channel`.
Rest — `tenantSalesDeclaration.status` · `announcement.category` · `marketingPost.status` ·
`.type` · `.audience` · `publicMarketingPost.type` · `tenant.type` · `tenant.status` ·
`publicStore.retailCategory`.

**Deliberately NOT in it**, each with a reason in `ApiVocabulary::NOT_A_VOCABULARY`:
`notification.type` (a class name you branch on for an icon — its words are already resolved into
`data.title`/`data.body`), `tenantRequestComment.authorKind` (a role, and `authorName` already reads
*"Property team"*), `deviceToken.platform`, the two `mimeType`s, and the two free-text
`*Reason` fields — a sentence a person typed is not a vocabulary. **`tenantRequest.category` is
excluded on purpose**: a sub-category belongs to its *type* and the same code sits under more than
one, so it keeps that structure in `/me/request-types` rather than being flattened into a lookup
that would lose it.

### What keeps it true

`TheApiSpeaksBothLanguagesConformanceTest` fails the build when:

- an API resource emits a classification field the registry does not cover **or explain** — the
  fields are discovered **from the resource files**, so a registry checked only against itself
  cannot hide what it omits;
- a closed set has no `ValueSets` entry, or any of its codes has no `en` **or** `ar` translation —
  checked with **`Lang::has(..., fallback: false)`**, because the default falls back to English and
  the obvious form of this check only ever catches a key missing from *both*;
- an Arabic label is byte-identical to its English and carries no Arabic script — `Lang::has()`
  proves a key exists and can never prove somebody put Arabic in it, which is the realistic failure
  when a hundred labels are added in one pass and reviewed in English;
- the whole vocabulary does not serve non-empty in both languages **over the real route**;
- an exemption goes stale, or its reason is too thin to review.

### Two real defects it found while being written

- **`credit_notes.reason` and `marketing_posts.audience` were in no value set at all.** The admin
  form offers `reason` as a Select over six values and the column accepted anything — a typo'd or
  imported reason saved cleanly, matched no filter, and rendered as a raw code on the tenant's own
  credit note. `audience` decides *who sees a marketing post*, and `MarketingPost::liveFor()`
  branches on it, so a typo showed a post to nobody. Both are registered now; `audience` joined
  `CLASSIFICATION_SUFFIXES` so the next column of that shape cannot ship unclassified, while
  `credit_notes.reason` is exempted **by name** — `reason` is free text in twelve other columns and
  a suffix rule would demand a value set for all of them.
- **`invoiceItem.type` served an EMPTY vocabulary on an unseeded install.** `ChargeCode::options()`
  is rows-only — unlike its `IsCodeCatalogue` twins it has no per-code floor — so a box whose chart
  had not been seeded answered *"there are no charge types"* rather than showing the shipped ones.
  The floor is `InvoiceItemType`, labelled through `ChargeCode::labelFor()`, which is the
  row → lang key → humanised order the panel settled on.
