# 00 — The Money Model & the Single Source of Truth

> **Audience:** business/finance team and engineers new to Atriom.
> **Scope:** how money is stored, computed, and kept correct on **Invoices, Invoice Items, Payments, and Credit Notes** — every column, every formula, every status, every edge case.
> **Golden rule (read this first):** never write `paid_amount` or `balance` on an Invoice by hand. Always go through `Invoice::recomputeTotals()`. Everything below explains why.

---

## Plain-language summary (what + why, for a non-engineer)

Atriom bills tenants for rent and other charges by issuing **invoices**. A tenant settles an invoice in one of two ways:

1. **Payments** — actual money received (cash, card, bank transfer, cheque, wallet, InstaPay, online link).
2. **Applied credit** — a **credit note** (a "we owe you" voucher, e.g. from a refund, a dispute, or a CAM over-payment) applied against what they owe.

For every invoice the system tracks three money facts:

- **`total`** — what the tenant owes for this invoice (rent + service charge + VAT + any late fee, etc.).
- **`paid_amount`** — how much of that `total` has already been settled, counting **both** real payments **and** applied credit.
- **`balance`** — what is still outstanding = `total − paid_amount`, never below zero.

The crucial design decision: **`paid_amount` and `balance` are never typed in or "set" anywhere by hand.** They are always *re-derived from the underlying records* (the captured payments allocated to the invoice, plus the credit applied to it) by a single function, `Invoice::recomputeTotals()`. This is the **single source of truth**. It means that no matter how money arrives — a gateway callback, an admin recording a cheque, a credit note being applied, a late fee being added, an invoice being cancelled — the headline numbers are always recomputed from the same inputs the same way, so the books cannot silently drift.

The **currency is Egyptian Pounds (EGP)** throughout, and **all money is rounded to 2 decimal places**.

---

## The exact rule / formula

### The invariant

For any invoice:

```
paid_amount = (Σ allocated_amount of CAPTURED payments on this invoice) + credit_applied_amount
balance     = max(0, total − paid_amount)
```

Both results are **rounded to 2 decimals (`round(x, 2)`)**. `balance` is **floored at 0** — an invoice can never show a negative balance even if it is over-settled (see *Edge cases → Over-allocation* for why the excess is prevented upstream rather than allowed to go negative here).

This is implemented in exactly one place: **`Invoice::recomputeTotals()`** — `app/Models/Invoice.php:255-283`.

```php
$paid  = Σ payments where payments.status = 'captured' → SUM(invoice_payment.allocated_amount)
$paid += credit_applied_amount;                    // applied credit settles AR too
$this->paid_amount = round($paid, 2);
$this->balance     = round(max(0, total − paid_amount), 2);
// …auto-status (below)…
$this->saveQuietly();                              // persists; skips model events
```

**Inputs:**

| Input | Where it comes from |
|---|---|
| `invoice_payment.allocated_amount` | the **pivot** row linking a payment to this invoice (how much of that payment is applied *here*). |
| `payments.status = 'captured'` | **only captured payments count.** `initiated` / `authorized` / `failed` / `refunded` / `bounced` payments are ignored. |
| `credit_applied_amount` | a durable column on the invoice, bumped only by `CreditNoteService::applyToInvoice()`. |
| `total` | the invoice header total (sum of item totals; set at creation, bumped by late fee). |

**Outputs:** `paid_amount`, `balance`, and `status` are written to the invoice via **`saveQuietly()`** (no model events fire, so this cannot recurse or re-trigger hooks).

### Auto-status derivation (inside the same function)

After recomputing the numbers, `recomputeTotals()` also derives `status` — **but it never overrides the three "manual" terminal/override statuses** `cancelled`, `credited`, `disputed` (`app/Models/Invoice.php:270`):

```
if status ∉ {cancelled, credited, disputed}:
    if balance ≤ 0 AND paid_amount > 0   → 'paid'
    elif paid_amount > 0                 → 'partially_paid'
    elif due_date is in the past         → 'overdue'
    else                                 → 'issued'
```

