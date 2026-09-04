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


### Reorder quantity, and what the low-stock alert now does (2026-08-19)

`reorder_level` says WHEN to buy. **`reorder_quantity`** (nullable) says HOW MUCH — the field whose
absence meant `inventory:scan-low-stock` could only ever ring a bell. The scan now also drafts a
purchase request per property from the open shortages; a human submits it. Null `reorder_quantity`
is a real answer, and the drafted line then carries the shortfall for the operator to correct. See
[modules/29 — the `draft` status](29-procurement.md).

## 0. Design decisions (confirmed 2026-07-03)

| Decision | Choice | Why |
|---|---|---|
| **Stock model** | **Movement ledger** (append-only; on-hand DERIVED) | Auditable + reconcilable, and mirrors Atriom's double-entry/GL "derived truth" — on-hand can't silently drift. |
| **Warehouse scope** | **Per-property** (`asset_id`, via `TenantScope`) | Each mall runs its own stores; matches units/leases/meters scoping. |
| **Item catalog** | **Shared** (global reference data) | A "pump seal" is the same item everywhere; stock differs per warehouse via movements. |
| **Warehouse category** | **Free-form label** (not an enum) | Sidesteps the "3rd category name" question (Q-C), which is **closed for that reason** — the operator creates whatever stores they run. |

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
   adjustments default their `unit_cost` to the **weighted-average cost of the stock on hand**
   when the caller supplies none (rule 10), so a shrinkage write-off always hits Inventory
   Adjustment at what the stock was actually loaded at. *(It read the item's STANDARD cost
   until 2026-08-11; that is the hole rule 10 closed, and this rule described it for months
   afterwards.)*
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
    worth": `Σ(quantity × unit_cost) ÷ Σ(quantity)` over every movement, signed, in that
    warehouse. That is **exactly** the Inventory account's balance divided by the stock on hand
    — `InventoryMovementJournalizer` posts `abs(quantity) × unit_cost` as a debit for a movement
    that adds and a credit for one that removes, and `onHand()` sums the same rows — so the two
    cannot diverge by construction rather than by diligence. Every path that relieves inventory
    without a stated cost reads it: `record()`'s fallback, the work-order part draw and the
    tenant-request consumption door. A caller who states a cost still wins (an auditor-valued
    write-off, or a part frozen at request time), and an item with nothing on hand falls back to
    the catalogue figure, which is the only answer available.

    *(It averaged only the movements that ADDED stock until 2026-09-02 — every receipt ever, whether
    still on the shelf or issued three years ago — which diluted each new price with history that no
    longer existed and left a permanent, compounding residual. See "The average is of what is ON THE
    SHELF" below, including why a date-ordered replay is the WRONG repair.)*

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
| `Bin` | **Only while unreferenced** — blocked by `movements` | a bin with stock history is part of the inventory record — deactivate it instead |

---

## Bin locations (2026-08-18)

A warehouse says which mall's storeroom; a **bin** says where in it. Without one, a storeroom
holding four hundred parts is a single undifferentiated box — *"we have six of those"* is true and
useless, because nobody can find them. This was the last open item in FR-INV phase 5.

**Master data, not a free-text label on the movement.** The cheap version is a `bin` string. It
drifts on the first typo: `A-03-2` and `A032` become two shelves that both look real, the count
splits between them, and there is nothing to reconcile against. A row with a unique code cannot be
typo'd into existence.

- **Unique per WAREHOUSE, not globally.** `A-01` is an ordinary aisle label in every storeroom in
  the portfolio; a global unique would stop the second mall using its own signage.
- **`stock_movements.bin_id` is NULLABLE and stays that way.** An operator who does not rack their
  storeroom pays nothing for bins, and every movement written before today has none by definition.
- **Validated on WRITE, not merely scoped in the form.** The picker is narrowed to the chosen
  warehouse, but a Livewire payload is not a promise: `StockMovementService::resolveBinId()` drops a
  bin belonging to another warehouse rather than filing stock on a shelf that is not in the
  building. It **drops the bin and keeps the movement** — the stock is real, and refusing the whole
  entry over a stale dropdown value would block a storeman from recording it.
- **Property isolation is inherited, not duplicated.** `Bin` is `#[PropertyOwned(via: 'warehouse')]`
  — a second `asset_id` would be a second answer to which mall the shelf is in.
- **On-hand per bin is DERIVED** (`Bin::onHandByItem()`), never stored. A per-bin quantity column
  would be a second truth about the same stock, and the first movement recorded outside this
  model's knowledge would desynchronise it silently.
- **`nullOnDelete`, not cascade.** Force-removing a bin must not take the MOVEMENT with it — that
  would make the shelf's contents vanish from stock history, which is worse than losing the label.

Surfaced as a **Bins tab on the warehouse** rather than a top-level resource: a bin has no meaning
apart from its storeroom, and a portfolio list of them would be a list of duplicate labels.

Tests: `tests/Feature/Regression/StorageBinsTest.php` — each refusal paired with a control, and the
cross-warehouse guard verified by mutation (trusting the payload's `bin_id` turns one case red).


## The average is of what is ON THE SHELF (SW-189, fixed 2026-09-02)

`StockMovementService::weightedAverageCost()` averaged the `ADDS_STOCK` movements alone — every
receipt the item had ever had, whether that stock is still there or was issued three years ago. So a
price rise is diluted by history that no longer exists:

| | Inventory |
|---|---|
| receive 100 @ 10 | Dr 1,000 |
| issue all 100 (at 10) | Cr 1,000 → **0**, on hand 0 |
| receive 100 @ 20 | Dr 2,000 |
| the old average | (100×10 + 100×20) ÷ 200 = **15** |
| issue those 100 | Cr 1,500 → **500 left**, on hand 0 |

Five hundred standing in a perpetual asset account for stock that is gone — and it **compounds**,
because the next receipt is diluted by both. Nothing re-derives that account, and owner statements
are drawn off these balances. It is the same hole the standard-cost fallback opened (see
`InventoryCostBasisDriftTest`), reached by a different road: relieving stock at a figure that is not
what it was loaded at.

It is now `Σ(quantity × unit_cost) ÷ Σ(quantity)` over every movement, **signed** — which is not one
plausible average among several. It is **exactly** the Inventory account's balance divided by the
stock on hand, because `InventoryMovementJournalizer` posts `abs(quantity) × unit_cost` as a debit
for a movement that adds and a credit for one that removes, and `onHand()` sums the same rows. The
figure stock is relieved at therefore cannot diverge from the figure the books hold, by construction
rather than by diligence. One aggregate on the index the register already uses; no new table.

### Why the OBVIOUS repair is wrong, measured

Replaying the ledger in date order as a textbook moving average is the intuitive fix, and it
**re-creates the very residual this exists to remove**. A relief row's `unit_cost` is decided at
RECORD time, is immutable afterwards (`ChangeImpact` lists `quantity`, `unit_cost` and `moved_on` as
REFUSED) and is the only thing the GL posts from. A back-dated movement is keyed *after* the
movements it precedes, so a date-ordered replay computes a cost that was never posted and can never
be posted:

> Jan receive 100 @ 10 · Mar issue 100 (posted at 10) · Feb receive 100 @ 20, **keyed last**.
> The replay says **15**. The ledger holds **2,000 for 100 units** — 20. Issue them and Inventory
> closes at 500 on an empty shelf.

The signed aggregate answers 20 and ties out, because it reads what was POSTED rather than
re-deriving what should have been. It is immune to the rest of that family too: an over-relief keyed
before its receipts, a caller stating its own cost, a transfer's two legs (same cost, they net —
which is what the journalizer already says about a transfer), and per-step rounding, where one
division absorbs the cents a step-by-step relief strands as a permanent credit on an asset account.

**An `adjustment` needs no special case.** It is a signed correction in neither `ADDS_STOCK` nor
`REMOVES_STOCK`, and the aggregate reads the sign that is already on the row.

Origination only: every existing movement keeps the `unit_cost` it was posted at, so nothing
re-values history and a ledger re-derive moves no entry.

**One door was still relieving at the catalogue cost** — the tenant-request consumption relation
manager, which the invariant above had claimed for months was covered. Measured: 100 @ 10 then
100 @ 30 (Inventory 4,000), issue 100 at the catalogue's 10, and 3,000 stands for 100 units really
worth 2,000 — from a single click. It now states no cost, which is what makes the service value it.

Tests: `StockIsRelievedAtWhatIsOnTheShelfTest` — the diluted cycle, a genuinely mixed shelf that must
still average to 15 (so a fix that simply took the last price would fail), a part issue then a fresh
receipt, both signs of adjustment, the empty-shelf fallback, the back-dated movement **with a ledger
assertion beside it**, unit-at-a-time rounding, the consumption door, and the invariant itself
(`cost === Inventory balance ÷ on hand`) asserted directly. Mutation-proved two ways.

---

## Sweep fixes — 2026-09-05

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a time.
Each row's full claim is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*

### SW-191

append to the '### On-hand is scoped to the properties in view (FR-INV-01)' section:

**And so is the tab underneath it (SW-191, 2026-09-04).** The scope above fixed the FIGURE and left the EXPLANATION of it unscoped: `StockMovementsRelationManager` is one class with two parents, and on an `InventoryItem` — a `#[PortfolioShared]` catalogue row — `$relationship = 'movements'` is the bare hasMany, so it listed every mall's receipts, consumptions and adjustments. The two halves of one screen therefore answered different questions, on the tab an operator opens *because* they doubt the number beside it. It now composes `StockMovementResource::scopeToProperty()` — the register's own rule, read off `StockMovement`'s `#[PropertyOwned(via: 'warehouse')]`, so the tab and `/admin/stock-movements` cannot drift — applied on BOTH parents because on a `Warehouse` it is a no-op and a branch on the parent type would be a second rule to keep true. Nothing was watching: `PropertyIsolationConformanceTest` sweeps models and resources and has never swept a relation manager, and the demo books hold all 17 movements in one property, so neither the gate nor the seeded data could show it. Pinned by `AnItemsMovementsTabStaysInTheMallYouAreInTest`, whose third case asserts the arithmetic the bug broke: the tab's quantities must sum to the `on_hand` the register prints.

### SW-194

append to the '### Reorder quantity, and what the low-stock alert now does (2026-08-19)' section:

**And nobody could enter it until SW-194 (2026-09-04).** The column shipped fillable, cast and read, and settable on no screen and no importer — measured at HEAD, `reorder_quantity` appeared in `app/` exactly twice, both halves of `DraftReorderPurchaseService`'s `$item->reorder_quantity !== null` ternary. So the stated-quantity branch was reachable only from a factory, and every drafted line on a real install carried the shortfall the migration's own docblock calls *the one answer that is definitely wrong*. It is on the item form now, labelled from `admin.fields.reorder_quantity` (the key the audit trail already resolves this column from — a second one beside it would be a second spelling of one label). **Blank stays NULL**: Filament dehydrates an empty input to null and the model deliberately does not coerce this column to 0 the way it does `reorder_level`, because 0 means *draft no line at all* (`$quantity <= 0` is skipped) and that is a different statement from silence — so the floor is 0.001, the smallest the `decimal(12,3)` column holds. `LowStockDraftsAPurchaseTest` proved the branch by WRITING the column from a fixture, which is green over dead code; `AnItemCanSayHowMuchItReordersTest` drives the real Create page and follows the typed figure through the scan to the drafted line.

### SW-195

`, at the end of the *Low-stock alerts (FR-INV-03)* section:

**A reorder level of 0 is ONE rule with three readers (SW-195, 2026-09-04).** The bullet above — *"0 means not tracked, not alert at zero"* — was true of the scan and false of the screen, because the list wrote the rule out for itself twice and wrote it the other way round: `InventoryItemsTable`'s on-hand column coloured `0 <= 0` DANGER, and the low-stock filter's `reorder_level >= sum(quantity)` was TRUE at `0 >= 0`. So the reorder worklist opened on every catalogue item the mall had never stocked, each painted red, and none of them could ever produce an alert — the screen and the bell disagreeing about the same item. **That is the normal state, not an edge case**: `InventoryItemForm` defaults `reorder_level` to 0, so it is every item created by somebody with no threshold to type. `InventoryItem::isLowAt()` / `tracksAReorderLevel()` (and its query twin, because the filter compares against a correlated subquery and the two spellings cannot be collapsed) is the one predicate the colour, the filter and `ScanLowStockCommand` all read. `AnItemWithNoReorderLevelIsNotShortTest` pins all three against a genuinely short control, and asserts the PHP and SQL forms return the same set — a fix that simply stopped calling anything low would have satisfied the refusals alone.

### SW-196

`, as a new section after *Transfers were declared everywhere and creatable nowhere*:

### A picker that chooses a store is a record picker (SW-196, 2026-09-04)

Both ends of the transfer modal and the ticket-consumption store picker were bare `Select`s fed by a private `warehouseOptions()` helper — so they searched one raw column, folded neither side of the comparison, showed one line per option and re-wrote the property scope by hand, every failure of which renders as an empty or ambiguous dropdown rather than an error. They are `EntitySelect`s over `Warehouse` now, with the scope DERIVED from `PropertyIsolation` (and it is the write guard, not just a filter — Filament validates a Select by asking it to label the submitted value). **The destination picker still narrows away the source**: taking the option away is what refuses a same-store transfer, and it is the same mechanism the old `->except()` used.

**The gate could not see any of them, and that was the real finding.** `EntitySelectConformanceTest` reads each `Select::make()` chain's own text for a `pluck('name', 'id')`, and the pluck was twenty lines further down the file. Measured: it reported ZERO offenders while there were **ELEVEN**, across seven files. It now follows ONE hop into helpers declared in the same file — the idiom `LeaseEventNarrativeIsAKeyNotProseTest` already uses — joined with a `;` so the `mapWithKeys` pattern cannot match across the boundary and invent an offender. The other eight are registered as `[file, chain, why]` triples split into *deliberately plain* (the custodian, whose own docblock measures that a scoped label lookup makes the whole custody unsavable the day they leave; one employee's advances; the lines of one invoice) and *should move and have not yet* (the payroll employee, both work-order assignees, the unit-ownership unit), and the gate now fails on a reason under 60 characters and on a stale entry.

