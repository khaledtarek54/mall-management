# Atriom — Money Paths & System Behaviour (team reference)

**Who this is for:** the client's business/finance team and any engineer who needs to understand—_in complete detail_—how Atriom handles money and how the system behaves. Every section is grounded in the actual code (with `file:line` pointers you can open and verify) and reflects the **current** behaviour, including the money-path hardening from the June 2026 review.

**How to read it:** start here for the map and the one invariant that governs everything, then open the [`docs/money/`](money/) section you care about. Each section has the same shape: _plain-language summary → exact rule/formula → worked numeric examples → every edge case → invariants & gotchas → code index._

> Related, broader docs: [docs/OVERVIEW.md](OVERVIEW.md) (the whole system), [docs/BUSINESS-RULES.md](BUSINESS-RULES.md) (sign-off rules), [docs/PROJECT-MAP.md](PROJECT-MAP.md) (where everything lives / routes), and the per-module docs in [docs/modules/](modules/).

---

## The one rule that governs all money

> **`Invoice::recomputeTotals()` is the single source of truth.** Nothing else ever writes `paid_amount` or `balance` by hand.

```
paid_amount = Σ(captured payment allocations)      # cash
            + credit_applied_amount                # credit notes
            + Σ(TenantCreditApplication.amount)    # on-account tenant credit
            + Σ(DepositApplication.amount)         # netted security deposit
balance     = round( max(0, total − paid_amount), 2 )     # floored at 0; cancelled ⇒ 0
```

**Four settlement channels, and every calculation that decides "how much of this invoice is settled" must count all four.** Only the first is cash — which is why the void guard (`Invoice::capturedCashPaid()`) nets the other three out, and why adding a fifth would also mean updating the cancel-invoice release and **both** payment over-allocation guards. Item-level allocation (`invoice_item_payment`) is **not** a fifth channel: it records how an already-counted settlement splits across the lines, and `App\Support\InvoiceItemSettlement` derives every per-line figure from `paid_amount` so the item outstandings always sum back to `balance`.

Every way money can move — a gateway callback, an admin recording a cheque, a credit note applied, a deposit netted at move-out, a late fee added, an invoice cancelled — funnels back through this one function, recomputed from the same inputs the same way. That is why the books cannot silently drift, and why the reconciliation harness (§12) can prove the tie-out at any time.

Currency is **EGP** throughout; every money value is `round(x, 2)`. Only **`captured`** payments count toward AR. Reporting and collection exclude **`cancelled`** and **`credited`** invoices.

---

## The money lifecycle at a glance

```
 Lease (terms: base rent, service charge, deposit, escalation, % rent)        §09
   │  seeds standing Charges (base rent VAT-exempt · service charge +14% VAT) §02
   ▼
 Monthly billing run  ──>  Invoice (issued)   [first month prorated by days]  §01
   │   • VAT 14% on service charge                                            §02
   │   • 5% marketing levy on base rent  ──> Marketing Fund accrual           §03
   │   • % rent (locked sales declaration) · utilities (meter reads)     §05 ·§10
   ▼
 Payment (portal / mobile / Paymob link / admin)  ──> recomputeTotals         §06
   │   • overdue + grace ⇒ late fee                                           §08
   ▼
 Credit notes: issue · apply (FIFO, lock-safe) · void · cancel-reversal       §07
   │
   ▼
 Year-end CAM reconciliation:                                                 §04
   • under-collected ⇒ recovery invoice (immediate)
   • over-collected  ⇒ credit note (auto-applied)
   │
   ▼
 ETA e-invoicing (Egyptian Tax Authority) submission                          §11
   │
   ▼
 `php artisan billing:reconcile`  ── proves the books tie (the safety net)    §12
```

---

## The sections

