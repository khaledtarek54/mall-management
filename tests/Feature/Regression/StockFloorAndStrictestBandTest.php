<?php

use App\Models\ApprovalRule;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\ApprovalPolicy;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — the last two findings of the round-2 gap analysis: **F-84** (module 22) and
 * **F-99** (module 28).
 *
 * Both are the round's signature shape: **a guard that already exists, one branch away.**
 *  - F-84: `consumption` had the overdraw floor; a negative `adjustment` never got it.
 *  - F-99: the ladder's *gap* path takes the strictest tier, with a comment explaining exactly
 *    why; the *match* path four lines above took the first one.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'FLR']);
    $this->warehouse = Warehouse::create([
        'asset_id' => $this->asset->id, 'code' => 'WH-FLR', 'name' => 'Main store',
    ]);
    $this->item = InventoryItem::create([
        'sku' => 'FLR-1', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 200,
    ]);

    // 5 units on hand.
    app(StockMovementService::class)->record([
        'type' => 'receipt', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id, 'quantity' => 5, 'unit_cost' => 200,
        'moved_on' => now()->toDateString(),
    ]);
});

/** On-hand for the fixture item/warehouse. */
function flrOnHand(): float
{
    return round((float) StockMovement::query()
        ->where('inventory_item_id', test()->item->id)
        ->where('warehouse_id', test()->warehouse->id)
        ->sum('quantity'), 3);
}

/* ---- F-84 · nothing may drive on-hand negative ------------------------------- */

it('refuses a negative adjustment that would drive on-hand below zero', function () {
    // THE BUG: the floor was keyed on `$type === 'consumption'`, so a write-off skipped it.
    // Reproduced by the audit against the live DB: adjust(-100) on 5 → on-hand -95, and
    // Dr Inventory Adjustment 20,000 / Cr Inventory 20,000 — a CREDIT balance on an asset.
    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id, 'quantity' => -100,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(flrOnHand())->toBe(5.0, 'stock cannot go negative');
});

it('still allows a negative adjustment within on-hand — shrinkage is legitimate', function () {
    // The guard must refuse the impossible without breaking the real write-off.
    $movement = app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id, 'quantity' => -2,
        'reference' => 'Damaged in store',
        'moved_on' => now()->toDateString(),
    ]);

    expect($movement->exists)->toBeTrue()
        ->and(flrOnHand())->toBe(3.0);
});

it('still allows a POSITIVE adjustment — the floor keys on the sign, not the type', function () {
    $movement = app(StockMovementService::class)->record([
        'type' => 'adjustment', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id, 'quantity' => 4,
        'reference' => 'Stock-take: found extra',
        'moved_on' => now()->toDateString(),
    ]);

    expect($movement->exists)->toBeTrue()
        ->and(flrOnHand())->toBe(9.0);
});

it('still refuses a consumption beyond on-hand — the original guard is intact', function () {
    expect(fn () => app(StockMovementService::class)->record([
        'type' => 'consumption', 'warehouse_id' => $this->warehouse->id,
        'inventory_item_id' => $this->item->id, 'quantity' => 50,
        'moved_on' => now()->toDateString(),
    ]))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(flrOnHand())->toBe(5.0);
});

/* ---- F-99 · the ladder must fail CLOSED on an overlap ------------------------ */

it('takes the strictest covering band when bands overlap', function () {
    // THE SCENARIO, exactly as an operator would create it: widen band 2 to "everything over
    // 1000 needs a manager", and leave the 10000+ band alone because it looks redundant.
    $module = ApprovalRule::MODULE_INVENTORY_DRAW;

    ApprovalRule::create(['module' => $module, 'min_amount' => 0, 'max_amount' => 1000,
        'required_permission' => ApprovalRule::TIER_1, 'is_active' => true]);
    ApprovalRule::create(['module' => $module, 'min_amount' => 1000, 'max_amount' => null,
        'required_permission' => ApprovalRule::TIER_2, 'is_active' => true]);
    ApprovalRule::create(['module' => $module, 'min_amount' => 10000, 'max_amount' => null,
        'required_permission' => ApprovalRule::TIER_3, 'is_active' => true]);

    // 50,000 is covered by BOTH the 1000–∞ (tier_2) and 10000+ (tier_3) bands. Ordered by
    // min_amount, the old ->first() returned tier_2 — a manager approving a tier_3 amount.
    expect(ApprovalPolicy::permissionFor($module, 50000.0))
        ->toBe(ApprovalRule::TIER_3, 'an overlap must resolve to the STRICTEST covering band');

    // Non-overlapping amounts are unaffected.
    expect(ApprovalPolicy::permissionFor($module, 500.0))->toBe(ApprovalRule::TIER_1)
        ->and(ApprovalPolicy::permissionFor($module, 5000.0))->toBe(ApprovalRule::TIER_2);
});

it('still resolves a clean, non-overlapping ladder unchanged', function () {
    // The seeded production ladder. The fix must not disturb it.
    $this->seed(\Database\Seeders\ApprovalRulesSeeder::class);
    $module = ApprovalRule::MODULE_INVENTORY_DRAW;

    expect(ApprovalPolicy::permissionFor($module, 0.0))->toBe(ApprovalRule::TIER_1)
        ->and(ApprovalPolicy::permissionFor($module, 999.99))->toBe(ApprovalRule::TIER_1)
        ->and(ApprovalPolicy::permissionFor($module, 1000.0))->toBe(ApprovalRule::TIER_2)
        ->and(ApprovalPolicy::permissionFor($module, 10000.0))->toBe(ApprovalRule::TIER_3)
        ->and(ApprovalPolicy::permissionFor($module, 999999.0))->toBe(ApprovalRule::TIER_3);
});

it('still fails closed on a gap', function () {
    // The path that was always right — proving the fix didn't regress it.
    $module = ApprovalRule::MODULE_INVENTORY_DRAW;

    ApprovalRule::create(['module' => $module, 'min_amount' => 0, 'max_amount' => 100,
        'required_permission' => ApprovalRule::TIER_3, 'is_active' => true]);
    ApprovalRule::create(['module' => $module, 'min_amount' => 5000, 'max_amount' => null,
        'required_permission' => ApprovalRule::TIER_1, 'is_active' => true]);

    // 500 falls in the gap → the strictest tier CONFIGURED, not the last band.
    expect(ApprovalPolicy::permissionFor($module, 500.0))->toBe(ApprovalRule::TIER_3);
});
