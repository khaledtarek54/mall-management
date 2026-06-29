# Test cases — Billing, Invoices & Payments (the money core)

> Worked example for the financial core — the highest-risk area. **Module docs:**
> [05-billing](../../modules/), [06-payments](../../modules/). The invariant:
> `Invoice::recomputeTotals()` is the single source of truth —
> `paid_amount = captured payments + credit_applied`, `balance = total − paid_amount`.

**Last full run:** `____` by `____`

## Cases

| # | Area | Case (steps) | Role | Expected result | Result | Tester / date |
|---|------|--------------|------|-----------------|--------|---------------|
| 1 | Monthly run | Generate the month for active leases | manager | One invoice per lease; correct period; no duplicates on re-run (idempotent) | | |
| 2 | Tax math | Inspect an invoice with base rent + service charge | manager | Base rent **VAT-exempt**; service charge **+14% VAT**; **5% marketing levy** on base rent | | |
| 3 | Proration | Lease commencing mid-month | manager | First invoice prorated correctly | | |
| 4 | Frequencies | Quarterly / annual charge | manager | Charge appears in the right period only | | |
| 5 | Payment | Record a full payment | accounting | `status=paid`, `balance=0`, `paid_amount=total` | | |
| 6 | Split allocation | One payment across 2 invoices | accounting | Each invoice's `paid_amount`/`balance` updated; sum of allocations = payment | | |
| 7 | Over-allocation | Allocate more than the invoice balance | accounting | Rejected / capped — no negative balance | | |
| 8 | Reconcile | Run `billing:reconcile` after the above | — | Exit 0, "Books tie out" | | |
| 9 | Credit issue | Issue a credit note | accounting | `status=issued`, `balance=total` | | |
| 10 | Credit apply | Apply credit to a live (issued/partial/overdue) invoice | accounting | Invoice balance drops by applied amount; credit balance reduced | | |
| 11 | Credit guard | Apply credit to a cancelled / disputed / paid invoice | accounting | **Refused** (no credit leak) | | |
| 12 | Credit void | Void an unapplied credit; try to void an applied one | accounting | Unapplied voids; applied **refuses** (issue offsetting note instead) | | |
| 13 | Late fee | Run `billing:apply-late-fees` on an overdue invoice; run again | — | Fee applied once (idempotent) | | |
| 14 | Overdue scan | Run `billing:scan-overdue-invoices` | — | Past-due invoices flagged overdue; not-yet-due untouched; idempotent | | |
| 15 | Paymob | Pay an invoice via the payment link; simulate the callback | tenant | Valid HMAC → captured + invoice paid; **invalid HMAC rejected** | | |
| 16 | Permissions | `viewer` tries to create an invoice / record a payment | viewer | Actions hidden / forbidden | | |
| 17 | Scoping | Manager scoped to property A views invoices | manager (A) | Only property-A invoices | | |
| 18 | Statement | Download a tenant statement PDF | accounting | Correct opening/closing balance + line items | | |

## Exploratory charter
- **Charter:** "Try to break the books — partial payments, credit + payment on the same invoice, void/refund edge cases, re-running every scheduled billing command, concurrent payments on one invoice."
- **Notes:**
