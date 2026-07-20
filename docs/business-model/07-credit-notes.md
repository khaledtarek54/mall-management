# Credit Notes — the business model

> A credit note (إشعار دائن / إشعار خصم) is how the operator **reduces what a tenant owes** for a
> reason of their own: an overcharge, a dispute, an agreed discount, a return. It is an *internal
> accounting adjustment* — not cash. Pairs with the technical spec in
> [modules/07](../modules/07-credit-notes.md) and the close-out in
> [gap-analysis](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. What it is (and what it is *not*)

An invoice says "the tenant owes us X." Sometimes that X is wrong, or the operator agrees to give
some of it back — a service was disputed, a charge was too high, a goodwill discount. A **credit
note** records that reduction as its own document, so the books show *why* the balance dropped and
the reduction survives every later payment recompute.

In the ledger a credit note is a **sales return**: it posts *Dr Sales Returns (contra-revenue) + Dr
VAT Payable / Cr Accounts Receivable*. So it walks back both the revenue **and** the 14% VAT on the
part it credits — exactly reversing what the original invoice booked.

**Credit note vs. tenant credit — two different things.** A **credit note** is an *operator
decision* to reduce a charge (Dr Sales Returns). A **tenant credit** (the "credit on account" from an
*overpayment*, [see payments](06-payments.md)) is the tenant's *own money* held in advance (Dr
Unearned Revenue). Different origin, different ledger account, different instrument. The system keeps
them cleanly separate.

## 2. The life of a credit note

```
draft ──issue──▶ issued ──apply──▶ (issued while balance remains) ──apply──▶ applied (drained)
  │                 │                                                            │
  └──void──▶ void   └──void──▶ void (only while UN-applied)          reverse ◀───┘ (un-apply → issued)
```

- **Draft** — freely editable. You set the tenant, the reason, and the line items (each with its own
  VAT rate). Its number is auto-assigned per property per month (`CN-AW-202607-0001`).
- **Issue** — it becomes a live accounting document (the sales-return posts to the ledger). From here
  its target, date, and line items are **frozen** — a mistake is corrected by voiding, not a silent edit.
- **Apply** — draw its balance down against one of the tenant's open invoices. A 5,000 note applied to
  a 3,000 invoice settles it and keeps 2,000 available for the next one.
- **Void** — cancel an **un-applied** note. An already-applied note can't be voided (its credit is
  live); **Reverse** it first.
- **Reverse** — un-apply an applied note: every invoice it settled re-opens and the note returns to
  available. The supported "undo."

## 3. Scenarios, with worked numbers

**Overcharge.** A tenant was billed a 1,140 service charge (1,000 + 14% VAT) that shouldn't have
applied. Issue a credit note with a 1,000 line at 14% → total **1,140**. The ledger books *Dr Sales
Returns 1,000 + Dr VAT Payable 140 / Cr Receivables 1,140*. Apply it to the open invoice → the
tenant's balance drops 1,140 and the VAT you owe the authority drops 140. **Tip:** picking the
original invoice on the form pulls its lines in, so the VAT is inherited correctly — you don't
re-key it (and can't accidentally credit a 14% charge at 0%).

**Drain across two invoices.** A 5,000 credit, tenant has invoice A (2,000) and invoice B (4,000).
Apply to A → A is cleared, 3,000 left on the note. Apply to B → 3,000 comes off B (1,000 still owed),
the note is fully **applied**. One note, two invoices, all same-tenant.

**A cancelled invoice returns the credit — once.** Invoice 10,000, you apply a 3,000 credit (owed
now 7,000). Then the whole invoice is **cancelled**. The credit doesn't vanish and it isn't
double-counted: the application is **un-applied** — the original 3,000 note goes back to *available*,
and the books stay tied. *(This is the corrected behaviour: the earlier design issued a second note,
which posted the sales-return twice and drove AR negative.)*

**Changed your mind.** You applied a credit to the wrong invoice. Hit **Reverse application** on the
note — that invoice re-opens and the note is available again to apply where you meant.

## 4. The guardrails (why the system says "no")

- **You can't credit another tenant.** A credit note settles only *its own tenant's* invoices.
- **You can't credit another property.** A note is bound to one property (the first invoice it
  settles, or the lease you pick) so its sales-return lands in that mall's books — and its owner's
  statement. It then only settles that property's invoices. (A standalone tenant-level note is visible
  only under the properties where that tenant actually leases — never portfolio-wide.)
- **You can't void an applied note.** Reverse it first (or issue an offsetting one) — voiding live
  credit would strand it.
- **You can't delete a note whose credit is applied.** Same reason — reverse first.
- **You can't back-date a note into a closed month.** Issuing posts a ledger entry dated the issue
  date; if the accountant has closed that month, the entry could never post. Refused.
- **You can't inflate the total.** The note's total is always re-derived from its line items on
  save — a tampered header amount can't reach the ledger.

## 5. Output & records

Every credit note has a **printable PDF** (إشعار دائن) — download it from the list or the note, to
hand or send the tenant for their own VAT books. The accountant can **export** the credit-note
register and filter it by **issue-date range** for a month-end close.

## 6. Built in this close-out

- The **credit-note PDF**, the **exporter + period filter**, and **VAT inheritance from the source
  invoice**.
- A **guided Reverse** action (the supported way to undo an application) — closing the old "applied
  note → dead-end" where Void was simply hidden with no explanation.
- The correctness fixes above: **un-apply on cancel** (no double sales-return), **cross-tenant** and
  **cross-property** apply guards, the **closed-period** and **tampered-total** guards, and a
  **delete guard** so an applied note's credit can never be stranded.

## 7. What's still deferred (and the trigger)

- **ETA e-filing of credit notes.** Egypt's Tax Authority wants a credit note against a filed
  e-invoice reported and linked to the original's UUID. It's wired for invoices but not yet for credit
  notes. *Trigger: the day ETA goes live (it's on the mock endpoint today).* This is the biggest
  compliance item.
- **A cash refund path.** A credit note reduces an *open* invoice; it can't pay money back to a tenant
  who has no open balance. *Trigger: a tenant demands cash back on a closed lease.*
- **Maker-checker approval** on issuing credits (a second approver above a threshold). *Trigger: a
  second finance user, or an auditor asking for segregation of duties.*
- **Tenant-portal visibility** of credit notes on the web (the mobile API already exposes them), and
  **auto-applying** an unapplied credit at the next billing run. *Triggers: tenant self-service; credit
  volume.*
