<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\PostingDate;
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
    /** Movement types that relocate stock without changing the company's inventory value. */
    public const TRANSFER_TYPES = ['transfer_in', 'transfer_out'];

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
        if ($unitCost <= 0 && $type !== 'receipt') {
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
        //
        // Scoped to the types that actually POST. A transfer is an intra-company location
        // move: InventoryMovementJournalizer returns null for it on purpose, because the
        // company's inventory value has not changed. So a transfer carrying no value is not
        // a GL leak — it is just an unvalued register row — whereas this guard's own message
        // ("would post nothing to the general ledger") would have been a false explanation.
        // Left unscoped, the guard made `transfer_in`/`transfer_out` IMPOSSIBLE to record
        // unless the caller passed an explicit cost: the fallback above skipped them, so
        // unit_cost stayed 0 and every transfer threw. A movement type that the migration,
        // the model constants, the journalizer and the ledger's Transfers tab all support
        // could not be created at all.
        if ($quantity != 0.0 && $unitCost <= 0 && ! in_array($type, self::TRANSFER_TYPES, true)) {
            throw new InvalidArgumentException(
                'Stock cannot move without a value: item #'.($data['inventory_item_id'] ?? '?')
                .' has no unit cost, so this movement would post nothing to the general ledger. '
                .'Set a unit cost on the item, or supply one with the movement.'
            );
        }

        // `moved_on` becomes the movement's GL entry_date (InventoryMovementJournalizer), so an
        // operator-supplied date in a CLOSED accounting period must be refused HERE — the same
        // guard the AP/AR service paths enforce (VendorBillService::approve, PostDatedChequeService).
        // Without it a back-dated receipt/adjustment (module 22's ad-hoc DatePicker is freely
        // editable) commits the row + on-hand while the real-time GL post silently fails — the
        // exact closed-period divergence PostingDate exists to stop, and it can strand a GRNI
        // credit a later vendor bill would then over-clear. A MISSING period is allowed (assertOpen
        // refuses only a CLOSED one), so fresh installs / pre-accounting dates are unaffected
        // (audit M29 F-3). Guarded in the service so console/API callers are covered too.
        $movedOn = PostingDate::assertOpen(
            filled($data['moved_on'] ?? null) ? $data['moved_on'] : today(),
            'moved_on',
        )->toDateString();

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
            'moved_on' => $movedOn,
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
     * Move stock between two warehouses as ONE atomic pair of movements.
     *
     * The ledger is append-only and on-hand is derived, so a relocation is not an
     * edit — it is a `transfer_out` from the source and a `transfer_in` to the
     * destination, both written inside a single transaction. That atomicity is the
     * point: if the second leg failed on its own, the quantity would simply have
     * vanished from the register with nothing to show where it went.
     *
     * Both legs go through record(), so the source leg inherits the overdraw guard
     * (you cannot transfer out stock you do not have) and the closed-period check.
     *
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transfer(
        Warehouse $from,
        Warehouse $to,
        InventoryItem $item,
        float $quantity,
        array $extra = [],
    ): array {
        $quantity = round(abs($quantity), 3);

        if ($quantity == 0.0) {
            throw new InvalidArgumentException('A transfer quantity must be greater than zero.');
        }

        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination warehouse must be different.');
        }

        // Same property only. A transfer posts NO journal entry by design — the company's
        // inventory value has not changed, only its location. That reasoning holds strictly
        // WITHIN a property: the GL dimensions every inventory entry by the warehouse's
        // asset_id, so shipping stock from mall A's store to mall B's would move real value
        // across the property boundary while both properties' Inventory balances stayed put
        // — A overstated by what it no longer holds, B understated by what it now does, and
        // no entry anywhere to explain it. Per-property owner statements are drawn off those
        // balances, so this is a money bug, not a tidiness rule.
        //
        // Cross-property relocation is therefore deliberately NOT modelled as a transfer:
        // record it as a shrinkage adjustment out of A and a receipt into B, which posts the
        // value movement each property's books need.
        if ((int) $from->asset_id !== (int) $to->asset_id) {
            throw new InvalidArgumentException(
                'Stock can only be transferred between warehouses in the same property. '
                .'To move stock to another property, adjust it out of the source and receive it at the destination, '
                .'so each property\'s books record the value leaving and arriving.'
            );
        }

        $common = array_merge($extra, [
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
        ]);

        return DB::transaction(function () use ($common, $from, $to) {
            // Out first: it carries the availability check, so an impossible transfer is
            // refused before anything is written.
            $out = $this->record(array_merge($common, [
                'warehouse_id' => $from->id,
                'type' => 'transfer_out',
            ]));

            $in = $this->record(array_merge($common, [
                'warehouse_id' => $to->id,
                'type' => 'transfer_in',
                // Carry the source leg's resolved value so the two sides of one relocation
                // agree in the register even if the item's standard cost changes later.
                'unit_cost' => (float) $out->unit_cost,
            ]));

            return ['out' => $out, 'in' => $in];
        });
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
