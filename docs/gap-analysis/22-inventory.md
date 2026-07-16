# Module 22 — Inventory & Stock

> **Round 2**, audited 2026-07-16 — first ever gap analysis. Spec:
> [../modules/22-inventory.md](../modules/22-inventory.md) · methodology: [000-plan.md](000-plan.md).
> All findings below were **reproduced live** against the dev DB inside a rolled-back transaction.

**Status: 🟢 Green** — F-83 + F-84 both fixed 2026-07-17; only F-85 (a too-coarse lock, performance only) remains. The ledger design,
sign-normalisation, the consumption overdraw guard and the cross-property draw guard are genuinely
well built. Both money bugs sat in the **unguarded siblings of paths that were hardened**: the
receipt side got the 0-cost guard and the cost-out side didn't (F-83); `consumption` got the
overdraw floor and `adjustment` didn't (F-84).

`pest --parallel --filter='Inventory|Stock'` → **green**.

---

## 1. Findings

### 🔴 F-83. A catalog item at `unit_cost = 0` silently never relieves Inventory — and collapses the approval ladder · **FIXED 2026-07-17**
`app/Services/StockMovementService.php:50` · `app/Filament/Admin/Resources/InventoryItems/Schemas/InventoryItemForm.php:32`

`unit_cost` on the item form is **not `required()` and defaults to `0`**. The consumption/adjustment
fallback re-reads that 0, so `$unitCost` stays 0 and `InventoryMovementJournalizer.php:42` returns
`null`. The **receipt** path guards this exact trap (`minValue(0.01)->required()`, commented *"a
0-cost receipt would silently post nothing to the GL"*); the cost-out side does not.

**Reproduced:**
```
receipt 10 @200  → GL Dr Inventory 2000
consumption 10   → movement unit_cost=0.00 value=0
journalizer payload NULL  ⇒ NO GL ENTRY
on-hand = 0 (stock physically gone) but Inventory still carries 2000
```
Inventory inflates forever, Maintenance Expense is never charged, service margin is overstated —
the precise gap this module exists to close. Doc rule 7 ("a shrinkage write-off *always* hits
Inventory Adjustment") is false at cost 0.

**Second blast radius:** `WorkOrderPartsRelationManager.php:138` never collects `unit_cost` for an
internal draw, so `WorkOrderPartService::requestInternal` falls back to the catalog → `value = 0` →
`ApprovalPolicy::permissionFor(…, 0)` → **lowest tier**. This is the same bug the service's own
comment says it fixed (*"a 500 EGP draw priced itself at 0.00 and asked for tier_1"*): `filled()`
guards a blank *submitted* value, but not a catalog cost of 0.

**Fix (2026-07-17) — both layers, because the form is not a gate.**
1. **`StockMovementService::record()` refuses a valueless movement**: `quantity != 0 && unitCost <= 0`
   → throw. This is the real guard — the relation managers, the work-order parts draw, and every
   console/API caller pass through it, and a **legacy item** created before the form was tightened
   still resolves to 0.
2. **`InventoryItemForm` requires `minValue(0.01)`**, mirroring the receipt's identical guard, so
   the bad data can't be created in the first place.

**Keyed on quantity, not type** — a zero-quantity `adjustment` is a deliberate no-op note (the
service's own `$quantity == 0.0 && $type !== 'adjustment'` check, and the journalizer's *"a
zero-value movement has no GL effect"*), and stays legal. What may never happen is stock physically
moving without its value following.

Guard: `tests/Feature/Regression/ValuelessStockMovementTest.php` — 3 of 5 fail without it; the two
"still works" cases pass either way, proving the catalog-cost fallback and the note-adjustment are
both intact. One change closed the GL non-relief **and** the tier_1 ladder collapse, as predicted.

### 🔴 F-84. A negative `adjustment` has no floor — drives on-hand negative and puts a credit balance on an asset account · **FIXED 2026-07-17**
`app/Services/StockMovementService.php:72` (guard is `if ($type === 'consumption')` only) ·
`ListStockMovements.php:82` (`->numeric()->required()`, no `minValue`, no on-hand check)

The *sign* flexibility is deliberate; the absence of a floor is not — you cannot remove more stock
than exists. **Reproduced** (warehouse holds 5 @ 200):
```
adjust(-100) ACCEPTED → qty=-100.000 unit_cost=200.00
on-hand NOW = -95                    ⇐ negative physical stock
GL: Dr Inventory Adjustment 20000 / Cr Inventory 20000
Inventory acct: +1000 received − 20000 relieved = -19000  ⇐ credit balance on an ASSET
```
19,000 of phantom expense, a balance sheet with negative inventory, and every subsequent
consumption of that item/warehouse is **hard-blocked** by the overdraw guard until someone posts a
compensating adjustment.

**Fix (2026-07-17).** The floor is now keyed on the **sign, not the type**: `if ($quantity < 0)`
runs the existing lock + on-hand re-check. `REMOVES_STOCK` types are forced negative and an
`adjustment` keeps the caller's sign, so `< 0` is exactly "stock is leaving", whatever it's called
— and it covers `transfer_out` for free, whenever transfers get built.

Guard: `tests/Feature/Regression/StockFloorAndStrictestBandTest.php`. **Three existing tests had to
be corrected, not weakened:** `QaNewModulesRegressionTest` and two in `LedgerPosterTest` wrote off
stock that was never received (on-hand 0, adjust −3), i.e. their fixtures described the very state
F-84 is about. They now `receive()` first — as the same file's `voids a stock movement entry` test
already did.

### 🟡 F-85. Consumption serialises portfolio-wide on the shared catalog row
`app/Services/StockMovementService.php:74` — `InventoryItem::whereKey(…)->lockForUpdate()` locks the
**global** item row, so a Mall A ticket consuming "pump seal" blocks a Mall B ticket consuming the
same SKU from a different warehouse. Correct, but one grain too coarse (the invariant is
per-warehouse). Performance only.

### 🟢 Known, already documented — not re-reported
- `InventoryItemResource.php:86` sums on-hand across **all** properties → the reorder colour shows
  green for a mall that is out of a part. **Must be fixed before low-stock alerts**, or the alerts
  inherit it. (ROADMAP §4 phase 5.)
- `transfer_in`/`transfer_out` exist as enum values, constants, sign-normalisation and translations
  — but **nothing creates them**. Dead code that looks shipped.
- GRNI `21701001` accumulates and is never cleared — procurement (FRD phase 4) is the seam.

---

## 2. Verified-correct — don't re-audit

- **The consumption overdraw guard is real** — a lock + re-check of on-hand *inside* the transaction
  (`:72`), not a pre-check. Concurrent draws serialise; a probe confirmed it rejects.
- **`StockMovement::warehouse()` is `withTrashed()`** — a movement stays GL-attributable after its
  warehouse is archived, and `Warehouse::booted()` deliberately does *not* soft-delete-cascade. Both
  sides agree; the reasoning is documented and sound.
- **`WorkOrderPartService::assertWarehouseServesOrder()`** correctly references **the order's own
  property**, not `visibleAssetIds()` — so an All-Properties user still can't cross stock between
  malls. (This is the guard whose *penalty* equivalent was missing — see [F-77](26-preventive-maintenance.md).)

---

## 3. Test gaps

- ~~`StockMovementServiceTest` proves the overdraw guard for `consumption` but never for `adjustment`~~ — ✅ covered by `StockFloorAndStrictestBandTest`.
- ~~No test constructs an item at `unit_cost = 0`~~ — ✅ covered by `ValuelessStockMovementTest`.
- Dispatch is genuinely proven — consumption posts through the real `approve()` → windowed sweep in
  `WorkOrderPartLedgerDispatchTest:66`, and receipts via `GlPostingSourcesScenarioTest:220`. **The
  SLA-penalty trap is not present here** (checked explicitly).

## 4. Deferred

- ~~**D-70**~~ — ✅ **F-83 fixed 2026-07-17.** One change, two bugs, as predicted.
- ~~**D-71**~~ — ✅ **F-84 fixed 2026-07-17.** Same shape as F-83, as predicted: a guard the
  `consumption` path already had, that `adjustment` never got.
- **D-72** — F-85 narrow the consumption lock to the warehouse grain.
