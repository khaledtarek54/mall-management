# Demonstrating Atriom — a 30-minute run for anyone who is not an accountant

> **Companion, not a duplicate.** The accountant has their own run at
> [accounting/ACCOUNTANT-DEMO.md](accounting/ACCOUNTANT-DEMO.md) — that one is about being unable to
> break the books. This one is for **your team, Eltizam, an owner, or anyone being shown the system
> for the first time**, and it is about a single question: *does one thing that happens in a mall
> travel all the way through?*
>
> **Every figure below was read off the demo data on 2026-08-20**, after
> `php artisan migrate:fresh --seed`. Reseed and the numbers move — read the new ones off the
> screen, do not quote these.

---

## The one sentence to open with

> *"Eltizam operates malls that Jawad owns, and retailers rent the shops. Everything in here is one
> of those three parties doing something — and the system's job is that nobody has to re-type it
> anywhere else."*

Do not open by listing modules. There are dozens and nobody remembers a list.

---

## 0 · Before they arrive (3 minutes)

```bash
php artisan migrate:fresh --seed     # canonical demo data — a mall mid-life
php artisan billing:reconcile        # expect: ✓ Books tie out — all checks passed
```

Log in at `/admin` as `admin@mall.test` / `password`, and **select Atriom Walk** in the property
picker. Have the handbook open in a second tab at `/admin/handbook`.

Know these five numbers, because they are the ones people ask for:

| | |
|---|---|
| Units / occupied | **50 / 35** on Atriom Walk (70% occupancy) |
| Leases · tenants | **33** leases · **39** tenants |
| Invoiced · collected | **11,178,784.59** · **9,701,959.05** EGP |
| Outstanding AR | **1,476,825.54** EGP |
| Journal entries | **697**, debits = credits, difference **0.00** |

---

## 1 · The shape of the thing (3 min)

Open `/admin` and let them read the left-hand nav rather than narrating it:

**Leasing · Receivables · Payables · General Ledger · Operations · Facility · Inventory & Assets ·
HR & Payroll · Marketing · Settings**

Say: *"It is organised the way the money moves, not the way the software was built."*

Then point at the **property picker** at the top and switch malls once. Everything on screen
changes. Say: *"Every screen in the system is scoped to one mall. There is no way to see a number
from another property by accident."*

**Why it lands:** the first fear anyone has about a multi-property system is that the numbers are
mixed together.

---

## 2 · One shop, end to end (10 min — the centrepiece)

This is the whole demo. Everything else is optional.

**Leasing → Leases.** Open any active lease. Show them, in order:

1. **The terms** — rent, the escalation ladder, the term dates. Say: *"This was typed once."*
2. **The charge schedule** — rent, service charge, marketing levy. *"These are what will be billed,
   every month, without anybody deciding to."*
3. **Receivables → Invoices**, filtered to that tenant. The invoices the schedule produced.
4. Open one invoice → **the lines**, the VAT, the balance.
5. **Receivables → Payments.** Open a payment → the invoices it settled.
6. **General Ledger → Journal Entries**, filter by source. **The same invoice, as a journal entry.**

Say at the end: *"Six screens, one chain, and I typed nothing. The lease produced the invoice, the
invoice produced the ledger entry, and the payment closed both. If any one of them disagreed with
the others, `billing:reconcile` would say so."*

**Why it lands:** everybody has used a system where the finance team re-keys what the leasing team
already entered.

---

## 3 · The other direction: something breaks (7 min)

The operational half, and the part that is newest.

**Operations → Tenant Requests.** Open one. Say: *"A tenant reports a problem from their phone."*

**Facility → Work Orders.** Open **CM-AW-202608-0001** (the corrective lift job):

- **The trade** — *"'Elevator' is a record, not a word somebody typed. It decides who can be sent,
  what an hour costs, and what a contractor may spend without asking."*
- **The proposals tab** — the contractor quoted **10,000**; then found more on strip-down and
  submitted a supplementary for **7,000**. Both approved. Say: *"Approving the second one IS the
  authorisation to spend more — the ceiling moved to 22,000 because somebody decided it did, and it
  is recorded who."*
- **The costs** — labour, parts off the shelf, and the contractor's invoice: **17,280 actual against
  17,000 estimated.**

Then open **WO-AW-202608-0002** and show the opposite:

> **Authorised 10,000. Invoiced 12,500. Over by 2,500** — and nobody approved it.

Say: *"The system does not block the invoice, because the work is done and the contractor is not the
one who got it wrong. It makes the overspend visible and attributable on the day, instead of six
months later in the P&L."*

