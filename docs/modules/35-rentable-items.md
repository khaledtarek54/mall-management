# 35 — Rentable items (parking, storage, signage)

> Parking bays, storage cages, signage faces and kiosk pitches: **let alongside a lease, billed like
> any other charge, and never counted as lettable area.**
>
> Benchmark: [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../benchmarks/yardi/09-yardi-space-and-parking.md).



> **The option says what KIND of thing it is (2026-08-28).** A mall lets bays, signage, storage and
> kiosks from one register, and the assign picker read `SGN-A · EGP 8,000.00` — which tells the
> operator what kind only through a code they chose themselves. Anyone who inherited the register
> could not tell a signage licence from a parking bay. It now names the type, from **the same lang
> group the resource's own table and form use**, so a second spelling cannot drift from the screen
> the operator just came off.
>
> **The STATUS is deliberately still absent**, which answers the question that came with it: the
> query already offers only what is lettable — out-of-service excluded, anything currently held
> rejected — so every option is available by construction and printing "available" on all of them
> would be a column of one value. An occupied bay is not a choice, and a picker that offered one
> would be offering a value the write guard then refuses.



> **⚠️ The picker offered what the service refuses (fixed 2026-08-28).** The options query excluded
> the holder's OWN holdings from the clash test, under a comment saying that re-assigning a bay it
> already holds should read as *"you have this"* rather than *"someone has this"*. But
> `AssignRentableItemService::assign()` refuses exactly that — *"This lease already holds P-101.
> Give it back first if you need to change the date or the rate."* — so the intent was never
> realised and the comment described behaviour that did not exist.
>
> Reported from the panel: a lease holding all three of a property's items was offered all three,
> and picking one failed on submit. **A picker whose value the write guard rejects is the worst
> kind**, because the operator has already decided by the time they are told no. The clash test now
> has no exception, and the service's refusal stays as the backstop for a crafted submit — the
> picker is UI, not a gate.
>
> The list answers **"free NOW"**, which is the question an assignment starting today is asking: a
> bay released effective the 31st is still held on the 29th and correctly stays out. That caught my
> own test first, which expected it back the moment the release was recorded.
> (`APickerNeverOffersWhatTheGuardRefusesTest`, proven by restoring the exception.)


## The holder is an AGREEMENT, not a lease (2026-08-19)

`lease_rentable_item` is now **`rentable_item_holdings`**, keyed on a polymorphic
`holder_type`/`holder_id` over `BillableAgreement`. A tenant holds a bay through a **lease**; an
owner-occupier holds one through his **unit ownership**.

**This is Voyager's model read correctly, not an extension of it.** Rentable items are assigned to
the customer RECORD — *(cited, [benchmark 09](../benchmarks/yardi/09-yardi-space-and-parking.md)
§2)* "you can assign Rentable Items and Service Charges to both new and existing **residents**" —
and in Voyager Condo/Co-Op the unit owner simply **is** that record, with dues posting to his
ledger (see [modules/37 §7](37-unit-owners.md)). Atriom had narrowed "customer record" to "lease"
only because, when rentable items were built, a lease was the only agreement that existed.
Operator's decision (2026-08-19): an owner can hold a bay, and its charge **rides his monthly
صيانة assessment**, exactly as a tenant's rides the lease schedule.

**What did NOT change**, and must not: a rentable item still carries no leasable area, so it never
reaches `totalUnitAreaSqm()`, `occupiedAreaSqm()`, the CAM denominator or the rent roll's per-m²
figure. That is the market rule the benchmark cites from three independent sources, and it is
independent of who holds the bay.

### The guard most at risk, and how it is held

`RentableItem::isHeldOn()` is the **double-let** guard, and it asked only about leases. That was
correct while a lease was the only holder and becomes a real double-booking the moment an ownership
can hold one — a bay held by an owner would have looked free to the next tenant. It now asks per
holder, because "live" means different things:

| Holder | Counts as holding while |
|---|---|
| `Lease` | `active` or `pending_approval` |
| `UnitOwnership` | not `transferred` — a sold-on unit's former owner holds nothing |

A **`contracted` owner can take a bay before handover**, deliberately: the bay is part of what he is
buying, and `isBillable()` (handover) governs when it starts being *charged*, not when it can be
*recorded*. Both directions of the clash are pinned by `AnOwnerCanHoldAParkingBayTest`, each
refusal paired with a control.

### One picker, three surfaces

`App\Support\RentableItemOptions` builds both lists (what an agreement *could* take, what it
*holds*) for every surface. It exists because the copies drifted: the list lived twice — once on
`LeaseActions`, once inside the lease relation manager — and by 2026-08-18 one picked the item with
a plain `Select` over a raw column while the other went through the registry, so the same act found
different things depending on which button you pressed. A third copy for ownerships would have made
it three answers to one question.

`LeaseActionTopologyTest` was narrowed to LEASE tabs at the same time: `LeaseActions::forOwner()`
takes a `Lease`, so an ownership tab cannot compose it and cannot drift a lease act either. The
narrowing carries a vacuity guard, because a filter that matched nothing would make the gate pass by
examining no files.

