# 12 — The Reconciliation Harness (the safety net)

> **Audience:** the operator's finance/accounting team **and** engineers new to Atriom.
> **Scope:** the read-only audit `php artisan billing:reconcile` — what it checks, the exact formula behind every check, the control totals it prints, when to run it, and how it catches drift. This is the **trust tool**: it independently re-derives the books from source records and tells you, in one command, whether the stored numbers still tie out.
> **One-line promise:** if this command says *"Books tie out — all checks passed"*, then every stored AR figure equals what the underlying records imply, with no value drifted, double-billed, or silently lost.

---

## Plain-language summary (what + why, for a non-engineer)

Atriom stores several "summary" money numbers so screens and reports are fast: each invoice carries a `paid_amount` and a `balance`; each marketing fund carries an `accrued_amount` and a `spent_amount`; each CAM pool carries a `total_actual_expense`. These summary numbers are supposed to always equal the sum of the detailed records behind them (the line items, the captured payments, the applied credits, the recorded spends, the pro-rata allocations).

The danger with any summary number is **drift**: the summary says one thing, the detail says another. Drift can come from a software bug, a write that skipped the official recompute path, a manual edit straight to the database, or a half-finished operation. Drift is silent — nothing on screen looks wrong — until an accountant adds up the detail by hand and finds it doesn't match the header. That is exactly the failure a finance team fears at month-end or tax-filing time.

**The reconciliation harness is the antidote.** It is a single command that:

