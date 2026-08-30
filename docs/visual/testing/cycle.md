# The money cycle, step by step

<p class="eyebrow">Start here</p>

One ordered pass from an empty unit to balanced books. **Every step tells you the figure to
expect** — because a tester who cannot check a number by hand can only report crashes, and crashes
are what the automated suite already catches.

Do it as `manager@mall.test`, **in Plaza Annex** — switch to it with the property picker at the top
of the panel before you start. Plaza Annex is empty, so every figure you see below is one you
caused. (Atriom Walk is the mall with history; go there when you want to see what a thing looks
like once it has months behind it.)

A mismatch is a bug: **write it down and keep going**, unless you are blocked.

<div class="plain">If a step's <i>business</i> reasoning is unfamiliar, the linked handbook page
explains it properly. Read it — judging whether a number is right needs knowing what it is for.</div>

## 1 · Let a unit

<p class="sub">The business: nothing in Atriom produces money until a tenant signs. A lease fixes
the rent, the service charge and the term, and everything downstream derives from it.
<a href="/leasing/">From empty unit to rent →</a></p>

**Do:**
1. **Tenants → New.** Create *Test Retailer One*.
2. **Leases → New.** Tenant *Test Retailer One*, unit **PA-01**, starting the **1st of this month**,
   12-month term. Add two charges: **base rent 12,000/month**, **service charge 3,000/month**.
3. Open **Units** and find PA-01.

**Expect:**
- The tenant gets a code like `TN-0000004` **automatically**. You cannot type one — codes are
  allocated by the system so two people creating tenants at once cannot collide.
- The lease saves and gets a reference.
- **PA-01 is now `occupied`**, not vacant. Nobody typed that: the unit's status is derived from the
  leases holding it.

**Now try to break it.** Create a *second* lease on **PA-01** for the same dates.

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
- Its number looks like `INV-PA-…` — the mall's own code — and you cannot edit it.

**A draft must be invisible to the tenant.** The tenant you just created has no portal login, so
test this against one that does: switch to **Atriom Walk**, raise a **draft** invoice for
*Cilantro*, then sign into `/portal` as `tenant1@atriomwalk.test` in a private window.

*Expect:* the draft is **not there**. A draft is a working note, not a bill anybody has decided to
send — if a tenant can see one, that is serious. Then issue it and confirm it appears.

## 3 · Collect

<p class="sub">The business: money arrives and has to be matched to bills. One payment can settle
several invoices, and one invoice can be settled by several payments — which is why a balance is
always <b>recomputed</b>, never typed. <a href="/money/">The money spine →</a></p>

**Do:** issue the invoice, then **Payments → New**. Pay **10,000** against it. Then a second
payment of **5,420.00**.

**Expect:**

| After | Paid | Balance | Status |
|---|---|---|---|
| First payment | 10,000.00 | **5,420.00** | partially paid |
| Second payment | 15,420.00 | **0.00** | **paid** |

The status flips to *paid* **on its own** when the balance reaches zero. Nobody sets it.

**Now try to break it.** Record a *third* payment against the same invoice.

> *Expect:* refused. An invoice cannot be over-paid — the excess would bury itself as a negative
> receivable that nobody would find.

## 4 · Credit it

<p class="sub">The business: a credit note runs <b>backwards</b> along the money spine. It reduces
what a tenant owes — a refund, a goodwill adjustment, a billing error — and settles a bill just
like a payment does. <a href="/money/credit-note-lifecycle">Life of a credit note →</a></p>

**Do:** raise a second month's invoice, then **Credit notes → New** for **1,000** against that
tenant, and apply it.

**Expect:** the invoice balance drops by **exactly 1,000.00**, and the credit note shows as applied.

> A credit note is a **document**, not an edit. The original invoice is never quietly changed —
> the correction has its own paper trail. If issuing a credit silently rewrote the invoice, that
> would be a serious bug.

## 5 · Check the books

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

## 6 · The documents

<p class="sub">The business: an invoice PDF is what a retailer files with their own accountant. It
has to be right and it has to be readable in their language.</p>

**Do:** download the invoice PDF. Then download it again choosing **Arabic**.

**Expect:** it opens; shows the mall's identity, the correct figures, a document reference and page
numbers; and the Arabic version is genuinely Arabic and right-to-left with **the numbers
unchanged**.

<div class="rule"><span class="lbl">Why the title matters</span>
A document calling itself a <b>Tax Invoice</b> must carry a tax registration number, or the retailer
cannot use it to reclaim the VAT they were charged — and an invoice that claims to be one without a
number is not merely incomplete, it is <b>confidently wrong</b> on the page every tenant files with
their own accountant.<br><br>
This box has a <b>placeholder registration — <code>000-000-000</code></b> — set so you can test that
path. <i>Expect</i> the document to title itself <b>Tax Invoice</b> and print that number.<br><br>
<b>The number is deliberately fake</b>: nine zeros cannot collide with a real Egyptian registration,
and the seller name says STAGING on its face. Nothing produced on this box is a real tax document.
The operator's true registration is set before production, and is a separate decision from anything
you are testing.</div>

## 7 · Part months — the fiddly one

<p class="sub">The business: leases rarely start on the 1st. A tenant opening mid-month owes part of
that month, and the clause in their contract decides how the part is worked out.</p>

**Do:** create a second lease on **PA-02** starting **mid-month** (say the 16th), then generate its
first invoice.

**Expect** a **part month**, not a full one. This box's rule is `actual`:

```
monthly amount × days remaining ÷ days in that month
```

Check both lines yourself, and check that **VAT is 14% of the prorated service charge**, not of the
full one. Then check the same rule is used in reverse: end that lease mid-month and confirm the
credit for the unused days uses the **same** method. A bill and its credit computed by different
rules is a real defect and it is easy to miss.

## 7b · Check the wall between the malls

<p class="sub">The business: an operator working one mall must never see another's data. Malls have
different owners, and a leak is a confidentiality breach, not a display bug. <b>This is the most
serious bug class in the system.</b></p>

**Do:** switch to **Atriom Walk** and confirm none of what you just built appears — not the tenant,
not the lease, not the invoices. Then switch back and confirm none of Atriom Walk's 303 invoices
appear in Plaza Annex. Then open a record in one mall, copy the URL, switch property, and paste it.

**Expect:** each mall shows only its own rows, everywhere — lists, reports, dropdowns, search. The
pasted URL from the other mall must **not** open.

> Check this as you go, in every area, not once here. A dropdown that offers a record from the
> other mall is as much a leak as a list that shows one.

## 8 · Where to go next

You now have a working lease, invoices, payments, a credit note and a set of books to compare
against. **[Every part of the system →](/testing/coverage)** takes you through the remaining 38
modules — recoveries, facilities, procurement, payroll, owner statements — using this cycle as the
foundation.
