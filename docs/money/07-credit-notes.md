# Credit Notes — complete reference

> Scope: the full credit-note lifecycle (draft → issued → applied → void), how applied credit settles
> Accounts-Receivable (AR), the lock-safe `applyToInvoice`, the cancel-reversal that preserves a tenant's
> credit, and the rules that stop a credit being lost or double-counted. Every statement here is grounded in
> the current code with `file:line` citations so a reader can verify each claim.
>
> Audience: the business/finance team **and** new engineers. Nothing is left implicit.

---

## Plain-language summary

A **credit note** is the mall operator (Eltizam) saying *"we owe this tenant money / we are reducing what they
owe us."* Reasons include a billing correction, a goodwill adjustment, or a CAM year-end over-collection that
must be handed back. A credit note is money **in the tenant's favour**.

A credit note does not move cash. It is a balance the tenant can **spend against their own unpaid invoices**.
If a tenant has a 1,000 EGP invoice and a 300 EGP credit note, they really only owe **700**. Applying the
credit note to the invoice records that fact: the invoice shows 300 "paid by credit", 700 still owed, and the
credit note shows 300 used up, 0 left.

The lifecycle has four states:

| Status | Meaning in plain language |
| --- | --- |
| **draft** | Being prepared. Not real yet — cannot be spent, does not reduce what the tenant owes. |
| **issued** | Live. Has remaining balance the tenant can spend against invoices. |
| **applied** | Fully used up — every EGP has been spent against invoices (balance 0). |
| **void** | Cancelled before being used. Has no value. (You may only void a credit note that was **never** applied.) |

The three things this document insists on, because each was a real bug that was fixed and hardened:

