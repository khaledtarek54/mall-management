# 06 — Payments, allocation & online pay (Paymob)

> **Audience:** the finance/operations team **and** new engineers. This is the
> exhaustive reference for how money *arrives* in Atriom: how a payment is
> recorded, how it is split (allocated) across one or more invoices, the
> over-allocation backstop, the four initiation channels, and the full Paymob
> online-pay flow (session → iframe → server-to-server callback → capture),
> including HMAC verification and replay/tamper handling.
>
> It documents the **current** behaviour, including this session's money-path
> hardening (proration, CAM positive/negative true-ups, credit-note
> locking/reversal/auto-apply, the billing run-lock, the cross-tenant guard, and
> the concurrency backstop). Every claim cites the code so you can verify it.
>
> Sibling business-rule reference: `docs/modules/06-payments.md`. Read
> `docs/OVERVIEW.md` first for the domain (Eltizam = operator, Jawad = owner,
> tenants = retailers).

---

## Plain-language summary

A **payment** is one inbound transaction from a tenant — a card charge, a bank
transfer, an InstaPay/wallet transfer, cash, or a cheque. It belongs to exactly
one tenant and has one **amount** in EGP.

That single payment can settle **one invoice or several** at once. The split is
recorded as **allocations** (rows in the `invoice_payment` pivot, each with its
own `allocated_amount`). Example: a tenant sends one EGP 60,000 bank transfer
that you spread as 45,000 against the oldest rent invoice and 15,000 against a
CAM invoice — that is **one** Payment with **two** allocations.

A payment only moves the books when its status is **`captured`**. An online card
payment is born **`initiated`** (the gateway session is open but nothing has
been charged yet) and only becomes `captured` when Paymob's server-to-server
webhook confirms a successful charge. Until then the allocation exists but is
invisible to the invoice's outstanding balance.

The invoice's outstanding balance is **never** edited directly. It is always
recomputed from the captured allocations (plus any applied credit notes) by a
single method, `Invoice::recomputeTotals()`. So "how much does this tenant still
owe?" is always a derived number, never a stored guess.

For online payments there are four ways a payment can start (the **channel**):
the tenant **portal**, the **mobile app** (`mobile_api`), a public **payment
link** (a no-login `/pay/{token}` URL you can WhatsApp to a tenant), or an
**admin**-recorded payment (a finance user keying in a cash/transfer/cheque
receipt). Card payments all go through Paymob; the others are recorded by hand.

