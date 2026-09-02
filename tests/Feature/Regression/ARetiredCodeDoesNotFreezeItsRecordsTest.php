<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Admin\Resources\TenantRequests\Pages\EditTenantRequest;
use App\Models\TenantRequest;
use App\Models\TenantRequestSubcategory;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TenantRequestSubcategorySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * RETIRING A CATALOGUE CODE MADE EVERY RECORD ALREADY CARRYING IT UNSAVABLE.
 *
 * `IsCodeCatalogue::catalogueOptions()` offers only ACTIVE rows, which is right — retiring a code is
 * how an operator stops it being chosen again. But Filament derives a `Select`'s `Rule::in` from the
 * options it resolved, so the moment a code leaves that list every record carrying it breaks, and in
 * the worst way: nothing on the screen says so. The field renders empty (Filament cannot label a
 * value it was not offered) and then either the submit is refused as *invalid* on a field the
 * operator never touched, or — where the field is optional — the save SUCCEEDS and silently blanks a
 * classification that was correct.
 *
 * That is the other half of the 2026-08-18 deposit bug. The per-code floor fixed the case where a
 * SHIPPED code had no row; it cannot help a code the operator deliberately switched off, which is
 * the ordinary case a catalogue exists for.
 *
 * Fixed in the CONTAINER, not at twenty-two call sites: `Field::make()` is
 * `app($fieldClass, ['name' => $name])`, so every Select in both panels resolves through it — the
 * same seam that put `AuthorizedAction` there, and for the same reason. And it is DERIVED from
 * `ValueSets::catalogueWidenedColumns()`, so a catalogue that grows a column is covered by being
 * registered rather than by anyone editing the class.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(TenantRequestSubcategorySeeder::class);

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Built on THIS asset's own unit — `makeTenantRequest()` mints its own property, and the
    // resource is property-scoped, so a request on somebody else's mall is a 404 here rather than a
    // finding.
    $this->request = makeTenantRequest([
        'unit_id' => makeUnit($this->asset)->id,
        'asset_id' => $this->asset->id,
        'request_type' => 'maintenance',
        'category' => 'electrical',
    ]);
});

it('still offers a retired code to the record that already carries it', function () {
    // The control: while active, it is an ordinary option.
    $sub = TenantRequestSubcategory::where('code', 'electrical')->sole();

    expect(TenantRequestSubcategory::optionsFor(\App\Enums\TenantRequestType::Maintenance))
        ->toHaveKey('electrical');

    // The operator retires it — the request already on the board still says `electrical`.
    $sub->update(['is_active' => false]);
    TenantRequestSubcategory::flushCatalogue();

    expect(TenantRequestSubcategory::optionsFor(\App\Enums\TenantRequestType::Maintenance))
        ->not->toHaveKey('electrical');

    asTenant($this->asset, function () {
        Livewire::test(EditTenantRequest::class, ['record' => $this->request->getRouteKey()])
            // The field still reads its own value…
            ->assertFormSet(['category' => 'electrical'])
            // …and the record saves, which is the whole finding.
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($this->request->fresh()->category)->toBe('electrical');
});

it('still refuses a retired code on a CREATE form', function () {
    // The other direction, and the one that would make the fix worse than the bug: retiring a code
    // must still stop it being CHOSEN. Only a saved record can be carrying one, so the carve-out is
    // keyed on `$record->exists`.
    //
    // Driven through the real create page, not a detached component: a bare `Select::make()` falls
    // through on the container check long before it reaches the record check, so it would prove
    // nothing about this guard.
    $sub = TenantRequestSubcategory::where('code', 'electrical')->sole();
    $sub->update(['is_active' => false]);
    TenantRequestSubcategory::flushCatalogue();

    asTenant($this->asset, function () {
        Livewire::test(CreateTenantRequest::class)
            ->fillForm([
                'unit_id' => makeUnit($this->asset)->id,
                'title' => 'Lights out',
                'description' => 'The corridor lights are out',
                'request_type' => 'maintenance',
                'category' => 'electrical',
                'priority' => 'medium',
                'channel' => 'portal',
                'status' => 'submitted',
            ])
            ->call('create')
            ->assertHasFormErrors(['category']);
    });
});

it('leaves an ordinary Select alone', function () {
    // Every other Select in the app must fall straight through — the registry lookup is on a key
    // that is not there, and a value absent from the options stays absent.
    $select = \App\Support\Filament\CatalogueAwareSelect::make('status')
        ->options(['open' => 'Open', 'closed' => 'Closed']);

    expect($select->getOptions())->toBe(['open' => 'Open', 'closed' => 'Closed']);
});

it('binds the catalogue-aware Select in the container', function () {
    // The binding IS the fix — a Select built with `new` would behave correctly and every screen
    // would still be broken. Pinned so a refactor cannot quietly remove it.
    expect(\Filament\Forms\Components\Select::make('probe'))
        ->toBeInstanceOf(\App\Support\Filament\CatalogueAwareSelect::class);
});