Finish the loop: **Payables → Vendor Bills**, open that bill, and show it is **filed against the
work order** and posted to the ledger. *"The job knows what it cost, and the books already had it."*

**If they bank in more than one place** — and an Egyptian operator usually does — add ninety
seconds here. **Finance → Payments**, open the **Bank account** filter and pick **CIB — operating
account**. The list narrows; toggle the **Bank account** column on and the two banks are visible per
receipt.

> *"The demo mall banks in two places. Each account posts to its own line in the chart, so when the
> CIB statement arrives the reconciliation only offers CIB's postings. Point both at one account and
> it offers the other bank's — the statement still balances, and the match is wrong. A wrong match
> marks money as verified, which is worse than not reconciling."*

Leave the ones that say **"The rail decides"** alone if asked — that is a receipt where nobody named
an account, and the payment rail answers, exactly as it did before any of this existed.

---

## 4 · Three things the system notices on its own (5 min)

These are the ones people remember, because no one had to ask.

**a) The fault that keeps coming back.** Facility → Work Orders, find the *"Shopfront lighting
circuit tripping"* jobs. Three visits to one shop in three weeks, the first two closed **"no fault
found"**. The third is flagged **repeat visit — 2 prior**.

> *"Without that flag this reads as three successful jobs. It is one unfixed fault and three
> invoices."*

**b) Planned work that slipped.** Facility → Service Plans. The compliance column:

| Plan | Compliance |
|---|---|
| Monthly fire-safety inspection | 85.7% |
| HVAC filter & coil service | 77.8% |
| Generator monthly test-run | 71.4% |
| Chiller annual overhaul | 66.7% |
| **Elevator quarterly maintenance** | **60%** |

> *"That is the share of planned visits that happened by the date they were due. The lift plan is
> the one slipping, and nobody had to compile a report to find that out."*

**c) A safety permit nobody closed.** Facility → Permits to Work. Three permits: one live now, one
closed properly, and **one issued two days ago whose window has passed with no sign-off**.

> *"That last one means somebody authorised electrical isolation and no one recorded that the work
> stopped and the board was made safe. There is deliberately no 'expired' status — expiry is a fact
> about the clock, and flipping it automatically would quietly close the exact question this
> register exists to ask."*

---

## 5 · It explains itself, in both languages (2 min)

Two things to show, then stop talking:

1. **The guide button** on any list screen — *purpose · steps · what it affects elsewhere · rules*.
   All **110 screens** have one. Say: *"Nobody has to be trained screen by screen. The 'affects'
   line is the one that matters: what moves somewhere else when you touch this."*
2. **The language switch.** Flip to Arabic. The whole panel, including the guides, the activity log
   and the handbook.

**Why it lands:** it answers "what happens when the person who set this up leaves" without anybody
having to ask it out loud.

---

## 6 · Be straight about what is not done (2 min)

Say this before they find it, every time:

- **The e-invoicing (ETA) connection is a mock.** It is built and it is not switched on to the real
  authority.
- **The online payment gateway is off.** Paymob is integrated and disabled.
- **The chart of accounts is a starting template** — the accountant's real Egyptian chart replaces
  it, and that is a data change, not a code change.
- **The contractor self-service portal is designed, not built** — contractors are handled by
  operator staff on the dashboard today.

Then: *"None of those are code problems. They are decisions and credentials."*

**Why it lands:** every demo has a hole, and naming yours first is the difference between a
limitation and a discovery.

---

## Adapting the run

| Audience | Cut | Add |
|---|---|---|
| **Your team** | §6 | Let them drive §2 themselves; make them find the guide button |
| **Eltizam / operations** | §2 down to two screens | All of §3 and §4 — the operational spine is what they buy |
| **An owner (Jawad)** | §3, §4 | General Ledger → **Owner Statements**: what their property earned and what it cost |
| **An accountant** | *this whole run* | Use [ACCOUNTANT-DEMO.md](accounting/ACCOUNTANT-DEMO.md) instead |
| **15 minutes only** | §4, §5 | §1 → §2 → §6, nothing else |

---

## If they ask something you cannot answer

Open the handbook at `/admin/handbook` in front of them and find it. It is bilingual, it is built
from the code by `npm run build`, and looking something up in it is a better answer than a confident
guess — it also demonstrates that the documentation is current, which is itself the claim.

Deeper detail, in order of depth: [OVERVIEW.md](OVERVIEW.md) → the module's own
[modules/NN-*.md](modules/README.md) → [BUSINESS-RULES.md](BUSINESS-RULES.md).

## What to do with what they say

Write down anything they name that the system does not do, and put it in
[OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) or [ROADMAP.md](ROADMAP.md) the same day. A demo's real
output is the list of things somebody expected and did not find.
