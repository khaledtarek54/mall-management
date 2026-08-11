# Bank reconciliation — the plan

**Status:** planned, not started. Written 2026-08-11.

> **The gap, verified rather than quoted.** There is no `bank_accounts` model, no statement, no
> statement line, no matching, and no migration for any of them. `BooksReconciliationService` is the
> internal AR/AP tie-out and `CamReconciliationService` is CAM — neither touches a bank. The cash and
> bank GL balances are **asserted from the documents that claim to have moved money, and never
> verified against what the bank says actually moved.** Two independent gap analyses (Odoo §3, the
> competitor review) reached this row separately and both put it first.
>
> **Why it is not started here.** This is a money module with a matching engine, and a half-built
> matcher is worse than none: a wrong match silently marks money as verified. It wants its own
> session with the suite green at the start. This document is what makes that session short.

---

## 1. What "reconciled" has to mean

Every control here reduces to one sentence, and the design follows from it:

> **For a period, every line on the bank statement is explained by exactly one thing in the books,
> and every posting to that bank account is explained by exactly one line on the statement.**

Both directions matter, and the second is the one systems forget. An unmatched *statement* line is
money that moved without the books knowing — a direct debit, a bank charge, a customer paying by
transfer nobody recorded. An unmatched *book* posting is the opposite and is worse: the books say
cash arrived and the bank disagrees. Only checking one direction catches half the errors and reads
as a clean reconciliation.

---

## 2. What the books can already offer a matcher

The system knows more than it looks. Everything that credits or debits `bank`/`cash` today:

| Document | Direction | Date column | Reference the bank would show |
|---|---|---|---|
| `Payment` (captured) | in | `payment_date` | `reference`, `cheque_number`, gateway txn id |
| `VendorBillPayment` (not voided) | out | `payment_date` | `reference` |
| `Expense` (recorded) | out | `expense_date` | `reference` |
| `DepositTransaction` | in / out | `transaction_date` | `number` |
| `Payroll` (net paid) | out | `period_month` | `number` |
| `MarketingSpend` | out | `spent_on` | `receipt_reference` |
| `Custody` / `CustodyTransaction` | out / in | `custody_date` / `transaction_date` | `reference` |
| `EmployeeAdvance` / `…Repayment` | out / in | `advance_date` / `repaid_on` | — |
| `Disbursement` (paid) | out | `paid_on` | `reference`, `external_reference` |
| `FixedAsset` (funded from bank) | out | `acquisition_date` | — |

**This table is `LedgerPoster::JOURNALIZERS` filtered by "touches a cash or bank account", and it
must be derived from the registry rather than re-typed** — the lesson the GL registry already paid
for. A matcher that walks a hand-written list of sources goes stale the day a new money source
ships, and its failure mode is silence: the new source's payments simply never appear as candidates
and sit unmatched forever.

**The honest way to derive it:** a candidate is any `journal_line` on an account whose role resolves
to `bank` or `cash`, joined back to `journal_entries.source_type/source_id`. That is one query
against the ledger rather than a union of ten Eloquent scopes, it inherits every source
automatically, it carries the property dimension for free, and it reconciles the thing that is
actually being reconciled — **the ledger**, not a parallel opinion of it.

---

## 3. The one modelling decision that has to come first

**`bank` and `cash` are account ROLES today, not accounts an operator manages.** There is no
`bank_accounts` table, so the system cannot express "the CIB current account" versus "the NBE
account", and `account_mappings` resolves one `bank` role per property.

A reconciliation is always *of one account*. So the first slice is not the matcher — it is:

- `bank_accounts`: name, bank, account number (masked in the UI), currency, **its ledger account**,
  property (`asset_id`, since `PropertyIsolation` will demand a classification either way), active.
- Point the existing `bank` role at a default account per property so nothing changes on day one.
- Only then can a statement belong to something.

**Do not skip this and reconcile "the bank role".** With two accounts in one property the role is
ambiguous, and a reconciliation that silently mixes two accounts is worse than none — it will
balance, and it will be wrong.

---

## 4. Slices, in the order they should ship

Each one is independently useful, which is the test of whether the slicing is honest.

1. **`bank_accounts` + the mapping.** Nothing reconciles yet; the chart gains a real account per
   bank. Useful alone: the Posting Map stops being the only place a bank exists.
2. **Statement import.** `bank_statements` (account, period, opening/closing balance) +
   `bank_statement_lines` (date, description, reference, amount, direction). CSV first — a bank feed
   is an integration, and no Egyptian bank here has one. **Import must be idempotent on
   (account, date, amount, reference)**, because re-importing an overlapping export is what
   operators actually do.
3. **Manual matching.** A screen: unmatched statement lines on the left, unmatched book postings on
   the right, both filtered to the account and period; an operator links them. `bank_matches` rows,
   reversible. **This is already the whole control** — the automatic matcher is convenience on top,
   and shipping it first is how matching engines end up trusted before they are correct.
4. **Suggested matches.** Exact amount + date within a tolerance + reference similarity, offered as
   a suggestion an operator confirms. Never auto-confirmed: a wrong match marks money verified.
5. **The reconciliation itself.** Closing balance per the statement, plus unmatched book postings,
   minus unmatched statement lines, must equal the ledger balance for that account on that date.
   That arithmetic IS the report, and it is what an auditor asks for.
6. **Unreconciled ageing.** A statement line unmatched for 30 days is a question nobody has asked.

---

## 5. What this must NOT become

- **Not a second ledger.** A match is an annotation on an existing posting; it never moves money,
  never posts an entry, and reconciling changes no balance. If a bank charge appears with no book
  entry, the answer is to *record an expense* through the normal path and then match it — not to let
  the reconciliation screen create postings, which is how a reconciliation becomes a back door into
  the GL that bypasses every posting-date and approval guard.
- **Not deposit batches.** The Yardi benchmark already declined them (§10 row 6: one operator, one
  bank, and the receipt already carries its method and date). Grouping receipts into bank deposits is
  the Voyager answer to a problem this operator does not have; revisit only if the bank statement
  genuinely shows one line per batch rather than per receipt.
- **Not a bank feed.** Listed as declined breadth for the same reason as multi-currency.

---

## 6. What it will expose, and that is the point

A first reconciliation of a real month will almost certainly find: bank charges nobody recorded,
a customer transfer applied to the wrong tenant or to nothing, a cheque presented in a different
month from the one it was recorded in (the `payment_date` vs cleared-date distinction the PDC module
already understands), and duplicate expense entries. **Every one of those is a defect in the books
that no internal check can see** — `billing:reconcile` re-derives the books from the documents, so
it agrees with a wrong document. That is precisely why this row is the #1 control gap in two
independent analyses: it is the only check whose evidence comes from outside the system.

---

## 7. Effort

| Slice | Size |
|---|---|
| 1 — bank accounts | S |
| 2 — statement import | M |
| 3 — manual matching | M |
| 4 — suggestions | S–M |
| 5 — the reconciliation report | S |
| 6 — ageing | S |

Slices 1–3 are the control. 4–6 make it pleasant. **Ship 1–3 before anything in 4–6**, and treat any
pressure to start at 4 as the warning sign it is.
