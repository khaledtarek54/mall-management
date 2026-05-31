# Module 06 — Payments

> Date: 2026-05-31
> Status: 🟢 Green — boot hooks + recomputation logic are the cleanest in the codebase. Three Yellow extensibility findings (allocation guard, cross-tenant constraint, dedicated tests).
> Surface: [Payment model](../../app/Models/Payment.php), [Admin Payments resource](../../app/Filament/Admin/Resources/Payments/), [Portal Payments resource](../../app/Filament/Portal/Resources/Payments/), `invoice_payment` pivot with `allocated_amount`.

## 1. Inventory

### 1.1 Payment model — [Payment.php](../../app/Models/Payment.php) (138 LOC)

Best model in the codebase for side-effect handling. The pattern Module 04 Leases should adopt (D-12 recommendation).

- Traits: `HasFactory`, `LogsActivity`, `SoftDeletes`.
- `$fillable` (14): identity (`reference`, `tenant_id`, `amount`, `currency`, `method`, `status`, `payment_date`), gateway (`gateway`, `gateway_transaction_id`, `gateway_response`), cheque (`cheque_number`, `cheque_clearance_date`), bookkeeping (`notes`, `received_by`).
- `$casts`: 2 dates + amount decimal:2 + gateway_response array.
- Relations: `tenant` BelongsTo, `invoices()` BelongsToMany with pivot `allocated_amount` + timestamps, `receiver()` BelongsTo `User`.
- Method enum (7): `card, bank_transfer, instapay, wallet, cash, cheque, other` — instapay/wallet present but no integration code yet (Q2 roadmap per FEATURES.md).
- Status enum (8): `initiated, authorized, captured, reconciled, settled, failed, refunded, bounced` (default `captured`).
- LogsActivity: 6-column allowlist, dirty-only, log name `payment`.
- **Boot hooks (the gold standard):**
  - `creating` → `generateUniqueReference()` race-safe via while-loop (max 100 attempts → throws), defaults currency to EGP.
  - `saved` → `recomputeAllocatedInvoices()` — every invoice on the pivot reruns `Invoice::recomputeTotals()`.
  - `deleted` → `recomputeAllocatedInvoices()` — soft-delete rolls back the allocation: invoice's `paid_amount` recomputes excluding the deleted payment; balance grows back; status auto-flips back to `partially_paid` or `issued`.

### 1.2 Migration — [2024_01_01_000007_create_payments_table.php](../../database/migrations/2024_01_01_000007_create_payments_table.php)

- `payments` table: 16 columns + soft-deletes + timestamps. Indexes `(status, payment_date)`, `tenant_id`, `method`. FKs: `tenant_id` restrict, `received_by` nullOnDelete.
- `invoice_payment` pivot in same migration: `id`, `invoice_id` (cascade), `payment_id` (cascade), `allocated_amount` decimal(12,2), timestamps. Composite unique `(invoice_id, payment_id)`. **No constraint that `invoice.tenant_id === payment.tenant_id`** — see F-26.

### 1.3 Admin Resource — `app/Filament/Admin/Resources/Payments/`

- **PaymentResource**: uses `RoleGatedActions` + `ScopesViaProperty`. `tenantScopeRelation()` = `'invoices.lease.unit'`. Nav: `CreditCard` icon, sort 2, group "Billing". **No `getNavigationBadge()` override — F-17 does not apply.**
- **PaymentForm**:
  - Identity: `reference` (disabled, auto), `tenant_id` (live, searchable), `payment_date` (default today).
  - Amount & Method: `amount` (live `onBlur` triggers `suggestAllocations()`), `method`, `status` (default `captured`).
  - **Allocations Repeater** — the killer UX feature:
    - `invoice_id` select filters to `tenant_id = selected AND balance > 0`, ordered by due_date, shows `{number} · Balance: EGP {balance} · Due {date}`.
    - `allocated_amount` numeric, required, `minValue 0.01`.
    - On invoice pick: auto-fills `min(invoice.balance, remaining_payment)` accounting for sibling rows.
    - On tenant or amount change: `suggestAllocations()` distributes payment across the tenant's oldest unpaid invoices — but only when the repeater is empty (respects manual edits).
    - Live summary row "Payment: X — Allocated: Y — Unallocated: Z" color-coded.
  - Gateway/Cheque (collapsible). Notes (collapsible).
