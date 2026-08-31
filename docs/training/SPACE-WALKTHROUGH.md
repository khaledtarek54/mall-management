# Space & the estate — a walkthrough for someone new

**Who this is for.** Someone who has to open Atriom and understand *the physical mall* — the
property, its floors, its shops, its parking bays — before they touch a lease or an invoice. It
assumes **no** property background.

**Who this is NOT for.** It is not the developer's document — that is
[`docs/modules/01-properties-units.md`](../modules/01-properties-units.md) and
[`35-rentable-items.md`](../modules/35-rentable-items.md). This does not repeat them; where a rule
needs proving it links there.

**Read this before [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md).** A lease is *space, for time,
for money*. This is the space half, and almost every number in the system is divided by it.

---

## Table of contents

| Part | What it answers |
|---|---|
| [0](#part-0--why-space-comes-first) | Why space comes first |
| [1](#part-1--the-vocabulary) | The vocabulary — 14 words |
| [2](#part-2--the-property-record) | The property record (`Asset`) |
| [3](#part-3--floors-and-the-unit-register) | Floors and the unit register |
| [4](#part-4--a-units-status-is-a-projection-never-a-choice) | A unit's status is a **projection**, never a choice |
| [5](#part-5--two-occupancy-numbers-and-why-they-disagree) | Two occupancy numbers, and why they disagree |
| [6](#part-6--area-is-a-dated-record) | Area is a **dated record**, not a column you edit |
| [7](#part-7--the-two-floor-plans) | The two floor plans |
| [8](#part-8--rentable-items--parking-storage-signage) | Rentable items — parking, storage, signage |
| [9](#part-9--unit-owners--the-shop-that-was-sold) | Unit owners — the shop that was sold |
| [10](#part-10--the-property-switcher-and-isolation) | The property switcher, and what isolation means for you |
| [11](#part-11--what-space-feeds) | What space feeds — every number that divides by m² |
| [12](#part-12--i-clicked-and-nothing-happened) | "I clicked and nothing happened" — refusals decoded |
| [13](#part-13--the-seven-rules) | The seven rules you must not break |

---

# Part 0 — Why space comes first

A mall sells **space, for time, for money.** Everything else in Atriom is downstream of one number:
how many square metres you have, and which of them are earning.

Get the space register wrong and the errors do not look like space errors. They look like this:

| What you see | What actually broke |
|---|---|
| A tenant's CAM true-up is 40,000 too high | Vacant units were left out of the denominator |
| Occupancy reads 91% but the rent roll is thin | Physical occupancy counts kiosks and anchors as one vote each |
| A shop cannot be re-let — *"this unit already has an active lease"* | An ended lease never moved off `active`, so its unit never freed |
| A tenant's request goes to the wrong supervisor | The unit is tagged with another mall's zone |
| The service charge apportionment moved for a year that was already reconciled | Someone typed a new `area_sqm` instead of running **Remeasure** |

**None of those are reported as space problems.** That is the whole reason this document exists.

---

# Part 1 — The vocabulary

The Arabic column is what the team actually says out loud.

| Term | العربي (Arabizi) | In the system | What it actually means |
|---|---|---|---|
| **Property / Asset** | el mall | `Asset`, code `AW` | One mall. **Everything** in Atriom belongs to exactly one property, and you see one at a time |
| **Floor** | el dor | `Floor` (per property) | A level, with a code and an ordering number. A register, not free text |
| **Unit** | wehda / ma7al | `Unit`, code `A-12` | One lettable shop |
| **GLA** | el mesa7a el mo2aggara | `units.area_sqm` | *Gross Leasable Area* — the m² you can let. The denominator of nearly everything |
| **Load factor** | — | derived | GLA ÷ gross building area. "70.8% lettable" — the rest is corridors, plant, back-of-house |
| **Category** | no3 el ma7al | `units.category` | retail · F&B · wellness · service · kiosk · office · storage |
| **Status** | 7alet el wehda | `units.status` | vacant · reserved · occupied · maintenance. **Projected, not typed** — Part 4 |
| **Area / zone** | el mante2a | `Area` (module 30) | A facility zone with supervisors. Routing, not geometry |
| **Rentable item** | mawa2ef / lafta / makhzan | `RentableItem` | Parking bay, storage cage, signage face, kiosk pitch. Billable, **never** GLA |
| **Holding** | — | `rentable_item_holdings` | Who holds a bay, and between which dates |
| **Unit ownership** | malek wehda | `UnitOwnership` | Somebody **bought** a shop. Pays صيانة, holds no lease |
| **Occupancy (physical)** | — | `Asset::occupancyRate()` | occupied units ÷ all units. One vote per shop |
| **Occupancy (economic)** | — | `Asset::areaOccupancyRate()` | occupied m² ÷ all m². The one that tracks money |
| **All Properties** | — | `Asset::ALL_PROPERTIES_CODE` | A pseudo-property used only as a context switch. Never a real mall |

---

# Part 2 — The property record

`/admin/assets` — and note you can only **create** a property from the All-Properties context, never
from inside a mall. That is deliberate: a property is not a child of a property.

## 2.1 The fields that matter

| Field | What to type | What it changes |
|---|---|---|
| `name` | "Haya Walk" | Every screen header, every document, the owner statement |
| `code` | `AW`, max 10, letters/dashes | **It is inside every document number** — `INV-AW-202608-0417`. Changing it later starts a fresh number sequence. Pick it once |
| `type` | mall / retail_walk / mixed_use / office / residential | Reporting only |
| `city`, `country` | Cairo, Egypt | The issuer block on every PDF |
| `currency` | EGP — **read-only** | There is no FX in this system. The field exists because it prints at the head of the owner statement |
| `total_area_sqm` | Gross building area | The denominator of the load factor shown under GLA |
| `leasable_area_sqm` | Declared GLA | Used by CAM when `denominator_basis = gla`. **Not** used for occupancy — see 5.2 |
| `logo` / `favicon` / `primary_color` | Branding | The admin panel repaints per property, **and so does the tenant portal** for a tenant trading in exactly one mall |

## 2.2 Staff vs owners — two different pivots, do not confuse them

| | Table | Means | Consequence |
|---|---|---|---|
| **Staff** | `asset_user` | Who *operates* this mall (Property Manager, Site Engineer) | Restricts what that user can see. This is the grant |
| **Owners** | `asset_owner` + `ownership_percentage` | Who *owns* it — Jawad | Overdue alerts, owner statements, the owner channel |

**The trap:** assigning a user to a property is what **grants** them access to it. So a `manager`
pinned to Mall A who edits their own user record and adds Mall B has just given themselves Mall B.
The system refuses this (`UserResource::enforceGrantableAssetsRule()`, both directions — you can
neither grant nor revoke a property you cannot see) and writes the attempt to the access-control
audit. Know that the guard exists so you do not read the refusal as a bug.

> A user with **no** assignment at all sees everything they have the role for. Assignment is a
> narrowing, not a widening.

---

# Part 3 — Floors and the unit register

## 3.1 Floors are a register, not text

A unit picks its floor from **the property's own floor register**. It used to be a free-text column,
and "G" and "Ground" became two floors to anything that grouped by it. Two rules follow:

1. **A unit's floor must belong to the unit's own property.** Guarded at the model, not just on the
   form — a raw write of another mall's floor is refused.
2. **A unit's zone must too**, and this one is worse if it goes wrong: area routing fans a tenant
   request out to that zone's supervisors, so a mis-tagged unit sends a leaking pipe in Mall A to
   the team standing in Mall B.

## 3.2 The unit form, field by field

| Field | Notes |
|---|---|
| `asset_id` | Pinned + disabled when you are inside a mall. It is not a picker — see Part 10 |
| `code` | e.g. `A-01`. **Unique per property**, max 20 |
| `floor_id` | From the property's floor register, ordered by level |
| `category` | retail / food_beverage / wellness / service / kiosk / office / storage |
| `area_sqm` | m². **Read-only on Edit** — Part 6 |
| `status` | vacant / reserved / occupied / maintenance. **Only `maintenance` is a real choice** — Part 4 |
| `description` | Free notes |

## 3.3 The list is the working screen

Columns: code · floor · category · area · **current tenant** · **current rent** · **lease expiry**
(turns orange inside 90 days) · status. Filters: status, category.

The nav badge on **Units** counts **vacant** units in the mall you are in. That badge is the
leasing team's daily worklist.

---

# Part 4 — A unit's status is a projection, never a choice

**The rule, in one line: you do not set a unit's status. You change its leases, and the status
follows.**

```
any lease on this unit is  active                                  → occupied
else any lease is          draft / pending_approval / renewed      → reserved
else                                                                → vacant

EXCEPT: status = 'maintenance' is NEVER overwritten by anything.
```

This runs from the lease lifecycle (`LeaseObserver`), from `syncUnits()`, from the unit form's own
save, and **nightly** from `leases:expire`.

## 4.1 Why the nightly sweep exists — the bug worth understanding

A stored value that is a function of **today** goes wrong on a day when *nothing happened*.

A give-back effective **1 January**, recorded in **August**: the projection is date-aware and
correctly says "no lease holds this unit on 1 January". But nothing re-ran it on 1 January, because
no one saved anything that day. The column read `occupied` from January onward — through eight
months of occupancy reports — until somebody happened to touch that lease.

Worse, nothing moved a finished lease off `active` at all. Measured seven months after a term ended:
the lease read `active`, the unit read `occupied`, **the shop could not be re-let** (*"this unit
already has an active lease"*), and the escalation sweep was still stepping its rent.

`leases:expire` (daily, 05:15) now does both: it expires ended leases **and** re-projects any unit
whose stored status disagrees with the projection.

## 4.2 `maintenance` is the one sticky status

Set a unit to `maintenance` and nothing will ever move it back automatically — not activating a
lease, not terminating one. It is a manual override for a shop out of service, and only a person can
lift it.

**Consequence you must know:** a unit left at `maintenance` by mistake is invisible to the occupancy
projection for ever and quietly understates your occupancy. Sweep for it.

## 4.3 A lease can cover several units

`lease_unit` is the truth about which units a lease covers. `leases.unit_id` is the **master** unit —
the lease's identity, and it can never be changed (moving out of it is a *relocation*, a different
act). Exactly one row is master.

Never write that pivot by hand. `Lease::syncUnits()` is the one safe way: it dedupes, mirrors the
master, and re-projects every unit that gained or lost the lease.

---

# Part 5 — Two occupancy numbers, and why they disagree

Both are shown side by side. **The gap between them is information, not a bug.**

| | Formula | What it answers |
|---|---|---|
| **Physical** | occupied units ÷ all units | "How many shops are dark?" — one vote per shop |
| **Economic** | occupied m² ÷ all m² | "How much of the earning surface is earning?" |

Letting the single 2,000 m² anchor barely moves physical occupancy and moves economic occupancy a
long way. Letting five kiosks does the opposite.

## 5.1 A worked example

A mall with 4 units: one anchor at 2,000 m² and three kiosks at 30 m² each. The anchor is vacant,
the kiosks are let.

| Measure | Sum | Result |
|---|---|---|
| Physical | 3 of 4 occupied | **75.0%** |
| Economic | 90 of 2,090 m² | **4.3%** |

Both are true. Only the second one predicts the rent roll.

## 5.2 Three design choices you will notice

1. **The denominator is summed from the units, not from `assets.leasable_area_sqm`.** So the ratio
   can never exceed 100% because the declared GLA and the unit register disagree.
2. **A unit with no area contributes to neither side.** Missing areas show up as economic occupancy
   *drifting away* from the physical figure. That drift is a data-quality signal — go fill the areas.
3. **A property with no units, or all-zero areas, reads 0.0%.** Never a crash, never `NaN`.

---

# Part 6 — Area is a dated record

**The rule: `units.area_sqm` is the CURRENT measurement. `unit_areas` is the truth.**

It is exactly the relationship rent has to its charge schedule: one current number on the record,
a dated ladder behind it.

## 6.1 Why

CAM apportions on `Unit::areaOn($date)` — the row **in force on that date**. So a re-survey, a
demise, or a fit-out that moved a wall does not change what a period that was already reconciled was
billed on.

## 6.2 The one way to change an area

Use the **Remeasure** action on the units table. Never edit the field.

The Edit form's `area_sqm` is **read-only** for this reason. When it was editable, it moved the
column and wrote **no dated row** — so CAM kept the OLD area while the unit register, the lease's
area, the mobile API and every report showed the NEW one. One measurement, two answers, split so
that the operator saw the change everywhere they looked *while the money ignored it*.

> `RemeasureUnitService` existed for months with **no caller anywhere in the app** — the register
> was there and nothing could add to it. The action is what made it reachable.

---

# Part 7 — The two floor plans

| Screen | Shows | Gated on |
|---|---|---|
| **Occupancy map** `/admin/occupancy-map` | Every **unit** as a card, grouped by floor, coloured by status, with per-status counts, status filter and search by unit or tenant | `reports.view` ∪ `units.view` |
| **Rentable item map** `/admin/rentable-item-map` | Every **bay / cage / signage face / kiosk pitch**, same grouping | `rentable_items.view` ∪ `reports.view` |

They are separate screens on purpose — Yardi treats parking as its own space type, with different
holders and different pricing — but they share the property resolution and the floor ordering, so
they can never disagree about access or about where a basement sorts.

**Both name the tenant trading in each space.** That is why they are gated at all: an external
maintenance contractor must not be able to read every retailer's name off a floor plan. (They could,
until the gate was added — the page had no access check whatsoever, and a missing method looks like
nothing.)

**Out-of-service space stays in the denominator.** A bay closed for resurfacing is not lost letting;
counting it as free would make a mall look worse the more diligently it maintains its car park.

---

# Part 8 — Rentable items — parking, storage, signage

## 8.1 The one rule

> **A rentable item is billable and is never leasable area.**

It never reaches the mall's total m², the occupied m², the CAM denominator or the rent roll's
per-m² figure. That is the market rule, and it is independent of who holds the bay.

## 8.2 The holder is an *agreement*, not a lease

A tenant holds a bay through their **lease**. A unit owner holds one through their **unit
ownership**, and the charge rides their monthly صيانة assessment.

**The double-let guard reads "live" differently per holder:**

| Holder | Counts as holding while |
|---|---|
| `Lease` | `active` or `pending_approval` |
| `UnitOwnership` | anything except `transferred` — a sold-on unit's former owner holds nothing |

A `contracted` owner may take a bay **before handover** — the bay is part of what he is buying —
and handover governs when it starts being *charged*, not when it can be *recorded*.

## 8.3 Assign and release

- The assign picker offers only what is **free now** and lettable. A bay released effective the 31st
  is still held on the 29th and correctly stays out of the list.
- The picker names the **kind** of item, not just its code — `SGN-A · EGP 8,000.00` told you nothing
  about whether that was signage or a bay.
- It deliberately does **not** print a status: every option is available by construction, so a
  column of "available" would be a column of one value.
- **Re-assigning an item to the lease that already holds it is refused** — *"This lease already
  holds P-101. Give it back first if you need to change the date or the rate."*

## 8.4 The status column has a nightly sweep too

`rentable_items.status` is the third projected column (with `units.status` and `leases.status`).
When a lease reaches expiry nothing closed the holding, so a bay read `assigned` for ever and an
operator filtering the register for *Available* could not see a free bay.

**What did not break is why it survived so long:** the bay stayed re-lettable throughout, because
the letting guard asks the *holder's* liveness, not this column. Nothing failed; a screen
under-reported. `leases:expire` sweeps it now.

---

# Part 9 — Unit owners — the shop that was sold

Some units are **sold** to investors rather than let. That buyer:

- is a `tenants` row (the same counterparty table — do not be confused by the word),
- holds **no lease**,
- pays a monthly **صيانة assessment** instead of rent, billed by `billing:run-assessments`,
- is an ordinary **CAM participant** — a handed-over ownership takes a share of the pool exactly as
  a lease does,
- **may let his own unit** to a retailer.

Two things to know:

1. **A management fee is recorded and charged by nothing yet.** The field states so on its face, and
   a test fails the day someone builds it. It is blocked on the accountant naming the GL account —
   the fee is Eltizam's revenue, not the property's, and guessing puts the operator's income into
   the owner's P&L. ([STATUS §B2](../STATUS.md))
2. `assessment_basis` answers the same question a lease's CAM share does: `area` (default, changes
   nothing), `participation`/`stated` (a named percentage), or `purchase_value` (re-cuts the
   purchase-value owners among themselves and is aggregate-neutral, so no lease moves because a
   neighbouring unit was sold).

---

# Part 10 — The property switcher, and isolation

**One rule: you are in exactly one mall at a time, and every list you open is that mall's.**

## 10.1 What the switcher actually does

It changes the tenant segment of the URL. Every resource scopes itself to it. A property you are
not assigned to is not merely hidden — typing its URL gives you a **404**, because the panel refuses
to enter a mall you do not hold.

## 10.2 The property field is pinned, not a picker

On a form, the property is `PropertyField` — **pinned, disabled, and still submitted**. It is not a
dropdown you choose from. Before this, seven screens *offered* other properties and then refused
them at submit with a bare 403, and six financial statements ran under a *"Consolidated (all)"*
caption while clamping every figure back to the selected mall — right figures, wrong caption, which
nobody re-checks.

**Five screens are different and it matters.** The posting map, departments, holidays, document
wording and owner requests ask a *scope* question, not a *which mall* question:

> ( ) All properties   ( ) This mall only

A blank property there means **every mall** — it is the house default that every resolver falls back
to. That is why those five carry a two-option control instead of a picker.

## 10.3 A NULL property means "portfolio", and it is invisible to a naive filter

Some rows are filed against **no** property: portfolio-level overhead, the house wording default,
a departmental cost. `WHERE asset_id IN (...)` never matches NULL, so those rows silently vanish
from anything property-scoped.

Two places you will meet this:

- **Financial statements** now print a notice sizing what they left out — *"N entries, EGP X, filed
  against no property"* — instead of quietly omitting money. It is silent on clean books.
- `php artisan atriom:audit-property-dimension` is the command that **finds and fixes** those rows.
  Run it before a deploy or an import.

---

# Part 11 — What space feeds

Every one of these divides by a number from this module. If the space register is wrong, they are
all wrong together and none of them says so.

| Consumer | What it takes from space |
|---|---|
| **CAM apportionment** | `Unit::areaOn($date)`, time-weighted by the days the tenant occupied |
| **CAM denominator** | Either occupied m², the property's declared GLA, or a fixed figure — `denominator_basis` |
| **Rent roll** | The lease's area, and the EGP/m²/yr derived from it |
| **Rent from a rate** | `base_rent = rate × area ÷ 12` — moving an area re-derives rent |
| **Occupancy cost %** | Tenant's total occupancy cost ÷ their sales |
| **Expiration schedule** | Area **and** income at risk, per year |
| **Property isolation** | `asset_id` — the single dimension the whole system is confined by |
| **Facility routing** | The unit's zone → that zone's supervisors |

---

# Part 12 — "I clicked and nothing happened"

| What you see | What it means | What to do |
|---|---|---|
| *"This unit already has an active lease"* | A lease on that unit is still `active` — possibly one whose term ended and never expired | Check the lease. Run `leases:expire` if its term is genuinely over |
| The status you typed reverted | Status is projected. You typed a value the projection overwrote | Change the lease, not the unit |
| A unit stays `occupied` after a give-back | The give-back is dated in the past and no write has crossed that date | The nightly sweep fixes it; or touch the lease |
| The area field is greyed out on Edit | Correct — area is a dated record | Use the **Remeasure** action |
| Economic occupancy is far below physical | Units with no area, or the vacancy is in your big units | Check for blank `area_sqm` first |
| A property is missing from the switcher | You are not assigned to it | Ask someone who holds it. You cannot grant yourself a property |
| The property dropdown shows one option and is disabled | Working as designed — no screen offers a mall other than the one you are in | — |
| Deleting a unit is refused | It has been leased, has requests, or has meters | Set it to `maintenance` instead. A unit that has traded is part of the property record |
| Deleting a property is refused | It has books | Deactivate it. Deleting would orphan every ledger, payroll and register that reports on it |

---

# Part 13 — The seven rules

1. **Never type a unit status.** The only real choice is `maintenance`, and it is sticky for ever.
2. **Never edit `area_sqm`.** Use **Remeasure**, or the money and the screens will disagree.
3. **The master unit of a lease can never change.** Moving out of it is a relocation.
4. **Never write `lease_unit` by hand.** `syncUnits()` mirrors the master and re-projects occupancy.
5. **A rentable item is never GLA.** Do not "fix" it into the area figures.
6. **A property code is inside every document number.** Choose it once.
7. **A NULL property is a real answer** — it means portfolio-wide. Never filter it away silently.

---

## Related

- [`../modules/01-properties-units.md`](../modules/01-properties-units.md) — the developer's doc
- [`../modules/35-rentable-items.md`](../modules/35-rentable-items.md) · [`37-unit-owners.md`](../modules/37-unit-owners.md) · [`30-areas.md`](../modules/30-areas.md)
- [`../PROPERTY-ISOLATION.md`](../PROPERTY-ISOLATION.md) — the invariant, in full
- [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md) — what happens once the space is let
- The **Guide** button on every list screen — that one screen, in both languages
