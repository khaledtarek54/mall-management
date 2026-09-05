# How Atriom works

Read this once before you test anything. You cannot judge whether a screen is *right* until you
know what job it is doing — and most of the defects worth finding in this system are "the number
is wrong", not "the page crashed".

---

## 1 · Three parties, and the money between them

| | Who | What they want |
|---|---|---|
| **Operator** | **Eltizam** | Runs the mall day to day. Bills tenants, collects the money, maintains the building, pays suppliers and staff. **Almost every login you test is theirs.** |
| **Owner** | **Jawad** | Owns the property. Wants their money, and a statement explaining it. |
| **Tenants** | The retailers | Rent shops. Pay rent, a service charge, a share of shared costs, sometimes a slice of their sales. |

Atriom is the operator's system. The owner gets a statement and a narrow login; the tenant gets a
portal and a phone app. Everything else is the operator's.

---

## 2 · The money spine — the one loop everything hangs off

```
   UNIT           a shop. It exists whether or not anyone rents it.
     |
   LEASE          the contract. Fixes rent, service charge, term, deposit, escalation.
     |
   CHARGES        the recurring lines the lease produces: base rent 12,000/mo,
     |            service charge 3,000/mo, marketing levy, parking...
     |
   INVOICE        once a month those charges become a bill, with VAT applied where it applies.
     |
   PAYMENT        money arrives and is matched against the bill.
     |
   LEDGER         every step above posted itself to double-entry accounts. Nobody typed a journal.
```

**Everything else in the system either feeds that invoice or spends money**, and it all lands in
the books. When you are lost on a screen, ask "which end of this loop am I on?".

### The rules that make it a spine and not a pile

- **A lease is the source of truth for money.** Change the rent and everything downstream follows.
- **An invoice's balance is recomputed, never typed.** Four different things can settle an invoice
  (see §4). The balance is always `total − everything that settled it`.
- **A unit's status is derived from the leases holding it.** Nobody sets "occupied".
- **Nothing that moved money can be deleted** — not even by the super admin. You correct it with
  another document (void, credit note, reversal) so an auditor can follow what happened.

---

## 3 · Draft versus issued — the distinction that trips everyone

An invoice starts as a **draft**. A draft is a *working note*, not a bill:

- The **tenant cannot see it** — not in the portal, not in the app, not in a PDF, not by URL.
- It is **not in the books** — nothing posted.
- It can be freely edited, re-pointed at a different lease, deleted.

**Issuing it is a deliberate act**, not a save. Since September 2026 it is a button (*Issue*) that
needs its own permission (`invoices.issue`), mirroring the split every serious property system
makes between *entering* a charge and *posting* it. Once issued:

- The tenant sees it. It has a number. It is in the ledger.
- **The form locks.** Status stops being a dropdown; the service period stops being typeable.
- The only ways back are **void**, **credit note** or **write-off** — each its own document.

> If you find a way to make a draft become issued *without pressing Issue* — for example by saving
> a line onto it — that is a serious bug. It has happened before.

---

## 4 · The four ways an invoice gets settled

This is the single most important rule in the system. An invoice's `paid` figure is the sum of:

1. **Payments** — actual money received (cash, transfer, card, cheque).
2. **Credit notes applied** — a document that reduces what is owed.
3. **Tenant credit applied** — money the tenant already has on account.
4. **Security deposit netted off** — the held deposit used to settle a bill.

`balance = total − (1 + 2 + 3 + 4)`

Any screen that answers "how much of this is settled?" must count **all four**. If you find a
screen that counts only payments, that is a real defect.

**A write-off is deliberately NOT one of these.** Writing off a debt does not reduce the balance —
it records that the operator has stopped chasing it. So:

- *Balance* says what was **owed**.
- *Collectable balance* says what is still **being chased** (balance less write-offs).

A chase letter, a late fee, or an ageing report quoting the raw balance would be asking a tenant
for money the operator already forgave. That is a finding.

---

## 5 · VAT — the rule behind almost every "is this number right?"

Egypt, and this catalogue as shipped:

| Charge | VAT |
|---|---|
| **Base rent** | **0% — exempt** |
| **Service charge** | **14%** |
| Marketing levy | 14% |
| Utilities rebilled | 14% |
| Percentage rent | follows its charge code |
| A fine / violation | exempt |

**Base rent carrying VAT, or a service charge carrying none, is a serious bug** — it is money the
operator either wrongly collected from a retailer or wrongly failed to.

Three things to know:

- **Which supplies are taxable is a row, not code.** It is set per charge code on the Charge Codes
  screen. So it is configuration, and it can legitimately differ between installs.
- **The rate is dated.** A rise entered in advance applies from its date; a back-dated invoice
  keeps the rate that was in force. Issued invoices are never re-rated.
- **A document records which tax it carried.** Changing the catalogue later never rewrites history.

---

## 6 · Part months (proration)

Leases rarely start on the 1st. A tenant opening on the 16th owes part of that month.

This box's rule is **`actual`**:

```
monthly amount  ×  days remaining  ÷  days in that month
```

Three other rules exist (30-day month, 365-day year, whole month) and are chosen per lease, because
different contracts say different things.

**The rule that matters for testing:** the **same method must be used in reverse**. When a lease
ends mid-month and the unused days are credited back, the credit must be computed the same way the
bill was. A bill and its credit on different rules is a real defect and easy to miss.

