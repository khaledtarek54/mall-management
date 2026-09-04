<?php

use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * **A stock refusal is the app talking to a storeman, so it speaks their language** (SW-197,
 * 2026-09-04).
 *
 * `StockMovementService` raised five operator refusals as `InvalidArgumentException` carrying a
 * raw English sentence, and `ListStockMovements::runMovement()` caught that class and printed
 * `$e->getMessage()` into the toast body. So on the Arabic panel an Egyptian storeman read
 * *"Stock can only be transferred between warehouses in the same property…"* — and
 * `RefusalsAreTranslatedConformanceTest` could not see any of it, because it sweeps
 * `DomainException` only, on the stated premise that an `InvalidArgumentException` is a developer
 * error nobody is meant to read. That premise was false on exactly this page: `runMovement()`'s
 * own docblock lists these as *"real, reachable things"*.
 *
 * They are `DomainException`s now, through `admin.refusals.*`, which buys three things at once:
 * the existing gate keeps them translated by derivation, `bootstrap/app.php` renders them as a
 * toast on every OTHER door into the service (the purchase-request receipt, the work-order part
 * draw) instead of the 500 page, and the mobile contract answers 422 with the sentence rather than
 * "Internal Server Error". `runMovement()` now catches `DomainException` alone, so a NEW
 * `InvalidArgumentException` here fails loudly instead of being quietly shown to somebody.
 *
 * The one genuine programming error — an unknown movement type, which comes from code and never
 * from a form — stays an `InvalidArgumentException`, and the last test pins that so the conversion
 * cannot be over-applied.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'RFS']);
    $this->otherMall = makeAsset(['code' => 'RFO']);

    $this->store = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Main store', 'code' => 'RFS-1', 'is_active' => true]);
    $this->sub = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Sub store', 'code' => 'RFS-2', 'is_active' => true]);
    $this->foreign = Warehouse::create(['asset_id' => $this->otherMall->id, 'name' => 'Other store', 'code' => 'RFO-1', 'is_active' => true]);

    // A legacy row. The item form has required a positive cost since F-83, but an imported
    // catalogue can still carry 0 — and nothing has ever been received, so there is no loaded cost
    // to average either. This is the one case the valueless guard is still for.
    $this->free = InventoryItem::create([
        'sku' => 'FREE', 'name' => 'Unpriced washer', 'unit' => 'each',
        'unit_cost' => 0, 'reorder_level' => 0, 'is_active' => true,
    ]);

    $this->priced = InventoryItem::create([
        'sku' => 'PRICED', 'name' => 'HVAC filter', 'unit' => 'each',
        'unit_cost' => 50, 'reorder_level' => 0, 'is_active' => true,
    ]);

    app(StockMovementService::class)->record([
        'warehouse_id' => $this->store->id, 'inventory_item_id' => $this->priced->id,
        'type' => 'receipt', 'quantity' => 10, 'unit_cost' => 50,
    ]);

    $this->actingAs(makeUser('super_admin', [$this->mall->id]));
});

it('refuses a cross-property transfer in the reader own language, naming the way out', function () {
    $service = app(StockMovementService::class);
    $tokens = ['from' => $this->store->name, 'to' => $this->foreign->name];

    $english = null;

    try {
        $service->transfer($this->store, $this->foreign, $this->priced, 1);
    } catch (Throwable $e) {
        $english = $e;
    }

    expect($english)->toBeInstanceOf(DomainException::class)
        ->and($english->getMessage())->toBe(__('admin.refusals.stock_transfer_across_properties', $tokens))
        // Nothing moved: the receipt from beforeEach is still the only row.
        ->and(StockMovement::count())->toBe(1);

    app()->setLocale('ar');
    $arabic = null;

    try {
        $service->transfer($this->store, $this->foreign, $this->priced, 1);
    } catch (Throwable $e) {
        $arabic = $e;
    }

    app()->setLocale('en');

    expect($arabic->getMessage())->not->toBe($english->getMessage())
        ->and(preg_match('/\p{Arabic}/u', $arabic->getMessage()))->toBe(1);
});

it('still moves stock between two stores in one property', function () {
    // The control. A refusal test passes just as happily when the whole path is broken.
    app(StockMovementService::class)->transfer($this->store, $this->sub, $this->priced, 4);

    expect(app(StockMovementService::class)->onHand($this->priced, $this->store))->toBe(6.0)
        ->and(app(StockMovementService::class)->onHand($this->priced, $this->sub))->toBe(4.0);
});

it('shows a valueless adjustment as a refusal instead of an error page', function () {
    asTenant($this->mall, function () {
        // An exception escaping `runMovement()` would be thrown out of `callAction` and fail this
        // test — which is the whole point: the refusal has to be CAUGHT after the type change.
        Livewire::test(ListStockMovements::class)
            ->callAction('adjust', [
                'warehouse_id' => $this->store->id,
                'inventory_item_id' => $this->free->id,
                'quantity' => -1,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    expect(StockMovement::where('inventory_item_id', $this->free->id)->count())->toBe(0);
});

it('records a valued adjustment through the same door', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->callAction('adjust', [
                'warehouse_id' => $this->store->id,
                'inventory_item_id' => $this->priced->id,
                'quantity' => -1,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    expect(StockMovement::where('inventory_item_id', $this->priced->id)->where('type', 'adjustment')->count())->toBe(1);
});

it('still treats an unknown movement type as a programming error', function () {
    // NOT a refusal: the type comes from code, never from a form, so it must keep rendering as a
    // fault rather than being shown to somebody in two languages.
    expect(fn () => app(StockMovementService::class)->record([
        'warehouse_id' => $this->store->id,
        'inventory_item_id' => $this->priced->id,
        'type' => 'teleport',
        'quantity' => 3,
    ]))->toThrow(InvalidArgumentException::class);
});
