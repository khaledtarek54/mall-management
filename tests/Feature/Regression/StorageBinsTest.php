<?php

/**
 * Bin locations inside a warehouse — the last open piece of inventory phase 5 (FR-INV).
 *
 * A warehouse says which mall's storeroom; a bin says where in it. Without one, a storeroom holding
 * four hundred parts is a single undifferentiated box: "we have six of those" is true and useless,
 * because nobody can find them.
 *
 * Two design decisions carry the risk, and both are pinned here:
 *
 *  - **Master data, not a free-text label.** `A-03-2` and `A032` would otherwise become two shelves
 *    that both look real, splitting the count with nothing to reconcile against.
 *  - **The bin is validated against the warehouse on WRITE.** The form scopes the picker, but a
 *    Livewire payload is not a promise, and a bin id from another building would file stock on a
 *    shelf that is not there.
 */

use App\Models\Bin;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->other = makeAsset();

    $this->warehouse = Warehouse::create([
        'asset_id' => $this->asset->id, 'name' => 'Main store', 'code' => 'WH-1', 'is_active' => true,
    ]);
    $this->foreign = Warehouse::create([
        'asset_id' => $this->other->id, 'name' => 'Other store', 'code' => 'WH-2', 'is_active' => true,
    ]);

    $this->bin = Bin::create(['warehouse_id' => $this->warehouse->id, 'code' => 'A-01', 'name' => 'Filters']);
    $this->foreignBin = Bin::create(['warehouse_id' => $this->foreign->id, 'code' => 'A-01']);

    $this->item = InventoryItem::create(['sku' => 'SKU-BIN', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 25]);
    $this->svc = app(StockMovementService::class);
});

/* ---- the label can be the same in two malls ------------------------------ */

it('lets every storeroom use its own A-01', function () {
    // A global unique would stop the second mall labelling its shelves the way it already has.
    expect($this->bin->code)->toBe('A-01')
        ->and($this->foreignBin->code)->toBe('A-01')
        ->and($this->bin->warehouse_id)->not->toBe($this->foreignBin->warehouse_id);
});

it('refuses the same code twice in ONE storeroom', function () {
    // The whole reason bins are master data rather than a typed string.
    expect(fn () => Bin::create(['warehouse_id' => $this->warehouse->id, 'code' => 'A-01']))
        ->toThrow(QueryException::class);
});

/* ---- the write guard ----------------------------------------------------- */

it('records the bin when it belongs to the warehouse', function () {
    // The control. Without it every refusal below passes on a bin that is never stored at all.
    $movement = $this->svc->record([
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->bin->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt',
        'quantity' => 10,
        'unit_cost' => 25,
    ]);

    expect($movement->bin_id)->toBe($this->bin->id);
});

it('drops a bin belonging to a DIFFERENT warehouse', function () {
    // A crafted payload, or a stale dropdown value surviving a warehouse change. The stock is real
    // and must still be recorded — so the bin is discarded, not the movement.
    $movement = $this->svc->record([
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->foreignBin->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt',
        'quantity' => 10,
        'unit_cost' => 25,
    ]);

    expect($movement->bin_id)->toBeNull()
        ->and($movement->warehouse_id)->toBe($this->warehouse->id)
        ->and((float) $movement->quantity)->toBe(10.0);
});

it('stays optional — a movement with no bin is perfectly normal', function () {
    // An operator who does not rack their storeroom must pay nothing for bins.
    $movement = $this->svc->record([
        'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt',
        'quantity' => 5,
        'unit_cost' => 10,
    ]);

    expect($movement->bin_id)->toBeNull();
});

/* ---- what is on the shelf is DERIVED ------------------------------------- */

it('derives what a bin holds from the movements', function () {
    // Never stored. A per-bin quantity column would be a second truth about the same stock.
    $common = [
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->bin->id,
        'inventory_item_id' => $this->item->id,
    ];

    $this->svc->record($common + ['type' => 'receipt', 'quantity' => 10, 'unit_cost' => 25]);
    $this->svc->record($common + ['type' => 'consumption', 'quantity' => 4]);

    $onHand = $this->bin->refresh()->onHandByItem();

    expect($onHand)->toHaveCount(1)
        ->and((float) $onHand->first()->on_hand)->toBe(6.0);
});

it('reports an emptied bin as holding nothing, not as holding zero of something', function () {
    // The control for the query's `having <> 0`: a shelf drawn down to nil should read as empty.
    $common = [
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->bin->id,
        'inventory_item_id' => $this->item->id,
    ];

    $this->svc->record($common + ['type' => 'receipt', 'quantity' => 3, 'unit_cost' => 25]);
    $this->svc->record($common + ['type' => 'consumption', 'quantity' => 3]);

    expect($this->bin->refresh()->onHandByItem())->toHaveCount(0);
});

/* ---- retiring a shelf must not rewrite stock history --------------------- */

it('refuses to delete a bin that stock has moved through', function () {
    $this->svc->record([
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->bin->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt', 'quantity' => 1, 'unit_cost' => 5,
    ]);

    expect(fn () => $this->bin->delete())->toThrow(DomainException::class);

    // …and the control: an unused bin IS removable, so this is a guard and not a blanket refusal.
    $unused = Bin::create(['warehouse_id' => $this->warehouse->id, 'code' => 'Z-99']);
    $unused->delete();

    expect($unused->fresh()->trashed())->toBeTrue();
});

it('keeps the movement when a bin is force-removed underneath it', function () {
    // nullOnDelete, not cascade: losing the MOVEMENT would make the shelf's contents vanish from
    // stock history, which is a worse outcome than losing the location label.
    $movement = $this->svc->record([
        'warehouse_id' => $this->warehouse->id,
        'bin_id' => $this->bin->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'receipt', 'quantity' => 2, 'unit_cost' => 5,
    ]);

    Bin::withoutEvents(fn () => Bin::where('id', $this->bin->id)->forceDelete());

    expect(StockMovement::find($movement->id))->not->toBeNull()
        ->and(StockMovement::find($movement->id)->bin_id)->toBeNull();
});