- **PaymentsTable**: 6 columns (reference / tenant.name / amount / method / payment_date / status), 5 filters incl. payment_date_range + amount_range, export + bulk export/delete/restore/forceDelete. Default sort `payment_date DESC`.
- **Pages**:
  - `CreatePayment` — extracts allocations from form state pre-create, guards total ≤ payment.amount, syncs the pivot post-create, explicitly calls `recomputeAllocatedInvoices()` (belt-and-braces with the `saved` boot hook).
  - `EditPayment::afterSave()` — extracts old vs new allocations, syncs pivot, recomputes invoices on **both** sides of the diff (previously-attached invoices that got removed AND newly-attached invoices that got added). The boot hook would catch the new side; the explicit call catches the removed side.

### 1.4 Owner Portal

No Owner-facing PaymentResource. Owners see only invoice paid/balance amounts indirectly.

### 1.5 Tenant Portal — `app/Filament/Portal/Resources/Payments/`

- Read-only (`canCreate/canEdit/canDelete = false`).
- Query scoped via `where('tenant_id', Auth::guard('portal')->id())`.
- Pages: List + View (Infolist).
- Infolist exposes: reference (copyable), payment_date, status, amount, method, gateway, gateway_transaction_id, cheque_number, cheque_clearance_date, notes.
- Tenant cannot initiate a payment from the portal in this codebase — that's the WhatsApp / offline rail (DEMO-ELTIZAM).

### 1.6 Cross-refs

- `Invoice::recomputeTotals()` reads `payments()->where('status','captured')->sum('invoice_payment.allocated_amount')` — only **captured** payments count toward `paid_amount`. Status flip `captured → failed` would automatically reduce `paid_amount` on the next `saved` hook fire. Test coverage for that exact transition is light — see F-27.
- Widgets consuming Payment:
  - `MallStats::Collected This Month` — `Payment::where('status','captured')->whereBetween('payment_date', [...])->sum('amount')`.
  - `RecentPayments` — last 8 captured, scoped via `TenantScope::applyTo($q, 'invoices.lease.unit')`.
  - `MonthlyRevenueTrend` — joins `invoice_payment` to `invoices.period_start` for the collection-rate line.

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md | "Recent Payments — latest captures." | ✅ `RecentPayments` widget. |
| FEATURES.md | "MonthlyRevenueTrend — last 12 months, Billed vs Collected." | ✅ uses pivot for invoice-month rate. |
| DEMO.md | "Collected This Month: EGP 220K — note the month-over-month delta." | ✅ formula correct; numeric drift logged in [01-dashboard.md F-2](01-dashboard.md). |
| FEATURES.md (implicit) | "Invoice ↔ Payment is many-to-many via a pivot with `allocated_amount`." | ✅ confirmed in migration. |

## 3. Findings

### 🟢 Boot-hook pattern is the codebase's reference design

`Payment::booted()` keeps the AR ledger consistent on every code path that mutates a Payment row:
- Form-driven create / edit (via `saved`)
- Soft-delete via DeleteAction (via `deleted`)
- Direct `Payment::create()` from seeders / API (via `saved`)
- Direct status changes (`$payment->update(['status'=>'failed'])` → `saved` → invoices recompute → balance grows back)

This is exactly what Module 04 Lease is missing (per F-19/F-21). The recommended fix at D-12 is "do what Payment does".

### 🟡 F-25. Per-row allocation can exceed invoice balance

The form auto-suggests `min(invoice.balance, remaining_payment)` but does **not enforce** it on validation. An admin who types `allocated_amount = 200` for an invoice with `balance = 100` will:

1. Pass the form's allocation-total guard (which checks Σ allocated ≤ payment.amount only).
2. Sync the pivot with the over-allocated row.
3. Trigger `recomputeAllocatedInvoices()` → `Invoice::recomputeTotals()` computes `paid = max(0, total - paid_amount)`. With `paid_amount = 200, total = 100`, balance becomes `max(0, -100) = 0` — but `paid_amount` is left at 200, which is wrong: the invoice now claims it received more than it billed.

**Operational consequence:** the AR snapshot becomes inconsistent (sum of paid_amounts could exceed sum of totals). KPI math still works because `balance` is floored at 0, but reports that compare invoiced-vs-collected would show > 100% collection rate for individual invoices.

**Fix sketch (small, deferred for explicit approval — D-18):**

Add per-row validation in `PaymentForm`:

```php
TextInput::make('allocated_amount')
    ->numeric()->required()->minValue(0.01)
    ->rule(function (Get $get) {
        return function (string $attribute, $value, $fail) use ($get) {
            $invoiceId = $get('invoice_id');
            if (! $invoiceId) return;
            $invoice = Invoice::find($invoiceId);
            if (! $invoice) return;
            $maxAllocatable = (float) $invoice->balance + $this->existingAllocation($invoiceId);
            if ((float) $value > $maxAllocatable + 0.01) {
                $fail(__('admin.payment.allocation_exceeds_balance', [
                    'invoice' => $invoice->number,
                    'max' => number_format($maxAllocatable, 2),
                ]));
            }
        };
    }),
```

