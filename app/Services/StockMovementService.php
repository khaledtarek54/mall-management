<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single entry point for writing to the stock ledger. Normalises the sign of a
 * movement by its type (receipts/transfer-in ADD, consumption/transfer-out
 * REMOVE, adjustments are a signed correction) so on-hand — always derived as
 * SUM(quantity) — stays correct and reconcilable.
 *
 * Phase 1 covers receipts + adjustments; consumption (from maintenance tickets)
 * and the GL costing plug in on top in later phases without changing this API.
 */
class StockMovementService
{
    /**
     * Record one movement. `quantity` is coerced to the correct sign for its
     * type; an `adjustment` keeps the sign it is given (a signed correction).
     *
     * @param  array{warehouse_id:int, inventory_item_id:int, type:string, quantity:float, unit_cost?:float, reference?:?string, source_type?:?string, source_id?:?int, moved_by_user_id?:?int, moved_on?:mixed, notes?:?string}  $data
     */
    public function record(array $data): StockMovement
    {
        $type = $data['type'] ?? null;
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new InvalidArgumentException("Unknown stock movement type '{$type}'.");
        }

        $quantity = round((float) ($data['quantity'] ?? 0), 3);
        if ($quantity == 0.0 && $type !== 'adjustment') {
            throw new InvalidArgumentException('A stock movement quantity must be non-zero.');
        }

        // Force the sign by type; adjustments keep the caller's signed value.
        if (in_array($type, StockMovement::ADDS_STOCK, true)) {
            $quantity = abs($quantity);
        } elseif (in_array($type, StockMovement::REMOVES_STOCK, true)) {
            $quantity = -abs($quantity);
        }

        // Consumption + adjustments are valued at the item's standard cost when the
        // caller supplies none (receipts must carry their own purchase cost) — so a
        // shrinkage write-off or a ticket consumption always hits the GL for its value.
        $unitCost = round((float) ($data['unit_cost'] ?? 0), 2);
        if ($unitCost <= 0 && in_array($type, ['consumption', 'adjustment'], true)) {
            $unitCost = round((float) (InventoryItem::find($data['inventory_item_id'])?->unit_cost ?? 0), 2);
        }

        // ...but the sentence above was only TRUE if the catalog actually carries a cost, and
        // `unit_cost` was optional on the item form (minValue(0), default 0). At cost 0 the
        // fallback resolved to 0, InventoryMovementJournalizer returned null, and the stock
        // left the warehouse having posted NOTHING: Inventory inflates forever, the expense is
        // never charged, and doc rule 7 ("a write-off ALWAYS hits Inventory Adjustment") is
        // quietly false. The receipt path had already reasoned this through and guarded it
        // ("a 0-cost receipt would add stock but post nothing to the GL", minValue(0.01)
        // ->required()) — the cost-out side just never got the same guard (gap-analysis F-83).
        //
        // Guarded HERE, not only on the form: the relation managers, the work-order parts draw
        // and any console/API caller all pass through this method, and a legacy item created
        // before the form was tightened still resolves to 0.
        //
        // Keyed on quantity, not type: a ZERO-quantity adjustment is a deliberate no-op note
        // (see the `$quantity == 0.0 && $type !== 'adjustment'` guard above, and the
        // journalizer's "a zero-value movement has no GL effect"), and must stay legal. What
        // may never happen is stock physically moving without its value following.
        if ($quantity != 0.0 && $unitCost <= 0) {
            throw new InvalidArgumentException(
                'Stock cannot move without a value: item #'.($data['inventory_item_id'] ?? '?')
                .' has no unit cost, so this movement would post nothing to the general ledger. '
                .'Set a unit cost on the item, or supply one with the movement.'
            );
        }

        $attributes = [
            'warehouse_id' => $data['warehouse_id'],
            'inventory_item_id' => $data['inventory_item_id'],
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference' => $data['reference'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'moved_by_user_id' => $data['moved_by_user_id'] ?? auth()->id(),
            'moved_on' => $data['moved_on'] ?? today(),
            'notes' => $data['notes'] ?? null,
        ];

        // Nothing may drive on-hand negative. Serialize concurrent removals of this item
        // (lockForUpdate) and re-check availability inside the transaction, so two tickets
        // can't both consume the last of the stock.
        //
        // Keyed on the SIGN, not the type. This was `$type === 'consumption'`, so a negative
        // `adjustment` — the shrinkage write-off — skipped the guard entirely: adjusting −100
        // against 5 on hand was accepted, leaving on-hand at −95 and posting Dr Inventory
        // Adjustment 20,000 / Cr Inventory 20,000, i.e. **a credit balance on an asset
        // account** and 19,000 of phantom expense. It also hard-blocks every later consumption
        // of that item/warehouse (this guard then refuses them) until someone posts a
        // compensating adjustment (gap-analysis F-84). Sign is the right key: REMOVES_STOCK
        // types are forced negative above, and an adjustment keeps the caller's sign — so
        // `< 0` is exactly "stock is leaving", whatever it is called.
        if ($quantity < 0) {
            return DB::transaction(function () use ($attributes, $quantity) {
                InventoryItem::whereKey($attributes['inventory_item_id'])->lockForUpdate()->first();
                $onHand = round((float) StockMovement::query()
                    ->where('inventory_item_id', $attributes['inventory_item_id'])
                    ->where('warehouse_id', $attributes['warehouse_id'])
                    ->sum('quantity'), 3);
                abort_unless($onHand >= abs($quantity) - 0.0001, 422); // insufficient stock

                return StockMovement::create($attributes);
            });
        }

        return StockMovement::create($attributes);
    }

    /** Receive stock into a warehouse (positive movement). */
    public function receive(Warehouse $warehouse, InventoryItem $item, float $quantity, float $unitCost, array $extra = []): StockMovement
    {
        return $this->record(array_merge([
            'warehouse_id' => $warehouse->id,
            'inventory_item_id' => $item->id,
            'type' => 'receipt',
            'quantity' => abs($quantity),
            'unit_cost' => $unitCost,
        ], $extra));
    }

    /** Correct stock by a signed delta (positive = found more, negative = shrinkage). */
    public function adjust(Warehouse $warehouse, InventoryItem $item, float $signedQuantity, array $extra = []): StockMovement
    {
        return $this->record(array_merge([
            'warehouse_id' => $warehouse->id,
            'inventory_item_id' => $item->id,
            'type' => 'adjustment',
            'quantity' => $signedQuantity,
        ], $extra));
    }

    /**
     * On-hand quantity for an item — across a single warehouse, or everywhere
     * when $warehouse is null. Derived from the ledger (non-trashed movements).
     */
    public function onHand(InventoryItem $item, ?Warehouse $warehouse = null): float
    {
        $query = StockMovement::query()->where('inventory_item_id', $item->id);

        if ($warehouse !== null) {
            $query->where('warehouse_id', $warehouse->id);
        }

        return round((float) $query->sum('quantity'), 3);
    }
}
