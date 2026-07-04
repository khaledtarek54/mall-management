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

        // Consumption must never drive on-hand negative. Serialize concurrent
        // consumption of this item (lockForUpdate) and re-check availability inside
        // the transaction, so two tickets can't both consume the last of the stock.
        if ($type === 'consumption') {
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
