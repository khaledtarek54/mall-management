# The money cycle, step by step

<p class="eyebrow">Start here</p>

One ordered pass from an empty unit to balanced books. **Every step tells you the figure to
expect** — because a tester who cannot check a number by hand can only report crashes, and crashes
are what the automated suite already catches.

Do it as `manager@mall.test`, **in Val Plaza** — switch to it with the property picker at the top
of the panel before you start. Val Plaza is nearly empty, so every figure you see below is one you
caused. (Nile Gate is the mall with history; go there when you want to see what a thing looks like
once it has months behind it — **but read only, it is the soak**.)

A mismatch is a bug: **write it down and keep going**, unless you are blocked. **Do not reset the
box.**

<div class="plain">If a step's <i>business</i> reasoning is unfamiliar, the linked handbook page
explains it properly. Read it — judging whether a number is right needs knowing what it is for.</div>

## 0 · Give Val Plaza a bank account

<p class="sub">Val Plaza has no bank account, so the bank picker on every money form is empty and a
payment on a rail that requires one cannot be completed. Do this once, first.</p>

**Do:** **Bank Accounts → New.** Name it (*CIB — operating*), property Val Plaza, purpose
**operating**, mark it **default**. On the ledger-account picker use the option that **creates a
chart account** for it.

**Expect:** it saves with a chart account **of its own**. Then try pointing a *second* bank account
at the same chart account.

> *Expect:* **refused**. Two banks sharing one account means reconciling one offers the other's
> transactions — a wrong match that still balances.

## 1 · Let a unit

<p class="sub">The business: nothing in Atriom produces money until a tenant signs. A lease fixes
the rent, the service charge and the term, and everything downstream derives from it.
<a href="/leasing/">From empty unit to rent →</a></p>

**Do:**
1. **Tenants → New.** Create *Test Retailer One*, with an Arabic name as well.
2. **Leases → New.** Tenant *Test Retailer One*, unit **A-12** (200 m², vacant), starting the
   **1st of this month**, 12-month term. Add two charges: **base rent 12,000/month**, **service
   charge 3,000/month**.
3. Open **Units** and find A-12.

**Expect:**
- The tenant gets a code like `TN-00000xx` **automatically**. You cannot type one — codes are
  allocated by the system so two people creating tenants at once cannot collide.
- The lease saves with a reference beginning **`LSE-VP-`** — Val Plaza's own initials. **If it says
  `LSE-AW-`, report it**: that was a real bug, and new leases must not repeat it.
- **A-12 is now `occupied`**, not vacant. Nobody typed that: the unit's status is derived from the
  leases holding it.

**Now try to break it.** Create a *second* lease on **A-12** for the same dates.

> *Expect:* **refused**, with a message saying the unit is already let. Not a crash, and definitely
> not a success — double-letting a shop is the single worst thing a leasing system can do.

## 2 · Bill it

<p class="sub">The business: once a month the lease's charges become a bill. VAT is added where it
applies — and in Egypt <b>base rent is exempt while the service charge is not</b>.
<a href="/money/invoice-lifecycle">Life of an invoice →</a></p>

**Do:** open the lease → **Generate invoice** for this month.

**Expect exactly this:**

| Line | Net | VAT | Total |
|---|---|---|---|
| Base rent | 12,000.00 | **0.00** | 12,000.00 |
| Service charge | 3,000.00 | **420.00** | 3,420.00 |
| **Invoice total** | 15,000.00 | 420.00 | **15,420.00** |

<div class="rule"><span class="lbl">The rule behind those numbers</span>
<b>Base rent carries no VAT. Service charge carries 14%.</b> That is not a rounding quirk — which
supplies are taxable is a decision recorded against each charge code. If <b>base rent shows VAT</b>,
or the service charge shows none, that is a serious bug: it is money the operator either wrongly
collected from a retailer or wrongly failed to.</div>

**Also expect:**
- The invoice is **draft** until you issue it. A draft is not a document — it is a working note.
- Its number looks like `INV-VP-…` — the mall's own code — and you cannot edit it.

**A draft must be invisible to the tenant.** Raise a **draft** invoice for **Cilantro** (which has a
portal login), then sign into `/portal` as `cilantro@plaza.test` in a private window.

