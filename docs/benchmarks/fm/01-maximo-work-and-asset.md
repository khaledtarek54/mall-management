# IBM Maximo — the work-and-asset yardstick

> **What this file is for.** Maximo is where the industry's vocabulary for maintenance work comes
> from — job plan, work order hierarchy, failure class, PM route, storeroom issue, craft. More
> importantly for Atriom, it is the reference implementation of **the work order as a cost object**,
> which was the structural idea the facility module was missing on the day this file was written.
> It stopped being missing that same day.
>
> Read §4 first if you read only one section. Everything else in this file exists to feed it.

> ### ✅ THIS IS THE YARDSTICK THE CLOSE-OUT WAS BUILT TO — re-verified against the code 2026-09-01
>
> This file was written on **2026-08-20** (`cd974289`) and the seven-step facility close-out was
> built off it over the following hours of the same day — trade as a row (`0eb40a96`), the cost
> object (`15875885`), PM compliance (`51ee5a12`), failure codes and repeat visits (`4de1ff38`),
> not-to-exceed and proposals (`556e1fe3`), routes and planned cost (`507ae51c`). The yardstick was
> never revised afterwards, so its own preamble went on describing the cost object as *"the
> structural idea the facility module is currently missing"* about a system that had had one since
> the afternoon the sentence was written.
>
> **The Maximo material below is unchanged and is still the standard to measure against**, and so are
> the handful of sentences in it that describe what Atriom lacked — *"routes are the second idea
> Atriom does not have"* is left standing in §6, because it is the case that was made and the
> reasoning is what the build had to respect. What is new is a **SHIPPED** note under each section
> that Atriom answered, naming the class or table that answered it and, where Atriom deliberately
> does something else, saying so as a stated deviation rather than leaving it to be re-derived.
> **Read the note before acting on the paragraph above it.** The eight situations in
> [fm/03-scenarios.md](03-scenarios.md) carry the per-scenario evidence and
> [gap-analysis §4](../../gap-analysis/README.md#4-facility-vendors-assets--vs-the-fm-standard) the
> per-step evidence; [modules/26](../../modules/26-facility.md) is the built module's own document.
>
> **This is not a "everything closed" notice**, and what remains is stated under the section it
> belongs to rather than collected somewhere a reader would have to go and find. The ones worth
> knowing before reading on, each re-verified by grep today: the PM **lead time** of §6; the
> **warranty window**, and meters on the machine rather than on the plan, of §2; the
> **classification and specification attributes** of §1; the **permit-requirement inheritance** of
> §9; **cost roll-up** across a parent machine or a location (§1, §4.4, §11); and the one hole in
> §8's storeroom invariant, where an outside purchase entered as both a part and a supplier's bill
> is counted twice on the job.

---

## 1. The entity hierarchy

```
Organization            currency, GL account structure, base calendar
  └── Site              the operating unit — work orders and assets belong to ONE site
        ├── Location    a hierarchy AND a network; the place work happens
        │     └── Asset a hierarchy; the thing work is done TO
        └── Storeroom   a location subtype that holds stock balances
```

**Location and asset are separate on purpose, and both carry cost.** A location is *where* — a
plant room, a floor, a shop. An asset is *what* — the chiller, the escalator, the fire panel. An
asset sits in a location and can move between them; the location keeps the cost of everything that
ever happened there, the asset keeps the cost of itself wherever it went. That is what makes
"what does this shop cost us to maintain" and "what does this chiller cost us" two different
questions with two different answers.

**Locations form a hierarchy *and* a network.** The hierarchy is containment (mall → floor → plant
room); the network is connection (this panel feeds that riser). Failures propagate along the
network, not the hierarchy. *(verify — network/system modelling is configuration-sensitive.)*

**Assets form a hierarchy with roll-up.** A parent asset's cost includes its children's, so an
"AHU-01" made of a fan, a coil and a VSD reports one number and three. Maximo maintains both the
parent link and a denormalized top-level ancestor for reporting.

**Classification instead of columns.** Rather than a column per attribute, an asset is given a
*classification* (e.g. `HVAC / CHILLER / WATER-COOLED`) and the classification carries an attribute
set — tonnage, refrigerant, voltage — stored as specification rows. This is how one asset table
serves chillers, escalators and fire dampers without 200 nullable columns. *(cited — the
Classifications and Specifications applications are documented in Maximo's product help.)*

> **PARTLY SHIPPED, and the two halves went different ways.** The **hierarchy** is real:
> [`Equipment`](../../../app/Models/Equipment.php) carries `parent_id` so a machine has sub-codes
> (`ESC-01` → `ESC-01-MOT`), with `selfAndDescendantIds()` for the walk and a saving-time guard
> against a cycle, and the *where* half is split from the *what* half by `asset_id` (the mall),
> `unit_id` and a free-text `location`. **Classification is the trade** — one taxonomy classifies
> work orders, service plans and machines alike, which is the same reasoning that keeps a second
> `category` column off all three (§4 note).
>
> **Specification attributes are NOT built** — re-verified 2026-09-01. A machine has fixed columns
> and `notes`; there is nowhere to record a chiller's tonnage or refrigerant as a queryable value.
> `Equipment` is deliberately absent from [`CustomFields::EXTENSIBLE`](../../../app/Support/CustomFields.php),
> whose registry is explicit by design rather than derived from *"has a metadata column"*, so this
> is an undecided extension and not an oversight being enforced. The D-7 machinery — definition
> rows, the typed filter, the export column, the search blob — would give this the smallest possible
> implementation the day someone decides it.
>
> **Roll-up is not built either.** The machine register sums `act_total_cost` over a machine's
> **own** work orders, so `AHU-01` excludes its fan, coil and VSD despite `selfAndDescendantIds()`
> existing for exactly that walk, and no surface answers *"what does this shop cost us to maintain"*
> — the work-order register filters by trade, type, execution type, status, priority and SLA, and by
> nothing that names a place. See §11.

---

## 2. The asset record

| Field group | What it holds | Why it matters |
|---|---|---|
| Identity | asset number, description, parent, location, site | |
| Status | `OPERATING` · `NOT READY` · `DOWN` · `DECOMMISSIONED` | Status is **operational**, not a soft-delete. "Down" is the state that drives availability and MTBF |
| Criticality / priority | a ranked value | Feeds work-order priority and PM scheduling |
| Rotating item | a link to an *item* in the storeroom | The idea that lets a spare pump be stock today and an asset tomorrow, keeping its history through both |
| Meters | one or more, with readings | Feeds usage-based PM and condition monitoring |
| Spare parts | the item list for this asset | Drives reservations when a job plan is applied |
| Warranty / contract | dates and vendor | So a job on a covered asset does not get billed to the operator |
| Costs | YTD and lifetime totals, rolled up from work orders | **The reason the cost object matters** |
| Failure class | the root of its failure hierarchy | See §7 |

**Asset status is not deletion.** Maximo does not delete an asset that has history — it is
decommissioned, and every past transaction still points at it. *(This is the same rule Atriom
already enforces through `#[DeletableWhenUnused]`.)*

> **Four of these nine rows are answered.** Identity, criticality and failure class are on
> [`Equipment`](../../../app/Models/Equipment.php) — `criticality` is read by
> `defaultWorkOrderPriority()`, which sets the priority a fault on that machine **starts** at and
> never overrides an operator who was explicit, and the failure class is the machine's `trade_id`
> (§7). Costs are the §4 figure summed onto the register, and `fixed_asset_id` links the machine to
> its balance-sheet record, which is where the *replace* half of a repair-or-replace decision would
> come from — the link exists, though the register itself shows only the repair side.
>
> **The parenthetical above is not right about this table, and the difference is worth having.**
> `Equipment` is `#[DeletionAllowed]`, not `#[DeletableWhenUnused]`: soft-delete **is** its
> retirement path, exactly as it is for `UtilityMeter` and `FixedAsset`, and the plans and jobs that
> reference a retired machine go on pointing at it. What the model does refuse is a **move** — a
> machine carrying sub-codes, plans or job history cannot be re-homed to another property, because
> the plan would be permanently invalid and the history would claim a machine that was never in that
> mall.
>
> **The other five rows have no counterpart, re-verified 2026-09-01.** There is no operational
> status machine — a machine is `is_active` or not, so *"DOWN"* as a state that drives availability
> and MTBF does not exist. There is **no warranty window**: grep finds no expiry date and no covering
> vendor on either `Equipment` or `FixedAsset`, so a corrective job on a machine still under warranty
> is costed and paid like any other — one of the four questions
> [`FailureCode`](../../../app/Models/FailureCode.php)'s own docblock promises the codes make
> answerable. **Meters hang off the PLAN, not the machine**: usage-based maintenance points at a
> `utility_meters` row from the service plan, so a machine does not carry its own readings. And
> there is neither a **rotating item** — the idea that lets a spare pump be stock today and an asset
> tomorrow — nor a per-machine spare-parts list, because a part belongs to a job here. All five are
> open; none is declined.

---

## 3. Job plans — the reusable method

A **job plan** is the template for how a piece of work is done, and it is where the *planned* half
of the cost comes from. It carries:

- **Tasks** — an ordered list, each becoming a child row on the work order
- **Labour estimates** — craft, skill level, quantity of people, estimated hours → estimated cost
  via the craft's rate
- **Material estimates** — item, quantity, storeroom → estimated cost via the item's average cost
- **Service estimates** — a purchased service line
- **Tool estimates** — with hours
- **Safety plan** — hazards, precautions, lock-out/tag-out, permits (§9)

**Job plans are revisioned, not edited.** A job plan in use is superseded by a new revision; work
orders created before the change keep the method they were actually planned against. *(cited —
job plan revision is a documented Maximo capability.)* This is the same reasoning that makes
`charges` date-ranged in Atriom rather than mutable.

**The job plan is the bridge between "what we intend to spend" and "what we spent."** Applying it
to a work order copies the estimates onto that order; reporting labour, issuing parts and receiving
services fills in the actuals beside them. A CMMS that has tasks but no estimates can tell you what
was done and never whether it cost what it should have.

> **SHIPPED 2026-08-20 — Atriom's job plan is the `ServicePlan`, and it stopped being tasks-without-
> estimates in `2026_08_20_600000_routes_and_planned_cost`.** A plan carries `est_labour_hours`,
> `est_material_cost` and `est_service_cost` beside its `checklist`, and
> [`GeneratePreventiveWorkOrdersService`](../../../app/Services/GeneratePreventiveWorkOrdersService.php)
> applies it exactly as this section describes: each checklist line becomes a work-order item, the
> three estimates are copied onto the order, and the **hours are turned into money at the trade's
> rate at generation** — so the planned figure is comparable with the actual one §4 fills in beside
> it. There is no safety plan on the template; see §9.
>
> **A plan is edited, not revisioned — a stated deviation.** Maximo supersedes a job plan in use so
> that orders keep the method they were planned against; here a plan is a live row and changing it
> changes the next cycle. What protects the history is that the order is a **copy**: an order
> already generated keeps the checklist and the estimates it was raised with, and
> `FacilityWorkOrderLabour` freezes the craft rate at entry, so re-pricing a trade next year cannot
> restate last year's jobs. What is lost is the ability to say *which* revision of the method a job
> two years ago was planned against.

---

## 4. The work order **is the cost object**

This is the section everything else serves.

### 4.1 The record

| Group | Fields |
|---|---|
| Identity | WO number, description, **parent WO**, site, class (`WORKORDER` / `ACTIVITY` / `CHANGE` / `RELEASE`) |
| What & where | asset, location, GL account, classification |
| Type | `PM` preventive · `CM` corrective · `EM` emergency · `CP` capital project · others configurable |
| Status | see §4.3 |
| Priority | work priority, derived from asset criticality + reported urgency |
| Dates | target start/finish, scheduled start/finish, actual start/finish, reported date |
| People | supervisor, lead, crew, owner |
| Money | **the eight cost fields below** |
| Records | work log, failure reporting, attachments, meter readings taken |

> **How the record maps.** *What and where* is `equipment_id` plus `unit_id`/`area_id`;
> **classification is the `trade_id`** — [`Trade`](../../../app/Models/Trade.php) shipped as a row on
> 2026-08-20 and replaced a `category` field fed from a translation array, which meant the column was
> unenforced, un-extendable without a deploy in two languages, and spelled out a second and third
> time on the equipment screens with four values missing from both. **`category` must not come back**
> on work orders, service plans or machines: two columns answering *"what kind of work is this"* are
> two truths about one question. There is deliberately **no GL account on the job** — it posts
> nothing (see the note under §4.2) — and the type vocabulary is narrower than Maximo's, `PPM` and
> `CM` only, with emergency carried as a priority rather than as a type.

### 4.2 The eight numbers

Every Maximo work order carries planned and actual cost in **four buckets**:

|  | Labour | Material | Service | Tool |
|---|---|---|---|---|
| **Planned** | `estlabcost` (from `estlabhrs` × craft rate) | `estmatcost` | `estservcost` | `esttoolcost` |
| **Actual** | `actlabcost` (from reported `LABTRANS`) | `actmatcost` (from `MATUSETRANS`) | `actservcost` (from `SERVRECTRANS` / invoice) | `acttoolcost` |

…plus `esttotalcost` / `acttotalcost`, and the same again **rolled up** across the work-order
hierarchy so a parent shows itself plus its children. *(cited — these are documented fields on the
`WORKORDER` object.)*

**Why four buckets and not one total.** Because the four answer different questions and behave
differently:

- **Labour** is the number that is invisible if you do not capture it. An in-house team fixing 200
  faults a year at 2 hours each is 400 hours of real cost that appears nowhere, which makes
  in-house work look free and every outsourcing decision wrong.
- **Material** must reconcile to inventory — the same movement that costs the job credits the
  storeroom.
- **Service** must reconcile to accounts payable — the same job that shows EGP 40,000 of contractor
  work has a vendor bill behind it.
- **Tool** is small in a mall and is why the bucket is separable rather than merged.

**Planned vs actual is the point, not the total.** A work order that estimated 4 hours and consumed
14 is the finding; one that shows only "14" is a number nobody can act on.

> **SHIPPED 2026-08-20 — this is the section the facility close-out was built to, and it is the one
> that closed.** `2026_08_20_200000_make_the_work_order_a_cost_object` put `est_*`/`act_*` on the
> job and [`HasWorkOrderCost`](../../../app/Models/Concerns/FacilityWorkOrder/HasWorkOrderCost.php)
> owns them. `recomputeCosts()` is the single source of truth, written the way
> `Invoice::recomputeTotals()` is and for the same reason — several independent channels change the
> number, so exactly one method computes it and every channel calls it. **Never set an `act_*`
> column anywhere else**; they are not fillable at all. A fourth channel means adding it there *and*
> wiring that model's events.
>
> **Three buckets, not four — a stated deviation.** Labour is `facility_work_order_labour` (hours ×
> the craft rate), material is approved and recorded part draws, service is vendor bills plus
> expenses carrying `facility_work_order_id`. **Tool is folded into service** because a mall hires
> the lift rather than owning it, so it arrives as somebody's invoice; the bucket earns its
> separation in a plant with a tool crib, not here. The service figure is **net of VAT and net of an
> applied SLA penalty** — VAT is recoverable and is not a cost of the job, and a penalty credited
> against a contractor's invoice genuinely reduces what the work cost, which is also what
> `SlaPenaltyJournalizer` does to the same expense account. A cancelled document costs nothing, and
> a document moved between jobs recomputes the job it **left**.
>
> **The planned total is derived too, and null is not zero.** `est_total_cost` is re-derived on
> every save, because the cost channels never touch an estimate and an operator editing
> `est_service_cost` had been leaving `costVariance()` — the number they act on — computed against a
> stale figure. An unestimated job stores **null**, since *"not estimated"* and *"estimated at
> nothing"* are different claims and planned-versus-actual is the whole point. `job_value` is gone:
> it duplicated `est_service_cost`, and two columns holding one figure are two truths about it.
>
> **The work order is a cost object and must NEVER become a GL source.** The money is already in the
> ledger through `StockMovement`, `VendorBill`/`Expense` and `Payroll`; these columns are a
> management dimension over posted money, so registering a journalizer would post every maintenance
> cost twice **and balanced**, which is the failure mode nothing downstream would catch.
> `WorkOrderIsACostObjectNotAGlSourceTest` fails the build on it. For the same reason a report may
> not add work-order labour to the wage bill — it **explains** part of it.
>
> **The before-the-money control sits on top of the estimate.**
> [`ControlsSpendAgainstNte`](../../../app/Models/Concerns/FacilityWorkOrder/ControlsSpendAgainstNte.php)
> defaults a job's not-to-exceed from `trades.default_nte` **at the moment it is raised and never
> afterwards** (changing a trade's default must not silently re-authorise every open job in it), and
> `WorkOrderProposal` is the quote loop. Approving a revised whole price replaces the estimate,
> **raises the ceiling and never lowers it** — approving a cheaper revision must not tighten what
> the contractor was already permitted for — and withdraws the competing pending quotes, since two
> prices for the same work cannot both stand; a *supplementary* quote for extra work **adds** to
> both and withdraws nothing, because two supplements are not alternatives. **Over-NTE is SHOWN and
> never blocks the bill**, a stated
> deviation from ServiceChannel, which holds the invoice: a job can legitimately grow for something
> nobody could have proposed for, and holding a supplier's invoice inside a system the supplier
> cannot see turns a commercial conversation into a payment failure.

### 4.3 The status lifecycle

```
WAPPR  waiting on approval
  → APPR    approved — work may be scheduled and material reserved
  → WMATL   waiting on material  (a legitimate stall, tracked separately)
  → WSCH    waiting to be scheduled
  → INPRG   in progress — actuals may be reported
  → COMP    complete — work done, failure reported, actuals in
  → CLOSE   closed — financially final, no further transactions
CAN       cancelled (from any pre-INPRG state)
```

**`COMP` and `CLOSE` are two different events and that is deliberate.** Complete means the work
finished. Closed means the money finished — the last invoice landed, the last labour was reported,
and the record is now history. A system with one terminal state either closes jobs whose invoices
have not arrived or leaves finished work looking open for a month. *(cited — the standard
`WOSTATUS` domain.)*

> **Atriom has four statuses and only one terminal pair — `open` · `in_progress` · `done` ·
> `cancelled` — and it takes the half of this that matters.** `done` freezes the **work**: the
> service-layer transition guards refuse further movement on a terminal order. It deliberately does
> **not** freeze the **money**, and that is what avoids the failure named above: `VendorBill`'s model
> events call `recomputeCosts()` on save, delete and restore, so a contractor's invoice that arrives
> three weeks after the job finished still lands in the service bucket without anyone reopening the
> job. **The missing half, re-verified 2026-09-01, is that a job never becomes financially final** —
> there is no `CLOSE`, so a mis-keyed bill linked to a years-old order silently restates its cost and
> its variance with nothing on the record saying the money was settled. This is a management
> dimension only; the general ledger has its own sealed-period guard, which is why the exposure is
> a wrong management figure rather than wrong books.

### 4.4 Work order hierarchy

A work order may have children, and this is how real jobs are modelled:

- **Tasks** — the steps within one job (from the job plan)
- **Child work orders** — separately assignable, separately costed sub-jobs, e.g. one per trade
- **Follow-up work orders** — a new job raised from a finding on this one, linked but *not*
  reopening the original

Costs roll **up**; status does not roll down automatically. *(verify — roll-up behaviour on status
is configurable.)*

> **Two of the three exist; the roll-up does not.** Tasks are `facility_work_order_items` — the
> checklist a job plan copies onto the order, and since the route work (§6) each line may name the
> **machine** it is about. Follow-ups are `parent_work_order_id` (FR-CM-15): a job raised because an
> earlier fix was incomplete, linked to it and not reopening it, which is what makes the repeat
> detection in §7 possible.
>
> **Separately-costed child work orders are not modelled, and costs do not roll up across the
> follow-up link** — re-verified 2026-09-01: `recomputeCosts()` sums a job's own labour, parts and
> bills and nothing else. For a mall that is mostly right, since the one-job-per-trade split Maximo's
> children exist for is rare here; what it costs is that a fault visited four times reads as four
> unrelated jobs on the money, even though the repeat badge tells the operator they are one story.

---

## 5. Labour, crafts and the hours that become money

- A **craft** (electrician, HVAC technician, cleaner) carries **rates** — standard, overtime,
  premium — optionally by skill level.
- A **labour** record is a person, tied to one or more crafts.
- Reporting time against a work order writes a **labour transaction** carrying hours × rate → cost,
  the GL accounts to debit and credit, and the date.
- **Contractor labour** flows through the same transaction with the vendor's contract rate, so an
  in-house hour and a contractor hour are comparable on one report.

**The design point Atriom needs:** cost is a *derived consequence of reporting time*, never a number
somebody types on the job. Nobody is asked "what did this cost" — they are asked "how long did it
take, and who did it", which is a question a technician can answer truthfully.

> **SHIPPED 2026-08-20, and the design point above is the docblock of the model that took it.**
> [`FacilityWorkOrderLabour`](../../../app/Models/FacilityWorkOrderLabour.php) records hours against
> a job; the craft is [`Trade`](../../../app/Models/Trade.php) and the rate is its
> `standard_hourly_rate`. This is the primitive whose absence made in-house work cost **zero** on
> every report, so insourcing always looked cheap and every outsourcing decision was wrong by the
> whole wage bill.
>
> Three rules it encodes. **The rate is frozen at entry** — resolved once on write, because a rise
> in a trade's rate must not silently re-price work done last March. **A null rate is deliberate and
> visible**: a trade with no rate produces hours with no cost, so the hours still count, the money is
> visibly missing and the operator can see which trade needs a rate — where a default rate would
> produce a number that looks computed and is invented. And a line may name **its own** craft rather
> than the job's, because an electrician helping on an HVAC job is real and forcing the job's trade
> onto those hours would misreport the cost of both.
>
> **Contractor hours do NOT flow through this transaction — a stated deviation.** Maximo puts
> in-house and contract labour through one `LABTRANS` at the vendor's contract rate; here a
> contractor arrives as a **vendor bill** in the service bucket, and there are no contracted vendor
> or trade rates to price an hour against (`vendor_contracts` carries a value and a scope, and the
> `trade_vendor` pivot is bare eligibility — re-verified 2026-09-01). The comparability this section
> is really about is preserved a different way: both buckets land in the same `recomputeCosts()`, so
> in-house and contracted work are one number on one job. What is lost is the rate-sheet check on a
> contractor's invoice, which has no data to check against.
>
> **These rows allocate; they never post.** The wage is already in the ledger through `Payroll` →
> `salaries_expense`, so a report that adds payroll and work-order labour together double-counts.

---

## 6. PM — preventive maintenance, frequencies and routes

A **PM** record generates work orders from a job plan on a trigger:

| Trigger | Basis | Example |
|---|---|---|
| Time-based | fixed calendar interval, with lead time | quarterly fire-damper inspection |
| Meter-based | a counter crossing an interval | grease the escalator every 2,000 running hours |
| Seasonal | active only within date/day windows | pre-summer chiller service |
| Hierarchy | a parent PM generating child PMs | one shutdown covering many assets |

**Lead time** generates the order *before* it is due, so it can be planned rather than discovered.

**Routes** are the second idea Atriom does not have: a route is an ordered list of assets or
locations covered by one visit — "inspect all 42 fire extinguishers on level 2". A route can
generate **one work order with one task per stop**, or **one child work order per stop**, and the
choice is per route. *(cited — route "WO generation" options are documented.)* Without routes,
either the technician gets 42 work orders for one walk, or one work order that cannot record which
of the 42 failed.

**PM compliance** — did the PM get done inside its window — is a first-class report, because a
preventive programme nobody measures is a preventive programme nobody does.

> **ROUTES SHIPPED 2026-08-20** (`2026_08_20_600000_routes_and_planned_cost`).
> [`ServicePlanStop`](../../../app/Models/ServicePlanStop.php) makes a plan an ordered list of
> machines, `ServicePlan::isRoute()` is the one predicate the generator and the screens share, and a
> round generates **one work order with one line per stop** — the line carrying `equipment_id`, which
> is the entire point: *"Extinguisher 2-17 — fail"* stops being a string and becomes a fact about a
> device, so the round can report which ones failed and 2-17's own history is no longer empty.
>
> **Maximo's per-stop-child option is deliberately not offered.** Children earn their keep when each
> stop needs separate assignment or costing, and 42 work orders for one walk is the failure the route
> exists to prevent. One judgement worth keeping: a **retired machine drops off the round and the
> round still runs** — skipped rather than refused, because one dead stop out of 42 must not stop the
> other 41 being inspected, which is the opposite of a single-target plan whose machine is retired,
> where generating the job is the useful signal that the plan is now pointless.
>
> **PM COMPLIANCE SHIPPED the same day.**
> [`TracksPmCompliance`](../../../app/Models/Concerns/FacilityWorkOrder/TracksPmCompliance.php)
> answers `on_time` · `late` · `overdue` · `due` from two columns that were already there — the
> generator copies `next_due_date` onto the order, so `scheduled_for` **is** the due date — with
> `ServicePlan::complianceRate()` as the per-plan figure and `withComplianceCounts()` as the list's
> one-query twin, both going through the same three scopes so they cannot disagree about what "on
> time" means. It is **derived, never stored**: `overdue` is a function of today, and a stored one
> would need its own sweep and go wrong on a day when nothing happened. A plan with nothing settled
> has **no** rate, because 0% and 100% are both inventions.
>
> **Measured strictly, with no tolerance window — a stated deviation from Maximo**, which allows one.
> A single global tolerance is wrong in both directions here: three days is most of a weekly cleaning
> round and nothing at all on an annual overhaul, and a percentage of the cycle is a policy nobody has
> agreed to. Strict never *overstates* compliance, which is the safe direction. Completing at 16:00
> on the due day is **on time** — whole days are compared, or every afternoon completion reads late.
>
> **Two of the four triggers, and no lead time — re-verified 2026-09-01.** Time-based and
> meter-based both exist (`TRIGGER_TIME`, `TRIGGER_USAGE` against a `utility_meters` row, including
> the `hours` type that is a run-hours counter); seasonal windows and a parent PM generating children
> do not. **Lead time is absent entirely**: `scopeDue()` compares `next_due_date` against today with
> no window, so a quarterly service materialises on the day it falls due with zero planning horizon —
> discovered rather than planned, which is the thing lead time exists to prevent. Whoever builds it
> must leave `scheduled_for` receiving `next_due_date` and not the generation date, or every
> early-raised job silently reads as generously windowed and the compliance figure above stops
> meaning anything.

---

## 7. Failure reporting — the reliability primitive

Maximo records failures as a **hierarchy**, not a free-text note:

```
Failure class      (e.g. HVAC)
  └── Problem      (e.g. NO COOLING)
        └── Cause  (e.g. REFRIGERANT LEAK)
              └── Remedy (e.g. LEAK REPAIRED / RECHARGED)
```

The class is attached to the **asset**, so the problems offered on a work order are the problems
that asset can actually have. Recording problem/cause/remedy on completion is what makes these
answerable:

- **MTBF / MTTR** per asset and per class
- **Bad-actor analysis** — which 5% of assets generate 40% of the work
- **Repair-or-replace** — cumulative repair cost vs replacement cost, both of which need §4
- **Warranty recovery** — a failure inside a warranty window that was paid for anyway

**The order matters: primitives first, dashboard second.** Failure codes are worth nothing on the
day they ship and everything two years later; a dashboard built before the codes has nothing to
read.

> **THE PRIMITIVES SHIPPED 2026-08-20, in the order this paragraph argues for.**
> [`FailureCode`](../../../app/Models/FailureCode.php) is the problem → cause → remedy vocabulary,
> recorded on the job's completion dialog, and
> [`RecordsFailuresAndRepeats`](../../../app/Models/Concerns/FacilityWorkOrder/RecordsFailuresAndRepeats.php)
> flags a second job on the same machine (or, failing that, in the same unit), in the same trade,
> inside the window as a **repeat** — so four visits to one fault stop reading as four unrelated
> successes. Only **corrective** work counts: a preventive job happened because a schedule said so,
> and counting a fortnightly round's own visits as repeats of each other would bury the signal. The
> codes are an operator-editable catalogue, so a failure mode this mall actually sees can be added
> without a deploy. The three pickers sit on the **completion** action itself, where the engineer
> already is — a screen they have to go and find afterwards is one nobody visits — and all three are
> **optional**, because a required code gets whatever clears the validation fastest, which is worse
> than a blank since it looks like data.
>
> **Scoped by TRADE, not chained to a parent — a stated deviation with a reason.** Maximo chains
> causes to problems and remedies to causes. That chain is a matrix somebody must populate before
> anything can be recorded, and an unpopulated matrix offers no codes, so nobody records anything and
> the primitive is dead on arrival. Here the trade is the class — it already classifies work orders,
> plans and machines, and a second taxonomy would be one more list to keep in step — and a code with
> no trade is offered everywhere. A code recorded on a finished job cannot be deleted, only
> deactivated, because it is the dimension every reliability figure will group by.
>
> **Nothing reads them yet beyond the repeat badge — re-verified 2026-09-01.** No MTBF, MTTR or
> bad-actor figure is computed anywhere; the only consumers are the register's repeat flag and the
> vendor scorecard's repeat column. That is consistent with the decision recorded in
> [gap-analysis §6](../../gap-analysis/README.md#6-declined--with-reasons-so-they-are-not-re-raised),
> which declines reliability analytics *at full depth* and says to build the primitives first — but
> the reason has now been satisfied, and a corrective-job count and an average time-to-complete per
> machine are two aggregates on a register that already sums lifetime cost the same way.

---

## 8. Storerooms, issues and reservations

- **Item master** is global; **inventory** is per storeroom (balance, bin, reorder point, average
  cost).
- Applying a job plan **reserves** material against the work order.
- **Issuing** to a work order writes a material-use transaction: debit the work order's GL account,
  credit the storeroom's — the parts cost lands on the job *and* on the inventory value in one
  movement.
- A **direct issue** purchase (bought for one job, never stocked) charges the work order at receipt.

**The invariant:** a part cannot cost a job without leaving stock, and cannot leave stock without
costing a job. One transaction, two consequences — the property that keeps a maintenance system and
a general ledger from disagreeing.

> **At parity on the internal road, and it predates the close-out.**
> [`WorkOrderPartService`](../../../app/Services/WorkOrderPartService.php) keeps request and issue
> apart exactly as this section does: a `pending` draw is a proposal and moves nothing, and only
> `approve()` writes the `StockMovementService::record()` movement and stamps its id on the part —
> so the stock ledger goes on meaning one thing, *stock that actually moved*. The cost is frozen on
> the draw at the warehouse's weighted-average cost and the movement is recorded with that explicit
> figure, and `recomputeCosts()` counts only `approved` and `recorded` rows, so a rejected draw
> costs nothing. Maximo's **direct issue** — bought for one job, never stocked — is
> `recordExternal()`, which costs the job without touching stock, deliberately.
>
> **One open hole in the invariant, verified 2026-09-01 and worth knowing before reading a job's
> total.** An externally-bought part and the supplier's bill for that same purchase are two
> independent roads into the figure: the part lands in **material** and the bill, linked through the
> `facility_work_order_id` the vendor-bill form offers, lands in **service**. Nothing links or
> de-duplicates them — the part carries a free-text `reference` for the supplier's invoice number and
> no `vendor_bill_id` — so a purchase entered both ways is counted twice in `act_total_cost`, which
> is the figure the NTE breach and `costVariance()` are read off.
> [modules/26](../../modules/26-facility.md) names this seam from the **other** side: a purchase with
> no bill is *absent* from the books rather than wrong in them, and journalizing the part is
> explicitly refused for exactly the double-count reason. The management figure when **both** are
> entered is the direction nothing states, and it is the one an operator reads.
>
> **Barcode capture and guided cycle counts do not exist** (re-verified: no such term appears
> anywhere under `app/`) — issue, receive and count are all web forms, and a count is an ad-hoc
> adjustment. Whatever is built there has to live in the admin panel under the technician role,
> because a technician mobile app was declined by the operator on 2026-08-20.

---

## 9. Safety plans and permits

A job plan may carry a **safety plan**: hazards, the precautions each requires, lock-out/tag-out
records for the assets that must be isolated, and the **permits** required before work starts.
Maximo treats a permit as attached to the *work*, not as an independent register, and the safety
plan is copied onto the work order when the job plan is applied — so the permit requirement is
inherited by every instance of that job rather than remembered by a person.

*(Atriom's `work_permits` is a standalone register, which is closer to ServiceChannel's compliance
model than to Maximo's. That is a defensible difference — see the gap analysis — but the
**inheritance** idea is the one worth taking: a job type that always needs a hot-work permit should
say so once.)*

> **STILL OPEN, and this is the section of the file whose recommendation was not taken — re-verified
> 2026-09-01.** The register is real and a permit can point at the job it covers
> (`work_permits.facility_work_order_id`), and a permit past its window with no closure recorded is
> swept hourly — nobody wrote down that the welding stopped and the area was made safe. But the
> **requirement** is still remembered by a person: grep finds no `requires_permit` anywhere, nothing
> on a trade or a service plan can state that this kind of work always needs one, and nothing warns
> when such a job starts without a live permit. The natural home is the **trade**, which is the
> routing spine both work orders and plans already classify by, surfaced as a warning rather than a
> block — the same suggest-never-block shape the vendor picker took.

---

## 10. Service level agreements

Maximo's SLA is a record with **commitments** (response, resolution) applied to work by matching
criteria — asset type, location, priority, customer. One SLA can cover many objects; a work order
can be measured against several. Missing a commitment triggers the **escalation engine**, which is
a general-purpose "when this condition holds for this long, do this" mechanism rather than a
maintenance-specific alarm.

*(Atriom's per-property `SlaPolicy` with a settings→config fallback is a narrower, more
opinionated version of the same thing, and the gap analysis rates it ahead of the benchmark for a
mall — the criteria engine's generality is cost, not value, at this scale.)*

> **Narrower still, and more local, since 2026-08-21.** A priority listed in
> `sla_working_clock_priorities` is measured on `App\Support\WorkingCalendar` — Egypt's Friday–Saturday
> weekend, the operator's own `holidays` register and Ramadan short days, resolved per property —
> so office-hours work is not judged against a clock that ran all weekend. The chosen clock is
> **frozen on the row** (`facility_work_orders.sla_clock`, `tenant_requests.sla_clock`), because a
> pending SLA is re-read on every breach scan and resolving at read time would re-time every running
> job the moment the setting changed. It ships empty: which priorities are office work is the
> operator's ruling, not ours.

---

## 11. The reports this model makes possible

| Report | Needs |
|---|---|
| Maintenance cost per asset / per location / per m² | §4 cost object + §1 hierarchy |
| Planned vs actual, by job and by trade | §3 job plans + §4 |
| In-house vs contracted cost per trade | §5 labour transactions |
| PM compliance % | §6 |
| MTBF / MTTR, bad actors | §7 failure hierarchy |
| Repair-or-replace | §4 cumulative cost + asset replacement value |
| Backlog by trade and age | §4.3 statuses |
| Stock consumed by job type | §8 |

**Every row needs §4.** That is the case for treating the cost object as the first thing to fix
rather than one item among many.

> **The case was accepted and §4 was built first — but an ingredient is not a report.** Every row's
> ingredients now exist; not every row has a surface. What can actually be read today, verified
> 2026-09-01: **planned versus actual by job** (`costVariance()` on the record, and `act_total_cost`
> with a running total on the work-order register); **in-house versus contracted by trade**, by
> filtering that register on trade and execution type and reading the same total; **PM compliance %**
> per plan; **backlog by trade and age**, from the register's own filters; and **maintenance cost per
> machine**, as a column on the equipment register.
>
> **What has no surface yet.** Cost per **location** or per m² has nothing to slice by — the
> work-order register carries no unit or area filter, though both columns are on the job. **MTBF,
> MTTR and bad actors** are computable from what §7 now records and nothing computes them.
> **Repair-or-replace** has its repair half on the machine and its replace half one link away on the
> fixed asset, on two different screens. And the per-machine figure sums only that machine's own
> jobs, so a **parent's** cost excludes its sub-codes (§1). None of these needs a new primitive; each
> needs a column, a filter or an aggregate on a screen that already exists.

---

## Sources

- IBM Maximo Manage / Maximo Asset Management product documentation (IBM Documentation): Work Order
  Tracking, Job Plans, Preventive Maintenance, Routes, Assets, Classifications, Inventory, Labor
  Reporting, Failure Codes, Service Level Agreements applications.
- Maximo data dictionary for the `WORKORDER`, `LABTRANS`, `MATUSETRANS`, `SERVRECTRANS` objects
  (cost field names in §4.2).
- Concepts marked ***(verify)*** are configuration- or version-sensitive and should be confirmed
  against a live MAS 8 tenant before being designed to.
