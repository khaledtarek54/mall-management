# Leasing — a walkthrough for someone new

**Who this is for.** Someone joining the team who has never worked in property, retail leasing or
accounting, and who has to be able to open Atriom and understand what the leasing screens are doing
and why. It assumes **no** business background. It assumes you can click.

**Who this is NOT for.** It is not the developer's document — that is
[`docs/modules/04-leases.md`](../modules/04-leases.md), 1,600 lines of invariants, past bugs and "do
not break this". This walkthrough does not repeat it; where a rule needs proving it links there.

**How to use it.** Read Parts 0–2 once (twenty minutes). Then keep Part 3 open beside the screen
while you work through the exercises in Part 9. Everything else is reference you come back to.

---

## Table of contents

| Part | What it answers |
|---|---|
| [0](#part-0--what-business-are-we-actually-in) | What business are we actually in? |
| [1](#part-1--the-vocabulary) | The vocabulary — 30 words you must own |
| [2](#part-2--where-leasing-sits) | Where leasing sits: what feeds it, what it feeds |
| [3](#part-3--the-lease-form-field-by-field) | The lease form, field by field — 6 tabs, ~45 fields |
| [4](#part-4--the-13-tabs-on-a-saved-lease) | The 13 tabs on a saved lease |
| [5](#part-5--every-button-on-a-lease) | Every button on a lease — all 13 actions |
| [6](#part-6--the-leases-list) | The leases list: tabs, filters, columns, import, wizard |
| [7](#part-7--what-happens-on-its-own-at-night) | What the system does on its own, at night |
| [8](#part-8--the-lifecycle) | The lifecycle: 7 states and the rules between them |
| [9](#part-9--the-guided-workflow) | The guided workflow — 16 hands-on exercises |
| [10](#part-10--i-clicked-and-nothing-happened) | "I clicked and nothing happened" — refusals decoded |
| [11](#part-11--the-nine-rules-you-must-never-break) | The nine rules you must never break |

---

# Part 0 — What business are we actually in?

## 0.1 Three parties, and none of them is "the customer"

There are three kinds of people in this system, and mixing them up is the most common mistake a
newcomer makes.

| Who | In the system | In plain words |
|---|---|---|
| **The owner** — Jawad | `Asset` (the mall), owner statements | Owns the building. Put up the money. Wants to know what his property earned this month and when he gets paid. He does not run anything. |
| **The operator** — Eltizam | `User` + roles (`manager`, `leasing`, `accounting`, `hr`…) | **This is us.** We run the mall on the owner's behalf — sign the tenants, collect the money, fix the lifts, pay the electricity. Everyone who logs into `/admin` is an operator. |
| **The tenant** — the retailer | `Tenant`, `TenantUser`, `/portal` | The shop. Zara, a coffee kiosk, a bank ATM. They pay us; we hand the money (minus what we spent) to the owner. |

So when the code says *tenant*, it never means "the person renting an apartment". It means **the
brand trading out of a shop in the mall.**

## 0.2 What a mall actually sells

A mall does not sell products. It sells **space, for time, for money.** That sentence is the whole
module:

- **space** → a `Unit` (shop A-12, 200 m²)
- **for time** → a term (3 years, from 1 January 2026)
- **for money** → rent, plus a list of other charges

The document that ties those three together is the **lease**. Everything else in this module exists
to answer questions about that document.

## 0.3 The five ways a mall makes money from one shop

A newcomer assumes "rent" is the whole story. It is usually 60–70% of it. A single shop can generate
five separate money streams, and Atriom bills all five off the same lease:

| # | Stream | In the system | Plain words |
|---|---|---|---|
| 1 | **Base rent** | charge type `base_rent` | The rent. A fixed monthly figure agreed in the contract. **VAT-exempt** in the catalogue we ship. |
| 2 | **Service charge** | charge type `service_charge` | The tenant's contribution to running the shared areas — cleaning, security, air-conditioning the corridors. **Carries VAT at 14%.** |
| 3 | **Marketing levy** | charge type `marketing`, 5% of base rent | Every tenant chips in to the mall's advertising fund. Derived automatically from the rent, not typed. VAT-exempt. |
| 4 | **Percentage rent** | `has_percentage_rent` + sales declarations | *"You pay us 8% of everything you sell above EGP 1,000,000 a month."* The landlord shares the tenant's upside. Billed only after the tenant declares their sales. |
| 5 | **Recoveries & extras** | CAM true-up, utilities, parking bays, signage, fines | Money the landlord spent and gets back, plus space beyond the shop itself. |

**Why this matters for the software:** each stream has its own timing, its own VAT treatment and its
own screen. A lease is not one number. It is a **schedule of several numbers, each with dates.**

## 0.4 The single most important idea in this module

> **The lease does not bill anything. The charge schedule does.**

The lease record holds the *deal* — who, where, how long, what was agreed. But when the billing run
fires, it does **not** read the lease's rent field. It reads the **charge schedule**: dated rows
saying *"base rent, EGP 60,000 a month, from 1 Jan 2026 to 31 Dec 2026"*, then *"base rent, EGP
64,200 a month, from 1 Jan 2027 to …"*.

Two consequences that will confuse you at least once:

1. **A lease with no charge rows bills nothing**, however neatly the rent field is filled in. This
   actually happened: an importer created leases with no schedule and they billed zero for months.
   There is now a command that hunts for exactly that — `php artisan atriom:audit-charge-schedules`.
2. **Rent is never overwritten.** When rent goes up, the system *closes* the old row the day before
   and *opens* a new one. So the answer to *"what was the rent in March 2026?"* stays true for ever,
   and re-running March's billing produces March's number — not today's.

If you remember one paragraph from this document, remember that one.

## 0.5 Why the system refuses things

Atriom refuses a lot. It will not let you delete an invoice. It will not let you type "terminated"
into the status box. It will not let you edit the rent on the lease form. **None of these are bugs.**

The reasoning is always the same: a lease is not data, it is **evidence**. Somebody signed it. An
auditor may read it. A tenant may dispute it. So the system prefers an **act with a record** over a
**field you can edit**:

- You don't type a new rent — you run **Change rent**, which asks for the effective date and the
  reason, closes one schedule row and opens another, and writes a line in the lease history.
- You don't type "terminated" — you run **Terminate**, which stops the charges, credits back money
  billed for time the tenant will not be there, and settles the deposit.

Every time you think *"why can't I just edit this?"* — the answer is that editing it would leave the
books saying something nobody can explain later.

---

# Part 1 — The vocabulary

Thirty words. The Arabic column is what the operators actually say out loud, not a dictionary
translation.

## Space and parties

| Term | العربي | In the system | What it actually means |
|---|---|---|---|
| **Property / Asset** | المول | `Asset`, code `AW` | One mall. Everything in Atriom belongs to exactly one property, and you can only see one at a time — see the **property switcher** below. |
| **Unit** | وحدة / محل | `Unit`, code `A-12` | One rentable shop. Has an area in m², a floor, and a status (vacant / occupied / reserved / under maintenance). |
| **GLA** | المساحة المؤجرة | `units.area_sqm` | *Gross Leasable Area* — the m² you can actually let. The mall's total GLA is the denominator of every occupancy figure and of every CAM share. |
| **Tenant** | المستأجر | `Tenant` | The retail brand. Not a person renting a flat — a company trading from a shop. |
| **Portal** | بوابة المستأجر | `/portal`, `TenantUser` | The tenant's own login: their invoices, their statement, their requests. They see far less than you do. |
| **Unit owner** | مالك وحدة | `UnitOwnership` | An investor who *bought* a unit rather than renting it. They pay a monthly assessment (صيانة) instead of rent, and they may let their own unit to a retailer. |

## The contract

| Term | العربي | In the system | What it actually means |
|---|---|---|---|
| **Lease** | عقد إيجار | `Lease`, ref `LSE-AW-2026-0001` | The contract. One tenant, one *or more* units, one term, one set of money terms. |
| **Master unit** | الوحدة الرئيسية | `leases.unit_id` | If a lease covers three shops, one is the "master". It is the lease's identity and **can never be changed** — moving out of it is a *relocation*, a different act entirely. |
| **Commencement date** | تاريخ بدء العقد | `commencement_date` | The day the **term** starts. Not necessarily the day rent starts. |
| **Rent commencement** | تاريخ بدء الإيجار | `rent_commencement_date` | The day **rent** starts. Usually later — the tenant gets free months to build the shop. |
| **Fit-out** | تشطيب المحل | `fit_out_scope` | The period where the tenant is building the shop and not trading. Normally rent-free; service charge usually still payable. |
| **Term** | مدة العقد | `term_months` | How many months the contract runs. Derived from the dates, and derives them back. |
| **Holdover** | امتداد بعد انتهاء المدة | `holdover_from`, `holdover_rate_pct` | The term ended and the tenant is still in the shop. Priced at a **penalty** — 150% of the old rent by default — to push them to sign or leave. |
| **Renewal** | تجديد | `previous_lease_id` | A **new lease** replacing the old one, with its own reference and its own negotiated terms. |
| **Extension** | مد المدة | `LeaseExtensionService` | The **same** contract running longer on the same terms. Different from a renewal, and the system keeps them apart deliberately. |
| **Option** | حق تعاقدي (خيار) | `LeaseOption` | A right the contract gives the tenant — to renew, to expand, to walk away — exercisable only inside a **notice window**. |
| **Notice window** | فترة الإخطار | `earliest_notice_date`, `latest_notice_date` | *"Notice no earlier than 12 and no later than 9 months before expiry."* Miss the window and the right is gone. |
| **Clause** | بند تعاقدي | `LeaseClause` | A legal term that is not money — exclusivity, radius restriction, co-tenancy, kick-out, guarantor. Abstracted from the signed PDF so the system can report on it. |

## The money

| Term | العربي | In the system | What it actually means |
|---|---|---|---|
| **Charge** | بند تحصيل | `Charge` row | One dated line of the schedule: *this type, this amount, from this date to that date.* The thing that actually bills. |
| **Escalation** | الزيادة السنوية | `escalation_type`, `escalation_rate` | The automatic rent increase written into the contract. Applied by a nightly job, not by a person. |
| **Collar** | الحد الأدنى/الأقصى للزيادة | `escalation_floor_rate` / `escalation_ceiling_rate` | Floor and ceiling on that increase — *"the greater of inflation or 3%, capped at 10%"*. In Egypt, where CPI has run 20–35%, no tenant signs an uncapped inflation clause. |
| **Proration** | تقسيط الشهر الناقص | `proration_method` | How a part-month is priced when a lease starts or ends mid-month. Four different ways; the contract says which. |
| **Billing frequency** | دورية الفوترة | `billing_frequency` | Monthly, quarterly, semi-annual or annual. Non-monthly leases pay **in advance** — one invoice covering the whole cycle. |
| **Percentage rent** | إيجار نسبة من المبيعات | `has_percentage_rent` | Extra rent based on what the shop sold. Requires the tenant to declare sales each month. |
| **Breakpoint** | حد المبيعات | `percentage_rent_threshold` | The sales level above which percentage rent starts. *Artificial* = a figure you agree. *Natural* = derived from the base rent itself. |
| **Security deposit** | التأمين | `security_deposit` | Money held against damage and arrears. It is a **liability** — it is the tenant's money that we hold, not income. Given back (or netted off) at move-out. |
| **CAM** | مصاريف الخدمات المشتركة | `CamExpensePool`, `LeaseCamTerm` | *Common Area Maintenance.* The landlord spends real money on shared areas all year, then at year-end divides it across tenants by area and bills the difference against what they already paid in service charge. |
| **Relief / abatement** | إعفاء مؤقت | `LeaseReliefService` | A temporary discount for a window of months. It reverts by itself. Not a rent change. |
| **VAT** | ضريبة القيمة المضافة | `tax_codes`, `Vat` | 14% in Egypt today. **Rent is exempt; service charge is not.** The charge code decides, not you. |
| **Straight-line rent** | توزيع الإيجار بالتساوي | `StraightLineRentAdjustment` | An accounting rule: the *books* recognise the average rent across the whole term even though the lease *bills* a rising one. Off by default. |

## The plumbing

| Term | العربي | In the system | What it actually means |
|---|---|---|---|
| **Property switcher** | مبدّل المول | top of the panel | Which mall you are looking at. **Everything is filtered by it.** If a list is empty or a dropdown offers nothing, check this first. |
| **Invoice** | فاتورة | `Invoice` | A bill. `draft` = a working document nobody outside can see. `issued` = a real claim, in the ledger, visible to the tenant. |
| **Journal entry** | قيد يومية | `JournalEntry` | The double-entry accounting record behind a money document. Every issued invoice writes one. |
| **Accounting period** | فترة محاسبية | `AccountingPeriod` | A month the books are open for. **Closed** means no document may be dated into it — the system refuses, rather than posting silently. |

---

# Part 2 — Where leasing sits

## 2.1 The flow, end to end

```
    Property (mall)
        │
        ├── Floor ── Unit  ◄────────── "the space"
        │             │
   Tenant ────────► LEASE ◄────────── "the deal"
                      │
                      ▼
            CHARGE SCHEDULE            ◄── dated rows: what bills, and when
                      │
                      ▼
          MONTHLY BILLING RUN          ◄── the 1st of the month, automatically
                      │
                      ▼
                  INVOICE  ── issued ──►  General Ledger (Dr Receivables / Cr Revenue / Cr VAT)
                      │                            │
                      ▼                            ▼
                  PAYMENT                   OWNER STATEMENT  ──►  Jawad gets paid
```

Read it top to bottom once. Every screen in the leasing module is somewhere on that line.

## 2.2 What must exist BEFORE you can write a lease

A newcomer's first attempt usually fails on a missing prerequisite. Check these in order:

| # | Prerequisite | Where | What happens without it |
|---|---|---|---|
| 1 | **A property selected** in the switcher | top of the panel | Dropdowns come back empty and you conclude the data is missing. It isn't — you are looking at no mall. |
| 2 | **A vacant unit** | Properties → Units | The unit picker hides occupied units, so a fully-let mall shows an empty list. |
| 3 | **A tenant record** | Leasing → Tenants | You can create one inline from the lease form, but a real tenant has a tax ID and a commercial register you will want later. |
| 4 | **Charge codes seeded** | Setup → Charge codes | The charge schedule cannot name a charge type that does not exist. `atriom:install` seeds them. |
| 5 | **An open accounting period** | Accounting → Periods | The lease will save, but its first invoice will refuse to post. |

Run `php artisan atriom:config-health` to be told which of these an install is missing.

## 2.3 What a lease touches the moment you save it

| The moment you… | What moves |
|---|---|
| Save a **draft** lease | Its units go **reserved** (held, not billing) |
| Set it **active** | Its units go **occupied**; they leave the "available to let" list; occupancy % and the rent roll move |
| Save it at all | The charge schedule is seeded (rent + service charge), the marketing levy is derived, and the whole escalation ladder is projected across the term |
| **Terminate / expire / cancel** it | Its units go **vacant** again, unless another live lease holds them |

## 2.4 The modules downstream of a lease

| Module | What it takes from the lease |
|---|---|
| **Billing & invoices** (05) | The charge schedule, the billing frequency, the payment terms, the proration method |
| **Payments** (06) | Which tenant owes, and which invoices a receipt settles |
| **CAM** (08) | The area held, month by month, and any per-lease cap |
| **Sales & percentage rent** (09) | The clause: breakpoint, rate, exclusions, deductions |
| **Owner statements** (32) | The revenue this tenancy produced for that property |

## 2.5 The property switcher, and why "it's missing" usually isn't

Every query in Atriom is filtered to the property you have selected. This is not a permission you
can be granted around — it is the shape of the query. So:

- A lease you created in Mall A is **invisible** in Mall B. Correct behaviour.
- An empty dropdown almost always means *"nothing in the mall you are looking at"*, not *"nothing
  exists"*.
- A number that looks wrong is often a number for a different mall.

**Check the switcher before reporting a bug.** This is rule 9 in Part 11 for a reason.

---

# Part 3 — The lease form, field by field

The lease form is **six tabs**, not one long page — thirty-odd fields on one screen is a scroll
rather than a form. Each tab carries a small **red badge counting the validation errors inside it**
when a save is refused; without it a required field left blank on a tab you are not looking at would
refuse the form with nothing visible to fix.

**Reading this part:** every field gets **What it is · What to type · What it changes · When it
locks**. "Locks" matters — many fields go read-only once the lease has been invoiced, because from
that moment they are what those invoices were derived from.

---

## Tab 1 — Lease Details

*Who is renting what.*

### `reference` — Lease reference
- **What it is.** The contract's document number: `LSE-AW-2026-0001` = *lease · Atriom Walk · 2026 ·
  the first one this year.*
- **What to type.** Nothing. Greyed out, allocated by the system when you save.
- **What it changes.** This is how the lease is quoted everywhere else — on invoices, in the charge
  importer (which is keyed by lease reference), in search.
- **Note.** An imported lease **keeps the reference the operator already used**. A migrating client's
  accountant has those numbers in their own files.

### `unit_id` — Unit (the master unit)
- **What it is.** The shop being let. If the lease covers several shops, this is the main one.
- **What to type.** Pick from the dropdown. It **opens showing the first 50 available units** — you
  do not have to type to see options — and searching reaches every unit beyond those, by code, by
  floor, and by the tenant currently in it.
- **What it changes.** Almost everything: the lease's **property** (a lease belongs to the mall its
  master unit is in), the **area** used for rate-priced rent and for CAM, and the unit's **status**.
- **What it refuses.** A unit that already has an active lease. You cannot let the same shop twice —
  the form refuses it, and the service refuses it again under a database row lock, so two people
  clicking Save in the same second cannot both win. If the tenant is expanding into the shop next
  door, that is **not** a second lease; it is an **additional unit** on the existing one.
- **When it locks.** On edit, permanently. The master unit is the lease's identity.

### `show_occupied_units` — Show occupied units (toggle)
- **What it is.** By default the unit dropdown hides shops already let or reserved. This widens the
  list to show them.
- **When you would use it.** Recording a historic lease, or scheduling a renewal that overlaps the
  current lease.
- **Careful.** Widening the *list* does not disable the *rule*. The form still refuses a genuinely
  double-booked unit at save. (For a while the rule behind this toggle was broken and the toggle was
  the only thing that *looked* like a guard. It isn't one.)

### `unit_ownership_id` — Unit ownership *(appears only sometimes)*
- **What it is.** Some units in the mall are **sold, not rented**. When that owner lets *their own*
  shop to a retailer, the lease sits **under the ownership**.
- **When it appears.** Only when the unit you picked has a handed-over ownership record.
- **What it changes.** The owner stays liable for the monthly assessment (صيانة); the retailer is a
  real occupant for access, fit-out, violations and SLA. *Owner of record is not occupant of record.*
- **When it locks.** On edit.

### `additional_unit_ids` — Additional units
- **What it is.** The other shops on the same contract. One lease, three shops.
- **What it changes.** Their status flips to occupied too, and their area is added to the lease's
  total — which matters if the rent is priced per m², and matters for CAM every year.
- **⚠️ The one real trap in this form.** On **edit** this field is **read-only**, deliberately. It
  used to be editable, and removing a unit here *deleted the row holding the dates the tenant
  actually occupied that shop* — silently restating a CAM reconciliation that may already have been
  signed. Two clicks, no warning. To change the premises you use the **Change premises** action,
  which *closes* the occupancy period instead of erasing it.

### `tenant_id` — Tenant
- **What it is.** The retailer. The party who owes the money.
- **What to type.** Pick, or create one inline (name, phone, email) if the brand is new.
- **What it changes.** Every invoice, statement and portal login for this lease.
- **When it locks.** On edit. Re-pointing the tenant would hand one retailer's billing history and
  deposit to another under the same contract reference. Handing a lease to a different company is an
  **assignment** — a separate commercial act, not a dropdown change.

### `status` — Status
- **What it is.** Where the lease is in its life.
- **What you may pick:** `draft` (default), `pending_approval`, `active`, `expired`, `cancelled`.
- **What you may NOT pick:** `terminated` and `renewed` are not offered. They are **outcomes of an
  action** — terminating deactivates the charge schedule, credits unearned billing, cancels open
  invoices and settles the deposit. Typing the word would record the word and skip every one of
  those acts.
- **What it changes.** `active` → unit **occupied**, lease **bills**. `draft` / `pending_approval` /
  `renewed` → unit **reserved**. `expired` / `terminated` / `cancelled` → unit **vacant**.

---

## Tab 2 — Term

*How long. Three fields that derive from each other, so you can enter the deal whichever way it was
written.*

### `commencement_date` — Commencement date
- **What it is.** Day one of the term.
- **What it changes.** Typing it (or changing the term) **recomputes the expiry date**. It also
  anchors the escalation anniversary, the billing cycle for a quarterly lease, and the earliest month
  that can be billed.
- **When it locks.** Once the lease has been invoiced.

### `term_months` — Term (months)
- **What it is.** The contract length, 1–120 months. The default comes from your accounting settings
  (typically 36). Typical Egyptian mall leases run 12, 24 or 36 months.
- **What it changes.** Changing it recomputes the expiry date.
- **The rule, stated once:** `expiry = commencement + term − 1 day`, with month-ends **clamped**. A
  lease commencing **31 August** for six months expires **27 February** — not 2 March. (Ordinary
  month arithmetic overflows, which once put leases three days outside their agreed term.)
- **When it locks.** Once invoiced.

### `expiry_date` — Expiry date
- **What it is.** The last day of the term, **inclusive**.
- **What it changes.** Typing an expiry recomputes the **term** instead — a deal is sometimes
  negotiated to a date (aligned to a financial year, or to another tenant's fit-out) rather than to a
  round number of months. Where the range is not a whole number of months, the term records the whole
  months the range covers rather than rounding the date to something tidier.
- **What it refuses.** An expiry on or before the commencement — on the form, in the importer, and on
  a programmatic call.
- **⚠️ The thing newcomers get wrong.** Typing a later date here does **not** extend the lease. It
  edits a field. Extending a term is an **act**: the **Extend term** action, which records who
  extended it and why, re-projects the future rent ladder, and refuses to pull an expiry
  *backwards* — ending a tenancy early is a *termination*, which settles money; this does not.

---

## Tab 3 — Financial Terms

*The heart of it. Twenty-two fields, in five groups.*

### Group A — How the rent is priced

#### `rent_pricing_basis` — Pricing basis (radio: flat / rate)
- **What it is.** Two ways to state a rent. **A flat monthly amount** (*"EGP 60,000 a month"*) or **a
  rate per m² per year** (*"EGP 4,800 per m² per year"*).
- **Why it matters.** Commercial rent is negotiated per m² almost everywhere, and a rate lets two
  deals be compared on the only basis that makes them comparable. It also means the rent
  **re-derives itself** when the let area changes — take an extra unit and the rent follows, with
  nobody doing the arithmetic by hand.
- **When it locks.** On edit. Changing how a signed deal is priced is a different contract.

#### `base_rent_rate_per_sqm_year` — Rate (EGP/m²/year) *(only when basis = rate)*
- **What to type.** The contracted rate. The helper text underneath tells you the **let area right
  now**, so you can see what it is about to be multiplied by.
- **The arithmetic:** `monthly rent = area × rate ÷ 12`. 200 m² at 4,800 → 200 × 4,800 ÷ 12 =
  **80,000 a month**.

#### `base_rent_monthly` — Monthly rent
- **What it is.** The rent that will be billed every month, before VAT.
- **When it is read-only.** (a) On a **rate-priced** lease — it is derived, and two editable fields
  deriving from each other is exactly how they end up disagreeing. (b) On **edit**, always — rent
  changes go through the **Change rent** action so the schedule row and the levy stay in step.
- **VAT.** None. Base rent is exempt in the catalogue we ship.

#### `service_charge_monthly` — Service charge
- **What it is.** The tenant's monthly contribution to running the shared areas — security,
  cleaning, common-area HVAC.
- **VAT.** 14%. This is the line that carries tax on a typical invoice.
- **When it locks.** On edit — same reason as the rent.

#### `has_marketing_levy` — Marketing levy (toggle, default ON)
- **What it is.** Whether this tenant pays into the mall's advertising fund.
- **What it does.** Adds a `marketing` charge to the schedule, **derived as a % of base rent** — you
  never type its amount. When rent changes, it re-derives itself.
- **Turn it off** for a tenant who negotiated out of it. Carried forward on renewal.

#### `marketing_levy_rate` — Levy % override *(only when the levy is on)*
- **Blank = the mall default**, which is shown as the placeholder in the box (5%).
- Fill it in only where this lease negotiated a different percentage.

### Group B — Handover and the rent-free period

#### `possession_date` — Possession date
- **What it is.** The day the tenant took the keys and fit-out could begin — routinely **before** the
  term commences, and the date a handover dispute turns on.
- **What it changes.** Nothing in billing. It is recorded because it is the fact people argue about.

#### `rent_commencement_date` — Rent commencement
- **What it is.** The day rent starts. Leave it **blank if there is no rent-free period** — the lease
  then bills from its commencement month.
- **What it refuses.** A date on or before the commencement is treated as no grace, so a mis-key
  cannot pull the first billable month backwards.
- **When it locks.** Once invoiced.

#### `fit_out_scope` — What the grace abates *(appears once a rent-commencement is set)*
- **`Base rent only`** — the market standard, and the default for new leases. The mall is still
  cleaning, securing and cooling the unit while it is being fitted out, so **the service charge and
  the marketing levy keep billing**. This surprises people: during fit-out the tenant still gets an
  invoice, just a smaller one.
- **`Everything`** — rent, service charge, CAM and levy all free. **Nothing bills at all** for those
  months, and the billing run reports the month as skipped with reason `fit_out`.
- **When it locks.** Once invoiced — together with the rent-commencement date, these two decided what
  was abated on invoices already issued.

### Group C — Cadence, deposit and payment terms

#### `billing_frequency` — Billing frequency
- **Monthly** (default) · **Quarterly (every 3 months)** · **Semi-annual (every 6)** · **Annual**.
- **Non-monthly leases pay IN ADVANCE**: one invoice per cycle covering the whole cycle — rent,
  service charge and levy for all N months billed together, on cycle-start months only.
- **Cycles are anchored to the lease's first billable month**, not to the calendar quarter. A lease
  whose first billable month is February bills Feb–Apr, May–Jul, and so on.
- Ask for an invoice in a non-cycle-start month and the system refuses with `off_cycle` and tells you
  the cadence.
- **When it locks.** Once invoiced — switching cadence mid-term could strand a month billed on
  neither the old nor the new cycle.

#### `security_deposit_months` — Deposit (months of rent)
- **What it is.** The **multiple** the deposit was negotiated at — *"three months' rent"*. Defaults
  to your property's setting (3.0).
- **Why it matters.** A deposit stored as a flat figure **erodes**: on a 7% escalation clause, a
  deposit agreed at 3× covers **2.62 months by year three and 2.29 by year five** — the landlord's
  security shrinks by nearly a quarter over a term, silently, exactly as the tenant becomes more
  likely to default. Stating the multiple makes the deposit track the rent through every escalation.
- **Blank = a flat sum** that never moves. That is a real deal too, so blank is respected.

#### `security_deposit` — Deposit amount
- **Read-only once a multiple is stated** — it is derived (3 × 60,000 = 180,000).
- **Cannot be negative.** Refused, not clamped, so a typo is reported rather than hidden.
- **The money itself does not live here.** This is the *contractual* figure. What was actually
  received lives in the deposit register — see the **Deposits** tab and the two deposit actions.

#### `payment_terms_days` — Payment terms
- **What it is.** How many days after issue an invoice is due. Defaults to the property's convention
  (typically 7).
- **Origination only:** the lease carries its own number from then on, so changing the setting later
  cannot move the due date on receivables already raised.

#### `proration_method` — Proration method
How a **part** month is priced. Blank inherits the property's answer, then the portfolio's.

| Option | The arithmetic | When a contract says it |
|---|---|---|
| **Actual days in the month** *(default)* | days ÷ that month's own length | The ordinary case |
| **One thirtieth per day (30/360)** | days ÷ 30 | *"one thirtieth of the monthly rent per day"* |
| **Annual rent ÷ 365 days** | days × 12 ÷ 365 | Annual-rent contracts |
| **Charge the whole month** | 1.0 | *"any part month is payable in full"* |

**A full month is exactly one month under every method** — the divisor only prices the stub.

### Group D — Escalation (the automatic increase)

`escalation_type` is asked **first**, and it is the only field in this group always on screen. Every
other field belongs to exactly one type and appears only when that type is chosen. Choose **none**
and nothing else appears at all.

| Type | What it means | Extra fields it reveals |
|---|---|---|
| **None** | The rent never moves by itself | — |
| **Fixed %** *(default)* | *"Rent rises 7% every year"* | rate, interval, floor, ceiling |
| **Fixed amount** | *"Rent rises by EGP 5,000 a month each year"* — an ordinary anchor-tenant term | amount, interval |
| **CPI** | Pegged to a published inflation index | index code, base value, lag, interval, floor, ceiling |

#### `escalation_rate` — Annual increase % *(fixed %)*
Default 7. Applied on the lease anniversary. **A 0% clause is still a clause** — the system keeps it
and rolls the date once a year, rather than reconsidering the lease every night.

#### `escalation_amount` — Flat monthly step *(fixed amount)*
Used **instead of** the percentage, never alongside it. The collar does not apply to it — a bound
written in percent cannot clamp a step written in pounds.

#### `escalation_interval_months` — How often the step applies
**Blank means every year.** Enter 24 for a biennial clause, 6 for a half-yearly review. The date
rolls by this many months from the last step, so an 18-month clause alternates between two dates in
the year rather than drifting.

#### The CPI trio — `escalation_index_code`, `escalation_index_base_value`, `escalation_index_lag_months`
- **Index code** — which published series this rent follows. You enter the actual figures under
  **Leasing → Rent indices**.
- **Base value** — the index figure this rent was last measured from. It **rolls forward** each time
  the rent steps, so year two measures year-on-year rather than cumulative-since-commencement.
- **Lag (months)** — a statistics agency publishes a month's figure weeks later, so a January step
  cannot read January. *"The September index, effective 1 January"* is a **4**-month lag.
- **The refusals are the point.** No figure published → the lease is left alone and **the anniversary
  does not roll**; the step lands the day the statistic does. No index named, or no base value → the
  lease is skipped. A **falling** index does not cut the rent, and does not move the base.

#### `escalation_floor_rate` / `escalation_ceiling_rate` — The collar *(fixed % and CPI)*
- **Floor** — the increase never falls below this. The *"greater of CPI or 3%"* half of the clause.
- **Ceiling** — the increase is capped here. The *"but no more than 10%"* half — **and a rail against
  a mistyped rate**: a `70` typed for `7` would otherwise step the rent seventy percent on the
  anniversary, unattended, at 3 a.m.
- **A floor above the ceiling is refused**, because the ceiling would silently win and the minimum you
  typed would be the one increase that could never happen.

### Group E — Late fees (all optional, all inherited when blank)

Five per-lease overrides. **Blank means the property's setting, then the portfolio's** — which is
what almost every lease uses. The placeholder in each box shows what it would otherwise inherit, so
you are never guessing.

| Field | Meaning |
|---|---|
| `late_fee_percent` | The fee, as a % of the overdue balance |
| `late_fee_grace_days` | Days after the due date before any fee is charged |
| `late_fee_minimum` | A floor — small balances still cost something |
| `late_fee_maximum` | A ceiling. **0 anywhere in the chain means no cap.** Applied *after* the minimum |
| `late_fee_recurrence_days` | Days before the same invoice may be charged **another** fee. **0 = charge once**, which is what every install did before this existed |

**A late fee never earns a late fee.** The system will not let a penalty compound on a penalty.

---

## Tab 4 — Percentage Rent

*Extra rent based on what the shop sold. Everything here is hidden until the toggle is on.*

#### `has_percentage_rent` — the toggle
Off for most leases. On, and the tenant must declare their sales every month before anything can be
billed.

#### `percentage_rent_calculation_type` — How it is worked out

| Type | The arithmetic | In plain words |
|---|---|---|
| **Artificial** *(default)* | `(sales − threshold) × rate` | The tenant pays base rent **AND**, on top, a share of sales above a separately agreed figure |
| **Natural breakpoint** | `(sales × rate) − base rent`, floored at 0 | The tenant pays **the greater of** base rent or a % of sales. The breakpoint *is* the base rent |
| **Tiered bands** | a ladder of bands, each with its own rate | Configured in the **Percentage rent tiers** tab on the lease |

**A natural breakpoint with no base rent is refused.** At zero base rent the clause silently becomes
*"a percentage of every pound of sales from the first one"* — the most expensive possible reading,
and one that looks perfectly ordinary on the resulting invoice.

#### `percentage_rent_frequency` — How often the breakpoint resets

| Option | What it means |
|---|---|
| **Monthly** | A fresh breakpoint each declared month |
| **Annual** *(default for new leases)* | The threshold is a **yearly** figure. Each locked month bills only the increment needed to bring the year's percentage rent up to the cumulative overage — so a strong month is netted against slow ones instead of over-charged |

**⚠️ The single easiest thing to get wrong.** On an annual basis the threshold is a **whole-year**
figure. Typing a monthly one under-bills by roughly 12×. The field's label changes to say so, and it
shows an orange warning if the annual figure you typed is smaller than one month's base rent.

#### `percentage_rent_billing_frequency` — When the overage is invoiced
`Monthly, in arrears` (default) · `Quarterly, in arrears` · `Annually, in arrears`. **A different
term from the basis above, and the two are constantly confused.** Sales are still declared every
month either way; this only decides when the invoice is raised. Quarterly and annual settle in
arrears — the invoice is raised only once every month of the period has been locked.

#### `percentage_rent_threshold` — The breakpoint *(artificial only)*
Hidden for a natural breakpoint, where it is derived from base rent.

#### `percentage_rent_sales_exclusions` — Deductions this clause grants
What the tenant may take off gross turnover before the percentage is applied: **VAT · returns · gift
cards · inter-store transfers · employee discounts · delivery and services · other**.

**VAT is not a concession** — that money was never the tenant's — but every other line here is
something a landlord *granted*, so it must be in the contract before you tick it. A lease whose
clause has not been abstracted yet allows VAT alone.

#### `percentage_rent_deductible_types` — Charges deductible from the overage
*"Percentage rent payable to the extent it exceeds CAM and tax paid in the same period"* — a common
retail clause. Pick the charge types this lease lets the tenant net off.

#### `percentage_rent_rate` — The rate
**Required** once the toggle is on. Left blank, the lease reads as configured on every screen and
calculates an overage of 0.00 for its whole term — silent, which is the failure mode this module
keeps meeting. Typical mall rates: 5–8% for food and beverage, 4–6% for retail.

---

## Tab 5 — Notes

`notes` — a free-text field, and it is **yours**. Nothing appends to it automatically any more; the
lease's own **History** tab records what happened and why.

---

## Tab 6 — Documents

`documents` — the signed lease and its addenda. PDF, image or Word, up to 10 MB each, reorderable.

**They are stored privately**, never on the public web root: a signed lease and a tenant's tax card
are exactly the documents that must not be one guessed URL away. Downloading one goes through the
panel, which checks who you are first.

---

*Plus:* any **custom fields** your operator has added to leases appear on this form automatically,
in their own section, and become filterable columns on the leases list.


---

# Part 4 — The 13 tabs on a saved lease

Once a lease is saved, its page becomes the **record hub**: a summary at the top, the action
dropdowns beside it, and thirteen tabs underneath. The order is deliberate — the two questions
people actually open a lease to answer come first, and reference data that only some leases have
sits after the money.

## The Summary, above the tabs

Six figures, so the common questions never need a tab at all:

| Card | What it says |
|---|---|
| **Rent today** | What is billing *now*, and when it next changes |
| **Premises** | The units held **as at today** |
| **Term** | The dates, plus days to expiry — or "in holdover" |
| **Outstanding** | What they owe, and how many invoices are overdue |
| **Deposit** | Held vs contractual, **naming the shortfall** |
| **Next critical date** | The soonest deadline from the open options |

**It computes nothing of its own.** Every figure is read from the same service the tab underneath
reads, because a summary with its own arithmetic is a second opinion — and the first thing anyone
notices is that it disagrees with the tab below it.

---

### 1. Charge schedule
**The question:** *what does this lease bill, and since when?*

Every dated row: type, amount, from, to, whether it is **billing now / scheduled / ended**, why it
exists (seeded, typed, escalated, carried on renewal), its frequency, whether it prorates, and its
VAT. The heading above the table states what is billing today and when it next changes.

**No row is editable in place — on purpose.** Two actions live here instead:
- **Add charge** — how any other catalogue code gets onto a lease: key money, a chiller charge, a
  signage fee. `base_rent`, `marketing` and `parking` are **not offered**, because each is derived by
  its own service and a second way to open one of those rows is a second way for them to disagree.
- **Stop charge** — closes a row from a date. It does not delete it: the months it billed stay true.

**The surprise:** people read this as a payment plan and ask why it does not show what is paid each
month. It holds the *rules*; the **Billing forecast** tab holds what those rules produce.

### 2. Invoices
**The question:** *what do they owe?* The most common reason anyone opens a lease at all.

Every invoice raised from this lease, its status and its balance. Drill through any number to the
invoice itself.

### 3. Billing forecast
**The question:** *what will this tenancy bill next year?*

Period by period, for the next 24 months. Three readings worth knowing:
- A **quarterly** lease is grouped into cycles rather than listing the mid-cycle months as gaps.
- A period **already invoiced** shows what it *actually* billed and names the invoice — re-planning
  the past would report it at today's rent and read as a discrepancy that isn't one.
- **"Truncated"** means *the schedule continues past what is shown*, not *we hit a row cap*. A
  60-month lease whose 24 rows fill the horizon is still cut short by three years.

**It computes nothing of its own** — every row is the same planner the real billing run uses. A
forecast with its own arithmetic diverges first on exactly the cases that matter, and silently.

**One action:** the row that is genuinely due carries **Bill this period**, routed through the real
billing service so it inherits the same locks and the already-billed check. It appears only inside
the allowed billing window — a button on every future row would let someone raise a receivable two
years early from the one screen whose whole job is to look ahead.

### 4. Deposits
**The question:** *have they paid the deposit, and how much of it is still ours?*

A header states **agreed / held / billed / shortfall**, then every movement: receipt, refund,
forfeit, and any amount netted against arrears.

**The surprise, and it is important:** a deposit can arrive on **two rails** — billed as an invoice
line and paid like any other bill, or handed over directly and recorded here. The table shows only
the second. That is why the header exists: an empty table on a lease holding EGP 180,000 used to
read as *"they never paid"*.

### 5. Options
**The question:** *what rights does this tenant have, and when do they expire?*

Renewal · termination · expansion · contraction · right of first refusal · right of first offer ·
purchase. Each with **both ends** of its notice window, the rent basis it would produce, any
termination penalty, and the unit it ties up. Sorted **soonest deadline first**, with a live
days-left badge — this table is a work-list, so the row that bites next is at the top.

Actions: **Exercise** (records the deal and pre-fills the renewal), **Waive**.

**The rule that matters:** *an option not recorded here is an option nothing will ever remind anyone
about.* The nightly scan reads these rows and nothing else. The leases list carries a **"No options
recorded"** filter so *"which contracts have not been abstracted yet"* is one click.

### 6. History
**The question:** *why did the numbers change, and on whose authority?*

Every commercial change — rent modification, abatement, holdover, expansion, contraction, extension
— with its type, effective date, reason, actor and document reference.

**Append-only, both ways.** Nothing here can be edited or deleted. Correct a mistake by recording
the correcting event, exactly as you would void an invoice rather than delete it. Where a change was
made by a nightly job the actor reads **"System"**, which is true.

### 7. Parking & signage (rentable items)
Bays, storage rooms and signage faces this lease holds — space **beyond the premises**. Shows the
**negotiated** rate off the assignment, not the item's asking rate, because what this lease pays is
the only figure that reconciles with the parking charge on the invoice.

Actions: **Assign**, **Release** — the same ones in the header, defined once.

### 8. Straight-line rent
**The question an owner's accountant asks:** *what do the books recognise, against what the lease
bills?*

Under the accounting standard, a rising rent is recognised as its **average** across the term. This
tab shows the schedule (recognised per month, total, term) then billed / recognised / adjustment per
period, with a cumulative sum that must **unwind to zero by expiry** — the property an accountant
checks.

Read-only: the adjustment is posted by a monthly job from the schedule and the month's billing, so a
create button would be a second way to state a number the engine already computes.

**Appears only** when the feature is switched on *and* this lease can actually be averaged (it needs
a term and a rent ladder). It ships **off**.

### 9. Clauses — the lease abstract
**The question:** *what did we agree that is not money?*

Use · **exclusivity** · **radius restriction** · **co-tenancy** · **kick-out** · assignment and
subletting · insurance · operating hours · signage · parking allocation · repairs · guarantor.

Only the number a clause actually carries is shown: kilometres for a radius restriction, an
occupancy floor for co-tenancy, a sales threshold for a kick-out, a notice period for anything
conferring a right.

**Two of these are contingent money and are badged apart** — co-tenancy and kick-out. *"Who can
claim an abatement if the anchor leaves?"* is a question that used to live only inside a PDF.

**What it deliberately does not do:** it does not abate rent automatically when a co-tenancy trigger
fires. Whether the trigger fired is a legal reading, and a system abating on its own reading would be
wrong in exactly the cases that matter — each error being a credit you then have to claw back from a
tenant who has already banked it. Record the trigger here; grant the abatement deliberately.

**The signed PDF remains the source of truth.** This is an abstract, and an abstract is allowed to be
shorter than what it summarises.

### 10. Percentage rent tiers
The band ladder, for a lease whose calculation type is **tiered**. Hidden otherwise, rather than
silently collecting rows nothing reads. The last band must be **unbounded**, or the ladder stops
charging above its own ceiling. A 0% first band **is** the breakpoint — worth saying, because it
looks like a mistake.

### 11. Sales declarations
What the tenant reported selling, month by month, and whether each month is **locked** (percentage
rent can only be billed from a locked month).

### 12. CAM cap terms
The ceiling a tenant's CAM share cannot exceed, effective-dated by year. Caps the year-end true-up
and the admin fee; the allocation itself stays uncapped. **Carried forward on renewal** — losing it
gives a capped tenant an uncapped bill.

### 13. Activity log
The audit trail: every column an operator changed, in both languages, with who and when.

---

# Part 5 — Every button on a lease

The lease page header carries **Generate invoice** and three dropdowns:

| Group | Actions |
|---|---|
| 💰 **Money** | Change rent · Grant relief · Bill security deposit · Record deposit movement |
| 🏬 **Premises** | Change premises · Assign parking/signage · Release parking/signage |
| 🔄 **Lease** | Renew · Extend term · Convert to holdover · Terminate · Final account |

**If a button is not there, it is one of three things:** your role does not hold the permission, the
lease's status does not allow it, or the module is switched off. Part 10 tells them apart.

Each action below: **what it is · when it appears · what it asks · what it does · what changes
elsewhere · what it refuses.**

---

## 📄 Generate invoice

**What it is.** Raise this lease's invoice for one period by hand, instead of waiting for the
monthly run.

**When it appears.** Status is `active`, and you hold `leases.generate_invoice`.

**What it asks.** The **period** — a month picker bounded to **12 months back and 1 month ahead** —
and a **prorate first period** toggle (default on).

**The bound is on you, not on the engine.** The nightly run, a data backfill and
`billing:run --period=` are all unclamped, because those periods were chosen deliberately by someone
who is not clicking a button. A month typed into a form is exactly where a mis-key becomes a
receivable nobody meant to raise.

**What it does.** Runs the same planner and the same service the nightly run uses — so it inherits
the period lock and the already-billed check, and cannot become a second billing path. The invoice it
produces is **issued**, not a draft: it is in the ledger and visible to the tenant straight away.

**What it refuses, and what each refusal means.** This is the single most useful list in the
document, because the button reports a *reason* rather than failing:

| Reason on screen | What it means |
|---|---|
| **Not billable — status** | The lease is not `active`. Change the status, or accept that a draft does not bill. |
| **Not billable — ended** | The period is past the lease's expiry. If the tenant is still there, that is **holdover** — convert it. |
| **Not billable — not started** | The period is before commencement. |
| **Already billed** | An invoice for that period exists. Look at the Invoices tab. |
| **No applicable charges** | The lease has **no charge rows covering that period.** Almost always the real problem: check the Charge schedule tab. |
| **Fit-out** | The whole period is inside a `gross` rent-free window, so nothing bills. Correct behaviour. |
| **Off cycle** | A quarterly/annual lease, and this is not a cycle-start month. It tells you the cadence. |
| **Run in progress** | The nightly billing run is holding the lock. Wait and retry. |
| Outside the billing window | You asked for a month too far in the past or the future. |

---

## 💰 Change rent

**What it is.** The only supported way to change what a tenant pays. Not an edit — an act.

**When it appears.** Status `active` or `pending_approval`, and you hold `leases.edit`.

**What it asks.**

| Field | Meaning |
|---|---|
| **New rate (EGP/m²/year)** *or* **New monthly rent** | Whichever the lease is priced on. A rate-priced lease asks for the **rate** and derives the monthly figure. |
| **New service charge** | Required. Pre-filled with the current one; leave it as it is if it is not changing. |
| **Effective from** | The date the new rent starts. |
| **Reason** | Free text — *"annual review"*, *"negotiated reduction"*. This is what makes the history readable a year later. |
| **Document reference** | Optional: the addendum this was agreed in. |

**What it does.**
1. **Closes** the schedule row in force, the day before the effective date.
2. **Opens** a new row from the effective date at the new amount.
3. Updates the lease's own rent field, so every screen showing "the rent" agrees.
4. Re-derives the **marketing levy** so it follows the new rent.
5. Writes a **rent modification** entry in the History tab — inside the same transaction, so a change
   and its record commit or fail together.

**What changes elsewhere.** Next month's invoice · the rent roll · the billing forecast · the
summary · the owner's revenue from the effective date on. **Nothing already invoiced moves.**

**What to expect.** Effective dates **snap to the billing month** — the engine bills one amount per
charge type per month, so a change effective the 14th starts on the 1st. And billing a **past** month
bills what was in force **then**: that is the point of the schedule, and it looks like a bug the
first time you see it.

---

## 💰 Grant relief

**What it is.** A temporary discount that **reverts by itself**. Deliberately not a checkbox on
Change rent: a concession and a renegotiation are different deals with different consequences, and
the whole point is that the system can tell them apart.

**When it appears.** Status `active` or `pending_approval`, `leases.edit`.

**What it asks.** Which charge (**base rent** or **service charge**) · basis (**a percentage off** or
**a relieved amount**) · the figure · **from** and **to** · reason · document reference.

**What it does.** Trims the underlying schedule rows around the window and lays a relief row over
them. When the window ends, the rent **resumes by itself** — and if a contracted escalation step
falls inside the relief window, it resumes at the **post-step** amount, not the pre-relief one.

**What it does NOT do.** It does not move the contracted rent, and **the marketing levy does not
follow it** — a concession is not a renegotiation. (On a rent *change*, both do.)

**What it refuses.** A `to` date not after the `from`. Neither figure supplied.

---

## 💰 Bill security deposit

**What it is.** Raise the deposit as an **invoice line**, so the tenant can pay it the way they pay
everything else — on the portal, by card, against a statement.

**When it appears.** Only while there is a **shortfall**, on an `active` or `pending_approval` lease.
An action that refuses the moment you press it is worse than one that is not offered.

**What it asks.** The **amount** (pre-filled with the shortfall) and an **issue date**.

**What it does.** Raises an invoice carrying one `security_deposit` line, dated to the lease term.

**Three rules that are easy to get wrong:**
- **It bills the SHORTFALL, never the contractual figure.** Billing 180,000 to a tenant who already
  paid 100,000 is how a landlord ends up holding — and owing back — twice the deposit.
- **An unpaid deposit invoice is not "held".** It is a receivable. Only settlement counts, or you
  would refund at move-out money that never arrived.
- **It carries no VAT.** A deposit is a security, not a supply; taxing it would charge VAT on the
  landlord's own liability.

**Why it is not income.** That charge code posts to a **liability** account: on issue `Dr Tenant
Receivables / Cr Deposits Held`, on payment `Dr Bank / Cr Tenant Receivables`. Net effect
`Dr Bank / Cr Deposits Held` — exactly what a direct receipt posts. No double count.

---

## 💰 Record deposit movement

**What it is.** The other rail: money that arrived (or left) outside the invoice system — the cheque
handed to the leasing office, the refund at move-out, the forfeit.

**When it appears.** You hold `deposit_transactions.create` and can edit the lease.

**What it asks.** **Type** (receipt / refund / forfeit) · **method** (`cash` or `bank` — the
deposit's own set, not the payment rails) · **amount** · **date** · notes. A header states what is
currently held against what was agreed.

Amount is pre-filled with **what is missing** on a receipt, and with **what is held** on a refund or
forfeit — you cannot give back more than you have.

**What it refuses.** A refund or forfeit larger than the amount held. That would post a *negative*
liability: the landlord recorded as owing a tenant money it never took.

**A forfeit asks for no method** — it moves nothing through a bank; it turns the landlord's liability
into income.

---

## 🏬 Change premises

**What it is.** Expand or contract the space this lease covers. **The only safe way** — the
additional-units field on the form is read-only for exactly this reason.

**When it appears.** Status `active` or `pending_approval`, `leases.edit`.

**What it asks.** **Direction** (expand / contract) · the **units** · **effective from** (defaults to
the 1st of next month) · an optional **new total rent** · reason · document reference.

- **Expanding** offers unlet space in **this lease's own property**.
- **Contracting** offers the units this lease currently holds — **except the master unit**, which
  cannot be given back because it is the lease's identity. (Moving out of the master is a
  *relocation*, an event type with no service yet.)

**What it does.** Space and money move on **one date, through one service**. It writes the dated
occupancy row (or closes one), records an expansion/contraction event, and re-prices the lease:
- a **rate-priced** lease re-derives its rent from the new area automatically;
- a **stated** new total rent wins over that — a blended rate for enlarged premises is a real
  negotiation.

**Why contraction closes rather than deletes.** The occupancy row carries the dates CAM is
apportioned on. Deleting it would erase the months the tenant genuinely held the space, silently
restating a reconciliation that may already be signed.

**A released unit is vacant immediately; a unit promised for a future date reads `reserved`** — not
occupied, not free — so nobody else can be promised it in the meantime.

---

## 🏬 Assign parking / signage · 🏬 Release parking / signage

**What they are.** Let (or give back) a bay, a store room or a signage face — space beyond the shop.

**When Assign appears.** You hold `rentable_items.edit` and the lease is `active` or
`pending_approval`. **Release** appears only when the lease currently holds something.

**What Assign asks.** The **item** (only items in this property that are **free on the chosen
date**) · **effective from** · an optional **monthly rate** — blank uses the item's asking rate; what
the tenant negotiated goes in the box.

**What it does.** Writes the dated assignment **and re-derives the lease's single `parking` charge**,
so the money follows in the same click.

**What it refuses.** An item already held by someone else on that date.

---

## 🔄 Renew

**What it is.** End this tenancy and start a **new lease** — its own reference, its own term, its own
negotiated money, linked back to the original so the chain is readable.

**When it appears.** Status `active`, and you hold `leases.renew`.

**What it asks.** New **term months** · new **rent** · new **service charge** · **commencement**
(defaults to the day after the current expiry). It shows you the **resulting expiry date** before you
commit — the modal used to take a term and a date and never display the end date they produce.

**Pre-filled from a recorded renewal option** where one exists, and the modal says so. A `market` or
`cpi` basis pre-fills **nothing** and falls back to the current rent for you to overwrite: a
valuation and an index feed are not numbers this system may invent.

**What it does.**
1. Creates the new lease, `active`, pointing back at the original.
2. Carries the **full unit set**, master preserved.
3. Carries **every negotiated term** — the payload is *"every lease column except the ones a renewal
   resets"*, so a term added to the system later is carried by default. The reset list is short and
   each entry says why (the reference, the dates, the rent, and state that belonged to the original
   tenancy such as possession, fit-out grace and holdover).
4. Carries the child records too: the **CAM cap**, the **percentage-rent ladder**, and the **parking
   and signage** assignments.
5. Marks the original **renewed**.

**Why this list is spelled out.** The service used to enumerate what to copy, the table grew, and
**14 negotiated terms were silently dropped on every renewal** — none of which errored. The worst was
invisible rather than wrong: the escalation clause arrived half-copied, so the lease never escalated
again for its whole term and looked exactly like a lease with no clause.

**What it refuses.** A lease that is not `active`. If two people press Renew at once, one of them is
refused rather than both creating a renewal.

---

## 🔄 Extend term

**What it is.** The **same** contract, running longer on the same terms — a "further term", or an
exercised extension option.

**When it appears.** Status `active` or `pending_approval`, and you can edit the lease.

**What it asks.** The **new expiry date** (the helper shows the current one) · **reason** ·
**document reference**.

**What it does.** Moves the expiry, re-derives the term in months, records an **extension** event,
and **re-projects the escalation ladder** — anniversaries that fell past the old expiry now fall
inside the term, and a lease must not run two more years with its future rent recorded nowhere.

**What it does NOT do.** It does not re-date the charge rows. They are open-ended, and a longer term
simply keeps billing them; re-dating them would be the bug.

**What it refuses.** **Pulling an expiry backwards.** Ending a tenancy early is a *termination* —
which settles the deposit, credits unearned billing and closes the schedule. None of that happens
here, so the action will not pretend to do it.

**Extension vs renewal, one line each:** an **extension** leaves the same contract running longer; a
**renewal** ends this tenancy and starts a new contract with new terms.

---

## 🔄 Convert to holdover

**What it is.** The term ended and the tenant is still trading. This keeps them billing — at a
penalty.

**When it appears.** Only on a lease that is **genuinely past its expiry** and has not already been
converted.

**What it asks.** The **uplift %** (pre-filled at 150% from settings) · **effective from**
(pre-filled with the month after expiry) · reason · document reference.

**What it does.** Applies the uplift to the rent row in force **at expiry** — not to a projected step
the term never reached — and lets the lease keep billing past its end date.

**Why nothing does this automatically.** *"The tenant is still in the unit"* is a fact only a human
knows. The system will alert you that a lease has run past its term; converting it is your decision.

**Two things that follow from the uplift being a premium:**
- The contracted rate **stays contracted**. Re-rating on conversion would bake a temporary penalty
  into the agreed rate and lose what the parties signed.
- Every derivation honours the premium — take an extra unit mid-holdover on a rate-priced lease and
  the rent is re-derived **including** the uplift, not silently dropped back to 100%.

---

## 🔄 Terminate

**What it is.** End the tenancy early, and settle the money that follows from it.

**When it appears.** Status `active` or `pending_approval`, and you hold `leases.terminate`.

**What it asks.**

| Field | Meaning |
|---|---|
| **Termination date** | Cannot be earlier than the commencement — the picker will not offer it. Equal is allowed: a deal that collapses at handover terminates on its commencement date. |
| **Reason** | Free text. |
| **Credit the unearned part** *(default ON)* | Rent bills in advance, so a tenant leaving on the 18th has already been invoiced for the whole month. This credits back the part they will not be there for. **Switch it off** only when the credit note would land in a **closed** accounting period — that is the one case the flag exists for. |
| **Cancel open invoices** *(default ON)* | Cancels fully-unpaid invoices for periods that **start after** the termination. |

**What it does.**
1. Status → `terminated`, expiry → the termination date.
2. **Deactivates every charge row** — the lease stops billing.
3. Cancels the open invoices described above.
4. Credits the unearned share of the invoice that **straddles** the termination.

**The rule that makes step 3 and step 4 work together — and it is worth understanding.** The
cancellation is scoped by **period**, not by balance:
- period **starts after** the termination → cancel it;
- period **straddles** it → leave it; step 4 credits the unearned share;
- period **ended before** it → leave it owing. That money was earned.

It used to cancel every fully-unpaid invoice regardless of period, which on a system that bills in
advance destroyed revenue the landlord had already earned — and worse, it deleted the very document
step 4 was going to credit, so **no credit note appeared and none was missing from anywhere anyone
would look**. On one reproduction that was EGP 463,260 of receivables gone from a tenant who had
occupied and traded from the space.

**What it does NOT do.** It does not cancel **partially-paid** invoices — that would orphan money
already received. Reverse those with a credit note.

**What it refuses.** A termination date before commencement. A lease that is not active or pending.

---

## 🔄 Final account (move-out statement)

**What it is.** The document that settles the tenancy — the last money conversation you will have
with this tenant.

**When it appears.** Status `terminated` or `expired`, `leases.edit`.

**What it shows you, before you touch anything:**

| Line | Meaning |
|---|---|
| Contractual deposit | What the lease said |
| Deposit held | What actually arrived — both rails |
| Deposit shortfall | If they never paid it in full |
| Open AR | What they still owe |
| Tenant credit | Any credit they hold |
| **Net to tenant** *or* **Residual debt** | Which way the money goes |
| **Pending true-ups** | What is **not knowable yet** — an unreconciled CAM year, missing sales declarations |

**What it asks you to add.** A repeater of **deductions** (description + amount) — damage, cleaning,
unreturned keys — plus a **settlement date** and a document reference.

**What it does, in Yardi's order:**
1. **Arrears are netted off the deposit first.** An unpaid rent invoice is a real document that may
   already have reached the tax authority; a deduction is an assessment made at settlement.
2. Then the operator's deductions are **forfeited** (the landlord keeps them — this turns liability
   into income).
3. Then the remainder is **refunded**.

**It is ONE transaction.** Whole or not at all. It used to settle arrears outside the transaction, so
a settlement that then failed — most reachably on *"the deductions exceed the deposit held"* — left
the deposit already spent while the operator saw an error and concluded nothing had happened.

**The statement is frozen** as the termination event's payload. Re-deriving it a year later would
show today's numbers, not the ones that were signed.

**The settlement date is a posting date** — back-dating it into a closed accounting period is
refused.

---

# Part 6 — The leases list

## 6.1 The five tabs

| Tab | What it shows |
|---|---|
| **All** | Everything |
| **Pending approval** | `draft` + `pending_approval` — the pipeline |
| **Active** | Live tenancies |
| **Expiring** | `active` leases whose expiry falls in the **next 90 days** |
| **Ended** | `expired`, `renewed`, `terminated`, `cancelled` |

**"Expiring" is a date window, not a status** — a lease is `active` right up to the day it lapses, so
renewals are invisible on a pure status split.

## 6.2 The columns

Reference (copyable) · unit code, with the additional units listed underneath (*"+ A-02, A-03"*) ·
tenant · monthly rent · **deposit due** · commencement · expiry (**red under 30 days, orange under
90**) · status badge · plus any custom fields your operator added.

**The deposit-due column shows its own subtraction** (agreed − held) underneath the figure, so it is
never a number you have to take on trust.

## 6.3 The filters — each one is a business question

| Filter | The question it answers |
|---|---|
| **New vs renewal** | *How much of the book is renewals?* |
| **Deposit outstanding** | *Who still owes me a deposit?* — one click instead of opening every lease |
| **No options recorded** | *Which contracts have not been abstracted yet?* |
| **Status** | The obvious one |
| **Tenant** · **Unit** | Both search the folded blob, so Arabic spelling variants match |
| **Percentage-rent basis** | *Show me every lease still on the monthly basis* — a clause review as a filter |
| **Commencement range** · **Expiry range** | Date windows |
| **Expiring soon** | Active leases lapsing in 90 days |
| **Option closing** | An option whose notice window closes within 90 days — **the deadline that cannot be recovered once missed** |
| **Holdover** | Active leases past their end date |
| **Missing sales declarations** | Percentage-rent tenants who have not reported last month's sales, so their overage cannot be billed |
| **Deleted** | Soft-deleted records |

## 6.4 Saved views

Save a set of filters, a search and a column layout under a name; share it with the team; mark one as
the view that **opens by default**. A personal default always beats a shared one, so a colleague
marking a team view never overrules a choice you made for yourself. The menu's **"All records"** gets
you back to the plain list.

## 6.5 The two importers

Both live on this page, and they are **not** interchangeable:

| Importer | What it creates | Keyed on |
|---|---|---|
| **Import leases** | Lease records | Lease reference (existing references are **kept**) |
| **Import charges** | Charge-schedule rows | The lease reference each row names |

**Uploading a charge file into the lease importer would create leases out of charge rows**, which is
why they are named apart. After any import, run
`php artisan atriom:audit-charge-schedules` — it reports leases whose charge rows overlap (which bill
**nothing**), leave a gap, or carry no start date.

## 6.6 Export

Whoever can read the list can export it, and an export can never contain a row the list would not —
it is the same scoped query with your filters applied.

## 6.7 Quick new lease (the wizard)

A three-step wizard on the list header: **tenant → unit and term → money**. It can create the tenant
inline, and it produces the lease, its charge schedule and the projected escalation ladder in one
pass.

**The difference from the full form** is only how much it asks. The wizard covers the common deal;
the full form exposes every term. Both end up producing the same shape of lease — this used not to be
true, and a lease created through the ordinary form had no projected rent ladder at all.

---

# Part 7 — What happens on its own, at night

Nothing here needs a person. Everything here is still your responsibility — the system records what
it did in the lease's **History** tab, but nobody approves it in the moment.

| Time | Job | What it looks for | What it does |
|---|---|---|---|
| **02:00** | Monthly billing run | Each property, on **its own** billing day | Raises every active lease's invoice for the period. Non-monthly leases only on cycle-start months |
| **04:00** | Late fees | Overdue invoices past their grace period | Raises a late-fee invoice, at this lease's rate/minimum/cap — and never charges a late fee on a late fee |
| **05:15** | `leases:expire` | `active` leases whose term has run out | Moves them to **expired** and re-projects the units, so the shop can be re-let. A converted holdover is excluded |
| **05:30** | `leases:apply-escalations` | Leases whose next escalation date has arrived | Applies the step through the same service the Change rent button uses. **One step per run**, so a backlog catches up over days instead of compounding in one pass. CPI leases wait for a published index |
| **06:00** | `billing:scan-overdue-invoices` | Newly overdue invoices | Alerts the **operator** side |
| **06:15** | `billing:remind-overdue-tenants` | The same invoices | Reminds the **tenant**, on its own stamp so the two alerts fire independently |
| **06:45** | `leases:scan-option-windows` | Option notice windows | Alerts **before the window opens**, **before it closes**, and records a missed one as **lapsed**. Three moments, because each calls for a different action |
| **07:00** | `leases:remind-expiring` | Active leases expiring within 90 days | Reminds the tenant, once per lease — email, in-app and push |
| **daily** | `sales:scan-missing-declarations` | Percentage-rent tenants who have not declared | Chases them, so the overage can be billed |
| **monthly, 2nd** | `accounting:post-straight-line-rent` | Leases with straight-line on | Posts the month's recognition adjustment — on the 2nd, so the month it recognises has closed and its invoices exist |
| **yearly, 15 Jan** | `cam:reconcile` | Last year's CAM pool | Divides actual spend across tenants and bills or credits the difference |

**Every one of them is idempotent and lock-safe**: a lease is row-locked, its due-ness re-checked
*inside* the transaction, and a second run in the same day finds nothing to do. This matters because
a re-run must never charge twice.

---

# Part 8 — The lifecycle

```
                   ┌──────────────────────────────────────────────┐
                   │                                              ▼
  draft ──► pending_approval ──► ACTIVE ──┬──► renewed   (a NEW lease takes over)
    │              │                      ├──► terminated (an act: money is settled)
    │              │                      ├──► expired   (the term ran out)
    ▼              ▼                      └──► holdover  (still ACTIVE, billing past expiry)
 cancelled     cancelled
```

| Status | How it is reached | What the unit reads | Does it bill? |
|---|---|---|---|
| **draft** | You create the lease | **Reserved** | No |
| **pending_approval** | You move a draft on for review | **Reserved** | No |
| **active** | You set it, or a renewal is created | **Occupied** | **Yes** |
| **renewed** | `Renew` marks the original | Reserved (the *renewal* occupies it) | No — the renewal does |
| **expired** | The nightly sweep, or you set it | **Vacant** | No |
| **terminated** | `Terminate` | **Vacant** | No — charges deactivated |
| **cancelled** | You cancel a draft or pending lease | **Vacant** | No |

**Holdover is not a status.** A lease in holdover is still `active`; what changes is that it has a
holdover date and an uplift, which is what lets it keep billing past its expiry.

## The unit projection, in one rule

> A unit is **occupied** if *any* lease on it is active. Otherwise **reserved** if any lease on it is
> draft, pending or renewed. Otherwise **vacant**.

A unit manually set to **under maintenance** overrides all of it until a person clears that.

**"Occupied" and "leased" are different questions**, and mixing them double-books space. Occupancy
reads the rows in force **today**. The double-booking guard reads the rows that have not **ended** —
because an expansion agreed in September for 1 November has already claimed that unit, and reading
the occupancy question there would let a second lease take it through October and collide on the day
the expansion lands. Such a unit reads **reserved**: not occupied, not free.

## Immutability

Once a lease is **terminated, expired, cancelled or renewed** it is **frozen**. Only notes,
attachments and metadata can change. The Edit page will refuse the save and tell you why.

**Correct it by recording the correcting act, never by editing.** That is the same discipline as
voiding an invoice rather than deleting it, and for the same reason: somebody may have to explain
this record to an auditor two years from now.


---

# Part 9 — The guided workflow

## Set-up (do this once)

These exercises run on an **empty mall**, so that every number on screen is one you put there:

```bash
php artisan migrate:fresh --seed --seeder='Database\Seeders\LearningSeeder'
```

That gives you reference data (roles, chart of accounts, charge codes, tax codes, an open accounting
period), **one property**, **12 vacant units**, **3 tenants**, and nothing else — no leases, no
invoices, no ledger entries.

Log in as `admin@mall.test` / `password` and **select Atriom Walk in the property switcher**.

> **Why an empty mall?** On the demo data, a lease you create is one of forty and an invoice you
> raise is one of hundreds — you cannot tell what you did from what was already there. Here, if a
> number moves, you moved it.

**The units you have:**

| Unit | Floor | Category | Area |
|---|---|---|---|
| A-01 | Ground | retail | 60 m² |
| A-02 | Ground | retail | 75 m² |
| A-03 | Ground | retail | 90 m² |
| A-04 | Ground | food & beverage | 120 m² |
| A-05 | Ground | food & beverage | 150 m² |
| A-06 | Ground | kiosk | 15 m² |
| A-07 | Ground | service | 45 m² |
| A-12 | Ground | retail | 200 m² |
| B-01 | 1 | retail | 100 m² |
| B-02 | 1 | retail | 110 m² |
| B-03 | 1 | office | 130 m² |
| B-04 | 1 | wellness | 180 m² |

**The tenants:** Zara Egypt · Cilantro · Nike Egypt.

**A note on the figures below.** Every number in the "what you should see" columns was computed from
the same formulas the code uses. If your screen disagrees, you have found either a typo of yours or
a real defect — both worth chasing.

**Two constraints to know before you start**, because they shape every exercise:

- **Manual invoicing is bounded to 12 months back and 1 month ahead.** The nightly run is not — the
  bound exists because a mis-keyed month typed into a form becomes a receivable nobody meant to
  raise. So every exercise below dates its lease relative to **today**.
- **A charge row starts on the 1st of its month.** The engine bills one amount per charge type per
  month, so an effective date mid-month snaps to the 1st. This is why the exercises commence leases
  on the 1st unless the exercise is *about* a part month.

---

## Exercise 1 — Write your first lease, the long way

**The deal.** *Zara Egypt takes shop A-01. Three years from the **1st of this month**. Rent EGP
60,000 a month, service charge EGP 8,000. Deposit three months' rent. 7% annual increase.*

**Do this**
1. **Leasing → Leases → New lease.**
2. **Lease Details:** unit `A-01` · tenant `Zara Egypt` · status **Draft**.
3. **Term:** commencement = the **1st of this month**. Term = `36`.
4. **Financial Terms:** basis `A flat monthly amount` · rent `60000` · service charge `8000` ·
   deposit months `3` · escalation type `Fixed %` · rate `7`.
5. Save.

**What to look at, in order**

| Look at | What you should see | Why |
|---|---|---|
| The **expiry date** field, before saving | It filled itself in the moment you typed the term | Term and expiry derive from each other |
| The **security deposit** amount | `180,000.00`, greyed out | 3 × 60,000 — you stated a *multiple*, so the amount is derived |
| The **reference** after saving | `LSE-AW-<this year>-0001` | Your first lease this year in this mall |
| The **Charge schedule** tab | **Three** rows: Base Rent 60,000 · Service Charge 8,000 · **Marketing Levy 3,000** | You typed **two** numbers and got **three** charges — the levy is 5% of base rent, derived, never typed |
| The **origin** column | *Seeded* on rent and service, *Levy* on the third | Every row records why it exists |
| The **VAT** column | 0% on rent, **14% on service charge**, 0% on the levy | Egyptian treatment, set by the charge code — not by you |
| Further down the schedule | Two more rent rows, at **64,200** and **68,694**, dated to the anniversaries | The whole 7% ladder was written at signing |
| **Properties → Units → A-01** | Status **Reserved**, not Occupied | The lease is a *draft*. Nothing is committed yet |

**Now activate it.** Edit the lease → status **Active** → Save.

| Look at | What you should see |
|---|---|
| **Units → A-01** | **Occupied**, and it has left the "available to let" list |
| **Charge schedule** heading | It states what is billing today and when it next changes |
| The **Summary** above the tabs | Rent today, premises, term, outstanding (0), deposit 0 of 180,000 |

**The point.** You entered a *deal*, and the system produced a *schedule*. Those are two different
things, and everything downstream reads the second one.

---

## Exercise 2 — Prove that the lease does not bill; the schedule does

**Do this**
1. On your lease, open the **Billing forecast** tab and read down.
2. Then open **Charge schedule** and read the heading above the table.

**What you should see**

| Period | Rent | Service | Levy | VAT | Invoice total |
|---|---|---|---|---|---|
| Months 1–12 | 60,000.00 | 8,000.00 | 3,000.00 | 1,120.00 | **72,120.00** |
| Months 13–24 | 64,200.00 | 8,000.00 | 3,210.00 | 1,120.00 | **76,530.00** |
| Months 25–36 | 68,694.00 | 8,000.00 | 3,434.70 | 1,120.00 | **81,248.70** |

The 7% steps are **already recorded**, from the day you saved the lease. A five-year 7% lease is five
rent rows on day one: the mall's future revenue is a recorded fact, not something the system will
work out later. Notice the levy steps with the rent by itself, and the VAT never moves — it is 14% of
the service charge, and only of the service charge.

**Then try to break it, and watch the system refuse:**
1. **Charge schedule → Add charge.** Open the type list and look for `Base rent`. It is **not there**
   — nor is `Marketing` or `Parking`.
2. Why: each of those is derived by its own service. Base rent moves through **Change rent**, which
   keeps the lease's own rent field and the levy in step. A second way to open one of those rows is a
   second way for them to disagree.
3. Now add something the list *does* offer — a signage or key-money code, EGP 2,000 from next month.
   Look at the forecast again: next month's total moved by exactly 2,000.

**The point.** Read the schedule and the forecast side by side once, and you will never confuse them
again: the schedule holds the **rules**, the forecast holds the **invoices those rules produce**.

---

## Exercise 3 — Raise a real invoice and watch four things move

**Do this**
1. Lease header → **Generate invoice.**
2. Period = **this month** (the first month of the term). Leave **prorate first period** on — it
   changes nothing on a full month. Generate.

**What to look at**

| Look at | What you should see |
|---|---|
| The toast | *Invoice INV-… created, EGP 72,120.00* |
| The **Invoices** tab | One row, status **Issued** — not draft. This path issues directly |
| The invoice's lines | Base rent 60,000 (no VAT) · Service charge 8,000 (+1,120 VAT) · Marketing levy 3,000 (no VAT) |
| The **due date** | Issue date + 7 days — the lease's payment terms |
| **Accounting → Journal entries** | One balanced entry: **Dr** Tenant receivables 72,120 · **Cr** rent revenue 60,000 · **Cr** service-charge revenue 8,000 · **Cr** marketing revenue 3,000 · **Cr** VAT payable 1,120 |
| The tenant's view (`/portal`) | The invoice is there. It is a real claim on them now |

*(If the journal entry has not appeared, the queue worker is not running — run
`php artisan accounting:sync-ledger`.)*

3. **Press Generate invoice again for the same month.** You get *"Already billed"*. The run is
   idempotent by design: you cannot double-bill by clicking twice.
4. **Try a month two years from now.** Refused — outside the billing window (12 months back, 1 month
   ahead). The nightly run is not bounded; you are.

**The point.** An issued invoice is simultaneously a claim on the tenant, a row in the ledger and a
line in the owner's revenue. That is why so much of this module refuses to let you edit things.

---

## Exercise 4 — A part month, priced four different ways

**The deal.** *Cilantro takes A-04 (120 m², food & beverage) from the **16th of this month**. Rent EGP
90,000. No service charge.*

**Do this**
1. Create the lease: unit `A-04`, tenant `Cilantro`, commencement **the 16th of this month**, term
   `24`, rent `90000`, service charge `0`, escalation `None`. Activate it. (The levy row appears at
   4,500 — 5% of 90,000. No service charge means no service-charge row and no VAT at all, which keeps
   the arithmetic visible.)
2. Generate the invoice for **this month**, with **prorate** on.
3. Note the total. Then edit the lease, set **proration method** to `One thirtieth per day (30/360)`,
   save, cancel the invoice, and generate it again. Repeat for the other two methods.

**The four formulas**, where `d` = days occupied (the 16th to the month end, inclusive) and `D` = days
in that month:

| Method | Share of the month |
|---|---|
| Actual days in the month *(default)* | `d ÷ D` |
| One thirtieth per day (30/360) | `d ÷ 30` |
| Annual rent ÷ 365 | `d × 12 ÷ 365` |
| Charge the whole month | `1.0` |

**Worked, in a 31-day month** (d = 16):

| Method | Factor | Rent | Levy | Invoice total |
|---|---|---|---|---|
| Actual days | 0.516129 | 46,451.61 | 2,322.58 | **48,774.19** |
| One thirtieth per day | 0.533333 | 48,000.00 | 2,400.00 | **50,400.00** |
| Annual ÷ 365 | 0.526027 | 47,342.47 | 2,367.12 | **49,709.59** |
| Whole month | 1.000000 | 90,000.00 | 4,500.00 | **94,500.00** |

**Worked, in a 30-day month** (d = 15):

| Method | Factor | Rent | Levy | Invoice total |
|---|---|---|---|---|
| Actual days | 0.500000 | 45,000.00 | 2,250.00 | **47,250.00** |
| One thirtieth per day | 0.500000 | 45,000.00 | 2,250.00 | **47,250.00** |
| Annual ÷ 365 | 0.493151 | 44,383.56 | 2,219.18 | **46,602.74** |
| Whole month | 1.000000 | 90,000.00 | 4,500.00 | **94,500.00** |

4. Now generate **next month**. Under **every** method it is exactly 90,000 + 4,500 = **94,500.00**.

**The point.** The method prices the **stub**, never a full month — otherwise a 30/360 lease would
bill 31/30 of a month every August. And the spread between the top and bottom rows is EGP 45,725 on
one month of one shop: which method the contract states is not a detail. Notice too that in a 30-day
month two of the methods agree exactly, and in a 31-day month they do not — which is the whole reason
a contract bothers to say.

---

## Exercise 5 — A rent-free fit-out that still sends an invoice

**The deal.** *Nike Egypt takes A-02 (75 m²) from the **1st of three months ago**, but rent does not
commence until the **1st of this month** — three months to build the shop. Rent EGP 50,000, service
charge EGP 6,000.*

**Do this**
1. Create the lease: unit `A-02`, tenant `Nike Egypt`, commencement = the 1st of three months ago,
   term `36`, rent `50000`, service `6000`, **rent commencement** = the 1st of this month. A
   **fit-out scope** field appears — leave it at `Base rent only`. Save, activate.
2. Generate the invoice for **three months ago**.

**What you should see**

| Look at | What you should see |
|---|---|
| That invoice | It **exists** — service 6,000 + levy 2,500 + VAT 840 = **9,340.00** |
| What is missing from it | No base-rent line. That is exactly what "base rent only" abates |
| This month's invoice | 50,000 + 6,000 + 2,500 + 840 = **59,340.00** |

*(If the levy line is missing from the older invoice, that is the charge-row rule from the setup note:
the levy row was opened on the 1st of the month you created the lease in, so it does not reach months
before that. Rent and service charge are stamped from the commencement date and do.)*

3. Now edit the lease, change **fit-out scope** to `Everything`, save, cancel the old invoice and
   generate it again.

| Look at | What you should see |
|---|---|
| The toast | A refusal: *"Fit-out"* — **nothing** bills for that month |

**The point.** *"Rent-free"* has two readings, and the contract picks one. The market standard is the
first: the mall is still cleaning, securing and cooling the unit while it is being fitted out, so
those reimbursements keep billing. A tenant who expects a zero invoice in month one and receives
9,340 is not looking at a bug — they are looking at a net abatement.

---

## Exercise 6 — A quarterly lease bills three months in advance

**The deal.** *B-01 (100 m²) to Zara Egypt. Rent EGP 40,000, service EGP 5,000, billed **quarterly**,
commencing the **1st of this month**.*

**Do this**
1. Create it with **billing frequency** = `Quarterly (every 3 months)`, escalation `None`. Activate.
   (Levy = 2,000.)
2. Generate the invoice for **this month**.
3. Then try to generate **next month**.

**What you should see**

| Step | Result |
|---|---|
| This month | **One** invoice: rent 120,000 · service 15,000 · levy 6,000 · VAT 2,100 → **143,100.00**, covering **this month through the month after next** |
| Next month | Refused: *"Off cycle"*, naming the cadence — it is not a cycle-start month |
| The **Billing forecast** tab | Grouped into cycles, not listing the mid-cycle months as gaps in the tenant's obligations |

**The point.** Non-monthly leases pay **in advance**, one invoice per cycle. And the cycle is anchored
to the lease's **first billable month**, not to the calendar quarter — a lease whose first billable
month is February bills Feb–Apr, then May–Jul.

---

## Exercise 7 — A rate-priced lease re-prices itself

**The deal.** *A-12 (200 m²) to Nike Egypt at **EGP 4,800 per m² per year**.*

**Do this**
1. Create the lease, commencing the 1st of this month. On **Financial Terms**, set the basis to `A
   rate per m² per year` and type `4800`. Watch the monthly rent field: it fills itself in and greys
   out. Activate.
2. **Premises → Change premises** → *Expand* → add **A-03** (90 m²) → effective the 1st of next month
   → reason *"tenant expansion"*. Leave the new total rent **blank**.

**What you should see**

| Step | Rent | The arithmetic |
|---|---|---|
| At creation | **80,000.00** | 200 m² × 4,800 ÷ 12 |
| After the expansion | **116,000.00** | 290 m² × 4,800 ÷ 12 |

| Look at | What you should see |
|---|---|
| **Charge schedule** | The 80,000 row **closed** the day before the effective date, a new row **opened** at 116,000. Nothing overwritten |
| The **levy** | Followed by itself: 4,000 → 5,800 |
| **History** | An **expansion** event with your reason and the effective date |
| **Units → A-03** | **Occupied** |
| The lease's **premises** on the Summary | Two units |

3. Now do it again on another lease, this time **stating** a new total rent of `110000`. The stated
   figure wins — a blended rate for enlarged premises is a real negotiation, and the system does not
   overrule it.

**The point.** This is why commercial rent is quoted per m². The money follows the area with nobody
doing arithmetic by hand, and two deals in different-sized shops become comparable.

---

## Exercise 8 — Change the rent, and prove the past keeps its own

**Do this** *(back on the Exercise 1 lease, which has this month's invoice already issued at 72,120)*
1. **Money → Change rent.** New monthly rent `66000`. Service charge: leave `8000`. Effective from
   the **1st of next month**. Reason: *"annual review"*.
2. Look at the **Charge schedule**, the lease's rent field and the **History** tab.
3. Generate the invoice for **next month**.
4. Now **cancel this month's invoice and generate it again.**

**What you should see**

| Look at | What you should see |
|---|---|
| Charge schedule | The 60,000 row now **ends** the last day of this month; a 66,000 row **opens** on the 1st of next. The levy did the same: 3,000 → 3,300 |
| The lease's rent field | 66,000 — every screen agrees |
| **History** | A **rent modification** entry with your reason, the date and your name |
| **Next month's** invoice | 66,000 + 8,000 + 3,300 + 1,120 VAT = **78,420.00** |
| **This month's** re-raised invoice | **72,120.00** — the old rent, again |
| The 7% ladder further down the schedule | Re-based on the new rent |

**The point.** Step 4 is the whole reason the schedule exists. A month bills what was in force **in
that month**, not what the lease says today. It will look like a bug the first time you see it; it is
the opposite of one — it is what makes a re-run of a past month reproduce the invoice you actually
sent.

---

## Exercise 9 — Grant relief, and watch it revert by itself

**The deal.** *Zara's shop loses the corridor to construction. You agree **50% off base rent for
three months**, starting next month.*

**Do this**
1. **Money → Grant relief.** Charge = `Monthly rent` · basis = `A percentage off` · `50` · from the
   1st of next month · to the last day of the third month · reason.
2. Read the toast, then open the **Charge schedule** and the **Billing forecast**.
3. Generate next month's invoice (cancel the one from Exercise 8 first).

**What you should see**

| Period | Rent | Levy | Service | Invoice total |
|---|---|---|---|---|
| The three relief months | **33,000.00** | 3,300.00 | 8,000.00 | **45,420.00** |
| The month after | **66,000.00** again | 3,300.00 | 8,000.00 | **78,420.00** |

| Look at | What you should see |
|---|---|
| The toast | It names the relieved amount **and the amount it reverts to** |
| Charge schedule | A relief row over the window, with the contracted rows trimmed around it |
| The lease's **rent field** | Still **66,000** — the contracted rent did not move |
| The **levy** | Still 3,300 — **it did not follow the relief** |

**The point.** A concession is not a renegotiation. The contracted rent is what the lease says; the
relief is a window with its end date built in, so nobody has to remember to put the rent back. And
the levy not following is deliberate: the marketing-fund contribution was not what you discounted.

---

## Exercise 10 — The deposit, on both rails

**Do this** *(Exercise 1 lease: deposit agreed 180,000, nothing collected)*
1. **Money → Bill security deposit.** Change the amount to `100000` and issue it.
2. Open that invoice and record a payment of 100,000 against it.
3. Back on the lease → **Deposits** tab.
4. Now **Money → Record deposit movement**: type `Receipt`, method `Bank`, amount `80000`, today.
5. Look at the Deposits tab again, and at the **Deposit due** column on the leases list.

**What you should see**

| Step | Held | Shortfall | Where it shows |
|---|---|---|---|
| Start | 0.00 | 180,000.00 | The leases-list column, showing its own subtraction |
| After billing 100,000 | still **0.00** | still 180,000.00 | **An unpaid deposit invoice is not held** — it is a receivable |
| After that invoice is paid | 100,000.00 | 80,000.00 | The Deposits tab **header** — and the table is **still empty** |
| After the 80,000 receipt | 180,000.00 | 0.00 | Now the table has one row |

6. Look at the deposit invoice's line: **no VAT**. A deposit is a security, not a supply.
7. Look at its journal entry: **Cr Deposits Held** — a **liability**, not revenue.
8. Try **Record deposit movement** → `Refund` → `200000`. Refused: you cannot give back more than you
   hold. That would post a negative liability — the landlord recorded as owing money it never took.
9. Look at the header again: the **Bill security deposit** button has gone. There is no shortfall
   left, and an action that would refuse the moment you press it is not offered.

**The point.** Two rails, one figure. The table shows only the movements you recorded by hand; the
header shows what the lease actually holds, both rails together. That gap is exactly what once made a
register read 390,000 against a real obligation of 534,000.

---

## Exercise 11 — Percentage rent, three ways on the same sales

**The deal.** *Cilantro (A-04, rent 90,000) pays **8%** percentage rent. Last month they sold EGP
1,500,000.*

**Do this**
1. Edit the Cilantro lease → **Percentage Rent** tab → toggle on → rate `8`.
2. Set calculation type `Artificial`, frequency `Monthly`, threshold `1000000`. Save.
3. **Leasing → Sales declarations** → declare 1,500,000 for Cilantro for last month → **Lock** it.
4. Read the computed overage, then change the lease's terms and compare.

**What you should see**

| Configuration | The arithmetic | Overage |
|---|---|---|
| **Artificial, monthly**, threshold 1,000,000 | (1,500,000 − 1,000,000) × 8% | **40,000.00** |
| **Natural breakpoint**, monthly | (1,500,000 × 8%) − 90,000 = 120,000 − 90,000 | **30,000.00** |
| **Artificial, annual**, threshold 12,000,000 | cumulative 1,500,000 is below 12,000,000 | **0.00** |

5. For the annual case, declare 1,500,000 for **nine** consecutive months. Nothing bills until the
   cumulative total passes 12,000,000 — in month nine, at 13,500,000, the overage is
   (13,500,000 − 12,000,000) × 8% = **120,000.00**, billed all at once.
6. Try a **natural breakpoint** on a lease with **no** base rent. Refused — at zero base rent the
   clause silently means "a percentage of every pound of sales from the first one".
7. Set the **annual** threshold to `1000000` by mistake. The field turns orange and warns you it looks
   like a monthly figure.

**The point.** Three readings of "8% of sales" producing 40,000, 30,000 and 0.00 on the *same*
month's trading. The contract says which. And the annual basis is why a seasonal tenant is not
over-charged for a strong December that a slow February should have absorbed.

---

## Exercise 12 — An option, and an alert that arrives in time to act

**The deal.** *Zara has the right to renew for a further 3 years at a contracted +10%, on notice no
earlier than 12 and no later than 9 months before expiry.*

**Do this**
1. Exercise 1 lease → **Options** tab → **New**.
2. Type `Renewal` · earliest notice = 12 months before the expiry date · latest notice = 9 months
   before it · term months `36` · rent basis `Uplift %` · uplift `10`. Save.
3. Add a **second** option — type `Expansion`, earliest notice **today**, latest **in 30 days**,
   encumbering unit `A-02` — purely so you can watch a live deadline behave.
4. Run the scan by hand: `php artisan leases:scan-option-windows`

**What you should see**

| Look at | What you should see |
|---|---|
| The **days-left** badge | Counting down to the **notice** deadline — not to the expiry. The 30-day one is coloured for urgency |
| The **Projected rent** column | The contracted uplift applied to the rent in force. On a `Market` or `CPI` basis it is **blank** — a valuation and an index feed are not numbers this system may invent |
| Sort order | Soonest deadline first. This table is a work-list |
| Leases list → **Option closing** filter | Finds this lease, because of the 30-day option |
| The unit picker on a new lease | A-02 shows as **encumbered** — a warning, not a block |

5. Try creating an option whose earliest notice is **after** its latest. Refused — an inverted window
   is simultaneously never-open and already-closed, so the scan would announce it lapsed having never
   announced it open.
6. **Exercise** the renewal option (notice date = today). Then open **Lease → Renew**: the modal
   arrives pre-filled with the contracted term and rent, and says where the numbers came from.

**The point, and the reason this feature exists.** The only lease-date alert used to be the expiry
reminder, 90 days out. A notice window closing **9 months** before expiry had already been missed by
six months when that reminder arrived. The system spoke reliably, and always too late — which is
worse than not speaking, because it feels like coverage.

> **The corollary:** *an option not recorded here is an option nothing will ever remind anyone about.*
> The **No options recorded** filter on the leases list finds the contracts nobody has abstracted yet.

---

## Exercise 13 — Renew, and check what came across

**Do this**
1. First give the lease some terms worth carrying: add a **CAM cap** (CAM terms tab) and assign a
   **parking bay** (Premises → Assign).
2. **Lease → Renew.** Term `36`, rent `75000`, service charge `9000`, commencement = the day after
   the current expiry. Read the **expiry preview** before you submit.

**What you should see**

| Look at | What you should see |
|---|---|
| The original lease | Status **Renewed**. Frozen — its Edit form refuses changes |
| The new lease | A **new reference**, status **Active**, linked back to the original |
| Its **escalation clause** | 7%, carried — with its next escalation date armed from **its own** commencement |
| Its **CAM cap** | Carried |
| Its **parking bay** | Carried, at the negotiated rate — but **not** an already-closed end date |
| Its **charge schedule** | Rent 75,000 · service 9,000 · levy 3,750, and the 7% ladder projected across the new term |
| **Units → A-01** | Still occupied — by the renewal now |
| Leases list → **New vs renewal** filter | The new lease answers to *Renewal* |

**The point.** A renewal is a **new contract**, not a longer one. And what comes across is everything
the lease holds except the handful a renewal resets — because the alternative was tried: the service
used to list what to copy, the list fell behind the table, and **14 negotiated terms were silently
dropped on every renewal**, none of which produced an error.

---

## Exercise 14 — Terminate, and read the money

**Do this** *(the quarterly lease from Exercise 6, with its quarter invoice issued at 143,100 and
unpaid)*
1. **Lease → Terminate.** Date = the **last day of next month** — the second month of the quarter ·
   reason · leave **both toggles on**.

**What you should see**

| Look at | What you should see |
|---|---|
| The lease | Status **Terminated**, expiry moved to your date |
| **Charge schedule** | Every row deactivated, ended on that date |
| The quarter invoice | **Still there, not cancelled** — its period *starts before* the termination |
| A new **credit note** | **47,700.00** — one whole unearned month: 47,000 net (40,000 + 5,000 + 2,000) plus 700 VAT (14% of 5,000) |
| The invoice's balance | 143,100 − 47,700 = **95,400.00** still owing — the two months they occupied and traded for |
| **Units → B-01** | **Vacant** |

2. Now consider what the other rule would have done. Had the invoice simply been **cancelled** for
   being unpaid, the landlord would have lost all **143,100** — including two months in which the
   tenant was trading in the shop — and **no credit note would exist anywhere**, because the document
   to credit had been destroyed.
3. Try terminating a different lease on a **mid-month** date. The unearned part of that month is
   prorated by the lease's own proration method — the same rule as Exercise 4.
4. Try a termination date **before** the lease's commencement. The date picker will not offer it.

**The point.** Terminating is a **money** act, not a status change. The rule is the **period**, not
the balance: a period starting after the termination is cancelled; one that straddles it is left
alone and its unearned share credited; one that ended before it stays owing, because that money was
earned.

---

## Exercise 15 — The final account

**Do this** *(same lease. Give it a deposit first: agreed 3 × 40,000 = 120,000, and record a receipt
for the whole 120,000)*
1. **Lease → Final account.** Read the statement before typing anything.
2. Add one **deduction**: *"Damage to shopfront"*, `5000`. Settlement date = today. Settle.

**What you should see**

| Line | Figure |
|---|---|
| Contractual deposit | 120,000.00 |
| Deposit held | 120,000.00 |
| Open AR | 95,400.00 |
| Your deduction | 5,000.00 |
| **Net to tenant** | **19,600.00** |

And the order it happened in:
1. **Arrears netted off the deposit first** — 95,400 applied to the invoice, which now reads settled.
2. **Deductions forfeited** — 5,000 kept by the landlord (liability becomes income).
3. **The remainder refunded** — 19,600.

| Look at | What you should see |
|---|---|
| The quarter invoice | Settled — by the **deposit**, not by a payment |
| The **Deposits** tab | An application, a forfeit and a refund, in that order |
| **Pending true-ups** on the statement | Anything not knowable yet — an unreconciled CAM year, missing sales declarations |
| **History** | A settlement event carrying the **frozen** statement |

3. Try it again with deductions of `200000`. Refused — and **nothing at all happens**. The whole final
   account is one transaction, so a failure cannot leave the deposit already spent while you are told
   it failed.
4. Try a settlement date inside a **closed** accounting period. Refused: a settlement date is a
   posting date.

**The point.** Arrears go first because an unpaid rent invoice is a real document that may already
have reached the tax authority, while a deduction is an assessment you are making right now. And the
statement is frozen because re-deriving it a year later would show today's numbers, not the ones that
were signed.

---

## Exercise 16 — Holdover: the tenant who did not leave

**The deal.** *A-06 (the 15 m² kiosk) was let to Nike for 3 months at EGP 10,000. The term ended last
month. They are still trading.*

**Do this**
1. Create the lease with a **back-dated** commencement — the 1st of four months ago — and a term of
   `3`. Rent `10000`, escalation `None`. Activate it.
2. Leases list → **Holdover** filter. Your lease is there.
3. Run the nightly sweep in **dry-run** first — this is the important bit:
   ```bash
   php artisan leases:expire --dry-run
   ```
4. Now → **Lease → Convert to holdover.** Uplift `150`% (pre-filled), effective from the month after
   expiry, reason *"tenant holding over pending renewal"*.
5. Run the dry-run again.

**What you should see**

| Step | What you should see |
|---|---|
| Before converting | `leases:expire --dry-run` lists it — the sweep **would end this tenancy** |
| The Convert button | Visible **only** because the lease is `active` **and** past its expiry |
| After converting | Rent **15,000.00** — 150% of 10,000 — from the effective date |
| Charge schedule | The 10,000 row closed, a 15,000 row opened. Nothing overwritten |
| **History** | A **holdover** event |
| `leases:expire --dry-run` again | It is **no longer a candidate** — a converted holdover is deliberately excluded, because its expiry is in the past *by design* |
| The **Holdover** filter | Still finds it. Holdover is a **state**, not a status: the lease is still `active` |
| The **Summary** card | Says *in holdover* instead of counting days to expiry |

6. Finally, let one expire without converting: create another short back-dated lease and run
   `php artisan leases:expire` for real. Status → **Expired**, unit → **Vacant**, and the shop can be
   let again.

**The point.** Three things at once. **Nothing auto-converts**, because *"the tenant is still in the
unit"* is a fact only a human knows. **The uplift is a penalty**, priced to make staying worse than
signing. And **the sweep and the conversion are in a race**: leave a holdover unconverted and the
nightly sweep will expire it — which is correct, because an ended tenancy nobody has decided about
should not stay open for ever.
---

# Part 10 — "I clicked and nothing happened"

## 10.1 A button is missing

Three different causes, in the order to check them:

| Check | How to tell |
|---|---|
| **The lease's status** | Most actions want `active` or `pending_approval`. *Final account* wants `terminated`/`expired`. *Convert to holdover* wants a lease past its expiry. *Bill security deposit* wants a shortfall |
| **Your role** | Ask someone with `super_admin` to look at the same screen. If they see it, it is a permission |
| **The module switch** | Settings → Modules. A module switched off removes its screens and actions everywhere, for everyone |

## 10.2 A dropdown is empty

| Cause | Fix |
|---|---|
| **Wrong property selected** *(the usual one)* | Check the switcher |
| The unit picker hides occupied units | Tick **Show occupied units** |
| Assign parking: no free item **on that date** | Change the date, or check the item register |
| A picker narrowed by a parent you have not chosen yet | Choose the parent first |

A picker that genuinely has nothing to offer says so in words — *"No bank accounts — create one
first"* — rather than showing a blank list.

## 10.3 The invoice refused

| Message | What it means | What to do |
|---|---|---|
| **Already billed** | An invoice for that period exists | Look at the Invoices tab |
| **No applicable charges** | The lease has **no charge row covering that period** | Open the Charge schedule. This is the most common real cause |
| **Not billable — status** | Not `active` | Activate it, or accept that a draft does not bill |
| **Not billable — not started** | Period is before commencement | — |
| **Not billable — ended** | Period is past expiry | If they are still there, convert to holdover |
| **Fit-out** | Inside a `gross` rent-free window | Correct behaviour |
| **Off cycle** | Quarterly/annual lease, not a cycle-start month | Bill the cycle start |
| **Run in progress** | The nightly run holds the lock | Wait, retry |
| Outside the billing window | Too far in the past or the future | — |

## 10.4 The save refused

| Message | Cause |
|---|---|
| *"This unit already has an active lease"* | Double-booking. Expansion goes on the **existing** lease as an additional unit |
| *"The lease is frozen"* / terminal immutability | Terminated, expired, cancelled or renewed. Record a correcting act instead |
| *Expiry must be after commencement* | A lease cannot end before it starts |
| *Natural breakpoint needs a base rent* | At zero base rent that clause means "a percentage of every pound from the first one" |
| *A floor above the ceiling* | The ceiling would silently win and your minimum could never happen |
| *Deposit cannot be negative* | Refused rather than clamped, so a typo is reported |
| **The accounting period is closed** | Nothing may be dated into a closed month. Ask accounting to reopen it, or move the date |

## 10.5 A lease bills nothing

Almost always the charge schedule. Run:

```bash
php artisan atriom:audit-charge-schedules
```

It reports three shapes, and the first is silent and expensive:
- **Overlapping rows** — two active rows of the same type covering one period. The lease produces
  **no invoice at all**, rather than billing twice.
- **Gaps** — a month with no rent line.
- **Rows with no start date** — harmless to billing, inconsistent to sort.

Run it before every deploy and after every import.

## 10.6 A number looks wrong

| Symptom | Usual cause |
|---|---|
| The rent on an old invoice is not today's rent | **Correct.** The schedule bills what was in force then |
| A total is smaller than expected | A part month — check the proration method |
| A total is bigger than expected | A quarterly lease bills the whole cycle in advance |
| Percentage rent is 0.00 all year | An **annual** breakpoint the tenant has not reached yet — or a rate nobody filled in |
| A deposit reads as unpaid | The invoice was issued but not **paid**. A receivable is not a holding |
| The figures are for the wrong mall | The property switcher |

---

# Part 11 — The nine rules you must never break

1. **The schedule bills, not the lease.** A lease with no charge rows bills nothing, however tidy the
   rent field looks.
2. **Never edit rent — act on it.** *Change rent* closes one row and opens the next, keeps the levy in
   step, and records who did it and why.
3. **Never type a terminal status.** `terminated` and `renewed` are outcomes of an act that also moves
   money. Typing the word records the word and skips everything else.
4. **Change premises on the action, never on the form.** The action closes an occupancy period;
   editing the field would erase the months the tenant genuinely held the space.
5. **A draft is not a document.** The tenant never sees one and it has no accounting effect —
   *issued* is the line where the ledger, the portal and the tax authority start caring. (Billing
   raises invoices already issued; drafts arrive by other paths, and a credit note starts as one.)
6. **Money records are never deleted** — not even by a super-admin. Cancel, credit-note, void or
   write off. Each leaves a document an auditor can follow.
7. **Rent is VAT-exempt, service charge is not, and the charge code decides — not you.**
8. **A back-dated anything is refused if the accounting period is closed.** That is not an obstacle;
   it is the books protecting themselves.
9. **If a dropdown is empty or a figure looks wrong, check the property switcher before reporting a
   bug.** It is the answer more often than everything else combined.

---

## Where to go next

| You want | Read |
|---|---|
| The developer's account of this module — invariants, past bugs, extension points | [`docs/modules/04-leases.md`](../modules/04-leases.md) |
| How a lease becomes an invoice | [`docs/modules/05-billing-invoices.md`](../modules/05-billing-invoices.md) |
| CAM, in full | [`docs/modules/08-cam.md`](../modules/08-cam.md) |
| Percentage rent and sales declarations | [`docs/modules/09-tenant-sales-percentage-rent.md`](../modules/09-tenant-sales-percentage-rent.md) |
| The whole system on one page | the visual handbook, in the panel at `/admin/handbook` |
| What each screen does, in the panel itself | the **Guide** button on any list screen |

---

*Written 2026-08-25 against the code, not from memory: `LeaseForm`, `LeaseActions`, `LeasesTable`,
`LeaseResource`, `CreateLease`, `EditLease`, `Lease` and its concerns, the thirteen lease services,
`MonthlyBillingService`, `ProrationMethod`, `MarketingLevyService`, `LearningSeeder`,
`routes/console.php`, and `docs/modules/04-leases.md`. Every figure in Part 9 was computed from the
formulas those files use.*
