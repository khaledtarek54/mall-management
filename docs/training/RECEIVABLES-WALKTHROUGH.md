# Receivables — a walkthrough for someone new

**Who this is for.** Someone who has to run the money-in side of a mall: raise the invoices, take
the cash, chase the arrears, and be able to explain every figure to a retailer's accountant. It
assumes **no** accounting background.

**Who this is NOT for.** It is not the developer's document — those are
[`modules/05`](../modules/05-billing-invoices.md), [`06`](../modules/06-payments.md),
[`07`](../modules/07-credit-notes.md), [`08`](../modules/08-cam.md) and
[`21`](../modules/21-general-ledger.md). This does not repeat them; where a rule needs proving it
links there.

**Read [SPACE-WALKTHROUGH.md](SPACE-WALKTHROUGH.md) and [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md)
first.** Everything here starts from a lease's charge schedule.

---

## Table of contents

| Part | What it answers |
|---|---|
| [0](#part-0--what-receivables-actually-is) | What "receivables" actually is |
| [1](#part-1--the-vocabulary) | The vocabulary — 22 words |
| [2](#part-2--the-charge-schedule-is-what-bills) | The charge schedule is what bills — not the lease |
| [3](#part-3--the-monthly-run) | The monthly run: preview → post |
| [4](#part-4--part-months--the-four-methods-and-the-flag) | Part months — four methods, and the flag |
| [5](#part-5--advance-vs-arrears) | Advance vs arrears |
| [6](#part-6--the-invoice-itself) | The invoice itself — statuses and what locks |
| [7](#part-7--tax-on-the-line) | Tax on the line |
| [8](#part-8--taking-money) | Taking money — payments and allocation |
| [9](#part-9--the-four-settlement-channels) | The four settlement channels — **the single most important rule** |
| [10](#part-10--credit-notes) | Credit notes — un-apply, never stack |
| [11](#part-11--credit-on-account) | Credit on account |
| [12](#part-12--security-deposits) | Security deposits |
| [13](#part-13--post-dated-cheques) | Post-dated cheques (الشيكات الآجلة) |
| [14](#part-14--the-recoveries--cam-utilities-turnover-rent) | The recoveries — CAM, utilities, turnover rent |
| [15](#part-15--chasing-the-money) | Chasing the money — overdue, dunning, late fees, dispute, write-off |
| [16](#part-16--what-each-act-does-to-the-books) | What each act does to the books |
| [17](#part-17--i-clicked-and-nothing-happened) | "I clicked and nothing happened" — refusals decoded |
| [18](#part-18--the-rhythm) | The rhythm — daily, monthly, annually |
| [19](#part-19--the-twelve-rules) | The twelve rules you must not break |

---

# Part 0 — What receivables actually is

**Receivables (AR, الذمم المدينة) = money the mall has *earned* and not yet *collected*.**

That gap is the whole subject. Two different questions live in it, and confusing them is the most
common mistake:

| Question | The answer lives in | Called |
|---|---|---|
| *"What does this tenant owe us?"* | The invoice | **Revenue / receivable** |
| *"What has this tenant paid us?"* | The payment | **Cash** |

An invoice is raised whether or not anyone pays. A payment exists whether or not it settles
anything. The system keeps them as **two documents** and derives the relationship between them —
it never stores "how much is left" as a fact somebody typed.

## 0.1 The one sentence to carry

> **The invoice is the receivable. `Invoice::recomputeTotals()` is the only thing that decides how
> much of it is settled, and it counts FOUR channels.**

Everything in Part 9 comes from that sentence. If you learn one thing here, learn that one.

## 0.2 The five money streams from one shop

| # | Stream | Charge type | Timing | VAT (shipped catalogue) |
|---|---|---|---|---|
| 1 | Base rent | `base_rent` | Monthly, usually in advance | **Exempt** — a row, not a rule (see 7.3) |
| 2 | Service charge | `service_charge` | Monthly | 14% |
| 3 | Marketing levy | `marketing`, 5% of base rent | Monthly, derived | Exempt |
| 4 | Percentage rent | after a sales declaration | Monthly or annual | Exempt |
| 5 | Recoveries & extras | CAM true-up, utilities, parking, signage, fines | Event-driven | Varies by charge code |

---

# Part 1 — The vocabulary

| Term | Arabizi | In the system | What it means |
|---|---|---|---|
| **Charge** | band fatoora | `Charge` | One dated row on a lease: *"base rent, 60,000/month, 1 Jan → 31 Dec"* |
| **Charge schedule** | gadwal el rosoom | `charges` for a lease | The whole ladder of those rows. **This is what bills** |
| **Charge code** | kod el rasm | `charge_codes` | The catalogue: what a charge is called, which GL account it hits, whether it is taxable |
| **Invoice** | fatoora | `Invoice`, `INV-AW-202608-0417` | The document that says *you owe us this* |
| **Invoice item** | satr el fatoora | `InvoiceItem` | One line. Carries its own amount, tax rate and tax code |
| **Subtotal / VAT / total** | — | derived from the lines | **Never typed.** The header follows the items |
| **paid_amount** | el madfoo3 | derived | How much of this invoice is settled, from four channels |
| **balance** | el motabaki | `total − paid_amount`, floored at 0 | What is still owed |
| **Payment** | daf3a / ta7seel | `Payment`, `RCT-…` | Money that arrived. Cash, transfer, cheque, card |
| **Allocation** | tawzee3 | `invoice_payment` pivot | Which invoices this payment settled, and by how much |
| **Captured** | — | `Payment::RECEIVED_STATUSES` | captured · reconciled · settled — **all three mean the money is on the books** |
| **Credit note** | esh3ar khasm | `CreditNote`, `CN-…` | A document reducing what a tenant owes |
| **Credit on account** | raseed lel mosta2ger | `TenantCreditApplication` | An overpayment sitting with us, spendable on a later invoice |
| **Security deposit** | ta2meen | `DepositTransaction` | Money held, not earned. A **liability** |
| **PDC** | sheek agel | `PostDatedCheque` | A forward-dated cheque, lodged now, cleared later |
| **CAM** | el syana / khadamat | `CamExpensePool` | Common Area Maintenance — the shared running cost, recovered from tenants |
| **True-up** | taswiya | `true_up_amount` | Actual cost minus what was estimated and collected |
| **Percentage rent** | eegar nesba | sales declarations | A share of the tenant's turnover above a breakpoint |
| **Late fee** | gharamet ta2kheer | `late_fee` invoice | A penalty on an overdue balance |
| **Dunning** | motab3et el tahseel | `dunning_level` | The ladder of chasing notices |
| **Write-off** | deyoun ma3dooma | `InvoiceWriteOff` | Accepting that a debt will not be paid |
| **Aging** | ta7leel a3mar el deyoun | `/admin/ar-aging` | Debt sliced by how old it is |
| **Draft** | musawwada | `status = 'draft'` | Not a document. **The tenant never sees one** |

---

# Part 2 — The charge schedule is what bills

> **A lease with no charge rows bills nothing, however neatly the rent field is filled in.**

The lease record holds the *deal*. The billing run does **not** read `leases.base_rent_monthly` — it
reads the dated **charge rows**. Two consequences you will meet:

1. **Rent is never overwritten.** A rent step closes the old row the day before and opens a new one.
   So *"what was the rent in March 2026?"* stays answerable for ever, and re-running March's billing
   produces March's number rather than today's.
2. **A broken schedule bills silently wrong.** Overlapping rows, gaps, or no start date each produce
   *nothing* rather than an error. `php artisan atriom:audit-charge-schedules` is the command that
   hunts for exactly that. **Run it before a deploy and after any import.**

## 2.1 What one charge row carries

| Field | Meaning |
|---|---|
| `type` | The charge code (`base_rent`, `service_charge`, …) — from the catalogue, not a fixed list |
| `amount` | Per period |
| `frequency` | monthly · quarterly · annually · one_time |
| `start_date` / `end_date` | The window this rung is in force |
| `is_active` | A stopped charge |
| `prorate` | **null/true = prorate; explicit false = bills whole months** (Part 4.3) |
| `billing_timing` | **null = advance; `arrears` = covers the previous cycle** (Part 5) |
| `vat_rate` | **null is normal** — an override, not the rate |
| `vat_applicable` | **null is normal** — an override, not the answer |

## 2.2 The two null-is-normal columns — why they matter

`vat_rate` and `vat_applicable` are **overrides**. Null means *"ask the catalogue at billing time"*.

This is not pedantry. When they were stamped with the catalogue's answer at creation time:

- a VAT rise entered in advance reached late fees, fines, meter recharges, CAM and percentage rent —
  **and never reached rent or service charge**, which is most of the money;
- and a `base_rent` row was born permanently exempt and could **never** become taxable again, because
  the flag was tested *before* the rate was read. Measured: charge code pointed at VAT 14%, resolver
  said 14.0, the charge said 0.0, and the run raised a rent line with **0.00 VAT where 14,000 was
  due** — and it discarded a rate the operator had deliberately typed.

Law 157/2025 pulled property rental into the tax net, so *"point base rent at VAT_14"* is exactly the
change this operator expects to make. **Leave both columns blank unless a specific deal fixed a
rate.** A row carrying a value is marked ⚠ on the schedule table for that reason.

---

# Part 3 — The monthly run

## 3.1 Preview, then post

`/admin/billing-run-preview` is a **dry run**: one row per eligible lease showing what it *would* be
billed, or the reason it would not be.

**It cannot lie**, and that is a design property rather than a promise: every row is produced by
`planInvoiceForLease()` — the *same* method the real run persists verbatim. It is the run, minus the
writes. A preview computed by a second implementation is a preview that can drift.

Then the run posts, portfolio-wide on a schedule or scoped to the mall you are in from the screen.

## 3.2 What makes a lease billable

All three, or nothing bills:

- status is `active`,
- `commencement_date` ≤ period end,
- `expiry_date` ≥ period start.

> The lease's own **Generate invoice** action used to apply **none** of this — it created a real GL-posting
> invoice for a *terminated* lease, a *draft* lease, and a lease past its *expiry*. Both paths now
> apply one definition.

## 3.3 The refusal codes, in plain words

Press **Bill this period** and you may get *"Nothing was billed"*. The reason is one of eight:

| Code | Plain words |
|---|---|
| `lease_not_billable` | Wrong status, not yet commenced, or the term has ended |
| `already_billed` | An invoice already covers that month for this lease |
| `run_in_progress` | The batch run holds the lock — wait for it |
| `fit_out` | The lease is inside its rent-free fit-out grace |
| `off_cycle` | A quarterly/annual lease; this is not a cycle-start month |
| `no_applicable_charges` | No charge row applies to this period |
| `lease_ended` | The period is past the lease's end |
| `exception` | Something failed — the message says what |

These are worded in one place now. Before that the lease action and the forecast tab translated the
same code **two different ways**, and only one was updated when the vocabulary grew — so an operator
billing an inactive lease read the literal string `admin.billing_preview.reason.lease_not_billable`.

## 3.4 How far ahead you may bill

Bounded by `BillingWindow` on **both** screens. It bounds the *operator*, not the engine — a
scheduled run, a CLI backfill or a deliberate `--period=` is not clicking a button. The line is
"typed into a form", which is where a mis-key becomes a receivable nobody meant to raise.

## 3.5 Double-billing is prevented three ways

1. A cache lock on the period — the manual action contends on the **same** lock as the batch,
2. an overlap probe (`alreadyBilledForMonth()`),
3. and a unique index on the invoice number underneath everything.

### The one class of bug to know about here

`alreadyBilledForMonth()` asks: does an invoice already **overlap** this month, and does it carry
none of the standalone one-off types? A one-off invoice dated into a billed month must not suppress
that month's rent.

That list has been short by one item **five** times — percentage rent, CAM recovery, utility
recharge, violation fine, NSF fee — and the **sixth cost more than a month**:
`security_deposit` was never added, and a deposit invoice takes the **lease's whole term** as its
period. Measured on a three-year lease: the deposit was billed and then **no rent invoice was raised
for any month of the term**, reported as an ordinary `skipped` and indistinguishable in the run
summary from a lease billed correctly. Eight invoices appeared the moment the type was registered.

**The shape to watch for: a standalone one-off invoice dated into a month the recurring run also
bills.** If you add one, it belongs on that list in the same change.

## 3.6 Adding a charge into a month already billed

The run refuses to bill a month twice — correctly. So a **back-dated** charge sits in the schedule
and no invoice ever raises it. Measured: a month billed at 44,000, a 14,000 service charge added into
it, run answers *skipped*, **14,000 lost**.

It is **not refused** — back-dating is legitimate and Yardi does not block it either — but you are
now told at the moment you do it, with the covering invoice **named**. Act on that toast: either
credit and re-issue, or raise the charge on its own document.

---

# Part 4 — Part months — the four methods, and the flag

## 4.1 Two different questions

1. **How** is a part month priced? → the **proration method** (per lease)
2. **Does this charge prorate at all?** → `charges.prorate` (per charge)

## 4.2 The four methods — 16 days of a 31-day August, rent 30,000

| Method | A partial month is | Bills |
|---|---|---|
| `actual` **(default)** | days ÷ that month's own length | **15,483.87** |
| `thirty_day` | days ÷ 30 — the "one thirtieth per day" clause | **16,000.00** |
| `year_365` | days × 12 ÷ 365 | **15,780.82** |
| `whole_month` | the whole month | **30,000.00** |

Resolved lease → property → portfolio. `actual` is the default at every tier, so nothing an existing
install bills moved when this shipped.

**A full month is exactly 1.0 under every method.** The divisor prices the *stub*. Without that rule,
30/360 would bill 31/30 of a month every August.

**One method per invoice.** The credit note raised on a termination reads the *agreement's* method
too — a credit computed on a different rule than the invoice it credits disagrees with it by days,
on exactly the mid-month move-out it exists for.

## 4.3 `charges.prorate` — the flag

Not every charge is time-priced. A flat signage licence, a fixed parking fee or a fixed management
fee is payable in full for **any** month the lease runs into. Measured on a 16 August commencement, a
5,000 parking fee billed **2,580.65** — and the termination credit then clawed back another 2,500 it
had actually earned.

- **Null is normal.** Null and true both mean *prorate by the lease's method*. Only an explicit
  false changes anything.
- On the **charge**, not the lease, because the case that matters is mixed: rent prorates and the
  licence beside it does not, on one lease and one invoice.
- A month the lease never reached still bills **nothing**. "Is a part month worth a whole month" and
  "did the lease run at all" are different questions.

Reachable from the charge-schedule tab (a *"Bills whole months"* toggle, offered only for a monthly
row), from the importer, and shown as a badge on the schedule table.

## 4.4 Both ends prorate — with one asymmetry

| Edge | Behaviour |
|---|---|
| **Move-in** (mid-month commencement) | Behind the run's `$prorate` flag — it is a **commercial term** |
| **Rent commencement** mid-month | **Unconditional** — rent is not owed before the date the contract says it starts |
| **Move-out / expiry** mid-month | **Unconditional** — billing days after a lease ended is an error, not a term |

**Abatement is per charge type.** Under the default `rent_only` fit-out abatement the tenant has been
paying service charge and marketing since handover, so those bill the **whole** month while the rent
beside them bills half. Only the prorated line carries the `(x% pro-rated)` label.

---

# Part 5 — Advance vs arrears

`charges.billing_timing` — **null means advance**, which is what every charge written before this
does.

Set to `arrears`, the row covers the **previous** cycle: the September invoice carries August's
service charge, on a line reading *"Service Charge - August 2026 (in arrears)"*.

Six things worth knowing:

1. **Per charge, not per lease** — rent ahead and service charge behind, on one lease, is the case
   that matters.
2. **One invoice, not two.** The arrears lines ride the same monthly invoice. A second invoice per
   lease per month would fall into the 3.5 trap monthly instead of occasionally.
3. **The header period no longer bounds every line.** It already did not — late fees and recharges
   ride invoices covering a different window — so the **line's own description** is what the tenant
   reads.
4. **The timing travels with the charge row.** A rent step, a renewal and an ownership transfer all
   carry it. Dropping it on any of those silently reverts an arrears charge to advance and bills the
   crossover month twice, with the schedule looking entirely ordinary.
5. **An arrears line is never clawed back on termination** — it covers a period that already ran.
6. **The last invoice settles both months** and is labelled as the span
   (*"Service Charge - Jul–Aug 2026 (in arrears)"*), because there is no invoice after the lease ends.

> **Known limitation, stated rather than hidden:** a *terminated* lease loses its final month's
> arrears unless the final invoice happens to fall in the termination period. Whether termination
> should raise a final arrears settlement is a decision, not an oversight.

---

# Part 6 — The invoice itself

## 6.1 The statuses

| Status | Means | Set by |
|---|---|---|
| `draft` | Not a document. **Invisible to the tenant everywhere** | Manual, or a service that omits the status |
| `issued` | Raised and owed | Born here |
| `partially_paid` | 0 < paid < total | Automatic |
| `paid` | balance ≤ 0 | Automatic |
| `overdue` | Past due with a balance | Automatic |
| `disputed` | Under argument | Manual |
| `cancelled` | **This should never have been billed** | The **Void invoice** action only |
| `credited` | Settled by a credit note | Manual |
| `written_off` | It was rightly billed and will not be paid | The **Write off** action only |

`cancelled`, `credited`, `disputed` and `written_off` are **never** auto-overwritten by a recompute.

## 6.2 What locks, and why

Once past `draft` — and invoices are **born `issued`** — the form disables the line items and the
lease / tenant / issue-date / number fields. Reverting to `draft` is refused at the model, not just
in the UI.

**Correcting a finalised invoice is a void, not an edit.** *"Void invoice"* takes a reason, returns
any applied credit, zeroes the balance and reverses the GL entry. Then re-issue a corrected one.

> **Captured cash blocks a void.** Refund the payment first. This is enforced on the model in
> `updating`, so the form, the API, a console command and a tinker session all hit it. Before that,
> the status Select offered `cancelled` as a plain option and walked straight past the guard —
> measured on a 10,000 invoice settled by a captured 10,000 payment: the invoice went `cancelled`
> with balance forced to 0, the payment stayed `captured` and still allocated, tenant credit read 0.
> **The tenant's 10,000 was neither receivable nor owed back.**

## 6.3 The header follows the items — always

`subtotal`, `vat_amount`, `total`, `balance`, `paid_amount` and `number` are **derived**. They are
re-derived at the model on every write path, not only in the browser.

This matters because the journalizer debits AR with the **header** total and credits revenue from the
**item** amounts. A divergence computes the two sides of one journal entry from two different numbers.

## 6.4 Due dates

`due_date = max(issue_date, today) + lease.payment_terms_days`.

The issue date stays at the period start — it is the GL entry date and the `YYYYMM` in the invoice
number, so a late run must not move it. The *due* date anchors to when the tenant can actually
receive the bill, which is what stops a back-filled invoice being **born overdue** and immediately
attracting a same-day late fee.

`payment_terms_days` lives on the lease and is pre-filled at origination from the property (falling
back to portfolio) default. It is **not** looked up at billing time — changing a property default must
not retroactively move the due date on receivables already raised.

## 6.5 A draft is not a document

Every tenant-facing read narrows twice: *"is this row theirs?"* **and** *"has it been raised?"*.

Only `draft` is hidden — a `cancelled` or `written_off` invoice still explains a number the tenant
remembers. This leaked on **seven** surfaces at once (list, show, invoice PDF, statement of account,
pay links, the portal table) and `?status=draft` let a tenant enumerate them.

---

# Part 7 — Tax on the line

## 7.1 Two separate questions, two separate homes

| Question | Where it lives | Who decides | Needs a deploy? |
|---|---|---|---|
| **Which supplies are taxable?** | `charge_codes.tax_code` | The accountant | **No** — it is a row |
| **What does that tax charge?** | `tax_codes` + `tax_rates` at `/admin/tax-codes` | The catalogue | **No** — dated rungs |

This is Yardi's shape: a *Tax* flag on the charge code — *"Yes means this charge is taxable; it does
not mean this charge is a tax."*

## 7.2 A rate is a dated rung, never a column

`Vat::rateForType($code, $on)` resolves for the **document's** date. So:

- a rise can be entered **in advance** and applies by itself on the day,
- a back-dated invoice keeps the rate that was in force,
- an issued invoice **never** re-rates. Origination only.

## 7.3 Base rent is exempt in the *shipped catalogue* — that is a row, not a rule

Pointing `base_rent` at a tax code on `/admin/charge-codes` is **configuration**. It needs no deploy,
reaches every lease already on the books, and turns no gate red. Law 157/2025 makes that the
operator's own configuration act.

## 7.4 The rate is picked, not typed

The line's rate is read-only unless you hold `tax_codes.override`, and the real gate is server-side.
An override records a written reason; its presence *is* the flag. It is withheld from `manager`
deliberately — departing from the catalogue is an accounting act.

## 7.5 The line records which tax it carried

`invoice_items.tax_code` is a **classification alongside** the rate, never a pointer the totals
re-derive through. Without it, zero-rated and out-of-scope landed in the same bucket on the VAT
return, and a hand-typed rate was indistinguishable from the catalogue's.

**Exempt ≠ zero-rated.** Both bill 0 and they are different lines on a VAT return.

## 7.6 The catalogue is the operator's own tax sheet

VAT · stamp · schedule · withholding, each in both directions. **Never invent a row beyond it and
never drop one.** Note the asymmetry, which is the real content:

| | Output (we charge it) | Input (we are charged it) |
|---|---|---|
| VAT | Liability — `vat_payable` | **Recoverable asset** — `vat_recoverable` |
| Stamp | Liability | **Expense** — `stamp_tax_expense` |
| Schedule | Liability | **Expense** — `schedule_tax_expense` |

Input stamp and schedule are expenses because neither has a credit mechanism the way input VAT does.
Copying the VAT shape there grows a receivable nobody can ever collect.

---

# Part 8 — Taking money

## 8.1 The payment record

| Field | Notes |
|---|---|
| `reference` | `RCT-…`, allocated race-safely |
| `tenant_id` | Property-scoped picker |
| `payment_date` | **Guarded** — a receipt back-dated into a *closed* period is refused |
| `amount` | Gross |
| `method` | The **rail** — a row in `payment_methods`, not a fixed list (8.4) |
| `status` | Forward flow only on the form (8.2) |
| `allocations` | **At least one is required** (8.3) |

Once recorded, `amount`, `payment_date`, `method` and `tenant` are **disabled**. The allocations
repeater stays editable, because re-allocating a receipt across invoices is legitimate.

## 8.2 "Received" is three statuses, not one

`captured` · `reconciled` · `settled` — **all three mean the money is on the books**, and every
consumer keys off the same set: the AR recompute, the journalizer, the collections widgets, the
portal balance, the statements, the reconciler and both over-allocation guards.

> Before this was one set, the core AR was `captured`-only while the portal widget already grouped
> the three — so **marking a captured payment "reconciled" silently un-paid the invoice.**

**Reversals are not on the form.** `refunded` / `failed` / `bounced` come only from the **Void /
refund** action, which requires a reason.

## 8.3 A receipt with no allocation is an orphan

The form requires at least one allocation, and the server re-checks. A zero-allocation receipt posts
as unearned revenue and is then **invisible** in the property-scoped UI — because payments are
scoped through the invoices they settle — with no way to ever apply it.

That is not theoretical. A cleared PDC that named no invoice minted exactly that record: the receipt
captured with zero allocations, per-property credit read 0, every draw was refused, and **the tenant
was chased and could be charged a late fee while the mall held their cleared cash.**

## 8.4 A payment rail is a row

Add one at `/admin/payment-methods`: a code, a bilingual name, the direction(s) it may be used in,
and optionally the ledger account its money lands in. **Fawry, Meeza, Vodafone Cash and Aman already
exist, switched off.** Activating one is a tick.

The rail's `ledger_account_id` points at the chart **directly**. Null means `cash` for cash and
`bank` for everything else — exactly what the code hard-coded before, so it ships behaviour-identical.

## 8.5 The guards, and what they are for

| Guard | Stops |
|---|---|
| Cross-tenant barrier | Allocating a payment to another tenant's invoice |
| Per-row cap | A row exceeding that invoice's balance |
| Total cap | Allocating more than the payment |
| **Over-allocation backstop** | Two people settling the same invoice at once |
| Posting-date guard | A receipt back-dated into a closed period |
| Duplicate-row dedup | Two repeater rows for one invoice — they are **summed**, not overwritten |

**On the backstop's message:** it names `total − what the other channels already settled`. It used to
quote the invoice *total*, so on an invoice part-settled by a credit note the operator read *"cannot
exceed EGP 240,300.00"* while 60,200 was all that remained — the quoted cap being the very amount they
had just been refused for.

## 8.6 Which lines a payment settled

Optional, via the **Payment split** action. It moves no money and posts nothing — the allocation to
the invoice is already counted.

**Nothing per-line is stored as a balance.** Every per-line figure is derived from `paid_amount`, so
line outstandings always sum back to `balance`. A stored per-item balance would be a second truth
about the same money.

Without an explicit split, a payment settles by **charge-type priority — rent first, late fees
last** — so a partial payment is never eaten by a penalty.

## 8.7 The card rail (Paymob)

Ships **off** (`PAYMOB_ENABLED=false`) pending certification. Three things to know if it is switched on:

- **One live session per invoice+channel.** Two taps used to open two live Paymob orders against one
  debt, each allocated the full balance — capture both and the tenant paid twice.
- **A session is reused only if the amount still matches.** A credit or partial payment drops the
  balance and forces a fresh session, or the tenant is handed a token bound to the old, higher amount.
- **A declined card does not close the order.** A shopper who is declined and retries produces a
  second transaction under the same order. Observed live: a decline at 18:53 and a success one minute
  later filed as "unknown order" and dropped — **the tenant charged and the invoice still open.**

---

# Part 9 — The four settlement channels

**This is the single most important rule in the system.**

```
paid_amount = captured payments
            + credit notes applied        (invoices.credit_applied_amount)
            + tenant credit applied       (TenantCreditApplication)
            + security deposit netted     (DepositApplication)

balance     = max(0, total − paid_amount)
```

Four ways an invoice becomes settled. **Only one of them is cash.**

## 9.1 Why you must know this as an operator

Because "the invoice says paid but no money came in" is a *correct* state, three ways out of four.
When you are reconciling, the question is never *"is it paid?"* — it is *"which channel paid it?"*.

`Invoice::capturedCashPaid()` is the predicate that answers *"is any of this real cash?"*, and it is
what decides whether a void is allowed.

## 9.2 Why a fifth channel is expensive

Each of the last three had to be told to four other places when it was added:

1. `capturedCashPaid()` — the void guard (none of the three is cash),
2. the cancel-invoice release — or the settlement strands on an invoice that left the books,
3. **both** payment over-allocation guards — omitting one lets a payment over-settle an invoice
   another channel already paid, burying the excess as negative AR. *That has happened once already.*

## 9.3 What is *not* a channel

| Not a channel | Why |
|---|---|
| **A write-off** | The balance is left standing; the **status** is what takes it out of AR |
| **Item-level allocation** | It records how an already-counted settlement splits across lines |
| **A lodged PDC** | Nothing settles until the cheque **clears** |
| **A dispute** | The debt stays claimed and aged; only the penalty is suspended |

---

# Part 10 — Credit notes

## 10.1 What one is

A document that reduces what a tenant owes — an overcharge, a service dispute, a CAM
over-recovery, a termination refund. It is **not** cash and it is **not** a payment.

For the tenant it is the document they use to **reverse input VAT they have already claimed**. That
is why it must itemise: a credit note with an empty items table asks them to do that against nothing.

## 10.2 The lifecycle

```
draft ──issue──▶ issued ──apply──▶ issued (balance left) ──apply──▶ applied
  │                 │
  └──── void ───────┴──▶ void   (refused once anything has been applied)
```

- **Applying caps at `min(note balance, invoice balance, requested)`.**
- **Void is refused once `applied_amount > 0`.** To reverse an applied note, un-apply it — do not
  issue a second offsetting note (10.3).
- `applied_at` is stamped on the first apply and never moves.
- A zero-total note flips straight to `applied`.

## 10.3 Un-apply, never stack — and why it is better than the benchmark

Yardi reverses a credit with a second offsetting charge. Atriom **un-applies the original**. Two
documents that cancel each other look tidy and then double-count the moment anything else touches
the invoice.

## 10.4 The tax code is inherited from the line being reversed

A note prefilled from an invoice copies each line's `tax_code` along with its rate, so the reversal is
classified on the VAT return exactly as the supply was. Re-resolving from today's catalogue would
classify the reversal differently from the thing being reversed the moment a rate or a ruling moved.

## 10.5 One line per VAT rate

A quarterly invoice mixes exempt base rent with standard-rated service charge. A single blended credit
line would declare a rate of **2.69%** — an average, not a tax anyone can reverse against.

---

# Part 11 — Credit on account

An overpayment leaves a **credit balance** with the tenant. The **Apply tenant credit** action draws
it onto an open invoice.

Three things to know:

1. **It is its own dated-today GL document**, not a re-shuffle of the original payment. Dr Unearned
   Revenue / Cr Accounts Receivable, dated the day you apply. The first attempt instead extended the
   source payment's allocation — which re-derived that payment's historically-dated GL entry into a
   possibly-**closed** period. AR would drop while the GL refused: a permanent divergence.
2. **Two caps, both applied.** Per-property (property-A credit cannot settle a property-B invoice)
   **and** global (a receipt split across two malls reports its surplus under *both* scopes, so
   without the total cap the same 5,000 could be drawn once per mall).
3. **It is reversible**, and a void auto-reverses it. Only real cash blocks a void.

**The refund interlock:** you cannot refund a receipt whose surplus has already been applied — you
would refund cash that also settled AR. Reverse the applications first, then refund.

---

# Part 12 — Security deposits

**A deposit is a liability, not revenue.** You are holding the tenant's money.

| Type | Books |
|---|---|
| `receipt` | Dr Bank/Cash · Cr **Deposits held** (a liability) |
| `refund` | Dr Deposits held · Cr Bank/Cash |
| `forfeit` | Dr Deposits held · Cr Misc income |
| *netted at move-out* | Dr Deposits held · Cr Accounts receivable |

**The held balance is derived, never stored:** receipts − refunds − forfeits − applications. There is
no cached figure to drift.

## 12.1 The receipt freezes once the deposit is drawn on

> receive 10,000 → net 8,000 against arrears → edit the receipt down to 2,000 →
> **deposit held = −6,000.**

The tenant's AR was settled by money the landlord no longer records receiving. So: amount, lease,
tenant, property, date, type and status freeze once **anything** has been netted, refunded or
forfeited against **that lease's** deposit. Notes stay editable. An **unused** receipt is still fully
correctable.

It is asked of the **lease**, not the row, because the deposit is one pot per lease.

## 12.2 The deposit requirement tracks rent

`security_deposit_months` re-derives `security_deposit` on the lease's save — so the escalation
sweep, the Change Rent action, a renewal, the importer and the API are all covered. **Null means a
flat sum and nothing moves**: a deposit agreed as a figure unrelated to rent is a real deal, and
dividing deposit by rent to infer a multiple would invent a term nobody agreed to.

## 12.3 A billed-but-unpaid deposit is not drift

The GL credits `deposits_held` at **issue**, while the deposit register counts it only once
**settled**. Both are right. The tie-out expects `held + billed-and-outstanding`, or every deposit in
flight reads as a books discrepancy.

---

# Part 13 — Post-dated cheques

In Egypt a tenant commonly lodges a year of post-dated cheques up front. This is the register for
them — and no Western benchmark has it.

```
held ──deposit──▶ deposited ──clear──▶ cleared   (records a captured cheque Payment)
 │                    │
 └──── cancel ────────┴──bounce──▶ bounced ──deposit──▶ deposited  (re-present)
```

**v1 is register-only, settle-on-clear.** A lodged cheque is *tracked*; the tenant's invoice stays
**open** until the cheque **clears**. Clearing records a normal cheque payment through the ordinary
payment flow, so the AR recompute stays the single source of truth.

Five rules:

1. **Clearing is the only money step**, and its date is guarded like any other posting date.
2. **A cheque naming no invoice settles what is open when it clears** — the tenant's own open
   invoices, in that property, **oldest due first**, capped per invoice. Any surplus stays on account
   and is drawable.
3. **A bounce reverses nothing** — no payment existed before clearing. Re-present it. (This is better
   than Voyager, which enters a receipt and then reverses it.)
4. **One physical cheque, one row.** Keyed on (tenant, bank, number) among non-cancelled cheques. Two
   rows for one piece of paper are each independently clearable, and `lodgeSeries()` re-run over the
   same cheque book regenerates the identical numbers *by design*.
5. **Cancelled is excluded from that guard** so a mis-key can be cancelled and re-lodged.

`pdc:scan-maturing` warns on maturity. `pdc:scan-coverage` (weekly) answers the question the maturity
scan structurally cannot: **which tenants are about to run out of lodged cheques while the lease runs
on.** The failure there is the *absence* of a row.

---

# Part 14 — The recoveries — CAM, utilities, turnover rent

These three are one shape of work: **measure a period, apportion it, bill the difference.** That is
why they share a sidebar group.

## 14.1 CAM — the annual cycle

```
estimate  →  tenants pay a monthly service charge all year
actual    →  the pool's real cost is known at year end
apportion →  each tenant's share of the actual
true-up   →  share − what they already paid
```

- **Positive true-up → an immediate recovery invoice of its own.** Its own document, because the
  lease may have ended by then and a line on a future invoice would strand.
- **Negative true-up → an automatic credit note**, applied FIFO.
- **The admin fee is a sibling of the true-up, never folded into it**, and it is charged on the
  **capped** cost so it cannot re-breach the cap.

## 14.2 The denominator is a lease term, not a convention

| `denominator_basis` | Recovers |
|---|---|
| `occupied` **(default)** | 100% of the pool from whoever is trading — **a half-empty mall bills its remaining tenants the whole service charge** |
| `gla` | Share of gross leasable area — **vacancy stays with the landlord** |
| `fixed` | A contractually pinned m² (falls back to `occupied` when unset) |
| a lease's `stated_share_pct` | The named percentage. Beats any derived one, and does not inflate the neighbours |

Some leases genuinely say the first. Many say the second. **Read the clause.**

## 14.3 Occupancy, not lease status

The share follows the **days a tenant occupied within the recovery year**. It was wrong at both ends,
in opposite directions:

- **Arriving:** a lease commencing 1 October drew a **full** year's share. On a 500,000 pool a 100 m²
  lease three months in took **23.81% / 119,048** against a correct **~6.2% / ~30,883** — over-recovering
  from the newcomer *and* under-charging everyone else.
- **Leaving:** a tenant who traded January to August and left was not a target at all, so the cost of
  the months they *did* occupy was recovered from **nobody** and fell silently to the landlord.

An `active` lease past its expiry is in **holdover**, still trading, and is not clamped.

## 14.4 Caps

Effective-dated per lease, tighter ceiling wins, landlord absorbs the difference. `absolute` · `yoy`
(simple or compounding) · `both`. Two refinements that match real clauses:

- **`cap_scope = controllable`** caps only what the landlord can manage. Most clauses carve out rates,
  insurance and utilities — you cannot ask a landlord to absorb a levy it does not set.
  ⚠ **It needs the POOL to say what is controllable**; unclassified, it behaves exactly like an
  unscoped cap.
- **`cap_carry_forward`** banks a year that came in under the ceiling and spends it on a later year
  that bites. It reads earlier **allocations**, not earlier terms, so a cap renegotiated in year three
  cannot retroactively change what year one banked.

## 14.5 Three things about CAM that surprise everyone

1. **A re-run reuses the FROZEN shares.** The share basis is set on the first run. So a stated share
   added afterwards **does nothing**, and nothing on screen distinguishes the two. To exercise a
   stated share, reconcile a pool that has never been generated.
2. **A stated share above the area share refuses the whole run** — *"the contractually stated CAM
   shares on this pool add up to 109.89% — more than the pool itself"*. That refusal is the feature.
   Scaling somebody's agreed percentage down is not a decision an engine may take.
3. **A derived total says when it has never been sourced.** `expense_basis = ledger` means the column
   is computed — *but only when someone runs **Sync from ledger***. Found on a live pool: actual
   500,000 · estimated **0** · variance 500,000, against a true variance of **154,000**. The
   allocations were right throughout; only the header lied.

Use **View working** on an allocation row: share → allocated → ceiling → capped → absorbed → estimate
→ true-up → recovery VAT → admin fee → net invoiced. The visible columns stop adding up the moment a
cap bites, which is the one time anyone looks.

## 14.6 Utilities and turnover rent, briefly

- **Utilities:** a meter reading × a **dated tariff** → a recharge invoice. A billed reading locks.
- **Percentage rent:** the tenant declares sales; the overage above a breakpoint is billed. Natural
  breakpoint (rent ÷ rate) and artificial (a stated figure) both ship, with tiers and deductions.
  `sales:estimate-missing` fills a gap from the tenant's own trailing average — **marked as an
  estimate and never auto-locked.**

---

# Part 15 — Chasing the money

## 15.1 The ladder

| Step | What it is | Cadence |
|---|---|---|
| `billing:scan-overdue-invoices` | Tells the **owner** their tenant is late | Daily, once per invoice ever |
| `billing:remind-overdue-tenants` | Tells the **tenant** | Daily, on its own stamp — the two fire independently |
| Dunning level | The notice **number** (`dunning_level`) beside the date of the last one | Cadence is a setting |
| `billing:apply-late-fees` | The penalty | Daily |

**`dunning_followup_days` ships at 0 = chase once**, which is what every install did before. **How
hard you chase is a commercial judgement about tenants you have to keep working with**, so nothing
changed on deploy.

`dunning_max_notices` (default 3) is the ceiling; the notice **at** the ceiling is a final demand —
and **the final demand is your own words or it does not happen.** It has no fallback wording,
deliberately: a system-composed *"FINAL NOTICE"* is the message most likely to start an argument
nobody intended.

**The ladder is per invoice, not per tenant.** A tenant may be current on one and months behind on
another; a per-tenant counter would send a final demand about a bill raised yesterday.

## 15.2 Late fees

`fee = max(minimum, chargeable × percent)`, then **capped**.

| Term | Tiers | Ships |
|---|---|---|
| `late_fee_percent` | lease → property → portfolio | 2% |
| `late_fee_grace_days` | same | 7 |
| `late_fee_minimum` | same | EGP 50 |
| `late_fee_maximum` | same | **0 = no cap** |
| `late_fee_recurrence_days` | same | **0 = charge once** |

Two rules with money in them:

- **The cap is applied AFTER the minimum.** A ceiling is a statement about the most you will charge;
  a floor only rounds small ones up. `max()` last would bill above the cap the clause names.
- **A late fee is its own invoice.** And `items()->where('type','late_fee')` is an **absolute bar**:
  with recurrence on, it is the only thing between you and a penalty compounding on a penalty.

## 15.3 A disputed line is not chargeable

`DisputeInvoiceItemService` marks a **line**, with a required reason. The late fee is then charged on
`balance − disputed outstanding`, and when that reaches zero it charges **nothing** — not the
minimum, which would bill the floor off a balance nobody has agreed is owed.

**A dispute is not a write-off.** The debt stays claimed, aged and on the balance sheet; only the
penalty is suspended. The disputed figure is shown **beside** the aged one on *Aging by charge type*,
never netted out of it. The invoice **header** is deliberately untouched — an invoice is rarely
disputed in full, and marking the header stops you chasing the rent on the same document.

## 15.4 Write-off — the last step

An uncollectible debt had two wrong homes before this:

- **Cancel** reverses the revenue in the *current* period — including revenue earned in a prior year.
  The year it was earned ends up understated, this year overstated, and the bad debt never appears as
  bad debt at all.
- **Leave it** and aging carries fiction for ever.

`Write off` keeps the revenue where it was earned, credits AR and debits Bad Debt Expense **dated at
the decision**.

| Rule | Why |
|---|---|
| Status becomes `written_off`, never `cancelled` | *"should never have been billed"* and *"rightly billed, will not be paid"* are different facts |
| The `balance` is **left standing** | It is the record of *what was written off*. The **status** is what takes it out of AR |
| A **partial** write-off leaves the invoice live | Writing off 5,000 of 20,000 does not mean the other 15,000 stopped being owed |
| The modal caps at the **remainder** | Otherwise accepting the default after a partial books 25,000 of bad debt against a 20,000 receivable |
| Reversal is a soft-delete, not an edit | A recovered debt — and **both** decisions stay on the record |

**Recovery has a button.** If a written-off debt is later paid, reverse the write-off. Do not re-bill
it (that double-counts the revenue) and do not book it as miscellaneous income (that loses the AR
history).

---

# Part 16 — What each act does to the books

Every one of these posts automatically. `[role]` resolves through the posting map, so the account is
the accountant's choice, not the code's.

| Act | Debit (مدين) | Credit (دائن) |
|---|---|---|
| **Issue invoice** | `accounts_receivable` (total) | one line per charge kind — `rent_revenue`, `service_charge_revenue`, `utility_revenue`, `percentage_rent_revenue`, `marketing_revenue` — plus `vat_payable` |
| **Capture payment** | `bank` / `cash` | `accounts_receivable` |
| **Apply credit note** | `sales_returns` + `vat_payable` | `accounts_receivable` |
| **Apply tenant credit** | `unearned_revenue` | `accounts_receivable` |
| **Late fee** | `accounts_receivable` | `late_fee_income` |
| **Deposit receipt** | `bank` / `cash` | `deposits_held` *(liability)* |
| **Deposit refund** | `deposits_held` | `bank` / `cash` |
| **Deposit forfeit** | `deposits_held` | `misc_income` |
| **Deposit netted at move-out** | `deposits_held` | `accounts_receivable` |
| **CAM positive true-up** | `accounts_receivable` | `cam_recovery_revenue` (+ VAT if taxed) |
| **Write-off** | `bad_debt_expense` | `accounts_receivable` |
| **Void anything** | a balanced **reversing** entry | — |

## 16.1 Four things about the GL you will feel as an operator

1. **The ledger is derived, not journalled.** When a document changes, the poster re-reads it and —
   if the posted entry no longer matches — **voids it and posts a fresh one**. That is why "may this
   edit move the books?" is a real question the system answers per field.
2. **A closed period refuses.** Every operator-typed date that becomes an entry date is guarded. A
   missing period is allowed; only a **closed** one is refused. Without the guard the row commits,
   you see *"Saved ✓"*, and the entry is refused inside a background job that only logs.
3. **A document whose entry sits in a closed period cannot have its money restated.** Measured on
   the demo books: a 1,590.00 marketing spend posted in a closed August, retyped to 2,824.00 → **save
   accepted**, GL still 1,590, and the reconciler failed permanently. That is now refused. Use
   **post month** if the bill genuinely belongs to a closed month you must report in an open one.
4. **The tie-out is the proof.** `accounts_receivable` in the ledger must equal Σ live invoice
   balances. `billing:reconcile --deep` says *which document* disagrees.

## 16.2 Opening balances at cut-over

Opening receivables arrive as **open items — real invoices**, not a lump sum per tenant. Aging, the
dunning ladder, statements and per-invoice allocation all work on documents: a single balance has no
number to quote to a retailer who disputes it.

They **post nothing** — the revenue was earned in your previous system and is already inside the
opening trial balance your accountant loads as one journal entry. Posting again would recognise it
twice. Their **own invoice numbers are preserved**, because that number is on the paperwork the
retailer already holds.

**The tie-out is the migration's proof:** `billing:reconcile` going square after cut-over is the
statement *"the receivables I loaded equal the receivables my accountant says I have."*

---

# Part 17 — "I clicked and nothing happened"

| What you see | What it means | What to do |
|---|---|---|
| *"Nothing was billed"* + a reason | One of the eight codes in 3.3 | Read the reason — it names which |
| Run says `skipped: already_billed` | An invoice overlaps that month | Check whether it is a one-off that should not have suppressed the rent (3.5) |
| A back-dated charge never bills | The month is closed to the run | You were told at the moment you added it. Credit and re-issue, or bill it separately |
| Void invoice is refused | Captured **cash** is allocated to it | Void/refund the payment first. Credit notes and tenant credit do **not** block |
| *"cannot exceed EGP 0.00"* | Another channel already settled it | Look at all four channels, not just payments |
| Apply credit is refused | No credit in **that property**, or the invoice has no resolvable property | Check the per-property balance, not the global one |
| Void credit note is refused | It has been applied | Un-apply it. Do not issue a second note |
| Deposit receipt fields are locked | Something has been netted against that lease's deposit | Correct via a refund/forfeit, not an edit |
| CAM re-run ignores a stated share | Shares froze on the first run | Reconcile a pool that has never been generated |
| CAM run refused, "…add up to 109.89%" | Stated shares promise more than the pool | Fix the terms. The refusal is correct |
| Estimate reads 0 with a huge variance | The pool is derived and was never sourced | Run **Sync from ledger** |
| A payment saved and the invoice is still open | Status is `initiated`, not received | Only captured/reconciled/settled count |
| Payment refuses to save | No allocation row | Every receipt must name at least one invoice |
| A tenant says they never got the invoice | It was raised by a path that does not notify | Use **Send to tenant** — it is a first-class re-sendable action |
| A date is refused | Its accounting period is **closed** | Re-date into an open period, or set a post month |

---

# Part 18 — The rhythm

## Daily (automatic — you only read the results)

`billing:scan-overdue-invoices` · `billing:remind-overdue-tenants` · `billing:apply-late-fees` ·
`accounting:sync-ledger` · `leases:expire` · `pdc:scan-maturing` · `sales:scan-missing-declarations`

## Monthly

| Order | Do |
|---|---|
| 1 | Open **Billing run preview** for the month, in each mall. Read the refusals |
| 2 | Post the run |
| 3 | Chase declarations — turnover rent cannot bill without them |
| 4 | Record receipts; clear matured cheques |
| 5 | Open `/admin/month-end-close` — seven ordered rows: billing posted · declarations received · payments settled · vendor bills posted · **ledger in sync** · books tie out · period closed |
| 6 | Fix whatever is red. **`ledger_in_sync` is the real close gate** — it catches the same assertion the close itself throws, so a green checklist means the close will succeed |
| 7 | Close the period on the Accounting Periods screen |

## Annually

CAM reconciliation (`cam:reconcile`), percentage-rent settle-up, the fiscal-year close.

## Before any deploy or import

```
php artisan atriom:preflight                  # health + config + both audits + a deep reconcile
php artisan atriom:audit-charge-schedules     # leases whose charge rows bill NOTHING
php artisan atriom:audit-property-dimension   # money filed against no property
```

---

# Part 19 — The twelve rules

1. **Never set `paid_amount` or `balance` yourself.** One derivation, four channels.
2. **Never edit a finalised invoice.** Void and re-issue, or credit-note it.
3. **`cancelled` and `written_off` are different facts.** Never use one for the other.
4. **A draft is not a document.** The tenant never sees one — do not "fix" that.
5. **Leave `vat_rate` and `vat_applicable` blank** unless a deal genuinely fixed a rate.
6. **A rate is a dated rung.** Enter a rise in advance; never edit history.
7. **Every receipt must allocate to something.** An orphan receipt is invisible and unspendable.
8. **A lodged cheque settles nothing until it clears.**
9. **Un-apply a credit note. Never stack a second one.**
10. **A back-dated date into a closed period is refused — always.** Use a post month instead.
11. **Read the CAM clause before choosing the denominator.** It decides who carries vacancy.
12. **Run the preview before the run**, and read the refusals rather than scrolling past them.

---

## Related

- [`../modules/05-billing-invoices.md`](../modules/05-billing-invoices.md) · [`06`](../modules/06-payments.md) · [`07`](../modules/07-credit-notes.md) · [`08`](../modules/08-cam.md) · [`09`](../modules/09-tenant-sales-percentage-rent.md) · [`33`](../modules/33-post-dated-cheques.md)
- [`../accounting/WALKTHROUGH.md`](../accounting/WALKTHROUGH.md) — the same tour for the books, bilingual
- [`../BUSINESS-RULES.md`](../BUSINESS-RULES.md) — every financial rule, for sign-off
- [PAYABLES-WALKTHROUGH.md](PAYABLES-WALKTHROUGH.md) — the other direction
- [OPERATOR-PLAYBOOK.md](OPERATOR-PLAYBOOK.md) — the cross-module traps and the rhythm
