# Paymob — the complete integration reference

**Audience:** an engineer (or an AI agent) porting this Paymob card-payment
integration into **another system**, including its **mobile app**.

This is the single authoritative description of how Atriom takes card money
through Paymob. It is written to be **portable**: every rule that matters is
stated as a rule, with the Atriom file that implements it named next to it, so
you can either copy the files or re-implement the rule in another stack.

> **Nothing in here is a credential.** Real keys live in `.env` only. Never
> commit an API key, HMAC secret, or `payment_token` anywhere — including
> logs (see [§14 Observability](#14-observability--what-gets-logged)).

**Read order if you are porting this:**
1. [§1 The model in one page](#1-the-model-in-one-page) — get the shape right first
2. [§4 File inventory](#4-file-inventory--what-to-copy) — what to copy
3. [§16 Gotchas](#16-gotchas--the-expensive-lessons) — **read before writing code**, these are paid-for lessons
4. [§17 Port checklist](#17-port-checklist-for-a-different-system) — the framework-agnostic spec

---

## 1. The model in one page

Paymob is a **redirect/webhook** gateway, not a synchronous charge API. Three
facts drive the whole design:

1. **You never see the card.** You create an *order* + a short-lived
   *payment key* on the server, then hand the user to Paymob's hosted iframe
   (or SDK). The card form is Paymob's.
2. **The browser/app result is not the truth.** Paymob shows the user
   "Success" and redirects back *before* — sometimes seconds before — its
   server-to-server webhook reaches you. **The HMAC-verified S2S callback is
   the only source of truth.** Every client (web, mobile) must re-read the
   invoice from your backend, never trust the redirect or the SDK callback.
3. **Opening a session is check-then-act with a network call in the middle.**
   Two taps = two live orders against one debt = the customer can pay twice.
   This must be serialised. (See [§9](#9-the-initiator--sessions-reuse-and-the-concurrency-lock).)

### The state machine

```
                    ┌──────────────────────────────────────────┐
                    │ user taps "Pay"                          │
                    └───────────────────┬──────────────────────┘
                                        ▼
             ┌────────────────────────────────────────────────┐
             │ SERVER: acquire lock(invoice, channel)         │
             │   reusable session in window? → return it      │
             │   else: auth → order → payment_key             │
             │   create Payment(status=initiated)             │
             │   allocate full balance to the invoice         │  ← NO effect on AR yet
             │   release lock                                 │
             └───────────────────┬────────────────────────────┘
                                 ▼
             ┌────────────────────────────────────────────────┐
             │ CLIENT: open iframe_url (WebView / browser)     │
             │         or hand payment_token to the SDK        │
             └───────────────────┬────────────────────────────┘
                                 │
              ┌──────────────────┴───────────────────┐
              ▼                                      ▼
   ┌─────────────────────┐              ┌──────────────────────────┐
   │ BROWSER REDIRECT    │              │ S2S WEBHOOK (truth)      │
   │ GET /paymob/return  │              │ POST /paymob/callback    │
   │ UX only. Trust      │              │ verify HMAC-SHA512       │
   │ nothing.            │              │ success && !is_voided    │
   └─────────────────────┘              │   → clamp allocation     │
                                        │   → status = captured    │
                                        │ else → status = failed   │
                                        └────────────┬─────────────┘
                                                     ▼
                                   ┌──────────────────────────────────┐
                                   │ Payment::saved hook              │
                                   │  · recompute invoice totals      │
                                   │  · fire receipt notification     │
                                   │  · GL posts Dr Bank / Cr AR      │
                                   └──────────────────────────────────┘
```

### Who owns which step

| Step | Owner | Notes |
|---|---|---|
| 1. Start session | **Your backend** | Never the client — the API key must never reach a device |
| 2. Open iframe / SDK | Client (web, mobile) | Client gets only a one-order-scoped token |
| 3. Card form, 3DS | **Paymob** | You have zero involvement |
| 4. S2S webhook | **Your backend** | HMAC-verified. The only thing that marks money received |
| 5. Confirm to user | Client | Re-fetch from your backend; poll if needed |

### The single most important invariant

> **An `initiated` payment has zero financial effect.** It exists, it is
> allocated to the invoice, and the invoice's AR balance is completely
> unchanged. Only the webhook flipping it to `captured` moves money.

In Atriom this is enforced because `Invoice::recomputeTotals()` sums *only*
`Payment::RECEIVED_STATUSES` (`captured`, `reconciled`, `settled`). Whatever
you build, make the equivalent filter explicit — it is what makes an abandoned
checkout harmless.

---

## 2. Channels — one gateway, four entry points

Every session is tagged with a **channel** (`payments.channel`). This is not
cosmetic: session reuse, the browser-return destination, and reporting all key
off it.

| Channel constant | Value | Where it starts | Where the browser returns |
|---|---|---|---|
| `Payment::CHANNEL_MOBILE` | `mobile_api` | `POST /api/v1/me/invoices/{id}/paymob-session` | App intercepts `/paymob/return` in the WebView |
| `Payment::CHANNEL_PORTAL` | `portal` | Tenant portal "Pay Now" action | `/portal/invoices` with a flash |
| `Payment::CHANNEL_LINK` | `payment_link` | Public `POST /pay/{token}/start` (no login) | Public `/pay/{token}/status` page |
| `Payment::CHANNEL_ADMIN` | `admin` | Reserved — admin-initiated | `/portal/invoices` |

**Why channels matter:** a mobile session must never be reused for a
payment-link payment. Their return handling differs, and the public status
page would show a stranger's payment state. Reuse lookup filters on channel
(`PaymobPaymentInitiator::findReusableSession`).

---

## 3. Sequence — the three live flows

### 3a. Mobile app (the one you're porting)

```
App                      Your API                  Paymob
 │  POST /me/invoices/{id}/paymob-session           │
 │  Authorization: Bearer <sanctum token>           │
 ├─────────────────────►│                           │
 │                      │ lock(invoice, mobile_api) │
 │                      │ POST /api/auth/tokens     │
 │                      ├──────────────────────────►│
 │                      │◄──────── auth token ──────┤
 │                      │ POST /api/ecommerce/orders│
 │                      ├──────────────────────────►│
 │                      │◄──────── order_id ────────┤
 │                      │ POST /api/acceptance/payment_keys
 │                      ├──────────────────────────►│
 │                      │◄──────── payment_token ───┤
 │                      │ INSERT Payment(initiated) │
 │◄─ {paymentToken, iframeUrl, orderId, paymentId, expiresAt, reused}
 │                                                  │
 │  open iframeUrl in WebView (or SDK + paymentToken)
 ├─────────────────────────────────────────────────►│
 │                       card form + 3DS            │
 │◄──── redirect to /paymob/return?success=… ───────┤
 │  (app intercepts, closes the WebView)            │
 │                      │◄── POST /paymob/callback ─┤  ← the truth
 │                      │    (HMAC-SHA512)          │
 │                      │  capture + recompute      │
 │  GET /me/invoices/{id}  (poll)                   │
 ├─────────────────────►│                           │
 │◄─── status: "paid" ──┤                           │
```

### 3b. Public payment link (no login)

`GET /pay/{token}` → review page → `POST /pay/{token}/start` → redirect away to
Paymob → `/paymob/return` → routed back to `GET /pay/{token}/status`.

The token is a 48-char random string on `invoices.payment_link_token`. It is a
**bearer credential** — whoever holds the URL sees the tenant name, line items
and amounts with no login and no expiry. Rotation
(`Invoice::rotatePaymentLinkToken()`) is the remedy for a leaked link;
deliberately *not* an expiry, because an expiry silently kills legitimate links
in already-sent emails and turns every late payer into a support call.

### 3c. Tenant portal / admin

Same as mobile but server-rendered: the Filament action calls the initiator and
`redirect()->away($session['iframe_url'])`.

---

## 4. File inventory — what to copy

### Core (copy these first — this is the whole integration)

| File | Lines | Role | Portable? |
|---|---|---|---|
| [`app/Services/Paymob/PaymobClient.php`](../../app/Services/Paymob/PaymobClient.php) | ~260 | The gateway wrapper. 3 API calls + iframe URL + HMAC verify. **No DB, no business logic.** | ✅ Pure — port as-is |
| [`app/Services/Paymob/PaymobPaymentInitiator.php`](../../app/Services/Paymob/PaymobPaymentInitiator.php) | ~240 | Glue: lock → reuse-check → client → create `initiated` Payment → allocate | ⚠️ Rewrite around your Payment model, keep every rule |
| [`app/Http/Controllers/Paymob/CallbackController.php`](../../app/Http/Controllers/Paymob/CallbackController.php) | ~146 | `processed()` = S2S webhook (the truth). `returned()` = browser bounce (UX only) | ⚠️ Keep the logic, swap the framework |
| [`config/integrations.php`](../../config/integrations.php) | — | The 8 `paymob.*` config keys + why each exists | ✅ |

### Mobile API surface

| File | Role |
|---|---|
| [`app/Http/Controllers/Api/V1/Tenant/InitiatePaymobSessionController.php`](../../app/Http/Controllers/Api/V1/Tenant/InitiatePaymobSessionController.php) | The one endpoint the app calls. All the guards + the error contract |
| [`app/Http/Resources/Api/V1/PaymobSessionResource.php`](../../app/Http/Resources/Api/V1/PaymobSessionResource.php) | The wire shape. **Every field explicitly cast** — see [§16.6](#166-untyped-json-broke-the-flutter-client) |
| [`app/Http/Controllers/Api/V1/Tenant/DemoPayInvoiceController.php`](../../app/Http/Controllers/Api/V1/Tenant/DemoPayInvoiceController.php) | `pay-demo` — simulate a capture while the gateway is off |
| [`app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php`](../../app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php) | Runs the *real* capture path with no gateway call |
| [`routes/api.php`](../../routes/api.php) (lines ~96–113) | Route + throttle registration |

### Public payment link

| File | Role |
|---|---|
| [`app/Http/Controllers/PaymentLinkController.php`](../../app/Http/Controllers/PaymentLinkController.php) | `show` / `start` / `status` — the no-login flow |
| [`resources/views/pay/show.blade.php`](../../resources/views/pay/show.blade.php), `pay/status.blade.php` | Public pages (EN + AR/RTL) |
| [`app/Models/Invoice.php`](../../app/Models/Invoice.php) — `paymentLinkToken()`, `rotatePaymentLinkToken()`, `paymentLinkUrl()`, `paymentLinkQrSvg()`, `isPayable()` | Token lifecycle + QR |
| [`app/Http/Middleware/SecurityHeaders.php`](../../app/Http/Middleware/SecurityHeaders.php) | Strict CSP scoped to `/pay/*` |

### Money model (the part that must not break)

| File | Role |
|---|---|
| [`app/Models/Payment.php`](../../app/Models/Payment.php) | `RECEIVED_STATUSES`, `refitAllocationsToBalance()`, the `saved` hook, capture immutability |
| [`app/Models/Invoice.php`](../../app/Models/Invoice.php) — `recomputeTotals()` | **Single source of truth** for `paid_amount` / `balance` |
| [`app/Services/Accounting/Journalizers/PaymentJournalizer.php`](../../app/Services/Accounting/Journalizers/PaymentJournalizer.php) | Dr Bank/Cash · Cr AR · Cr Unearned (the remainder) |

### Wiring / ops

| File | Role |
|---|---|
| [`routes/web.php`](../../routes/web.php) (lines ~40–76) | `/paymob/callback`, `/paymob/return`, `/pay/*`, Apple Pay domain file |
| [`bootstrap/app.php`](../../bootstrap/app.php) | **CSRF exemption for `paymob/callback`** · trusted proxies · camelCase API middleware |
| [`app/Providers/AppServiceProvider.php`](../../app/Providers/AppServiceProvider.php) | `PaymobClient` singleton via `fromConfig()` · `URL::forceScheme('https')` |
| [`app/Support/OpsLog.php`](../../app/Support/OpsLog.php) | Redaction list — `payment_token` **must** be in it |
| [`app/Console/Commands/CheckIntegrationsCommand.php`](../../app/Console/Commands/CheckIntegrationsCommand.php) | `php artisan integrations:check --paymob` — auth-only preflight, charges nothing |

### Schema

| Migration | What it adds |
|---|---|
| [`2024_01_01_000007_create_payments_table.php`](../../database/migrations/2024_01_01_000007_create_payments_table.php) | `payments` + `invoice_payment` pivot |
| [`2026_06_27_000001_add_payment_channel_and_invoice_pay_link.php`](../../database/migrations/2026_06_27_000001_add_payment_channel_and_invoice_pay_link.php) | `payments.channel`, `invoices.payment_link_token` |
| [`2026_06_27_000002_add_receipt_notified_at_to_payments.php`](../../database/migrations/2026_06_27_000002_add_receipt_notified_at_to_payments.php) | Once-only receipt notification guard |
| [`2026_06_28_000001_index_payments_gateway_transaction_id.php`](../../database/migrations/2026_06_28_000001_index_payments_gateway_transaction_id.php) | **Index on `gateway_transaction_id`** — the webhook's lookup key |

---

## 5. Configuration

### 5a. Environment variables

```env
# Master switch. false = every Pay button hidden, session endpoint 409s,
# and the demo-pay endpoint becomes active instead.
PAYMOB_ENABLED=false

# Same host for sandbox and production — Paymob switches environment based
# on which ACCOUNT the credentials belong to, not by URL.
PAYMOB_BASE_URL=https://accept.paymob.com

# The 4 dashboard credentials (see §5c)
PAYMOB_API_KEY=
PAYMOB_INTEGRATION_ID=
PAYMOB_IFRAME_ID=
PAYMOB_HMAC_SECRET=

# Must match the integration's account currency
PAYMOB_CURRENCY=EGP

# Session concurrency lock (see §9)
PAYMOB_SESSION_LOCK_SECONDS=30        # how long the lock is held (across the gateway call)
PAYMOB_SESSION_LOCK_WAIT_SECONDS=10   # how long a second request waits to reuse

# Apple Pay is a SEPARATE Paymob integration and needs a verified domain.
# Empty = the Apple Pay button stays hidden. Card payments unaffected.
PAYMOB_APPLE_PAY_INTEGRATION_ID=
```

Any change requires `php artisan config:clear` (or your framework's equivalent)
when config is cached.

**Two switches, deliberately asymmetric.** `.env` means *"credentials are
provisioned"* — a deploy-time fact. The toggle at **/admin/settings →
Integrations** means *"collect right now"* — an operator decision, stored in the
settings table. `AppServiceProvider::applyIntegrationKillSwitches()` **ANDs**
them at boot and writes the result back into
`config('integrations.paymob.enabled')`, so the whole codebase keeps reading one
key. The UI toggle can therefore only ever **disable**: it cannot conjure
credentials, but it lets an operator stop card collection during a Paymob outage
or a fraud incident without an SSH session. It reads the settings table only when
env has already enabled the integration, and fails **open** if that table is
unreadable (mid-migration) — refusing to boot over an unreadable toggle is the
worse outcome. Tested in `tests/Feature/Regression/IntegrationKillSwitchTest.php`.

### 5b. Hard runtime requirements

| Requirement | Why | Where |
|---|---|---|
| **HTTPS on the public host** | Paymob's iframe fails with mixed-content errors on iOS over plain http; the return URL and payment links are opened from emails | `URL::forceScheme('https')` in `AppServiceProvider`, gated on `config('security.force_https')` |
| **Trusted proxies configured** | TLS terminates at the proxy, so `$request->ip()` is the *proxy's* address without it — which guts throttling and the callback's origin record | `bootstrap/app.php` → `trustProxies()` |
| **CSRF exemption for the callback** | Paymob's webhook is a server POST with no session; CSRF would reject every capture | `bootstrap/app.php` → `validateCsrfTokens(except: ['paymob/callback'])` |
| **Cache driver that supports atomic locks** | The session lock is `Cache::lock()`. `array`/`file` in a multi-process deployment gives you no lock at all | Redis or database cache in production |

### 5c. Paymob dashboard setup

| Credential | Dashboard location |
|---|---|
| `PAYMOB_API_KEY` | Account → **Profile** → API Key |
| `PAYMOB_INTEGRATION_ID` | **Developers** → Payment Integrations → your card integration → ID |
| `PAYMOB_IFRAME_ID` | **Developers** → Iframes → your iframe → ID |
| `PAYMOB_HMAC_SECRET` | Account → **Profile** → HMAC |

Then register the two callback URLs on the integration
(**Developers → Payment Integrations → your integration → Edit**):

- **Transaction processed callback** → `https://<host>/paymob/callback` *(POST, S2S, HMAC — the real one)*
- **Transaction response callback** → `https://<host>/paymob/return` *(GET, browser bounce, UX only)*

For local development, pair with an HTTPS tunnel (`ngrok http 8000` or Herd's
share) and register the tunnel URL. **Rotating the API key invalidates every
in-flight session** — only do it in a known-quiet window.

---

## 6. Data model

### `payments`

| Column | Type | Paymob-relevant meaning |
|---|---|---|
| `reference` | string unique | Your own receipt no. (`PAY-YYYYMM-NNNN`), generated in `creating` |
| `tenant_id` | FK | Customer |
| `amount` | decimal(12,2) | The invoice balance at session-open time |
| `currency` | char(3) | `EGP` |
| `method` | string | `card` for Paymob |
| `status` | string | `initiated` → `captured` \| `failed` (see below) |
| `payment_date` | date | `now()` at session open |
| `gateway` | string | `paymob` (or `demo`) |
| `channel` | string | `mobile_api` \| `portal` \| `payment_link` \| `admin` |
| `gateway_transaction_id` | string, **indexed** | `paymob:order:{id}` while initiated → `paymob:txn:{txn}:order:{order}` after capture |
| `gateway_response` | json | Session payload while initiated; the full Paymob `obj` after the callback |
| `receipt_notified_at` | timestamp | Once-only notification guard |

**Status set:** `initiated`, `authorized`, `captured`, `reconciled`, `settled`,
`failed`, `refunded`, `bounced`.
**`RECEIVED_STATUSES` = `captured` + `reconciled` + `settled`** — the single set
every AR/GL/collections consumer keys off. A `captured → reconciled → settled`
move must never un-pay an invoice.

> Atriom's original migration used a DB-level `enum` for `method`/`status`. **Do
> not copy that** — use a string column plus application-level validation, so
> adding a status doesn't need a migration.

### `invoice_payment` pivot

`invoice_id`, `payment_id`, `allocated_amount decimal(12,2)`,
unique(`invoice_id`,`payment_id`). One payment can settle several invoices.

### `invoices`

- `payment_link_token` — 48-char random, nullable, lazily minted, rotatable.
- `paid_amount` / `balance` — **only** ever written by `recomputeTotals()`.

---

## 7. The gateway wrapper — exact API calls

All four are `PaymobClient`. Base URL `https://accept.paymob.com`.

### 7a. Authenticate

```http
POST {base}/api/auth/tokens
Content-Type: application/json

{ "api_key": "<PAYMOB_API_KEY>" }
```
→ `{ "token": "<bearer>" , … }`. Throw if `token` is missing or empty.

### 7b. Create order

```http
POST {base}/api/ecommerce/orders

{
  "auth_token": "<bearer>",
  "delivery_needed": false,
  "amount_cents": 115000,                       // MAJOR × 100, integer
  "currency": "EGP",
  "merchant_order_id": "INV-2026-0042-20260805143000",
  "items": [{
    "name": "Invoice INV-2026-0042",
    "amount_cents": 115000,
    "description": "Invoice INV-2026-0042",
    "quantity": 1
  }]
}
```
→ `{ "id": 537814381, … }` — the **order_id**. Throw if it isn't an integer.

> **`merchant_order_id` must be unique per order.** Atriom appends
> `-YmdHis` to the invoice number for exactly this reason: Paymob rejects a
> duplicate, so a second attempt on the same invoice would fail if you sent the
> bare invoice number.

### 7c. Request payment key

```http
POST {base}/api/acceptance/payment_keys

{
  "auth_token": "<bearer>",
  "amount_cents": 115000,
  "expiration": 3600,                  // PaymobClient::PAYMENT_TOKEN_TTL_SECONDS
  "order_id": 537814381,
  "billing_data": {
    "first_name": "Andiamo", "last_name": "Italian",
    "email": "tenant@example.com", "phone_number": "+201001234567",
    "apartment": "NA", "floor": "NA", "street": "NA", "building": "NA",
    "shipping_method": "NA", "postal_code": "NA",
    "city": "Cairo", "country": "EG", "state": "NA"
  },
  "currency": "EGP",
  "integration_id": 5699414            // or the Apple Pay integration id
}
```
→ `{ "token": "<payment_token>" }`

> **`billing_data` fields are all mandatory to Paymob and it rejects empty
> strings** — hence the literal `"NA"` placeholders. Name is split on the first
> space with `Atriom`/`Tenant` fallbacks so a single-word customer name still
> passes.

### 7d. Iframe URL

```
{base}/api/acceptance/iframes/{PAYMOB_IFRAME_ID}?payment_token={payment_token}
```

That's the whole gateway surface. `buildPaymentSession(Invoice)` runs 7a→7c and
returns `['payment_token', 'iframe_url', 'order_id']`, refusing outright if the
invoice balance is ≤ 0.

---

## 8. HMAC verification — get this exactly right

Paymob signs the S2S callback with **HMAC-SHA512** over a **fixed, ordered
concatenation** of 20 fields from `obj`, delivered as `?hmac=` in the query
string.

**The field order (this exact order, no separators):**

```
amount_cents, created_at, currency, error_occured, has_parent_transaction,
id, integration_id, is_3d_secure, is_auth, is_capture, is_refunded,
is_standalone_payment, is_voided, order.id, owner, pending,
source_data.pan, source_data.sub_type, source_data.type, success
```

```php
$expected = hash_hmac('sha512', implode('', $fields), $hmacSecret);
return hash_equals($expected, $signature);   // constant-time — never ===
```

**Three traps, all of which produce a silent 100% verification failure:**

1. **Booleans must be the lowercase strings `"true"`/`"false"`.** PHP's
   `implode` on a real `true` yields `"1"` and on `false` yields `""` — every
   signature mismatches. `PaymobClient::boolStr()` normalises `bool`, the
   strings `"true"/"false"`, and truthy scalars to the same two words.
2. **Empty HMAC secret must fail closed.** If `hmac_secret` is unset,
   `verifyHmac()` returns `false` immediately. Never compute against `''` —
   that turns a misconfigured deploy into an unauthenticated capture endpoint.
3. **Not every POST to that URL is a charge callback.** Paymob fires other
   callbacks (e.g. with a null order id) at the same URL. They legitimately
   fail HMAC and are harmless — log the payload **shape** (keys only, never
   values) so you can tell a benign one from a real problem.

---

## 9. The initiator — sessions, reuse, and the concurrency lock

`PaymobPaymentInitiator::start(Invoice $invoice, string $channel, ?int $integrationId)`.

### 9a. The lock (non-negotiable)

```php
$lock = Cache::lock("paymob-session:{$invoice->id}:{$channel}", $lockSeconds);
$lock->block($waitSeconds);      // WAIT, don't fail — so tap #2 reuses tap #1's session
try   { return $this->startLocked(...); }
finally { $lock->release(); }
```

**Why:** `start()` is check-then-act with a *network call* in the middle. Two
requests arriving together — a double-click, two tabs, a retried POST — both
find no reusable session and both open one. Result: **two live Paymob orders
against one debt, each allocated the full balance.** If both capture, the
customer paid twice.

Design points that are easy to get wrong:
- The lock is **held across the gateway call** deliberately. Releasing before it
  reopens the exact window you're closing.
- Hence the **TTL** — a wedged request must not hold an invoice hostage.
- The second request **blocks and waits**, it does not fail. It then finds the
  first request's session inside the lock and reuses it.
- Released in `finally`, **including when the gateway throws**.
- Proven by `tests/Feature/Regression/PaymobConcurrentSessionTest.php`.

### 9b. Session reuse

Inside the lock, look for an existing session:

```
gateway = paymob
AND status = 'initiated'
AND channel = <same channel>
AND tenant_id = invoice.tenant_id
AND allocated to this invoice
AND created_at >= now() - REUSE_WINDOW_SECONDS   (2700s = 45 min)
ORDER BY id DESC
```

`REUSE_WINDOW_SECONDS = 2700` sits **below** the gateway's
`PAYMENT_TOKEN_TTL_SECONDS = 3600` by a 15-minute margin, so you never hand out
a token that expires while the user is filling the card form.

Reuse makes the endpoint **idempotent** — the mobile client needs no
double-tap protection, and repeated taps don't burn Paymob orders.

### 9c. The stale-amount discard (do not skip this)

A reusable session is **rejected** if its amount no longer equals the invoice
balance:

```php
if (round($payment->amount, 2) !== round($invoice->balance, 2)) {
    OpsLog::info('paymob.session_discarded_stale_amount', [...]);
    return null;   // fall through to a fresh session
}
```

If a credit note or a partial payment reduced the balance since the session was
opened, the gateway token is bound to the **old, higher** amount — reusing it
overcharges the customer. Log the branch: silent, it is indistinguishable from
"the reuse window expired", and the two have very different implications.

### 9d. Apple Pay never reuses

When `$integrationId` is non-null (Apple Pay has its own Paymob integration),
the initiator **always builds fresh** — otherwise a card-integration token
could be served into an Apple Pay flow.

### 9e. What gets written

```php
Payment::create([
    'tenant_id' => $invoice->tenant_id,
    'amount'    => $invoice->balance,
    'currency'  => $invoice->currency ?? 'EGP',
    'method'    => 'card',
    'status'    => 'initiated',
    'payment_date' => now(),
    'gateway'   => 'paymob',
    'channel'   => $channel,
    'gateway_transaction_id' => "paymob:order:{$orderId}",   // the webhook's lookup key
    'gateway_response' => ['order_id'=>…, 'payment_token'=>…, 'iframe_url'=>…, 'issued_at'=>…],
]);
$payment->invoices()->attach($invoice->id, ['allocated_amount' => round($invoice->balance, 2)]);
```

Allocating the full balance up front is safe **because** `recomputeTotals()`
counts only received statuses. This is what lets the webhook capture with no
extra state lookup.

---

## 10. The callback — the only source of truth

### `POST /paymob/callback` → `CallbackController::processed()`

```
1. verifyHmac(payload, request.query('hmac'))
      fail → log payload SHAPE (keys only) → 401 {"ok":false,"error":"invalid_hmac"}
2. orderId = obj.order.id ; txnId = obj.id
      no orderId → 422 {"error":"missing_order_id"}
3. payment = Payment where gateway=paymob
                     and gateway_transaction_id = "paymob:order:{orderId}"
      not found → 200 {"ok":true,"skipped":"unknown_order"}       ← see idempotency
4. payment.status in [captured, failed, refunded]
                  → 200 {"ok":true,"skipped":"already_processed"}
5. isCapture = obj.success === true AND obj.is_voided !== true
6. TRANSACTION:
      gateway_transaction_id = "paymob:txn:{txnId}:order:{orderId}"   ← promotion
      gateway_response       = obj
      if isCapture: refitAllocationsToBalance()      ← BEFORE the status flip
      status = isCapture ? 'captured' : 'failed'
      save()   → saved hook recomputes invoices + fires the receipt notification
7. 200 {"ok":true,"payment_id":…,"status":…}
```

### Idempotency by ID promotion — the elegant bit

While initiated, the row is keyed `paymob:order:{id}`. On capture it is
**promoted** to `paymob:txn:{txn}:order:{order}`. A replayed webhook therefore
misses the bare-order lookup entirely at step 3 and gets a clean
`200 unknown_order` — no double-capture, no extra dedup table, no lock.

**Always return 200 for "I don't know this order".** A 4xx/5xx makes Paymob
retry indefinitely.

### `refitAllocationsToBalance()` — the capture clamp

Card money is **already collected** when the webhook arrives, so — unlike the
manual form path, which *throws* on over-allocation — the gateway path
**accepts the payment and clamps the allocation**:

```
fittable = invoice.total
         − invoice.credit_applied_amount            (applied credit NOTES)
         − Σ TenantCreditApplication.amount         (applied on-account CREDIT)
         − Σ other RECEIVED allocations
         (and 0 outright if the invoice was cancelled after session init)
```

Any excess stays **unallocated** on the payment — the journalizer books it to
unearned revenue as a recoverable overpayment. Runs **inside** the capture
transaction and **before** the status flip, so this payment is still excluded
from the "received" sum. Rows are `lockForUpdate()`'d.

> **Every AR settlement channel must be counted here.** Omitting applied
> on-account tenant credit let a gateway capture over-settle an invoice and bury
> the excess as negative AR. If your system has *n* ways to settle a receivable,
> all *n* appear in this formula and in `recomputeTotals()`, or they will
> disagree. (Test: `PaymobGatewayCreditClampTest`.)

### `GET /paymob/return` → `CallbackController::returned()`

Paymob appends `?success=true|false&id=<txn>&order=<order_id>`. **Trust
none of it.** It is used only to pick a destination:

- Look up the payment by order id (bare ref *or* `%:order:{id}` post-promotion).
- `channel === payment_link` → redirect to the public `pay.status` page.
- Otherwise → `/portal/invoices` with a success/error flash.

---

## 11. The mobile contract

### Endpoint

```http
POST /api/v1/me/invoices/{invoice}/paymob-session
Authorization: Bearer <sanctum token>
Accept: application/json
```

Middleware: `auth:tenant-api` → `EnsureTenantActive` → `throttle:60,1`.
No request body. `{invoice}` is numeric-constrained.

### Guards, in order

| Check | Response |
|---|---|
| Unauthenticated | `401 {"message":"Unauthenticated.","statusCode":401}` |
| `PAYMOB_ENABLED=false` | `409 {"error":"paymob_disabled"}` |
| Invoice belongs to another tenant | **`404`** — not 403. Another tenant's invoice must be indistinguishable from a non-existent one, or invoice IDs are enumerable |
| Invoice `cancelled` / `credited` | `422 {"error":"invoice_not_payable","status":…}` |
| `balance <= 0` | `422 {"error":"no_balance","balance":…}` |
| Gateway threw | `502 {"error":"paymob_upstream_error"}` (the real exception is `report()`ed, never returned) |
| Over 60 req/min on the authed surface | `429` |

### Success — `200`

Responses are camelCased by middleware (`CamelCaseResponseKeys`); the internal
resource is snake_case.

```json
{
  "data": {
    "paymentToken": "ZXlKaGJHY2lPaUpJVXpVeE1pSXNJblI1Y0NJNklr…",
    "iframeUrl": "https://accept.paymob.com/api/acceptance/iframes/1049031?payment_token=…",
    "iframeId": "1049031",
    "orderId": 537814381,
    "paymentId": 193,
    "expiresAt": "2026-08-05T09:46:39+00:00",
    "reused": false
  }
}
```

| Field | Type | Use |
|---|---|---|
| `paymentToken` | string | Hand to the native Paymob SDK |
| `iframeUrl` | string | Open in a WebView (recommended for v1) |
| `iframeId` | string | Native SDK config |
| `orderId` | **int** | Paymob's order — support/logs |
| `paymentId` | **int** | Your Payment row — poll/track "processing" |
| `expiresAt` | ISO-8601 string | Token expiry (1 h from issue) |
| `reused` | **bool** | `true` = you got the cached session back |

### Client rules (all three are load-bearing)

1. **Never trust the SDK/WebView result.** Re-fetch `GET /api/v1/me/invoices/{id}`.
2. **No double-tap guard needed** — the endpoint is idempotent inside 45 min.
3. **Intercept the return URL** in the WebView (`…/paymob/return`), close the
   sheet, then refresh. `success=` in that URL is informational only.

### Reference client (Flutter)

```dart
// 1 — start the session
final res = await http.post(
  Uri.parse('$baseUrl/api/v1/me/invoices/$invoiceId/paymob-session'),
  headers: {'Authorization': 'Bearer $token', 'Accept': 'application/json'},
);
if (res.statusCode != 200) {
  final b = jsonDecode(res.body);
  throw PaymobException(res.statusCode, b['error'] ?? 'unknown', b['message'] ?? '');
}
final s = jsonDecode(res.body)['data'];

// 2 — WebView, intercepting the return URL
WebViewController()
  ..setJavaScriptMode(JavaScriptMode.unrestricted)
  ..setNavigationDelegate(NavigationDelegate(
    onNavigationRequest: (r) {
      if (r.url.contains('$returnHost/paymob/return')) {
        Navigator.of(context).pop();     // ignore ?success — not the truth
        return NavigationDecision.prevent;
      }
      return NavigationDecision.navigate;
    },
  ))
  ..loadRequest(Uri.parse(s['iframeUrl']));

// 3 — poll your backend, not Paymob
Future<bool> pollUntilPaid(int id, {int max = 10}) async {
  for (var i = 0; i < max; i++) {
    await Future.delayed(const Duration(milliseconds: 1500));
    if ((await api.showInvoice(id)).status == 'paid') return true;
  }
  return false;                          // show "processing — refresh shortly"
}
```

Map error codes to copy: `no_balance` → "already paid" · `invoice_not_payable`
→ hide Pay · `paymob_disabled` → hide Pay · `paymob_upstream_error` → "try
again later" · `401` → re-auth.

### Native SDK path

The `payment_token` works with Paymob's mobile SDKs (native card form, saved
cards, Apple/Google Pay). **Never ship the merchant API key on a device** —
that is what the server session endpoint exists to avoid. Some SDK wrappers ask
for an `apiKey` at init; the session approach means it never needs a real one.

### Demo fallback while the gateway is off

```http
POST /api/v1/me/invoices/{invoice}/pay-demo
```

Active **only** while `PAYMOB_ENABLED=false` (returns `409 use_real_payment`
otherwise). Runs the *real* capture path with no gateway call, so the invoice
flips to paid, the GL posts, and the receipt notification fires — the app can
be built and demoed end-to-end before KYC clears. Stamps `gateway = demo`.

---

## 12. Apple Pay

Apple Pay is a **separate Paymob integration** with its own `integration_id`
and a **verified domain**. Card payments are unaffected if it isn't configured.

- Set `PAYMOB_APPLE_PAY_INTEGRATION_ID`; empty keeps the button hidden.
- Serve Apple's domain-association file at
  `/.well-known/apple-developer-merchantid-domain-association` from
  `storage/app/apple-pay/domain-association` (404 until provisioned) — see
  [`routes/web.php`](../../routes/web.php).
- `PaymentLinkController::start()` picks the Apple Pay integration when
  `method=apple_pay` is posted and the id is configured.
- Apple Pay sessions are **never reused** ([§9d](#9d-apple-pay-never-reuses)).

Full walkthrough: [`docs/PAYMENT-LINK-APPLEPAY.md`](PAYMENT-LINK-APPLEPAY.md).

---

## 13. What happens after capture

The `Payment::saved` hook is the fan-out point:

1. **`recomputeAllocatedInvoices()`** → `Invoice::recomputeTotals()` →
   `paid_amount` / `balance` / auto-status (`paid` / `partially_paid`).
2. **`notifyReceiptOnce()`** → payment-received notification to the customer
   record *and* every portal user, guarded once-only by `receipt_notified_at`.
3. **GL posting** — `PaymentJournalizer`: `Dr Bank` (card ⇒ bank, not cash) ·
   `Cr AR` per property · `Cr Unearned Revenue` for any unallocated remainder.
   Allocations are bucketed by property so per-property books stay correct.

**Capture is immutable.** Once a payment is in `RECEIVED_STATUSES`, its
`amount` and `payment_date` are frozen at the model layer — correct by voiding
and re-recording, never by editing. The `initiated → captured` transition itself
is explicitly allowed (the guard reads the *original* status).

---

## 14. Observability — what gets logged

| Event | When | Carries |
|---|---|---|
| `paymob.session_started` | Every fresh session | invoice id + number, tenant, payment id, order id, amount, channel, integration id |
| `paymob.session_reused` | Cache hit | invoice, payment, order, channel |
| `paymob.session_discarded_stale_amount` | Balance moved under a session | session amount vs invoice balance |
| `paymob.request_failed` | Any non-2xx from Paymob | step, HTTP status, truncated body (240 chars) |
| *(callback)* `Paymob callback rejected: bad HMAC` | Failed verification | payload **shape only** — keys, never values |

**Never logged: `payment_token`, `iframe_url`.** A `payment_token` *authorises a
charge*. `OpsLog::REDACT` matches keys **exactly, not by substring** — `token`
in that list does **not** cover `payment_token`. Both, plus `payment_key`,
`access_token`, `auth_token`, `pan`, `card`, `cvv`, must be spelled out
individually. This is precisely how such a list leaks.

Preflight: `php artisan integrations:check --paymob` authenticates against
Paymob and charges nothing.

---

## 15. Test coverage (mirror these when porting)

| File | Proves |
|---|---|
| `tests/Feature/Services/Paymob/PaymobClientTest.php` | The 3 calls, cents conversion, iframe URL format, HMAC accept, missing-credential throw, non-2xx error |
| `tests/Feature/Services/Paymob/PaymobPaymentInitiatorTest.php` | Initiated payment + full allocation, **balance untouched until capture**, reuse, fresh session past the window |
| `tests/Feature/Regression/PaymobConcurrentSessionTest.php` | **One session when two attempts race**; never over-allocates; second request reuses; lock released — including when the gateway throws |
| `tests/Feature/Regression/PaymobSessionStaleAmountTest.php` | Fresh session cut for a reduced balance instead of reusing the stale higher one |
| `tests/Feature/Regression/PaymobGatewayCreditClampTest.php` | Capture clamped by applied tenant credit — no negative AR |
| `tests/Feature/Http/Paymob/CallbackControllerTest.php` | Valid HMAC captures; bad HMAC touches nothing; unknown order acks 200; already-captured is idempotent; `success=false` → failed; both return-URL branches |
| `tests/Feature/Scenarios/PaymobPaymentScenarioTest.php` | Voided-success → failed; `missing_order_id`; replay settles once; terminal-payment ack; credit clamp; cancelled-after-init allocates nothing; ID promotion + `obj` persisted; **only the targeted order captures**; notification fan-out; no notification on failure; demo capture |
| `tests/Feature/Api/V1/Tenant/InitiatePaymobSessionTest.php` | The endpoint's full guard/error contract |
| `tests/Feature/PaymentLink/PaymentLinkFlowTest.php` | Public flow, 404 on unknown token, Arabic/RTL, gateway-down leaves no orphan payment, channel-correct return routing, Apple Pay hidden, QR modal |
| `tests/Feature/Regression/PaymentLinkRotationTest.php` | Rotation kills the old URL on every route, doesn't strand an in-flight gateway payment, permission-gated |

**Sandbox cards:** take the current list from Paymob's own dashboard/docs —
they change. The long-standing approved test card is `5123 4567 8901 2346`,
CVV `100`, with any future expiry. Verify the declined and 3DS numbers against
Paymob before relying on them.

**A local box never receives the S2S callback** — Paymob cannot reach a `.test`
host, so the browser bounce-back lands on the status page reading *processing*
and it stays there. That is delivery, not a bug in the capture path. Either
tunnel a public URL into the dashboard's *transaction processed* hook, or post a
signed callback at yourself: build the 20 signed fields in the order
[§8](#8-hmac-verification--get-this-exactly-right) gives, HMAC-SHA512 them with
the merchant secret, and
POST to `/paymob/callback?hmac=…`. That exercises the whole real path (verify →
order lookup → row lock → refit → capture → recompute → receipt). Keep such a
helper OUT of the repo — a shipped tool that fabricates a captured payment is
the `pay-demo` footgun again ([modules/06 §8](../modules/06-payments.md)).

---

## 16. Gotchas — the expensive lessons

These are the ones that cost real debugging. Each maps to a rule above.

### 16.1 Two taps = two orders = paid twice
Session creation is check-then-act across a network call. Without the
per-invoice+channel lock ([§9a](#9a-the-lock-non-negotiable)) a double-click
opens two live Paymob orders, each allocated the full balance. **Hold the lock
across the gateway call**, make the second request *wait* (so it reuses), TTL
it, release in `finally`.

### 16.2 Reusing a session after the balance dropped overcharges
A credit note or partial payment between session-open and payment leaves the
gateway token bound to the old, higher amount. Compare amounts before reusing
([§9c](#9c-the-stale-amount-discard-do-not-skip-this)) — and **log the
discard**, or it looks identical to a normal window expiry.

### 16.3 The clamp must count *every* AR settlement channel
`refitAllocationsToBalance()` originally missed applied on-account tenant
credit. A capture then over-settled an invoice whose balance the credit had
already reduced, burying the excess as **negative AR**. Enumerate all settlement
channels — captured payments, applied credit notes, applied tenant credit — in
both the clamp and `recomputeTotals()`, or they drift.

### 16.4 HMAC booleans
PHP `implode` turns `true` into `"1"` and `false` into `""`. Paymob signs the
literal strings `"true"` / `"false"`. Get this wrong and **every** callback
fails verification — which presents as "payments never complete", with a
perfectly healthy-looking checkout.

### 16.5 A "success" redirect is not a payment
The browser/SDK returns before the webhook lands. Any client that marks an
invoice paid off the redirect will show paid invoices that aren't. Re-fetch,
or poll.

### 16.6 Untyped JSON broke the Flutter client
The session array is untyped, so the OpenAPI generator inferred `string` for
every field — telling the Dart client to decode `orderId`/`paymentId` (ints)
and `reused` (bool) as `String`. That **throws on decode and kills the entire
card-payment response.** `PaymobSessionResource` now casts every field
explicitly, pinning the runtime type and the published contract to the same
thing. Any typed mobile client needs the same discipline.

### 16.7 CSRF eats the webhook
Paymob's POST carries no session or CSRF token. Exempt the callback route
explicitly. Symptom: captures never happen and your logs show nothing, because
the request dies in middleware.

### 16.8 Cross-tenant must 404, not 403
`403` confirms the invoice exists — that's ID enumeration. Return `404`.

### 16.9 Multi-process locks need a real cache driver
`Cache::lock()` on the `array` or `file` driver gives you no cross-process lock
in production. Redis or database.

### 16.10 An unindexed lookup key
The webhook finds the payment by `gateway_transaction_id`. Index it.

### 16.11 An abandoned `initiated` payment is *not* cleaned up
There is **no expiry job** — an abandoned session stays `initiated` forever.
That is harmless (it has zero AR/GL effect) and intentional, but do not
document it as "expires after 24 hours", and don't build a report that counts
`initiated` rows as anything meaningful. What governs re-issuing is the
45-minute **reuse window**, not any expiry.

### 16.12 `merchant_order_id` must be unique
Paymob rejects duplicates. Suffix a timestamp, or a customer's second attempt
on the same invoice fails at order creation.

---

## 17. Port checklist for a different system

Framework-agnostic. Tick these in order.

**Config & infrastructure**
- [ ] 8 config keys: enabled, base URL, api key, integration id, iframe id, HMAC secret, currency, lock seconds (+ Apple Pay integration id if wanted)
- [ ] HTTPS enforced on generated absolute URLs
- [ ] Trusted-proxy / real-client-IP handling
- [ ] CSRF (or equivalent) exemption on the callback route
- [ ] Distributed-lock-capable cache (Redis)
- [ ] Secret redaction list includes `payment_token`, `payment_key`, `api_key`, `hmac`, `pan`, `cvv` — **exact key matching, spell out every variant**

**Schema**
- [ ] `payments`: amount, currency, method, **status**, date, gateway, **channel**, `gateway_transaction_id` (**indexed**), `gateway_response` (json), once-only notify stamp
- [ ] payment↔invoice allocation pivot with `allocated_amount`
- [ ] `invoices.payment_link_token` if you want the public link
- [ ] Define `RECEIVED_STATUSES` in one place; every AR/GL query keys off it

**Gateway wrapper** (no DB, no business logic)
- [ ] `authenticate()` → bearer
- [ ] `createOrder()` → int order id; amount × 100; **unique `merchant_order_id`**
- [ ] `requestPaymentKey()` → token; full `billing_data` with `"NA"` placeholders; `expiration = 3600`; overridable `integration_id`
- [ ] `iframeUrl()`
- [ ] `verifyHmac()` — 20 fields in the exact order, `"true"`/`"false"` strings, `hash_equals`, **fail closed on an empty secret**
- [ ] Non-2xx → log step + status + truncated body, then throw

**Initiator**
- [ ] Lock on `(invoice, channel)`, held across the gateway call, TTL'd, **wait** not fail, released in `finally`
- [ ] Reuse check **inside** the lock: same invoice + channel + tenant, `initiated`, within the reuse window (< token TTL)
- [ ] **Discard a reusable session whose amount ≠ current balance** — and log it
- [ ] Never reuse for a non-default integration (Apple Pay)
- [ ] Create `initiated` payment keyed `paymob:order:{id}`; allocate full balance; store the session in `gateway_response`
- [ ] Refuse a zero/negative balance
- [ ] Log `session_started` **without** the token or iframe URL

**Callback**
- [ ] Verify HMAC first; on failure log **shape only** and return 401
- [ ] Missing order id → 422
- [ ] Unknown order → **200** (never let Paymob retry forever)
- [ ] Already terminal → 200, change nothing
- [ ] `capture = success && !is_voided`
- [ ] In one transaction: promote the id → store `obj` → **clamp allocations** → flip status
- [ ] Clamp counts every settlement channel; excess stays unallocated
- [ ] Post-capture hooks: recompute totals, notify once, post to the ledger

**Return URL**
- [ ] Trust nothing in the query string; use it only to choose a destination
- [ ] Route by channel (public link vs authenticated app)

**Client / mobile**
- [ ] One authenticated endpoint; explicit types on every response field
- [ ] Error codes are stable strings the client can switch on
- [ ] Cross-tenant → 404
- [ ] Client re-fetches/polls; never trusts the redirect or SDK result
- [ ] Client intercepts the return URL to close the WebView
- [ ] No API key or HMAC secret anywhere on the device

**Tests** — port at minimum: the concurrency race, the stale-amount discard, the
clamp, HMAC accept/reject, webhook replay, voided-success-is-failure, and
"balance untouched until capture".

---

## 18. Known drift in this repo

All three were found while writing this doc and are now **fixed** — recorded
here because the first is a design lesson worth carrying into any port.

1. **The admin Settings toggle for Paymob did nothing (fixed 2026-08-05).**
   `app/Filament/Admin/Pages/Settings.php` wrote
   `IntegrationsSettings::$paymob_enabled` into the settings table, and every gate
   in the codebase read `config('integrations.paymob.enabled')` — env only.
   Nothing bridged the two. An operator who switched Paymob off in the UI saw
   "Saved", and the mall carried on taking cards. **A kill switch that silently
   does nothing is worse than no kill switch**, because the operator stops looking
   for another way to stop it. Now ANDed at boot — see
   [§5a](#5a-environment-variables). Same bug had the same fix on
   `whatsapp_enabled`. **If you port a two-source feature flag, make one source
   authoritative and prove it with a test that drives the real endpoint**, not
   just the config value.
2. **The retired root-level `INFRA.md` named `INTEGRATIONS_PAYMOB_ENABLED`** — that variable never
   existed. The real one is `PAYMOB_ENABLED`.
3. Older docs said the mobile session endpoint is throttled at 5/min; it inherits
   the authenticated surface's **60/min**. Corrected in this doc, `PAYMOB-SETUP.md`,
   `PAYMOB-FLUTTER.md` and `docs/api/MOBILE-API.md`.

---

## 19. Related documents

| Doc | For |
|---|---|
| [`PAYMOB-SETUP.md`](PAYMOB-SETUP.md) | Operator: dashboard walkthrough, credentials, smoke test, go-live |
| [`PAYMOB-FLUTTER.md`](PAYMOB-FLUTTER.md) | Mobile dev: Flutter code, WebView vs SDK, polling |
| [`docs/modules/06-payments.md`](../modules/06-payments.md) | The payments module: AR math, allocation, late fees, state machine |
| [`docs/modules/20-mobile-api.md`](../modules/20-mobile-api.md) · [`docs/api/MOBILE-API.md`](../api/MOBILE-API.md) | The full mobile API contract |
| [`docs/PAYMENT-LINK-APPLEPAY.md`](PAYMENT-LINK-APPLEPAY.md) | Apple Pay + domain verification |
| [`docs/modules/21-general-ledger.md`](../modules/21-general-ledger.md) | How a capture reaches the books |
