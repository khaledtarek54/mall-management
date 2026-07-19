# Payments & Allocation

> System for recording tenant payments against invoices, tracking AR balances, integrating with Paymob gateway, and managing late fees.
>
> **Plain-language companion:** [docs/business-model/06-payments.md](../business-model/06-payments.md) — how payments work with worked scenarios.

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
| `payments`      | `Payment` | `id`, `reference` (string, unique), `tenant_id` (FK), `amount` (decimal 12,2), `currency` (string 3, default EGP), `method` (enum: card, bank_transfer, instapay, wallet, cash, cheque, other), `status` (enum: initiated, authorized, captured, reconciled, settled, failed, refunded, bounced, default captured), `payment_date` (date), `gateway` (string, nullable; e.g. "Paymob", "demo"), `gateway_transaction_id` (string, nullable), `gateway_response` (json, nullable), `cheque_number` (string, nullable), `cheque_clearance_date` (date, nullable), `notes` (text, nullable), `received_by` (FK → users, nullable), `receipt_notified_at` (timestamp, nullable), timestamps, softDeletes | Core payment record. Scoped to a single tenant. |
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
paid_amount = SUM(received_payments.allocated_amount) + credit_applied_amount
balance = MAX(0, total - paid_amount)   /* never negative */
```

- Only payments in `Payment::RECEIVED_STATUSES` (captured / reconciled / settled) count. Initiated, failed, refunded, bounced allocations have zero effect on AR.
- Credit notes increase `credit_applied_amount` (tracked separately so recompute doesn't erase them).
- Balance is floored at 0, never negative (guards against overpayment rounding).

**Status auto-flip** (unless overridden to `cancelled`, `credited`, `disputed`):
- `balance ≤ 0` and `paid_amount > 0` → `paid`
- `0 < paid_amount < total` → `partially_paid`
- `due_date` is past AND `paid_amount = 0` → `overdue`
- Otherwise (issued, future due, no payment) → `issued`

Guarded by tests: `PaymentScenarioTest` (HAPPY, STATE-TRANSITION, BOUNDARY, NEGATIVE, STATE).

### Payment Allocation Guards
1. **Cross-tenant barrier** (audit M06 F-26 / D-19): `Payment::assertInvoicesShareTenant()` throws `DomainException` if any invoice belongs to a different tenant. Called by Create/Edit pages after pivot sync; tested in `PaymentAllocationGuardsTest`.
2. **Per-row allocation cap** (audit M06 F-25 / D-18): Form validation ensures allocated amount ≤ invoice balance (+ existing row allocation when editing). Prevents over-allocation that the total-only guard would miss.
3. **Total allocation cap**: Form displays unallocated remainder and warns if allocated > payment amount.
4. **Property-scoped tenant select** (regression: cross-property IDOR fixed): PaymentForm's tenant_id relationship is filtered by `TenantScope::visibleAssetIds()`, so a property-restricted user cannot see another property's tenants. Tested in `PaymentFormPropertyScopeTest`.
5. **Posting-date guard** (close-out 2026-07-19): `CreatePayment` runs `PostingDate::assertOpen(payment_date)` — a receipt back-dated into a **closed** accounting period is refused before it relieves AR (its GL cash/AR leg could never post → silent divergence). A missing period is allowed. Tested in `PaymentFormGuardsTest`.
6. **At-least-one-allocation** (close-out 2026-07-19): the allocations Repeater has `minItems(1)` + a server `guardHasAllocation()` on create & edit. A zero-allocation on-account receipt would post as unearned revenue but be **orphaned** — invisible in the property-scoped UI (which scopes payments via their invoices) with no way to later apply it. Surfacing/auto-applying a tenant credit balance is deferred. Tested in `PaymentFormGuardsTest`.
7. **Duplicate-allocation dedup** (close-out 2026-07-19): two repeater rows for the same invoice are **summed** in the pivot builder (was: the pivot is keyed by invoice id, so the second row silently overwrote the first → money stranded while the summary reported it allocated). Tested in `PaymentFormGuardsTest`.
8. **Manual status restricted to the forward flow** (close-out 2026-07-19): the status Select offers only initiated + the received set; reversals route through the Void action (see §3 blockquote). Tested in `PaymentReceivedStatusesTest`.

### Late Fees (LateFeeService)
- Config: `billing.late_fee_percent` (default 2%), `billing.late_fee_grace_days` (default 7), `billing.late_fee_minimum` (default 50 EGP).
- Applied once per invoice when: `due_date + grace_days ≤ today`, balance > 0, and no late_fee item yet exists.
- Fee = `MAX(minimum, balance × percent / 100)`, rounded to 2 decimals.
- Idempotent via invoice-level check inside pessimistic lock (prevents double-charge on concurrent runs).
- Run via `ApplyLateFeesCommand` (daily cron) or `ApplyLateFees` job.
- Tested in `LateFeeServiceTest`.

### Paymob Gateway Rules (audit M11 F-42 / D-33)
- Session reuse window: 2700 seconds (< 3600s token TTL) so reuse margin exists for user to fill the card form.
- Reuse only if: payment is `initiated`, same invoice, same amount (rounded to 2 decimals). A credit/partial payment drops balance → fresh session forced.
- Capture: `success = true AND NOT is_voided`. Voided transactions are treated as failed.
- Idempotency: callback gateway_transaction_id is promoted from `paymob:order:{id}` to `paymob:txn:{txn_id}:order:{order_id}` on capture, so a replay of the same payload misses the bare-order lookup and returns 200 `already_processed` without re-touching anything.
- Tested in `PaymobPaymentScenarioTest`, `PaymobSessionStaleAmountTest`.

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
**Signature:** `public function start(Invoice $invoice): array`

Creates an `initiated` Payment for the invoice's current balance, allocates the full amount in the pivot, and returns the Paymob session (payment_token, iframe_url, order_id, payment_id, expires_at, reused). The Payment is keyed by Paymob's order_id so the S2S callback can recover it.

- **Idempotency:** Reuses an existing initiated session if within 2700s window and amount matches current balance.
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

Demo-only (gates to PAYMOB_ENABLED=false): simulates a successful Paymob capture. Creates initiated Payment for invoice.balance, allocates full amount, flips status to captured in one transaction. Mirrors real Paymob flow exactly (initiated → allocate → capture → recompute + notify).

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

### Adding a new payment method or gateway
1. Add enum option to payments.method or create a new column (e.g. gateway_name for multi-gateway support).
2. Update PaymentForm enum select to include the new method.
3. If integrating a new gateway (e.g., Stripe), create a new PaymobClient-like wrapper (StripClient) and a new *Initiator service.
4. Register the S2S callback route in routes/web.php and create a new CallbackController or extend the existing one.
5. Add tests mirroring PaymobPaymentScenarioTest for the new gateway's state transitions.
6. **DO NOT break:** Invoice::recomputeTotals only counts `captured` payments—respect this so AR math stays correct.

### Changing late-fee logic
1. Edit LateFeeService::runForToday (grace_days, percent, minimum from config).
2. Update config/billing.php values.
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

Payments carry a **`channel`** (`payments.channel`): `payment_link` (public `/pay/{token}` page), `mobile_api` (the app), `portal` (tenant portal Pay Now), `admin`. Paymob **session reuse is scoped per channel**, and `CallbackController::returned()` routes the browser by channel — `payment_link` → the public status page `/pay/{token}/status`, everything else → the portal. The S2S capture + tenant notification are shared. The public link is surfaced via the admin/portal **"Payment link"** action and the mobile `invoice.payment_link_url`. **Apple Pay** is scaffolded (a separate `PAYMOB_APPLE_PAY_INTEGRATION_ID` + the `/.well-known/apple-developer-merchantid-domain-association` route), off until configured. Full runbook: **[docs/PAYMENT-LINK-APPLEPAY.md](../PAYMENT-LINK-APPLEPAY.md)**. Tests: `tests/Feature/PaymentLink/PaymentLinkFlowTest.php`.

### Related Modules

- **[Invoices & AR](./04-invoices.md)** — Invoice creation, ETA submission, monthly billing. Invoices are the payment target; recomputeTotals drives AR.
- **[Credit Notes](./05-credit-notes.md)** — Credit notes apply to invoices via credit_applied_amount column; folded into recomputeTotals.
- **[Leases](./03-leases.md)** — Leases generate invoices; Invoice.lease_id FKs to Lease.
- **[Tenants](./02-tenants.md)** — Tenants are payment owners; Tenant.notifyPortal() fans notifications.
- **[Properties & Units](./01-properties.md)** — Payment form is property-scoped; unit.asset_id filters TenantScope.

---

**Last updated:** 2026-06-27  
**Module code:** M06  
**Key decision points:** Audit M06 F-25, F-26 (allocation guards); M11 F-42 (Paymob session reuse).
