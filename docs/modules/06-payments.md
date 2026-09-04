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

> **⚠️ A refused allocation left an orphan RECEIPT behind (fixed 2026-09-02).**
>
> `CreatePayment::afterCreate()` synced the invoice allocations *after* Filament had already
> committed the payment row, and compensated for a refusal with `$payment->delete()`. **That delete
> cannot work.** `RefusesDeletionOfCommittedRecords` refuses anything `isReceived()`, and the payment
> form **defaults to `captured`** — measured: `delete()` on a captured payment throws, on an
> `initiated` one succeeds. So the compensation threw its own `DomainException`, the operator was
> shown the DELETION refusal instead of the allocation error they had actually hit, and the orphan
> survived: a captured receipt with no allocations, which reads as unallocated tenant credit and
> invites the operator to key the payment a second time.
>
> CLAUDE.md states that an uncommitted record stays deletable *"which is what keeps `CreatePayment`'s
> orphan rollback working"*. The rule is right; the claim about this caller was not, because nothing
> here is uncommitted by the time the hook runs.
>
> The create is now ONE unit of work, through **Filament's own** transaction
> (`protected ?bool $hasDatabaseTransactions = true`). `CreateRecord::create()` already rolls back
> and re-throws on any Throwable; it was simply inert, because `CanUseDatabaseTransactions` defers to
> the panel and **no panel here opts in** — which means every other Create/Edit page that throws in
> `afterCreate`/`afterSave` still commits its record (recorded as SW-003d).
>
> **The first cut hand-rolled the wrapper and was worse**, caught in review: it swallowed the refusal
> to keep the operator's form, which left `$this->record` pointing at a row the rollback had just
> removed — `exists` is true and `id` is set, because a rollback does not touch PHP object state — so
> Livewire dehydrated it with a key and the operator's very next keystroke 404'd on `firstOrFail()`.
> Letting the refusal propagate destroys and rebuilds the component, which is how every other refusal
> in this app already behaves.
>
> **The receipt e-mail moved to `DB::afterCommit()`.** `assertInvoicesNotOverAllocated()` holds
> `lockForUpdate()` on the invoice and four settlement tables, and `PaymentReceivedNotification` is
> **not** `ShouldQueue` — its mail channel sends synchronously, per portal user. Under the outer
> transaction the inner one is only a SAVEPOINT, and releasing a savepoint does not release row
> locks, so every other capture, credit-note application, deposit netting or write-off against that
> invoice would have queued behind an SMTP round-trip.
>
> **Reachable in ONE request, no concurrency** — the comment claiming a parallel capture was wrong.
> `PaymentForm` caps each allocation ROW independently while this hook SUMS rows against the same
> invoice, so 700 + 600 on a 1,000 invoice passes every form gate and is refused only here
> (SW-003b). (`AnOrphanReceiptIsRolledBackNotDeletedTest` drives the real page through exactly that.)

### Online payment link & channels (2026-06-27)

Payments carry a **`channel`** (`payments.channel`): `payment_link` (public `/pay/{token}` page), `mobile_api` (the app), `portal` (tenant portal Pay Now), `admin`. Paymob **session reuse is scoped per channel**, and `CallbackController::returned()` routes the browser by channel — `payment_link` → the public status page `/pay/{token}/status`, everything else → the portal. The S2S capture + tenant notification are shared. The public link is surfaced via the admin/portal **"Payment link"** action and the mobile `invoice.payment_link_url`. **Apple Pay** is scaffolded (a separate `PAYMOB_APPLE_PAY_INTEGRATION_ID` + the `/.well-known/apple-developer-merchantid-domain-association` route), off until configured. Full runbook: **[docs/PAYMENT-LINK-APPLEPAY.md](../integrations/PAYMENT-LINK-APPLEPAY.md)**. Tests: `tests/Feature/PaymentLink/PaymentLinkFlowTest.php`.

