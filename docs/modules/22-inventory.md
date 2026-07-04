# Module 22 — Inventory & Stock (المخزون)

> **Status: COMPLETE (Phases 1a + 1b + 2 + 3).** Data foundation (1a), admin surfaces
> & RBAC (1b), maintenance-ticket **consumption** (2), and **GL costing** (3): every
> stock movement posts to the double-entry ledger via `InventoryMovementJournalizer`
> (receipt → Dr Inventory / Cr Payables; consumption → Dr Maintenance Expense / Cr
> Inventory), so material cost is recognised as it's used and inventory is reconcilable
> with the books. Delivers the FRD's highest-priority net-new build (D-3, "full
> inventory + consumption costing").

Operations (Eltizam) run stores of spare parts, deep-clean machines, and daily
consumables per mall. This module tracks **what's in stock, where, and what it's
worth**, and (later phases) recognises the **cost of materials as they're consumed**
on maintenance work — closing the "vendor bill = black box" gap so service margins
become visible.

---

## 0. Design decisions (confirmed 2026-07-03)

| Decision | Choice | Why |
|---|---|---|
| **Stock model** | **Movement ledger** (append-only; on-hand DERIVED) | Auditable + reconcilable, and mirrors Atriom's double-entry/GL "derived truth" — on-hand can't silently drift. |
| **Warehouse scope** | **Per-property** (`asset_id`, via `TenantScope`) | Each mall runs its own stores; matches units/leases/meters scoping. |
| **Item catalog** | **Shared** (global reference data) | A "pump seal" is the same item everywhere; stock differs per warehouse via movements. |
| **Warehouse category** | **Free-form label** (not an enum) | Sidesteps the open "3rd category name" question (Q-C) — the operator creates whatever stores they run. |

---

## 1. Domain model

Three tables. Money is `decimal(14,2)`; quantities are `decimal(14,3)` (fractional units).

### `warehouses` — stock locations (per property)
| Column | Meaning |
|--------|---------|
| `asset_id` | the property (FK, cascade) |
| `name` · `code` | label + short code (**unique per asset**) |
| `category` | free-form (spare parts / machines / consumables / …) |
| `notes` · `is_active` | — |

### `inventory_items` — the catalog (shared)
| Column | Meaning |
|--------|---------|
| `sku` (unique) · `name` · `category` | identity |
| `unit` | unit of measure (each/litre/kg) |
| `unit_cost` | standard/last cost per unit |
| `reorder_level` | low-stock threshold |
| `is_active` | — |

### `stock_movements` — the append-only ledger
| Column | Meaning |
|--------|---------|
| `warehouse_id` · `inventory_item_id` | where + what (FK, cascade) |
| `type` | `receipt` / `consumption` / `adjustment` / `transfer_in` / `transfer_out` |
| `quantity` | **SIGNED** — positive adds stock, negative removes it |
| `unit_cost` | cost per unit at movement time (drives GL costing) |
| `reference` | PO / ticket ref |
| `source_type` / `source_id` | nullable polymorphic — the origin (a maintenance ticket for consumption, Phase 2; a vendor bill for a receipt, Phase 3) |
| `moved_by_user_id` · `moved_on` · `notes` | audit |

---

## 2. Business rules & invariants

1. **On-hand is derived, never cached.** On-hand = `SUM(quantity)` of an item's
   (non-trashed) movements — per warehouse or in total (`StockMovementService::onHand`).
   Corrections are a **new `adjustment` movement**, not an edit — the stock history
   stays intact (mirrors the GL's "void, don't edit").
2. **Sign is forced by type.** `StockMovementService::record()` normalises the sign:
   receipts/transfer-in are stored positive, consumption/transfer-out negative;
   an `adjustment` keeps the caller's signed value (a correction can go either way).
3. **A movement quantity is non-zero** (except an adjustment, which may legitimately be
   a zero-effect note); an unknown type is rejected.
4. **NOT-NULL money/quantity** — blank `quantity`/`unit_cost`/`reorder_level` coerce to 0
   in the model (the `meter_readings.cost` bug class).
5. **Value of a movement** = `|quantity| × unit_cost` (always non-negative).
6. **Consumption can't overdraw stock** (QA-hardened) — `record()` locks the item and
   re-checks on-hand inside a transaction, so a ticket can never consume more than is on
   hand (no negative stock / phantom COGS); concurrent consumptions serialise.
7. **Every movement carries a value** — a receipt's `unit_cost` is **required** (prefilled
   from the item; a 0-cost receipt would silently post nothing to the GL); consumption and
   adjustments default their `unit_cost` to the item's **standard cost** when the caller
   supplies none, so a shrinkage write-off always hits Inventory Adjustment.