The two guard-rails that keep the money honest: (1) you can never allocate more
than the invoice can absorb (a per-row form cap **and** a database-locked
backstop that catches two payments racing each other), and (2) a tenant can only
ever pay/see their own invoices (cross-tenant allocation is blocked at the model
layer, and the API returns 404 — not 403 — so you can't even discover that
another tenant's invoice exists).

---

## The exact rule / formula

### 1. What a Payment is

`payments` table — `database/migrations/2024_01_01_000007_create_payments_table.php`:

| Column | Type | Notes |
|---|---|---|
| `reference` | string, **unique** | `PAY-YYYYMM-NNNN`, auto-generated at save (see below). |
| `tenant_id` | FK → tenants, `restrictOnDelete` | A payment **must** belong to a tenant; the tenant can't be deleted while payments exist. |
| `amount` | `decimal(12,2)` | EGP major units. Cast `decimal:2` (`Payment.php:54`). |
| `currency` | string(3), default `EGP` | Forced to `EGP` on create if blank (`Payment.php:203-205`). |
| `method` | enum | `card, bank_transfer, instapay, wallet, cash, cheque, other`. |
| `status` | enum, default `captured` | `initiated, authorized, captured, reconciled, settled, failed, refunded, bounced`. **Only `captured` counts toward AR.** |
| `payment_date` | date | Cast `date`. |
| `gateway` | string, nullable | PSP name: `paymob`, `demo`, or null for manual. |
| `channel` | string, nullable, indexed | `mobile_api`, `portal`, `payment_link`, `admin` (added `2026_06_27_000001_add_payment_channel_and_invoice_pay_link.php`). |
| `gateway_transaction_id` | string, nullable, indexed | Keyed lookup target for the callback (index `2026_06_28_000001`). |
| `gateway_response` | json, nullable | Cast `array`. Raw gateway payload / session data. |
| `cheque_number`, `cheque_clearance_date` | | Cheque tracking. |
| `received_by` | FK → users, `nullOnDelete` | Which admin keyed it. |
| `receipt_notified_at` | datetime, nullable | Idempotency stamp for the "payment received" tenant notification (`2026_06_27_000002`). |

Channel constants — `Payment.php:27-30`:

```php
public const CHANNEL_MOBILE = 'mobile_api';
public const CHANNEL_PORTAL = 'portal';
public const CHANNEL_LINK   = 'payment_link';
public const CHANNEL_ADMIN  = 'admin';
```

### 2. The reference number

`Payment::generateReference()` (`Payment.php:105-120`): `PAY-` + `now()->format('Ym')`
+ a zero-padded 4-digit running sequence (`%04d`) scoped to that year-month,
computed by reading the highest existing reference for the prefix
**including soft-deleted rows** (`withTrashed()`).

`generateUniqueReference()` (`Payment.php:230-247`) wraps it: it re-checks
existence and increments on collision (up to 100 attempts, then throws). It is
**always (re)generated at save time** in the `creating` hook (`Payment.php:198-206`),
deliberately ignoring any stale value cached in the form state.

### 3. Allocation (the split)

The relationship is many-to-many through `invoice_payment`
(`Payment::invoices()` `Payment.php:93-98`; `Invoice::payments()`
`Invoice.php:84-89`), each pivot row carrying `allocated_amount decimal(12,2)`,
with a **unique `(invoice_id, payment_id)`** constraint (one allocation row per
invoice-payment pair) and `cascadeOnDelete` from both sides.

**The invariant — only captured allocations settle AR.** `Invoice::recomputeTotals()`
(`Invoice.php:255-283`) is the single source of truth:

```
paid_amount = SUM(invoice_payment.allocated_amount  WHERE payments.status = 'captured')
            + credit_applied_amount               (applied credit notes settle AR too)
balance     = max(0, total − paid_amount)          (rounded to 2 dp, floored at 0)
```

Both sums are rounded to 2 decimals. Status is then auto-derived **unless** the
invoice is in a manual-override state (`cancelled`, `credited`, `disputed` are
left untouched — `Invoice.php:270`):

- `balance ≤ 0 && paid_amount > 0` → `paid`
- `paid_amount > 0` (but balance remains) → `partially_paid`
- else if `due_date` is past → `overdue`
- else → `issued`

It persists via `saveQuietly()` (`Invoice.php:282`) — no model events, so it
never recurses into itself.

**Form-level allocation rules** (admin Create/Edit payment — `PaymentForm.php`):

- **Total cap:** the sum of allocations may not exceed the payment `amount`
  (`guardAllocationsTotal()`, `CreatePayment.php:26-47` / `EditPayment.php:53-74`).
  Over-allocating halts the save with a danger notification.
- **Per-row cap:** each allocation row may not exceed
  `round(invoice.balance + thisRowsExistingAllocation, 2)` (a `+0.005` epsilon
  for float slop) — `PaymentForm.php:153-183`. On Edit, the row's own existing
  allocation is **added back** so a re-save of an unchanged row isn't rejected
  (the balance already nets it out).
- **Auto-suggest:** picking a tenant or typing an amount auto-fills the
  repeater with that tenant's **oldest-due** open invoices, distributing the
  amount oldest-first, but **only when the repeater is empty** so it never
  clobbers manual edits (`suggestAllocations()` `PaymentForm.php:244-290`).
  The invoice picker lists only invoices of the chosen tenant with
  `balance > 0`, property-scoped via `TenantScope::visibleAssetIds()`
  (`PaymentForm.php:92-108`).

### 4. The over-allocation backstop (lock + assert)

`Payment::assertInvoicesNotOverAllocated(array $invoiceIds)` (`Payment.php:143-165`)
is the **concurrency-safe** final guard. It must be called **inside the same DB
transaction** that syncs the pivot, **after** `recomputeAllocatedInvoices()`.
For each invoice id it:

1. `Invoice::whereIn('id', $invoiceIds)->lockForUpdate()->get()` — takes a row
   lock (`SELECT … FOR UPDATE`) on each invoice, serialising any parallel
   payment save touching the same invoice.
2. Recomputes `allocated = round(SUM(captured allocations) + credit_applied_amount, 2)`.
3. Throws `\DomainException` (message key `admin.payment.allocation_exceeds_balance`)
   if `allocated > round(invoice.total, 2) + 0.01` (a 1-piastre tolerance).

Because the lock serialises writers, two captures that each fit the balance
*alone* but together exceed the invoice total are caught: the **second** one to
commit sees the first's allocation already counted and rolls back. The
form/API caps handle the common (single-request) case; this is the **race
backstop** only.

`Payment::assertInvoicesShareTenant(array $invoiceIds)` (`Payment.php:179-194`)
is the companion guard: it throws `\DomainException`
(`admin.payment.cross_tenant_allocation`) if any allocated invoice belongs to a
**different tenant** than the payment. The form's tenant filter prevents this in
normal use, but a stale repeater row or an API client could bypass the UI, so
the model enforces it (audit M06 F-26 / D-19).

### 5. Status-change → recompute hooks

`Payment::booted()` (`Payment.php:196-223`):

- **`saved`** → `recomputeAllocatedInvoices()` (recompute every allocated
  invoice — so a `captured ↔ failed` flip rolls forward to the invoice balance)
  **then** `notifyReceiptOnce()`.
- **`deleted`** → `recomputeAllocatedInvoices()` (so deleting/voiding a payment
  releases the invoices back to outstanding).

`recomputeAllocatedInvoices()` (`Payment.php:126-129`) simply calls
`recomputeTotals()` on each allocated invoice.

**Receipt notification idempotency** — `notifyReceiptOnce()` (`Payment.php:66-86`):
fires `PaymentReceivedNotification` to the tenant portal **exactly once**, only
when `status === 'captured'` **and** there is at least one allocated invoice,
guarded by the `receipt_notified_at` stamp (set via `saveQuietly()` so it
doesn't re-trigger hooks). It is called from both the `saved` hook (gateway
path: allocations precede the capture flip) **and** the admin Create/Edit
after-hooks (allocations follow the save) — the stamp guarantees one delivery
regardless of order.

### 6. Channels — who initiates a payment and how

| Channel | Constant | Entry point | How the payment is created |
|---|---|---|---|
| **Mobile app** | `mobile_api` | `POST /api/v1/me/invoices/{invoice}/paymob-session` (`InitiatePaymobSessionController`) | `PaymobPaymentInitiator::start($invoice)` — default channel `mobile_api`. |
| **Portal** | `portal` | "Pay Now" action (portal invoice table + `ViewInvoice`) | `PaymobPaymentInitiator::start($record, CHANNEL_PORTAL)`. |
| **Payment link** | `payment_link` | Public `POST /pay/{token}/start` (`PaymentLinkController::start`) | `$initiator->start($invoice, CHANNEL_LINK, $integrationId)`. |
| **Admin** | `admin` | Filament Payments → Create/Edit (`CreatePayment`/`EditPayment`) | Manually keyed; allocations synced in the after-hooks. |

`CHANNEL_ADMIN` is the constant for hand-keyed receipts (cash/transfer/cheque).
Note: the admin payment **form** does not write the `channel` column itself —
it is on the fillable list for completeness; admin receipts are identified by
having no `gateway`/`channel` set rather than by a literal `admin` tag. The
three online channels (`mobile_api`, `portal`, `payment_link`) are stamped by
`PaymobPaymentInitiator::start()`.

### 7. The Paymob online-pay flow (session → iframe → callback → capture)

Paymob's classic 3-step "iframe" flow, wrapped by `PaymobClient`
(`app/Services/Paymob/PaymobClient.php`):

1. **`authenticate()`** (`PaymobClient.php:65-79`) → `POST {base}/api/auth/tokens`
   with `api_key` → returns a bearer token. Throws if no token comes back.
2. **`createOrder($bearer, $amount, $invoice)`** (`PaymobClient.php:85-111`) →
   `POST {base}/api/ecommerce/orders`. **Amount is converted to piastres**:
   `amount_cents = (int) round($amount * 100)`. `merchant_order_id` =
   `{invoice.number}-{YmdHis}` (unique per attempt). Returns the numeric
   `order_id`.
3. **`requestPaymentKey($bearer, $orderId, $amount, $billing, $integrationId)`**
   (`PaymobClient.php:116-150`) → `POST {base}/api/acceptance/payment_keys` with
   `expiration = 3600` (`PAYMENT_TOKEN_TTL_SECONDS`, `PaymobClient.php:34`), the
   `order_id`, billing data (tenant name split into first/last; safe placeholder
   address fields), and `integration_id` (the supplied one for Apple Pay, else
   the default card integration). Returns the `payment_token`.
4. **`iframeUrl($token)`** (`PaymobClient.php:152-155`) →
   `{base}/api/acceptance/iframes/{iframe_id}?payment_token={token}`.

`buildPaymentSession($invoice, $integrationId)` (`PaymobClient.php:161-181`) runs
all four in order. It charges the **current invoice balance**
(`$amount = (float) $invoice->balance`) and **throws** if the balance ≤ 0.
Config comes from `config/integrations.php` → `integrations.paymob.*`:
`api_key`, `integration_id`, `iframe_id`, `hmac_secret`, `base_url`
(default `https://accept.paymob.com`), `currency` (default `EGP`),
`apple_pay_integration_id`, and the master `enabled` toggle
(`env('PAYMOB_ENABLED', false)`). `PaymobClient::fromConfig()`
(`PaymobClient.php:44-63`) throws if `api_key`, `integration_id`, or `iframe_id`
is missing.

**`PaymobPaymentInitiator::start()`** (`PaymobPaymentInitiator.php:54-100`) is
the glue between callers and the client:

- **Session reuse (idempotency):** for the card flow (`$integrationId === null`)
  it first looks for a reusable `initiated` session via `findReusableSession()`
  (`PaymobPaymentInitiator.php:117-158`). A session is reused only if **all** of:
  same `gateway = paymob`, `status = initiated`, **same channel**, same tenant,
  same invoice, created **within `REUSE_WINDOW_SECONDS = 2700`** (45 min — below
  Paymob's 3600s token TTL by a safety margin), **and** its stored `amount`
  still equals the current `invoice.balance` (rounded to 2 dp). The
  amount-match check is the **stale-amount fix**: if a credit or partial payment
  reduced the balance after the session was issued, the gateway token is bound
  to the *old, higher* amount — reusing it would overcharge, so it falls through
  to a fresh session. Apple Pay (a non-null integration) **always** builds fresh
  so a card token is never served for it.
- **Otherwise** it calls `buildPaymentSession()`, then in a **DB transaction**
  (`PaymobPaymentInitiator.php:64-99`) creates a `Payment` with
  `status = 'initiated'`, `method = 'card'`, `gateway = 'paymob'`, the channel,
  `amount = invoice.balance`, and `gateway_transaction_id = orderRef(order_id)`
  = `"paymob:order:{orderId}"` (`PaymobPaymentInitiator.php:107-110`). The
  `gateway_response` stashes `order_id`, `payment_token`, `iframe_url`, and
  `issued_at`.
- It **immediately allocates the full invoice balance** to that initiated
  payment (`->attach($invoice->id, ['allocated_amount' => round(balance, 2)])`,
  `PaymobPaymentInitiator.php:87-89`). This has **zero** effect on AR because
  `recomputeTotals()` only counts `captured` allocations — the allocation just
  pre-stages the link so the callback has nothing left to do but flip status.
- Returns `payment_token`, `iframe_url`, `order_id`, `payment_id` (our row id, a
  poll target), `expires_at` (`now + 3600s`), and `reused`.

The mobile API serialises this via `PaymobSessionResource`
(`PaymobSessionResource.php:21-32`), which also returns `iframe_id`. The portal
and payment-link actions redirect the browser straight to `iframe_url`
(`redirect()->away(...)`).

### 8. The server-to-server callback (capture) + HMAC

Paymob fires two things after the iframe:

- **`POST /paymob/callback` → `CallbackController::processed()`** — the
  authoritative, HMAC-verified server-to-server "transaction processed"
  webhook. **CSRF-exempt** (`bootstrap/app.php` `validateCsrfTokens(except:
  ['paymob/callback'])`, `routes/web.php:29-30`).
- **`GET /paymob/return` → `CallbackController::returned()`** — the browser
  bounce-back. **Trusted for nothing**; it only routes the user to the right
  result page (`CallbackController.php:102-134`). For a `payment_link` payment it
  redirects to the public `pay.status` page using the invoice's
  `payment_link_token`; everything else lands on `/portal/invoices` with a
  success/error flash.

**`processed()` — exact sequence** (`CallbackController.php:32-100`):

1. Read `hmac` from the query string and the full body (`$request->all()`).
2. **`PaymobClient::verifyHmac($payload, $signature)`** (`PaymobClient.php:187-227`).
   The spec: take `$callback['obj']`, concatenate these 20 fields **in this
   fixed lexicographic order** —
   `amount_cents, created_at, currency, error_occured, has_parent_transaction,
   id, integration_id, is_3d_secure, is_auth, is_capture, is_refunded,
   is_standalone_payment, is_voided, order.id, owner, pending, source_data.pan,
   source_data.sub_type, source_data.type, success` — booleans normalised to the
   strings `"true"`/`"false"` via `boolStr()` (handles real bools and the
   string `"true"`; `PaymobClient.php:229-239`). Compute
   `hash_hmac('sha512', implode('', $fields), hmac_secret)` and compare with
   **`hash_equals()`** (constant-time, no timing leak). If `hmac_secret` is
   unset it returns **false** (fail-closed). **Tamper handling:** any altered
   field (e.g. `success` flipped, `amount_cents` bumped) changes the digest, so
   verification fails.
3. **On bad HMAC** → log the payload **shape only** (keys, not values/PII) via
   `OpsLog::warning` and return **`401 {ok:false, error:invalid_hmac}`**
   (`CallbackController.php:37-52`). (Paymob legitimately fires non-charge
   callbacks here — e.g. with a null order id — which fail HMAC harmlessly.)
4. Extract `order_id = obj.order.id` and `txn_id = obj.id`. **No order id** →
   **`422 missing_order_id`** (`CallbackController.php:55-60`).
5. Recover our payment by `gateway = paymob` **and**
   `gateway_transaction_id = orderRef(order_id)` (`CallbackController.php:62-64`).
   - **Not found** → log info and return **`200 {ok:true, skipped:unknown_order}`**
     (`CallbackController.php:66-76`). 200 (not an error) so Paymob stops
     retrying — this covers a retry that arrives *after* we already promoted the
     `gateway_transaction_id` (see step 7) and an unknown order.
6. **Replay / already-processed guard:** if the payment is already in a terminal
   state (`captured`, `failed`, **or** `refunded`) → return **`200 {ok:true,
   skipped:already_processed}`** without touching anything
   (`CallbackController.php:78-80`). This makes the callback **idempotent** —
   Paymob's at-least-once retries can't double-capture.
7. **Decide outcome:** `isCapture = success && !is_voided`
   (`CallbackController.php:82-84`). In a **DB transaction**
   (`CallbackController.php:86-93`): rewrite
   `gateway_transaction_id = "paymob:txn:{txn}:order:{order}"` (so the order-keyed
   lookup in step 5 no longer matches → future retries take the
   `unknown_order` 200 path), store the raw `obj` in `gateway_response`, and set
   `status = isCapture ? 'captured' : 'failed'`, then `save()`. The
   `Payment::saved` hook then **recomputes the invoice** (flipping it to `paid`
   since the full balance was pre-allocated) and **fires the receipt
   notification** exactly once.
8. Return **`200 {ok:true, payment_id, status}`**.

**Demo path** — when `PAYMOB_ENABLED=false`, `RecordDemoPaymentAction`
(`app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php`) mirrors the real
path **byte-for-byte minus the gateway call**: inside a transaction it
**`lockForUpdate()`** the invoice, re-checks `balance > 0` (aborts 422
otherwise), creates an `initiated` `gateway = 'demo'` payment, allocates the
full balance, flips to `captured` (which recomputes + notifies), then runs the
`assertInvoicesNotOverAllocated` backstop. This keeps balances, AR ageing, and
notifications identical to a real capture.

### 9. The public payment link

`Invoice::paymentLinkToken()` (`Invoice.php:97-104`) lazily generates a 48-char
random token (`Str::random(48)`), persisted in `invoices.payment_link_token`
(unique, nullable — `2026_06_27_000001`). New invoices get one pre-generated in
the `creating` hook (`Invoice.php:215-217`). `PaymentLinkController`
(`app/Http/Controllers/PaymentLinkController.php`):

- **`GET /pay/{token}` (`show`)** — resolves the invoice by token or **404**
  (no enumeration); if the invoice **isn't payable** it redirects to the status
  page; otherwise renders the pay view with `paymentEnabled` and
  `applePayEnabled` flags.
- **`POST /pay/{token}/start` (`start`)** — bails to the status page if Paymob is
  disabled or the invoice isn't payable; chooses the Apple Pay integration when
  `method=apple_pay` **and** `apple_pay_integration_id` is configured, else
  card; calls `start($invoice, CHANNEL_LINK, $integrationId)`; on any failure
  logs and redirects back with an error; on success `redirect()->away(iframe_url)`.
- **`GET /pay/{token}/status` (`status`)** — public result page. State machine
  (`PaymentLinkController.php:98-103`): `balance ≤ 0` → **paid**; else latest
  link payment `failed` → **failed**; `initiated` → **processing**; else →
  **unpaid**. The displayed amount is **this link's payment amount** (what the
  client transacted on this link), not the full invoice total — so a
  partially-pre-paid invoice shows the right figure.

`isPayable()` (`Invoice.php:127-131`): not `cancelled`/`credited`, and
`round(balance, 2) > 0`. All `/pay/*` routes are throttled `30,1`
(`routes/web.php:42`); the public visitor's locale comes from `?lang` or the
`Accept-Language` header (`PaymentLinkController.php:32-40`).

---

## Worked example — a split payment with real numbers

**Scenario.** Tenant *"Cilantro Café"* (`tenant_id = 7`) owes on two open
invoices:

| Invoice | Type | Total | Already paid | Balance |
|---|---|---|---|---|
| `INV-AW-202606-0012` | June base rent | EGP 50,000.00 | 0 | **50,000.00** |
| `INV-AW-202606-0019` | June CAM | EGP 18,000.00 | 0 | **18,000.00** |

Cilantro sends **one** bank transfer of **EGP 60,000.00**. A finance user keys
it in via **Admin → Payments → Create**.

**Step 1 — payment header.** Tenant = Cilantro (id 7), amount = 60,000.00,
method = `bank_transfer`, status = `captured`, date = today. On selecting the
tenant/typing the amount the form **auto-suggests** allocations oldest-due-first
(`suggestAllocations`): 50,000 → INV-0012, then 10,000 of the remaining → INV-0019.

**Step 2 — finance adjusts the split.** Suppose they instead choose **45,000 →
INV-0012** and **15,000 → INV-0019** (total 60,000). Validation:

- Per-row cap (`PaymentForm.php:153-183`): 45,000 ≤ balance 50,000 ✓;
  15,000 ≤ balance 18,000 ✓.
- Total cap (`guardAllocationsTotal`): 45,000 + 15,000 = 60,000 ≤ amount
  60,000 ✓. The live tally shows **Unallocated: EGP 0.00** in green
  (`PaymentForm.php:187-209`).

**Step 3 — save.** `CreatePayment::afterCreate()`
(`CreatePayment.php:49-87`): builds the sync map `{12 => 45000, 19 => 15000}`,
runs `assertInvoicesShareTenant([12,19])` (both belong to tenant 7 ✓), then in a
transaction `sync()`s the pivot, `recomputeAllocatedInvoices()`, and
`assertInvoicesNotOverAllocated([12,19])`. Reference auto-generated, e.g.
`PAY-202606-0007`.

**Step 4 — invoices recompute** (`Invoice::recomputeTotals()`):

| Invoice | total | captured allocation | + credit | paid_amount | balance | status |
|---|---|---|---|---|---|---|
| INV-0012 | 50,000.00 | 45,000.00 | 0 | 45,000.00 | **5,000.00** | `partially_paid` |
| INV-0019 | 18,000.00 | 15,000.00 | 0 | 15,000.00 | **3,000.00** | `partially_paid` |

One payment, EGP 60,000.00, settled two invoices and left each
`partially_paid` with the right residual. The tenant gets **one**
`PaymentReceivedNotification` (idempotent via `receipt_notified_at`).

**Step 5 — the backstop in action (concurrency).** Imagine a *second* payment of
EGP 6,000 trying to also allocate against INV-0012 at the same instant. INV-0012
can only absorb 5,000 more. Both requests' form caps read balance 5,000 at form
time, but inside `assertInvoicesNotOverAllocated` the first to commit holds the
`lockForUpdate` on INV-0012; the second blocks, then re-reads
`SUM(captured) = 45,000 + 6,000 = 51,000 > 50,000 + 0.01` → throws
`\DomainException`, and its whole transaction (sync + recompute) **rolls back**.
On Create, the orphan payment row is then deleted
(`CreatePayment.php:74-76`); the user sees the "allocation exceeds balance"
notification.

**Online variant.** Had Cilantro instead tapped **Pay Now** on INV-0012 in the
portal, `PaymobPaymentInitiator::start($invoice, CHANNEL_PORTAL)` would create
an `initiated` payment for **50,000.00** (the full balance), allocate it (no AR
effect yet), and redirect to the iframe. On the successful S2S callback the
payment flips to `captured`, `recomputeTotals` sets INV-0012 to `paid`
(balance 0), and the receipt fires — all without anyone keying a number.

---

## Every edge case + how the system handles it

| Edge case | Handling | Where |
|---|---|---|
| **Allocations exceed the payment amount** | Save halts with a danger notification; nothing persists. | `guardAllocationsTotal` `CreatePayment.php:26-47` / `EditPayment.php:53-74` |
| **One row exceeds the invoice's balance** | Per-row validation rule fails (cap = balance + this row's existing allocation, +0.005 ε). | `PaymentForm.php:153-183` |
| **Two payments race the same invoice** | Lock + re-check serialises them; second rolls back. On Create the orphan payment is deleted. | `assertInvoicesNotOverAllocated` `Payment.php:143-165`; `CreatePayment.php:74-76` |
| **Allocating to another tenant's invoice** | `\DomainException` before sync. | `assertInvoicesShareTenant` `Payment.php:179-194` |
| **Initiated (uncaptured) online payment** | Allocation exists but `recomputeTotals` ignores non-captured → zero AR effect. | `Invoice.php:257-259`, `PaymobPaymentInitiator.php:83-89` |
| **Callback with tampered/forged fields** | HMAC digest mismatch → `401 invalid_hmac`; shape-only log. | `verifyHmac` `PaymobClient.php:187-227`; `CallbackController.php:37-52` |
| **Callback replayed / Paymob at-least-once retry** | Terminal-status guard returns `200 already_processed`; post-promotion the order lookup misses → `200 unknown_order`. | `CallbackController.php:62-80` |
| **Callback with no/zero order id** | `422 missing_order_id`. | `CallbackController.php:55-60` |
| **`hmac_secret` not configured** | `verifyHmac` returns false (fail-closed) → all callbacks 401. | `PaymobClient.php:219-221` |
| **Voided-but-"success" transaction** | `isCapture = success && !is_voided` → marked `failed`, not captured. | `CallbackController.php:82-84` |
| **Reusing a session after the balance dropped** | Amount-match check fails → fresh session built (no overcharge). **Stale-amount fix.** | `findReusableSession` `PaymobPaymentInitiator.php:139-141`; regression `PaymobSessionStaleAmountTest` |
| **Reuse across channels** | Reuse scoped to the same `channel` — a mobile session is never reused for a payment-link payment. | `PaymobPaymentInitiator.php:120-125` |
| **Apple Pay** | Always builds a fresh session on its own `integration_id`; never serves a card token. | `PaymobPaymentInitiator.php:58`, `PaymentLinkController.php:72-73` |
| **Paying an invoice with no balance** | `buildPaymentSession` throws ≤0; API returns `422 no_balance`; pay-link redirects to status; portal/admin actions hidden when `balance ≤ 0`. | `PaymobClient.php:163-165`, `InitiatePaymobSessionController.php:65-71`, `ViewInvoice.php:39/69` |
| **Paying a cancelled/credited invoice** | `isPayable()` false; API returns `422 invoice_not_payable`. | `Invoice.php:127-131`, `InitiatePaymobSessionController.php:57-63` |
| **Cross-tenant API session request** | **404** (not 403) — no existence enumeration. | `InitiatePaymobSessionController.php:50-55` |
| **Paymob disabled** | API `409 paymob_disabled`; portal shows the **demo** capture action instead. | `InitiatePaymobSessionController.php:43-48`; `ViewInvoice.php:63-87` |
| **Captured payment later deleted/voided** | `deleted` hook recomputes → invoice flips back to outstanding. | `Payment.php:220-222` |
| **Detaching an invoice on Edit** | Edit recomputes the **union** of previously-attached and newly-synced ids, so a detached invoice returns to outstanding. | `EditPayment.php:99-101` |
| **Cancelling an invoice that consumed credit** | Consumed credit is **returned** as an offsetting credit note (not on `credited`, which is the intended settlement). | `Invoice.php:235-238` → `CreditNoteService::reverseAppliedCredit` |
| **Duplicate reference under load** | `generateUniqueReference` re-checks + increments (incl. soft-deleted), up to 100 tries. | `Payment.php:230-247` |
| **`/pay/*` abuse (public)** | Throttled `30,1`; unknown token → 404. | `routes/web.php:42`, `PaymentLinkController.php:26-29` |
| **Mobile session endpoint abuse** | Throttled `5,1` (the session route sits under the `5,1` group). | `routes/api.php:63`, `InitiatePaymobSessionController` docblock |

