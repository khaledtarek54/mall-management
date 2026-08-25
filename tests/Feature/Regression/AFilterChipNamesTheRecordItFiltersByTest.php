<?php

/**
 * An entity filter must survive being CHOSEN — and then say what was chosen.
 *
 * Reported from the panel as a 500 on `/admin/AW/rentable-items`: picking a floor threw
 * `Unknown column 'floors.floor' in 'order clause'`. The dropdown itself was fine, because
 * `EntitySelect` serves it; what compiled that SQL was the CHIP. Filament resolves an active
 * filter's label by re-reading the record it names through `getRelationshipQuery()`, which
 * orders by the relationship's TITLE ATTRIBUTE — and `EntitySelectFilter` defaulted that
 * attribute at CALL TIME from `$this->entityModel`, which is null until `->entity()` runs.
 * Every call site in the panel reads `->relationship('floor')->entity(Floor::class)`, so the
 * default was always the fallback: the relationship NAME, used as a column.
 *
 * Eleven filters, one cause, and none of them visible until somebody picked a value:
 * `tenants.tenant` (invoices, payments, credit notes, leases), `units.unit`, `vendors.vendor`,
 * `employees.employee`, `departments.department`, `users.head`, `users.creator`, `floors.floor`.
 *
 * The second half is what the chip then SAYS. Filament's own indicator never reads the
 * `getOptionLabelFromRecordUsing` callback `entity()` installs — it plucks the title-attribute
 * column raw, so a floor would have read "0" (its `level`) — and for an entity filter naming no
 * relationship it reads `getOptions()`, which is empty here, so those rendered NO chip at all:
 * a filter silently applied with nothing in the bar to say so or to clear it.
 *
 * Paired with a control throughout — a filter that returned nothing would satisfy "does not
 * error" just as happily as one that works.
 */

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\RentableItems\Pages\ListRentableItems;
use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Models\Department;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Models\Tenant;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Search\OptionDisplay;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'AW']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $this->ground = Floor::create(['asset_id' => $this->asset->id, 'code' => 'G', 'name' => 'Ground floor', 'level' => 0]);
    $this->first = Floor::create(['asset_id' => $this->asset->id, 'code' => '1', 'name' => 'First floor', 'level' => 1]);

    $this->onGround = RentableItem::create([
        'asset_id' => $this->asset->id,
        'floor_id' => $this->ground->id,
        'code' => 'P-01',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 500,
    ]);
    $this->onFirst = RentableItem::create([
        'asset_id' => $this->asset->id,
        'floor_id' => $this->first->id,
        'code' => 'P-99',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 700,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('filters by floor and names the floor in the chip', function () {
    $component = Livewire::test(ListRentableItems::class)
        ->filterTable('floor_id', $this->ground->id);

    // The control. A filter that matched nothing would pass every refusal below.
    expect(tableRows($component)->pluck('code')->all())->toBe(['P-01']);

    // The 500 lived here, not in the query above: a second builder, ordered differently.
    $indicators = $component->instance()->getTable()->getFilterIndicators();

    $labels = collect($indicators)->map(fn ($i) => (string) $i->getLabel())->implode(' | ');

    // Not "0". The chip reads the floor the way the picker offered it.
    expect($labels)->toContain(OptionDisplay::for($this->ground)->toText())
        ->and($labels)->toContain('Ground floor');

    $component->assertOk();
});

it('orders the chip lookup by the entity registry, whichever way the chain is written', function () {
    // The two orderings must be one behaviour — `EntitySelect` states that property for the
    // field and this is its filter half. Before the fix the first of these answered 'floor'.
    $relationshipFirst = EntitySelectFilter::make('floor_id')->relationship('floor')->entity(Floor::class);
    $entityFirst = EntitySelectFilter::make('floor_id')->entity(Floor::class)->relationship('floor');

    foreach ([$relationshipFirst, $entityFirst] as $filter) {
        expect($filter->getRelationshipTitleAttribute())
            ->toBe(OptionDisplay::order(Floor::class)[0])
            ->toBe('level');
    }
});

it('still shows a chip for an entity filter that names no relationship', function () {
    // `TenantRequestsTable`'s department filter is the shape with no `->relationship()` at all.
    // Filament falls back to `getOptions()` for those, which is empty because the options come
    // from the EntitySelect — so the chip was simply absent.
    $department = Department::create(['asset_id' => $this->asset->id, 'name' => 'Facilities']);
    $tenant = makeTenant();
    $unit = makeUnit($this->asset);
    makeLease($unit, $tenant);

    $request = makeTenantRequest([
        'asset_id' => $this->asset->id,
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'department_id' => $department->id,
    ]);

    $component = Livewire::test(ListTenantRequests::class)
        ->filterTable('department_id', $department->id);

    expect(tableRows($component)->pluck('id')->all())->toContain($request->id);

    $labels = collect($component->instance()->getTable()->getFilterIndicators())
        ->map(fn ($i) => (string) $i->getLabel())
        ->implode(' | ');

    expect($labels)->toContain('Facilities');

    $component->assertOk();
});

it('resolves the chip through the property scope, not through a bare find', function () {
    // `OptionDisplay::pickable()`'s docblock makes the label lookup the write-side guard. A chip
    // is a read of the same kind: an id from another mall must not come back as a NAME.
    $elsewhere = makeAsset(['code' => 'BW']);
    $theirs = Floor::create(['asset_id' => $elsewhere->id, 'code' => 'G', 'name' => 'Their ground', 'level' => 0]);

    $filter = EntitySelectFilter::make('floor_id')->relationship('floor')->entity(Floor::class);

    expect($filter->indicatorLabelsFor([$theirs->id]))->toBe([]);
    // Control: our own floor still resolves, so the emptiness above is the scope and not a
    // lookup that never works.
    expect($filter->indicatorLabelsFor([$this->ground->id]))->not->toBe([]);
});

it('leaves the tenant filter usable on the money documents it broke', function () {
    // The reported crash was on rentable items; the same default broke the tenant filter on
    // every money list. One of them, driven end to end.
    $tenant = makeTenant(['name' => 'Zara Home']);
    $lease = makeLease(makeUnit($this->asset), $tenant);
    $invoice = makeInvoice($lease);
    makeInvoice(makeLease(makeUnit($this->asset), makeTenant(['name' => 'Other Retailer'])));

    $component = Livewire::test(ListInvoices::class)
        ->filterTable('tenant_id', $tenant->id);

    expect(tableRows($component)->pluck('id')->all())->toBe([$invoice->id]);

    $labels = collect($component->instance()->getTable()->getFilterIndicators())
        ->map(fn ($i) => (string) $i->getLabel())
        ->implode(' | ');

    expect($labels)->toContain('Zara Home');

    $component->assertOk();
});

it('has a tenant filter on the money lists at all', function () {
    // Guards the premise of the test above: if the filter were renamed or dropped, `filterTable`
    // on a name nothing answers to would leave the list unfiltered and the assertion would be
    // measuring the default page.
    expect(Tenant::query()->count())->toBeGreaterThanOrEqual(0);

    $filters = Livewire::test(ListInvoices::class)
        ->instance()->getTable()->getFilters();

    expect($filters)->toHaveKey('tenant_id')
        ->and($filters['tenant_id'])->toBeInstanceOf(EntitySelectFilter::class);
});
