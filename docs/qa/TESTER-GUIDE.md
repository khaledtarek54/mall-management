# Tester's guide — the admin dashboard

**Who this is for:** someone testing the Atriom admin panel by using it, who did not build it.

**What it is not:** [UAT-SCRIPTS.md](UAT-SCRIPTS.md) asks the business *"is this the workflow we
actually run?"* and is signed off by the business. This asks a different question — *"does it
work, and is it usable?"* — and is answered by finding defects.

> **Read [What NOT to test](#what-not-to-test) before you start.** About 3,700 automated tests
> already run on every change. Repeating what they cover is the fastest way to spend a week and
> find nothing.

---

## 1. Getting in

| | |
|---|---|
| Admin panel | **https://atriom.tri-tech.net/admin** |
| Tenant portal | https://atriom.tri-tech.net/portal |
| Password (every account below) | ask whoever set the box up — it is not written down here on purpose |

You will meet a Cloudflare Turnstile checkbox on the sign-in form. That is expected.

| Login | Role | What it is for |
|---|---|---|
| `admin@mall.test` | super_admin | Everything. Use it to set up, not to test with — it can do things nobody in real life can. |
| `manager@mall.test` | manager | **Your main account.** The day-to-day mall operator. |
| `leasing@mall.test` | leasing | Leases and tenants, no money screens |
| `accounting@mall.test` | accounting | Invoices, payments, the ledger |
| `viewer@mall.test` | viewer | Read-only |
| `owner@atriom.test` | owner | The landlord's view |
| `zara@atriomwalk.test` | *portal* | The retailer's own login |

---

## 2. What is on the box

**A deliberately empty mall.** One property (*Atriom Walk*), **12 vacant units**, **3 tenants who
lease nothing**, and no leases, invoices, payments or accounting entries at all.

That is on purpose. The alternative — a mall mid-life with 33 leases and 700 ledger entries — means
every number on every screen was put there by somebody else, and **you cannot tell what your own
action changed.** Here, every figure you see is one you caused.

Units you will use: `A-01` (60 m²), `A-02` (75 m²), `A-03` (90 m²), `A-04` (120 m²).

**Every screen explains itself.** There is a **guide button on each screen** — it tells you the
screen's *purpose*, *steps*, *rules*, and most usefully **what else moves when you touch this
one**. Read it before testing a screen you do not know. There is also an illustrated handbook at
**/admin/handbook**.

---

## 3. The main script — one full money cycle

Do these in order, as `manager@mall.test`. Each step says what to expect. **A mismatch is a bug —
write it down and keep going**, unless you are blocked.

### 3.1 Create a tenant and a lease

1. **Tenants → New.** Create *Test Retailer One*.
   *Expect:* it gets a code like `TN-0000004` automatically. You cannot type one.
2. **Leases → New.** Tenant *Test Retailer One*, unit **A-01**, start on the **1st of this month**,
   term 12 months.
   Add two charges: **base rent 12,000 / month** and **service charge 3,000 / month**.
   *Expect:* saves without complaint.
3. **Units.** Find A-01.
   *Expect:* it is now **occupied**, not vacant.

> Now try to break it: create a second lease on **A-01** for the same dates.
> *Expect:* **refused**, with a message saying the unit is already let — not a crash, not a success.

### 3.2 Bill it

4. Open the lease → **Generate invoice** for this month.
   *Expect* an invoice with exactly these lines:

   | Line | Net | VAT | Total |
   |---|---|---|---|
   | Base rent | 12,000.00 | **0.00** | 12,000.00 |
   | Service charge | 3,000.00 | **420.00** | 3,420.00 |
   | **Invoice total** | 15,000.00 | 420.00 | **15,420.00** |

   **Base rent carries no VAT and service charge carries 14%.** That is not a rounding quirk — it
   is the rule this system is built on. If base rent shows VAT, that is a serious bug.

5. *Expect* the invoice status is **draft** until you issue it, and its number looks like
   `INV-AW-…`.

> **Check the tenant cannot see a draft.** Sign into the portal as `zara@atriomwalk.test` in a
> private window. A draft invoice must be **invisible** there. (Zara is a different tenant, so you
> may need to raise a draft for Zara to test this properly.)

### 3.3 Take a payment

6. Issue the invoice, then **Payments → New**. Pay **10,000** against it.
   *Expect:* invoice paid 10,000.00, **balance 5,420.00**, status *partially paid*.
7. Record a second payment of **5,420.00**.
   *Expect:* balance **0.00**, status **paid**.

> Try to break it: record a **third** payment against the same invoice.
> *Expect:* refused. An invoice cannot be over-paid.

### 3.4 Credit it

8. **Credit notes → New** against that tenant for **1,000**, then apply it to an invoice with a
   balance (raise a second month's invoice first if you need one).
   *Expect:* the invoice balance drops by exactly 1,000.00 and the credit note shows as applied.

### 3.5 Check the books

9. **Accounting → Trial balance.**
   *Expect:* total debits **equal** total credits, exactly. Any difference at all is a serious bug.
10. **Accounting → General ledger.**
    *Expect:* every document you created above appears, and each entry names its source.
11. **Reports → AR Aging.**
    *Expect:* the outstanding figure matches what the invoice list shows. Two screens disagreeing
    about money is a serious bug.

### 3.6 Documents

12. Download the **invoice PDF**.
    *Expect:* it opens, shows the mall's name, the correct figures, and is not a broken layout.
13. Download it again choosing **Arabic**.
    *Expect:* an Arabic document, right-to-left, with the numbers unchanged.

### 3.7 Part months — the fiddly one

14. Create a second lease on **A-02** starting **mid-month** (say the 16th).
15. Generate its first invoice.
    *Expect:* a **part month**, not a full one. Under this box's rule (`actual`) it is
    `monthly amount × days remaining ÷ days in that month`. Check the arithmetic yourself for both
    lines, and that VAT is 14% of the **prorated** service charge, not the full one.

---

## 4. After the cycle — four more passes

**These find different bugs from the script above. Do not skip them.**

### 4.1 In Arabic
Switch the panel to Arabic and repeat a few screens. This system's history is full of bugs visible
*only* in Arabic: untranslated headings, English text on an otherwise Arabic screen, text clipped
by its own box, columns in the wrong order under right-to-left. **Anything in English on an Arabic
screen is a bug** — except a person's name, a company's name, or a code.

### 4.2 As another role
Sign in as `leasing@`, `accounting@` and `viewer@`. Each should see a **different, smaller** panel.

- *Expect:* `viewer@` can open things and **cannot** change them.
- *Expect:* no role sees a button that then refuses them. **A button you are allowed to press and
  then told you may not use is a bug**, even though nothing broke.

### 4.3 On a phone
Open the panel on a real phone. Tables, forms and the sidebar should be usable — not perfect, but
usable.

### 4.4 Try to break things
Deliberately: submit empty forms, type letters into money fields, enter an end date before a start
date, negative amounts, a very long name, click Save twice quickly.
*Expect:* a clear message saying what is wrong. **Never** a white page, a raw error, or a silent
"nothing happened".

---

## 5. How to report what you find

A bug report that cannot be reproduced cannot be fixed. **Always include these five:**

```
WHAT I DID      Screen, then the steps. "Leases → New → saved with unit A-01."
WHAT I EXPECTED "An invoice of 15,420.00"
WHAT HAPPENED   "An invoice of 15,000.00 — no VAT on the service charge"
LOGGED IN AS    manager@mall.test
PROPERTY + LANGUAGE   Atriom Walk · Arabic
```

**The last line matters more than it looks.** A large share of the defects in this system are
either property-scoping or language bugs, and a report missing those two fields usually cannot be
reproduced by anyone.

Add a **screenshot** for anything visual, and the **exact figures** for anything about money —
"the total was wrong" cannot be investigated; "expected 15,420.00, got 15,000.00" can.

### Say how bad it is

| | |
|---|---|
| **Serious** | Money is wrong · books do not balance · someone sees another property's or tenant's data · a screen crashes |
| **Normal** | A workflow does not work, or works but produces the wrong result |
| **Minor** | Wording, layout, something confusing but correct |

---

## 6. What NOT to test

Around **3,700 automated tests** run on every change, plus a browser suite over every admin screen.
Repeating them wastes your week. In particular, do not systematically re-verify:

- That arithmetic is internally consistent — totals, balances, ledger sums are checked continuously.
- That every screen loads for every role — swept automatically for all roles across all screens.
- That a permission refuses someone — the whole role matrix is tested.

**What you catch and they cannot:**

- Wording that is *correct but misleading*
- A workflow that is technically fine and **makes no sense to do in that order**
- A screen that is right but you cannot work out what to do on it
- Something that looks broken even though the data is fine
- Anything that only shows up in Arabic, or on a phone
- **A number that is right but nothing on the page explains where it came from**

---

## 7. Starting over

You will make a mess. That is the job, and the box exists to be messed up.

To wipe everything you created and get a clean empty mall back — about 30 seconds, no undo:

```bash
sudo atriom-qa-reset
```

It needs SSH access to the box, so ask whoever set it up if you do not have it. Reset whenever the
data stops making sense to you; there is nothing on this box worth keeping.

> **This box holds no real data.** Every tenant, lease and figure is invented. Nothing you do here
> reaches a real retailer, a real bank or the tax authority.