So a fully-settled invoice becomes `paid`; a part-settled one `partially_paid`; an unpaid one past its due date `overdue`; otherwise `issued`. An invoice an admin has marked `cancelled`, `credited`, or `disputed` keeps that status regardless of its numbers.

> Note: `Invoice::recalculateBalance()` (`app/Models/Invoice.php:150-159`) is a **legacy/simpler** helper that sets `balance = total − paid_amount` and flips status to paid/partially_paid. It does **not** floor the balance at 0, does **not** include credit, and does **not** respect override statuses. Prefer `recomputeTotals()`. Treat `recalculateBalance()` as deprecated.

### VAT, rent, marketing levy, late fee (the amounts that feed `total`)

`total` is built from **invoice items**. Each `InvoiceItem` self-computes its VAT and total on save (`app/Models/InvoiceItem.php:33-38`):

```
vat_amount = round(amount × vat_rate / 100, 2)
total      = round(amount + vat_amount, 2)
```

Business rates (see also `docs/modules/05-billing-invoices.md`, `docs/modules/13-marketing.md`):

- **VAT = 14%** on **service charges** (and any VAT-applicable charge). Stored per-item as `vat_rate`; the column default is `14.00`.
- **Base rent is VAT-exempt** — `vat_rate = 0`.
- **Marketing levy = 5% of base rent**, **VAT-exempt**, billed as a recurring `marketing` line item. The rate is **config-tunable**: `MarketingSettings::$levy_rate_percent` (default `5.0`, group `marketing`, editable at `/admin/settings`), read by `MarketingLevyService::ratePercent()` (`app/Services/MarketingLevyService.php:21-29`; falls back to `5.0` if the settings row is missing). The amount is **captured on the Charge at creation time**, so a later rate change never rewrites historical charges.
- **Late fee** = `max(min, balance × percent / 100)` from config (`config/billing.php`): `late_fee_percent` (default **2.0%**), `late_fee_minimum` (default **EGP 50.00**), applied once after `late_fee_grace_days` (default **7** days past due). VAT-exempt. See *Edge cases → Late fees*.

The invoice header is set at creation from the items (`MonthlyBillingService::generateInvoiceForLease`, `app/Services/MonthlyBillingService.php:241-262`):

```
subtotal   = round(Σ item.amount, 2)        // ex-VAT
vat_amount = round(Σ item.vat_amount, 2)
total      = round(subtotal + vat_amount, 2)
```

### Currency & rounding (precise)

- **Currency:** `EGP` everywhere. Defaulted on create for Invoice (`app/Models/Invoice.php:207-209`), Payment (`Payment.php:203-205`), Credit Note (`CreditNote.php:113-115`). The lease's `currency` is propagated to its invoices.
- **Storage precision:** money columns are `DECIMAL(12,2)` (Invoice/Item/Payment/CreditNote) — **except** `invoices.credit_applied_amount` which is `DECIMAL(14,2)` (`2026_06_27_000001_add_credit_applied_amount_to_invoices.php:22`). All are cast `decimal:2` in the models, so reads return 2-dp strings.
- **Rounding rule:** PHP `round($x, 2)` — **half-away-from-zero to 2 decimals** — applied at every computed money value (item VAT/total, header totals, proration amounts, late fee, true-up, recompute outputs).

---

## Entity relationships

```
Lease ──< Invoice ──< InvoiceItem            (one lease → many invoices → many line items)
              │  └────────── charge_id ─────► Charge        (item may trace to the recurring charge it came from)
              │
              ├──< invoice_payment >──── Payment            (many-to-many via pivot, pivot carries allocated_amount)
              │
              └── credit_applied_amount  ◄── CreditNote (via CreditNoteService::applyToInvoice)

CreditNote ──< CreditNoteItem
CreditNote ── invoice_id (optional origin link) · lease_id (optional) · tenant_id (required)
```

