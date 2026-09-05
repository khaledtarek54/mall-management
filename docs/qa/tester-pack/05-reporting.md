# Reporting what you find

A report that cannot be reproduced cannot be fixed.

---

## The five lines every report needs

```
WHAT I DID            Screen, then steps. "Leases -> New -> saved with unit A-12."
WHAT I EXPECTED       "An invoice of 15,420.00"
WHAT HAPPENED         "An invoice of 15,000.00 - no VAT on the service charge"
LOGGED IN AS          manager@mall.test
PROPERTY + LANGUAGE   Val Plaza - Arabic
```

> **The last line matters more than it looks.** A large share of the defects in this system are
> either **property-scoping** or **language** bugs. A report missing those two fields usually cannot
> be reproduced by anyone.

Add a **screenshot** for anything visual, and **exact figures** for anything about money. "The total
was wrong" cannot be investigated; "expected 15,420.00, got 15,000.00" can.

---

## Before you report — three questions

**1. Is it in the "deliberately true" list?**
See [01-the-system.md §14](01-the-system.md). A locked field, absent VAT on rent, a missing Delete
button, a balance that survives a write-off — all correct.

**2. Is it already known and fixed?**
Two things on this box are already fixed upstream. Do not report them:
- *Record violation* gives a 404.
- Opening a form through a prefill link wipes the form's own defaults.

**3. Is it the demo residue in Val Plaza?**
The three pre-existing VP leases have `LSE-AW-` references and no dates. That was a real bug, since
fixed. **Report it only if a lease *you* create still comes out with `AW`.**

If you are unsure, **ask your assistant with this pack loaded** before writing it up. It can usually
tell you whether the rule you are looking at is deliberate.

---

## How bad is it?

| | | Examples |
|---|---|---|
| 🔴 **Serious** | Money is wrong · the books do not balance · someone sees another property's or another tenant's data · a screen crashes | Base rent charged VAT · debits ≠ credits · a Val Plaza list showing a Nile Gate row · a draft invoice visible to a tenant · a move-out refunding money that never arrived |
| 🟠 **Normal** | A workflow does not work, or works and produces the wrong result | A credit note that will not apply · a second billing run double-billing · a bay that stays assigned after its lease ends |
| 🟡 **Minor** | Wording, layout, confusing but correct | An English label on an Arabic screen · a column clipped on a phone · a number that is right but unexplained |

**Anything that sends money outward is serious**, even if it looks small — a refund, a credit note,
an owner disbursement, a payout. Money leaving wrongly has no recovery path.

---

## What is worth your time — and what is not

About **3,700 automated tests** run on every change, plus a browser sweep over every admin screen
for every role. Repeating that work is the fastest way to spend a week and find nothing.

**Do not systematically re-verify:**

- That totals and balances add up — the arithmetic is checked continuously.
- That every screen loads for every role — swept automatically.
- That a permission blocks someone — the whole 14-role matrix is tested.

**What only a person catches:**

- Wording that is **correct but misleading**.
- A workflow that technically works and **makes no sense in that order**.
- A screen that is right but you **cannot tell what to do on it**.
- Something that **looks** broken though the data is fine.
- Anything that only appears **in Arabic** or **on a phone**.
- **A number that is right, with nothing on the page explaining where it came from.**
- **Something that should have happened and did not** — a tenant never billed, a sweep that never
  fired, an alert that never arrived. Nothing errors; a figure is just missing. These are the
  hardest and the most valuable.

---

## Two things you must not do

1. **Never reset or reseed the box.** A month-long unattended test is running on it. If the data
   gets into a state you cannot work with, say so and ask.
2. **Never create, edit or delete anything in Nile Gate (NG).** It is the test. Read it freely.