### SW-197

`, in *3. Services*, under the `StockMovementService` bullet list:

**Its refusals are `DomainException`s (SW-197, 2026-09-04).** Five of them were `InvalidArgumentException` carrying a hardcoded English sentence, and `ListStockMovements::runMovement()` caught that class and printed `$e->getMessage()` into the toast — so a storeman working the panel in Arabic read *"Stock can only be transferred between warehouses in the same property…"*, and `RefusalsAreTranslatedConformanceTest` could not see it: that gate sweeps `DomainException` only, on the stated premise that an `InvalidArgumentException` is a developer error rendering as a 500 that nobody reads. `runMovement()`'s own docblock falsifies the premise — it calls these *"real, reachable things"*. As `DomainException`s they are translated by derivation, `bootstrap/app.php` renders them as a toast on every OTHER door into the service (`PurchaseRequestService`, `WorkOrderPartService`) rather than the 500 page that loses the form, and `/api/v1` answers 422 with the sentence. **An unknown movement TYPE stays an `InvalidArgumentException`** — it comes from code, never a form — and `runMovement()` now catches `DomainException` ALONE, which is the structural half: a new `InvalidArgumentException` here fails loudly instead of being shown to somebody untranslated. The valueless refusal names the item the way the screen does rather than printing `item #37`. *The same shape is still open outside this module:* `LeaseActions` (8 sites) and `CamExpensePoolActions` (2) catch `\InvalidArgumentException` and toast its message.

