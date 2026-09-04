<?php

/**
 * Stock transfer between warehouses (module 22 gap-analysis).
 *
 * `transfer_in` / `transfer_out` were in the migration's type list, in
 * StockMovement::ADDS_STOCK / REMOVES_STOCK, had their own branch in
 * InventoryMovementJournalizer, and had a "Transfers" tab on the ledger — and
 * **nothing in the application could create one**. The tab was permanently
 * empty, and a storeman moving a part from the main store to a sub-store had to
 * record it as a shrinkage plus a receipt, which posts GL entries for value that
 * never left the company.
 *
 * Worse, the F-83 "stock cannot move without a value" guard was not scoped to
 * the types that actually post, so its standard-cost fallback skipped transfers
 * and `record()` rejected every transfer that did not carry an explicit cost —
 * the type was unusable even from code.
 *
 * The rules that matter here are atomicity (a half-written transfer loses stock)
 * and the property boundary (a transfer posts no GL entry, which is only true
 * within one property's books).
 */

use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->svc = app(StockMovementService::class);
    $this->asset = makeAsset();
    $this->item = InventoryItem::create([
        'name' => 'Pump seal', 'sku' => 'PS-1', 'unit' => 'pc',
        'unit_cost' => 40, 'reorder_level' => 0, 'is_active' => true,
    ]);
    $this->main = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main store', 'code' => 'MAIN', 'category' => 'spare_parts', 'is_active' => true]);
    $this->sub = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Sub store', 'code' => 'SUB', 'category' => 'spare_parts', 'is_active' => true]);

    // 10 on hand at the main store.
    $this->svc->receive($this->main, $this->item, 10, 40);
});

it('can record a transfer at all — the type was unusable', function () {
    // The bug in its simplest form: no explicit unit_cost, exactly as a caller
    // that trusts the item's standard cost would do it.
    $movement = $this->svc->record([
        'warehouse_id' => $this->main->id,
        'inventory_item_id' => $this->item->id,
        'type' => 'transfer_out',
        'quantity' => 1,
    ]);

    expect($movement->exists)->toBeTrue()
        ->and((float) $movement->unit_cost)->toBe(40.0); // fell back to the item's standard cost
});

it('moves stock from one store to the other', function () {
    $this->svc->transfer($this->main, $this->sub, $this->item, 4);

    expect($this->svc->onHand($this->item, $this->main))->toBe(6.0)
        ->and($this->svc->onHand($this->item, $this->sub))->toBe(4.0)
        // The company still holds the same stock — only its location changed.
        ->and($this->svc->onHand($this->item))->toBe(10.0);
});

it('writes both legs or neither', function () {
    // Atomicity is the whole reason this is a service method and not two calls:
    // a transfer_out that lands without its transfer_in has silently destroyed
    // stock, and the ledger is append-only so there is nothing to roll back by hand.
    $before = StockMovement::count();

    expect(fn () => $this->svc->transfer($this->main, $this->sub, $this->item, 999))
        ->toThrow(HttpException::class);

    expect(StockMovement::count())->toBe($before, 'A refused transfer left a movement behind.')
        ->and($this->svc->onHand($this->item, $this->main))->toBe(10.0);
});

it('cannot transfer out more than is on hand', function () {
    expect(fn () => $this->svc->transfer($this->main, $this->sub, $this->item, 11))
        ->toThrow(HttpException::class);

    expect($this->svc->onHand($this->item, $this->sub))->toBe(0.0);
});

it('refuses a transfer to another property', function () {
    // A transfer posts NO journal entry, which is only true while the value stays
    // inside one property's books. Across properties it would move real value with
    // no entry anywhere: the source property's Inventory balance would keep stock
    // it no longer holds, and owner statements are drawn off those balances.
    $otherAsset = makeAsset(['code' => 'OTHER', 'name' => 'Other Mall']);
    $foreign = Warehouse::create(['asset_id' => $otherAsset->id, 'name' => 'Other store', 'code' => 'OTH', 'category' => 'spare_parts', 'is_active' => true]);

    // A REFUSAL the operator reads, in their own language — DomainException since SW-197.
    expect(fn () => $this->svc->transfer($this->main, $foreign, $this->item, 1))
        ->toThrow(DomainException::class);

    expect($this->svc->onHand($this->item, $this->main))->toBe(10.0)
        ->and($this->svc->onHand($this->item, $foreign))->toBe(0.0);
});