**Revoking a leaked link.** `invoices.payment_link_token` is a bearer credential: 48 random chars, no login, **no expiry**. Anyone holding the URL can read the tenant, the line items and the amounts — which is the point (it has to work from an email on a phone), but it means a link that is forwarded, lands in a shared inbox or is screenshotted stays live forever. The remedy is **rotation**, not expiry: `Invoice::rotatePaymentLinkToken()` mints a new token and every previously-issued URL 404s on all three public routes. Surfaced as the **"Regenerate payment link"** action on the invoice table and edit page, gated on `invoices.edit` in *both* `visible()` and `action()`, confirmation-required, and written to `ops.log` as `invoice.pay_link_rotated` (a client reporting "the link stopped working" is otherwise unanswerable).

- It is **not** gated on `isPayable()` — a leaked link to a settled invoice still discloses the tenant and the amounts via `/pay/{token}/status`, so the remedy has to outlive payability.

> **⚠️ WHICH invoice a tenant may pay, and HOW MUCH, each had four answers (fixed 2026-09-01).**
>
> **WHICH.** `Invoice::isPayable()` was a hand-rolled denylist of `cancelled|credited|written_off`
> plus `balance > 0`, sitting beside `App\Support\InvoiceSettlement` — the register built for that
> exact question, carrying a written reason against every status on both sides of its partition. It
> missed **`draft`**, which `InvoiceSettlement` had refused since the day it was written (*nothing
> was ever posted, so cash against a draft credits a receivable that does not exist*). Reproduced
> over the real route before the fix: a draft invoice answered **200** at `/pay/{token}` to an
> unauthenticated visitor, naming the tenant and the amount, and would have taken the money. That is
> an eighth surface for the *"a draft is not a document"* invariant, and the only one with no login
> in front of it.
>
> The portal's View page then held two more opinions three lines apart: `canPayDemo()` repeated the
> same three statuses, and **`canPayNow()` — the one that opens a LIVE Paymob checkout — tested no
> status at all**, so a written-off invoice offered real card payment while the fake button beside it
> correctly refused. The permissive branch is always the one that spends money, because the careful
> author guards the path they are thinking about.
>
> **HOW MUCH.** Every path charged the raw `balance`. A write-off deliberately leaves `balance`
> standing — that is what keeps it visible on the document — so a 10,000 invoice with 6,000 forgiven
> asked the tenant for **10,000**, on the public page, in the Paymob session, in the pivot's
> `allocated_amount`, in the session-REUSE comparison, in the ops log and in the demo capture.
> Collecting it drives AR negative for that debt and leaves bad-debt expense standing against cash
> that arrived — the permanently red `billing:reconcile --deep` that blocks the next deploy.
> `Invoice::payableAmount()` is now the one amount, over `InvoiceSettlement::settleableAmount()` —
> which already answered this and was already load-bearing on **seven** call sites (the payment
> form, tenant credit, credit notes, post-dated cheques). **It was applied on every channel an
> OPERATOR drives and on none a TENANT drives**, which is the more useful way to state the gap: the
> netting went in beside the code whose author was thinking about write-offs, and the pay link, the
> portal and the mobile API were each written by somebody thinking about a gateway. It composes with the write-off rather than fighting it:
> paying 4,000 leaves `balance` at 6,000, which the write-off has already relieved, so
> `collectableBalance()` reads 0 and the document is finished from both sides.
>
> Two things that must move WITH the amount. The **session-reuse comparison**, or every session for a
> partly written-off invoice is discarded as stale for ever and the tenant gets a fresh gateway hop
> on every click. And the **eager load**: `isPayable()` became a per-row aggregate the moment it
> started netting write-offs, so `settleableAmount()` prefers a loaded `writeOffs` exactly as
> `collectableBalance()` does, and the portal's invoice table loads it.
>
> (`APublicPayLinkCannotCollectWhatIsNotOwedTest` — every refusal paired with a control, because a
> route that redirected everything would satisfy the refusals alone and read as a pass.)
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

**Asked, defaulted, required — and all three or none (2026-09-02).** EG-12 shipped the field
**optional on every form with no default**, so on a real install almost every document named nothing,
every posting fell to the generic `bank` role, and the separation above stayed theoretical. Yardi
makes the cash account **mandatory** on every money movement, and it is liveable there for one
reason: the **property carries default cash accounts** (operating · security-deposit trust ·
reserve), so a receipt arrives with its bank filled in and the operator *confirms* rather than
chooses. **Required without a default is the worst half of that design** — somebody picking the same
value three hundred times a month eventually picks the wrong one, and a wrong bank account is worse
than none, because `MatchBankStatementLineService::candidatesFor()` finds candidates BY the chart
account and presents the mistake as a real match against the wrong statement.