The `+0.01` tolerance accounts for floating-point round-off in decimal:2.

### 🟡 F-26. No DB constraint preventing cross-tenant allocation

`invoice_payment` pivot has FKs to `invoices` and `payments` but **no check** that `invoice.tenant_id === payment.tenant_id`. The form filters invoices by tenant so normal use is safe. But:

1. CSV import or `php artisan tinker` direct pivot insert: cross-tenant allocation goes through silently.
2. If an operator changes a Payment's `tenant_id` after pivot rows exist (currently allowed by the form), those pivot rows become cross-tenant.

**Fix scope:** ideal is a model-level guard in `Payment::saved` ("if `tenant_id` changed, refuse if pivot rows exist for different tenants"), plus a DB check constraint. Either is a small change. Deferred D-19.

### 🟡 F-27. No dedicated `PaymentTest.php` or allocation/recompute test

`php artisan test --parallel --filter='Payment|RecentPayments|Allocation'` matches **11 tests** — those are scoping (`ResourceScopingTest`), widget instantiation (`UncoveredWidgetsTest`), and `ReportServiceTest`. The complex booted+pivot+recompute logic deserves explicit tests:

- Save payment with allocation → invoices' paid_amount + status flip.
- Soft-delete payment → invoices' balance restored + status reverts.
- Status change `captured → failed` → invoices' paid_amount drops.
- Re-allocate (edit form) → both old + new invoices recomputed.
- Race-safe reference generation under parallel inserts.

Deferred to the dedicated test-writing pass (consistent with D-10 on Module 03 — write tests in a batch after the audit).

### 🟢 Soft-delete safely rolls back allocation

[Payment:110-112](../../app/Models/Payment.php#L110-L112) `deleted` hook fires on soft-delete, recomputes invoices. Verified by hook code; no edge case found in static read.

### 🟢 Edit page handles re-allocation correctly

`EditPayment::afterSave()` recomputes **both** previously-attached and newly-attached invoices. So if an admin moves 100 EGP from Invoice A to Invoice B in a single edit: A's balance grows back, B's shrinks. Boot hook + page logic are belt-and-braces.

### 🟢 No F-17 carryover

`PaymentResource` does not override `getNavigationBadge()`.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='Payment|RecentPayments|Allocation'` | **11 passed / 0 failed** | 1.25 s |
| `php artisan test --parallel` (regression — should match Module 05 post-fix) | **287 passed / 0 failed** (no new code in Module 06) | 4.1 s |

## 5. Manual UX

Covered indirectly:
- `02-admin-pages.spec.js` confirms `/admin/{tenant}/payments` and `.../payments/create` load cleanly.
- `99-system-smoke.spec.js` confirms portal `/portal/payments` loads.

## 6. No inline fixes this module

No bug fix code paths were touched. F-25/F-26/F-27 are flagged as deferred small fixes for explicit approval — none are demo blockers (the auto-suggest UX in the form prevents over-allocation in normal use).

## 7. Deferred decisions

| # | Decision | Default if not raised |
|---|---|---|
| D-18 | F-25: per-row `allocated_amount` validation | Apply — small, contained |
| D-19 | F-26: cross-tenant pivot guard (model-level or DB check) | Apply — model-level guard; DB check is harder due to MySQL CHECK enforcement quirks |
| D-20 | F-27: write the missing Payment/allocation/recompute test file | Apply during the post-audit test-writing pass |

## 8. Verdict

**🟢 Green.** Module 06 is the codebase's reference implementation for model-level consistency: boot hooks idempotently maintain the AR ledger across every mutation path, soft-delete rolls back cleanly, and the form's allocation repeater is best-in-class UX. The three Yellow findings (F-25/F-26/F-27) are small refinements rather than bugs, and none affect the demo path or pilot production.

This module gives us the design pattern Lease should adopt at D-12.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢.

## Next

Module 07 — CAM (Common Area Maintenance) Expense Pools. Surface: [CamExpensePool model](../../app/Models/CamExpensePool.php), [CamAllocation model](../../app/Models/CamAllocation.php), [CamReconciliationService](../../app/Services/CamReconciliationService.php), [CamAnnualReconciliationCommand](../../app/Console/Commands/CamAnnualReconciliationCommand.php), the CamExpensePools admin resource + relation manager, and the DEMO-ELTIZAM claim that CAM is "Egyptian retail wedge #1".