- **Lease → Invoice** (`invoices.lease_id`, `restrictOnDelete`): the lease's recurring **Charges** drive monthly billing. One invoice per lease per billed period.
- **Invoice → InvoiceItem** (`invoice_items.invoice_id`, `cascadeOnDelete`): the line items. Each item optionally references the `Charge` it was generated from (`charge_id`, `nullOnDelete`).
- **Invoice ↔ Payment** (pivot `invoice_payment`): **many-to-many**. A single payment can be split across several invoices; an invoice can receive several payments. The pivot's **`allocated_amount`** is the amount of that payment applied to that specific invoice. Unique on `(invoice_id, payment_id)`.
- **CreditNote**: belongs to a `tenant` (required); optionally references the originating `invoice` and/or `lease`. It does **not** allocate through a pivot — applying it bumps the target invoice's `credit_applied_amount`.
- **Tenant** owns invoices, payments and credit notes (every money record carries `tenant_id`).

---

## Every money column and its meaning

### `invoices` (`2024_01_01_000006_create_invoices_table.php`, + `credit_applied_amount` migration)

| Column | Type | Meaning | Who writes it |
|---|---|---|---|
| `subtotal` | DECIMAL(12,2) | Sum of item `amount` (ex-VAT). | Billing service at creation; late fee bumps it. |
| `vat_amount` | DECIMAL(12,2), default 0 | Sum of item VAT. | Billing service at creation. |
| `total` | DECIMAL(12,2) | What the tenant owes = subtotal + vat_amount (+ late fee). | Billing service / late fee. |
| `paid_amount` | DECIMAL(12,2), default 0 | **DERIVED.** Captured payments + applied credit. | **Only `recomputeTotals()`.** |
| `credit_applied_amount` | DECIMAL(14,2), default 0 | Durable record of credit applied to this invoice. | **Only `CreditNoteService` (apply / reverse).** |
| `balance` | DECIMAL(12,2), default 0 | **DERIVED.** `max(0, total − paid_amount)`. | **Only `recomputeTotals()`** (and the create-hook seed below). |
| `currency` | char(3), default `EGP` | Always EGP. | Create hook. |
| `status` | enum | Lifecycle (see below). | `recomputeTotals()` for the auto-set ones; admin actions for `cancelled`/`credited`; percentage-rent path for `disputed`. |
| `issue_date`, `due_date`, `period_start`, `period_end` | date | Billing period + payment terms. | Billing service. |

> **Create-hook seed (`Invoice::booted`, `app/Models/Invoice.php:199-218`):** on create, if `balance` is null it is seeded to `total − paid_amount`; `currency` defaults to EGP; a unique `number` (`INV-{ASSET}-{YYYYMM}-{NNNN}`) and a 48-char `payment_link_token` are generated. This seed is a convenience for the initial row only — every subsequent change goes through `recomputeTotals()`.

### `invoice_items`

| Column | Type | Meaning |
|---|---|---|
| `amount` | DECIMAL(12,2) | Line amount **ex-VAT**. |
| `vat_rate` | DECIMAL(5,2), default 14.00 | Percentage (14 for service charge, 0 for rent/marketing/late fee). |
| `vat_amount` | DECIMAL(12,2) | **DERIVED** on save: `round(amount × vat_rate/100, 2)`. |
| `total` | DECIMAL(12,2) | **DERIVED** on save: `round(amount + vat_amount, 2)`. |
| `type` | string(32) | One of `App\Enums\InvoiceItemType`: `base_rent`, `service_charge`, `utility`, `parking`, `percentage_rent`, `marketing`, `late_fee`, `other`. (Now a plain string, not a DB enum — add a type in the enum, no migration.) |
| `charge_id` | FK nullable | The recurring Charge this item came from, if any. |

### `payments` (`2024_01_01_000007_create_payments_table.php`)

| Column | Type | Meaning |
|---|---|---|
| `amount` | DECIMAL(12,2) | Gross amount received in this payment. |
| `currency` | char(3), default EGP | Always EGP. |
| `method` | enum | `card`, `bank_transfer`, `instapay`, `wallet`, `cash`, `cheque`, `other`. |
| `status` | enum, default `captured` | `initiated`, `authorized`, `captured`, `reconciled`, `settled`, `failed`, `refunded`, `bounced`. **Only `captured` counts toward AR.** |
| `channel` | string | `mobile_api` / `portal` / `payment_link` / `admin` (constants on `Payment`). |
| `gateway`, `gateway_transaction_id`, `gateway_response` | — | PSP (e.g. Paymob) details. |
| `cheque_number`, `cheque_clearance_date` | — | Cheque tracking. |
| `received_by` | FK users nullable | Admin who recorded it. |

