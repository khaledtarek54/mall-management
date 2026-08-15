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
| `source_type` / `source_id` | nullable polymorphic — the origin (a maintenance ticket or a `FacilityWorkOrder` for consumption; a vendor bill for a receipt, Phase 3) |
| `moved_by_user_id` · `moved_on` · `notes` | audit |

---

### On-hand is scoped to the properties in view (FR-INV-01)

FR-INV-01 asks the system to "track spare parts stock levels **per mall/location**". The item
catalog is deliberately SHARED — a pump seal is the same part everywhere — so nothing scopes it by
default, and the derived `on_hand` column summed **every warehouse in the portfolio**.

That was a property **leak** and a **wrong number** at once. Proven: a manager restricted to mall A
read `on_hand = 100` for an item mall A had *none* of, because mall B held 100 — and the reorder
colour therefore painted it **green, "well stocked", for the one mall that had run out**.

`InventoryItemResource::getEloquentQuery()` now constrains the sum to
`TenantScope::reportAssetIds(TenantScope::currentAssetId())`: the selected property, or the user's
visible set, or genuinely everything for a portfolio role in All-Properties mode (scoping must not
become under-reporting — a portfolio question deserves a portfolio answer).

> ⚠️ **This had to land before FR-INV-03's low-stock alerts, not after.** An alert built on the old
> figure inherits the lie exactly where it hurts: the mall that is out is the one it stays quiet
> about. Pinned by `tests/Feature/Regression/InventoryOnHandScopeTest.php`; reverting the scope
> fails three of its five tests.

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
9. **A transfer is an atomic pair, within one property.** `StockMovementService::transfer()`
   writes the `transfer_out` and the `transfer_in` in **one transaction** — a half-written
   transfer would delete stock from the register with nothing to show where it went — and the
   out leg goes through `record()`, so it inherits the overdraw floor and the closed-period
   check. **Both warehouses must belong to the same property.** A transfer posts *no* journal
   entry (the value has not left the company), and that is only true inside one property's
   books: the GL dimensions every inventory entry by the warehouse's `asset_id`, so a
   cross-property move would shift real value with no entry anywhere — the source property's
   Inventory balance keeping stock it no longer holds, and owner statements are drawn off those
   balances. Move stock to another property as an adjustment out + a receipt in, which posts
   the value movement each property's books need.

10. **Stock is relieved at what it was LOADED at — weighted average, per warehouse.**
    `StockMovementService::weightedAverageCost()` is the single answer to "what is this stock
    worth": the average of the costs on the movements that ADDED it, in that warehouse. Every
    path that relieves inventory without a stated cost reads it — `record()`'s fallback and the
    work-order part draw — so the value credited out of Inventory can never diverge from the
    value debited in. A caller who states a cost still wins (an auditor-valued write-off), and
    an item with nothing received falls back to the catalogue figure, which is the only answer
    available.

    **This closed a hole in the balance sheet (2026-08-11).** The fallback used to read the
    item's *current* catalogue `unit_cost`. Receive 10 @ 100 (Dr Inventory 1,000), edit the item
    to 300 — an ordinary act, the field exists to be edited — then issue the 10, and Inventory
    is credited 3,000: **on-hand 0, Inventory −2,000**, Repairs & Maintenance overstated by the
    same amount, and nothing re-derives a perpetual account so every later movement compounds it.
    Owner statements are drawn off these balances. It was carried in this doc as a "known
    limitation … keep `unit_cost` stable or reconcile Inventory periodically", which is advice
    no operator can follow and no system enforces.

    The **same root cause** reached one path further out: `WorkOrderPartService::requestInternal`
    defaulted from the same catalogue figure, and that figure both picks the approval tier
    (FR-CM-11 — a stale-low price routes a large draw to a junior approver, the escalation
    mechanism quietly not escalating) and is frozen onto the part, so `approve()` passed it to
    `record()` as an EXPLICIT cost and walked straight past the new fallback. Both now read the
    weighted average. The item's `unit_cost` keeps its real job: the default for a NEW receipt —
    what we expect to pay next — which is what an operator editing it actually means.

    Tests: `InventoryCostBasisDriftTest` (driven through the real service + `accounting:sync-ledger`).

> **Remaining limitation — no purchase-price variance.** Weighted average is a costing basis, not
> a variance layer: a receipt at an unexpected price moves the average rather than booking the
> difference to a PPV account. That is the correct behaviour for a mall's spare-parts store and
> matches what the specialists do at this scale; a PPV layer is a manufacturing concern. **Trigger
> to revisit:** if the operator ever needs to report purchase-price variance to the owner, or a
> supplier contract prices parts on a fixed-standard basis.

---

### Low-stock alerts (FR-INV-03)