So three things ship together. **`payment_methods.requires_bank_account`** is whether naming an
account is part of recording money on this rail — a ROW, because `RecurringExpenseForm` was the one
screen that had ever asked and it answered with a hardcoded `!== 'cash'`, which is a filter written
twice and wrong the day the operator activates Fawry. A rail with **no row falls to `code !== 'cash'`
— verbatim the ternary `accountIdOrFloor()` applies** — which is load-bearing rather than tidy:
`payrolls.paid_from`, `expenses.paid_from` and `deposit_transactions.method` all accept the legacy
literal **`bank`**, a `ValueSets` member that has never been a `payment_methods` code, so reading
"no row" as "no requirement" would exempt the most obviously bank-borne value in the system. The
lookup uses `array_key_exists`, not `??`: "the operator said no" and "there is no row" are both
falsy, and confusing them makes `requires_bank_account = false` unsettable for every rail but cash.

**`bank_accounts.purpose`** (`operating` · `deposits` · `payroll`) is Yardi's own split, and
`deposits` is the row that earns the column — a security deposit is money the operator HOLDS
(`deposits_held` is a liability), not working cash. Egypt mandates no trust account, so it is a
facility rather than a rule: leave every account `operating` and the ladder falls back to operating,
which is what a mall without a separate account actually does. There is deliberately **no
`cash`/`till` purpose** — a petty-cash box is the `cash` posting role, and a row here would sit in
the reconciliation register waiting for a statement that never arrives.

**`bank_accounts.is_default`** is which account a new document fills itself in with.
`BankAccount::defaultFor()` is the ladder: this purpose → the default **operating** account → **the
only active account there is** (one account is not a choice) → nothing, which is verbatim today's
behaviour. One default per (property, purpose), kept by demoting the previous holder on write and
**not by an index** — MySQL has no partial unique index, and a plain one would forbid a second
operating account outright, which is the situation EG-12 exists for.

`App\Support\Filament\BankAccountField::for($model)` takes the document CLASS because the document
declares both its purpose and the column naming its rail (`RecordsBankAccount::bankAccountPurpose()`
/ `::bankAccountRailColumn()`), so the picker and the model-level default cannot disagree. The
requirement is conditional **twice** — the rail says an account is part of the record, AND the
property has one to offer; an install that has not reached the register must still be able to record
a receipt, and `ConfigurationHealth::bankAccountDefaultsSet()` raises the gap as the advisory it is
(only where a property banks in **more than one place** with no default — one account and no account
are both legitimate, and a row that is red on a healthy install is one people stop reading).

**The field is deliberately NOT hidden on a cash rail.** The obvious `->visible()` is wrong twice: a
hidden Filament field is not dehydrated, so a document switched from transfer to cash would silently
KEEP the account it names while the picker vanishes — and `bank_account_id` is classified DERIVED,
so clearing it to compensate would void and re-post the entry.

**The hook is on `creating`/`updating`, never `saving`, and that fixed a shipped hole.** A trait's
boot method runs during `bootTraits()`, before the class's own `booted()` — so a `saving` listener in
a concern fires *before* the model derives its own property, and `DepositTransaction` derives
`asset_id` from its lease in exactly such a hook. Measured: a deposit receipt was never defaulted,
and the **cross-property guard skipped it too**, so a deposit receipt naming another mall's bank
account was being accepted. `RecurringExpense` also joined the concern the same day — it grew the
column on 2026-09-02 with no relation, no guard and a bare `EntitySelect`, so a schedule could name
another mall's account and stamp it onto every cost it generated. That made the sweep **seven**
documents, not six. (`ABankRailSaysWhichAccountItMovedThroughTest`, mutation-proved both ways.)

**EACH BANK ACCOUNT GETS A CHART ACCOUNT OF ITS OWN (2026-09-02) — the market standard, and the
thing that makes any of this reconcilable.** Yardi's Bank record points at one cash GL account and
its reconciliation is OF that account; NetSuite, QuickBooks and Odoo each make a bank account its own
GL account, and **Odoo creates the account for you when you add the bank**. The mechanical reason is
`MatchBankStatementLineService::candidatesFor()`, which finds candidates with
`where('ledger_account_id', …)` — two banks on one chart account means reconciling CIB **offers
NBE's postings**, and a wrong match that still balances marks money verified.

