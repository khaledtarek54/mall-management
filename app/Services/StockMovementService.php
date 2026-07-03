<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
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

        return StockMovement::create([
            'warehouse_id' => $data['warehouse_id'],
            'inventory_item_id' => $data['inventory_item_id'],
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => round((float) ($data['unit_cost'] ?? 0), 2),
            'reference' => $data['reference'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'moved_by_user_id' => $data['moved_by_user_id'] ?? auth()->id(),
            'moved_on' => $data['moved_on'] ?? today(),
            'notes' => $data['notes'] ?? null,
        ]);
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