| # | Section | What it covers | Must-know rule |
|---|---------|----------------|----------------|
| 00 | [The Money Model](money/00-money-model.md) | The AR invariant, every money column, status lifecycles, AR exclusions, rounding, concurrency | Never write `paid_amount`/`balance` directly — only `recomputeTotals()` |
| 01 | [Monthly Billing](money/01-billing-monthly.md) | Lease eligibility, charge frequencies, **first-month proration** (day-granular, full-precision), the 3 idempotency/lock guards | Proration rounds the **amount**, not the day-factor; idempotency is a period-**overlap** check |
| 02 | [VAT & Tax](money/02-vat-and-tax.md) | VAT **14%** on service charges, **base rent exempt**, per-line → header rollup, ETA tax codes | Exempt always wins; `vat = round(amount × rate/100, 2)` per line |
| 03 | [Marketing Levy & Fund](money/03-marketing-levy.md) | **5% of base rent** levy, billed as a VAT-exempt line, fund accrual **derived** from billed lines | Accrual is recomputed from line items, never incremented |
| 04 | [CAM Reconciliation](money/04-cam-reconciliation.md) | Pools, pro-rata by leased sqm, **positive → immediate recovery invoice**, **negative → auto-applied credit** | Positive true-up settles immediately; the traceability charge is `is_active=false` so it's never double-billed |
| 05 | [Percentage Rent](money/05-percentage-rent.md) | Tenant sales declarations, breakpoint (artificial/natural), lock → charge | Only a **locked** declaration generates a charge |
| 06 | [Payments & Online Pay](money/06-payments.md) | Multi-invoice allocation, over-allocation guard, channels, **Paymob** session→callback→capture, HMAC | A captured payment triggers `recomputeTotals`; over-allocation is blocked under lock |
| 07 | [Credit Notes](money/07-credit-notes.md) | issue · **apply (lock-safe, FIFO)** · void · **cancel-reversal** | Apply only to live invoices; cancelling a credit-consuming invoice returns the credit; `credited` does **not** reverse |
| 08 | [Late Fees](money/08-late-fees.md) | Eligibility (overdue + balance + grace), the fee formula, idempotency | Applied **once**, via `recomputeTotals` (no direct balance write) |
| 09 | [Leases: Rent, Deposit, Escalation](money/09-leases-deposit-escalation.md) | Base rent + service charge, security deposit, annual escalation, renewal/termination | Deposit & escalation values + the charges a lease seeds |
| 10 | [Utility Meters & Billing](money/10-utilities.md) | Meter types, consumption = current − previous, cost calc, the NOT-NULL coercion | A reading becomes a utility charge/line |
| 11 | [ETA E-Invoicing](money/11-eta-einvoicing.md) | When invoices submit, the JSON build, tax codes, signing seam, **mock mode** | Mock vs certified — read this before go-live claims |
| 12 | [The Reconciliation Harness](money/12-reconciliation-harness.md) | `billing:reconcile`, every check, control totals — **the operator's trust tool** | Run it to prove the books tie; it catches drift |
| 13 | [System Behaviours](money/13-system-behaviors.md) | Request state machine + terminal immutability, property scoping, RBAC, scheduled jobs, notifications | The non-money machinery that keeps the rest correct |

---

## The safety net (read §12)

`php artisan billing:reconcile [--month=YYYY-MM]` re-derives every invoice's composition, asserts `paid = captured + applied credit` and `balance = max(0, total − paid)`, checks no payment is over-allocated, ties the marketing fund and the CAM allocations — and, since the June 2026 review, asserts every billed CAM true-up **actually reached a non-cancelled invoice** (so lost revenue is caught, not masked). It prints control totals (invoiced / collected / credit applied / outstanding AR / net AR / VAT) an accountant can reconcile against. This is the tool that turns "we think the books are right" into "we can prove it."

---

## June 2026 hardening — what changed and why it matters

The money paths were put through a multi-pass adversarial review. The behaviours documented here are the **post-review** behaviours. The notable corrections, so the team understands the _current_ truth:

- **First-month proration** is day-granular and full-precision (the per-line _amount_ is rounded, not the factor) — §01.
- **CAM positive true-up** settles **immediately** on a dedicated recovery invoice (never a deferred charge that an ended lease's monthly run would skip); its traceability charge is `is_active=false` and the invoice's period is the **reconciled year**, so it neither double-bills nor pre-empts the tenant's regular monthly invoice — §04.
- **CAM negative true-up** becomes a credit note **auto-applied** FIFO to open invoices (never a negative charge, which would floor to zero and lose money) — §04.
- **Credit-note apply** is lock-safe and capped; **cancelling** a credit-consuming invoice issues an offsetting note (the credit is returned), while a `credited` invoice deliberately keeps its settlement — §07.
- **Cancelled invoices** carry a **zero** balance and are excluded from AR widgets/reports — §00.

---

_Docs are part of "done": when a money rule changes, update its `docs/money/NN-*.md` section (and this index if cross-cutting) in the same commit._