**A POSTING ROLE account is refused too, and it is the subtler half.** The `bank` role is where every
document naming NO bank account lands — the floor in `MoneyAccount` — so pointing a real bank at it
merges *"money we know went through CIB"* with *"money nobody attributed"*, and every unattributed
posting becomes a CIB candidate. `DemoSeeder` had been careful about exactly this since the register
was first seeded (*"neither may BE a posting-role account, and that is the whole point"*) and **the
FORM was not**, which is how a real install ended up with its only bank on `11102001 Bank Account`.
Any role is refused, not just `cash`/`bank`: a bank pointing at the AR control account is a worse
version of the same mistake.

**Refused only on a DIRTY write.** Every install predating the rule has a bank mapped somewhere,
quite possibly at the role account — refusing on every save would make those rows uneditable, so the
operator could not rename their own bank without first solving a chart problem. That is the trap
CLAUDE.md records for `#[NeverDeletable]`. `ConfigurationHealth::bankAccountsHaveTheirOwnAccount()`
reports the existing ones as the **advisory** they are (the books are correct; the reconciliation is
merely ambiguous), and a bank naming NO chart account is deliberately not a finding — that is a
different, earlier question and a legitimate state.

**A SERVICE and not a method on `BankAccount`** — it WRITES a different aggregate, a row in the accountant's own chart, which is business logic rather than something a bank account knows about itself; `BankAccount::defaultFor()` stays on the model because it only READS. **`MintBankLedgerAccountService` is Odoo's half**, offered from the ledger-account picker's
*create* option, and it is what makes the rule a help rather than an obstacle. It anchors on **the
parent of the `bank` role account** — this install's own answer to *where do we keep banks in the
chart* — never a literal `11102`, because the real Egyptian chart has not been supplied and any
hardcoded code would be a guess about somebody else's numbering. **The code width comes from the
siblings**, so an 8-digit chart and a 10-digit one each get a leaf that looks like its neighbours;
that question is still open in `docs/STATUS.md`, and deriving it is how this survives the answer. It
returns **null** rather than inventing a home when the `bank` role is unmapped. The picker
**suggests** rather than filters — a hard clause is the write guard and Filament resolves a submitted
value's LABEL through it, so excluding taken accounts would make the row that HOLDS one fail its own
validation. (`EachBankAccountGetsItsOwnChartAccountTest`, all three teeth mutation-proved, including
the lockout the dirty check prevents.)

**A DOCUMENT HAS MORE THAN ONE DOOR, and the first pass only reached six of eight.** A deposit
movement is recorded from the deposit register **and** from the lease's own Security deposit tab —
and the tab is where an operator actually records one. `LeaseActions::recordDeposit()` did not get
the field, so every deposit taken from the lease page recorded no bank account at all. **Reported
from the panel, not by the suite**, a day after a change whose entire subject was that column, and
with a suite full of green deposit tests — because every one of them drove the model or the register.
CLAUDE.md already stated the rule, about this very pot: *enumerate the doors onto a pot by grepping
the pot, never from the diff that fixed one of them.* **A sentence is not a gate.**

`App\Support\MoneyDocumentDoors` is the gate. **A door is DERIVED, never listed**: a Filament schema
that collects the document's rail as a FORM FIELD — `Select::make('method')` / `('paid_from')`, the
column that document itself names — because that is the observable signal a screen is *recording*
money movement rather than listing it (a `TextColumn` or a `SelectFilter` reads the same column and
records nothing). A registry of doors would go stale the moment somebody adds a screen, which is the
failure being caught, so it must not rest on the same person remembering. Attribution is by whether
the file NAMES one of the seven documents — not an exemption list — so the petty-cash screens that
legitimately collect a rail and have no bank account (`Custody`, employee advances, marketing spend)
are correct *by being what they are*, and a new one is too. Eight doors found; both teeth
mutation-proved.