### SW-198

`, as a new section after *Bin locations (2026-08-18)*:

### The ledger can be narrowed, and the export follows the screen (SW-198, 2026-09-04)

The stock movement register carried ONE filter — movement type, which the tabs above it already offer — so *"what left the Consumables store in March"*, the question asked at every stock count and the only way to explain a variance, meant scrolling an append-only table that grows for ever. It now carries a **date range** (the house `Filter::make('…')` + `from`/`until` DatePicker shape every other dated register uses) and **store** and **item** `EntitySelectFilter`s, so the dropdown and its chip read exactly like the pickers on the Receive/Adjust/Transfer modals. Neither record filter is narrowed to active rows: a ledger has to be filterable by the store or the item that was RETIRED, which is precisely when somebody comes looking.

**The CSV had to move with them, and that is the half that could have gone wrong silently.** `movementsCsv()` read `getEloquentQuery()` under a comment promising it *"reads the same property-scoped query the table shows so the export can never disagree with the screen"* — already untrue of the type tabs, and dangerously untrue once filters exist: narrow to one store, export, and the file is the whole portfolio's with nothing on it to say so. It takes an optional query now (null for the whole scoped register, so console callers are unaffected) and the list page hands in `getFilteredTableQuery()`.

Crossing three filters also crosses `SavedViews::THRESHOLD`, so `ListStockMovements` mounts `SavesTableViews` — `SavedViewsCoverageConformanceTest` fails in both directions, so this is not optional.

