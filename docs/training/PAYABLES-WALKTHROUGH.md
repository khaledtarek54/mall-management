# Payables — a walkthrough for someone new

**Who this is for.** Someone who has to run the money-out side of a mall: buy things, record what
suppliers invoice, pay them the right amount after tax, and be able to show an auditor where every
pound went. It assumes **no** accounting background.

**Who this is NOT for.** It is not the developer's document — those are
[`modules/12`](../modules/12-vendors.md), [`29`](../modules/29-procurement.md),
[`25`](../modules/25-treasury-custody.md), [`22`](../modules/22-inventory.md),
[`23`](../modules/23-fixed-assets.md), [`24`](../modules/24-hr-employees.md) and
[`21`](../modules/21-general-ledger.md).

**Read [RECEIVABLES-WALKTHROUGH.md](RECEIVABLES-WALKTHROUGH.md) first if you can.** AP is AR in a
mirror, and half the rules are the same rule pointed the other way.

---

## Table of contents

| Part | What it answers |
|---|---|
| [0](#part-0--what-payables-actually-is) | What "payables" actually is |
| [1](#part-1--the-vocabulary) | The vocabulary — 20 words |
| [2](#part-2--the-five-roads-money-leaves-by) | The five roads money leaves by |
| [3](#part-3--the-supplier-register) | The supplier register — and the dispatch gate |
| [4](#part-4--the-vendor-bill) | The vendor bill — draft → approved → paid |
| [5](#part-5--paying-a-bill-and-withholding-tax) | Paying a bill, and withholding tax (خصم وإضافة) |
| [6](#part-6--voiding-a-payment) | Voiding a payment — the way back from a wrong cheque |
| [7](#part-7--direct-expenses) | Direct expenses (مصروف مباشر) |
| [8](#part-8--recurring-costs) | Recurring costs |
| [9](#part-9--procurement--request-approve-order-receive) | Procurement — request, approve, order, receive |
| [10](#part-10--grni--the-account-that-proves-you-paid-once) | GRNI — the account that proves you paid once |
| [11](#part-11--custody--عهدة) | Custody — العهدة |
| [12](#part-12--payroll-briefly) | Payroll, briefly |
| [13](#part-13--the-sla-penalty--the-signature-move) | The SLA penalty — the signature move |
| [14](#part-14--what-each-act-does-to-the-books) | What each act does to the books |
| [15](#part-15--the-statutory-filings) | The statutory filings |
| [16](#part-16--i-clicked-and-nothing-happened) | "I clicked and nothing happened" — refusals decoded |
| [17](#part-17--the-rhythm) | The rhythm |
| [18](#part-18--the-eleven-rules) | The eleven rules you must not break |

---

# Part 0 — What payables actually is

**Payables (AP, الذمم الدائنة) = money the mall *owes* and has not yet *paid*.**

The same split as AR, mirrored:

| Question | The document | Called |
|---|---|---|
| *"What do we owe this supplier?"* | The vendor bill | **Expense / payable** |
| *"What have we paid them?"* | The bill payment | **Cash** |

## 0.1 The two sentences to carry

> **1. A cost hits the P&L when it is INCURRED, not when it is paid.**
> **2. `VendorBill::recompute()` is the only thing that decides how much of a bill is settled, and
> it counts payments AND applied SLA penalties.**

Sentence 1 is the whole of accrual accounting. Sentence 2 is the AP twin of the AR four-channel rule.

## 0.2 Why "when it is incurred" matters to you

A generator service done in August and invoiced in October is an **August** cost. If you book it in
October:

- August's P&L understates the cost and looks better than it was,
- October's overstates it,
- and the CAM pool for the year splits across two years' worth of numbers that neither tenants nor
  the owner can reconcile.

That is what a **post month** is for (Part 4.5), and what a **closed period** refusal is protecting.

---

# Part 1 — The vocabulary

| Term | Arabizi | In the system | What it means |
|---|---|---|---|
| **Vendor** | mawrid / mo2awil | `Vendor` | A supplier, contractor, service provider or consultant |
| **Vendor contract** | 3a2d el mawrid | `VendorContract` | A time-bound agreement, optionally pinned to one mall |
| **COI** | shahadet ta2meen | `coi_expires_at` + `vendor_documents` | Certificate of insurance. **It gates dispatch** |
| **Vendor bill** | fatoret mawrid | `VendorBill`, `VB-…` | *They* invoiced *us*. The payable |
| **Reference** | ra2m fatorethom | `vendor_bills.reference` | **Their** invoice number. The duplicate guard is keyed on it |
| **Expense** | masroof mobasher | `Expense`, `EXP-…` | Paid immediately from cash or bank. No payable stage |
| **Recurring expense** | masroof motakarrer | `RecurringExpense` | A cost that arrives on a calendar — real-estate tax, a retainer |
| **Purchase request** | talab shira | `PurchaseRequest`, `PR-…` | *We need this, here is why* |
| **Approval tier** | mostawa el e3temad | `ApprovalPolicy` bands | Which manager may sign off, by value |
| **Goods receipt** | estelam bada2e3 | `StockMovement` | Goods arrived and entered stock |
| **GRNI** | badae3 mostalema gher mofawtara | `21701001` | *Goods Received Not Invoiced* — a clearing liability |
| **AP control** | 7esab el da2eneen | `accounts_payable` | The ledger account that must equal Σ open bill balances |
| **WHT** | khasm w edafa | `withholding_amount` | Tax withheld at source from a supplier and remitted to the ETA |
| **Input VAT** | dareeba modkhalat | `vat_recoverable` | VAT the supplier charged us, which we reclaim |
| **Custody** | 3ohda | `Custody` | Cash advanced to a named person, settled back with receipts |
| **Payroll** | mortabat | `Payroll` | The monthly staff run |
| **SLA penalty** | gharamet ta2kheer 3ala el mawrid | `SlaPenalty` | What a contractor owes for missing a service level |
| **Void** | elgha2 | `voided_at` + a reason | Reversing a payment **without deleting it** |
| **Post month** | shahr el tarheel | `posting_month_overrides` | Post the entry to a different month from the document's own date |
| **Three-way match** | — | ordered / received / billed | The AP clerk's check against paying twice |

---

# Part 2 — The five roads money leaves by

Every pound out of the mall goes one of five ways. Picking the wrong one is not a formatting choice —
it changes the books.

| Road | Screen | Use when | Payable stage? |
|---|---|---|---|
| **Vendor bill** | Payables → Vendor bills | A supplier sent an invoice you will pay later | **Yes** — Dr Expense / Cr AP |
| **Direct expense** | Payables → Expenses | Paid on the spot from cash or bank; no invoice to sit on | No — Dr Expense / Cr Cash |
| **Recurring cost** | Payables → Recurring expenses | It arrives on a calendar every month/quarter/year | Either (see Part 8) |
| **Purchase request** | Payables → Purchase requests | You are **buying** something and want it approved first | Becomes a bill |
| **Custody** | HR → Custody | You gave a person cash to spend and settle | No — Dr Custodies / Cr Cash |

Plus two that are their own modules: **payroll** and **depreciation**.

## 2.1 The one distinction people get wrong

> **Naming a vendor IS the statement that a cost is a payable.**

`expenses` has no `vendor_id` at all. So:

- A cost with a **supplier** → a vendor bill.
- A cost with **no supplier to chase** → an expense.

There is no second `type` column that could disagree with the row.

---

# Part 3 — The supplier register

## 3.1 A vendor is shared, a contract is not

One HVAC contractor can serve every mall you run — so the **vendor** record is portfolio-wide, and
one COI covers every mall. A **contract** may be pinned to one property (`asset_id`), or left null
for a portfolio-wide agreement.

## 3.2 The dispatch gate — the one rule with teeth

> **A blacklisted or inactive vendor, or one whose insurance has lapsed, cannot be dispatched to
> work.**

| Detail | Rule |
|---|---|
| When it is checked | **At assignment**, not retroactively — a job whose vendor's COI expires later is not broken |
| No COI on file | **Still assignable.** v1 does not force a certificate onto every existing vendor. Blacklist to hard-block |
| Which documents block | `vendor_document_types.blocks_dispatch` — **a row**. Insurance is the shipped default |
| Where the COI lives | A **private** media collection. Signed certificates are never on the public disk |

**Read the failure direction on the blocking list:** an empty blocking list matches *nothing*, so a
catalogue answering "no blocking types" would make **every uninsured contractor dispatchable** with
no error at all. The shipped default therefore applies when the table holds **no rows**, not when the
list is empty — an operator who unticks everything meant it. And **inactive types still block**:
`is_active` governs what may be *filed*, not whether a certificate on file counts.

**Eligibility is a suggestion; compliance is the gate.** The vendor picker *groups* by trade
("Does this trade" / "Other vendors") and never filters, because refusing an unusual-but-legitimate
pick is the worse failure.

## 3.3 Two fields that decide money

| Field | Effect |
|---|---|
| `withholding_tax_code` | Which of the four withholding natures on your tax sheet applies (0.5 / 1 / 3 / 5%) |
| `withholding_exempt` | This supplier is outside Egyptian withholding altogether |

These used to be one overloaded percentage column where null meant *"use the default"* and an explicit
0 meant *exempt* — a distinction a spreadsheet cannot show at all.

`wht_default_tax_code` **ships empty**, deliberately: there is no defensible default nature, and an
empty one withholds nothing.

## 3.4 The vendor scorecard

`/admin/vendor-scorecard` — jobs raised / completed / open, average hours to acknowledge and to
resolve, SLA breaches, penalties applied and their value, lapsed documents, and whether the vendor is
dispatchable at all.

Two decisions worth knowing:

- **Counts and times, never a single score.** Weighting responsiveness against cost against
  compliance is your judgement — a vendor who is slow but cheap may be exactly right for routine work.
- **A blank response time is not zero.** Averaging "never acknowledged" as zero would flatter the
  vendor into looking instant.

It is gated on `vendors.view`, deliberately **not** `reports.view` — the external `vendor` role must
never read a competitor's response times.

---

# Part 4 — The vendor bill

## 4.1 The lifecycle

```
draft ──approve──▶ approved ──recordPayment──▶ partially paid ──▶ paid
  │                    │
  └──── cancel ────────┘   (refused once any cash has been paid)
```

- **`draft` posts nothing.** It is a document you are still keying.
- **`approve()` is what recognises the payable** — and it **guards the bill date's period**. A draft
  approved after its bill-date month has closed cannot recognise the payable, so approval is refused
  until you re-date the still-editable draft into an open period.
- **`cancel()` refuses if any cash was paid**, and **releases any applied SLA penalty back to
  `final`** — a cancelled bill owes nothing, so an applied penalty would otherwise be silently
  dropped (still owed, but no longer chargeable).

## 4.2 `reference` is THEIR invoice number, and it is guarded

**The same supplier invoice cannot be entered twice.** Keyed on **(vendor, reference)** among
non-cancelled bills, matched **case-insensitively and trimmed** — because two people keying one
document do not produce byte-identical strings, and an exact-match guard would miss the realistic
duplicate while looking like coverage.

| Case | Behaviour |
|---|---|
| Blank reference | **Exempt.** Not every bill arrives with the number to hand — this is the deliberate escape hatch |
| Cancelled bill | Excluded, so a mis-key can be cancelled and re-entered |
| Why not a DB index | Because of the two rows above |

> **This deviates from Yardi, which warns and lets you through. Here it refuses**, because this
> particular failure pays money out of the door.

## 4.3 Input tax on a supplier's bill

`vendor_bills.tax_code` names which purchases-side tax the supplier charged.

**The amount stays editable**, unlike an invoice line's rate — and that asymmetry is deliberate:

| | Whose number | Editable? |
|---|---|---|
| Our invoice's VAT | **Our** decision | Re-derived from the catalogue, gated on `tax_codes.override` |
| A supplier's bill VAT | **Their** number on **their** document | Editable — refusing to record what they actually charged pushes the difference somewhere worse |

Picking a code fills the amount in. A departure of more than **EGP 1** requires a written reason —
one pound because a rounding difference between two systems computing the same percentage is
sub-unit; anything larger is a different rate or a different base, which is a decision.

## 4.4 A bill cannot be paid beyond its balance

`recordPayment()` locks the bill and caps at the remaining balance. What makes this airtight rather
than merely guarded is that `VendorBillPayment::create` has **exactly one caller in the codebase**,
and it is that service. **Keep it that way** — a second creator would need its own cap.

## 4.5 Post month — the bill that arrives late

`posting_month_overrides` lets one document post to a **different month** without changing its own
date. That is the honest answer to "the October invoice is for August work and August is closed".

Three things it does **not** do:

1. It does not reopen a closed month — a **closed target is still refused**.
2. It does not change the document's own date, so the supplier's invoice still says what it says.
3. It clamps the day rather than rolling it — 31 January → February is the 28th, never 2 March.

---

# Part 5 — Paying a bill, and withholding tax

## 5.1 What withholding actually is

Egyptian law makes **you** responsible for deducting a slice of what you pay a supplier and remitting
it to the tax authority on their behalf. Pay them gross and the un-withheld amount becomes **your own
liability**.

It is a **prepayment of the supplier's income tax**, not a cost to you. That is why they get a
**certificate** — the document they hand their own accountant to claim the tax already deducted.

## 5.2 The mechanics

```
amount            = GROSS — it discharges the supplier's claim in full
withholding_amount= the slice owed to the ETA
net cash out      = amount − withholding_amount
```

| Books | |
|---|---|
| Dr | `accounts_payable` — **gross** |
| Cr | `bank` — **net** |
| Cr | `withholding_tax_payable` — the withheld slice (only when > 0) |

**Withholding sits INSIDE the gross payment**, so it never enters the balance arithmetic. The bill is
settled in full; part of the settlement went to the supplier and part to the ETA.

## 5.3 The base EXCLUDES VAT — the bug worth understanding

Withholding is charged on the **consideration for the supply**. The VAT on top is the supplier's own
output tax, which they remit themselves. Withholding on it taxes a tax.

Measured, at 3% on a 100,000 net bill with 14,000 VAT:

| | Base used | Withheld | Result |
|---|---|---|---|
| **Wrong** | 114,000 (total) | **3,420** | Supplier short-paid by 420, ETA over-remitted by 420 — **on every payment** |
| **Right** | 100,000 (net) | **3,000** | — |

**A bill with no VAT is unaffected**, which is exactly what made this invisible to every exempt
supply — and why the whole existing test suite was blind to it: every fixture had `vat_amount => 0`,
so net and gross were the same number.

A **partial** payment splits on the bill's own VAT-exclusive share. Of 57,000 paid against a
100,000 + 14,000 bill, **50,000 is consideration**.

## 5.4 On screen

The payment modal shows a live *"what the bank will pay"* breakdown, and the success toast reports
**net paid + withheld**, not just gross. The preview calls the **same** function that records, and a
test asserts the two agree — a displayed number drifting from a recorded one is invisible, because
the screen is the only thing anyone checks.

Ships **off** (`wht_enabled`). It was kept off for months precisely because withholding money you
cannot declare or evidence is worse than not withholding it — the filing artefacts (Part 15) are what
unblocked it.

---

# Part 6 — Voiding a payment

A payment keyed against the wrong bill used to be **permanent**. Three places promised a correction
that did not exist, while the AP balance, the bank leg and the withholding liability were all wrong
with no operator path to any of them.

> **A void is a status flip, not a delete.**

| Rule | Why |
|---|---|
| `voided_at` + `void_reason` + who did it | The row **stays on the register**, greyed with its reason |
| The reason also goes to the activity log | `void_reason` is a column somebody can later edit; the log is not |
| The payable **re-opens** | A voided payment is excluded from `paid_amount`, so the status falls back from `paid` |
| The entry is **reversed** — AP, bank and WHT | All three legs net to zero |
| It does **not** reopen a closed period | The reversal lands in the original entry's period when open, and in **today's** otherwise |
| A recorded payment is otherwise **immutable** | Amount, withholding, date and which bill are refused by a guard — the cash has already left the bank |

**That last row could not exist before the void did.** Locking the fields without a reversal path
would have trapped an operator holding a wrong cheque, and a refusal is only as good as the path it
names.

Voiding is its own permission (`vendor_bills.void_payment`), not folded into `.pay` — **keying a
cheque and un-keying one are different acts.**

---

# Part 7 — Direct expenses

مصروف مباشر — a cost paid immediately from cash or bank, with no payable stage.

| Field | Notes |
|---|---|
| `category` | **The catalogue** — it names the P&L account the cost books to |
| `paid_from` | cash / bank — a **rail** from the payment-methods catalogue |
| `expense_date` | Guarded. A closed period is refused |
| `amount` | Net |
| `tax_code` + `vat_amount` | Input tax, with the same EGP 1 override rule as a bill |
| `total` | **Derived** — amount + VAT. Never typed |
| `facility_work_order_id` | Optional — attaches the cost to a job (Part 13) |
| `asset_id` | **Nullable, and null is a real answer**: portfolio-level overhead every mall must see |

## 7.1 An expense category is a row, and it names an account

This is the single most useful thing to know about costs.

> **`expense_categories.ledger_account_id` points at the chart directly.**

It used to be a six-entry list in code, which was the **only** thing deciding which account a supplier
bill, an expense or a custody spend hit. So insurance, government fees, bank charges, legal fees and
generator fuel **all fell through into `admin_expense`** behind a silent log warning.

Two consequences:

1. **Adding a category is a row, not a deploy.** Null falls back to the same six-entry floor, so
   nothing moves until you point it somewhere.
2. **Pointing a category at an account inside a CAM pool starts recovering those costs through it.**
   That is a real commercial decision made by editing a row — know that you are making it.

All four cost journalizers — vendor bill, expense, custody settlement, and the recurring path —
resolve through the same method, so the three cannot drift.

## 7.2 A cost's nature is a different question

`CostNature` (fixed vs variable) is read by the expense register and the weekly-spend report. It is
**not** what CAM apportionment reads — that is `cam_pool_accounts.cost_nature`, a per-account pivot on
a different table with the opposite default. **Naming them alike is a trap.** What actually reaches a
tenant is the **account**.

---

# Part 8 — Recurring costs

`/admin/recurring-expenses` + a daily run. Yardi's Recurring Payables, which this system had no
counterpart to at all — recurrence existed only on the revenue side, so every cost arriving on a
calendar (real-estate tax, municipal levies, a licence renewal, a fixed retainer) was somebody's
reminder.

## 8.1 Naming a vendor changes what it raises

| The schedule names | It raises |
|---|---|
| No vendor | An **`Expense`**, posted |
| A vendor | A **DRAFT `VendorBill`** |

**Draft, deliberately.** `vendor_bills.reference` is the *supplier's* invoice number — unique per
vendor and impossible to invent — and posting Dr Expense / Cr AP for an invoice nobody sent invents a
creditor's claim. Voyager stages its recurring payable batch for exactly this reason.

So a recurring supplier cost is a **worklist item**: the system drafts it, you fill in their invoice
number when it arrives, and approve.

## 8.2 Four safety properties

1. **The schedule is never a GL source.** It mints an `Expense` or a `VendorBill`, which post through
   the journalizers that already exist. Registering the schedule itself would post every levy
   **twice** — and balance both times.
2. **One period per run.** A schedule switched back on after six months must not post six back-dated
   entries into possibly-closed periods.
3. **The day is clamped to the month** — a 31 must not skip February.
4. **Idempotent three ways**: a lock, the due date re-derived *inside* the transaction after the lock,
   and a UNIQUE index underneath so a bug fails loudly instead of double-booking.

## 8.3 What is deliberately not modelled

Real-estate tax **itself** — the rate, the rental-value basis, the 32% non-residential deduction and
the assessment are the operator's facts. A computed guess would put a confident wrong number on a
statutory filing. `government_fees` ships switched **off**, so activating it is part of setting one up.

---

# Part 9 — Procurement — request, approve, order, receive

```
draft ──submit──▶ requested ──approve──▶ approved ──order──▶ ordered ──receive──▶ received
   │                  │
   └── cancel ────────┴── reject
```

## 9.1 The requirement, expressed as data

There is **no edge from `requested` to `ordered`**. Ordering an unapproved request is not refused —
it is *unrepresentable*. That is the whole of *"route through an approval workflow before order
placement"*.

## 9.2 The approval tier

- Which approver depends on the **value**, resolved against the procurement bands.
- The tier is **frozen onto the row at request time** — so a later edit to the bands cannot rewrite
  history about who was *supposed* to sign off.
- **…but the tier is re-judged on the CURRENT total at approval.** The frozen value is a record, not
  the gate.
- **Two questions, both required:** the base right (`procurement.decide`) **and** the tier. With no
  bands configured, the tier check returns true for any signed-in user — so the base right is what
  keeps the door shut.
- **You cannot approve your own purchase.**
- `procurement.decide` is withheld from `operations` on purpose: operations raises the need and
  receives the goods; committing the mall's money is a management act.

## 9.3 Approved lines freeze

Editing what is being bought behind an approved *value* is exactly the failure the approval exists to
prevent. Measured:

> raise 5,000 → tier 1 → a supervisor approves it, correctly → **add a 500,000 line** → the total
> re-derives to 505,000 while the frozen tier still says tier 1. **The mall is committed two tiers
> above what anyone with the authority approved, and the record asserts a supervisor approved it.**

Only the **commercial** fields freeze. `stock_movement_id` is deliberately excluded — receiving goods
stamps the line it fulfilled, on a request that is past `requested` by definition.

## 9.4 Low stock drafts, a human submits

`inventory:scan-low-stock` now **drafts** one purchase request per property from the open shortages,
and **refreshes** it on the next run rather than creating a second — so a shortage that resolved
itself **drops off** instead of being ordered anyway.

- A system-raised draft has **no requester** — nobody asked for it.
- A draft can only be **submitted or cancelled**, never approved. That single restriction is what
  makes it safe for something other than a person to create one.
- `reorder_quantity` null is a real answer meaning *"we have not said"*, and the drafted line carries
  the bare shortfall — a number that lands the item exactly on its threshold and is therefore to be
  **corrected, not accepted**. Inventing a multiple would be inventing a purchasing policy, and a
  plausible wrong number in a draft gets approved whereas a blank gets filled in.

## 9.5 Receiving

- Receipt is the only path here that writes stock, and it stamps the source link.
- **Goods are received into the mall that requested them** — guarded in the service, not only the
  form.
- A line already carrying a receipt is skipped.
- A **service** line produces no stock movement, which is why the item link is nullable.

---

# Part 10 — GRNI — the account that proves you paid once

## 10.1 The three-step story

```
Receipt                Dr Inventory  / Cr GRNI     "we have the goods, not yet the invoice"
Bill for those goods   Dr GRNI       / Cr AP       the invoice arrives — GRNI nets to zero
Consumption            Dr Expense    / Cr Inventory the cost hits the P&L when it is USED
```

GRNI (*Goods Received Not Invoiced*) is a dedicated clearing liability, deliberately **not** the AP
control — so the AP tie-out stays honest, because a receipt has no supplier bill behind it yet.

## 10.2 Why this is not academic

Before the clearing existed, buying 500 EGP of stock **once** left:

| Account | Movement |
|---|---|
| Inventory | +500 |
| **Expense** | **+500** |
| GRNI | −500 |
| **AP** | **−500** |

The same money recognised **twice** — once as an asset and once in the P&L — and the liability
recorded twice. **Every stock purchase whose supplier bill was entered overstated both the P&L and
the balance sheet by its full value.**

## 10.3 Three rules that keep the clearing honest

1. **Only received, stockable lines clear.** A service line never touched stock; an unreceived line
   has posted no GRNI credit yet.
2. **Never clear more than the receipt credited.** A bill larger than the goods — freight, a price
   rise — must not manufacture a GRNI debit no receipt ever credited. The excess is an expense.
3. **A bill with no purchase behind it is unchanged** — all of net is expense. That is most bills.

## 10.4 The three-way match, on the bill form

Ordered · received into stock · billed **including this one** — with the overrun stated in red.

This exists because **billing twice what was ordered looked like an ordinary bill**: a purchase worth
5,000, received, then a supplier bill for **10,000** linked to it — accepted without a murmur.

**And it is deliberately not a refusal.** The legitimate case (the bill also covers labour or
delivery) and the duplicate-billing case post *identically*; the only difference is whether a human
meant it. Blocking would break the case the clearing was built for.

The variance sums **every postable bill**, because the case that hides is a **split delivery**: two
bills of 3,000 against a 5,000 purchase each look fine alone.

## 10.5 One thing still open — know it

The **ad-hoc receipt action** on stock movements writes a movement with free text and **no source
link**, so a purchase received that way is unlinkable and its GRNI never clears. It has legitimate
non-purchase uses (opening balances, found stock), so it was not removed.

Measured on the demo books: **166,120 EGP of GRNI credits, zero debits, 0 of 12 receipts carrying a
source link.**

> **Route purchases through a purchase request.** That is the operator-side fix.

---

# Part 11 — Custody — العهدة

Cash advanced to a **named person** who spends it and settles back with receipts. It is an **asset**
while they hold it — money in a custodian's hands — not an expense and not a receivable.

| Event | Books |
|---|---|
| **Grant** | Dr `custodies` / Cr Cash or Bank |
| **Expense settlement** | Dr the **category's** expense account / Cr `custodies` |
| **Cash return** | Dr Cash or Bank / Cr `custodies` |

`Outstanding = amount − Σ settlements`, **derived, never stored**.

## 11.1 The rules

1. **No grant to a terminated employee**, and no non-positive amount.
2. **A settlement cannot exceed outstanding** — re-checked under a lock, so two concurrent
   settlements cannot over-spend.
3. **Grant terms lock once settled** — amount, date and paid-from become read-only once anything has
   been settled against it. Lowering the amount below what is already spent makes outstanding
   **negative**: the register showing a custodian owing money never granted to them.
4. **"Once settled", not "on grant".** A عهدة keyed wrongly stays fixable until it is spent against.
   `purpose` and `reference` carry no money and stay editable.
5. **The custodian is fixed at grant.** The property dimension is copied from the employee, so moving
   them later would otherwise land a settled عهدة's entries in another mall.

---

# Part 12 — Payroll, briefly

| Books | |
|---|---|
| Dr | `salaries_expense` — **gross** |
| Cr | `salary_tax_payable` + `social_insurance_payable` — withheld |
| Cr | `bank` / `cash` — **net** |

Two things an AP person needs to know:

1. **The statutory numbers are a dated ladder**, resolved for the **run's own period month** — never
   for today. Reading undated settings meant a January run generated in March computed on March's
   numbers, a rise could not be entered in advance, and nothing recorded what a past run used.
2. **Two different bases, not interchangeable.** Salary tax is on the whole gross; social insurance is
   on the gross **clamped into the insurable band** — and the cap binds the **employer** share too,
   because the cap is on the wage.

Rates ship at **0**: a migration that started withholding from every salary would be the software
deciding to deduct money from people.

---

# Part 13 — The SLA penalty — the signature move

When a contractor misses a service level, the penalty is **charged straight onto their bill**. No
Western benchmark does this end to end.

## 13.1 How it behaves

| Rule | Detail |
|---|---|
| The basis is **configuration** | `vendor_contracts.sla_penalty_basis` + rate — flat fee, per-day accrual, or a share of the job's value. `none` by default |
| **One penalty row per work order** | Each scan **updates** it; the amount freezes when the job reaches a terminal state, so re-running the scan is free |
| Lateness stops at **completion**, not at "now" | Otherwise a closed job's overrun keeps growing in the archive and bills more every day |
| Part of a day counts as a **whole** day | Charging 0.4 of a day for a nine-hour overrun invites an argument nobody wants |
| **Only `applied` is deducted** | `final` is assessed-and-owed, not yet taken off the bill |
| It may **never exceed** what the bill still owes | AP would go negative — a receivable wearing a payable's clothes. Split it across bills instead |

## 13.2 The accounting, and the two things it is not

**Dr Accounts Payable / Cr the same expense the bill debited.**

- It is a **cost reduction, not income** — money from a supplier adjusts the price paid to them
  unless it buys something distinct. The penalty follows the cost.
- **No VAT line** — liquidated damages are compensation, not a supply.
- It is an **AP offset, not a line on the bill**: `recompute()` folds it in exactly as a credit note
  folds into an invoice.

## 13.3 ⚠️ CAM does not follow automatically

`CamExpensePool.total_actual_expense` is **typed in by an operator**, not derived from the GL. So
whoever records the year's actual CAM spend must use the figure **net of penalties** — otherwise
tenants over-pay CAM while the operator keeps the penalty.

**This is an operator rule, not a code rule. Write it on the CAM checklist.**

## 13.4 A work order is a cost object, never a GL source

A job's cost is the sum of three channels — labour hours × the craft rate (frozen at entry), part
draws, and vendor bills or expenses carrying the job's id. **Net of VAT and net of an applied
penalty**, cancelled documents excluded.

The money is **already** in the ledger through those documents. Registering the work order as a
posting source would post every maintenance cost **twice** — and balance both times. So a report may
never *add* work-order labour to the wage bill; it **explains part of it**.

---

# Part 14 — What each act does to the books

| Act | Debit (مدين) | Credit (دائن) |
|---|---|---|
| **Vendor bill** (approved) | The category's own account (net) + `vat_recoverable` | `accounts_payable` (total) |
| **Vendor bill for received goods** | **`GRNI`** up to the received value, expense for the excess | `accounts_payable` |
| **Pay a vendor** | `accounts_payable` (gross) | `bank` (net) + `withholding_tax_payable` |
| **Void that payment** | `bank` (net) + `withholding_tax_payable` | `accounts_payable` |
| **Direct / petty-cash expense** | The category's account (net) + `vat_recoverable` | `cash` / `bank` |
| **Goods receipt** | `inventory` | **`GRNI`** |
| **Stock consumption** | `maintenance_expense` | `inventory` |
| **Stock adjustment** | `inventory` (found) / `inventory_adjustment` (shrinkage) | the other side |
| **Custody grant** | `custodies` | `cash` / `bank` |
| **Custody expense settlement** | the category's account | `custodies` |
| **Custody return** | `cash` / `bank` | `custodies` |
| **Payroll run** | `salaries_expense` (gross) | tax + insurance payable + `bank` (net) |
| **Depreciation** | `depreciation_expense` | `accumulated_depreciation` |
| **Applied SLA penalty** | `accounts_payable` | the **same expense** the bill debited |
| **Marketing spend** | `marketing_expense` | `cash` / `bank` per `paid_from` |

**Which chart account cash moved through** is one question asked in three parts, in this order:

1. the document's own `bank_account_id`,
2. else the **rail's** account,
3. else the posting role — `cash` for cash, `bank` otherwise.

That matters because bank reconciliation finds candidates **by account**: a mall banking in two
places that put both banks' money in one account will be offered the *other* bank's postings when
reconciling the first. A wrong match marks money verified, which is worse than not reconciling.

---

# Part 15 — The statutory filings

| Screen | What it is | Period |
|---|---|---|
| `/admin/vat-return` | Output VAT against input VAT | Per registration, not per mall |
| `/admin/withholding-tax-return` | **Form 41** per supplier | **Quarterly, off the FISCAL year's start** |
| Withholding **certificate** PDF | What a supplier hands their own accountant | Per supplier |
| `/admin/tax-depreciation` | The tax view of the fixed-asset register | — |

Four decisions inside the withholding return worth knowing:

1. **Quarters come off the fiscal year, not the calendar.** An April→March mall year is ordinary in
   Egypt.
2. **Filed per registration, not per property.** One Form 41 covers the portfolio.
3. **The per-supplier rate is DERIVED — withheld ÷ base**, never re-resolved from today's catalogue.
   Several payments in one quarter can carry different rates, and a rate revised now must not rewrite
   a past quarter.
4. **The tie-out is the point of the screen.** What the payments say was withheld, against the credit
   movement on the withholding liability — two independent reads that must agree before a number
   becomes a filing position somebody signs.

**It files nothing and reproduces none of the ETA's boxes.** Those are the accountant's, and a screen
imitating them would be a document that looks official and is not.

---

# Part 16 — "I clicked and nothing happened"

| What you see | What it means | What to do |
|---|---|---|
| Approve is refused | The bill date's period is **closed** | Re-date the draft into an open period, or set a post month |
| *"already billed"* on a supplier reference | (vendor, reference) already exists | Check for a duplicate. Cancel the wrong one, then re-enter |
| Cancel a bill is refused | Cash has been paid against it | Void the payment first |
| The payment amount will not save | It exceeds the remaining balance | Check what a penalty has already offset |
| A payment's fields are locked | Correct — the cash has left the bank | **Void** it and re-record |
| Order is not offered | The request is not `approved` | There is no requested → ordered edge, by design |
| You cannot approve a request | It is yours, or you lack `procurement.decide`, or you are below the tier | Ask someone else — that is the control |
| A line cannot be edited | The request is past `requested` | The approval is settled. Raise a new request |
| GRNI never clears | The receipt carried no source link | Route purchases through a purchase request |
| A cost booked to `admin_expense` unexpectedly | Its category has no ledger account | Point the category at an account. It is a row |
| The custody amount is locked | Something has been settled against it | Correct via a settlement or a return |
| Withholding looks 14% too high | The base included VAT | Should be fixed — but check the bill's VAT split if a figure looks off |
| A vendor cannot be dispatched | Blacklisted, inactive, or a blocking document has lapsed | Renew the certificate, or blacklist/unblacklist deliberately |
| A date is refused | Its accounting period is **closed** | Re-date, or set a post month |

---

# Part 17 — The rhythm

## Daily (automatic)

`expenses:generate-recurring` · `vendors:scan-document-expiry` · `vendors:expire-contracts` ·
`vendors:scan-contract-renewals` · `facility:scan-sla-breaches` · `inventory:scan-low-stock` ·
`accounting:sync-ledger` · `accounting:post-depreciation`

## Weekly

- Review drafted purchase requests from the low-stock scan — **submit or cancel**, do not leave them.
- Review drafted vendor bills from recurring schedules — fill in the supplier's reference.
- `billing:reconcile --deep` runs; read what it names.

## Monthly

| Order | Do |
|---|---|
| 1 | Enter and approve every supplier bill for the month **before** you close |
| 2 | Record bill payments; check the withheld figures |
| 3 | Settle or return outstanding custodies |
| 4 | Run payroll |
| 5 | Check the **three-way match** on anything linked to a purchase |
| 6 | Open `/admin/month-end-close` — *vendor bills posted* is one of its seven rows |
| 7 | Close the period |

## Quarterly

Form 41 — reconcile the withholding tie-out, then issue the certificates.

## Before any deploy

```
php artisan atriom:preflight
```

---

# Part 18 — The eleven rules

1. **Naming a vendor makes it a payable.** No vendor = a direct expense. There is no third answer.
2. **`reference` is THEIR invoice number.** Never invent one to get past the duplicate guard.
3. **Never edit a recorded payment.** Void it and re-record — the void keeps both facts.
4. **Withholding is charged on the NET**, never on the total.
5. **A cost belongs to the month it was INCURRED.** Use a post month, never a re-date into an open
   period you did not mean.
6. **Route purchases through a purchase request**, or GRNI never clears.
7. **An approved request's lines are settled.** Raise a new one instead of editing.
8. **You cannot approve your own purchase**, and the base right matters as much as the tier.
9. **Point an expense category at an account.** Everything unpointed silently lands in
   `admin_expense`.
10. **Record CAM actuals NET of penalties.** Nothing does this for you.
11. **A work order is a cost object, never a posting source.** Do not add its labour to the wage bill.

---

## Related

- [`../modules/12-vendors.md`](../modules/12-vendors.md) · [`29`](../modules/29-procurement.md) · [`28`](../modules/28-approvals.md) · [`25`](../modules/25-treasury-custody.md) · [`22`](../modules/22-inventory.md) · [`23`](../modules/23-fixed-assets.md) · [`24`](../modules/24-hr-employees.md) · [`26`](../modules/26-facility.md)
- [`../accounting/EGYPTIAN-TAX-CATALOG.md`](../accounting/EGYPTIAN-TAX-CATALOG.md) — the tax sheet the catalogue must match
- [RECEIVABLES-WALKTHROUGH.md](RECEIVABLES-WALKTHROUGH.md) — the other direction
- [OPERATOR-PLAYBOOK.md](OPERATOR-PLAYBOOK.md) — the cross-module traps and the rhythm
