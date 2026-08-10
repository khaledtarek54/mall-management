# 35 — Rentable items (parking, storage, signage)

> Parking bays, storage cages, signage faces and kiosk pitches: **let alongside a lease, billed like
> any other charge, and never counted as lettable area.**
>
> Benchmark: [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../benchmarks/yardi/09-yardi-space-and-parking.md).

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
(`DeletionPolicy::WHEN_UNUSED`, blocked by `leases`).

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

- **VAT is a SETTING, not a constant** — `TaxSettings::$parking_vat_applicable`, on the Settings →
  Tax tab. Rent is exempt in Egypt, service charge is standard-rated, and parking is neither
  obviously: it is a licence to use a space rather than a lease of it, which the VAT Law schedules
  settle and a developer does not. **Ships exempt**, the conservative direction — under-charging the
  tenant beats collecting tax that may not be due and having to refund it. Read at ORIGINATION only,
  so flipping it never rewrites an issued invoice.
- **`monthly_rate` on the item is the asking price; the pivot's is what this tenant pays.** They
  differ whenever anything was negotiated, and the charge is built from the pivot.
- **Adding a type** means a `lang` entry in both files; the column is a string, not a DB enum.

## 8. Tests

`tests/Feature/Regression/RentableItemNotLettableAreaTest.php` (the invariant) ·
`tests/Feature/Regression/RentableItemAssignmentTest.php` (letting, releasing, re-letting, billing
through the real monthly run, and every refusal with a paired control).
