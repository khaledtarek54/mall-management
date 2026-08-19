# IBM Maximo — the work-and-asset yardstick

> **What this file is for.** Maximo is where the industry's vocabulary for maintenance work comes
> from — job plan, work order hierarchy, failure class, PM route, storeroom issue, craft. More
> importantly for Atriom, it is the reference implementation of **the work order as a cost object**,
> which is the structural idea the facility module is currently missing.
>
> Read §4 first if you read only one section. Everything else in this file exists to feed it.

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

### 4.4 Work order hierarchy

A work order may have children, and this is how real jobs are modelled:

- **Tasks** — the steps within one job (from the job plan)
- **Child work orders** — separately assignable, separately costed sub-jobs, e.g. one per trade
- **Follow-up work orders** — a new job raised from a finding on this one, linked but *not*
  reopening the original

Costs roll **up**; status does not roll down automatically. *(verify — roll-up behaviour on status
is configurable.)*

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

---

## Sources

- IBM Maximo Manage / Maximo Asset Management product documentation (IBM Documentation): Work Order
  Tracking, Job Plans, Preventive Maintenance, Routes, Assets, Classifications, Inventory, Labor
  Reporting, Failure Codes, Service Level Agreements applications.
- Maximo data dictionary for the `WORKORDER`, `LABTRANS`, `MATUSETRANS`, `SERVRECTRANS` objects
  (cost field names in §4.2).
- Concepts marked ***(verify)*** are configuration- or version-sensitive and should be confirmed
  against a live MAS 8 tenant before being designed to.