**Two more guards, generalised beyond the bank account (2026-09-02).** `MoneyDocumentDoors::disagreements()`
asks whether the doors onto one document ask the same money questions — **compared to EACH OTHER,
never to a spec**. The alternative was measured and rejected: requiring every door to collect every
column `ChangeImpact` calls DERIVED makes `lease_id`, `tenant_id`, `asset_id` and `status` a finding
on every door that legitimately derives them — about forty entries, a list exempted into
meaninglessness. Comparing doors to each other asks the question that actually failed. The union is
narrowed to DERIVED/REFUSED columns, so a `notes` box on one screen and not the other is not a
defect, and it is **silent for a document with one door** — correct rather than a hole, since it arms
itself the moment somebody adds the second. Two derivations are registered with reasons: the lease
modal knows its own `lease_id`, and `is_opening_balance` is a CUTOVER flag the day-to-day modal must
not offer.

`App\Support\ModalFieldReach` is the other end: an action that builds its row inline must keep what
it collected. **Tokenised, never grepped** — `LeaseActions` declares thirteen actions in one file, so
a file-wide comparison reported a 29-name list of noise and two false positives elsewhere; the
question is only meaningful per ACTION, which means finding each chain's balanced extent. **The `::`
trap:** PHP returns `T_DOUBLE_COLON` as an ARRAY, not the string `'::'`, so the first cut scanned
**zero** actions and reported a clean sweep — this codebase's signature failure, a gate reporting on
a set it never collected, which is why the third test asserts the sweep found something. Five inline
builders today, no gaps; both guards mutation-proved to name the exact screen and field.

**The second tooth is the field that records nothing.** A door that builds its row inline must pass
`bank_account_id` through — a field on a modal the write does not carry renders, validates, and saves
none of it, which is *worse* than not offering it, because the operator has been told the answer was
taken. Already pinned for the two SERVICE writers; this is the same trap one layer up.

**Two traps in testing this.** The action's schema is a CLOSURE, so a test that enumerates actions
proves nothing about what their modals contain — drive the action. And the behavioural test's first
version **passed with the write deleted**: it named the property's *default* account, which
`RecordsBankAccount` fills in on create, so the row was right for a reason that had nothing to do
with the modal. It now names a second, non-default account, so the fallback cannot stand in for the
thing under test. **Billing a deposit is deliberately NOT a door** — `BillSecurityDepositService`
raises an INVOICE, and the bank account arrives with the payment that settles it.

**Reading it back.** `App\Support\Filament\BankAccountColumn` and `…\BankAccountFilter` are the
read half of `BankAccountField`. The field shipped write-only — no column, no infolist entry, no
filter anywhere — so an operator could set the account and never see it again, and *"which documents
went through CIB?"* was unanswerable from any list. The column is on all six registers; the filter
is on the five standalone ones, since the vendor-bill payments relation manager has no filter bar of
its own. Both are toggled/optional, and the column needs no `with()` at any call site: Filament
eager-loads the relationship columns that are actually VISIBLE, so one toggled off costs nothing.

**The empty-mall seeders lay one down too (2026-09-02).** `LearningSeeder` — and therefore
`ValPlazaSeeder`, which extends it — seeds ONE operating bank account, default for its property, on
a chart leaf minted through `MintBankLedgerAccountService`. A bank account is SETUP, like the
chart and the posting map that seeder already lays down, and it adds no numbers to the screen, so it
does not breach that seeder's rule. Without it the first receipt recorded in a demo falls to the
generic `bank` role, the money forms' bank picker is empty, and the requirement that a bank rail
names its account lifts itself for want of anything to offer — the demo would show the
pre-2026-09-02 behaviour with nothing on screen to say so. Minting rather than hardcoding means no
chart code is written into a seeder and the arrangement is one the running system would actually
produce, so a bug in the minting shows up on the demo books instead of hiding behind seeder-specific
wiring. (`AnEmptyMallStillBanksSomewhereTest`.)

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

## The document a tenant receives (2026-08-27)

**Language.** The PDF is written in the language its READER reads, not its sender's. It rendered in
`app()->getLocale()` — the operator's panel language, or `config('app.locale')` for a scheduled run
— so an operator working in Arabic sent Arabic documents to tenants whose accountants file in
English. `App\Support\Pdf\DocumentLocale::resolve()` now answers, in order: what the operator picked
on the download modal → the tenant's own `locale` → the current request → the app default. The
download button carries the picker (`App\Support\Filament\PdfDownloadAction`), pre-selected to the
tenant; `/api/v1` takes `?lang=`; the e-mailed copy follows the TENANT, because a tax document is
addressed to the company and must not vary with which portal login happened to be notified.