## 1. Purpose & business context

A mall earns real money from things that are not shops. Atriom could not record any of it: `parking`
existed as an invoice-line type and nothing produced one. An operator letting forty bays tracked them
in a spreadsheet and typed the total onto the rent by hand.

Yardi keeps two registers — **spaces** (lettable floor area) and **rentable items** (garages,
carports, parking, storage), the latter identified by an item number, assigned to a lease, billed by
their own charge code, and carrying no leasable area. This module is that second register.

## 2. Domain model

| Table | Purpose |
|---|---|
| `rentable_items` | The register. `asset_id` · `floor_id` · `area_id` · `code` (unique per property) · `type` · `status` · `monthly_rate` (the ASKING price) · `notes` |
| `lease_rentable_item` | The dated assignment: `effective_from` / `effective_to` / `monthly_rate` (what this lease actually pays) |

`type` — `parking` · `storage` · `signage` · `kiosk`. `status` — `available` · `assigned` ·
`out_of_service`.

**There is no area column, deliberately.** See §3.

## 3. Business rules & invariants

### A rentable item is NEVER lettable area

The rule the whole module exists to protect. A bay must never reach `Asset::totalUnitAreaSqm()`,
`occupiedAreaSqm()`, the CAM denominator, or the rent roll's EGP/m²/yr. Stored as a `Unit` it would
have grown the CAM denominator (cutting every tenant's recovery share, with the landlord absorbing
the difference), reported the mall as massively vacant, and made the per-m² comparison meaningless —
**none of which would have thrown.**

A separate table makes that structural. `RentableItemNotLettableAreaTest` pins it as "nothing
changed" assertions, including a true A/B over two identical properties where one also lets forty
bays, and asserts the table **never gains an area column** — because every other guarantee could be
satisfied today and broken tomorrow by adding `area_sqm` "just for reporting".

### One charge row per lease, not per item

A lease with four bays has ONE `parking` charge summing what it pays for what it holds. Forced by
`Charge`'s overlap guard (two active rows of a type covering one period are refused), and
independently what belongs on an invoice. `AssignRentableItemService::rebuildCharge()` re-derives it
by **summing the register** — never incrementing, which drifts the first time an assignment is
corrected.

### The first charge row is dated from the assignment, not the commencement

`ChargeScheduleService::openFirstRow()` normally dates a type's first row to the lease commencement:
a charge that never existed should bill the lease's term. That is true of rent, which was merely
unrecorded — and **false** for anything acquired mid-term. A bay taken on 1 March was not held in
January. The service passes `first_row_from_effective`, and the schedule names the distinction where
the assumption lives.

### Double-booking is refused, and the lock is on the ITEM

Two operators letting the same bay to different tenants contend on the item row — locking the lease
would let both through. Same rule and same reasoning as the premises guard.

## 4. Lifecycle

`available` → **assign** → `assigned` → **release** (dated) → `available` the next day.
`out_of_service` is a manual withdrawal that the assign picker excludes; it is how a bay leaves
letting without being deleted. Deletion is refused once anything has held it
(`#[DeletableWhenUnused]`, blocked by `leases`).

## 5. Services

`App\Services\AssignRentableItemService` — `assign()` / `release()`. It moves no money itself: the
`parking` charge it writes is what bills, through the ordinary monthly run, VAT and GL. Nothing in
billing knows rentable items exist, which is the whole point of building on the charge schedule
rather than beside it.

## 6. Filament

`RentableItemResource` under **Leasing** (you reach it while doing a deal, not while doing
maintenance) — property-scoped, `rentable_items.*` permissions, floor and zone selects reading the
property's own registers, and the **current holder** shown in the table because "who has bay 42" is
the question an operator arrives with. Assign / release are actions on the lease.

## 7. Gotchas