**Pivot `invoice_payment`:** `invoice_id`, `payment_id`, **`allocated_amount`** DECIMAL(12,2) — the slice of this payment applied to that invoice. The sum of a payment's `allocated_amount` across invoices should equal (or be ≤) its `amount`.

### `credit_notes` (`2026_05_24_131937_create_credit_notes_table.php`)

| Column | Type | Meaning |
|---|---|---|
| `subtotal`, `vat_amount`, `total` | DECIMAL(12,2) | The credit's value (VAT usually 0 for adjustments). |
| `applied_amount` | DECIMAL(12,2), default 0 | How much of this credit has been used against invoices. |
| `balance` | DECIMAL(12,2), default 0 | **= total − applied_amount.** Remaining credit available. |
| `status` | enum, default `draft` | `draft`, `issued`, `applied`, `void`. |
| `reason` | string(100) | `return` / `dispute` / `adjustment` / `discount` / `refund` / `other`. |
| `invoice_id` | FK nullable | Origin invoice (optional). |
| `lease_id` | FK nullable | Origin lease (used for FIFO auto-apply). |
| `applied_at`, `voided_at` | timestamps | When first applied / when voided. |
| `issued_by_user_id` | FK users nullable | Issuer. |

`credit_note_items` mirrors invoice items but defaults `vat_rate` to **0**.

---

## Status lifecycles & AR exclusion

### Invoice status

| Status | Meaning | Set by | Counts in AR? |
|---|---|---|---|
| `draft` | Not yet issued (DB default; production invoices are created `issued`). | — | No |
| `issued` | Live, unpaid, not yet overdue. | `recomputeTotals()` / billing. | **Yes** |
| `partially_paid` | Some settlement, balance remains. | `recomputeTotals()` when `paid_amount > 0` and `balance > 0`. | **Yes** |
| `overdue` | Unpaid past `due_date`. | `recomputeTotals()`; late fee also sets it. | **Yes** |
| `paid` | Fully settled (`balance ≤ 0` and `paid_amount > 0`). | `recomputeTotals()`. | Yes (settled) |
| `disputed` | Under dispute (e.g. percentage-rent challenge). | `PercentageRentCalculationService` (`:142`); admin. **Override — not auto-changed.** | Yes |
| `cancelled` | Voided; leaves the books. | Admin action. **Override.** | **No — excluded** |
| `credited` | Settled **by a credit note** (revenue recognised; stays on the books). | Admin action. **Override.** | **No — excluded from net-AR/revenue queries** |

**Which statuses are excluded from AR / revenue:** reporting and KPI queries filter out **`cancelled` and `credited`** — e.g. `MonthlyRevenueTrend` (`:61, :78`), `MallStats` (`:102`), `AssetStatementPdfService` (`:33`), `TenantStatementPdfService` (`:22`). Open-AR collection paths (late fees, credit auto-apply, online pay) operate on **`issued` / `partially_paid` / `overdue` with `balance > 0`**.

`isPayable()` (`Invoice.php:127`) = not `cancelled`/`credited` **and** `balance > 0`. Online-pay controllers reject `cancelled`/`credited` invoices.

### Payment status

`captured` is the only AR-relevant state. Gateway callbacks flip `initiated → captured | failed`; the `saved` hook then recomputes every allocated invoice (`Payment::booted`, `:208-218`). `failed`/`refunded`/`bounced`/`initiated`/`authorized` contribute **0** to `paid_amount`.

### Credit-note status

`draft → issued → applied → void`. `hasBalance()` (`CreditNote.php:84`) = `balance > 0 AND status ∈ {issued, applied}` — only such notes can be applied. `void` requires `applied_amount = 0` (you cannot void a partly-used note; issue an offsetting note instead — `CreditNoteService::void`, `:130-151`).

---

## Worked examples (real numbers)

### Example A — base rent + service charge, one full payment

