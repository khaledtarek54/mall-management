# 09 — Space, floors and parking: how Yardi models what gets let

> Written 2026-08-10, before any code, because the 2026-08 benchmark cycle mentioned parking exactly
> once — in passing, as a lease clause — and nobody had researched it. Building it from instinct
> would have broken the "follow Yardi" rule rather than honoured it.
>
> Same conventions as [01](01-yardi-lease-administration.md): *(cited: …)* is sourced,
> ***(verify — …)*** is an inference that still needs confirming.

---

## 1. The question that actually matters

Not "can Atriom store a parking space" — it obviously can. The question is **whether a parking
space is a `Unit`**, and the answer decides three numbers that are already live:

| If parking becomes a `Unit` with an `area_sqm`… | …then |
|---|---|
| `Asset::totalUnitAreaSqm()` sums **every** unit, unfiltered | the CAM denominator grows by the car park, **every tenant's share falls**, and the landlord silently absorbs the difference |
| `Asset::areaOccupancyRate()` divides occupied by that same total | the mall reports as massively vacant because a car park is not "occupied" |
| `ReportService::rentRoll()` divides rent by area | EGP/m²/yr becomes meaningless — the comparison number the whole rent roll exists for |

None of those three would throw. They would just quietly report the wrong number, which is the worst
failure mode this codebase has. **That is the whole reason this document exists.**

---

## 2. Yardi's answer: parking is a *rentable item*, not a space

