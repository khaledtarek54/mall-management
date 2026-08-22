# Payments & Allocation

> **⚠️ The over-allocation guard did not fire under real concurrency (fixed 2026-08-19).**
> `Payment::assertInvoicesNotOverAllocated()` locks the invoice ROWS and then sums the four
> settlement channels — with **plain** reads. Under MySQL REPEATABLE READ those are served from the
> snapshot the transaction took before it waited for the lock, so the second writer sums a pivot that
> does not yet contain the first writer's allocation and concludes there is room. Proven with two
> processes on two connections: the guard passed on a fully-settled invoice, and what refused the
> second receipt was the UNIQUE index on `payments.reference`.
>
> All four sums are now **locking** reads — the guard is only as strong as its weakest term — and so
> are the three in `refitAllocationsToBalance()`, which runs inside the gateway capture transaction
> for the same reason. `Payment` also now uses `AllocatesDocumentNumber`: it was the one money model
> carrying a UNIQUE reference without the lock, so two receipts taken in the same second both
> computed `PAY-202608-0195` and one died with a duplicate-key 500. After the fix the race ends in
> *"Allocation to invoice … cannot exceed EGP 0.00"*.
>
> **The suite cannot prove this** — SQLite compiles locks to nothing and one connection never
> interleaves. `ConcurrencyGuardsReadUnderLockTest` pins the structure via `LockSpy`;
> `docs/qa/scripts/race.sh` is the real proof and must be run against MySQL.

> **⚠️ An unpaid security-deposit invoice is no longer a books discrepancy (fixed 2026-08-19).**
> `InvoiceJournalizer` credits `deposits_held` at ISSUE, while `DepositHoldings::held()` counts a
> billed deposit only once SETTLED — both correct, and compared directly by `deposits_tie_out`. So
> every deposit in flight was reported as drift: a 150,000 deposit billed and unpaid moved the GL and
> not the register, and the check failed until the payment landed. With `billing:reconcile --deep`
> running Friday and terms of 7 days, a deposit billed on a Thursday failed it every time — the
> "cries wolf" failure the CAM check's own comment warns about. The tie-out now expects
> `held + billed-and-outstanding`. Pinned by `DepositInFlightTiesOutTest`, whose second case proves
> a REAL one-road gap is still caught.


> System for recording tenant payments against invoices, tracking AR balances, integrating with Paymob gateway, and managing late fees.
>
> **Plain-language companion:** the visual handbook at `/admin/handbook` — how payments work, with worked scenarios, bilingual.

## 1. Purpose & business context

The **Payments module** records money received from tenants (Eltizam operatives, through their portal) and allocates it across their outstanding invoices. It is the financial core of the system:

- **Eltizam tenants** use the portal to see payment options (Pay Now via Paymob card gateway, or inform admins of bank transfers/cheques).
- **Jawad admins** (accounting department) manually record cash, transfers, cheques in the admin Payment form, allocating amounts to specific invoices.
- **Paymob integration** creates an `initiated` Payment when a tenant starts a Pay-Now session, captures it when the callback confirms success, and automatically settles the invoice.
- **Late fees** are applied daily to overdue invoices (past due date + grace days), with a configurable % and minimum threshold.
- **Invoices** recompute their AR balance (paid_amount, balance, status) from allocated *received* payments (captured/reconciled/settled) + applied credit notes, making the pivot the source of truth for cash collection.

The module ensures money math is exact, AR ageing is accurate, and every payment path (manual, Paymob, demo) converges on the same recomputation logic.

## 2. Domain model

