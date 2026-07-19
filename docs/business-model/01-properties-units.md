# Business Model — Properties & Units (Module 01)

> The foundation everything sits on: the **mall** and the **spaces** inside it. This module answers
> "which mall, which shop, is it occupied?" — and keeps occupancy honest.
> Technical spec: [modules/01-properties-units.md](../modules/01-properties-units.md).

---

## 1. What it is

- An **Asset** is a **property — a mall**. The operator (Eltizam) runs one or more malls for their
  owners (Jawad).
- A **Unit** is a **leasable space** inside a mall — a shop, kiosk, food-court stall, office. Each
  unit has a code (unique within its mall), a floor, a category (retail / F&B / kiosk / …), and a
  **leasable area** in m².

Everything else in the system hangs off these: leases bind tenants to *units*, invoices bill
*leases*, CAM splits cost by *unit area*, and the whole app is scoped to **one mall at a time**.

---

## 2. One operator, many malls — property isolation

The operator picks a mall and works **inside it**. Every list, picker, and report is scoped to the
selected property; you never see another mall's units, tenants, or money by accident. A user can be
restricted to specific malls (a mall_admin for Mall A only sees Mall A). This isolation is
**enforced, not just visual** — a restricted user can't reach another mall's data even by crafting a
request or uploading a CSV (see §5).

---

## 3. Occupancy is a *projection*, never typed by hand

A unit's status — **vacant · reserved · occupied · maintenance** — is not something an operator sets.
It is **computed from the unit's leases**:

- any **active** lease covering the unit → **occupied**
- any **draft/pending/renewed** lease → **reserved**
- none → **vacant**
- **maintenance** is the one manual override (a unit taken offline for works), and it's never
  auto-overwritten.

So occupancy is always the truth of "does a live lease reference this unit?" — the operator can't
inflate it.

**Scenario — a shop's life:**

| Event | Unit A-01 status |
|-------|------------------|
| New draft lease on A-01 | reserved |
| Lease activated | occupied |
| Lease terminated | vacant (freed automatically) |
| Operator sets A-01 to "maintenance" for a refit | maintenance (manual, stays) |

**Two things the close-out fixed here (2026-07-19):**
- **Deleting a lease frees the unit.** Previously, soft-deleting an active lease left its unit stuck
  at *occupied* forever (a phantom occupancy). Now delete/restore/force-delete all re-project the
  unit's status correctly.
- **A hand-typed occupancy self-heals.** If someone sets a lease-less unit to *occupied* on the
  form, it now drops back to *vacant* on save (maintenance is preserved) — occupancy can't be faked.

---

## 4. Occupancy rate — how "68% occupied" is computed

`occupancy rate = occupied units ÷ total units`. It drives the dashboard, the owner's property view,
and the mall's occupancy badge.

> **Open decision (deferred):** this counts **units**, not **area**. A mall with one vacant 3,000 m²
> anchor and 20 leased 15 m² kiosks reads as "95% occupied" by unit count, but is mostly-empty by
> floor area. Retail specialists (Yardi/AppFolio) report by **Gross Leasable Area**. The `area_sqm`
> data already exists — building area-based (and economic vs physical) occupancy is a flagged
> decision for when a mall's tenant mix makes unit-count misleading.

---

## 5. No double-booking, no cross-property writes

- **One active lease per unit.** A unit already covered by an active lease — as the master *or* as
  an additional unit in a multi-unit lease — can't be leased again. (The check consults the
  lease_unit *pivot*, so an additional unit is protected too — a close-out fix.)
- **CSV imports respect isolation.** Bulk-importing units resolves the target mall by its code, but
  **clamped to the malls you can manage** — a Mall-A-only operator can't upload a row that
  creates/overwrites a Mall-B unit (a close-out fix). Import can only set *vacant/maintenance* status
  (occupied/reserved are lease projections, not importable).

---

## 6. How it connects

- **[Leases (04)](04-leases.md)** — a lease's status projects its units' occupancy; the leased area
  is the unit's `area_sqm`.
- **[CAM (08)](../modules/08-cam.md)** — each unit's `area_sqm` is its share of common-area cost.
- **[Areas (30)](../modules/30-areas.md)** — units can belong to a supervised facility zone.