*Expect:* the draft is **not there** — not in the list, not by URL, not as a PDF. A draft is a
working note, not a bill anybody has decided to send; if a tenant can see one, that is serious.
Then issue it and confirm it appears.

## 3 · Issue it — a deliberate act

<p class="sub">The business: entering a charge and posting it to the books are two different
decisions, made by different people in most property companies. Atriom splits them.</p>

**Do:** on the invoice, press **Issue**.

**Expect:**
- The invoice becomes `issued`, is in the ledger, and is visible to the tenant.
- **The form locks.** Status stops being a dropdown you can pick from; the service period stops
  being typeable.
- **The due date stays editable** — extending it as a one-off concession is ordinary credit control
  while the money is still owed. That difference is deliberate.

**Now check the other side of it**, with the two narrow logins:

- **`viewer@mall.test`** may *read* invoices and nothing else. *Expect:* the **Invoices list opens**,
  no row lets you open a record, and typing an invoice's `/edit` URL is **refused**.
- **`leasing@mall.test`** holds no invoice rights at all. *Expect:* **Invoices is not in the sidebar**.
  A money screen visible to a role holding nothing on it would be the finding here.

<div class="plain"><b>A gap worth knowing.</b> No seeded login on this box can <i>edit</i> an invoice
without also being able to <i>issue</i> it — <code>manager</code> and <code>accounting</code> hold
both, <code>viewer</code> holds neither, <code>leasing</code> holds nothing. So the sharpest version
of this test — <i>someone who may edit but is not offered Issue</i> — needs a role created for it.
Ask for one rather than concluding the split does not work.</div>

## 4 · Collect

<p class="sub">The business: money arrives and has to be matched to bills. One payment can settle
several invoices, and one invoice can be settled by several payments — which is why a balance is
always <b>recomputed</b>, never typed. <a href="/money/">The money spine →</a></p>

**Do:** **Payments → New**. Pay **10,000** against it. Then a second payment of **5,420.00**.

**Expect:**

| After | Paid | Balance | Status |
|---|---|---|---|
| First payment | 10,000.00 | **5,420.00** | partially paid |
| Second payment | 15,420.00 | **0.00** | **paid** |

The status flips to *paid* **on its own** when the balance reaches zero. Nobody sets it.

**Also expect:** the form asks **which bank account** the money moved through, prefilled with the
default you made in step 0. A **cash** receipt should not require one.

**Now try to break it.** Record a *third* payment against the same invoice.

> *Expect:* refused. An invoice cannot be over-paid — the excess would bury itself as a negative
> receivable that nobody would find.

**Then void one of the payments.** *Expect:* the balance goes back up, the voided payment stops
counting as paid, and **you are asked for a reason**. A void, a refund and a bounced cheque are
**three different events** and must not all be recorded as the same thing.

## 5 · Credit it

<p class="sub">The business: a credit note runs <b>backwards</b> along the money spine. It reduces
what a tenant owes — a refund, a goodwill adjustment, a billing error — and settles a bill just
like a payment does. <a href="/money/credit-note-lifecycle">Life of a credit note →</a></p>

**Do:** raise a second month's invoice, then **Credit notes → New** for **1,000** against that
tenant, issue it and apply it.

**Expect:** the invoice balance drops by **exactly 1,000.00**, and the credit note shows as applied.
Then **un-apply** it and confirm the balance goes back.

> A credit note is a **document**, not an edit. The original invoice is never quietly changed —
> the correction has its own paper trail. If issuing a credit silently rewrote the invoice, that
> would be a serious bug.

## 6 · Check the books

<p class="sub">The business: every step above posted to the accounts automatically. Nobody keys
journal entries. <a href="/accounting/the-ledger">The ledger & the rules →</a></p>

**Do:** open **Accounting → Trial balance**, then **General ledger**, then **Reports → AR Aging**.

**Expect:**
- **Total debits equal total credits, exactly.** Any difference at all is the most serious class of
  bug in this system.
- Every document you created appears in the ledger, and each entry **names its source** — you can
  get from a number back to the invoice that caused it.
- The AR Aging outstanding figure **matches the invoice list**. Two screens disagreeing about money
  is serious even when both look plausible.

