# 09 — Space, floors and parking: how Yardi models what gets let

> Written 2026-08-10, before any code, because the 2026-08 benchmark cycle mentioned parking exactly
> once — in passing, as a lease clause — and nobody had researched it. Building it from instinct
> would have broken the "follow Yardi" rule rather than honoured it.
>
> Same conventions as [01](01-yardi-lease-administration.md): *(cited: …)* is sourced,
> ***(verify — …)*** is an inference that still needs confirming.

> ### ✅ §5 and §6 are CLOSED — re-verified against the code on 2026-09-01
>
> **Sections 1 to 4 are the research and still stand. Sections 5 and 6 do not.** The verdict table
> and the build list describe as absent a register that shipped **the same day this document was
> written** — `c470a0dc`, 2026-08-10, *"the rentable-item register, and floors that sort"* — and they
> are the two sections a reader actually reaches for. §4 got its *Resolved* note that same afternoon;
> §5 and §6 never did, so the table still reads *"Rentable items — ❌ **nothing**"* about
> [`App\Models\RentableItem`](../../../app/Models/RentableItem.php), and the conformance gate §6 asks
> for as future work has existed for as long as the register has.
>
> Both sections keep their text. It is the case that was made, and the reasoning behind it — above
> all §1's three numbers — is what any future change has to respect. Each now carries a dated
> **CLOSED** note naming what closed it. The module doc is
> [35 — Rentable items](../../modules/35-rentable-items.md).

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

> **Read "resident" as the CUSTOMER RECORD, not as "lease" (clarified 2026-08-19).** Atriom
> implemented the assignment against a lease, because a lease was the only agreement it had when
> rentable items were built — and that quietly excluded the unit owner, who in Voyager Condo/Co-Op
> is himself the customer record that dues post to. The pivot is now polymorphic over
> `BillableAgreement`, so a tenant holds a bay through a lease and an owner-occupier through his
> ownership, each billed on its own schedule. Nothing about Voyager's model changed here; what
> changed is that Atriom had read one word more narrowly than the benchmark meant it.

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

**So the standard answer is: keep floor on the unit, but make it ORDERABLE and consistent.**

> **Correction (2026-08-10).** The first version of this section claimed the Occupancy Map renders
> the ground floor below the first floor. That was wrong — I read `->orderBy('floor')` out of
> context; it is the third clause of a three-clause `orderByRaw` that already special-cases the
> ground floor. The map's common case is correct. The real defect is narrower and still worth
> fixing, and it is stated accurately below.

`units.floor` is free text, so `OccupancyMap` carries a workaround: a CASE lifting 'ground'/'g'/'0'
to the front, then `length()`, then the value. It handles Ground → 1 → 2 → 10, and then:

```
Ground → 1 → 2 → 10 → Basement → Mezzanine
```

A basement sorts **after the tenth floor**, because the CASE only knows about the ground floor and
`length()` is doing the rest. Three things the workaround cannot fix: it is raw SQL on `lower()` and
`length()` (the cross-database hazard this project has hit twice), it lives in one screen so every
other consumer still gets plain string order, and free text leaves "Ground"/"G"/"ground" as three
different groups to anything that groups rather than sorts.

A `floor_level` ordinal (basement negative, ground 0, first 1…) beside the existing label fixes the
sort, makes floors groupable, and is the precondition for a stacking plan — without inventing a
hierarchy Yardi's own core does not have. **Resolved 2026-08-10:** `Floor` became an entity for a different reason (a per-property register
that units and rentable items both select from), and per-floor GLA turned out to need no column at
all — it is a sum over the units standing on the floor, shown on the property's Floors tab through
the same `App\Support\Occupancy` definition the property and dashboard use. Storing it would have
been a second truth about the same square metres.

## 5. Atriom today, row by row