1. **Re-derives every summary number from scratch** out of the source records — ignoring the stored summary entirely while it computes — and then **compares** its independent figure to what is stored. If they differ by more than **one piastre (0.01 EGP)**, that's a *discrepancy*, and it's reported with the exact invoice/pool/fund and the two numbers that disagree.
2. **Re-runs the structural rules** that must always hold (an invoice's parts add up to its total; no payment is spread across invoices for more than it was worth; every CAM charge that was billed actually landed on a real invoice).
3. **Prints a set of control totals** — total invoiced, collected, credits applied, outstanding AR, credit outstanding, net AR, VAT — the headline figures an accountant reconciles against their own ledger.

It **never changes any data** (it is strictly read-only — see the class docblock, `app/Services/Reconciliation/BooksReconciliationService.php:8-18`). It just reports. If everything matches it exits "OK" (process exit code 0); if anything is off it lists every discrepancy and exits "FAIL" (non-zero), so it can also gate an automated month-end close or a pre-filing check.

Think of it as an **independent second set of books** computed live from the raw records, held up against the official books to confirm they agree.

---

## How to run it

```bash
# Reconcile ALL non-cancelled invoices (and all funds / pools):
php artisan billing:reconcile

# Reconcile only invoices ISSUED in a given calendar month (YYYY-MM):
php artisan billing:reconcile --month=2026-06
```

- **Command:** `billing:reconcile` — defined in `app/Console/Commands/ReconcileBooksCommand.php`.
- **Signature:** `billing:reconcile {--month= : Optional YYYY-MM to scope to invoices issued that month}` (`ReconcileBooksCommand.php:17`).
- **`--month` semantics:** filters the **invoice** checks to invoices whose `issue_date` falls in that `YYYY-MM` (`BooksReconciliationService.php:34-37`, using `whereYear` + `whereMonth`). It does **not** narrow the cross-cutting checks that scan whole tables (payment over-allocation, marketing funds, CAM pools) — those always run portfolio-wide. So `--month` makes the AR portion month-scoped but keeps the fund/pool integrity checks global. Omit `--month` to reconcile everything.
- **Exit code:** `0` (SUCCESS) when every check passes; `1` (FAILURE) when any check has at least one discrepancy (`ReconcileBooksCommand.php:63,69`). This is what lets you wire it into CI or a close checklist (`# fail the close if the books don't tie`).
- **Output:** a table of checks (✓/✗ per check), a table of control totals, and — only on failure — a per-check list of the first 20 discrepancies with a "… N more" tail (`ReconcileBooksCommand.php:29-67`).

> **It is NOT on a schedule.** Unlike `cam:reconcile`, `billing:reconcile` is **not** registered in `routes/console.php` — it is a deliberate, on-demand audit you run (or your CI runs) at a moment of your choosing. Running it can never harm data because it writes nothing.

---

## What gets reconciled — the universe of records

The invoice-level checks operate on this set (`BooksReconciliationService.php:30-39`):

```
Invoice::query()
    ->where('status', '!=', 'cancelled')   // cancelled invoices are off the books — excluded
    ->with('items')
    (+ optional whereYear/whereMonth on issue_date when --month is given)
```

**Cancelled invoices are excluded on purpose.** A cancelled invoice has left the books (no revenue recognised, its consumed credit is returned to the tenant — see `Invoice::booted()` `updated` hook, `app/Models/Invoice.php:223-248`). Including it would compare a deliberately-zeroed row against live records and raise false alarms. **`credited` invoices are kept** — that is the terminal "paid by credit note" state which *stays* on the books with revenue recognised, so its numbers must still tie.

The fund and pool checks scan their own tables in full (`MarketingBudget`, `CamExpensePool`), independent of `--month`.

---

## The six checks — exact rule, inputs, and what each one catches

Each check produces a list of discrepancies; a check **passes** when that list is empty (`check()`, `BooksReconciliationService.php:188-191`). The whole report's `ok` flag is `true` only when **every** check passes (`BooksReconciliationService.php:181`).

**The money tolerance is one piastre:** `EPS = 0.01` (`BooksReconciliationService.php:22`). A difference is a discrepancy only when `abs(derived − stored) > 0.01`. This absorbs legitimate 2-decimal rounding without hiding real drift.

### Check 1 — `invoice_composition`: "Invoice total = line-item subtotal + VAT"

*Code: `BooksReconciliationService.php:42-59`.*

For every non-cancelled invoice in scope:

- If it has line items, **re-add the items** independently:
  - `itemsSubtotal = round(Σ items.amount, 2)` must equal the stored `invoice.subtotal` (within EPS).
  - `itemsVat = round(Σ items.vat_amount, 2)` must equal the stored `invoice.vat_amount` (within EPS).
- For **every** invoice (items or not): `subtotal + vat_amount` must equal `total` (within EPS).

**What it catches:** a header total that no longer matches its parts — a dropped/edited line item, a VAT miscalculation, a late fee that bumped `total` but not `subtotal`, or a direct DB edit to any of the three columns. (Invoices with no line items skip the first two sub-checks but still must satisfy `subtotal + vat = total`.)

> VAT rule reminder (verified in the money model): **14% on service charges, 0% on base rent (VAT-exempt)**. This check doesn't recompute VAT *rates* — it confirms the stored VAT total equals the sum of the per-item VAT and that the three header figures are internally consistent.

### Check 2 — `paid_amount`: "Paid = captured payments + applied credits"

*Code: `BooksReconciliationService.php:61-73`. This **mirrors `Invoice::recomputeTotals()` exactly**.*

For every in-scope invoice:

```
allocated = round( Σ allocated_amount of this invoice's payments WHERE payments.status = 'captured' , 2 )
derived   = round( allocated + invoice.credit_applied_amount , 2 )
```

`derived` must equal the stored `paid_amount` (within EPS).

**Inputs:**

| Input | Source |
|---|---|
| `invoice_payment.allocated_amount` | the pivot row linking a payment to this invoice — how much of that payment applies here. |
| `payments.status = 'captured'` | **only captured payments count.** `initiated` / `authorized` / `failed` / `refunded` / `bounced` are ignored — same filter as the source of truth. |
| `credit_applied_amount` | the durable column on the invoice, bumped only by `CreditNoteService` when a credit note is applied. |

**Why it mirrors the source of truth:** `Invoice::recomputeTotals()` computes `paid = Σ(captured allocations) + credit_applied_amount` then `round(…, 2)` (`app/Models/Invoice.php:255-266`). This check re-derives the **identical** quantity from the **same** records. So if any code path ever set `paid_amount` directly (bypassing `recomputeTotals()` — the project invariant forbids it), or a credit application updated `credit_applied_amount` without recomputing, or a payment's status flipped without a recompute, this check fails and names the invoice.

### Check 3 — `balance`: "Balance = total − paid (floored at 0)"

*Code: `BooksReconciliationService.php:75-83`.*

For every in-scope invoice:

```
derived = round( max(0, total − paid_amount) , 2 )
```

must equal the stored `balance` (within EPS). The **floor at 0** is identical to `recomputeTotals()` (`Invoice.php:267`): an invoice can never carry a negative balance, even if it were somehow over-settled — over-allocation is prevented upstream (see Check 4) rather than allowed to push the balance negative.

**What it catches:** a `balance` that drifted from `total − paid_amount` — typically a sibling symptom of a Check 2 failure, or a manual edit to `balance`.

### Check 4 — `payment_allocation`: "No payment allocated beyond its amount"

*Code: `BooksReconciliationService.php:85-93`. This is the only check keyed on **payments**, not invoices, and it always scans all captured payments (ignores `--month`).*

For every **captured** payment:

```
alloc = round( Σ allocated_amount across all invoices this payment is linked to , 2 )
```

is a discrepancy when `alloc − payment.amount > EPS` — i.e. the payment has been spread across invoices for **more than it was actually worth**.

**What it catches:** double-application of a single payment, or an allocation pivot row inflated beyond the payment. (Under-allocation — a payment with money left unapplied — is **allowed**: that is a legitimate credit-on-account situation, so only *over*-allocation is flagged.) The discrepancy reference is the payment's `reference` or `payment #{id}`.

### Check 5 — `marketing_budget`: "Marketing accrued = billed levies, spent = recorded spends"

*Code: `BooksReconciliationService.php:95-115`. Scans every `MarketingBudget` (one row per asset + year), ignores `--month`.*

For each marketing fund (asset, `period_year`):

- **Accrued (income side):** independently re-sum the **marketing levy** that was actually billed —
  ```
  accrued = round( Σ amount of InvoiceItem WHERE type = 'marketing'
                   AND its invoice.status != 'cancelled'
                   AND invoice.issue_date year = budget.period_year
                   AND invoice.lease.unit.asset_id = budget.asset_id , 2 )
  ```
  must equal the stored `budget.accrued_amount` (within EPS). This is the **same derivation** `MarketingBudget::recomputeAccrued()` uses (`app/Models/MarketingBudget.php:75-86`).
- **Spent (expense side):**
  ```
  spent = round( Σ amount of this budget's spends , 2 )
  ```
  must equal the stored `budget.spent_amount` (within EPS). Same as `MarketingBudget::recomputeSpent()` (`MarketingBudget.php:65-67`).

**Background (verified):** the **marketing levy is 5% of base rent**, accrued per billed charge; `LeaseRentChangeService` keeps the levy in lock-step at 5% when base rent changes (`app/Services/LeaseRentChangeService.php:87`). Cancelling an invoice that carried a marketing item re-derives the accrual via the invoice `updated` hook (`Invoice.php:243-247`), which is precisely why the accrual must always equal the *non-cancelled* billed marketing items — and why this check excludes cancelled invoices.

**What it catches:** a marketing fund whose accrued or spent total drifted from the billed levy items / recorded spends — e.g. a spend deleted without recompute, an invoice cancelled without the accrual re-deriving, or a manual fund edit.

### Check 6 — `cam_allocations`: "CAM allocations tie to the pool + every billed one has a charge or credit note (and the charge reached an invoice)"

*Code: `BooksReconciliationService.php:117-152`. Scans every `CamExpensePool` with allocations, ignores `--month`. This is the most defensive check, hardened across several fixes this session.*

For each CAM pool with allocations:

**(a) Allocations tie to the pool expense.**
```
summed    = round( Σ allocations.allocated_amount , 2 )
tolerance = 0.01 × max(1, allocation_count)   // pro-rata rounding slack — ONE piastre PER allocation
```
`summed` must be within `tolerance` of the pool's `total_actual_expense`. The tolerance scales with the number of allocations because each pro-rata share is independently rounded to 2 decimals, so the sum can legitimately differ from the pool total by up to a piastre **per allocation**. (Pools with no allocations are skipped — `BooksReconciliationService.php:122-124`.)

**(b) Every BILLED allocation is backed, and a billed charge actually reached an invoice.** For each allocation whose `status = 'billed'`:

1. It must have **either** a backing charge (`billed_charge_id` points at an existing `Charge`) **or** a backing credit note (`billed_credit_note_id` points at an existing `CreditNote`) — `BooksReconciliationService.php:134-137`.
   - A **positive true-up** (tenant under-collected, owes more) is billed as a **charge**.
   - A **negative true-up** (tenant over-collected, is owed) is billed as a **credit note** — modelled as a credit, never as a "lost negative charge" (this session's fix `c022f9f`).
   - A billed allocation with **neither** is a discrepancy: "billed but no backing charge/credit-note".
2. If it has a backing **charge**, that charge must have **reached a non-cancelled invoice** — `BooksReconciliationService.php:142-149`:
   ```
   reached = EXISTS( InvoiceItem WHERE charge_id = alloc.billed_charge_id
                     AND its invoice.status != 'cancelled' )
   ```
   If `reached` is false, that's a discrepancy: *"billed true-up charge … never reached a non-cancelled invoice (lost revenue)"*.

**Why (b.2) exists — the lost-revenue class this catches.** A positive CAM true-up is settled **immediately on a dedicated recovery invoice** at reconciliation time (`CamReconciliationService::billChargeImmediately()`, `app/Services/CamReconciliationService.php:236-286`, creates the `Charge`, an `issued` `Invoice`, and the linking `InvoiceItem`). It is **not** deferred to a future monthly run. The reason (documented in that method): reconciliation runs the year *after* a fully-billed year, and the most likely under-collected tenant often has an ended-term lease that the monthly engine's active/expiry filter would skip — so deferring would silently lose the revenue. Earlier code (before fixes `e9e6235` and `67ae0e0`) could mark an allocation `billed` against a charge that never landed on an invoice; this check would now flag that instead of masking it. The credit (negative) path applies immediately too, oldest-invoice-first (`applyCreditToOpenInvoices`, `CamReconciliationService.php:288-306`).

**What it catches, in one line:** double-billing, a billed allocation that lost its backing record, and — crucially — a true-up that was marked billed but whose revenue never reached an invoice.

---

## The control totals — the figures an accountant reconciles against their own books

Printed after the checks (`ReconcileBooksCommand.php:35-45`), computed in `BooksReconciliationService.php:154-178`. All are over the **in-scope** invoices (so `--month` narrows them). All are `round(…, 2)` EGP.

| Metric (label printed) | Key | Definition | Source line |
|---|---|---|---|
| Invoices | `invoiceCount` | count of in-scope (non-cancelled) invoices | `:156` |
| Total invoiced | `invoiced` | `Σ total` over in-scope invoices | `:157` |
| Collected (paid) | `collected` | `Σ paid_amount` (captured payments + applied credit) | `:158` |
| Credits applied | `creditApplied` | `Σ credit_applied_amount` | `:159` |
| Outstanding AR | `outstandingAR` | `Σ balance` over invoices with `status ∈ {issued, partially_paid, overdue}` **and** `balance > 0` | `:163-166` |
| VAT total | `vatTotal` | `Σ vat_amount` | `:167` |
| *(computed, not printed by the CLI table)* Credit outstanding | `creditOutstanding` | `Σ balance` of `CreditNote` with `status ∈ {issued, applied}` and `balance > 0` | `:173-176` |
| *(computed, not printed)* Net AR | `netAR` | `outstandingAR − creditOutstanding` | `:177` |

**Why `outstandingAR` uses a status filter, not "all non-cancelled".** The canonical AR definition is *open and owed* invoices only — `issued`, `partially_paid`, `overdue`, with a real positive balance. Summing balances over every non-cancelled invoice would fold in `paid`, `credited`, `disputed`, and `draft` rows and misstate AR. This matches the AR-aging report and `Tenant::outstandingBalance()` (`app/Models/Tenant.php:160-176`), which uses the same `{issued, partially_paid, overdue}` filter.

**Why `netAR` nets out standing credit.** An unapplied (standing) credit note is a liability that offsets AR — a tenant carrying a 1,000 invoice and a 300 issued credit owes 700 net, not 1,000. So `netAR = outstandingAR − creditOutstanding` matches the net figure `Tenant::outstandingBalance()` reports, so the accountant reads **net** AR, not gross. (`creditOutstanding` and `netAR` are present in the returned `controlTotals` array for programmatic/test use; the CLI's printed table shows the six headline rows above.)

---

## Worked example (real numbers)

A small portfolio, reconciling `--month=2026-06`. Three non-cancelled invoices:

| Invoice | subtotal | vat_amount | total | captured payments | credit_applied | status |
|---|---|---|---|---|---|---|
| INV-AW-202606-0001 | 10,000.00 | 1,400.00 | 11,400.00 | 11,400.00 | 0.00 | paid |
| INV-AW-202606-0002 | 20,000.00 | 700.00 | 20,700.00 | 5,000.00 | 0.00 | partially_paid |
| INV-AW-202606-0003 | 5,000.00 | 0.00 | 5,000.00 | 0.00 | 5,000.00 | credited |

(Inv 1: 10,000 service charge @ 14% VAT. Inv 2: mixed — 5,000 service charge @ 14% = 700 VAT, plus 15,000 VAT-exempt base rent. Inv 3: 5,000 base rent, settled entirely by a credit note.)

**Check 1 (composition):** each invoice's items re-add to its subtotal/VAT, and `subtotal + vat = total` for all three (11,400; 20,700; 5,000). ✓

**Check 2 (paid):**
- Inv 1: `round(11,400 + 0) = 11,400` = stored `paid_amount`. ✓
- Inv 2: `round(5,000 + 0) = 5,000` = stored. ✓
- Inv 3: `round(0 + 5,000) = 5,000` = stored (paid entirely by applied credit). ✓

**Check 3 (balance):**
- Inv 1: `max(0, 11,400 − 11,400) = 0` ✓ · Inv 2: `max(0, 20,700 − 5,000) = 15,700` ✓ · Inv 3: `max(0, 5,000 − 5,000) = 0` ✓

**Check 4 (allocation):** suppose the 11,400 payment is allocated only to Inv 1 (11,400 ≤ 11,400) and the 5,000 payment only to Inv 2 — no over-allocation. ✓

**Control totals (period = 2026-06):**

| Metric | Value |
|---|---|
| Invoices | 3 |
| Total invoiced | `11,400 + 20,700 + 5,000` = **37,100.00** |
| Collected (paid) | `11,400 + 5,000 + 5,000` = **21,400.00** |
| Credits applied | `0 + 0 + 5,000` = **5,000.00** |
| Outstanding AR | only Inv 2 qualifies (`partially_paid`, balance 15,700 > 0) = **15,700.00** |
| VAT total | `1,400 + 700 + 0` = **2,100.00** |

If a standing credit note of 300.00 exists for one of these tenants (status `issued`, balance 300), then `creditOutstanding = 300.00` and `netAR = 15,700 − 300 = 15,400.00`. Result line: **"Books tie out — all checks passed."**, exit 0.

**Now inject drift.** Suppose someone hand-edits Inv 2's `paid_amount` to `6,000` in the database (it should be 5,000). The captured allocation is still 5,000.

- **Check 2 fails:** `derived paid 5000.00 (captured 5000 + credit 0) ≠ stored paid_amount 6000.00` for INV-AW-202606-0002.
- **Check 3 fails too:** stored `balance` 15,700 ≠ `max(0, 20,700 − 6,000) = 14,700` — *"derived balance 14700.00 ≠ stored balance 15700.00"*. (Drift usually trips two checks — that paired symptom is itself a fingerprint of a hand-edit rather than a formula error.)
- The command prints both discrepancies under `[paid_amount]` and `[balance]`, ends with **"✗ Books do NOT tie out."**, and exits **1**.

**The fix is never to edit the column.** Re-running `Invoice::recomputeTotals()` on that invoice re-derives `paid_amount = 5,000` and `balance = 15,700` from the captured payments + credit, and the harness goes green again. The harness *finds* the drift; `recomputeTotals()` *removes* it.

---

## Every edge case + how the system handles it

| Scenario | Behaviour |
|---|---|
| **Cancelled invoice** | Excluded from all invoice checks and control totals (`status != 'cancelled'`, `:31`). Its consumed credit was already returned on cancel (`Invoice.php:235-238`), so it must not be compared. |
| **`credited` invoice** (paid by credit note) | **Included.** It stays on the books with revenue recognised; its `paid_amount = credit_applied_amount`, `balance = 0` must tie (verified by Checks 2 & 3 — see Inv 3 in the example). |
| **`disputed` / `draft` invoice** | Included in Checks 1–3 (their composition/paid/balance must still be internally consistent) but **excluded from `outstandingAR`**, which only counts `issued`/`partially_paid`/`overdue`. |
| **Invoice with no line items** | Skips the line-item sub-checks of Check 1 but must still satisfy `subtotal + vat = total` (`:44-57`). |
| **Rounding (per invoice)** | Tolerance `EPS = 0.01`; a 2-decimal rounding gap is never a false positive. |
| **Rounding (CAM pro-rata)** | Check 6a's tolerance is `0.01 × allocation_count` — one piastre **per** allocation, because each share is rounded independently (`:126`). |
| **Payment with money left unapplied** | Allowed. Check 4 only flags **over**-allocation (`alloc − amount > EPS`), never under-allocation (credit-on-account is legitimate). |
| **Initiated / authorized / failed / refunded / bounced payment** | Ignored by Check 2 and by `recomputeTotals()` alike — only `captured` counts. |
| **Standing (unapplied) credit note** | Surfaced as `creditOutstanding` and netted into `netAR` so AR isn't read gross (`:173-177`). |
| **Partially-applied credit note** | Left in `issued` with `balance > 0` (the enum is `draft/issued/applied/void`; no `partially_applied` state shipped — `Tenant.php:166-170`). Counted in `creditOutstanding` (filter `status ∈ {issued, applied}` AND `balance > 0`). |
| **CAM positive true-up** | Backed by a `Charge`; Check 6 requires that charge to have **reached a non-cancelled invoice** (`:142-149`), settled immediately on a recovery invoice (`CamReconciliationService.php:236-286`). |
| **CAM negative true-up** | Backed by a `CreditNote` (`billed_credit_note_id`); accepted by Check 6's "has a charge OR a credit note" clause (`:135-136`). Credit auto-applies to open invoices oldest-first. |
| **CAM allocation still `draft`/unbilled** | Only `status = 'billed'` allocations are checked for backing (`:130`); the pool-tie sub-check (6a) sums **all** allocations regardless of status. |
| **CAM pool with no allocations** | Skipped entirely (`:122-124`) — nothing to tie. |
| **`--month` with no invoices** | All invoice checks pass vacuously (empty discrepancy lists); control totals show 0/0.00; cross-table checks (4, 5, 6) still run portfolio-wide. |
| **Concurrent monthly billing / CAM run during reconcile** | The harness is read-only, so it can't corrupt anything; at worst it reports a transient mid-transaction state. Billing itself is serialised by `Cache::lock('billing:run:'.$period, 900)` (`MonthlyBillingService.php:34`) and CAM billing by `lockForUpdate` + in-transaction re-check (`CamReconciliationService.php:185-188`), so committed data the harness reads is internally consistent. |

---

## Invariants + gotchas

- **Read-only, always.** The service mutates nothing (`BooksReconciliationService.php:8-18`). It is safe to run any time, in production, repeatedly. It detects drift; it does not repair it.
- **One piastre is the line.** `EPS = 0.01`. Anything within a piastre is "tied"; anything beyond is a discrepancy. CAM 6a relaxes to one piastre **per allocation**.
- **Check 2 is a literal mirror of the source of truth.** It re-derives `paid_amount` with the exact inputs and rounding of `Invoice::recomputeTotals()`. The project invariant *"never set `paid_amount`/`balance` directly — go through `recomputeTotals()`"* is what makes this check meaningful: if everyone obeys it, Check 2 is always green; a red Check 2 means someone bypassed the rule (a bug, a manual edit, or a missed recompute).
- **The remedy for a red book is `recomputeTotals()`, not a column edit.** Editing the stored column to match would mask the cause; re-running the recompute removes the drift at its source. (For funds: `recomputeAccrued()` / `recomputeSpent()`; for CAM, the reconciliation service.)
- **`outstandingAR` ≠ "sum of all balances".** It is deliberately the canonical open-and-owed AR (status-filtered, balance > 0). Don't "simplify" it to all non-cancelled invoices — that misstates AR and was a known trap.
- **Read AR net, not gross.** `netAR = outstandingAR − creditOutstanding`. Standing credit is a real offsetting liability.
- **`--month` is asymmetric.** It scopes the invoice checks and control totals by `issue_date`, but the payment-allocation, marketing-fund, and CAM-pool checks always run portfolio-wide. A clean `--month=2026-06` does not by itself certify a clean fund/pool elsewhere — though those global checks still ran and would have failed the whole report if drift existed anywhere.
- **The exit code is the gate.** In CI or a close checklist, rely on exit 0/1 — non-zero means "do not close / do not file until resolved".
- **First 20 discrepancies per check.** The CLI lists up to 20 per check then "… N more" (`ReconcileBooksCommand.php:53-58`). The full list lives in the returned `checks[i].discrepancies` array (use the service directly, or a test, to see them all).
- **Session hardening this check suite now reflects (current correct behaviour):**
  - `c022f9f` — a CAM **negative** true-up is modelled as a **credit note**, not a lost negative charge (Check 6 accepts a credit-note backing).
  - `e9e6235` + `67ae0e0` — a CAM **positive** true-up is settled **immediately on a recovery invoice**, so Check 6's "charge must have reached a non-cancelled invoice" rule is satisfiable and catches the lost-revenue class instead of masking it.
  - `8a0a5c6` / `91fb4be` — lock-safe CAM allocation billing (`lockForUpdate` + in-txn re-check) so a concurrent run can't clobber a billed allocation or leave it unbacked.
  - `bb1ceed` — **net AR** for credits (the `creditOutstanding` / `netAR` totals) + the monthly billing **run-lock** (`Cache::lock`) + late-fee via `recomputeTotals()` (so Checks 1–3 stay consistent after a late fee).
  - `76bc843` / `88816d4` — cancel returns consumed credit; `credited` does **not** reverse (prevents a double-refund and negative net AR) — which is why Check 2/3 keep `credited` invoices on the books.
  - `3057520` / `88816d4` — prorated first-month invoice no longer double-bills the month (keeps `invoiced` honest).

---

## When to run it (the operator's playbook)

- **Before a monthly close** — confirm the prior month ties out before you lock it: `php artisan billing:reconcile --month=YYYY-MM`.
- **Before a VAT / tax filing** — the `vatTotal` control total is your filing figure; a green run certifies it equals the sum of per-item VAT and that totals are internally consistent.
- **In CI / a pre-deploy gate** — exit-non-zero on any discrepancy lets it block a release that would ship drift.
- **After any bulk data operation or migration** that touched invoices, payments, credit notes, CAM, or marketing — to confirm nothing drifted.
- **When an accountant's external ledger disagrees with a screen** — the control totals are the reconciliation handshake; the per-check discrepancies pinpoint the exact rows.
- **After a hot-fix to any money path** — to confirm the fix didn't leave residue.

It is the same suite of guarantees the regression/scenario tests assert, run against **live data** instead of fixtures — your standing proof that the books still tie out.

---

## Where it lives in the code (file:line index)

| Concern | Location |
|---|---|
| Reconciliation service (all six checks + control totals) | `app/Services/Reconciliation/BooksReconciliationService.php` |
| — Read-only / purpose docblock | `…/BooksReconciliationService.php:8-18` |
| — `EPS` (one-piastre tolerance) | `:22` |
| — Invoice universe + `--month` filter | `:30-39` |
| — Check 1 `invoice_composition` | `:42-59` |
| — Check 2 `paid_amount` (mirrors `recomputeTotals`) | `:61-73` |
| — Check 3 `balance` | `:75-83` |
| — Check 4 `payment_allocation` | `:85-93` |
| — Check 5 `marketing_budget` | `:95-115` |
| — Check 6 `cam_allocations` (tie + backing + reached-invoice) | `:117-152` |
| — Control totals | `:154-178` |
| — `ok` = every check passed | `:181` |
| — `check()` / `disc()` helpers | `:187-196` |
| CLI command `billing:reconcile` | `app/Console/Commands/ReconcileBooksCommand.php` |
| — Signature / `--month` option | `ReconcileBooksCommand.php:17` |
| — Tables + per-discrepancy output (first 20) | `:29-58` |
| — Exit codes (SUCCESS / FAILURE) | `:63,69` |
| The source of truth it mirrors — `Invoice::recomputeTotals()` | `app/Models/Invoice.php:255-283` |
| Cancel returns consumed credit (why cancelled is excluded) | `app/Models/Invoice.php:223-248` |
| `Tenant::outstandingBalance()` (net AR definition) | `app/Models/Tenant.php:160-176` |
| `MarketingBudget::recomputeAccrued()` / `recomputeSpent()` | `app/Models/MarketingBudget.php:65-86` |
| CAM positive true-up → immediate recovery invoice | `app/Services/CamReconciliationService.php:236-286` |
| CAM negative true-up → credit note, auto-applied | `app/Services/CamReconciliationService.php:288-306` |
| Monthly billing run-lock | `app/Services/MonthlyBillingService.php:34` |

---

## Related

- [`docs/money/00-money-model.md`](00-money-model.md) — the single source of truth (`recomputeTotals`), every money column, the AR invariant this harness audits.
- [`docs/money/01-billing-monthly.md`](01-billing-monthly.md) — the monthly billing run, proration, and the run-lock.
- [`docs/money/03-marketing-levy.md`](03-marketing-levy.md) — the 5%-of-rent marketing levy that Check 5 reconciles.
- [`docs/money/04-cam-reconciliation.md`](04-cam-reconciliation.md) — CAM pools, pro-rata allocations, positive/negative true-ups that Check 6 reconciles.
- [`docs/money/05-percentage-rent.md`](05-percentage-rent.md) — percentage-rent charges that can also generate true-ups landing on invoices.
- [`docs/money/07-credit-notes.md`](07-credit-notes.md) — credit-note lifecycle, application, reversal-on-cancel; `credit_applied_amount` and `creditOutstanding`.
- Project invariants: `CLAUDE.md` (Money / AR section). Books tie-out narrative: `docs/BUSINESS-RULES.md`.
- Module-level companions: [`docs/modules/05-billing-invoices.md`](../modules/05-billing-invoices.md), [`docs/modules/06-payments.md`](../modules/06-payments.md), [`docs/modules/07-credit-notes.md`](../modules/07-credit-notes.md), [`docs/modules/08-cam.md`](../modules/08-cam.md).