**Typesetting.** Set in **Direction D** — a full-bleed navy band carrying the mall's identity,
the balance in an amber panel of its own — chosen from four directions drawn side by side in both
languages. Built on the shared shell (`resources/views/pdf/layout.blade.php`, `_styles`, `_issuer`)
and rendered by `App\Support\Pdf\PdfDocument` — the only thing in the app that
constructs mpdf. It carries a running footer with the document's own reference and `page x of y`,
and a cancelled or voided one is watermarked. Do NOT add an `@page` rule to the template: page
geometry belongs to the renderer, and a template that sets its own margins leaves no room for the
footer, which then renders nowhere at all.

**Free text.** Anything a person typed — a party name, a line description, notes — is fenced with
`App\Support\Pdf\Bidi::isolate()` so it keeps its own direction inside a document written in the
other one. Without it an Arabic document renders an English sentence as `.Issued in error`.

See [OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference) for the
whole rule, and `ADocumentIsWrittenInItsReadersLanguageTest` for what is pinned.


> **⚠️ "RECORD PAYMENT" BUILT A 404 (SW-175, fixed 2026-09-02).** `tenant` is **Filament's own
> tenancy ROUTE parameter** — the mall's segment in every admin URL — so
> `PaymentResource::getUrl('create', ['tenant' => $tenantId])` did not append a query string: it
> substituted the RETAILER's id for the MALL's slug and produced `/admin/{tenantId}/payments/create`.
> The operator got a 404, and `CreatePayment::fillForm()`, which reads `request()->query('tenant')`,
> was looking for a key the URL never carried.
>
> **Two producers had it and the sweep row named one** — the collections worklist AND the tenant
> hub's own *Record payment* button, which is the daily loop the prefill was built for (call the
> tenant, they say they paid, record it). The parameter is `for_tenant` now, which is not a route
> parameter of any panel, so Filament appends it to the query string where `fillForm()` looks.
> (`RecordPaymentLandsOnTheFormWithTheTenantTest`.)

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-012

**WHICH PROPERTY'S MONEY A RECEIPT IS, IS ONE DEFINITION (SW-012, 2026-09-03).** `payments` carries no `asset_id`: a receipt belongs to the property whose debt it clears, so the dimension comes from the invoices it settles, with the CHEQUE it came from as the fallback for a receipt that settles nothing yet. That predicate was written out three times — `Tenant::creditBalance()`, `App\Support\TenantBalances`, and the tenant hub's Payments tab — and the third had drifted to `whereHas('invoices.lease.unit')`, a chain from before unit owners existed. Measured on `mall_management_qa` (2026-09-03): **42 of 42** unit-owner assessment invoices carry `lease_id` NULL — `UnitOwnership::invoiceLinkAttributes()` returns `lease_id => null` and `Invoice::assertBelongsToExactlyOneAgreement()` enforces it — so the chain matched **none** of them, and every receipt settling an owner's صيانة assessment vanished from that owner's own Payments tab for any property-restricted operator. So did every UNALLOCATED receipt, i.e. every cleared series cheque. **Nothing was loud**: the Payments REGISTER listed the same receipt throughout, because it scopes from `#[PropertyOwned(via: 'invoices')]` — one hop, the invoice's own column — so the money was on one screen and not on the other. `Payment::scopeInProperties()` is the one home and all three read it; **the OR branch is GROUPED** because AND binds tighter, and written flat the no-allocations branch escapes the caller's own `tenant_id`/`received()` clauses, which is a wider leak than the one being closed. The registry chain is deliberately still the narrower `via: 'invoices'` — widening the RESOURCE's list to unallocated cheques is a change to a reported set, not to this tab's question. (`AnOwnerReceiptIsOnTheirPaymentsTabTest`.)


### SW-026

