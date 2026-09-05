# The money cycle — do this first

One ordered pass from an empty shop to balanced books. **Every step tells you the figure to
expect**, because a tester who cannot check a number by hand can only report crashes — and crashes
are what the automated suite already catches.

**Sign in as `manager@mall.test` and switch to Val Plaza (VP)** with the property picker at the top
before you start. Val Plaza is nearly empty, so every figure below is one *you* caused.

> A mismatch is a bug: **write it down and keep going**, unless you are actually blocked.
> Do not reset the box — see [02-the-box.md](02-the-box.md).

---

## 0 · Give Val Plaza a bank account

**Do this once, first.** VP has no bank account, so money-in forms have an empty bank picker.

1. **Bank Accounts → New.**
2. Name it (e.g. *CIB — operating*), pick Val Plaza, purpose **operating**, mark it **default**.
3. On the ledger-account picker, use the option that **creates a chart account** for it.

**Expect:** it saves, and it gets a chart account **of its own**. Try pointing a second bank account
at the *same* chart account — *expect* a refusal. Two banks sharing one account means reconciling
one offers the other's transactions.

---

## 1 · Let a unit

> **The business:** nothing in Atriom produces money until a tenant signs. The lease fixes the rent,
> the service charge and the term, and everything downstream derives from it.

**Do:**
1. **Tenants → New.** Create *Test Retailer One*. Give it an Arabic name too.
2. **Leases → New.** Tenant *Test Retailer One*, unit **A-12** (200 m², vacant), starting the
   **1st of this month**, 12-month term.
3. Add two charges: **base rent 12,000/month** and **service charge 3,000/month**.
4. Open **Units** and find A-12.

**Expect:**
- A tenant code like `TN-00000xx` is **allocated automatically**. You cannot type one.
- The lease saves with a reference beginning **`LSE-VP-`** — Val Plaza's own initials.
  **If it says `LSE-AW-`, report it** (that was a real bug; new leases must not repeat it).
- **A-12 is now `occupied`**, not vacant. Nobody typed that — a unit's status is derived from the
  leases holding it.

**Now try to break it.** Create a *second* lease on **A-12** for the same dates.

> **Expect: refused**, with a message saying the unit is already let. Not a crash, and definitely
> not a success. Double-letting a shop is the worst thing a leasing system can do.

---

## 2 · Bill it

> **The business:** once a month a lease's charges become a bill. VAT is added where it applies —
> and in Egypt **base rent is exempt while the service charge is not**.

**Do:** open the lease → **Generate invoice** for this month.

**Expect exactly this:**

| Line | Net | VAT | Total |
|---|---|---|---|
| Base rent | 12,000.00 | **0.00** | 12,000.00 |
| Service charge | 3,000.00 | **420.00** | 3,420.00 |
| **Invoice total** | 15,000.00 | 420.00 | **15,420.00** |

> **The rule behind those numbers.** Base rent carries no VAT; the service charge carries 14%. That
> is not a rounding quirk — which supplies are taxable is recorded against each charge code. **If
> base rent shows VAT, or the service charge shows none, that is serious**: it is money the operator
> either wrongly collected from a retailer or wrongly failed to.

**Also expect:**
- The invoice is **draft**. A draft is a working note, not a bill anybody decided to send.
- Its number looks like `INV-VP-…` — the mall's own code — and you cannot edit it.

**Check a draft is invisible to the tenant.** Raise a **draft** invoice for **Cilantro** (which has
a portal login), then sign into `/portal` as `cilantro@plaza.test` in a private window.

> **Expect:** the draft is **not there** — not in the list, not by URL, not in a PDF. Then issue it
> and confirm it appears.

---

## 3 · Issue it — a deliberate act

> **The business:** entering a charge and posting it to the books are two different decisions, made
> by different people in most property companies. Atriom splits them.

**Do:** on the invoice, press **Issue**.

**Expect:**
- The invoice becomes `issued`. It is now in the ledger and visible to the tenant.
- **The form locks.** Status stops being a dropdown you can pick from; the service period
  (period start / end) stops being typeable.
- **The due date stays editable** — extending it as a one-off concession is ordinary credit control
  while the money is still owed. That difference is deliberate.

**Now check the other side of it**, with the two narrow logins:

- **`viewer@mall.test`** may *read* invoices and nothing else. **Expect:** the **Invoices list
  opens**, no row lets you open a record, and typing an invoice's `/edit` URL is **refused**.
- **`leasing@mall.test`** holds no invoice rights at all. **Expect:** *Invoices* is **not in the
  sidebar**. A money screen visible to a role holding nothing on it would be the finding here.

> **A gap worth knowing.** No seeded login on this box can *edit* an invoice without also being able
> to *issue* it — `manager` and `accounting` hold both, `viewer` holds neither, `leasing` holds
> nothing. So the sharpest version of this test — *someone who may edit but is not offered Issue* —
> needs a role created for it. Ask for one rather than concluding the split does not work.

---

## 4 · Collect

> **The business:** money arrives and has to be matched to bills. One payment can settle several
> invoices and one invoice can be settled by several payments — which is why a balance is always
> **recomputed**, never typed.

**Do:** **Payments → New**. Pay **10,000** against the invoice. Then a second payment of
**5,420.00**.

**Expect:**

| After | Paid | Balance | Status |
|---|---|---|---|
| First payment | 10,000.00 | **5,420.00** | partially paid |
| Second payment | 15,420.00 | **0.00** | **paid** |

The status flips to *paid* **on its own** when the balance reaches zero. Nobody sets it.

**Also expect:** the payment form asks **which bank account** the money moved through, and fills in
the default you created in step 0. A cash receipt should not require one.

**Now try to break it.** Record a *third* payment against the same invoice.