---

## Invariants + gotchas

**Invariants — never break these.**

1. **`recomputeTotals()` is the only writer of `paid_amount`/`balance`/derived
   status.** Never set them by hand. `paid_amount = captured allocations +
   credit_applied_amount`; `balance = max(0, total − paid_amount)`
   (`Invoice.php:255-283`).
2. **Only `captured` payments settle AR.** An `initiated`/`failed`/`refunded`
   allocation contributes **nothing** (`Invoice.php:257-259`). This is *why* the
   online flow can pre-allocate the full balance the moment a session opens.
3. **Allocation must be ≤ the invoice's absorbable balance**, enforced at three
   layers: per-row form cap, total form cap, and the locked DB backstop. The
   backstop is the only one that survives a concurrency race.
4. **Cross-tenant allocation is impossible** (`assertInvoicesShareTenant`), and
   the mobile API hides existence with a 404.
5. **The S2S callback is the single source of truth** for online capture; the
   browser `return`/SDK result is UX-only and trusted for nothing.
6. **The callback is idempotent** (terminal-status guard + post-promotion
   id rewrite) and **fail-closed** on HMAC.

**Gotchas.**

- **Allocations save from the repeater state, not the form data** — they're
  unset from `$data` and synced in `afterCreate`/`afterSave`. That's why the
  cross-tenant + over-allocation guards live in the after-hooks, **inside a
  transaction**, *after* recompute. (Matches the project memory note on
  Filament relationship-save: guard post-sync.)
