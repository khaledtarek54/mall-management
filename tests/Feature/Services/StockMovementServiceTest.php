<?php

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;

function invItem(array $attrs = []): InventoryItem
{
    return InventoryItem::create(array_merge([
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Pump Seal',
        'unit' => 'each',
        'unit_cost' => 25,
        'reorder_level' => 10,
    ], $attrs));
}

function warehouse(array $attrs = []): Warehouse
{
    return Warehouse::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'Spare Parts Store',
        'code' => 'SP-'.strtoupper(substr(uniqid(), -5)),
        'category' => 'spare_parts',
    ], $attrs));
}

beforeEach(fn () => $this->svc = app(StockMovementService::class));

it('derives on-hand as the sum of movements (receipt adds stock)', function () {
    $w = warehouse();
    $i = invItem();

    $this->svc->receive($w, $i, 40, 25);
    $this->svc->receive($w, $i, 10, 25);

    expect($this->svc->onHand($i, $w))->toBe(50.0);
});

it('forces the movement sign by type (receipt +, consumption/transfer-out −)', function () {
    $w = warehouse();
    $i = invItem();

    // Even if a positive quantity is passed, a stock-removing type is stored negative.
    $receipt = $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'receipt', 'quantity' => 30, 'unit_cost' => 25]);
    $consume = $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'consumption', 'quantity' => 12, 'unit_cost' => 25]);
    $transferOut = $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'transfer_out', 'quantity' => 3, 'unit_cost' => 25]);

    $transferIn = $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'transfer_in', 'quantity' => 5, 'unit_cost' => 25]);

    expect((float) $receipt->quantity)->toBe(30.0);
    expect((float) $consume->quantity)->toBe(-12.0);
    expect((float) $transferOut->quantity)->toBe(-3.0);
    expect((float) $transferIn->quantity)->toBe(5.0); // transfer_in ADDS stock
    expect($this->svc->onHand($i, $w))->toBe(20.0); // 30 − 12 − 3 + 5
});

it('keeps an adjustment signed as given (shrinkage vs found)', function () {
    $w = warehouse();
    $i = invItem();
    $this->svc->receive($w, $i, 20, 25);

    $this->svc->adjust($w, $i, -5);  // shrinkage
    expect($this->svc->onHand($i, $w))->toBe(15.0);

    $this->svc->adjust($w, $i, 2);   // found more
    expect($this->svc->onHand($i, $w))->toBe(17.0);
});

it('tracks on-hand per warehouse and in total', function () {
    $i = invItem();
    $a = warehouse(['code' => 'WA-1']);
    $b = warehouse(['code' => 'WB-1']);

    $this->svc->receive($a, $i, 12, 25);
    $this->svc->receive($b, $i, 8, 25);

    expect($this->svc->onHand($i, $a))->toBe(12.0);
    expect($this->svc->onHand($i, $b))->toBe(8.0);
    expect($this->svc->onHand($i))->toBe(20.0); // both warehouses
});

it('rejects an unknown type and a zero non-adjustment quantity', function () {
    $w = warehouse();
    $i = invItem();

    expect(fn () => $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'bogus', 'quantity' => 1]))
        ->toThrow(InvalidArgumentException::class);

    // The quantity comes off a form, so this one is a REFUSAL — DomainException since SW-197,
    // while the unknown type above stays a programming error.
    expect(fn () => $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'receipt', 'quantity' => 0]))
        ->toThrow(DomainException::class);
});

it('coerces blank money/quantity to 0 without crashing (NOT-NULL guard)', function () {
    $w = warehouse();
    // A blank unit_cost/reorder_level on the item must not crash the decimal cast.
    $i = invItem(['unit_cost' => '', 'reorder_level' => '']);
    expect((float) $i->fresh()->unit_cost)->toBe(0.0);

    $movement = StockMovement::create([
        'warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'adjustment',
        'quantity' => '', 'unit_cost' => '', 'moved_on' => today(),
    ]);
    expect((float) $movement->fresh()->quantity)->toBe(0.0);
    expect((float) $movement->fresh()->unit_cost)->toBe(0.0);
});

it('computes a non-negative money value for a movement', function () {
    $w = warehouse();
    $i = invItem();
    $this->svc->receive($w, $i, 10, 25); // stock on hand to consume from
    $consume = $this->svc->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'consumption', 'quantity' => 4, 'unit_cost' => 25]);

    // quantity is −4 but the value is |qty| × cost = 100.
    expect($consume->value())->toBe(100.0);
});
