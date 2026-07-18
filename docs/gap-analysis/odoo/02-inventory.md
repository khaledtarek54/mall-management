# Inventory & stock — Atriom vs Odoo

> Domain 2 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `app/Services/StockMovementService.php`, the models, `InventoryMovementJournalizer`, and
> [`docs/modules/22`](../../modules/22-inventory.md) + [`docs/gap-analysis/22`](../22-inventory.md).
> Odoo claims for recent versions; *(verify)* = version/config-sensitive.

Legend: ✅ full · 🟡 partial/basic · ❌ absent · ⏭️ out of scope

## 1. Capability matrix

| Capability | Atriom | Odoo Community | Odoo Enterprise | Gap note |
|---|---|---|---|---|
| Multi-warehouse | ✅ per-property | ✅ | ✅ | Even. Warehouses are the finest grain (no bins). |
| Per-property / per-entity scoping | ✅ `asset_id` + `TenantScope`, tamper-guarded | 🟡 per-company | 🟡 per-company | **Atriom ahead** for one operator/many malls — native, not a multi-company workaround. |
| Item / product catalog | ✅ shared global (SKU/unit/cost/reorder) | ✅ + variants, categories | ✅ + variants | Odoo richer (variants, routes); Atriom's shared catalog intentional + adequate for parts. |
| **Valuation method** | 🟡 **standard cost only** (single `unit_cost`) | ✅ standard / **FIFO** / **average** | ✅ standard / FIFO / average | **Odoo well ahead.** No FIFO/AVCO layer; editing `unit_cost` drifts perpetual Inventory from receipt-loaded value. |
| Perpetual GL posting | ✅ automated, property-dimensioned | 🟡 automated needs Anglo-Saxon config *(verify)* | ✅ real-time | Even-to-ahead. Atriom's posting is automatic and always-on. |
| On-hand tracking | ✅ derived from signed ledger | ✅ real-time quants | ✅ | Even on truth; Odoo also exposes forecasted/incoming/outgoing. |
| Reorder rules / auto-replenishment | ❌ level stored, no action | ✅ min/max → auto RFQ/PO | ✅ + replenishment dashboard | **Odoo well ahead.** Atriom stores `reorder_level` but only alerts; never raises a purchase. |
| Low-stock alerts | ✅ daily scan, per-property, bell | 🟡 via reordering/forecast | 🟡 | **Atriom ahead** as a standalone nudge. |
| **Lots & serial tracking** | ❌ | ✅ + traceability | ✅ + reports | **Odoo well ahead.** Low value for generic spares; matters for warranty/expiry. |
| **Bins / sub-locations** | ❌ warehouse is finest grain | ✅ hierarchical + putaway | ✅ | **Odoo ahead.** No shelf/bin addressing. |
| **Internal transfers between warehouses** | ❌ enum types exist but **dead** | ✅ | ✅ + multi-step routes | **Odoo ahead; Atriom gap is a stub** — `transfer_in/out` defined (signs, translations, GL no-op) but nothing creates them. |
| Scrap / write-off | 🟡 via negative `adjustment` (floored ≥0) | ✅ dedicated scrap + location | ✅ | Odoo cleaner; Atriom folds it into adjustments — covers write-off. |
| Inventory adjustments / physical counts | 🟡 per-movement `adjustment` | ✅ count sessions | ✅ + cycle counts | Odoo ahead on count *workflow*; Atriom has the primitive, not the session. |
| **Landed costs** | ❌ | 🟡 *(verify)* | ✅ | **Odoo ahead.** Freight/duty not capitalised. Rare for a mall parts store. |
| Barcode | ❌ | 🟡 field only; **Barcode app = Enterprise** | ✅ scan-driven | **Odoo ahead.** No scanning. |
| Units of measure / conversion | 🟡 free-text `unit`, **no conversion** | ✅ buy-in-box, stock-in-each | ✅ | **Odoo ahead.** `unit` is a display string. |
| Delivery & receipt operations | 🟡 `receipt` movement; no pickings | ✅ + operation types | ✅ + multi-step | Odoo well ahead on WMS; ⏭️ largely out of scope (no outbound customer shipping). |
| Traceability / reporting | 🟡 append-only ledger + activity log + GL tie-out | ✅ valuation/traceability report | ✅ + forecasting | Odoo ahead on stock reporting; Atriom's strength is the **GL reconciliation** (perpetual inventory ties to the books). |

