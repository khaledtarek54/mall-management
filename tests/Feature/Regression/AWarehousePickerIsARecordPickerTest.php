<?php

use App\Filament\Admin\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use App\Support\Filament\EntitySelect;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Forms\Components\Field;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * **The transfer modal picks a WAREHOUSE, so it uses the record picker** (SW-196, 2026-09-04).
 *
 * `EntitySelectConformanceTest` reads each `Select::make()` chain's own text for a
 * `pluck('name', 'id')` and calls that a record picker. The transfer modal wrote
 * `->options(fn () => $this->warehouseOptions())`, and the pluck was twenty lines further down the
 * file in a private helper — so the gate saw nothing. Measured 2026-09-04: it reported ZERO
 * offenders while there were ELEVEN, across seven files. A gate an `Extract Method` defeats is not
 * a gate, so it now follows ONE hop into helpers declared in the same file, which is the only
 * shape that hides these.
 *
 * What the bare Select cost the storeman is the four failures `EntitySelect`'s own docblock lists,
 * every one of which renders as an empty or ambiguous dropdown rather than an error: one raw
 * column searched, neither side folded, one line per option, and the property scope re-written by
 * hand instead of derived from `PropertyIsolation`.
 *
 * The two assertions a plain `->options()` array can never satisfy are a SERVER search
 * (`getSearchResults()` returns `[]` with no `getSearchResultsUsing`, which is exactly what a bare
 * Select has) and a scope that is also the WRITE guard. Both are paired with a control, because a
 * picker that found nothing at all would satisfy the refusals on its own.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'PKR']);
    $this->otherMall = makeAsset(['code' => 'PKO']);

    $this->main = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Parts store', 'code' => 'PKR-1', 'is_active' => true]);
    $this->sub = Warehouse::create(['asset_id' => $this->mall->id, 'name' => 'Consumables store', 'code' => 'PKR-2', 'is_active' => true]);
    $this->foreign = Warehouse::create(['asset_id' => $this->otherMall->id, 'name' => 'Zamalek annexe', 'code' => 'PKO-1', 'is_active' => true]);

    $this->item = InventoryItem::create([
        'sku' => 'FILT', 'name' => 'HVAC filter', 'unit' => 'each',
        'unit_cost' => 50, 'reorder_level' => 0, 'is_active' => true,
    ]);

    $this->actingAs(makeUser('super_admin', [$this->mall->id]));
});

/**
 * The MOUNTED transfer modal's fields, keyed by name.
 *
 * Mounted rather than read off the action: an action's schema is a closure, so enumerating actions
 * proves nothing about the modal an operator actually opens.
 */
function transferModalFields(): Collection
{
    $page = Livewire::test(ListStockMovements::class)->mountAction('transfer');
    $instance = $page->instance();
    $schema = $instance->getSchema($instance->getMountedActionSchemaName());

    expect($schema)->not->toBeNull('the transfer modal built no schema');

    return collect($schema->getComponents(withHidden: true))
        ->filter(fn ($component) => $component instanceof Field)
        ->keyBy(fn (Field $component) => $component->getName());
}

it('picks a store through the shared record picker on both ends of a transfer', function () {
    asTenant($this->mall, function () {
        $fields = transferModalFields();

        foreach (['from_warehouse_id', 'to_warehouse_id'] as $name) {
            expect($fields->get($name))->toBeInstanceOf(EntitySelect::class)
                ->and($fields->get($name)->getEntityModel())->toBe(Warehouse::class)
                // It OPENS on something rather than waiting to be typed into.
                ->and($fields->get($name)->getOptions())->not->toBeEmpty()
                // And it SEARCHES on the SERVER, through the folded blob.
                ->and($fields->get($name)->getSearchResults('parts'))->not->toBeEmpty();
        }
    });
});

it('cannot reach a store in another mall, from either end', function () {
    // The scope is also the WRITE guard: Filament validates a Select by asking it to resolve the
    // submitted value's label, so a store this picker cannot find is a store it cannot accept.
    asTenant($this->mall, function () {
        $fields = transferModalFields();

        foreach (['from_warehouse_id', 'to_warehouse_id'] as $name) {
            expect($fields->get($name)->getSearchResults('zamalek'))->toBe([])
                // The control in the same breath: this mall's own stores ARE reachable, so the
                // emptiness above is a property scope and not a broken lookup.
                ->and($fields->get($name)->getSearchResults('consumables'))->not->toBeEmpty();
        }
    });
});

it('still moves stock between two stores through the real modal', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->callAction('receive', [
                'warehouse_id' => $this->main->id,
                'inventory_item_id' => $this->item->id,
                'quantity' => 10,
                'unit_cost' => 50,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', [
                'from_warehouse_id' => $this->main->id,
                'to_warehouse_id' => $this->sub->id,
                'inventory_item_id' => $this->item->id,
                'quantity' => 4,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    $service = app(StockMovementService::class);

    expect($service->onHand($this->item, $this->main))->toBe(6.0)
        ->and($service->onHand($this->item, $this->sub))->toBe(4.0);
});

it('will not send stock to the store it is coming from', function () {
    // Narrowing the OPTIONS is what refuses it — the same mechanism the old `->except()` relied
    // on, kept rather than replaced by a message after the fact.
    asTenant($this->mall, function () {
        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', [
                'from_warehouse_id' => $this->main->id,
                'to_warehouse_id' => $this->main->id,
                'inventory_item_id' => $this->item->id,
                'quantity' => 1,
                'moved_on' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['to_warehouse_id']);
    });
});