*(cited: "Rentable Items allows you to track items such as Garages, Carports, Parking Spaces or
Washers/Dryers by assigning them an item number. It allows you to manage who has what and when.")*

Voyager keeps **two separate registers**:

- **Spaces** (the commercial term for what Atriom calls units) — the lettable floor area that carries
  rent, CAM share, occupancy and the stacking plan.
- **Rentable items** — garages, carports, parking spaces, storage, signage. Identified by an **item
  number**, assigned to a lease, billed by their own charge code, and **carrying no leasable area**.

*(cited: "you can assign Rentable Items and Service Charges to both new and existing residents")* —
the assignment is a lease-level act, so the same lease holds both its space and its items.

**Billing is an ordinary recurring lease charge.** *(cited: "Pet rent, parking charges and/or other
charges that will be recurring monthly should be entered under the lease charges column"; a charge
needs "the charge code (rent, petrent, storage, etc.), the amount, and the date the charge started")*.
And *(cited)* a **new rentable item mid-month prorates**, exactly like a mid-month move-in.

So in Yardi terms parking is: an item register + an assignment + a dated recurring charge on a
charge code. Not a space, and not a bespoke module.

## 3. Why the separation is a money rule, not a modelling preference

The industry definition backs it independently of Yardi:

*(cited: "Parking structures and garages, whether off-street parking areas are under or adjacent to
the building, are not part of GLA because they are not leasable floor area.")*

*(cited: "a shopping mall's gross leasable area would include the square footage of individual retail
units but not the square footage of parking lots.")*

*(cited: "The GLA denominator in a retail lease is typically the aggregate of all tenant spaces… This
means parking areas, being non-leasable, are not included in calculating a tenant's pro-rata share
of CAM expenses.")*

The same source excludes mall corridors, food courts, common restrooms and plant rooms from GLA for
the same reason: *(cited: "only space that can be rented to tenants counts toward the GLA
denominator")*.

**Conclusion for Atriom:** parking must be assignable and billable, and must never reach
`totalUnitAreaSqm()`, `occupiedAreaSqm()`, the CAM denominator, or the rent roll's per-m² figure.

## 4. Floors — and where the research changed the recommendation

I expected to recommend a first-class `Floor` entity. The evidence does not support going that far.

In core Voyager, **floor is an attribute of the space**, not its own register. The floor-and-stacking
capability is a *separate product*: *(cited: Yardi "Floorplan Manager… facilitates the design,
organization and tracking of spatial layouts in a building… integrating with and overlaying the rich
property and lease data from your Voyager database")*, and *(cited)* it "enables clients to create
floor and stacking plans with real-time lease metrics and instant area calculations."

The stacking plan itself is a **view over lease data**, not a new hierarchy: *(cited: "Within the
stacking plan, you can view option details such as expiration dates and notice history, access lease
abstract… and view outstanding proposals related to vacant units")*, with *(cited)* vacant units
coloured distinctly.

**So the standard answer is: keep floor on the unit, but make it ORDERABLE and consistent.** Atriom's
`units.floor` is a free-text string — which is not a modelling nicety, it is a live defect:

```
floors as ordered by the Occupancy Map today:  1 → Ground
```

`OccupancyMap` does `->orderBy('floor')` on a string, so the ground floor renders **below** the
first floor. Add a basement or a tenth floor and it degrades further ("10" sorts before "2"), and
free text means "Ground"/"G"/"ground" are three different floors to any report.

A `floor_level` ordinal (basement negative, ground 0, first 1…) beside the existing label fixes the
sort, makes floors groupable, and is the precondition for a stacking plan — without inventing a
hierarchy Yardi's own core does not have. ***(verify — whether per-floor GLA / common-area figures
are ever needed; if they are, that is the trigger to promote `Floor` to an entity, and not before.)***

## 5. Atriom today, row by row

| Capability | Yardi | Atriom today | Verdict |
|---|---|---|---|
| Lettable space register | ✅ Spaces | ✅ `Unit` — area, status, category, zone (`area_id`) | ✅ KEEP |
| **Rentable items (parking, storage, signage)** | ✅ own register + item number | ❌ **nothing**. `parking` exists only as an `invoice_items.type` value | ➕ **BUILD** |
| Item assigned to a lease, dated | ✅ | ❌ | ➕ BUILD — reuse the dated `lease_unit` pattern |
| Item billed by its own charge code, prorated | ✅ | 🟡 the charge schedule can express it; nothing creates it | ➕ BUILD — a `parking` charge row, so it flows through billing/VAT/GL unchanged |
| Item area excluded from GLA | ✅ by definition | ⚠️ **would break if parking were a Unit** — see §1 | 🔴 the constraint to design against |
| Floor on the space | ✅ attribute | 🟡 free-text string, sorts wrongly | ➕ EXTEND — add an ordinal |
| Stacking plan | ✅ (Floorplan Manager) | ❌ | 🟡 later — needs the ordinal first |
| Occupancy / vacancy by floor | ✅ | 🟡 `OccupancyMap` groups by floor, in the wrong order | ➕ EXTEND — falls out of the ordinal |

## 6. What this means for the build

1. **Parking is its own register**, not a unit type. A `parking_space`-style model with a code, a
   zone, a status and an optional monthly rate — carrying **no leasable area**.
2. **Assignment is dated on the lease**, mirroring `lease_unit`, so a lease that takes two more bays
   in March is expressible and the money follows the date.
3. **Billing goes through the existing charge schedule** on a `parking` charge type. Nothing new in
   invoicing, VAT, or the GL — parking income lands as an ordinary charge row. VAT treatment is a
   question for the accountant ***(verify — Egyptian VAT on parking; rent is exempt, service charge
   is standard-rated, and parking is neither obviously)***.
4. **Floors get an ordinal**, the occupancy map is sorted by it, and the stacking plan becomes
   possible without a new hierarchy.
5. **A conformance test should pin §1** — that no lettable-area calculation can ever see a parking
   space. That is the invariant this whole document is about, and it is exactly the kind of rule this
   codebase has learned to gate rather than trust.

---

## Sources

- [Yardi Rentable Items Set Up — Property Vista](https://support.propertyvista.com/hc/en-us/articles/360052226833-Yardi-Rentable-Items-Set-Up) — rentable items track garages/carports/parking spaces by item number; assigned to leases
- [Adding a recurring lease charge in Voyager — Affinity Support](https://affinityproperty.happyfox.com/kb/article/82-adding-in-a-reoccurring-lease-charge-in-voyager/) — parking billed as a recurring lease charge; charge code + amount + start date; new rentable items prorate
- [Floorplan Manager — Yardi](https://www.yardi.com/product/floorplan-manager/) · [press release](https://www.yardi.com/news/press-releases/yardi-launches-floorplan-manager-to-optimize-space-management/) — floor and stacking plans as a layer over Voyager lease data
- [Elevate Suite for Commercial — Yardi](https://www.yardi.com/suite/elevate-suite-commercial/) — stacking plan contents: option dates, notice history, lease abstract, vacant-unit proposals
- [Gross Leasable Area (GLA) and CAM impact — CAMAudit](https://www.camaudit.io/partners/resources/expense-reduction-consultants/gross-leasable-area) — parking excluded from GLA; GLA denominator is the aggregate of tenant spaces
- [GLA — Janover](https://www.commercialrealestate.loans/commercial-real-estate-glossary/gla-gross-leasable-area/) — mall GLA includes retail units, excludes parking lots
- Atriom side: [`Asset::totalUnitAreaSqm()`](../../../app/Models/Asset.php), [`CamReconciliationService`](../../../app/Services/CamReconciliationService.php), [`OccupancyMap`](../../../app/Filament/Admin/Pages/OccupancyMap.php), [module 01](../../modules/01-properties-units.md)