| Capability | Yardi | Atriom today | Verdict |
|---|---|---|---|
| Lettable space register | ✅ Spaces | ✅ `Unit` — area, status, category, zone (`area_id`) | ✅ KEEP |
| **Rentable items (parking, storage, signage)** | ✅ own register + item number | ❌ **nothing**. `parking` exists only as an `invoice_items.type` value | ➕ **BUILD** |
| Item assigned to a lease, dated | ✅ | ❌ | ➕ BUILD — reuse the dated `lease_unit` pattern |
| Item billed by its own charge code, prorated | ✅ | 🟡 the charge schedule can express it; nothing creates it | ➕ BUILD — a `parking` charge row, so it flows through billing/VAT/GL unchanged |
| Item area excluded from GLA | ✅ by definition | ⚠️ **would break if parking were a Unit** — see §1 | 🔴 the constraint to design against |
| Floor on the space | ✅ attribute | 🟡 free-text string; one screen carries raw-SQL ordering that puts a basement after floor 10 | ➕ EXTEND — add an ordinal, delete the workaround |
| Stacking plan | ✅ (Floorplan Manager) | ❌ | 🟡 later — needs the ordinal first |
| Occupancy / vacancy by floor | ✅ | 🟡 `OccupancyMap` groups by floor label, so Ground/G/ground are distinct groups | ➕ EXTEND — falls out of the ordinal |