## 2. Architecture read

**Is the design sound? Broadly yes — for what a mall actually is.** Atriom isn't building a
distribution warehouse; it's building a spare-parts/consumables store whose real job is to make
maintenance material cost land in the GL. Judged against *that*, the core is well-built: an
append-only signed-movement ledger with **derived** on-hand (never a cached count that can drift),
a single write path (`StockMovementService`) that forces sign by type and re-checks on-hand under
a row lock, and automatic perpetual GL posting dimensioned to the property. That's a cleaner, more
auditable spine than many ERPs ship. **Keep the ledger model, the derived on-hand, the automatic GL
costing.**

**Standard-cost-only — a real but bounded limitation.** Everything is valued at the single
`unit_cost` on the catalog item; consumption/adjustment relieve at the item's *current* cost, so
editing `unit_cost` between receipt and consumption silently drifts the perpetual Inventory account
(the docs flag this honestly). For commodity spares with stable prices, standard cost is defensible
and much simpler than FIFO/AVCO. It becomes a real limitation the moment prices move materially
(imported parts, FX swings — very live in Egypt) or an auditor wants inventory at what was actually
paid. Recommendation: **do not build FIFO** (over-engineering here), but reconsider a
**weighted-average** cost that auto-updates on receipt — it removes the drift with far less
machinery than FIFO and no lot tracking, and is the single most defensible valuation upgrade.

**Shared global catalog + per-property warehouse split vs Odoo's product-per-company — a genuinely
good call.** Odoo scopes product/valuation per company, forcing a multi-company setup to run several
malls and duplicating "pump seal" per entity. Atriom models one catalog (a pump seal *is* the same
part everywhere) with stock differing per warehouse via movements — the better fit for one operator
running many malls, enforced correctly (the on-hand column was hardened to scope the sum to visible
properties). **Keep this.** The one sharp edge (F-85): the portfolio-wide `lockForUpdate` on the
shared item row is correct but one grain too coarse — Mall A consuming an SKU briefly blocks Mall B
consuming the same SKU. Performance-only, worth narrowing to the warehouse grain eventually.

**Movement ledger vs Odoo's `stock.move` / `stock.valuation.layer` — same shape, less surface.**
Atriom's `stock_movements` row fuses stock-move-plus-valuation-layer. Odoo separates the physical
move from the valuation layer, which is what lets it re-value (FIFO/AVCO) independently of the flow.
Atriom's fusion is simpler and fine under standard costing, but it's *why* a proper costing layer
would be non-trivial — the valuation is welded to the movement. Acceptable today; note it as the
structural reason FIFO would be expensive.

**The dead transfer types — decide, don't drift.** `transfer_in`/`transfer_out` are fully defined
(constants, sign normalisation, translations, a "no GL effect" journalizer branch, and the
negative-floor guard even covers `transfer_out` "for free") but **nothing creates them**. Code that
looks shipped and isn't. Either build the thin transfer action (genuinely close — needs a
paired-movement service call and a form) or mark it explicitly as roadmap. Leaving it half-present
is a maintenance and credibility trap.

## 3. Top 5 real gaps (ranked for a mall operator)

1. **Reorder rules / auto-replenishment** — Atriom knows the reorder level and alerts, but never
   turns a shortage into a purchase; a storeman still re-orders by hand — the biggest day-to-day
   productivity gap vs Odoo.
2. **Internal transfers between warehouses (finish the stub)** — a real operation the data model
   already anticipates but no one can perform.
3. **Weighted-average (or last-cost-on-receipt) valuation** — closes the documented standard-cost
   drift that bites as import/FX prices move, keeping perpetual Inventory honest against the books.
4. **Physical count / cycle-count workflow** — a guided count-and-reconcile session (vs one-off
   signed adjustments) is what an auditor and a year-end stocktake actually need.
5. **UoM conversion** — buying in boxes/drums but consuming in each/litre is common for consumables;
   today `unit` is a label with no conversion.

*Uncertainty flags: automated valuation posting (needs accounting config) and landed costs (app
availability) are version/edition-sensitive — treat as* (verify)*. Confirmed **Community** core:
lots/serials, min/max reordering, internal transfers, multi-location bins. The **Barcode app is
Enterprise**.*