**A TYPED FIELD MUST FIT THE COLUMN IT IS STORED IN, AND THE SUITE CANNOT SEE WHEN IT DOES NOT (SW-026, 2026-09-03).** `payments.gateway`, `gateway_transaction_id` and `cheque_number` are `varchar(255)` (measured on `mall_management_qa`) and `PaymentForm` bounded none of them — the only three free-text string inputs on the form. Laravel ships MySQL `strict => true`, so the database answers `SQLSTATE[22001] Data too long for column` (reproduced with 300 characters on that server): a **500 on Create**, not a field error, so the operator loses the amount, the date and every allocation they had keyed. The realistic route is pasting a long bank reference into a box that invites free text. **Green said nothing because SQLite does not enforce a varchar width at all** — the same class as the dropped CHECK constraint and the `select tbl.*, x, *` that only MySQL rejects: a rule the test driver cannot express has to be declared on the FORM to exist here at all. `PostDatedChequeForm` had it right the whole time (100 and 200, matching its own narrower columns), which is what makes this an omission rather than a convention. No new lang keys: Laravel's `max` message is already in both catalogues. Note `maxLength` does NOT trip `BoundedFieldsExplainTheirLimitConformanceTest`, which reads `minValue`/`maxValue` only — a length that equals the storage width is not a rule about the business and needs no help text.


### SW-206

append to the section **The payment-rail catalogue (EG-11, 2026-08-21)** (line 675):

**A retired code is still a code the COLUMN may hold (SW-206, 2026-09-03).** `ValueSets` widened every catalogue-backed column from `IsCodeCatalogue::codes()`, which was `is_active = true` only — so retiring an operator-added rail, expense category, request subcategory, retail category, violation category or vendor document type removed it from the set the saving listener ACCEPTS, not merely from the set the pickers OFFER. A plain edit survived by accident (`guard()` short-circuits on `! isDirty()`), but every path that re-states the column was refused at the model layer. Measured: `ExpenseCategorySeeder` ships five Egyptian overheads switched off, so "activate it, file costs under it, retire it" is the ordinary life of a row; retire one that a `recurring_expenses` schedule names and `expenses:generate-recurring` refuses that schedule for ever — `Expense::create(['category' => …])` makes the column dirty, the guard throws, the failure is caught per schedule and the command exits non-zero every night while the levy never books. A catalogue answers two questions and already had two methods for them — `options()` is *what may be filed NOW* and `filterOptions()` is *what may already be filed* — and `ValueSets`, being the replacement for the DB enum, asks the second one; it was calling the first. `codes()` now returns every row, active or retired, and nothing else moved: `catalogueOptions()` still skips any code that has a row, so `is_active` goes on deciding every picker exactly as before. `widen()`'s own docblock had asserted this property in writing (*"it must never invalidate the documents that already name it"*) while not having it — the comment was the bug report. Pinned by `ARetiredCategoryStillBooksTheCostsFiledUnderItTest`, mutation-proved three ways.


### SW-116

append to the end of the `## The payment-rail catalogue (EG-11, 2026-08-21)` section:

### A form opens on a rail the catalogue still offers (SW-116, fixed 2026-09-04)

EG-11 made the OPTIONS a row and left the DEFAULT a literal. Measured at HEAD 2026-09-04, nine rail pickers read `PaymentMethod::optionsFor()` and **seven of them stated `->default('cash' | 'bank' | 'bank_transfer' | Disbursement::METHOD_BANK_TRANSFER)`** beside it, so the option list moved with the catalogue and the default did not. Retire the rail a form defaults to and Filament — which derives a Select's `Rule::in` from the options it resolved and cannot label a value it was not offered — renders the field EMPTY while its state still carries the retired code, and the operator is refused as *invalid* on a field they never touched. That is the 2026-08-18 deposit bug (a form whose own default stopped being one of its options), reopened by the operator's own act of retiring a rail; `CatalogueAwareSelect` cannot rescue it, because it keys on the RECORD's table and these are create-shaped action modals whose record has no rail column — the gap already recorded as SW-205. Probe: with the `bank_transfer` row deactivated, `optionsFor('disbursements.method')` answers `[cash, cheque, card, instapay, other]`. **`PaymentMethod::defaultFor($column, $preferred)`** asks the SAME list the picker renders and answers **null** once the preferred rail is gone — never a substitute rail, because the rail decides which chart account the entry lands in (`accountIdOrFloor()`) and picking one for the operator would put money on a channel nobody chose. `DepositTransaction::defaultMethod()` is its deposit twin, beside `methodOptions()`. `bank` is floor-only (no `payment_methods` row has ever had that code), so the two deposit call sites are behaviour-identical and were routed anyway, which leaves the gate with no exemptions. (`APayoutRailDefaultsToOneTheCatalogueStillOffersTest`, three teeth mutation-proved.)

