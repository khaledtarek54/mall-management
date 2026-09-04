# Bank reconciliation — the plan

**Status:** written 2026-08-11. **Slices 1, 2, 3, 5 and 6 shipped the same day** — the register, statement import, matching with its guards and workspace, the reconciliation statement, and the ageing. **Only slice 4 (suggested matches) remains, and it is the one to be slowest about.**

> **The gap, verified rather than quoted.** There is no `bank_accounts` model, no statement, no
> statement line, no matching, and no migration for any of them. `BooksReconciliationService` is the
> internal AR/AP tie-out and `CamReconciliationService` is CAM — neither touches a bank. The cash and
> bank GL balances are **asserted from the documents that claim to have moved money, and never
> verified against what the bank says actually moved.** Two independent gap analyses (Odoo §3, the
> competitor review) reached this row separately and both put it first.
>
> **Why only slice 1 is built.** The matching engine is the risky half — a wrong match silently marks
> money as verified — and it wants its own session starting green. Slice 1 is the opposite: a register
> that changes no posting and no balance, asserted by a test, so it could ship on its own the day the
> plan was written. This document is what makes the next session short.

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
  ✅ **Built** — see slice 1 below.
- Pointing the existing `bank` ROLE at a default account per property is deliberately **not** part of
  slice 1: that changes the posting path, and it belongs with the first slice that needs to know
  *which* account rather than with the one that creates the row. Until then the role resolves exactly
  as it always has.
- Only then can a statement belong to something.

**Do not skip this and reconcile "the bank role".** With two accounts in one property the role is
ambiguous, and a reconciliation that silently mixes two accounts is worse than none — it will
balance, and it will be wrong.

> **✅ Closed 2026-08-22 (EG-12).** For eleven days this was true of the shipped system: slice 1 said
> "nothing posts through it and that is asserted by a test", which was honest about the slice and
> left the matcher reading a chart account that both banks' money landed in. `bank_account_id` is now
> on the six documents a statement line can explain, and `App\Support\MoneyAccount` is the ONE seam
> all thirteen journalizers resolve through — the document's bank account, then the rail's account,
> then the posting role. Null still means "the rail decides", so an install that has not filled the
> register in posts exactly as it did.

---

## 4. Slices, in the order they should ship

Each one is independently useful, which is the test of whether the slicing is honest.

1. ✅ **`bank_accounts` + the register — shipped 2026-08-11.** Property-owned (classified ISOLATED,
   guarded by `assertAssetInScope` on create *and* edit), searchable, its own `bank_accounts.*`
   permissions, optionally linked to the postable chart account it IS. **Nothing posts through it and
   that is asserted by a test** — the `bank` role still resolves exactly as before, so the slice is
   additive and could ship alone. What is deliberately NOT done yet: pointing the `bank` role at a
   default account per property. That is a change to the posting path and belongs with the slice that
   first needs to know *which* account, not with the one that creates the row.
2. ✅ **Statement import — shipped 2026-08-11.** `bank_statements` (account, period, opening/closing
   balance, unique per account+period) + `bank_statement_lines`. `ImportBankStatementService` parses
   a CSV — forgiving about headings, strict about values, EN and AR — and folds a debit/credit pair
   into ONE signed amount, so nothing downstream ever sees two columns that can contradict each
   other. **Idempotency is the database's**, not the service's: a unique `(statement, row_hash)`
   index over date + amount + reference + description + **occurrence**. The occurrence number is the
   part worth remembering — hashing content alone would collapse a bank's genuine duplicate (two
   identical fees on one day) into one row and quietly lose money from the evidence, and the
   statement's own arithmetic would then fail while blaming the operator. Posts nothing, asserted by
   a test.

   `BankStatement::isSelfConsistent()` checks opening + Σ lines = closing — the cheapest signal that
   a file was truncated, half-mapped, or had its sign convention read backwards, and a precondition
   for anything in slice 3.