- **The backstop must run inside the same transaction as the sync** or the lock
  is pointless. Both pages do (`CreatePayment.php:67-73`, `EditPayment.php:96-105`).
- **`gateway_transaction_id` is overloaded.** Pre-capture it's
  `paymob:order:{orderId}` (the callback's lookup key); post-capture it's
  rewritten to `paymob:txn:{txnId}:order:{orderId}` — which *intentionally*
  makes the order-keyed lookup miss so retries hit the harmless `unknown_order`
  200 path. The browser `returned()` handler therefore matches **either**
  pattern (`CallbackController.php:113-118`).
- **Reference numbers and pay-link tokens are regenerated/pre-generated at save
  time**, never trusted from cached form state (`Payment.php:198-206`,
  `Invoice.php:199-218`).
- **Session reuse is amount-bound.** The 45-minute reuse window
  (`REUSE_WINDOW_SECONDS = 2700`) is deliberately below Paymob's 3600s token TTL
  so a near-expiry token is never served, *and* a reused session is dropped the
  moment the invoice balance changes (the stale-amount fix) — otherwise a card
  token bound to the old, higher amount would overcharge.
- **Amounts cross the gateway boundary in piastres** (`* 100`,
  `PaymobClient.php:87/118`); everything in Atriom is EGP major units.
- **The demo capture path is gated to `PAYMOB_ENABLED=false`** and uses
  `gateway = 'demo'`, so it can never run against a live gateway and is easy to
  filter out of reports.

---

## Where it lives in the code (file:line index)

**Model & allocation**
- `app/Models/Payment.php`
  - channel constants `:27-30`; fillable/casts `:32-57`
  - `notifyReceiptOnce()` `:66-86`
  - `invoices()` pivot `:93-98`
  - `generateReference()` `:105-120`; `generateUniqueReference()` `:230-247`
  - `recomputeAllocatedInvoices()` `:126-129`
  - **`assertInvoicesNotOverAllocated()` (lock + assert) `:143-165`**
  - `assertInvoicesShareTenant()` `:179-194`
  - `booted()` hooks (saved/deleted → recompute + notify) `:196-223`
- `app/Models/Invoice.php`
  - `payments()` pivot `:84-89`
  - `paymentLinkToken()/Url()/QrSvg()` `:97-124`; `isPayable()` `:127-131`
  - `creating` hook (number, token) `:199-218`; `updated` hook (cancel → credit reversal) `:223-248`
  - **`recomputeTotals()` (AR source of truth) `:255-283`**
- `database/migrations/2024_01_01_000007_create_payments_table.php` (payments + `invoice_payment` pivot)
- `database/migrations/2026_06_27_000001_*` (channel + `payment_link_token`); `2026_06_27_000002_*` (`receipt_notified_at`); `2026_06_28_000001_*` (gateway-id index)

**Paymob**
- `app/Services/Paymob/PaymobClient.php` — `authenticate :65`, `createOrder :85`, `requestPaymentKey :116`, `iframeUrl :152`, `buildPaymentSession :161`, **`verifyHmac :187-227`**, `boolStr :229`, `throwIfFailed :241`
- `app/Services/Paymob/PaymobPaymentInitiator.php` — `REUSE_WINDOW_SECONDS :33`, `start() :54-100`, `orderRef() :107-110`, `findReusableSession() :117-158`
- `app/Http/Controllers/Paymob/CallbackController.php` — **`processed() :32-100`**, `returned() :102-134`
- `app/Http/Controllers/PaymentLinkController.php` — `show/start/status`
- `app/Http/Controllers/Api/V1/Tenant/InitiatePaymobSessionController.php` — mobile session + guards
- `app/Http/Resources/Api/V1/PaymobSessionResource.php`
- `app/Actions/Api/V1/Payments/RecordDemoPaymentAction.php` — demo capture path
- `config/integrations.php` → `integrations.paymob.*`
- `routes/web.php:29-46` (paymob + pay routes); `routes/api.php` (`paymob-session`, `pay-demo`); `bootstrap/app.php` (CSRF exempt `paymob/callback`)

**Admin / portal UI**
- `app/Filament/Admin/Resources/Payments/Schemas/PaymentForm.php` — repeater, caps, suggest
- `app/Filament/Admin/Resources/Payments/Pages/CreatePayment.php` / `EditPayment.php` — sync + guards in after-hooks
- `app/Filament/Portal/Resources/Invoices/Pages/ViewInvoice.php` + `.../Tables/InvoicesTable.php` — Pay Now / Pay Demo / payment-link actions
- `app/Notifications/PaymentReceivedNotification.php`

**Tests (executable spec)**
- `tests/Feature/Http/Paymob/CallbackControllerTest.php` (HMAC, idempotency, capture/fail)
- `tests/Feature/Services/Paymob/PaymobClientTest.php` / `PaymobPaymentInitiatorTest.php`
- `tests/Feature/Scenarios/PaymobPaymentScenarioTest.php`
- `tests/Feature/Regression/PaymobSessionStaleAmountTest.php` (stale-amount reuse fix)
- `tests/Feature/Api/V1/Tenant/InitiatePaymobSessionTest.php`

**Lang keys**: `admin.payment.allocation_exceeds_balance`,
`admin.payment.cross_tenant_allocation` (`lang/en/admin.php:1504-1505`);
`admin.actions.allocation_exceeds_title/body`,
`admin.notifications.pay_now_failed*`, `payment_received_title`,
`payment_return_success/failed`.

---

## Related

Sibling docs in `docs/money/`:

- `00-money-model.md` — the overall AR/money model (the big picture this fits into).
- `01-billing-monthly.md` — how invoices (totals, VAT, proration) are created
  upstream of payment, including the billing run-lock.
- `03-marketing-levy.md` — the 5%-of-rent marketing levy that lands on invoices.
- `04-cam-reconciliation.md` — CAM reconciliation and positive/negative true-ups
  (true-ups become recovery invoices or credit notes that flow through the same
  payment/AR machinery).
- `05-percentage-rent.md` — turnover/percentage rent that also bills via invoices.
- `07-credit-notes.md` — `credit_applied_amount`, credit-note
  locking/reversal/auto-apply, and how applied credit feeds `recomputeTotals`.

Elsewhere:

- `docs/modules/06-payments.md` — the canonical business-rule module doc
  (rules · extension points · gotchas).
- `docs/modules/20-mobile-api.md` — the `/api/v1` surface that issues Paymob
  sessions.
- `docs/PAYMENT-LINK-APPLEPAY.md` · `docs/ETA-PAYMOB-CERTIFICATION.md` —
  Apple Pay domain verification and gateway certification status.
