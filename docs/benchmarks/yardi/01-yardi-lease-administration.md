# 01 — How Yardi Voyager Commercial models a lease

> Everything in this file describes **Yardi**, not Atriom. The Atriom comparison lives in
> [06-atriom-gap-analysis.md](../../gap-analysis/README.md); worked examples live in
> [04-scenarios.md](04-scenarios.md). Confidence markings are explained in the
> [README](README.md#sourcing--confidence): unmarked = stable product knowledge, *(cited)* =
> verified this session, ***(verify)*** = version/edition-sensitive.

---

## 1. The entity hierarchy

Voyager Commercial's data model is deeper than "property → unit → lease". The layers exist because
each answers a different question, and collapsing them is the most common modelling mistake.

```
Company / Entity            the legal owner — whose books are these? (Jawad's SPV)
   └── Property             the mall. Owns the chart segment, the recovery pools, the post month
        └── Building        optional physical grouping (phases, towers)
             └── Space      the leasable box. Code, RENTABLE AREA, USABLE AREA, space class
                            (retail / kiosk / anchor / office / storage / pad), floor, status

Customer / Company          the tenant's LEGAL ENTITY. One customer, many leases, many properties
   └── Lease (tenant rec.)  the occupancy contract for a set of spaces over a term
        ├── Lease–Space     which spaces, WHICH AREA EACH, over WHICH DATE RANGE
        ├── Charges         the date-ranged money schedule  ← the heart of the system
        ├── Amendments      the versioned history of changes to any of the above
        ├── Options         renewal / termination / expansion / ROFR, with notice windows
        ├── Clauses         the abstracted legal terms (use, exclusivity, co-tenancy, radius…)
        └── Retail params   sales categories, breakpoints, reporting/billing frequency

Prospect  →  Deal  →  Lease     the leasing pipeline (Deal Manager)
```

Four consequences worth internalising:

**Customer ≠ Lease.** "Zara" is one customer; the Zara lease in Mall A, the Zara kiosk in Mall B
and the expired Zara lease in Mall A are three lease records under it. Credit exposure, AR
statements and disputes are naturally *customer*-level while billing is *lease*-level.

**Space carries area, and the lease–space link carries the area actually let.** A 900 m² shop can
be let as 900 m² to one tenant or split. Recovery denominators, percentage-rent breakpoints and
the rent roll all read area from here, so area is a first-class, date-ranged, auditable number —
not a display column.

**The lease–space link is date-ranged.** That is how expansion and contraction work without a new
lease: space `A-14` is added to the lease effective 01/07/2027; space `A-09` is dropped effective
30/06/2027. The lease record is continuous; the premises change under it.

**Prospects and deals are inside the same system**, so a space's future is visible: leased through
2028, then an executed deal starts 2029, then a live negotiation for 2032.

---

## 2. The lease record

### 2.1 Identity & classification

| Field | Meaning / why it exists |
|---|---|
| Lease code / tenant code | `t0001234`. Stable for the life of the tenancy, *through* renewals and amendments |
| Customer | The legal entity. Guarantor and parent-company links hang here |
| Lease type | new · renewal · expansion · relocation · **holdover** · month-to-month · assignment · sublease |
| Lease status | Prospect → Applicant → **Future** → **Current** → **Notice** → **Past** *(cited: "lease status" is a core Lease Administration field)* |
| Property / spaces | With the area let per space |

**Status vs type is not redundant.** *Type* says what kind of deal this is (which drives leasing
commission, deal reporting and whether it consumed an option). *Status* says where it is in time
(which drives occupancy, billing and the rent roll). Atriom collapses both into one `status` enum,
which is why "renewed" has to be a *status* there — a category error the schedule model avoids.

### 2.2 The dates — Voyager keeps six, not two

This is where most home-grown systems are wrong, because in practice these dates diverge and each
drives something different:

| Date | Drives |
|---|---|
| **Sign / execution date** | when the contract becomes binding; deal reporting; commission |
| **Possession / handover date** | when the tenant gets the keys and fit-out starts; the landlord's obligation clock |
| **Rent commencement date** | when RENT starts billing — usually *after* possession by the fit-out period |
| **Lease commencement date** | when the *term* starts counting (drives expiration and option windows) |
| **Expiration date** | contractual end of term |
| **Move-in / move-out (occupancy) dates** | physical occupancy — drives occupancy %, and it is *not* the same as the lease dates |

The gap between **possession** and **rent commencement** is the fit-out/rent-free period, and it is
a *date fact*, not a counter. The gap between **expiration** and **move-out** is holdover. A system
with only `commencement_date` and `expiry_date` has to encode both of those as something else —
which is exactly what Atriom's `fit_out_months` integer and its holdover *alert* are.

### 2.3 Area & measurement

Rentable area, usable area and a load/common-area factor are all held, plus a measurement standard
(BOMA and, in the Commercial Suite, mixed-use office/ground-floor-retail calculations via Floorplan
Manager *(cited: Spring 2026 release)*). Area is date-ranged with the lease–space link, so a
mid-term expansion re-bases every per-m² calculation from its effective date forward and leaves the
prior period's numbers intact.

---

## 3. Charges — the heart of the system

### 3.1 Charge codes (global setup)

A **charge code** is a configured setup record, not a free-text label. Each one carries:

- code + description (`RENT`, `CAM`, `CAMEST`, `RETAX`, `INS`, `MKTG`, `PCTRENT`, `UTIL`, `PARK`,
  `LATE`, `NSF`, `DEP`, `WRTOFF`)
- **the GL account it credits** — this is the charge-code→revenue mapping, and it is *data*
- tax treatment (taxable / exempt / which tax code)
- category / type, including whether it is a **deposit** code (→ posts to a liability, enters the
  deposit register) or a **recovery** code
- whether it is **recoverable** (i.e. may be included in a recovery pool) and whether it is
  excluded from percentage-rent deductions
- **application priority** — the order a receipt consumes open charges ***(verify)***

*(cited: "Charge Codes control how rent and other charges are billed. Each charge type — base rent,
CAM estimate, tax estimate, insurance estimate — requires a configured charge code before you can
enter the corresponding lease charges.")*

**Why this matters:** adding a new billable thing in Voyager is a *configuration* act by an
accountant — new charge code, point it at a GL account, done. In Atriom it is a code change:
`InvoiceItemType` gains a case and `InvoiceJournalizer::REVENUE_ROLE` gains a hard-coded mapping
([`InvoiceJournalizer.php:19`](../../../app/Services/Accounting/Journalizers/InvoiceJournalizer.php#L19)).

### 3.2 Lease charges — the date-ranged schedule

A lease charge row is: **charge code · amount · from date · to date · frequency · basis · prorate
flag · (optional) GL account override**.

*(cited: "Each charge record needs: start date (typically rent commencement), end date (lease
expiration), amount, and charge code.")*

The rows are **many-per-code**. A five-year lease with 7% steps is five `RENT` rows:

| Code | From | To | Amount / month |
|---|---|---|---|
| RENT | 01/01/2026 | 31/12/2026 | 100,000 |
| RENT | 01/01/2027 | 31/12/2027 | 107,000 |
| RENT | 01/01/2028 | 31/12/2028 | 114,490 |
| RENT | 01/01/2029 | 31/12/2029 | 122,504 |
| RENT | 01/01/2030 | 31/12/2030 | 131,079 |
| CAMEST | 01/01/2026 | 31/12/2030 | 8,400 |
| MKTG | 01/01/2026 | 31/12/2030 | 5,000 |

*(cited: "Rent Schedule within the lease record stores the stepped rent over the full lease term.
This is distinct from the single 'current rent' field — the full schedule drives future billing
automatically.")*

**Basis.** An amount can be a flat monthly figure or **rate × area** (`$/sf/yr`, or `EGP/m²/yr` in
our terms). When it is rate-based, an area change re-derives the money automatically. This is why
Voyager can answer "what is the average rent per m² in this mall" without a spreadsheet.

**Frequency.** monthly · quarterly · semi-annual · annual · one-time, with the billing anchored to
the schedule row, not to a global run day.

### 3.3 Posting charges

*(cited: "Voyager applies lease charges only when you post monthly charges.")*

The monthly posting run, per property, per **post month**:

1. Reads every lease charge row whose date range covers the period, on every lease that is
   `Current` (or `Future` and commencing this period).
2. Prorates the first and last partial months where the charge is flagged to prorate.
3. Produces a reviewable **batch** — a list of proposed charges the accountant can inspect, edit
   and delete *before* it commits.
4. On post: creates one **charge transaction per charge code per lease per period**. Each is an
   **open receivable** in its own right, with a due date and a GL entry `Dr AR / Cr <the charge
   code's revenue account>`, plus tax.

Three things to note. **The batch-before-commit step** is the control an operator expects and is
where they catch a mis-keyed escalation before it reaches 400 tenants. **The charge, not the
invoice, is the AR atom** — see [02-yardi-money-flow.md §2](02-yardi-money-flow.md#2-what-a-charge-actually-is).
**Posting is per-property**, so one mall can close while another is still billing.

### 3.4 Invoices vs charges

Voyager Commercial *can* produce a tenant invoice or statement — Correspondence Management merges
lease data into a formatted invoice or statement and mails/PDFs it *(cited)* — but the invoice is a
**document rendered over charges**, not the accounting record. The receivable is the charge.

Atriom inverts this: the invoice **is** the record. See the README's "one deliberate difference to
keep" — the inversion is correct for Egypt, and the cost of it is quantified in the gap analysis.

---

## 4. Escalations

Voyager holds an **escalation schedule** on the lease, per charge code, with:

- **method** — fixed %, fixed amount, **index/CPI**, or market review
- **index source** for CPI, with a publication lag and a base index value
- **floor and ceiling** on the increase (e.g. "CPI, but not less than 3% and not more than 8%") —
  the norm in real leases, and the reason a bare `escalation_rate` is not enough
- **compounding** vs applied to the original base
- **effective date and frequency** (usually each anniversary)

*(cited: the Commercial brochure lists "Renew/Escalations" alongside "Straight-line Rent" as core
lease-management functions.)*

**The critical behaviour: escalation *generates future charge rows*.** It does not overwrite the
current amount. The 2027 row is created and sits in the schedule; the operator can see it, review
it, and correct it before it ever bills. Because the schedule is complete, the straight-line
calculation and the revenue forecast are both computable the day the lease is signed.

---

## 5. Amendments — the versioned lease

Every mid-term change is an **amendment** against the same lease record:

*(cited: "All lease modifications such as expansion, contraction, relocation, holdover or renewals
are stored with the lease details for easy drill-down and historical reference.")*

| Amendment | What it changes |
|---|---|
| Expansion | adds a space (and its area) from an effective date; opens new/adjusted charge rows |
| Contraction | ends a space from an effective date; closes charge rows or reduces them |
| Relocation | ends one space, starts another, same tenancy |
| Renewal / extension | extends the expiration and appends new charge rows |
| Holdover | converts to month-to-month at a holdover rent (typically 125–200% of the last rent) |
| Rent modification | closes the current charge row, opens the new one, with a **reason** |
| Assignment / sublease | changes the customer or adds a subtenant, preserving the chain |

The amendment carries an effective date, a document reference and a reason. The lease's state at
any past date is therefore reconstructible — which is what an auditor, an owner dispute and a
straight-line recalculation each need.

**The renewal question.** Voyager can do a renewal either as an amendment (same lease code, extended
term) or as a new lease record, and shops differ ***(verify — it is configurable and both patterns
are in the field)***. Atriom's choice — a new lease row chained by `previous_lease_id` — is
defensible and arguably cleaner for a mall where a renewal is genuinely a new negotiated contract.
**Atriom's real gap is not renewal; it is that there is no amendment concept at all**, so a
mid-term expansion or rent modification has nowhere to live except `leases.notes`.

---

## 6. Options & critical dates

A commercial lease is a bundle of options, and options are money.

| Option type | Fields that matter |
|---|---|
| **Renewal / extension** | notice window (earliest and latest notice date), term, **rent basis** (fixed / fixed % uplift / market review / CPI), number of options remaining |
| **Termination (break)** | window, **penalty** (often unamortised TI + commission + N months' rent), notice period |
| **Expansion / contraction** | which space, window, rent basis |
| **ROFR / ROFO** | which space, response window |
| **Purchase option** | price basis, window |

Two behaviours make these real rather than decorative:

**Critical-date alerting.** Voyager surfaces lease expirations, **option notice dates** and tenant
insurance expiry on dashboards and in automated notifications *(cited)*. The alert fires ahead of
the *earliest* notice date, not the expiry — because by the expiry it is too late.

**Encumbrance.** *(cited: "Lease Option management with encumbrance tracking.")* If tenant A holds
an expansion option over space `B-12`, `B-12` is **encumbered** — the leasing team is warned before
they commit it to tenant B. Without this, a mall double-promises space and finds out in a lawsuit.

**The economics, if it is missed:** a renewal option at a fixed 5% uplift, unexercised because the
notice window passed unnoticed, converts a five-year known-rent tenancy into a holdover at
whatever the parties can agree — or a vacancy plus fit-out plus a leasing commission. This is the
single highest-value cheap feature in this whole benchmark.

---

## 7. Clauses & lease abstraction

The lease abstract is a structured record of the legal terms that don't reduce to money:

use clause · **exclusivity** (no competing use in the centre) · **radius restriction** (no second
store within N km — a mall staple, and it interacts with percentage rent) · **co-tenancy**
(rent abates if the anchor leaves or occupancy drops below X%) · **kick-out** (landlord or tenant
may exit if sales miss a threshold) · assignment & subletting consent · insurance requirements &
limits · operating-hours and continuous-operation covenants · signage · parking allocation ·
landlord/tenant repair obligations · guarantor.

Yardi's **Smart Lease** now abstracts these with AI/OCR directly into Voyager fields with
source-linked citations back to the PDF *(cited: Spring 2026)*.

**Why an ERP should care:** co-tenancy and kick-out clauses are *contingent money*. A co-tenancy
trigger abates rent automatically in a well-run system. In Atriom these clauses live only in the
uploaded PDF, so nothing can act on them and nothing can even report "how many of our leases have
a co-tenancy trigger tied to the anchor we are about to lose".

---

## 8. Deal Manager — the pipeline before the lease

*(cited: Spring 2026 — milestone dashboard, pipeline visibility, filters by assignee/property/date,
benchmarking at space/property/market level, listing agreements with commission tracking.)*

Prospect → tour → proposal/LOI → negotiation → executed lease, with:

- **deal comparison** against budget and market rent, so a leasing manager can see what a deal costs
  in free rent + TI + commission versus what the budget assumed
- **net effective rent** — the number that actually compares two deals, after abatement and TI
- **commission tracking** on the listing agreement
- conversion into the lease record with the deal's terms carried forward

Atriom has no pipeline: a lease starts at `draft`. For a single stabilised mall that is survivable;
for an operator leasing a second mall from scratch, the pipeline *is* the job.

---

## 9. Lease costs: TI allowances & commissions

Tenant-improvement allowances and leasing commissions are **capitalised lease costs** in Voyager,
amortised over the lease term with their own GL treatment, and — critically — their **unamortised
balance** is the number that goes into a termination-option penalty or an early-termination
settlement. They also feed net-effective-rent comparison in Deal Manager.

Atriom has neither concept. If Eltizam pays for a tenant's fit-out or pays a broker, it is an
`Expense` with no link to the lease that justifies it and no amortisation.

---

## 10. The reports this model makes possible

These are not "nice to have"; in a commercial shop they are the daily instruments, and each one is
a straight aggregation of the schedule model above:

| Report | What it is |
|---|---|
| **Rent roll** | one row per lease/space at a point in time: tenant, space, area, term dates, current rent, rent/m², recovery charges, options, security deposit. **The single most-used commercial report there is.** |
| **Lease expiration schedule** | expiries by year with area and rent at risk — the renewal work-list and the vacancy forecast |
| **Stacking plan** | the building drawn as occupancy by floor, colour-coded by expiry |
| **Occupancy / vacancy & absorption** | area let vs GLA over time |
| **Aged receivable by charge code** | who owes what, *and for what* — rent arrears read differently from a disputed CAM true-up |
| **Tenant statement** | the customer-level account: charges, receipts, credits, running balance |
| **Sales & occupancy cost** | MTD / YTD / **MAT** sales, and total occupancy cost as a % of sales *(cited)* — the number that tells you a tenant is about to fail |
| **Straight-line rent schedule** | billed vs recognised vs deferred, per lease per month |
| **Forecast** | lease-by-lease revenue projection including speculative renewals and re-lets *(Forecast Manager, cited)* |

**Atriom has none of the first three, and no rent roll at all** (verified: no rent-roll report
exists in `app/`; the term appears only in `docs/OPEN-QUESTIONS.md` and the discovery
questionnaire). For a mall operator that is a conspicuous hole, and once a rent schedule exists it
is a day of work.

---

## Sources

- [Yardi Commercial Suite brochure](https://resources.yardi.com/documents/commercial-suite-brochure/) — recoveries, straight-line rent, options, critical dates
- [YARDI VOYAGER Commercial Property Management Software (lease-management architecture)](https://silo.tips/download/yardi-voyager-commercial-property-management-software) — entity hierarchy, amendments, escalations, retail parameters, ROFR/ROFO, encumbrance
- [What's new in the Yardi Commercial Suite: Spring 2026](https://www.yardi.com/blog/commercial-product-update-2026-q1/) — Smart Lease, Deal Manager, Forecast Manager, Floorplan Manager
- [Smart Lease — AI lease abstraction](https://www.yardi.com/product/smart-lease/) and [Yardi's abstraction blog](https://www.yardi.com/blog/commercial-lease-abstraction-software/)
- [Adding a recurring lease charge in Voyager](https://affinityproperty.happyfox.com/kb/article/82-adding-in-a-reoccurring-lease-charge-in-voyager/) — charge fields, prorated move-in
- [Yardi Voyager Charge Procedures](https://yardi.westcliff-group.com/voyager/Help/Residential%20User's%20Guide/Charge%20Procedures.html) — "Voyager applies lease charges only when you post monthly charges"
- [Importing lease data into Yardi Voyager](https://lextract.io/resources/articles/import-lease-data-yardi-voyager) — Lease Administration fields, rent schedule vs current rent, charge record fields
- [Yardi Smart Lease guide](https://www.bcsolut.com/resources/yardi-smart-lease-guide) — amendment matching to a parent lease with field-level overrides