> The FRD hedges this one: *"minimum-stock thresholds and low-stock alerts (**recommended addition
> — confirm with client if desired**)"*. It is the FRD's own suggestion, not a confirmed need — so
> it sits behind the `inventory` module flag and **only ever notifies**. An alert cannot move stock
> or money, which is why it was safe to build ahead of the confirmation. BUSINESS-RULES q16.

`inventory:scan-low-stock`, daily at 07:30 (`routes/console.php`).

- **Per property, never portfolio-wide.** FR-INV-01 tracks stock "per mall/location" and the catalog
  is SHARED, so "are we low on filters?" is only answerable one mall at a time. This is the same
  trap the on-hand column fell into — a portfolio sum stays *silent about the mall that is out*
  while another mall sits on a pile. That fix had to land first, and did.
- **A reorder level of 0 means "not tracked", not "alert at zero"** — otherwise every item every
  mall has never stocked would alert forever.
- **A mall holding none of a tracked item IS short.** That is the definition of needing some.
- **Fires at the level, not below it** — at the minimum you are already meant to reorder.
- **One row per (item, property)** in `low_stock_alerts`, reused for the life of the shortage. Every
  other scan here stamps its "already alerted" flag on the row it alerted about
  (`invoices.owner_overdue_notified_at`); a low-stock alert has no such row — its subject is a
  *pair*, and stamping the SHARED item would silence the alert for every other mall. So the pair
  gets a row: `notified_at` when it fires, `resolved_at` when stock returns.
- **Once per shortage, not once per run.** Idempotent + lock-safe like every other scan: the alert
  row is locked and its stamp re-checked *inside* the transaction. A restock-then-dip alerts again,
  because that is a new shortage.
- **Bell only, no email.** An internal restocking nudge is not a deadline; the modules that email
  (overdue invoices, SLA breaches, lease expiry) all involve an outside party or a clock. Emailing
  every storeman about every filter is how people learn to ignore alerts.
- Notifies the short mall's `manager` + `operations` via `AssetStaffRecipients` — nobody else's.

**Gotcha found by mutation testing:** asserting "the All-Properties pseudo-asset never alerts" on the
default state proves nothing — it owns no warehouse, so the no-warehouse guard already skips it. The
test builds a *stray warehouse* on the pseudo-asset, which is the misconfiguration the exclusion
actually defends against.


## 3. Services

`app/Services/StockMovementService.php` — the single write path to the ledger:
`record()` (sign-normalising create), `receive()`, `adjust()`, `transfer()`
(atomic same-property pair), and `onHand()` (derived). Consumption (Phase 2) and GL posting (Phase 3) plug in on top without
changing this API.

---

## 4. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1a — Data foundation** | warehouses + item catalog + stock ledger + `StockMovementService` (receipts/adjustments, derived on-hand) + tests | ✅ shipped |
| **1b — Admin surfaces** | Filament resources (Warehouses, Items, Stock Movements) property-scoped + `inventory.*` RBAC + `inventory` module flag (`Modules::KEYS` / `ModulesSettings`) + receive/adjust actions | ✅ shipped |
| **2 — Consumption on tickets** | "Consumed materials" relation manager on the maintenance request: **Log consumed item** → `consumption` movement linked via `source`, decrements stock, costs at item standard cost, captures who/what. Property-tamper-guarded + gated on the inventory module. | ✅ shipped |
| **2b — Draws on a work order (FR-CM-09/10/11)** | a spare part on a maintenance work order is **requested** and only becomes a `consumption` movement once approved — the approver set by the part's value ([module 26](26-facility.md), [module 28](28-approvals.md)). The pending request lives on `facility_work_order_parts`, **not** here: a pending row in this ledger would understate on-hand everywhere (reorder colour, low-stock scan, GL) for stock that never left the shelf. | ✅ shipped |
| **2c — Draws reach the ledger** | an approved draw's `consumption` movement posts through the normal `InventoryMovementJournalizer` path (Dr R&M / Cr inventory), proven through the real `accounting:sync-ledger` sweep rather than a direct `LedgerPoster::post()` — the trap that let the SLA penalty ship posting nothing. A part **bought outside** posts nothing here: it never touched our stock, and its accounting document is the vendor bill ([module 26](26-facility.md) documents that seam). | ✅ shipped |
| **3 — GL costing** | `InventoryMovementJournalizer`: receipt → Dr Inventory (11301001) / Cr **GRNI (21701001)** — a dedicated clearing liability, NOT the AP control (keeps the AP tie-out intact); consumption → Dr Repairs&Maintenance / Cr Inventory; adjustment ↔ `inventory_adjustment` (51108001) per sign; transfers post nothing. Value = \|qty\| × unit_cost, dimensioned to the warehouse's property; swept by `accounting:sync-ledger`; soft-delete voids. Recognises cost as materials are used (COST-1). See [module 21](21-general-ledger.md). | ✅ shipped |

---