**Then open the income statement.** It should stop at **Net Operating Income** before interest and
depreciation, and offer the **month beside the year to date** and a **twelve-month spread**. Check a
figure in one of those columns against the single-period statement.

## 7 · The documents

<p class="sub">The business: an invoice PDF is what a retailer files with their own accountant. It
has to be right and it has to be readable in their language.</p>

**Do:** download the invoice PDF. Then download it again choosing **Arabic**.

**Expect:** it opens; shows the mall's identity, the correct figures, a document reference and page
numbers; and the Arabic version is genuinely Arabic and right-to-left with **the numbers
unchanged**. A **void** or **cancelled** document must be **watermarked** — a printed void invoice
and a payable one must not differ only by a small pill in the corner.

<div class="rule"><span class="lbl">Why the title matters</span>
A document calling itself a <b>Tax Invoice</b> must carry a tax registration number, or the retailer
cannot use it to reclaim the VAT they were charged — and an invoice that claims to be one without a
number is not merely incomplete, it is <b>confidently wrong</b> on the page every tenant files with
their own accountant.<br><br>
This box has a <b>placeholder registration — <code>000-000-000</code></b> — set so you can test that
path. <i>Expect</i> the document to title itself <b>Tax Invoice</b> and print that number.<br><br>
<b>The number is deliberately fake</b>: nine zeros cannot collide with a real Egyptian registration.
Nothing produced on this box is a real tax document. The operator's true registration is set before
production, and is a separate decision from anything you are testing.</div>

## 8 · Part months — the fiddly one

<p class="sub">The business: leases rarely start on the 1st. A tenant opening mid-month owes part of
that month, and the clause in their contract decides how the part is worked out.</p>

**Do:** create a second lease on **B-01** starting **on the 16th of this month**, same two charges
(12,000 + 3,000), then generate its first invoice.

**Expect** a **part month**, not a full one. This box's rule is `actual`:

```
monthly amount × days remaining ÷ days in that month
```

**In a 30-day month** the 16th onward is 15 days — exactly half. (In a 31-day month it is 16 days,
so recompute: 12,000 × 16 ÷ 31 = 6,193.55.) For a 30-day month:

| Line | Net | VAT | Total |
|---|---|---|---|
| Base rent | 6,000.00 | 0.00 | 6,000.00 |
| Service charge | 1,500.00 | **210.00** | 1,710.00 |
| **Total** | 7,500.00 | 210.00 | **7,710.00** |

Check that **VAT is 14% of the prorated service charge (210.00)**, not of the full one (420.00).
Then check the same rule is used in reverse: end that lease mid-month and confirm the credit for
the unused days uses the **same** method. A bill and its credit computed by different rules is a
real defect and it is easy to miss.

## 9 · Check the wall between the malls

<p class="sub">The business: an operator working one mall must never see another's data. Malls have
different owners, and a leak is a confidentiality breach, not a display bug. <b>This is the most
serious bug class in the system.</b></p>

**Do:** switch to **Nile Gate** and confirm none of what you just built appears — not the tenant,
not the lease, not the invoices. Then switch back and confirm none of Nile Gate's 66 invoices appear
in Val Plaza. Then open a record in one mall, copy the URL, switch property, and paste it. Then type
a Nile Gate tenant's name into the **search bar** while in Val Plaza. Then open a form's dropdowns —
unit, tenant, lease, vendor — and check they offer **only** the current mall.

**Expect:** each mall shows only its own rows, everywhere — lists, reports, dropdowns, search. The
pasted URL from the other mall must **not** open.

> Check this as you go, in every area, not once here. A dropdown that offers a record from the
> other mall is as much a leak as a list that shows one.

## 10 · Where to go next

You now have a working lease, invoices, payments, a credit note and a set of books to compare
against. **[Every part of the system →](/testing/coverage)** takes you through the remaining
modules — deposits, recoveries, facilities, procurement, payroll, owner statements — using this
cycle as the foundation.

Before you move on, repeat what you just did **in Arabic**, **as `viewer@`**, **on a phone**, and
**breaking things deliberately**. The second pass over work you understand finds different defects
from the first.