8. **Warehouse soft-delete is GL-safe** — `StockMovement::warehouse()` is `withTrashed`, so
   a live movement stays attributable (its journal entry isn't voided) after its warehouse
   is archived.

> **Known limitation — standard costing, no variance layer.** Receipts load Inventory at
> their entered purchase cost; consumption/adjustments relieve it at the item's *current*
> `unit_cost`. If an item's `unit_cost` is edited between receipt and consumption, the
> perpetual Inventory account drifts from receipt-loaded value (no purchase-price-variance /
> FIFO / weighted-average layer). A proper costing layer is a future enhancement; for now,
> keep `unit_cost` stable or reconcile Inventory periodically.

---

## 3. Services

`app/Services/StockMovementService.php` — the single write path to the ledger:
`record()` (sign-normalising create), `receive()`, `adjust()`, and `onHand()`
(derived). Consumption (Phase 2) and GL posting (Phase 3) plug in on top without
changing this API.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1a — Data foundation** | warehouses + item catalog + stock ledger + `StockMovementService` (receipts/adjustments, derived on-hand) + tests | ✅ shipped |
| **1b — Admin surfaces** | Filament resources (Warehouses, Items, Stock Movements) property-scoped + `inventory.*` RBAC + `inventory` module flag (`Modules::KEYS` / `ModulesSettings`) + receive/adjust actions | ✅ shipped |
| **2 — Consumption on tickets** | "Consumed materials" relation manager on the maintenance request: **Log consumed item** → `consumption` movement linked via `source`, decrements stock, costs at item standard cost, captures who/what. Property-tamper-guarded + gated on the inventory module. | ✅ shipped |
| **3 — GL costing** | `InventoryMovementJournalizer`: receipt → Dr Inventory (11301001) / Cr **GRNI (21701001)** — a dedicated clearing liability, NOT the AP control (keeps the AP tie-out intact); consumption → Dr Repairs&Maintenance / Cr Inventory; adjustment ↔ `inventory_adjustment` (51108001) per sign; transfers post nothing. Value = \|qty\| × unit_cost, dimensioned to the warehouse's property; swept by `accounting:sync-ledger`; soft-delete voids. Recognises cost as materials are used (COST-1). See [module 21](21-general-ledger.md). | ✅ shipped |

---

## 5. Tests

`tests/Feature/Services/StockMovementServiceTest.php` — derived on-hand, sign-by-type,
signed adjustments, per-warehouse vs total, invalid-type/zero-qty rejection, NOT-NULL
coercion, movement value.

## 6. Gotchas / hardening backlog

- **Receive/Adjust are property-tamper-guarded.** The warehouse picker is scoped AND
  the action re-validates the submitted `warehouse_id` against the user's visible
  properties server-side (`abort(403)` on a foreign warehouse) — mirrors the
  credit-note apply guard.
- **FK cascade on hard-delete** (Phase-2 hardening): `stock_movements.inventory_item_id`
  / `warehouse_id` are `cascadeOnDelete`, so **force-deleting** an item/warehouse would
  erase its ledger history. Delete is super-admin-only and models soft-delete (which
  does NOT cascade), so it's a rare path — but consider switching these FKs to
  `restrictOnDelete` (force soft-delete + `is_active=false` instead) to protect the
  ledger, alongside the GL costing work.

**Related:** 11 Maintenance (Phase 2 consumption), 12 Vendors (Phase 3 receipts),
21 General Ledger (Phase 3 costing), 18 RBAC (Phase 1b), 01 Properties (asset scope).