1. **A credit can never be over-spent.** Two people clicking "apply" at the same moment cannot each spend the
   full balance. (`applyToInvoice` locks both rows — see [The exact rule](#the-exact-rule--formula).)
2. **A credit can never leak.** It can only be applied to an invoice that is actually still collecting money.
   Applying it to a cancelled/disputed/draft/paid invoice is refused (returns 0, changes nothing).
3. **A credit is never silently lost, and never double-refunded.** If you **cancel** an invoice that already
   consumed a tenant's credit, the system automatically hands that credit back as a **new offsetting credit
   note**. But if you mark an invoice **`credited`** (the "this invoice was settled *by* a credit note" terminal
   state), the credit stays consumed — reversing it there would refund the tenant twice.

---

## The exact rule / formula

All credit-note state transitions live in one service: `app/Services/CreditNoteService.php`. There are exactly
four operations. Below, every input, output, cap, rounding, and guard is spelled out.

### Data model — the columns that matter

`credit_notes` (model `app/Models/CreditNote.php`):

| Column | Type / cast | Meaning |
| --- | --- | --- |
| `status` | string enum | `draft` · `issued` · `applied` · `void` (no `partially_applied` — see note below) |
| `subtotal` / `vat_amount` / `total` | `decimal:2` | The face value of the note. `total` is what can be spent. |
| `applied_amount` | `decimal:2` | How much of `total` has already been spent against invoices. |
| `balance` | `decimal:2` | Remaining = `total − applied_amount`. This is what `hasBalance()` checks. |
| `applied_at` | `datetime` | Stamped the first time any amount is applied (never overwritten thereafter). |
| `voided_at` | `datetime` | Stamped when voided. |
| `tenant_id` / `lease_id` / `invoice_id` | FK | Whose credit it is; optional source invoice. |
| `currency` | string | Defaults to `EGP` on create (`CreditNote::booted`, `app/Models/CreditNote.php:113-115`). |

> **There is no `partially_applied` status.** A note that has been partly spent stays `issued` with
> `balance > 0`. This was a deliberate design decision — a `partially_applied` state "was once contemplated but
> never shipped" (`app/Models/Tenant.php:166-170`). Code that sums a tenant's outstanding credit therefore
> filters on `status = 'issued'` alone (`Tenant::outstandingBalance`, `app/Models/Tenant.php:171-173`).

`invoices` carries one column purpose-built for credit (model `app/Models/Invoice.php`):

| Column | Cast | Meaning |
| --- | --- | --- |
| `credit_applied_amount` | `decimal:2`, default `0` | Total credit applied to this invoice. Folded into `paid_amount` by `recomputeTotals()`. Added by migration `2026_06_27_000001_add_credit_applied_amount_to_invoices.php`. |

### `hasBalance()` — the gate for every apply

```
hasBalance() = (float) balance > 0  AND  status ∈ ['issued', 'applied']
```

Source: `app/Models/CreditNote.php:84-87`. This single predicate blocks `draft`, `void`, and fully-drained
notes from being applied. (A fully-drained note has `status = 'applied'` but `balance = 0`, so the `balance > 0`
leg fails — see the zero-note edge case below.)

### 1. `issue(CreditNote $note): CreditNote` — `CreditNoteService.php:15-28`

Turns a `draft` into a live note.

- **Idempotency guard (first line):** if status is already `issued` **or** `applied`, return immediately —
  no re-computation (`:17-19`).
- Inside a DB transaction (`:21`):
  - `balance = total − applied_amount` (`:22`).
  - `status = balance > 0 ? 'issued' : 'applied'` (`:23`). **A zero-value note issues straight to `applied`**
    (it has nothing to spend) — confirmed by `CreditNoteVoidEdgeCasesTest`'s "zero-total note issues straight
    to applied" case.
  - `save()` then `refresh()` and return (`:24-26`).
- **Inputs:** the note's `total` and `applied_amount`. **Output:** the issued (or applied) note.
- **No rounding** is applied here beyond the model's `decimal:2` casts.

### 2. `applyToInvoice(CreditNote $note, Invoice $invoice, ?float $requestedAmount = null): float` — `CreditNoteService.php:30-93`

Spends (some of) a note's balance against one invoice. **Returns the actual amount applied** — `0.0` means
nothing was applied (and nothing changed).

This is the lock-safe core. The whole body runs inside `DB::transaction` (`:39`). Step by step:

1. **Lock + re-read both rows under the lock** (`:45-46`):
   ```php
   $note    = CreditNote::query()->lockForUpdate()->find($note->id);
   $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
   ```
   This is the over-application backstop. Without it, two concurrent applies would each read the same
   pre-state and each commit their full amount, driving the note balance negative and pushing the invoice's
   `credit_applied_amount` past its `total`. The lock serialises them: the second waits, then re-reads the
   already-reduced balance and caps correctly. (Mirrors the payment path's over-allocation guard,
   `Payment::assertInvoicesNotOverAllocated`, `app/Models/Payment.php:143-165`.)

2. **Re-check `hasBalance()` under the lock** (`:50-52`): if either row vanished, or the note no longer has a
   spendable balance (drained / voided / still draft), return `0.0`.

3. **Read the two ceilings** (`:54-55`): `$available = note.balance`, `$owed = invoice.balance`. If either is
   `<= 0`, return `0.0` (`:57-59`).

4. **Payable-invoice guard** (`:64-66`):
   ```php
   if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
       return 0.0;
   }
   ```
   A credit may be applied **only** to a live, collecting invoice. Applying it to a `cancelled`, `credited`,
   `disputed`, `draft`, or `paid` invoice would consume the credit against a row that isn't collecting —
   silently leaking it. (Regression: `tests/Feature/Regression/CreditApplyGuardTest.php`.)

5. **Compute the amount (the cap)** (`:68-70`):
   ```
   amount = requestedAmount === null
            ? min(available, owed)
            : min(available, owed, requestedAmount)
   ```
   So the applied amount is **never** more than the note has, **never** more than the invoice owes, and (when a
   specific amount is asked for) **never** more than requested. If the result is `<= 0`, return `0.0` (`:72-74`).

6. **Update the credit-note side** (`:77-81`):
   - `applied_amount += amount` (capped at `available`, so it can never exceed `total`).
   - `balance = total − applied_amount`.
   - `status = balance > 0 ? 'issued' : 'applied'`.
   - `applied_at = applied_at ?? now()` — stamped once, on the first application, then preserved.
   - `save()`.

7. **Update the invoice side** (`:86-88`):
   - `credit_applied_amount += amount`.
   - `recomputeTotals()` — re-derives `paid_amount`, `balance`, and `status` (see [recomputeTotals](#how-credit_applied_amount-folds-into-recomputetotals)).

8. **Return `$amount`** — the real number applied.

### 3. `reverseAppliedCredit(Invoice $invoice): void` — `CreditNoteService.php:100-122` (the CANCEL-REVERSAL)

Hands a tenant's consumed credit back when their invoice leaves the books.

- `$amount = round(invoice.credit_applied_amount, 2)` (`:102`). If `<= 0`, return — nothing to reverse (`:103-105`).
- **Issue a brand-new offsetting credit note** (`:107-120`) for that exact `$amount`:
  - `status = 'issued'`, `reason = 'adjustment'`,
  - `reason_notes = "Credit returned from cancelled invoice {number}"`,
  - `subtotal = total = balance = $amount`, `applied_amount = 0`, `vat_amount = 0`,
  - `tenant_id` / `lease_id` / `currency` copied from the invoice (`currency` falls back to `EGP`).
- **Zero the invoice's `credit_applied_amount`** and `recomputeTotals()` (`:121-122`). The recompute does a
  `saveQuietly()` (no model events), which **keeps** the `cancelled` / `credited` status — `recomputeTotals`
  never overrides those statuses (`Invoice::recomputeTotals`, `app/Models/Invoice.php:270`).

**Who triggers it, and when:** the invoice model's `updated` hook (`app/Models/Invoice.php:223-248`). On a
**status change to `cancelled`** where `credit_applied_amount > 0`, it calls
`reverseAppliedCredit` (`:235-238`). Crucially this fires **only for `cancelled`, not for `credited`** — see the
worked examples and edge cases below. The hook uses `saveQuietly` internally so there is no recursion.

### 4. `void(CreditNote $note, ?string $reason = null): CreditNote` — `CreditNoteService.php:128-152`

Cancels a never-applied credit note.

- **Idempotency guard (first):** if already `void`, return untouched — **no throw**, even though the next check
  could throw (`:130-132`). Guard order matters: already-void wins. (Verified by
  `CreditNoteVoidEdgeCasesTest`: "void() on an already-void note returns it untouched without throwing".)
- **Applied guard:** if `applied_amount > 0`, **throw** `\DomainException`
  *"Cannot void a credit note that has already been applied. Issue an offsetting note instead."* (`:134-136`).
  This is the v1 rule — you do **not** reverse an applied note by voiding it; you issue an offsetting note. The
  throw happens **before** any write, so both sides are left completely untouched.
- Otherwise, in a transaction (`:138`): `status = 'void'`, `voided_at = now()`, optionally append
  `"Voided: {reason}"` to `notes`, `balance = 0`, `save()`, return (`:139-149`).

---

## How `credit_applied_amount` folds into `recomputeTotals`

`Invoice::recomputeTotals()` is the **single source of truth** for AR balances
(`app/Models/Invoice.php:255-283`). Credit notes never write `paid_amount` or `balance` directly — they bump
`credit_applied_amount` and let recompute fold it in:

```
paid  = SUM(captured payments' allocated_amount)        // payments pivot, status='captured'   :257-259
paid += credit_applied_amount                           // applied credit settles AR too        :264
paid_amount = round(paid, 2)                            //                                       :266
balance     = round(max(0, total − paid_amount), 2)     // never goes negative                  :267
```

Then auto-status (`:270-280`), **but only if** status is not one of `cancelled` / `credited` / `disputed`
(those are manual/terminal and are never overridden):

- `balance <= 0 && paid_amount > 0` → `paid`
- `paid_amount > 0` → `partially_paid`
- else if `due_date` is past → `overdue`
- else → `issued`

Finally `saveQuietly()` (`:282`) — no events, so this recompute does not retrigger the `updated` hook.

**Why the separate column exists (the bug it fixed):** before `credit_applied_amount`, applied credit was
baked straight into `paid_amount`. The very next time a **payment** triggered `recomputeTotals()`, the method
re-summed *only* the captured-payments pivot and **erased the credit**, leaving a phantom balance. The column
records the credit durably so recompute re-adds it every time. Migration:
`database/migrations/2026_06_27_000001_add_credit_applied_amount_to_invoices.php` (and it backfilled existing
rows as `paid_amount − captured payments`). Regression:
`tests/Feature/Regression/CreditNoteBalanceDriftTest.php`.

**Net AR for a tenant** also accounts for unspent credit. `Tenant::outstandingBalance()`
(`app/Models/Tenant.php:160-176`) = `SUM(open invoice balances) − SUM(issued credit-note balances)`, rounded to
2 dp. A tenant with a 1,000 invoice and a 300 issued credit note owes **700**, not 1,000.

---

## Worked examples with real numbers

### Example A — issue, partial apply, then a payment finishes it (the drift-proof path)

Tenant has invoice **INV** with `total = 1,000`, `paid_amount = 0`, `balance = 1,000`, `status = issued`,
`due_date` 30 days out.

1. Create a **draft** credit note `total = 300`. `issue(note)` → `balance = 300`, `status = issued`.
2. `applyToInvoice(note, INV, 300)`:
   - `available = 300`, `owed = 1,000`, invoice status `issued` (payable). `amount = min(300, 1000, 300) = 300`.
   - Note: `applied_amount = 300`, `balance = 0`, `status = applied`, `applied_at` stamped.
   - Invoice: `credit_applied_amount = 300` → `recomputeTotals` → `paid_amount = 300`, `balance = 700`,
     `status = partially_paid`.
3. Now capture a **700 cash payment** allocated to INV, then `recomputeTotals()`:
   - `paid = 700 (captured) + 300 (credit) = 1,000` → `paid_amount = 1,000`, `balance = 0`, `status = paid`.
   - The credit is **not** erased. (This is exactly `CreditNoteBalanceDriftTest`.)

### Example B — applying caps at what the invoice owes (no over-application)

Invoice owes 8,000. Credit note has 2,000 balance.
`applyToInvoice(note, INV, 2000)` → `amount = min(2000, 8000, 2000) = 2000`. Invoice:
`credit_applied_amount = 2000`, `paid_amount = 2000`, `balance = 8000`, `status = partially_paid`; note flips to
`applied`, `balance = 0`. (This is the setup in `CreditNoteVoidEdgeCasesTest`.)

If instead the invoice owed only 1,200 and you tried to apply the whole 2,000:
`amount = min(2000, 1200) = 1200`. The note keeps an 800 residual balance and stays `issued`.

### Example C — the CANCEL-REVERSAL preserves the tenant's credit

(Mirrors `tests/Feature/Regression/CancelledInvoiceCreditReversalTest.php`.)

1. Invoice INV `total = 11,400`, `status = issued`. Tenant has a 5,000 issued credit note.
2. `applyToInvoice(note, INV)` → `credit_applied_amount = 5,000`, INV `balance = 6,400`, `partially_paid`.
3. Operator **cancels** INV: `INV->update(['status' => 'cancelled'])`.
   - The `updated` hook fires: status changed to `cancelled` and `credit_applied_amount = 5,000 > 0` →
     `reverseAppliedCredit(INV)`.
   - A **new** `issued` credit note for **5,000** is created (`reason_notes` = "Credit returned from cancelled
     invoice INV-..."). INV's `credit_applied_amount` is zeroed; `recomputeTotals` re-derives totals while the
     status stays `cancelled`.
   - **Net effect:** the tenant still has 5,000 of spendable credit. Nothing was lost.

### Example D — `credited` does NOT reverse (no double refund)

(Mirrors the second case in `CancelledInvoiceCreditReversalTest`.)

1. Same setup: 5,000 credit applied to an invoice.
2. Operator marks the invoice **`credited`** (the terminal "this invoice was settled *by* a credit note"
   state): `INV->update(['status' => 'credited'])`.
   - The hook checks `status === 'cancelled'` — it is **not** — so `reverseAppliedCredit` is **not** called.
   - No new credit note is created; `credit_applied_amount` stays `5,000`.
   - **Why:** the credit *is* the intended settlement and the invoice stays on the books (revenue recognised).
     Reversing it here would hand the tenant back 5,000 they already spent settling this invoice — a
     double-refund that would drive net AR negative. This was a **CRITICAL** fix (commit `88816d4`,
     *"'credited' double-refund (CRITICAL)"*; the original H1 fix was `76bc843`).

### Example E — CAM negative true-up becomes an auto-applied credit

When CAM year-end reconciliation finds a tenant **over-paid** (negative true-up), it does not write a negative
charge — it issues a credit and immediately applies it to that lease's open invoices, oldest first.

- `CamReconciliationService::billCredit` creates an `issued` credit note for `abs(amount)`, `reason = 'adjustment'`,
  `reason_notes = "CAM reconciliation credit — {year}"` (`app/Services/CamReconciliationService.php:308-329`).
- `CamReconciliationService::applyCreditToOpenInvoices` (`:288-306`) loads the lease's
  `issued / partially_paid / overdue` invoices with `balance > 0`, ordered by `due_date`, and calls
  `applyToInvoice(note, invoice)` for each — breaking as soon as the note's live `balance` hits 0. Any residual
  stays on the note as spendable `issued` credit.
- See `tests/Feature/Regression/CamNegativeTrueUpCreditTest.php` and `docs/money/08-cam.md`.

---

## Every edge case + how the system handles it

| Edge case | Behaviour | Where |
| --- | --- | --- |
| **Apply to a non-payable invoice** (cancelled / credited / disputed / draft / paid) | Returns `0.0`, nothing changes. Status must be `issued`/`partially_paid`/`overdue`. | `CreditNoteService.php:64-66`; `CreditApplyGuardTest` |
| **Apply more than the invoice owes** | Capped at `owed`; residual stays on the note (`issued`). | `:68-70` |
| **Apply more than the note has** | Capped at `available`; note can never go negative or exceed `total`. | `:68-70`, `:77-79` |
| **Two concurrent applies** (race) | `lockForUpdate` serialises them; the second re-reads the reduced balance and caps. No over-application. | `:45-46`; mirrors `Payment::assertInvoicesNotOverAllocated` |
| **Apply a fully-drained note** (`applied`, balance 0) | `hasBalance()` false (balance>0 leg) → returns `0.0`, touches nothing. | `:50-52`; `CreditNoteVoidEdgeCasesTest` |
| **Apply a draft note** | `hasBalance()` false (status not in issued/applied) → `0.0`. | `:50-52` |
| **Apply a void note** | `hasBalance()` false → `0.0`. | `:50-52` |
| **Issue a zero-total note** | Goes straight to `applied` with `balance 0`; never apply-able. | `:23`; `CreditNoteVoidEdgeCasesTest` |
| **Issue an already-issued/applied note** | Idempotent — returned unchanged. | `:17-19` |
| **Void an applied note** (full or partial) | **Throws** `DomainException`; both sides untouched (no `voided_at`, no status change, invoice settlement intact). | `:134-136`; `CreditNoteVoidEdgeCasesTest` |
| **Void an already-void note** | Idempotent early-return, **no throw**, no re-stamp, reason not appended. | `:130-132`; `CreditNoteVoidEdgeCasesTest` |
| **Cancel an invoice that consumed credit** | Offsetting `issued` note auto-created; `credit_applied_amount` zeroed; status stays `cancelled`. | `Invoice.php:235-238`, `CreditNoteService.php:100-122` |
| **Mark an invoice `credited`** | **No** reversal — credit stays consumed (it is the settlement). | `Invoice.php:235` (guard is `=== 'cancelled'`); `CancelledInvoiceCreditReversalTest` |
| **Payment recompute after a credit was applied** | Credit survives — `recomputeTotals` re-adds `credit_applied_amount`. | `Invoice.php:264`; `CreditNoteBalanceDriftTest` |
| **`balance` never null on create** | `CreditNote::booted` defaults `balance = total − applied_amount` if null. | `CreditNote.php:113-115` |

### Admin UI guardrails (`EditCreditNote`, `app/Filament/.../Pages/EditCreditNote.php`)

These are convenience caps on top of the service guards — the service is still the authority.

- **Issue** action visible only when `status === 'draft'` (`:26`).
- **Apply** action: the invoice picker is filtered to the **same tenant**, `balance > 0`, status in
  `issued/partially_paid/overdue` (`:46-49`); the amount input is capped at the note's balance via `maxValue`
  (`:61`). On a `0` result it shows an "apply failed" warning rather than a success (`:70-76`).
- **Void** action visible only when `status ∈ [draft, issued]` **and** `applied_amount <= 0` (`:94`); the
  `DomainException` is still caught and surfaced as a danger toast (`:105-110`) in case the state shifted.

---

## Invariants + gotchas

**Invariants (do not break):**

1. **`balance = total − applied_amount`** always holds on a credit note. Recomputed inside `issue`,
   `applyToInvoice`, and zeroed in `void`. Never set `balance` independently.
2. **`applied_amount` never exceeds `total`**, and `balance` is never negative — guaranteed by capping each
   apply at `min(available, owed[, requested])` (`CreditNoteService.php:68-70`).
3. **AR is `recomputeTotals` only.** Credit settles AR through `credit_applied_amount`, never by writing
   `paid_amount`/`balance` directly. Recompute: `paid = captured payments + credit_applied_amount`
   (`Invoice.php:264`). This is the project-wide money invariant (see `CLAUDE.md`).
4. **A credit only applies to a live, payable invoice** (`issued`/`partially_paid`/`overdue`). Never to
   cancelled / credited / disputed / draft / paid.
5. **You never void an applied note** — you issue an offsetting note. Reversal is by offsetting, not by undo.
6. **`cancelled` reverses applied credit; `credited` does not.** They are different terminal states with
   opposite credit semantics. `credited` = settled *by* credit (stays consumed); `cancelled` = invoice voided
   (credit handed back).
7. **`reverseAppliedCredit`/recompute use `saveQuietly`** so a status-only cancel does not recurse the
   `updated` hook.

**Gotchas:**

- **No `partially_applied` status.** A partly-spent note is `issued` with `balance > 0`. Code that sums tenant
  credit filters on `status = 'issued'` (`Tenant.php:171-173`). Do not add a `partially_applied` filter
  expecting to catch residuals — they live under `issued`.
- **`applied_at` is stamped once** and never overwritten (`applied_at ?? now()`), so it marks the *first*
  application, not the last.
- **The payable-invoice guard is by `status`, not by `isPayable()`.** `Invoice::isPayable()` excludes
  `cancelled`/`credited` and requires `balance > 0` (`Invoice.php:127-131`) but `disputed` would slip through
  it; the service's explicit allow-list (`issued`/`partially_paid`/`overdue`) is stricter and is the one that
  governs `applyToInvoice`.
- **The `0.0` return is meaningful.** Callers must check the return value of `applyToInvoice`. The Filament page
  treats `<= 0` as a failed apply (`EditCreditNote.php:70-76`); the CAM loop relies on the live note balance to
  decide when to stop (`CamReconciliationService.php:301`).
- **Reconciliation cross-checks credit.** `BooksReconciliationService` derives
  `paid = allocated payments + credit_applied_amount` per invoice and flags any drift from stored `paid_amount`
  (`app/Services/Reconciliation/BooksReconciliationService.php:68-70`), and reports total applied credit
  (`:159`). If credit and AR ever disagree, the `billing:reconcile` harness will surface it.

---

## Where it lives in the code (file:line index)

| What | File | Lines |
| --- | --- | --- |
| Credit-note service (all 4 ops) | `app/Services/CreditNoteService.php` | `15-28` issue · `30-93` applyToInvoice · `100-122` reverseAppliedCredit · `128-152` void |
| `hasBalance()` gate | `app/Models/CreditNote.php` | `84-87` |
| `balance` default / number / currency on create | `app/Models/CreditNote.php` | `104-116` |
| `credit_applied_amount` column + fillable/cast | `app/Models/Invoice.php` | `41`, `64` |
| Cancel-reversal trigger (`updated` hook) | `app/Models/Invoice.php` | `223-248` (call at `235-238`) |
| `recomputeTotals` folds in credit | `app/Models/Invoice.php` | `255-283` (credit add at `264`) |
| Over-allocation backstop (includes credit) | `app/Models/Payment.php` | `143-165` |
| Net tenant AR (open invoices − issued credit) | `app/Models/Tenant.php` | `160-176` |
| CAM negative true-up → credit + auto-apply | `app/Services/CamReconciliationService.php` | `288-306` apply · `308-329` billCredit |
| `credit_applied_amount` migration + backfill | `database/migrations/2026_06_27_000001_add_credit_applied_amount_to_invoices.php` | whole file |
| Admin issue/apply/void actions | `app/Filament/Admin/Resources/CreditNotes/Pages/EditCreditNote.php` | `22-114` |
| Reconciliation cross-check | `app/Services/Reconciliation/BooksReconciliationService.php` | `68-70`, `159` |

**Tests (verify the rules):**

- `tests/Feature/Regression/CreditApplyGuardTest.php` — no leak to non-payable invoices.
- `tests/Feature/Regression/CreditNoteBalanceDriftTest.php` — credit survives a later payment recompute.
- `tests/Feature/Regression/CancelledInvoiceCreditReversalTest.php` — cancel reverses, `credited` does not.
- `tests/Feature/Regression/CamNegativeTrueUpCreditTest.php` — CAM credit auto-applies.
- `tests/Feature/Services/CreditNoteVoidEdgeCasesTest.php` — void-of-applied blocked, zero-note, drained-note, guard order.
- `tests/Feature/CreditNoteServiceTest.php` · `tests/Feature/Scenarios/CreditNoteScenarioTest.php` — happy paths, idempotency, caps.
- `tests/Feature/Api/V1/CreditNotesTest.php` — mobile/API surface.

**Session hardening commits (current correct behaviour):** `8a0a5c6` (CAM credit auto-applies + lock-safe
apply), `76bc843` (return consumed credit on cancel — backlog H1), `88816d4` (`credited` double-refund fix —
CRITICAL), plus the `credit_applied_amount` column migration dated `2026-06-27`.

---

## Related

- [`docs/money/05-billing-invoices.md`](05-billing-invoices.md) — invoice statuses, proration, the monthly billing run-lock.
- [`docs/money/06-payments.md`](06-payments.md) — captured-payments pivot, `recomputeTotals`, over-allocation guard.
- [`docs/money/08-cam.md`](08-cam.md) — CAM reconciliation: positive/negative true-ups; negative true-up → credit note.
- [`docs/money/09-tenant-sales-percentage-rent.md`](09-tenant-sales-percentage-rent.md) — percentage-rent charges that can also generate true-ups.
- Module-level companion: [`docs/modules/07-credit-notes.md`](../modules/07-credit-notes.md).
- Project invariants: `CLAUDE.md` (Money / AR section); books tie-out: `docs/BUSINESS-RULES.md`.