### SW-025

**A rail has to move money in SOME direction (2026-09-04).** Both toggles default true on the form and in `$attributes`, and nothing stopped an operator un-ticking both — which produced an ACTIVE row that no picker will ever offer and no document can ever name. `inboundCodes()` and `outboundCodes()` each filter on their own flag, `optionsFor()` picks one of them per column, and `ValueSets` widens a column from the same two readers, so the rail is absent from all seven money columns AND would be refused by the saving listener even if a crafted payload sent it — while the register beside it goes on rendering **Active**, the one word that says the opposite. This is the inert-configuration shape recorded in `project_settings_screen_inert_bug`, in its quietest form: the screen shows the operator's decision took effect and nothing anywhere shows that it did nothing. **Retiring a rail is `is_active`, and the two are not the same act** — an inactive rail is still labelled on every document that names it (`IsCodeCatalogue::labelFor()` reads inactive rows on purpose) and is still offered by `filterOptionsFor()` so those documents stay findable; both directions off is not a retirement, it is a row that means nothing, which is why the refusal names `is_active` as the escape. Guarded on the MODEL (the seeder, a console command and a future importer never see a form rule) and echoed as ONE shared rule on both toggles so the field error and the toast read the same sentence. **Only on a DIRTY write**, the same reasoning `BankAccount::assertLedgerAccountIsItsOwn()` gives: refusing on every save would make an already-inert row uneditable, which is the `#[NeverDeletable]` trap. Measured on `mall_management_qa` 2026-09-04: 0 of 11 rails have both directions off, and `PaymentMethodSeeder` writes none, so nothing an install already holds is affected. (`APaymentRailMustMoveMoneySomewhereTest`, mutation-proved four ways including the lockout and a guard that refuses everything.)

### SW-027

**The payment register's status filter offered 4 of the 9 statuses the column accepts** — `initiated`,
`authorized`, `settled`, `bounced` and `voided` were unfilterable, each rendered in colour by the
`status` column a few lines above. `voided` is the one that matters most: it shipped 2026-08-28 as
the status that says money was **not** returned (as against `refunded`, which says it was), and it is
in no worklist tab either, so nothing on the register could name it — the reversal you most need to
find was the one you could not select. Now `StatusOptions::for('payments')`. Full reasoning in
[modules/05 → SW-027](05-billing-invoices.md); regression test
`ARegisterFilterFindsEveryStatusItHoldsTest`.

### SW-238

**`reconciled` and `settled` were offered on the status field and nothing has ever written them.**
The column documents them as *"matched in accounting"* and *"final"* — and bank reconciliation,
`MatchBankStatementLineService`, writes a `BankMatch` row and never touches `payments.status`.
Nothing in `app/` writes either value; nothing reads them apart from membership in
`RECEIVED_STATUSES`, which `captured` already satisfies. So an operator marked a receipt
**Reconciled**, believed it had been matched to a bank statement, and it meant nothing to any
consumer — a lifecycle outcome offered as a decision with no act behind it, which is exactly why the
reversal statuses (`refunded` / `failed` / `bounced`) were taken off this field and routed through
the reason-gated Void action. A received payment now has one target: `captured`. **The vocabulary
stays** in `ValueSets` and in both lang files — `RECEIVED_STATUSES` must go on counting a
`reconciled` receipt as money in, or removing the OPTION would quietly un-pay every invoice such a
payment settles — and a record already carrying one still renders and still saves. When the
reconciliation screen is given a status to write, this is the list it re-joins. Full reasoning in
[CHANGE-IMPACT-PLAN §16](../accounting/CHANGE-IMPACT-PLAN.md#16-the-ui-sweep-2026-09-05--a-status-is-the-outcome-of-an-act-and-an-act-is-on-the-record);
regression test `APostedDocumentsStatusIsNotAPickerTest`.