> **Expect: refused.** An invoice cannot be over-paid — the excess would bury itself as a negative
> receivable nobody would find.

**Then void one of the payments.**

> **Expect:** the invoice balance goes back up, the voided payment stops counting as paid, and you
> are **asked for a reason**. A void, a refund and a bounced cheque are **three different events**
> and must not all be recorded as the same thing.

---

## 5 · Credit it

> **The business:** a credit note runs **backwards** along the money spine. It reduces what a tenant
> owes — a billing error, a goodwill adjustment — and settles a bill just as a payment does.

**Do:** generate a second month's invoice, then **Credit notes → New** for **1,000** against that
tenant, issue it and apply it.

**Expect:** the invoice balance drops by **exactly 1,000.00**, and the credit note shows as applied.

> A credit note is a **document**, not an edit. The original invoice's own figures are never quietly
> changed — the correction has its own paper trail. If issuing a credit rewrote the invoice, that
> would be serious.

**Then un-apply it** and confirm the balance goes back.

---

## 6 · Check the books

> **The business:** every step above posted to the accounts automatically. Nobody keys journal
> entries.

**Do:** open **Accounting → Trial balance**, then **General ledger**, then **Reports → AR Aging**.

**Expect:**
- **Total debits equal total credits, exactly.** Any difference at all is the most serious class of
  bug in this system.
- Every document you created appears in the ledger, and each entry **names its source** — you can
  get from a number back to the invoice that caused it.
- The **AR Aging** outstanding figure **matches the invoice list**. Two screens disagreeing about
  money is serious even when both look plausible.

**Then look at the income statement.** It should stop at **Net Operating Income** before interest
and depreciation — and it should offer you the month beside the year-to-date, and a twelve-month
spread. Check a figure in a column against the same figure on the single-period statement.

---

## 7 · The documents

> **The business:** an invoice PDF is what a retailer files with their own accountant. It has to be
> right and readable in *their* language.

**Do:** download the invoice PDF. Then download it again choosing **Arabic**.

**Expect:**
- It opens; shows the mall's identity, the correct figures, a document reference and page numbers.
- The Arabic version is genuinely Arabic and right-to-left, with **the numbers unchanged**.
- It titles itself **Tax Invoice** and prints the registration `000-000-000`.

> **Why the title matters.** A document calling itself a *Tax Invoice* must carry a tax registration
> number, or the retailer cannot reclaim the VAT they were charged. An invoice claiming to be one
> without a number is not merely incomplete — it is **confidently wrong** on the page every tenant
> files with their accountant.
>
> The number here is a **deliberate placeholder**. Nothing produced on this box is a real tax
> document.

**Also check:** a **void** or **cancelled** document is **watermarked** — a printed void invoice and
a payable one must not differ only by a small pill in the corner.

---

## 8 · Part months — the fiddly one

> **The business:** leases rarely start on the 1st. A tenant opening mid-month owes part of that
> month, and the clause in their contract decides how the part is worked out.

**Do:** create a second lease on **B-01** starting **on the 16th of this month**, same charges
(12,000 rent + 3,000 service charge), then generate its first invoice.

**Expect a part month.** This box's rule is `actual`:

```
monthly amount × days remaining ÷ days in that month
```

**In a 30-day month**, the 16th onward is 15 days — exactly half. (In a 31-day month it is 16 days,
so recompute: 12,000 × 16 ÷ 31 = 6,193.55.) For a 30-day month:

| Line | Net | VAT | Total |
|---|---|---|---|
| Base rent | 6,000.00 | 0.00 | 6,000.00 |
| Service charge | 1,500.00 | **210.00** | 1,710.00 |
| **Total** | 7,500.00 | 210.00 | **7,710.00** |

**Check the VAT is 14% of the *prorated* service charge (210.00), not of the full one (420.00).**

**Then check the same rule is used in reverse:** end that lease mid-month and confirm the credit for
the unused days uses the **same** method. A bill and its credit computed by different rules is a
real defect and it is easy to miss.

---

## 9 · The wall between the malls

> **The business:** an operator working one mall must never see another's data. Malls have different
> owners — a leak is a confidentiality breach, not a display bug. **This is the most serious bug
> class in the system.**

**Do:**
1. Switch to **Nile Gate** and confirm **none** of what you just built appears — not the tenant, not
   the lease, not the invoices.
2. Switch back to Val Plaza and confirm none of Nile Gate's 66 invoices appear.
3. Open a record in one mall, **copy the URL**, switch property, and paste it.
4. Type a Nile Gate tenant's name into the **search bar** while in Val Plaza.
5. Open any form with a dropdown — a lease's unit picker, an invoice's tenant picker — and check
   it offers **only** the current mall's records.

**Expect:** each mall shows only its own rows, **everywhere** — lists, reports, dropdowns, search,
PDFs. The pasted URL from the other mall must **not** open.

> Check this as you go, in every area, not once here.

---

## 10 · Where to go next

You now have a working lease, invoices, payments, a credit note and a set of books to compare
against. **[04-modules.md](04-modules.md)** takes you through the rest of the system — deposits,
recoveries, facilities, procurement, payroll, owner statements — using this cycle as the foundation.

Also worth doing before you move on, on the records you just made:

| Pass | What it finds |
|---|---|
| **Repeat in Arabic** | Untranslated headings, English on an Arabic screen, clipped text, wrong column order |
| **Repeat as `viewer@`** | Actions you are offered and then refused; screens you should not reach |
| **Repeat on a phone** | Tables and forms that are unusable, not merely ugly |
| **Break things deliberately** | Empty forms, letters in money fields, an end date before a start date, negative amounts, a 500-character name, Save clicked twice fast. *Expect* a clear message — **never** a white page, a raw error, or a silent "nothing happened". |