> **CLOSED 2026-08-10, extended 2026-08-19 and 2026-08-27, re-verified 2026-09-01.** Row by row, in
> the table's own order.
>
> **The register.** [`App\Models\RentableItem`](../../../app/Models/RentableItem.php) is Voyager's
> rentable items rather than a parking module, exactly as §2 argued: one register whose `type` is
> `parking`, `storage`, `signage` or `kiosk`, with a code unique per property, a `monthly_rate` and a
> status. It is `#[PropertyOwned]` and `#[DeletableWhenUnused]`, and it reaches the panel as
> `/admin/rentable-items`.
>
> **The assignment is dated, and the holder is an AGREEMENT rather than a lease.**
> `rentable_item_holdings` is the pivot this row asked for, modelled on `lease_unit` and carrying
> `effective_from`, `effective_to` and the rate the item was let at. Since 2026-08-19 it is
> polymorphic over `BillableAgreement`, so an owner-occupier holds a bay through his unit ownership
> and a tenant through his lease — the correction §2 already records, that Voyager assigns rentable
> items to the customer RECORD and Atriom had read one word more narrowly than the benchmark meant.
> [`AssignRentableItemService`](../../../app/Services/AssignRentableItemService.php) takes a
> `lockForUpdate` on the contended item, and the double-let guard `RentableItem::isHeldOn()` asks
> **per holder**, which is the invariant that refactor could most easily have broken.
>
> **Billing goes through the ordinary charge schedule and nothing more.** `rebuildCharge()` sums what
> is held on the day and writes one `parking` charge row through `ChargeScheduleService`, with
> `first_row_from_effective` so a bay taken on 1 March is not back-charged to the commencement date,
> and with no `prorate` flag — so `charges.prorate` stays null, meaning *prorate by the lease's own
> method*, which is the mid-month behaviour §2's citation names. When the last item goes back the row
> is **closed** rather than re-opened at zero: `setAmount(0)` once put *"Parking & rentable items —
> EGP 0.00"* on every invoice for the rest of the term, and a charge for nothing is not a charge.
>
> **The one thing this row asked for that is genuinely NOT built is a charge code per item type.**
> All four types bill through the single `parking` code, so signage and storage income cannot be told
> apart in the general ledger and cannot carry a VAT treatment different from parking's. Half of that
> was deliberate and is now correct: the catalogue row is named *"Parking & rentable items"* precisely
> because it bills all four, and `ChargeCode::labelFor()` was fixed in `b826215c` to read that row
> before the lang key, so a signage licence no longer appears on the billing forecast under a heading
> naming car parks. The other half is real and small — one seeded code per type in
> [`ChargeCodeSeeder`](../../../database/seeders/ChargeCodeSeeder.php), each a row the accountant can
> point at its own `tax_code`, and `rebuildCharge()` grouped by type instead of summed across it. It
> is worth doing the day a supply here is rated differently from a bay, and not before.
>
> **The GLA constraint is structural, not merely tested.** `RentableItem` carries **no area column at
> all**, so there is nothing for a future report to find and sum, and
> [`RentableItemNotLettableAreaTest`](../../../tests/Feature/Regression/RentableItemNotLettableAreaTest.php)
> pins it as deliberate *"nothing changed"* assertions: a property that lets fifty bays must produce
> figures byte-identical to one that lets none, across `Asset::totalUnitAreaSqm()`,
> `areaOccupancyRate()`, `CamReconciliationService` and the rent roll's EGP/m²/yr. That is §6's item
> 5, and it is the gate this whole document exists to ask for.
>
> **Floors became a register, and the workaround described above is gone.**
> `2026_08_10_160000_create_floors_and_move_units_onto_them` created `floors` with an integer `level`
> (basement negative, ground 0), backfilled one row per distinct label already in use, and **dropped
> `units.floor`** — the free text this section is about. The three-clause `orderByRaw` is replaced by
> [`App\Support\Filament\FloorGrouping`](../../../app/Support/Filament/FloorGrouping.php), a
> correlated subquery on `floors.level` shared by both floor plans, so a basement can no longer sort
> after the tenth floor and *Ground* / *G* / *ground* are no longer three groups to anything that
> groups rather than sorts. **A stated deviation, and §4 was right that promoting the floor is one:**
> core Voyager keeps floor as an attribute of the space. Atriom promoted it because units *and*
> rentable items both point at the same register, and a second free-text floor on the item register
> would have had no guarantee of agreeing with the units'.
>
> **Occupancy by floor needed no column.** `Floor::areaFigures()` sums the units standing on the floor
> through the shared `App\Support\Occupancy` — the same definition the property and the dashboard
> read — because storing it would be a second truth about the same square metres, drifting the first
> time a unit is re-measured.
>
> **The stacking plan exists twice.** `/admin/occupancy-map` renders every unit as a card grouped by
> floor and coloured by status, with per-status counts and a status filter (the gap analysis corrected
> its own row on 2026-08-19: earlier passes called it absent because they grepped for the phrase).
> [`RentableItemMap`](../../../app/Filament/Admin/Pages/RentableItemMap.php) (2026-08-27) is the same
> plan for bays, storage, signage and kiosks — deliberately a **separate page**, because a parking bay
> is not a unit and the two have different holders and different pricing. Both order through
> `FloorGrouping`, so they cannot disagree about where a basement sorts. On the item map an
> out-of-service bay leaves the **denominator** rather than counting as vacant: a bay closed for
> resurfacing is not lost letting, and counting it as vacant makes a mall look worse the more
> diligently it maintains its car park.
>
> **And `rentable_items.status` is a swept projection**, registered in
> [`App\Support\ProjectedState`](../../../app/Support/ProjectedState.php) and re-derived nightly by
> `leases:expire`. It has to be: a lease reaching its expiry date is not a write, so nothing fired and
> a bay whose lease had ended read `assigned` for ever. Nothing actually broke —
> `RentableItemOptions::lettable()` rejects on `isHeldOn()` and never on this column, so the bay stayed
> re-lettable throughout — which is precisely why nobody hit it: the damage was to the register an
> operator reads, not to the letting.
>
> **Two things §4's citations name that Atriom still does not have**, neither of them a defect in the
> space model. Voyager's stacking plan overlays *"option details such as expiration dates and notice
> history"* per unit; the occupancy-map card carries the unit code, the trading tenant's name and the
> status, while expiries live on `/admin/expiration-schedule` and notice windows on
> `leases:scan-option-windows`. One line on the occupied card off the already eager-loaded
> `activeLease` would close it, which makes it a refinement rather than a gap. The other is
> *"outstanding proposals related to vacant units"*, and there is **no counterpart at all**: Atriom
> has no prospect, LOI or deal record anywhere, so a vacant card can say nothing about a negotiation
> in progress (`WorkOrderProposal` is a contractor's quote from module 12b and unrelated). That is
> leasing-pipeline territory rather than space modelling, and it is currently recorded as neither a
> gap nor a decline — it should be decided once, so the next sweep does not re-derive it.

## 6. What this means for the build

1. **Parking is its own register**, not a unit type. A `parking_space`-style model with a code, a
   zone, a status and an optional monthly rate — carrying **no leasable area**.
2. **Assignment is dated on the lease**, mirroring `lease_unit`, so a lease that takes two more bays
   in March is expressible and the money follows the date.
3. **Billing goes through the existing charge schedule** on a `parking` charge type. Nothing new in
   invoicing, VAT, or the GL — parking income lands as an ordinary charge row. VAT treatment is a
   question for the accountant ~~***(verify — Egyptian VAT on parking; rent is exempt, service charge
   is standard-rated, and parking is neither obviously)***~~ — **the marker is answered; see the note
   below.**
4. **Floors get an ordinal**, the occupancy map is sorted by it, and the stacking plan becomes
   possible without a new hierarchy.
5. **A conformance test should pin §1** — that no lettable-area calculation can ever see a parking
   space. That is the invariant this whole document is about, and it is exactly the kind of rule this
   codebase has learned to gate rather than trust.

> **CLOSED — items 1, 2, 4 and 5 are covered by the §5 note above; item 3's verify-marker was settled
> on 2026-08-12, and the answer is that it was never a code question.** *Which supplies are taxable*
> is `charge_codes.tax_code`, the accountant's own ruling and a **row**:
> [`ChargeCodeSeeder`](../../../database/seeders/ChargeCodeSeeder.php) seeds the `parking` code
> pointing at `VAT_EXEMPT`, and `rebuildCharge()` writes `vat_rate => null` so the rate is resolved
> from the tax catalogue at each billing's **origination** rather than frozen onto the charge row
> (EG-01 — for a monthly row, origination is the billing, not the day the bay was assigned).
> Repointing the code at a taxable rate on `/admin/charge-codes` therefore reaches every lease already
> on the books, needs **no deploy**, and leaves every invoice already issued on the treatment it was
> billed at.
>
> It ships **exempt** for a stated reason rather than a researched one: parking is a licence to use a
> space rather than a lease of it, the VAT Law schedules settle that and a developer does not, and
> under-charging a tenant beats collecting tax that may not be due and having to refund it. The
> reasoning is recorded at the write itself, in
> [`AssignRentableItemService::rebuildCharge()`](../../../app/Services/AssignRentableItemService.php),
> together with the history that makes it worth stating: it was a settings toggle of its own, then a
> `vat_treatment` column, then the tax catalogue — **one question with three homes over three days**,
> which is how they come to disagree. The remaining sharpness is the one the §5 note names: because
> all four item types share the `parking` code, ruling signage taxable would tax parking with it.

---

## Sources

- [Yardi Rentable Items Set Up — Property Vista](https://support.propertyvista.com/hc/en-us/articles/360052226833-Yardi-Rentable-Items-Set-Up) — rentable items track garages/carports/parking spaces by item number; assigned to leases
- [Adding a recurring lease charge in Voyager — Affinity Support](https://affinityproperty.happyfox.com/kb/article/82-adding-in-a-reoccurring-lease-charge-in-voyager/) — parking billed as a recurring lease charge; charge code + amount + start date; new rentable items prorate
- [Floorplan Manager — Yardi](https://www.yardi.com/product/floorplan-manager/) · [press release](https://www.yardi.com/news/press-releases/yardi-launches-floorplan-manager-to-optimize-space-management/) — floor and stacking plans as a layer over Voyager lease data
- [Elevate Suite for Commercial — Yardi](https://www.yardi.com/suite/elevate-suite-commercial/) — stacking plan contents: option dates, notice history, lease abstract, vacant-unit proposals
- [Gross Leasable Area (GLA) and CAM impact — CAMAudit](https://www.camaudit.io/partners/resources/expense-reduction-consultants/gross-leasable-area) — parking excluded from GLA; GLA denominator is the aggregate of tenant spaces
- [GLA — Janover](https://www.commercialrealestate.loans/commercial-real-estate-glossary/gla-gross-leasable-area/) — mall GLA includes retail units, excludes parking lots
- Atriom side: [`Asset::totalUnitAreaSqm()`](../../../app/Models/Asset.php), [`CamReconciliationService`](../../../app/Services/CamReconciliationService.php), [`OccupancyMap`](../../../app/Filament/Admin/Pages/OccupancyMap.php), [module 01](../../modules/01-properties-units.md)
- Atriom side, what this document became: [`RentableItem`](../../../app/Models/RentableItem.php), [`AssignRentableItemService`](../../../app/Services/AssignRentableItemService.php), [`Floor`](../../../app/Models/Floor.php), [`FloorGrouping`](../../../app/Support/Filament/FloorGrouping.php), [`RentableItemMap`](../../../app/Filament/Admin/Pages/RentableItemMap.php), [`RentableItemNotLettableAreaTest`](../../../tests/Feature/Regression/RentableItemNotLettableAreaTest.php), [module 35](../../modules/35-rentable-items.md)