- **VAT is DATA, not a constant** — the `parking` charge code's **VAT treatment** (Charge Codes →
  parking), like every other supply. Rent is exempt in Egypt, service charge is standard-rated, and
  parking is neither obviously: it is a licence to use a space rather than a lease of it, which the
  VAT Law schedules settle and a developer does not. **Ships exempt**, the conservative direction —
  under-charging the tenant beats collecting tax that may not be due and having to refund it. Read
  at ORIGINATION only (`Vat::rateForType('parking')` in `AssignRentableItemService`), so a ruling
  never rewrites an issued invoice. *(It was a settings toggle of its own, `TaxSettings::
  $parking_vat_applicable`, from 2026-08-10 to 2026-08-11 — retired when taxability moved onto the
  catalogue, because one question with two homes is how the two come to disagree. The migration
  carries the operator's answer across.)*
- **`monthly_rate` on the item is the asking price; the pivot's is what this tenant pays.** They
  differ whenever anything was negotiated, and the charge is built from the pivot.
- **Adding a type** means a `lang` entry in both files; the column is a string, not a DB enum.

- **`status` is a PROJECTION, and it was not swept (2026-08-26).** It is a stored column that is a
  function of TODAY, which is the shape `App\Support\ProjectedState` exists for: it goes wrong on a
  day when nothing happened. A bay is attached to a lease with an OPEN holding (`effective_to`
  null), and when that lease reaches its expiry date nothing closes the holding and nothing touches
  the item — a lease expiring is not a write. Measured: the bay read `assigned` for ever, so an
  operator filtering the register for *Available* to find a free bay could not see it. It was in
  neither `PROJECTIONS` nor `NOT_PROJECTED`, i.e. nobody had decided which it was.
- **What that did NOT break, which is why it survived.** The bay stayed re-lettable throughout:
  `RentableItemOptions::lettable()` rejects on `isHeldOn()`, which reads the HOLDER's liveness, and
  never on this column. Nothing failed and no double-let was possible — a screen simply
  under-reported, and a failure with no error in it is one nobody reports.
- **`isHeldOn()` and `isSpokenFor()` are two questions, and the difference is load-bearing.**
  `isHeldOn($date)` is date-ranged and answers *"is it occupied that day"* — the double-let guard.
  `isSpokenFor()` asks *"is it off the market"*: held OPEN-ENDEDLY by a live agreement. They
  genuinely differ, because `release()` marks a bay released effective 30 June as AVAILABLE the
  moment the release is recorded — the operator can let it from July, and `RentableItemAssignmentTest`
  pins that. **A projection built on `isHeldOn(today)` would have fought it** and flipped the bay
  back to `assigned` on the next nightly run. `recomputeStatus()` is the one place the column is
  decided, and `assign()`, `release()` and the sweep all go through it so they cannot drift.
- **The sweep is `leases:expire`, not a command of its own** — the staleness is caused by a lease
  reaching its expiry date, which is the event that command exists to notice, and a second command
  is a second thing to schedule and forget. `out_of_service` is never overwritten: a manual
  override, the same rule `maintenance` gets on a unit.
- **The map is `RentableItemMap` — `/admin/{mall}/rentable-item-map`** (2026-08-26). Until then
  there was no floor plan for this register at all: `OccupancyMap` queries `Unit` only,
  `Occupancy::forUnits()` is units-only, `Floor::areaFigures()` excludes these items deliberately
  (*"a parking bay is not a unit"*), and `ReportCatalogue` had no parking or utilisation entry. The
  only way to find a free bay was the LIST filtered by status — which under-reported, because the
  status column had no sweep. **The column was fixed first, on purpose**: a map over an
  untrustworthy status draws the same wrong answer more convincingly.
- **A separate page, not a mode of the unit map.** Yardi treats parking as its own space type with
  its own register, and this codebase already says the same in its own words. Different holders,
  different pricing, a different vacancy conversation. **What is shared is shared properly**:
  `MapsOneProperty` resolves the property for BOTH maps (who may map which mall, the `?asset=`
  clamp, the picker) and `App\Support\Filament\FloorGrouping` orders both by the floor register,
  so the two can never disagree about access or about where a basement sorts. Both pages — and the
  concern itself — are registered in `ReportFilters::EXEMPT`, because that gate sweeps file by file.
- **Gated from the first commit** on `rentable_items.view` OR `reports.view`, the same union its
  sibling takes. It names the HOLDER on every let tile, which is exactly the commercial data
  `OccupancyMap` was left open on until the same day.
- **Out-of-service items leave the DENOMINATOR, they are not counted as vacant.** A bay closed for
  resurfacing is not lost letting, and counting it as free makes a mall look worse the more
  diligently it maintains its car park. It is reported separately so it does not simply vanish.
- **`currentHolderLabel()` is the reading half of `isSpokenFor()`** — the same predicate answered
  with a name instead of a boolean, so a card can never name a tenant for an item the same page
  colours as available. Resolved in PHP against the eager-loaded relations, because a floor plan
  renders every bay at once and a query per card is the N+1 that makes a map feel broken.
- **Deliberately NOT deliverable** (`ReportCatalogue::NOT_DELIVERABLE`): the value is the
  at-a-glance read, and the register already exports the list.

## 8. Tests

`tests/Feature/Regression/RentableItemNotLettableAreaTest.php` (the invariant) ·
`tests/Feature/Regression/RentableItemAssignmentTest.php` (letting, releasing, re-letting, billing
through the real monthly run, and every refusal with a paired control) ·
`tests/Feature/Regression/AParkingBayIsFreedWhenItsLeaseEndsTest.php` (the projection: freed on
expiry, NOT flipped back after a future-dated release, `out_of_service` untouched, an ownership
holder still counted, and the picker and the register agreeing) ·
`tests/Feature/Pages/RentableItemMapTest.php` (the map: refused to six roles with a paired control
on five, scoped at the QUERY under a tampered selection, empty when no property resolves, the holder
named on a let tile, and the utilisation figure excluding out-of-service). Both mutation-proved —
deleting the sweep call turns two cases red, deleting the gate turns six red.