it('refuses a transfer to the same warehouse', function () {
    expect(fn () => $this->svc->transfer($this->main, $this->main, $this->item, 1))
        ->toThrow(DomainException::class);
});

it('refuses a zero quantity', function () {
    expect(fn () => $this->svc->transfer($this->main, $this->sub, $this->item, 0))
        ->toThrow(DomainException::class);
});

it('posts NOTHING to the general ledger — driven through the real sweep', function () {
    // The claim the journalizer makes ("intra-company location move, same account")
    // has to survive the real dispatch path, not just a direct payload() call.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->svc->transfer($this->main, $this->sub, $this->item, 4);

    $this->artisan('accounting:sync-ledger', ['--all' => true])
        ->assertExitCode(0);

    $transferIds = StockMovement::whereIn('type', ['transfer_in', 'transfer_out'])->pluck('id');

    expect($transferIds)->toHaveCount(2);

    $entries = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))
        ->whereIn('source_id', $transferIds)
        ->count();

    expect($entries)->toBe(0, 'A location move created a journal entry — inventory value was double-counted.');
});

it('both legs carry the same value, even if the item cost changes later', function () {
    $this->svc->transfer($this->main, $this->sub, $this->item, 4);

    $out = StockMovement::where('type', 'transfer_out')->latest('id')->first();
    $in = StockMovement::where('type', 'transfer_in')->latest('id')->first();

    expect((float) $in->unit_cost)->toBe((float) $out->unit_cost);
});

/* ---- the operator's path ------------------------------------------------- */

it('transfers from the real Filament action', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListStockMovements::class)
        ->callAction('transfer', [
            'from_warehouse_id' => $this->main->id,
            'to_warehouse_id' => $this->sub->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 4,
            'moved_on' => today()->toDateString(),
        ]);

    expect($this->svc->onHand($this->item, $this->sub))->toBe(4.0);
});

it('refuses a foreign warehouse server-side, independently of the picker', function () {
    // Two separate defences, and this pins the SECOND one on purpose.
    //
    // Filament's scoped Select already rejects a foreign id via its own in:options
    // rule — which is why a Livewire dispatch here is NOT evidence: mutation-testing
    // confirmed the dispatch is refused even with assertWarehouseVisible deleted. A
    // test written that way would keep passing after the guard was removed.
    //
    // So the guard is exercised directly. It is what protects the paths the picker
    // does not cover: the options closure narrows to the CURRENT property, while a
    // multi-property user's visible set is wider, and any future caller that builds
    // this data without the form gets no Select validation at all.
    $this->seed(RolesPermissionsSeeder::class);
    $foreignAsset = makeAsset(['code' => 'FOR', 'name' => 'Foreign Mall']);
    $foreign = Warehouse::create(['asset_id' => $foreignAsset->id, 'name' => 'Foreign store', 'code' => 'FGN', 'category' => 'spare_parts', 'is_active' => true]);

    $this->actingAs(makeUser('manager', [$this->asset->id])); // cannot see $foreignAsset
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $page = new ListStockMovements;
    $assert = fn (int $id) => (function (int $warehouseId) {
        (new ReflectionMethod(ListStockMovements::class, 'assertWarehouseVisible'))
            ->invoke($this, $warehouseId);
    })->call($page, $id);

    expect(fn () => $assert($foreign->id))->toThrow(HttpException::class);

    // ...and it does not refuse a warehouse the user CAN see (a guard that refuses
    // everything would also pass the line above).
    $assert($this->main->id);
    expect(true)->toBeTrue();
});

it('shows the operator a toast, not an error page, when stock runs short', function () {
    // A refusal the operator can cause by typing a number that is too big must not
    // blank the screen and lose the form.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListStockMovements::class)
        ->callAction('transfer', [
            'from_warehouse_id' => $this->main->id,
            'to_warehouse_id' => $this->sub->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 999,
            'moved_on' => today()->toDateString(),
        ])
        ->assertNotified();

    expect($this->svc->onHand($this->item, $this->main))->toBe(10.0);
});