Lease: base rent **EGP 10,000** (VAT-exempt), service charge **EGP 2,000** (VAT 14%).

| Item | amount | vat_rate | vat_amount | total |
|---|---|---|---|---|
| Base rent | 10,000.00 | 0 | 0.00 | 10,000.00 |
| Service charge | 2,000.00 | 14 | 280.00 | 2,280.00 |
| Marketing levy (5% of rent) | 500.00 | 0 | 0.00 | 500.00 |

Header: `subtotal = 12,500.00`, `vat_amount = 280.00`, **`total = 12,780.00`**, `paid_amount = 0`, `balance = 12,780.00`, status `issued`.

Tenant pays EGP 12,780.00 (captured), fully allocated to this invoice. `recomputeTotals()`:
`paid_amount = 12,780.00 + 0 (credit) = 12,780.00`; `balance = max(0, 12,780 − 12,780) = 0.00`; status → **`paid`**.

### Example B — partial payment then a credit note

Same invoice (total 12,780.00). Tenant pays **EGP 5,000.00** (captured). →
`paid_amount = 5,000.00`, `balance = 7,780.00`, status **`partially_paid`**.

Admin issues a **credit note** for EGP 2,780.00 (a service-charge dispute resolution) and applies it. `CreditNoteService::applyToInvoice` caps at `min(note.balance, invoice.balance) = min(2,780, 7,780) = 2,780.00`, bumps `credit_applied_amount` to 2,780.00, calls `recomputeTotals()`:
`paid_amount = 5,000.00 (captured) + 2,780.00 (credit) = 7,780.00`; `balance = 5,000.00`; status **`partially_paid`**. The credit note: `applied_amount = 2,780.00`, `balance = 0`, status **`applied`**.

A **later** payment recompute will **not** erase the credit, because `recomputeTotals` adds `credit_applied_amount` back in — this is precisely the bug the column was introduced to fix (`2026_06_27_000001` migration header).

### Example C — mid-month proration

Lease commences **15 June** (30-day month), monthly base rent **EGP 1,000**, `prorate = true`.
`daysInPeriod = 30`; `daysBilled = |30 Jun − 15 Jun| + 1 = 16`; `factor = 16/30 = 0.5333…` (full precision, **not** rounded). Per-line amount = `round(1,000 × 16/30, 2) = 533.33`. The line is labelled "… (53% pro-rated)". `period_start` is stored as **15 June** (the commencement), not 1 June — which is what lets the idempotency guard recognise the month as billed. (Pre-fix, a signed/fractional day count undercharged mid-month move-ins and billed 0 for a last-day start — `MonthlyBillingService.php:206-218`.)

### Example D — CAM negative true-up (over-collected) becomes a credit, auto-applied

Annual CAM reconciliation finds a tenant over-paid by **EGP 1,500** (`true_up_amount = −1,500`). The system issues a **CreditNote** for 1,500 (not a negative charge — a negative charge could drive an invoice total below zero, which `recomputeTotals` would floor to 0 and **silently lose** the credit). It then **auto-applies FIFO** to the lease's open invoices oldest-first (`applyCreditToOpenInvoices`, `CamReconciliationService.php:289-306`). Any remainder stays on the note as standing credit. A **positive** true-up (under-collected) is billed **immediately** on its own recovery invoice (`billChargeImmediately`, `:236-286`), because the tenant's lease may already be ended/expired and excluded from monthly billing — deferring would silently lose revenue.

---

## Every edge case + how the system handles it