**A full month is always exactly one month** under every method — the divisor only prices the stub.

Some charges deliberately **do not prorate at all**: a flat signage licence or a fixed parking fee
is payable in full for any month the lease runs into. That is a per-charge setting, not a bug.

---

## 7 · Recovering shared costs

The mall spends money on things every tenant benefits from — cleaning, security, common-area power,
lifts. Two mechanisms recover it:

**Service charge** — a monthly estimate billed with the rent.

**CAM reconciliation (the true-up)** — once a year the operator adds up what those shared costs
*actually* were, works out each tenant's share (usually by floor area), compares it with what they
were billed monthly, and settles the difference:

- Under-billed → a **recovery invoice**.
- Over-billed → a **credit note**, applied automatically.

**What must always hold:** every tenant's share adds back to the pool. A pool recovering **more
than it cost** is serious — that is over-charging retailers.

Refinements you may meet: an **anchor tenant** may be carved out of the denominator (they negotiate
a cap), and a lease may carve **named cost accounts** out of its own share. Both are deliberate.

Unit **owners** (people who bought a shop rather than renting it) pay a monthly service assessment
— *صيانة, "siyana"* — instead of rent, and they participate in the same pools.

---

## 8 · Percentage rent (turnover rent)

Many retail leases take **a share of the shop's turnover above a threshold**, on top of base rent.

1. The tenant declares monthly sales.
2. The operator reviews and **locks** the declaration.
3. Anything above the threshold is billed at the agreed percentage.

A locked declaration must not be editable. A zero declaration ("we sold nothing") is different from
no declaration at all — the system distinguishes them, and so should you.

---

## 9 · The books

Every document above posts itself to double-entry accounts. **Nobody types journal entries.**

- **Debits must equal credits, exactly, always.** Any difference at all is the most serious class of
  bug in this system.
- Every ledger entry **names its source** — you can get from a number in the ledger back to the
  invoice, payment or bill that caused it.
- **Correcting a posted document does not erase its entry.** The system voids the old entry and
  posts a fresh one, so the history stands.
- **A closed accounting period is closed.** Once the month is closed, a document dated inside it
  cannot have its money changed. Trying should be refused with a readable message.

The financial statements are: trial balance, income statement (P&L), balance sheet, cash flow,
general ledger. The income statement stops at **Net Operating Income** — the figure a mall is valued
on — before interest, depreciation and one-off items.

---

## 10 · Property isolation — the most serious bug class

An operator working one mall **must never see another mall's data**. Malls have different owners; a
leak is a confidentiality breach, not a display bug.

This applies to **everything**: lists, reports, dropdowns, the search bar, the PDF, the URL. A
dropdown that offers a record from the other mall is as much a leak as a list that shows one.

**Test it everywhere you go, not once.** If you are ever unsure, report it.

---

## 11 · Who can do what

Fourteen roles. Each should see a **different, smaller** panel. Two rules:

- A role that cannot do a thing should **not be offered** it. A button you are allowed to press and
  are then told you may not use is a bug, even though nothing broke.
- Typing the URL of a screen you should not have must not get you in.

---

## 12 · Both languages, always

The panel, the portal and every PDF work in **English and Arabic**. Arabic is right-to-left.

- **Anything in English on an Arabic screen is a bug** — except a person's name, a company's name,
  or a code.
- A **document goes to its reader in the reader's language**, not in the language of whoever pressed
  the button. An invoice emailed to a tenant follows *that tenant's* language setting; the download
  button lets you choose.
- Numbers stay as numbers in both.

---

## 13 · Work that happens without anybody

About forty scheduled jobs run nightly: billing on the 1st, late fees, overdue scans, dunning
reminders, lease expiry, preventive maintenance generation, vendor document expiry, cheque
maturity, CAM reconciliation, depreciation, ledger sync, backups.

This matters for testing in two ways:

- Some things you are looking for **only happen on a date**. Back-dating a record is often how you
  make one visible.
- A defect can be **the absence of something** — a sweep that never fired, a tenant who was never
  billed. Nothing errors; a number is just missing. Those are the hardest and most valuable finds.

---

## 14 · Things that are deliberately true and look like bugs

Check this list before reporting. Each of these has surprised somebody already.

| What you see | Why it is correct |
|---|---|
| Base rent shows **no VAT** | Base rent is exempt in the shipped catalogue. |
| A **paid** invoice's status field is greyed out | Committed money documents lock. Correct via void/credit note. |
| The **Delete** button is missing on an invoice, payment, journal entry | Money records are never deletable, by anyone. |
| A **draft** invoice is invisible in the portal | A draft is not a document. |
| A written-off invoice still shows a **balance** | Balance says what was owed; the write-off is recorded separately. |
| A **credit note** did not change the original invoice's figures | Correct — it settles it as a separate document. |
| A job finished at **16:00 on its due date** counts as on time | Compliance is measured in whole days. |
| A **terminated** lease still appears in lists | It is history, not a mistake. |
| Two malls show **different** everything | That is property isolation working. |
| The **tenant code** was allocated for you and cannot be typed | Codes are allocated centrally so two people cannot collide. |
| An **expired** lease's shop shows as vacant the next morning | A nightly sweep frees it. |