| Table           | Model    | Key columns (type / constraint / default) | Meaning |
|-----------------|----------|-------------------------------------------|---------|
| `payments`      | `Payment` | `id`, `reference` (string, unique), `tenant_id` (FK), `amount` (decimal 12,2), `currency` (string 3, default EGP), `method` (string 32 — the `payment_methods` catalogue; the seven shipped codes are a FLOOR that an operator's own rails widen), `status` (enum: initiated, authorized, captured, reconciled, settled, failed, refunded, bounced, default captured), `payment_date` (date), `gateway` (string, nullable; e.g. "Paymob", "demo"), `gateway_transaction_id` (string, nullable), `gateway_response` (json, nullable), `cheque_number` (string, nullable), `cheque_clearance_date` (date, nullable), `notes` (text, nullable), `received_by` (FK → users, nullable), `receipt_notified_at` (timestamp, nullable), timestamps, softDeletes | Core payment record. Scoped to a single tenant. |
| `invoices`      | `Invoice` | `id`, `number` (string, unique; format INV-{ASSET_CODE}-{YYYYMM}-{SEQ}), `lease_id` (FK), `tenant_id` (FK), `status` (enum: draft, issued, partially_paid, paid, overdue, disputed, cancelled, credited), `issue_date`, `due_date` (dates), `period_start`, `period_end` (dates), `subtotal`, `vat_amount`, `total` (decimals 12,2), `paid_amount` (decimal 12,2, default 0), `credit_applied_amount` (decimal 12,2, default 0; see § 3), `balance` (decimal 12,2, default 0), `currency`, `eta_*` (tax authority submission fields), `owner_overdue_notified_at` (timestamp, nullable), timestamps, softDeletes | One invoice per lease billing period. AR balance computed from received payments + credit. |
| `invoice_items` | `InvoiceItem` | `id`, `invoice_id` (FK), `charge_id` (FK, nullable), `description`, `type` (enum: base_rent, service_charge, utility, parking, percentage_rent, late_fee, other), `amount`, `vat_rate`, `vat_amount`, `total`, timestamps | Line items (rent, CAM, utilities, late fees). Late fees added at charge time. |
| `invoice_payment` | Pivot   | `id`, `invoice_id` (FK), `payment_id` (FK, unique pair), `allocated_amount` (decimal 12,2), timestamps | Many-to-many: allocates a payment across multiple invoices. Only *received* rows (captured/reconciled/settled) count toward AR. |

**Relationships:**
- `Payment` belongs-to `Tenant` | has-many `Invoice` (via pivot invoice_payment)
- `Invoice` belongs-to `Lease`, `Tenant` | has-many `InvoiceItem` | has-many `Payment` (via pivot invoice_payment)
- `Payment` belongs-to `User` (received_by)

## 3. Business rules & invariants

> **A recorded payment's money fields are immutable in the form (GL integrity, Phase 1).** Once a
> payment exists, the admin form disables `amount`, `payment_date`, `method`, and `tenant` — a
> mistake is corrected by voiding + re-recording, not a silent edit that would desync the GL cash/AR
> movement. The **allocations repeater stays editable** (re-allocating a receipt across invoices is
> legitimate — it bumps the payment so the GL sweep re-derives the split). See [module 21 §Document immutability](21-general-ledger.md).
>
> **"Received" = captured / reconciled / settled — one canonical set (`Payment::RECEIVED_STATUSES`).**
> All three mean the money is on the books, and EVERY consumer keys off the set: `Invoice::recomputeTotals`,
> `PaymentJournalizer`, the collections widgets (`MallStats`, `RecentPayments`, `MonthlyRevenueTrend`),
> the portal `AccountBalance`, the tenant/asset statements, `ReportService`, `BooksReconciliationService`,
> and the over-allocation guards. So a captured→reconciled→settled move never un-pays an invoice or voids
> its cash entry. *(This consolidated a drift where the core AR/GL was `captured`-only while the portal
> widget already grouped the three — marking a captured payment "reconciled" silently un-paid it.)*
>
> **Reversing a received payment = void/refund, not edit.** The status Select on the form offers only
> the forward "money in" lifecycle (initiated + the received set); the reversal statuses
> (`refunded`/`failed`/`bounced`) are NOT manually selectable. Reversal goes through the **"Void / refund"**
> action (`VoidPaymentService`, gated `payments.void`, with a mandatory reason) → sets `status='refunded'`,
> the allocated invoices' AR re-opens (only *received* payments count toward `paid_amount`), the GL leg is
> reversed, and the reason lands in the audit trail. Record the actual refund to the tenant separately.

### AR Balance Computation (Invoice::recomputeTotals)
The *single source of truth* for paid amount and balance:

```
paid_amount = SUM(received_payments.allocated_amount) + credit_applied_amount + SUM(tenant_credit_applications.amount)
balance = MAX(0, total - paid_amount)   /* never negative */
```

- Only payments in `Payment::RECEIVED_STATUSES` (captured / reconciled / settled) count. Initiated, failed, refunded, bounced allocations have zero effect on AR.
- Credit notes increase `credit_applied_amount` (tracked separately so recompute doesn't erase them).
- **Applied tenant credit** (an on-account overpayment drawn onto this invoice) adds its own summand — `SUM(tenant_credit_applications.amount)`, active rows only. See § *Applying tenant credit* below.
- Balance is floored at 0, never negative (guards against overpayment rounding).

**Status auto-flip** (unless overridden to `cancelled`, `credited`, `disputed`):
- `balance ≤ 0` and `paid_amount > 0` → `paid`
- `0 < paid_amount < total` → `partially_paid`
- `due_date` is past AND `paid_amount = 0` → `overdue`
- Otherwise (issued, future due, no payment) → `issued`

Guarded by tests: `PaymentScenarioTest` (HAPPY, STATE-TRANSITION, BOUNDARY, NEGATIVE, STATE).

### Which LINES a payment settled (MF-06)

`invoice_item_payment` records an optional split of a payment across the invoice's lines, written by
`AllocatePaymentToInvoiceItemsService` and surfaced as the **Payment split** action on the invoice.
It moves no money and posts nothing: the payment's allocation to the invoice is already on
`invoice_payment` and already counted by `recomputeTotals()`.

**Nothing per-item is stored as a balance.** `App\Support\InvoiceItemSettlement` DERIVES every
per-line figure from `invoices.paid_amount`, so the item outstandings sum back to
`invoices.balance` — **while the invoice is internally consistent**. `total` is a header column that
deleting an item does not recompute, so a mutilated invoice breaks the tie-out; that state is
already broken independently, and `billing:reconcile`'s "Invoice total = line-item subtotal + VAT"
check is what catches it.

**A credit note cannot be pointed at a line** — only payments carry an item allocation, so a credit
issued for a disputed service charge distributes by priority and lands on rent first. Conservative
(a late fee is suppressed on credited money, under-charging rather than over-charging) and covered by
the workflow, since an operator who credits a dispute resolves it. A stored per-item balance would be a second truth about the same money, and the
first credit note applied without an item breakdown would desynchronise it silently. This is also why
a fifth AR settlement channel would need no change here.

A line settles either **explicitly** (the operator typed what the remittance advice said) or by
**charge-type priority** — `InvoiceItemSettlement::TYPE_PRIORITY`, rent first and **late fees last**,
so a partial payment is never eaten by a penalty. Yardi makes that order configurable per AR
settings; Atriom's is a constant until an operator asks (see the class docblock).

Explicit rows are counted only for payments in `RECEIVED_STATUSES`. That filter matters most when a
refunded payment sits *beside* a live one: without it the live money spreads across both splits and
reports a line as part-paid when it was paid in full.

### Payment Allocation Guards
1. **Cross-tenant barrier** (audit M06 F-26 / D-19): `Payment::assertInvoicesShareTenant()` throws `DomainException` if any invoice belongs to a different tenant. Called by Create/Edit pages after pivot sync; tested in `PaymentAllocationGuardsTest`.
2. **Per-row allocation cap** (audit M06 F-25 / D-18): Form validation ensures allocated amount ≤ invoice balance (+ existing row allocation when editing). Prevents over-allocation that the total-only guard would miss.
3. **Total allocation cap**: Form displays unallocated remainder and warns if allocated > payment amount.
   - **The race backstop is `Payment::assertInvoicesNotOverAllocated()`**, which locks each invoice and re-checks captured allocations + all three non-payment channels against the invoice total. Its refusal used to quote **the invoice total** as the maximum, so on an invoice already part-settled by a credit note the operator read *"cannot exceed EGP 240,300.00"* while 60,200 was all that remained — the quoted cap being the very amount they had just been refused for. The form's per-row rule had always named the fittable figure, so the two layers disagreed about the same invoice and only the one reached under contention was wrong. It now names `total − what the other channels already settled`, floored at zero (a fully-settled invoice reports `EGP 0.00`, never a negative). `OverAllocationRefusalNamesTheRealCapTest`.
4. **Property-scoped tenant select** (regression: cross-property IDOR fixed): PaymentForm's tenant_id relationship is filtered by `TenantScope::visibleAssetIds()`, so a property-restricted user cannot see another property's tenants. Tested in `PaymentFormPropertyScopeTest`.
5. **Posting-date guard** (close-out 2026-07-19): `CreatePayment` runs `PostingDate::assertOpen(payment_date)` — a receipt back-dated into a **closed** accounting period is refused before it relieves AR (its GL cash/AR leg could never post → silent divergence). A missing period is allowed. Tested in `PaymentFormGuardsTest`.
6. **At-least-one-allocation** (close-out 2026-07-19): the allocations Repeater has `minItems(1)` + a server `guardHasAllocation()` on create & edit. A zero-allocation on-account receipt would post as unearned revenue but be **orphaned** — invisible in the property-scoped UI (which scopes payments via their invoices) with no way to later apply it. A tenant credit balance from an *overpayment* is now surfaced (admin + portal) and spendable via the Apply action below; banking a standalone pre-invoice advance is still deferred. Tested in `PaymentFormGuardsTest`.
7. **Duplicate-allocation dedup** (close-out 2026-07-19): two repeater rows for the same invoice are **summed** in the pivot builder (was: the pivot is keyed by invoice id, so the second row silently overwrote the first → money stranded while the summary reported it allocated). Tested in `PaymentFormGuardsTest`.
8. **Manual status restricted to the forward flow** (close-out 2026-07-19): the status Select offers only initiated + the received set; reversals route through the Void action (see §3 blockquote). Tested in `PaymentReceivedStatusesTest`.

### Cancelling an invoice that holds captured cash — refused on every path

`VoidInvoiceService` has always refused to void an invoice with a captured payment allocated to it:
the money would stop being receivable without ever being returned. **The invoice form's status
Select offered `cancelled` as a plain option and walked straight past that.** Measured on a 10,000
invoice settled by a captured 10,000 payment:

| | after a form cancel |
| --- | --- |
| invoice | `status=cancelled`, balance forced to **0** |
| payment | `status=captured`, still allocated **10,000** |
| tenant credit | **0** |

The tenant's 10,000 was neither receivable nor owed back — gone from every operator-visible surface
while the cash sat in the GL.

- **The guard is on the model, in `updating`** (`Invoice::booted`), so the form, the API, a console
  command and a tinker session all hit it — and the write is *refused*, not merely reported. *(It
  was first written in the `updated` hook, which fires after the row is persisted: the exception
  surfaced and the cancel still happened. The regression test caught it.)*
- **It reuses `capturedCashPaid()`** — paid, net of credit notes and applied tenant credit — the
  same predicate `VoidInvoiceService` uses, named once so the two cannot drift.
- **Reversible non-cash settlement still cancels.** An invoice settled by a credit note or applied
  tenant credit nets to zero cash, so it voids normally and the credit returns. Only real cash
  refuses; the remedy is to void/refund the payment first.
- **`cancelled` is no longer offered on the status Select.** Cancelling is the *outcome* of the
  "Void invoice" action, which takes a reason and writes it to the audit trail. The model is the
  gate; removing the option stops the UI inviting it.
- **Test:** `CancelInvoiceCapturedCashTest`.

### Checked and covered — don't re-derive these (close-out 2026-07-30)

Swept during the module-06 close-out and found sound. Recorded so the next pass spends its time
elsewhere.

- **Payment → GL tie-out, all 197 payments.** Every received payment has a posted entry whose debit
  equals its amount; no entry survives on a payment that is not received. The three failure modes
  (missing / stale-amount / orphaned) are all zero.
- **Allocation invariants, all 197 payments and 279 invoices.** No over-allocation past the payment
  amount, no row exceeding its invoice total, no cross-tenant allocation, no received payment
  without an allocation, no negative tenant credit, and `paid_amount` matches the documented formula
  on every invoice.
- **Re-allocating a receipt between invoices re-opens the invoice that LOST it.** `EditPayment`
  captures the previously-attached ids *before* the sync and recomputes the **union** of old and
  new, so the detached invoice does not keep a stale `paid_amount`. Easy thing to get wrong; it is
  right.
- **Cash cannot be allocated to a cancelled invoice.** Not by one guard but by the same one at every
  entry point — the repeater only offers invoices with `balance > 0` and caps each row at that
  balance; `RecordDemoPaymentAction` aborts 422 on `balance <= 0`; `PostDatedChequeService::clear()`
  allocates `min(cheque, balance)` and skips at zero; and the Paymob cancel-after-init race has its
  own scenario test. Reachable only by calling `invoices()->attach()` directly, which no production
  path does.

### Applying tenant credit (ApplyTenantCreditService)
An overpayment leaves a **credit on account** (`Tenant::creditBalance()` = received-payment remainders − applied credit, both optionally property-scoped). The **Apply tenant credit** action on `EditInvoice` draws that down onto an open invoice.

- **A new dated-today GL source, not a re-shuffle.** Each apply writes a `TenantCreditApplication` row (`tenant_id`, `invoice_id`, `asset_id`, `amount`, `entry_date = today`, `created_by`) — a registered GL source (`TenantCreditApplicationJournalizer`) posting **Dr Unearned Revenue / Cr Accounts Receivable** dated the day you apply. This is deliberate: the first attempt instead extended the *source payment's* pivot, which re-derived that payment's **immutable, historically-dated** GL entry into a possibly-**closed** period — AR would drop while the GL refused, a permanent GL↔AR divergence. Dating the correction *today* means a credit from a closed month spends cleanly. (Memory: *never move AR by re-deriving a historical immutable-dated GL entry into a possibly-closed period; corrections post dated-now.*)
- **Capped + scoped (two caps).** `applyToInvoice()` row-locks the invoice + tenant, then applies `min(invoice.balance, creditBalance([invoiceAsset]), creditBalance(null), requested)`; throws `no_credit_to_apply` if ≤ 0. The **per-property** cap (`creditBalance([invoiceAsset])`) stops property-A credit settling a property-B invoice; the **global** cap (`creditBalance(null)`) is a backstop — a single receipt split across two malls reports its surplus under *both* properties' scopes, so without the total-credit cap the same 5,000 could be drawn once per mall (adversarial review, MEDIUM). An invoice with no resolvable property is **refused** rather than allowed to draw consolidated (null-asset) credit.
- **Reversible.** `reverseForInvoice()` soft-deletes the applications; `LedgerPoster::sync` sees the trashed source and **voids** the GL entry, `recomputeTotals` re-opens the invoice, and the credit returns to the balance. Backed by the `reverse_credit` action.
- **Refund interlock (property-scoped).** `VoidPaymentService` refuses to refund a receipt whose unallocated surplus has already been applied (`refund_blocked_credit_applied`) — else you'd refund cash that also settled AR, driving the credit negative. The check is scoped to **the receipt's own property(ies)**: a global balance would let unrelated credit at another mall mask that this receipt's surplus was already spent (adversarial review, MEDIUM). Reverse the applications first, then refund.
- **Void auto-reverses applied credit.** Applied tenant credit is reversible non-cash, so it does **not** block a void — `Invoice::capturedCashPaid()` (`paid_amount − credit_applied_amount − Σ tenant_credit_applications`) is the single "real cash" predicate shared by `VoidInvoiceService` and the `void_invoice` / Filament `visible()` guard (named once so they can't drift). On cancel, the Invoice `updated` hook soft-deletes the applications (returning the credit) just as it reverses applied credit notes — on **any** cancel path, so a credit can never strand on a voided invoice. Only captured *cash* still blocks a void (refund it first).
- **Gate:** both actions gate on `payments.edit` in **both** `visible()` and `action()` (`abort_unless`). Registered in `LedgerPoster::JOURNALIZERS` + `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` (`entry_date`) + `#[PropertyOwned]` (direct `asset_id`) — the conformance gates enforce all three. Tested in `TenantCreditApplyTest` (incl. a GL↔AR tie-out through real posting, the closed-source-period case, the cross-property double-apply + scoped-refund guards, and void-auto-reversal).

### A disputed line is not chargeable (MF-07)

`invoice_items.disputed_at` / `disputed_reason` / `disputed_by_id`, written by
`DisputeInvoiceItemService`. `LateFeeService` charges its percentage on
`balance − DisputeInvoiceItemService::disputedOutstanding()`, and when that reaches zero it charges
**nothing** — not the minimum, which would bill the floor off a balance nobody has agreed is owed.

**A dispute is not a write-off.** The debt stays claimed, aged and on the balance sheet; only the
penalty is suspended. The disputed figure is shown BESIDE the aged one on *Aging by charge type*,
never netted out of it. The header `invoices.status` is deliberately untouched — an invoice is rarely
disputed in full, and marking the header stops chasing the rent on the same document.

Only the **outstanding** part of a line is disputed (read through `InvoiceItemSettlement`, MF-06): a
part-paid line is argued about for what is still owed on it, and using the gross figure would
suppress a fee on money already settled. The reason is required by the service.

### Late Fees (LateFeeService)
- Terms resolve **lease → property → portfolio** through `Lease::lateFeeTerms()` (`ActsAsBillableAgreement`): a per-lease column, else `/admin/property-overrides`, else `BillingSettings` (defaults 2% · 7 days · EGP 50). **Not config** — the `config/billing.php` keys were read by nothing and were deleted (EG-19).
- Applied once per invoice when: `due_date + grace_days ≤ today`, balance > 0, and no late_fee item yet exists.
- Fee = `MAX(minimum, balance × percent / 100)`, rounded to 2 decimals.
- Idempotent via invoice-level check inside pessimistic lock (prevents double-charge on concurrent runs).
- Run via `ApplyLateFeesCommand` (daily cron) or `ApplyLateFees` job.
- Tested in `LateFeeServiceTest`.

### Paymob Gateway Rules (audit M11 F-42 / D-33)

> Full implementation reference — every API body, the HMAC field order, the
> channel model, the capture clamp, the mobile contract, the known gotchas, and
> a checklist for porting it to another system:
> [`docs/integrations/PAYMOB.md`](../integrations/PAYMOB.md).

- Session reuse window: 2700 seconds (< 3600s token TTL) so reuse margin exists for user to fill the card form.
- Reuse only if: payment is `initiated`, same invoice, same amount (rounded to 2 decimals). A credit/partial payment drops balance → fresh session forced.
- Capture: `success = true AND NOT is_voided`. Voided transactions are treated as failed.
- Idempotency: `gateway_transaction_id` is promoted from `paymob:order:{id}` to `paymob:txn:{txn_id}:order:{order_id}` on capture. A replay of the same payload still returns 200 `already_processed` — but now because the controller FINDS the payment and declines to touch it, not because the lookup missed.
- **A declined card does not close the order (fixed 2026-08-17).** A Paymob ORDER carries many transactions: a shopper who is declined and presses "try again" on the hosted page produces a second transaction under the same order. Two independent defects each discarded it, and either alone loses the money —
  - the lookup compared `gateway_transaction_id` against `paymob:order:{id}`, **the very string the first callback overwrites**, so no later callback for that order could find its row;
  - and `failed` was treated as terminal, so even once found the capture would have been skipped.

  Consequence when the retry succeeded: **the tenant is charged and the invoice stays open.** Observed live — order `589424727` declined as txn `229844534` at 18:53, txn `803955240` one minute later filed as "unknown order" and dropped. It could not be adjudicated afterwards, because the log recorded the ids and **not whether the transaction succeeded**: a dropped decline and a dropped payment were the same line. The lookup now matches on the order (suffix match, so rows already promoted are reachable — this repairs history, not just new sessions), `failed → captured` is allowed for a *different* transaction reporting success, and an unmatched or skipped SUCCESS logs a **warning** naming the amount. `captured` and `refunded` stay terminal on purpose: a late failure must never un-capture collected money, and a refund is an operator's decision no gateway delivery may reverse. `PaymobRetryAfterDeclineTest` pins all five cases.
- **Paymob sends no decline reason we can show.** The stored `gateway_response` for a refused card carries `success:false` with `error_occured:false` and `pending:false` — a clean issuer decline — but no `data.message` or `txn_response_code`, so neither the operator nor the tenant can be told *why* the bank refused. Nothing to fix in this codebase; noted so nobody hunts for a reason field that never arrived.
- Tested in `PaymobPaymentScenarioTest`, `PaymobSessionStaleAmountTest`, `PaymobRetryAfterDeclineTest`.
- **One session per invoice+channel at a time.** `start()` is check-then-act with a network call in the middle, so it is serialised on a `Cache::lock("paymob-session:{invoice}:{channel}")` held ACROSS the gateway call. Without it two simultaneous taps (double-click, two tabs, a retried POST) both find no reusable session and both open one, leaving **two live Paymob orders against one debt**, each allocated the full balance — capture both and the tenant has paid twice. The second request WAITS (`session_lock_wait_seconds`, default 10) and then reuses the first one's session rather than failing. Lock TTL `session_lock_seconds` (default 30) so a wedged request cannot strand the invoice; released in a `finally`, including when the gateway throws. Tested in `PaymobConcurrentSessionTest`.
- **The pay page's CSP must name the gateway ORIGIN in `form-action` (fixed 2026-08-17).** `/pay/*` carries a deliberately tight policy from `App\Http\Middleware\SecurityHeaders`, and it said `form-action 'self'` — but that directive is checked against **every hop of a form submission's navigation, redirects included**, not just the POST target. So the browser accepted `POST /pay/{token}/start` and then silently refused the 302 to `accept.paymob.com`: the button did nothing, while the server logged a perfectly healthy `paymob.session_started` and wrote a real `initiated` Payment against a real Paymob order. **The suite could not see it** — Laravel's test client does not enforce CSP, so `PaymentLinkFlowTest`'s `assertRedirect()` to the gateway passed the whole time the button was inert. The origin is **derived from `PAYMOB_BASE_URL`**, never written out, so a sandbox ⇄ production switch or a regional host cannot leave the policy behind; Apple Pay rides the same host and needs no entry. `PayPageCspAllowsGatewayHandoffTest` asserts the property the browser actually checks — that the `Location` the hand-off redirects to is an origin the page's own policy permits — rather than that either half looks right alone.

### Receipt Notification (regression: fixed in `PaymentReceivedNotification`)
- Fires exactly once when: payment is `captured` AND has at least one allocated invoice.
- Idempotent via `receipt_notified_at` timestamp (guards against double-notify on Edit re-save).
- Called from: Payment::saved hook (Paymob capture path), Create/Edit after-hooks (manual path).
- Notifies `Tenant` record + all `TenantUser` portal logins via `Tenant::notifyPortal()`.
- Tested in `PaymentReceiptNotifyOnceTest`, `PaymobPaymentScenarioTest`.

## 4. Lifecycle / state machine

### Payment Status Flow

```
initiated → captured     (successful gateway or manual record)
         → failed        (failed gateway or manual mark)
         → authorized    (reserved for 3D auth holds; rarely used)
         → reconciled    (settled in accounting system)
         → settled       (final accounting state)
         → refunded      (chargeback, cancellation, or reversal)
         → bounced       (cheque bounce, etc.)
```

**Triggers:**
- `initiated` → `captured`: Paymob callback posts success, or manual Create/Edit form sets it to captured.
- `initiated` → `failed`: Paymob callback posts failure, or user marks it failed.
- `captured` → `failed`: Chargeback / manual reversal (flips invoice back to unpaid).
- Any → `refunded`: Manual chargeback/cancellation.

**Immutable:** Once a payment is deleted (soft or hard), its allocations are detached and invoices recompute to restore their open balances.

### Invoice Status Flow (auto-driven by recomputeTotals)

```
issued ↔ partially_paid ↔ paid
  ↓
overdue
  
Manual overrides (not clobbered):
  → disputed, cancelled, credited (recompute leaves these alone)
```

**Transitions:**
- Unpaid, future-due → `issued`
- Unpaid, past-due (no grace) → `overdue` (via late-fee cron or ScanOverdueInvoicesCommand)
- `0 < paid_amount < total` → `partially_paid`
- `paid_amount ≥ total` → `paid`

**Immutability:** Manual statuses (`disputed`, `cancelled`, `credited`) are never auto-flipped by recompute (guard: `if (in_array($this->status, ['cancelled', 'credited', 'disputed']))`).

## 5. Services, jobs & scheduled commands

### PaymobPaymentInitiator::start(Invoice) → array
**Signature:** `public function start(Invoice $invoice, string $channel = Payment::CHANNEL_MOBILE, ?int $integrationId = null): array`

Creates an `initiated` Payment for the invoice's current balance, allocates the full amount in the pivot, and returns the Paymob session (payment_token, iframe_url, order_id, payment_id, expires_at, reused). The Payment is keyed by Paymob's order_id so the S2S callback can recover it.

- **Idempotency:** Reuses an existing initiated session if within 2700s window and amount matches current balance.
- **Concurrency:** serialised per invoice+channel by a cache lock; the reuse check is re-run INSIDE it, which is what makes reuse a guarantee rather than a race. See the gateway rules above.
- **Transaction:** DB::transaction wraps Payment create + pivot attach.
- **Call sites:** Portal invoice Pay-Now button, mobile app payment endpoint.
- **Tested:** `PaymobPaymentInitiatorTest`, `PaymobSessionStaleAmountTest`.

### PaymobClient::buildPaymentSession(Invoice) → array
**Signature:** `public function buildPaymentSession(Invoice $invoice): array`

Orchestrates Paymob API: auth → createOrder → requestPaymentKey. Throws RuntimeException if any step fails. Returns the session dict for PaymobPaymentInitiator to wrap.

- **No idempotency:** Each call burns a fresh Paymob order. PaymobPaymentInitiator handles reuse.
- **Amount:** Fetches from invoice.balance (EGP major units); converts to piastres for API.
- **Tested:** `PaymobClientTest`.

### LateFeeService::runForToday(?CarbonImmutable) → array
**Signature:** `public function runForToday(?CarbonImmutable $today = null): array`

Applies late fees to all invoices past due_date + grace_days with balance > 0. Returns stats: `{considered, applied, skipped, failed}`.

- **Idempotency:** Skips invoices that already carry a `late_fee` InvoiceItem.
- **Locking:** `DB::transaction` + `lockForUpdate` inside the transaction re-checks the guard so concurrent runs don't double-charge.
- **Amendment:** Creates an InvoiceItem with type=late_fee, bumps subtotal/total/balance, and forces status to overdue.
- **Tested:** `LateFeeServiceTest`.

### ApplyLateFeesCommand
**Signature:** `artisan billing:apply-late-fees {--date=} {--queue}`

Thin wrapper around LateFeeService::runForToday. Dispatches to queue if `--queue` flag, otherwise synchronous. Defaults to today; `--date=YYYY-MM-DD` overrides.

- **Idempotent:** Service is idempotent; running twice same day applies nothing on second pass.
- **Scheduled:** Expected to run daily (in kernel.php schedule).

### ScanOverdueInvoicesCommand
**Signature:** `artisan billing:scan-overdue-invoices {--dry-run}`

Notifies Jawad owners when a tenant is overdue on a property. Idempotent via `invoice.owner_overdue_notified_at`.

- **Locking:** DB::transaction + lockForUpdate inside re-checks the timestamp guard.
- **Dry-run:** Shows what would alert without writing.
- **Recipients:** AssetStaffRecipients::owners() for the unit's asset.
- **Notification:** InvoiceOverdueOwnerNotification (mail + database channels).

### RecordDemoPaymentAction::handle(Invoice) → Payment
**Signature:** `public function handle(Invoice $invoice): Payment`

Simulates a successful Paymob capture. Creates initiated Payment for invoice.balance, allocates full amount, flips status to captured in one transaction. Mirrors real Paymob flow exactly (initiated → allocate → capture → recompute + notify).

**Gated by `App\Support\DemoPayments::enabled()` — never by `PAYMOB_ENABLED` alone (corrected 2026-08-11).** The old gate was inverted with respect to safety: Paymob-off is the shipped default *and* the runbook's incident response, so the shortcut was live precisely on a production box with no gateway configured. An authenticated tenant could `POST /api/v1/me/invoices/{id}/pay-demo` and mark their own invoice paid — AR closed, the ledger posted `Dr Bank / Cr AR`, and `billing:reconcile` stayed **green**, because every internal relationship really was consistent; the money simply never existed. Chained with the quick-lease wizard's default password, knowing a retailer's email was enough.

Three conditions now, all required, asked in **one** place because the predicate had been written out at three dispatch points: never on `production` (checked first, independently of config, so it cannot be misconfigured); an explicit `DEMO_PAYMENTS_ENABLED` opt-in that defaults off outside local/testing (staging included — it carries real-shaped data); and Paymob still off. `atriom:health` **fails** a production box where the flag is set, so the intent cannot be silent even though the environment check would refuse it.

- **Idempotency:** None; each call creates a new Payment.
- **Notification:** The status flip to captured fires PaymentReceivedNotification (via Payment::saved hook).
- **Gateway tag:** gateway='demo', gateway_transaction_id prefixed with "demo:invoice:".
- **Tested:** `PaymentScenarioTest` (demo path), `PaymobPaymentScenarioTest` (demo fan-out).

## 6. Filament resources & key fields

### Admin PaymentResource (`app/Filament/Admin/Resources/Payments/PaymentResource.php`)
- **Model:** Payment
- **Pages:** List, Create, Edit
- **Scope:** TenantScope via invoices.lease.unit (property-restricted admins see only their property's payments)
- **Permissions:** payments.view, payments.create, payments.edit, payments.delete (RolesPermissionsSeeder)

**Form fields (PaymentForm):**
- `reference` (auto-generated PAY-YYYYMM-####, disabled)
- `tenant_id` (required, searchable, live, property-scoped via TenantScope::visibleAssetIds)
- `payment_date` (date picker, required, default now)
- `amount` (numeric, required, live for allocation suggestion)
- `method` (enum select, required)
- `status` (enum, default captured)
- `allocations` (repeater, live):
  - `invoice_id` (select, options filtered to tenant's invoices with balance > 0)
  - `allocated_amount` (numeric, auto-fill on invoice pick, per-row validation cap at invoice.balance + existing row)
- HTML summary: payment amount, allocated total, unallocated remainder (color-coded)
- `gateway`, `gateway_transaction_id`, `cheque_number`, `cheque_clearance_date` (collapsible section)
- `notes` (textarea, collapsible)

**Validation:**
- Total allocated ≤ payment amount (guardAllocationsTotal in CreatePayment/EditPayment)
- Per-row allocated ≤ invoice.balance + existing row (form rule)
- Cross-tenant allocation rejected by assertInvoicesShareTenant (afterCreate/afterSave)

**After-hooks:**
- CreatePayment::afterCreate: sync pivot, recomputeAllocatedInvoices, notifyReceiptOnce
- EditPayment::afterSave: sync pivot, recompute ALL touched invoices (previously + newly attached), notifyReceiptOnce

### Portal PaymentResource (`app/Filament/Portal/Resources/Payments/PaymentResource.php`)
- **Model:** Payment
- **Pages:** List (infolist), View
- **Scope:** WHERE tenant_id = Portal::tenantId() (portal user sees only their tenant's payments)
- **Permissions:** None (implicit; restricted to authenticated portal user)
- **Actions:** Read-only (canCreate=false, canEdit=false, canDelete=false)

## 7. Notifications & integrations

### PaymentReceivedNotification
- **Channels:** mail + database (Filament bell)
- **Recipient:** Tenant + all TenantUser portal logins via Tenant::notifyPortal()
- **Trigger:** Payment::saved hook (after status → captured), OR explicit notifyReceiptOnce() call (Create/Edit after-hooks)
- **Guard:** receipt_notified_at timestamp (fires once)
- **Content:** Mail subject/body reference payment details (reference, amount, method, date, allocated invoices); database notification is persistent (not auto-dismiss) with icon=banknotes, color=success.

### InvoiceOverdueOwnerNotification
- **Channels:** mail + database
- **Recipient:** Asset staff (Jawad owners of the property)
- **Trigger:** ScanOverdueInvoicesCommand (daily cron, idempotent via owner_overdue_notified_at)
- **Content:** Alerts owner to overdue invoice (number, tenant, balance, due date).

### Paymob Gateway Integration (PaymobClient)
- **Endpoints:** 3-step iframe flow:
  1. POST /api/auth/tokens → bearer token
  2. POST /api/ecommerce/orders → order_id
  3. POST /api/acceptance/payment_keys → payment_token
- **HMAC Verification:** S2S callback signed with SHA512 over lexicographically-ordered fields (PaymobClient::verifyHmac)
- **CallbackController:**
  - **processed()** — S2S "transaction processed" callback; verifies HMAC, flips Payment status, recomputes invoices.
  - **returned()** — Browser redirect post-iframe (UX only; server-to-server is source of truth).
- **Config:** integrations.paymob.{api_key, integration_id, iframe_id, base_url, currency, hmac_secret}
- **Tested:** CallbackControllerTest, PaymobPaymentInitiatorTest, PaymobPaymentScenarioTest.

## 8. Extension points — how to change/extend SAFELY

### Adding a new payment RAIL — no deploy (EG-11, 2026-08-21)

A rail is a **row**. Add it at `/admin/payment-methods`: a code (stored on every document, immutable
once saved), a bilingual name, the direction(s) it may be used in, and optionally the ledger account
its money lands in. Nothing else. The steps below described the world before that and were wrong the
day the catalogue shipped — every picker, filter, table cell, export and PDF now reads
`PaymentMethod::options()` / `::labelFor()`, gated by
`PaymentRailSurfacesReadTheCatalogueConformanceTest`.

Fawry, Meeza, Vodafone Cash and Aman already exist, switched off. Activating one is a tick.

### Adding a new GATEWAY (still code)
1. Create a `PaymobClient`-like wrapper (e.g. `StripeClient`) and a matching `*Initiator` service.
2. Register the S2S callback route in routes/web.php and create a new CallbackController or extend the existing one.
3. Add tests mirroring PaymobPaymentScenarioTest for the new gateway's state transitions.
6. **DO NOT break:** Invoice::recomputeTotals only counts `captured` payments—respect this so AR math stays correct.

### Changing late-fee logic
1. To change the NUMBERS, change no code: Settings → Billing for the portfolio default, `/admin/property-overrides` for one mall, or the lease's own columns for one tenant. `LateFeeService` reads them through `Lease::lateFeeTerms()`.
2. To change the FORMULA (`max(minimum, chargeable × percent)`), edit `LateFeeService::applyTo()`. There is deliberately no cap and no compounding — see EG-35 in [EGYPT-MARKET-FIT](../EGYPT-MARKET-FIT.md).
3. Add or update tests in LateFeeServiceTest to cover new thresholds.
4. **DO NOT break:** The service MUST remain idempotent (re-checking inside pessimistic lock); status must be set to overdue on apply.

### Adjusting AR balance computation
1. Edit Invoice::recomputeTotals() formula. Ensure:
   - Only `payments.status = 'captured'` allocations count.
   - `credit_applied_amount` is added (reflects credit notes that bypass the pivot).
   - Balance is max(0, total - paid), never negative.
   - Manual status overrides (disputed, cancelled, credited) are respected.
2. Add regression test to PaymentScenarioTest covering the new formula.
3. Backfill existing invoices if the formula changes materially (via migration).

### Adding a new notification recipient (e.g., mall admin CC on payment)
1. Edit PaymentReceivedNotification::via() to add a new channel (e.g. 'sms').
2. Implement the toX() method (e.g. toSms()).
3. Add tests to PaymentReceivedNotification test coverage.
4. **DO NOT break:** The notification must remain idempotent (guarded by receipt_notified_at).

### Extending allocation scoping (e.g., multi-property payments)
**RISKY.** The entire model assumes one Payment → one Tenant, one Tenant → multiple properties. Multi-property payments would require:
1. New foreign-key design (payment scoped to property, not tenant).
2. Tenant-spanning invoices or a new allocation dimension.
3. Updates to all guards: assertInvoicesShareTenant, tenantScopeRelation in PaymentResource, PaymentForm's tenant_id filter.
4. Retest all scenarios in PaymentScenarioTest + regression tests.
**Recommendation:** Avoid. If needed, design as a separate "consolidated payment" resource with its own lifecycle.

## 9. Gotchas, edge cases & recently-fixed bugs

### Credit Notes & AR Erasure (Regression: credit_applied_amount)
**Issue:** Credit notes applied to invoices were silent-erased when a payment recomputed the invoice (because recompute only sums the payments pivot, not credits).
**Fix:** Added invoices.credit_applied_amount column. Invoice::recomputeTotals() now includes it: `paid_amount = captured_payments + credit_applied_amount`. Backfill migration dedups existing paid_amount to separate captured payments from credit.
**Gotcha:** A payment recompute WILL overwrite paid_amount if it doesn't include credit separately. Always call recomputeTotals, not manual arithmetic.

### Paymob Session Stale Amount (Regression: session reuse)
**Issue:** PaymobPaymentInitiator reused an old session even after invoice balance dropped (e.g. partial payment landed), handing the tenant a gateway token bound to the OLD (higher) amount → overcharge.
**Fix:** findReusableSession() now checks `round(payment.amount, 2) === round(invoice.balance, 2)`. Balance drop → forced fresh session.
**Gotcha:** Payment.amount is a snapshot at initiation time; it does NOT auto-update when invoice balance changes. Reuse logic must check equality, not assume monotonicity.

### Payment Receipt Notification Timing (Regression: Create/Edit path)
**Issue:** The admin Create/Edit form allocated invoices AFTER the Payment was saved, so the Payment::saved hook's receipt notification fired against an empty allocations list (no invoices to notify about).
**Fix:** Introduced Payment::notifyReceiptOnce() (guarded by receipt_notified_at timestamp). Create/Edit after-hooks explicitly call it after syncing the pivot. The Payment::saved hook also calls it (for Paymob path consistency), but the timestamp guard makes double-notify impossible.
**Gotcha:** Receipt notification is NOT guaranteed to fire in the form flow—only after pivot is synced. Manual records without allocations won't notify (intended: no invoice to credit).

### Overpayment & Negative Balance
**Issue:** A payment allocation > invoice.total would leave balance negative, breaking AR reports.
**Fix:** Invoice::recomputeTotals() floors balance at 0: `balance = max(0, total - paid)`. Also, per-row and total allocation guards in the form reject over-allocation before persist.
**Gotcha:** If a manual DB insert bypasses guards, balance WILL compute correctly (floored), but paid_amount reflects the true allocation (can exceed total). The mismatch is visible in reports; this is correct (audit trail) but unexpected.

### Concurrency: Paymob Callback + Manual Edit
**Issue:** A callback captures a payment while an admin is editing it in the form, race-condition flip of status.
**Fix:** None built-in. Paymob callback is in a DB::transaction; form is transactional. Last-write-wins. Payment::saved hook recomputes invoices regardless (idempotent).
**Gotcha:** If a callback captures while the form is open, the user's form save may overwrite the callback's status flip. UX: form should re-fetch the payment before showing it. Test with race-condition harness if this is a problem.

### Late Fees & Invoices Marked Paid
**Issue:** The LateFeeService skips invoices where `balance <= 0` (paid/overpaid). If an invoice is manually set to `paid` status but balance > 0 (inconsistent state), it WON'T get a late fee even if past due.
**Fix:** Query checks balance, not status: `where('balance', '>', 0)`. Status inconsistency is a data issue, not a service bug.
**Gotcha:** Never manually set status without recomputing. Invoice::recomputeTotals() auto-sets status; manual overrides should be explicit (disputed, cancelled, credited).

### Cross-Tenant Allocation Detection (Audit M06 F-26)
**Issue:** A stale repeater row (user edit, loses focus, page refreshes) or an API client could allocate a payment to another tenant's invoice, bypassing the form's property-scoped tenant select.
**Fix:** Payment::assertInvoicesShareTenant() checks at the model layer; Create/Edit after-hooks call it before sync. Throws DomainException with the offending invoice number.
**Gotcha:** The guard is called AFTER form validation, so a UI alert may not fire (depends on Filament's exception handling). The transaction rolls back, but the UX is not great. Consider a live validation rule in the repeater.

### Double-Notify on Edit Re-save
**Issue:** An admin edits a Payment's allocations and saves twice; the PaymentReceivedNotification fires twice.
**Fix:** Receipt notification is guarded by `receipt_notified_at` timestamp. notifyReceiptOnce() checks it; if set, returns early. Only the first call sends the notification.
**Gotcha:** The timestamp is set via `forceFill(...)->saveQuietly()`, so observers don't re-trigger. A stale in-memory Payment object won't know the timestamp was set unless refreshed.

## 10. Tests & related modules

### Test Files

**Core Payment & AR Logic:**
- `tests/Feature/Scenarios/PaymentScenarioTest.php` — allocation math, demo path, state transitions, scoping, boundary cases (370 lines, 10+ cases).
- `tests/Feature/Regression/PaymentReceiptNotifyOnceTest.php` — receipt notification idempotency (100 lines).
- `tests/Feature/Regression/PaymentFormPropertyScopeTest.php` — cross-property IDOR fix (60 lines).
- `tests/Feature/Models/PaymentAllocationGuardsTest.php` — assertInvoicesShareTenant (54 lines).
- `tests/Feature/Models/InvoiceTest.php` — invoice model basics (recomputeTotals, status helpers).

**Gateway Integration:**
- `tests/Feature/Scenarios/PaymobPaymentScenarioTest.php` — voided success, validation, idempotency, persistence, scoping, notification, demo fan-out (370 lines, 9 cases).
- `tests/Feature/Regression/PaymobSessionStaleAmountTest.php` — session reuse guard on amount mismatch (106 lines).
- `tests/Feature/Services/Paymob/PaymobPaymentInitiatorTest.php` — initiator session reuse, window logic.
- `tests/Feature/Services/Paymob/PaymobClientTest.php` — API endpoint mocking, HMAC verification.
- `tests/Feature/Http/Paymob/CallbackControllerTest.php` — S2S callback, bad HMAC, unknown order, return redirect.

**Late Fees & Scheduled Tasks:**
- `tests/Feature/Services/LateFeeServiceTest.php` — grace period, idempotency, zero-balance skip (69 lines).
- `tests/Feature/InvoiceOverdueOwnerAlertTest.php` — ScanOverdueInvoicesCommand, concurrent execution.

**Form & UI:**
- `tests/Feature/Notifications/InvoiceAndPaymentNotificationsTest.php` — notification channels, content.
- `tests/Feature/Api/V1/Tenant/InitiatePaymobSessionTest.php` — portal Pay-Now endpoint.
- `tests/Feature/Portal/PortalDemoPaymentTest.php` — demo capture from portal.

### Online payment link & channels (2026-06-27)

Payments carry a **`channel`** (`payments.channel`): `payment_link` (public `/pay/{token}` page), `mobile_api` (the app), `portal` (tenant portal Pay Now), `admin`. Paymob **session reuse is scoped per channel**, and `CallbackController::returned()` routes the browser by channel — `payment_link` → the public status page `/pay/{token}/status`, everything else → the portal. The S2S capture + tenant notification are shared. The public link is surfaced via the admin/portal **"Payment link"** action and the mobile `invoice.payment_link_url`. **Apple Pay** is scaffolded (a separate `PAYMOB_APPLE_PAY_INTEGRATION_ID` + the `/.well-known/apple-developer-merchantid-domain-association` route), off until configured. Full runbook: **[docs/PAYMENT-LINK-APPLEPAY.md](../integrations/PAYMENT-LINK-APPLEPAY.md)**. Tests: `tests/Feature/PaymentLink/PaymentLinkFlowTest.php`.

**Revoking a leaked link.** `invoices.payment_link_token` is a bearer credential: 48 random chars, no login, **no expiry**. Anyone holding the URL can read the tenant, the line items and the amounts — which is the point (it has to work from an email on a phone), but it means a link that is forwarded, lands in a shared inbox or is screenshotted stays live forever. The remedy is **rotation**, not expiry: `Invoice::rotatePaymentLinkToken()` mints a new token and every previously-issued URL 404s on all three public routes. Surfaced as the **"Regenerate payment link"** action on the invoice table and edit page, gated on `invoices.edit` in *both* `visible()` and `action()`, confirmation-required, and written to `ops.log` as `invoice.pay_link_rotated` (a client reporting "the link stopped working" is otherwise unanswerable).

- It is **not** gated on `isPayable()` — a leaked link to a settled invoice still discloses the tenant and the amounts via `/pay/{token}/status`, so the remedy has to outlive payability.
- It is safe mid-checkout: the gateway session is keyed by Paymob's `order_id`, not by this token, so rotating never strands a payment already at the gateway.
- Expiry was rejected deliberately: it would silently kill legitimate links in already-sent mail and turn every late payer into a support call.
- Tests: `tests/Feature/Regression/PaymentLinkRotationTest.php`.

**Reading the link is `invoices.view`; revoking it is `invoices.edit` (fixed 2026-08-18).** The **"Payment link"** modal — the URL, the copy box, the QR — was gated on `config('integrations.paymob.enabled') && isPayable()`, while "Regenerate payment link" beside it was gated only on a token existing. On the shipped default (`PAYMOB_ENABLED=false`) that left the invoice screen offering revocation and nothing else: **the operator could kill a bearer credential they were not allowed to read.** The gate was wrong about its own premise — the token is minted for *every* invoice in `Invoice::creating`, and `InvoiceResource` publishes `payment_link_url` to the mobile app with no gateway check, so the URL is live and already in tenants' hands whether or not Paymob is on. Both admin surfaces (table + edit page) now show it whenever a token exists, and the modal states which of the three situations it is in: it can collect (hint + scan-to-pay QR), the gateway is off (link works, cannot collect), or nothing is left to collect (opens the status page). The QR renders only in the first case — a "scan to pay" code that cannot take a payment is a worse answer than saying so.

- The **portal** action keeps the old gate deliberately: a tenant looking at their own invoice gains nothing from a link to a page that cannot collect. The operator's need — see what the client holds, hand it over, revoke it — is a different question.
- Tests: `tests/Feature/Regression/PaymentLinkVisibleWithoutGatewayTest.php` (pairs each assertion with its opposite: the QR case, the settled case, and view-vs-edit on one screen).

**Settling a pay link with no gateway (`POST /pay/{token}/demo`, added 2026-08-18).** So the copied link can be clicked through end to end on a box that has no Paymob, the pay page offers a demo settle button in place of the card button. It is the **only route under `/pay` that writes money, and the only demo-pay surface with no actor behind it** — the portal asks `Portal::isAdmin()`, the mobile endpoint runs under a Sanctum tenant token, and this one has nothing but the bearer token in the URL. That widening was a deliberate call; four things bound it:

- **`DemoPayments::enabled()`** — the same single predicate the other two ask, so the route is dead on production whatever the config says, dead unless `DEMO_PAYMENTS_ENABLED` is explicitly set outside local/testing, and dead the moment a real gateway is wired. It is checked **before the token is resolved**, so a disabled box answers a flat **404** and the endpoint cannot be told apart from one that was never built (nor probed for which invoices exist).
- **Its own `throttle:6,1`**, outside the `/pay` group's 30/min — two `throttle` middlewares on one route share a request signature and their counts interfere, so it is registered separately.
- **`ops.log` as `invoice.demo_paid_via_link`** (warning), naming the invoice, the amount and the caller's IP. The other two paths can name a user; this request is anonymous by construction, so the log is the entire audit trail for a fabricated payment.
- **The button cannot be mistaken for the real one** — dashed amber, not the brand fill, with a note saying no card and no money are involved.

`RecordDemoPaymentAction::handle()` gained an optional `$channel`, and this path **must** pass `Payment::CHANNEL_LINK`: `status()` finds the payment behind a link by `where('channel', CHANNEL_LINK)`, so a null-channel capture would leave the status page reporting a paid invoice for **0.00** — broken in exactly the flow the feature exists to demonstrate. Null stays the default, so the portal and mobile callers keep recording no channel as before.

- Tests: `tests/Feature/PaymentLink/PayLinkDemoSettleTest.php` — the capture, the channel, and five refusals (production, flag off, gateway live, rotated token, already settled), each paired with a control. Verified by mutation: deleting the `DemoPayments` gate turns four cases red, dropping the channel turns one.

### Related Modules

- **[Invoices & AR](05-billing-invoices.md)** — Invoice creation, ETA submission, monthly billing. Invoices are the payment target; recomputeTotals drives AR.
- **[Credit Notes](07-credit-notes.md)** — Credit notes apply to invoices via credit_applied_amount column; folded into recomputeTotals.
- **[Leases](04-leases.md)** — Leases generate invoices; Invoice.lease_id FKs to Lease.
- **[Tenants](./02-tenants.md)** — Tenants are payment owners; Tenant.notifyPortal() fans notifications.
- **[Properties & Units](01-properties-units.md)** — Payment form is property-scoped; unit.asset_id filters TenantScope.

---

**Last updated:** 2026-06-27  
**Module code:** M06  
**Key decision points:** Audit M06 F-25, F-26 (allocation guards); M11 F-42 (Paymob session reuse).

---

## The payment-rail catalogue (EG-11, 2026-08-21)

A payment method is a **row** in `payment_methods`, not a PHP constant. It carries a code (the value
every document stores), a bilingual name, a direction, and the ledger account its money lands in.

**Why a row.** `ValueSets`' own docblock said it — *"Egypt's payment rails keep moving: Fawry, Meeza,
Aman, Vodafone Cash"* — while keeping them in a `const`, so adding one was a 9–14 file deploy. There
were also four parallel lists that had drifted (7 / 5 / 2 / 3 values), one of them outside
`ValueSets` entirely, which is why a security deposit received by InstaPay could not be recorded as
InstaPay. Fawry, Meeza, Vodafone Cash and Aman now ship **present and switched off** — a tick.

**Where the money lands.** `payment_methods.ledger_account_id` points at a chart row **directly**,
the way `bank_accounts.ledger_account_id` does — not at a `PostingRoles` key. A role exists so a code
path can ask for "the bank account" without knowing the chart; a rail is operator data pointing at
operator data. The decisive argument is mechanical: `Health::accountingReadiness()` requires every
`PostingRoles` key to be mapped, so a clearing role per rail would turn a **blocking** health row red
on every existing install until the accountant mapped them.

`App\Support\MoneyAccount::for()` is the ONE seam, and **all thirteen** journalizers resolve through
it (EG-12): the document's own `bank_account_id` first, then the rail's account via
`PaymentMethod::accountIdOrFloor()`, then the posting role. Null on both means the floor: `cash` for cash, `bank` for everything else, verbatim the
ternary each of them carried. So the catalogue ships behaviour-identical and an operator opts in one
rail at a time.

**Reading it back.** `App\Support\Filament\BankAccountColumn` and `…\BankAccountFilter` are the
read half of `BankAccountField`. The field shipped write-only — no column, no infolist entry, no
filter anywhere — so an operator could set the account and never see it again, and *"which documents
went through CIB?"* was unanswerable from any list. The column is on all six registers; the filter
is on the five standalone ones, since the vendor-bill payments relation manager has no filter bar of
its own. Both are toggled/optional, and the column needs no `with()` at any call site: Filament
eager-loads the relationship columns that are actually VISIBLE, so one toggled off costs nothing.

`DemoSeeder` registers two accounts on Atriom Walk — CIB operating and NBE service-charge — each on
its **own chart leaf under `11102 Banks`** (`11102002`, `11102003`), added beside the generic
`11102001` rather than instead of it, which stays the `bank` role for any document naming no
account. Neither may BE a posting-role account, and that is the whole point: pointed at
`11101001 Main Cashier` and `11102001 Bank Account` — the first two postable asset accounts by code
— CIB's receipts would land in the till and NBE would resolve to exactly what the floor already
picks, so the separation would be invisible on the trial balance and the matcher would still offer
one bank's postings against the other's statement. The register is also seeded the moment the
property exists rather than beside the other financial modules, because the invoice-history
generator and the current-month payment run both fire earlier — seeded late, almost every demo
receipt recorded no account at all. `DemoDataDemonstratesTwoBanksTest` pins both.

**What is still wrong, and why it is not a code problem.** A card capture debits the bank on the day
it is captured while the money lands T+1/T+2 (longer for Fawry), so the book line and the bank line
carry different dates and the reconciliation sees them unmatched. The fix is a clearing account per
rail — the mechanism is here, the account codes are the accountant's, and the real Egyptian chart has
not been supplied.

**One invariant this created.** `ValueSets` answers "what may this column hold" twice: `allowed()`
for what a picker OFFERS, `forTable()` for what the saving listener ACCEPTS. While every set was a
literal they could not disagree; a catalogue makes one dynamic. Both now derive from one `widen()`,
and `OfferedValuesAreAcceptedValuesConformanceTest` fails if they ever drift — because when they did,
the deposit modal offered eight rails and the guard took two, and the operator saw a button do
nothing.


## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `Payment` | **Never deletable** | void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice |
| `TenantCreditApplication` | Deletable (super_admin) | parent-managed: soft-deleted to reverse an applied tenant credit |