- **Credit must survive later payment recompute.** Credit is stored in `credit_applied_amount` (durable column) and re-added inside `recomputeTotals` (`Invoice.php:264`). A subsequent payment recompute cannot wipe it.
- **Over-allocation of payments (race).** Form-level caps handle the normal case; the **concurrency backstop** is `Payment::assertInvoicesNotOverAllocated()` (`Payment.php:143-165`) — called **inside** the pivot-sync transaction, it `lockForUpdate()`s each invoice and throws `DomainException` if `captured allocations + credit > total + 0.01`. The 0.01 tolerance absorbs rounding. The lock serialises parallel captures so two that each fit alone but together over-allocate are caught (second rolls back).
- **Cross-tenant payment allocation.** `Payment::assertInvoicesShareTenant()` (`:179-194`) throws if any allocated invoice belongs to a different tenant — a model-layer guard behind the form's tenant filter (audit M06 F-26/D-19).
- **Balance can never go negative.** `recomputeTotals` floors at `max(0, …)`. Over-settlement is prevented upstream (caps + the over-allocation guard), not absorbed as negative AR.
- **Credit applied only to live invoices.** `applyToInvoice` refuses unless invoice status ∈ `{issued, partially_paid, overdue}` and `balance > 0` (`CreditNoteService.php:56-66`) — applying to a paid/cancelled/credited/draft invoice would leak the credit against a row that isn't collecting.
- **Concurrent credit application (race).** `applyToInvoice` (`:38-91`) locks **both** the credit note and the invoice with `lockForUpdate()` and re-reads state inside the transaction — two concurrent applies cannot both commit the full amount (no negative note balance, no `credit_applied > total`).
- **Cancelling an invoice that consumed credit.** The `updated` hook (`Invoice.php:235-238`) calls `CreditNoteService::reverseAppliedCredit()` — it issues an **offsetting credit note** for the consumed amount (returning the tenant's credit), zeroes `credit_applied_amount`, and recomputes. Without this the credit would vanish with the cancelled row.
- **`credited` is deliberately NOT reversed.** A `credited` invoice **stays on the books** with revenue recognised — its credit is the intended settlement. Reversing it would double-refund and drive net AR negative. Only **`cancelled`** triggers the reversal (`Invoice.php:228-238`).
- **Late fee idempotency + concurrency.** `LateFeeService::applyTo` (`:60-97`) `lockForUpdate()`s the invoice and re-checks "no `late_fee` item yet" **inside** the transaction, so two concurrent runs can't double-charge. The fee bumps `subtotal`/`total` then calls `recomputeTotals()` (it does **not** write `balance` directly — that was an invariant smell, now fixed).
- **Monthly billing double-bill prevention.** `runForPeriod` wraps the whole run in a `Cache::lock('billing:run:{Y-m}', 900)` (`MonthlyBillingService.php:34`) so a manual CLI run can't race the scheduled job. Per-lease, a **period-overlap** guard (`period_start` within the month, `:75-78` and `:136-139`) skips an already-billed lease — overlap, not exact month-start, so a prorated first-month invoice (whose `period_start` is mid-month) isn't billed a second time.
- **CAM allocation/billing idempotency + concurrency.** `generateAllocations` and `bill` both `lockForUpdate()` and skip already-`billed` allocations inside the transaction (`CamReconciliationService.php:56-67, 188-191`) — no double charge/credit on re-runs.
- **NOT-NULL money columns.** Optional/blank form fields must never send `null` into a NOT-NULL money column (this class of bug bit `meter_readings.cost`, `leases.has_percentage_rent`). Money columns are coerced/seeded in the model create hooks and item save hooks.
- **Marketing fund accrual follows cancellation.** Cancelling/un-cancelling an invoice with a `marketing` item re-derives the property's marketing budget accrual via the `updated` hook (`Invoice.php:240-247`).

---

## Invariants + gotchas

1. **THE GOLDEN RULE — never write `paid_amount` or `balance` directly.** Always go through `Invoice::recomputeTotals()`. Anything that changes settlement (attach/detach/sync payment pivot, flip payment status, apply/reverse credit, add a late fee) must end in a `recomputeTotals()` call. The payment hooks (`Payment::booted` → `recomputeAllocatedInvoices`) and credit-note service already do this for you.
2. **`credit_applied_amount` is owned by `CreditNoteService` only.** Apply (`applyToInvoice`) and reverse (`reverseAppliedCredit`) are the only writers. Never bump it ad hoc.
3. **Only `captured` payments and applied credit settle AR.** Everything else is invisible to `paid_amount`.
4. **`recomputeTotals` never overrides `cancelled` / `credited` / `disputed`.** Use these as deliberate manual overrides.
5. **`recomputeTotals` uses `saveQuietly()`** — it persists without firing model events, so it cannot recurse. Don't "help" it by also calling `save()`.
6. **Floor at zero, round at every step.** Balance is `max(0, …)`; every money output is `round(x, 2)`. Comparisons that must tolerate rounding use a `0.01` epsilon (over-allocation guard).
7. **Proration rounds the amount, not the factor.** Keep the day-ratio at full precision; round only the per-line money.
8. **Prefer `recomputeTotals()` over `recalculateBalance()`** — the latter is legacy (no credit, no zero-floor, no override respect).
9. **Lock + re-check inside the transaction** is the pattern for every concurrency-sensitive money mutation (payments, credit apply, late fee, CAM). Stale reads outside the lock are the enemy.

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---|---|
| **Single source of truth** — `recomputeTotals()` | `app/Models/Invoice.php:255-283` |
| Invoice money columns / casts | `app/Models/Invoice.php:28-67` |
| Invoice create-hook seed (number, currency, balance, pay token) | `app/Models/Invoice.php:199-218` |
| Cancel → reverse applied credit; marketing re-accrual | `app/Models/Invoice.php:223-248` |
| `isPayable()` / `isOverdue()` / legacy `recalculateBalance()` | `app/Models/Invoice.php:127-159` |
| Invoice item VAT/total derivation | `app/Models/InvoiceItem.php:31-55` |
| Allowed item types | `app/Enums/InvoiceItemType.php` |
| Payment recompute + receipt-notify hooks | `app/Models/Payment.php:196-223` |
| Over-allocation guard (lock-safe) | `app/Models/Payment.php:143-165` |
| Cross-tenant allocation guard | `app/Models/Payment.php:179-194` |
| Credit note model + `hasBalance()` | `app/Models/CreditNote.php:84-119` |
| Apply / reverse / issue / void credit | `app/Services/CreditNoteService.php` (apply `:36-91`, reverse `:99-123`, void `:130-151`) |
| Monthly billing + proration + run-lock | `app/Services/MonthlyBillingService.php` (lock `:34`, proration `:206-218`, header `:241-262`) |
| Marketing levy (5%, config rate) | `app/Services/MarketingLevyService.php` · `app/Settings/MarketingSettings.php` |
| Late fee (idempotent, via recomputeTotals) | `app/Services/LateFeeService.php:60-97` · `config/billing.php` |
| CAM true-ups (positive=recovery invoice, negative=credit auto-applied) | `app/Services/CamReconciliationService.php` (bill `:182-225`, credit `:289-328`) |
| `credit_applied_amount` migration + backfill | `database/migrations/2026_06_27_000001_add_credit_applied_amount_to_invoices.php` |
| Schema: invoices/items | `database/migrations/2024_01_01_000006_create_invoices_table.php` |
| Schema: payments + pivot | `database/migrations/2024_01_01_000007_create_payments_table.php` |
| Schema: credit notes/items | `database/migrations/2026_05_24_131937_create_credit_notes_table.php` |

**Recent hardening (git):** `88816d4` (`credited` double-refund + proration undercharge), `67ae0e0`/`e9e6235`/`c022f9f` (CAM true-up settlement), `bb1ceed`/`76bc843`/`8a0a5c6` (net-AR for credits, billing run-lock, credit auto-apply + lock-safe apply), `3057520` (prorated double-bill).

---

## Related

- `docs/money/05-billing-invoices.md` — monthly billing engine, invoice generation, proration. *(See also `docs/modules/05-billing-invoices.md`.)*
- `docs/money/06-payments.md` — payment capture, allocation, gateway callbacks. *(See also `docs/modules/06-payments.md`.)*
- `docs/money/07-credit-notes.md` — issuing, applying, reversing, voiding credit notes. *(See also `docs/modules/07-credit-notes.md`.)*
- `docs/money/08-cam.md` — CAM expense pools, allocations, annual true-ups. *(See also `docs/modules/08-cam.md`.)*
- `docs/money/13-marketing.md` — marketing levy + budget accrual. *(See also `docs/modules/13-marketing.md`.)*
- `docs/BUSINESS-RULES.md` — the operator-facing rule sign-off sheet.