3. 🟡 **Manual matching — the ENGINE shipped 2026-08-11; the screen has not.** `bank_matches` +
   `MatchBankStatementLineService` (`candidatesFor` / `match` / `unmatch` / `coverage`), fully tested.

   **A match posts nothing** — it annotates two rows that already exist, so creating or removing one
   leaves every account where it was. That is what stops this becoming a back door into the GL.

   **Candidates come from the ledger**, not a list of models: journal lines on the bank account's own
   chart account, so every money source is included the day it ships.

   **Cardinality is asymmetric on purpose.** A statement line may carry several matches (a bank shows
   one line for two cheques banked together); a journal line may be matched at most once, enforced by
   a unique index, because reporting the same money as verified twice is the failure the module
   exists to prevent.

   Four guards, each of which would otherwise still BALANCE and look finished: already-matched,
   wrong account, wrong direction (a receipt cannot be explained by a payment), and a voided entry —
   which explains nothing, its reversal being a separate matchable line. An unmapped bank account
   returns no candidates and says so, rather than an empty list dressed up as "nothing to match".

   ✅ **The screen shipped too** — `BankStatementResource` with the lines beneath it: import, match,
   unmatch, an unmatched-only filter, and a per-line state of matched / partly matched (with the
   amount still outstanding) / unmatched. Importing and matching are one workflow, so they are one
   screen.
4. ⬜ **Suggested matches — the only slice left, and the one to be slowest about.** Exact amount +
   date within a tolerance + reference similarity, offered as a suggestion an operator confirms.
   **Never auto-confirmed**: a wrong match marks money verified, and a suggester that is usually
   right is precisely the thing that stops being read. The manual path already works, so this buys
   speed and risks trust — which is why it was sequenced last and should stay there until the
   operator has reconciled a real month by hand and can say where the tedium actually is.
5. ✅ **The reconciliation itself — shipped 2026-08-11.** `ReconcileBankStatementService`, on the
   statement page as its terms rather than a verdict:

       ledger balance = statement closing + Σ(unmatched book postings) − Σ(unmatched statement lines)

   The two terms on the right are the only two ways the bank and the books can legitimately differ —
   money the books know that the bank has not shown (an unpresented cheque), and money the bank moved
   that the books have not recorded (a charge). **Outstanding items are counted from ALL time up to
   the period end, not just within the period**: a cheque written in February and unpresented in
   March is outstanding in March too, and windowing it to the period is the classic way to make a
   reconciliation balance that should not.

   `reconciled` is deliberately NOT "every line is matched" — it is "every difference is explained",
   which is what the identity tests. Outstanding items are explanations, not failures. An unmapped
   account reports that it cannot be reconciled rather than a row of zeroes, because zeroes read as
   reconciled.
6. ✅ **Unreconciled ageing — shipped 2026-08-11.** An age badge on each unexplained line, an
   "unexplained over 30 days" filter, and a **stale count on the statement list** — because an
   operator scanning statements should not have to open each one to find the questions nobody has
   asked. The age is silent on a matched line: it is explained, and how long that took is not a
   question anyone is asking.

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

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-148

in the slice-3 (matching workspace) section: "**`coverage()` answers from the eager load the workspace already paid for.** It re-queried a line's matches on every call, and `LinesRelationManager` asks it four times per rendered row (the age cell, the match-state cell, that cell's colour, the Match action's `visible()`) over a query that has already run `->with('matches.journalLine.entry')` — two statements a call, eight throwaway queries a line, plus a ninth from the Unmatch action's `matches()->exists()`. Both now prefer the loaded relation and fall back to a query, the same shape as `Invoice::collectableBalance()` preferring an eager-loaded `writeOffs`. **It is allowed here because `coverage()` decides nothing** — it words a badge and hides a button, while every refusal rests on `match()`'s own `lockForUpdate()` read of the journal line — so no guard is being answered from a possibly-stale in-memory collection. `TheBankMatchingWorkspaceAsksTheDatabaseOnceTest` COUNTS the statements rather than inspecting the code, and pairs the zero-query claim with a cold read that must still be right."

