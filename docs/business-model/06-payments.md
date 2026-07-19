# Payments — how a tenant's money reaches the books

> Companion to [docs/modules/06-payments.md](../modules/06-payments.md) (the technical reference).
> This is the plain-language version: what a payment is, how you record it, and every scenario with
> worked numbers.

A **payment** is a real cash receipt from a tenant — cash at the office, a bank transfer, InstaPay,
a card, or a cleared cheque. Recording it does two things at once: it **reduces the tenant's
outstanding balance** (the invoice moves toward paid) and it **posts to the general ledger** (Dr
Cash/Bank, Cr Accounts Receivable). You never edit an invoice's "paid" number by hand — it is always
recomputed from the payments allocated to it. That is the one rule that keeps the books honest.

---

## 1. The lifecycle — when is money "received"?

A payment has a status. Three of them mean **the money is really on the books**, and the system
treats them identically for accounts-receivable, the general ledger, and every "collected" figure:

| Status | Meaning |
|--------|---------|
| **Captured** | The money cleared. This is the normal state for a recorded receipt. |
| **Reconciled** | You matched it against your bank statement. Still real money — just further along. |
| **Settled** | The gateway/bank settled the batch into your account. Still real money. |

Moving a receipt **captured → reconciled → settled** is a bookkeeping step; it never changes the
tenant's balance or the ledger. The other three statuses are **reversals** — they take the money
back off the books and re-open the invoice:

| Status | Meaning |
|--------|---------|
| **Refunded** | You gave the money back (via the Void/Refund action). |
| **Failed** | A card capture failed / a chargeback. |
| **Bounced** | A cheque didn't clear. |

**You reverse a receipt through the "Void / refund" action, not by editing its status.** The action
makes you type a reason and records it in the audit trail; a bare status edit would move money with
no reason and no record, so the form doesn't let you pick a reversal status directly.

---

## 2. Recording a receipt — allocation

When you record a payment you say **which invoices it pays** (the allocation). Pick the tenant and
the system lists their open invoices, oldest first, and pre-fills the split for you; you can adjust
it. A receipt **must** settle at least one invoice.

**Scenario — a tenant pays one invoice in full.** Invoice INV-001 owes 11,400. Tenant transfers
11,400. You record a payment of 11,400 allocated 11,400 → INV-001. The invoice's balance drops to 0
and its status flips to **paid**. The ledger books Dr Bank 11,400 / Cr AR 11,400.

**Scenario — one receipt, several invoices (split).** A tenant clears three months at once: pays
34,200 cash. You allocate 11,400 to each of INV-001/002/003. One payment, three invoice lines, all
three invoices go to paid. (The tenant's receipt — email, portal, and mobile app — shows the split so
they know exactly which months are now cleared.)

**Scenario — a partial payment.** Invoice owes 11,400; the tenant can only pay 5,000 now. You record
5,000 allocated to it. The invoice goes to **partially paid**, balance 6,400. A later receipt clears
the rest.

**Scenario — an overpayment.** Invoice owes 9,000; the tenant pays 10,000. You allocate 9,000 to the
invoice; the remaining 1,000 is an advance (it books to *unearned revenue* — money you owe the tenant
in service). *Applying that credit to a future invoice automatically is not yet built* — see the
deferred list below.

---

## 3. The guardrails (why the system says "no" sometimes)

- **You can't over-allocate.** No invoice can be paid more than it owes, and one receipt can't
  allocate more than its own amount — the form stops you, and a database-level lock catches the rare
  case where two people record payments at the same instant.
- **You can't pay another property's / another tenant's invoice.** The invoice list is scoped to the
  tenant you picked and the property you're working in.
- **You can't back-date a receipt into a closed month.** If your accountant has closed June, you
  can't record a receipt dated in June — the ledger has sealed that month, so the cash entry could
  never post, and it would quietly make the invoice look paid while the books disagree. The system
  refuses it and tells you the period is closed. (Record it in the open month, or reopen June first.)
- **A recorded receipt's money is frozen.** Once a payment is on the books, its amount, date, method,
  and payer can't be edited — a mistake is corrected by voiding it and recording a new one. You can
  still re-allocate it across invoices (that's a legitimate correction).

---

## 4. Reversing a receipt

Use the **Void / refund** action on the payment. You type a reason; the system sets the status to
refunded, **re-opens** every invoice the receipt had covered (their balances go back up), and
**reverses the ledger entry** (the cash comes back off the books). The reason is stored in the audit
trail, and the allocation history is kept so you can see what the reversed receipt had covered.

---

## 5. What's deferred (and what triggers building it)

- **Printable receipt voucher (سند قبض).** A per-receipt PDF the office can hand a cash-paying
  tenant. Today the tenant gets an email + portal + app record of the receipt, but there's no printed
  voucher. *Trigger: cash tenants need a stamped receipt at the counter — build it mirroring the
  invoice PDF.*
- **Tenant credit balance / auto-apply advances.** Surfacing a tenant's on-account balance and
  automatically applying it to the next invoice. *Trigger: prepayments/advances become a real
  workflow.* (Until then, a receipt must be allocated to an invoice.)
- **Autopay / recurring / stored card**, and **batch (lockbox) receipt entry** for a day's cash
  intake. *Triggers: online-collection volume, or a daily cash-batch process, makes them worth it.*

See [docs/gap-analysis/PROPERTY-FACILITY-CLOSURE.md](../gap-analysis/PROPERTY-FACILITY-CLOSURE.md)
for the full deferred list with triggers.