### Stock valuation on screen + CSV export of the registers (UX, 2026-07-23)

The engine already knew what stock was **worth** (`|qty| × unit_cost`), but the number lived only in
the GL — the item table showed quantity and unit cost side by side and left the operator to multiply.
Two thin UX additions, no new mechanics:

- **`value` column** on the items table — `on_hand × unit_cost`, money-formatted, so "what is this
  mall sitting on?" is answerable at a glance. It reuses the same property-scoped `on_hand` subquery,
  so the valuation can never disagree with the quantity beside it.
- **Export CSV** on both the item register and the movement ledger, via the shared
  `App\Support\ReportCsv` primitive (UTF-8 BOM so Excel renders Arabic) — the format an accountant
  actually reconciles a stock-take in, unlike a screen. `InventoryItemResource::stockRegisterCsv()`
  reads the **same scoped query the table shows** and closes with a **total valuation** row;
  `StockMovementResource::movementsCsv()` exports the scoped movement trail. Both gate on
  `canViewAny()` in `visible()` **and** `authorize()`. The register export is deliberately the
  live on-hand valuation (matches the screen), not an as-of-date snapshot — a point-in-time
  valuation would need a movement-replay and is a future enhancement.

## 5. Tests

`tests/Feature/Services/StockMovementServiceTest.php` — derived on-hand, sign-by-type,
signed adjustments, per-warehouse vs total, invalid-type/zero-qty rejection, NOT-NULL
coercion, movement value.

`tests/Feature/Regression/InventoryStockRegisterCsvTest.php` — the register CSV values stock at
`on_hand × unit_cost` **scoped to the user** (a restricted manager gets their mall's quantity, not
the portfolio sum), closes with a portfolio total, and the movement-ledger CSV is scoped the same way.

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

### Transfers were declared everywhere and creatable nowhere (gap-analysis, 2026-07-29)

`transfer_in`/`transfer_out` were in the migration's type enum, in `ADDS_STOCK`/`REMOVES_STOCK`,
had their own branch in `InventoryMovementJournalizer`, and had a **Transfers tab** on the
movement ledger — and **no code path in the application created one.** The tab was permanently
empty, and a storeman moving a part between two stores had to record it as a shrinkage plus a
receipt, which posts GL entries for value that never left the company.

Underneath it was worse: the F-83 "stock cannot move without a value" guard was not scoped to
the types that actually post. Its standard-cost fallback covered only `consumption`/`adjustment`,
so a transfer's `unit_cost` stayed 0 and the guard then rejected it — **`record()` refused every
transfer that did not carry an explicit cost**, with an error message ("would post nothing to the
general ledger") that is precisely what a transfer is *supposed* to do. The fallback now covers
every type except `receipt` (which must carry its own purchase cost), and the hard refusal is
scoped to the GL-posting types.

Now: a **Transfer** action on the movement ledger, `StockMovementService::transfer()`, and
`tests/Feature/Regression/StockTransferTest.php`.

**A refusal is a toast, not an error page.** The service refuses things an operator can cause by
typing — not enough on hand, a closed period, a cross-property transfer, an item with no cost.
Uncaught, each replaced the screen with a raw 422/500 and lost the form; `runMovement()` on
`ListStockMovements` turns them into a notification. `abort_unless(…, 422)` (the overdraw floor)
carries no message worth showing, so it maps to a written explanation.

**Testing note — the property guard needs a direct test.** Asserting that a Livewire dispatch with
a foreign `warehouse_id` moves nothing proves *nothing about the guard*: Filament's scoped Select
rejects the id first via its own `in:options` rule, and mutation-testing confirmed such a test keeps
passing with `assertWarehouseVisible()` deleted. The guard is therefore exercised directly (and
asserted not to refuse a *visible* warehouse, so a guard that refused everything would not pass).

**Related:** 11 Maintenance (Phase 2 consumption), 12 Vendors (Phase 3 receipts),
21 General Ledger (Phase 3 costing), 18 RBAC (Phase 1b), 01 Properties (asset scope).

---

## Deletion policy

Operator decision 2026-07-31, following Yardi/MRI/Entrata: a record that carries history is
**refused**, not warned about — the damage lands on the reports and audit trail that referenced
it, none of which are in front of whoever clicks the button. The single register is
[`App\Support\DeletionPolicy`](../../app/Support/DeletionPolicy.php); `DeletionPolicyConformanceTest` fails the build if a model here ships unclassified or a Delete
button reappears on a money record.

| Model | Rule | Instead / why |
|---|---|---|
| `StockMovement` | **Never deletable** | post a correcting movement; the original is what the GL was built from |
| `InventoryItem` | **Only while unreferenced** — blocked by `movements` | deactivate the item — its movements are what the stock valuation was built from |
| `Warehouse` | **Only while unreferenced** — blocked by `movements` | a warehouse with stock history is part of the inventory record |
