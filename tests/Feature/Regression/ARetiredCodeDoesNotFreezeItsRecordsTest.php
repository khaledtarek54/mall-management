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

it('STILL REFUSES a code the picker does not offer, on an existing record', function () {
    // **THE ADVERSARIAL QUESTION, AND THE FIRST VERSION FAILED IT.** Keying the append on
    // `getState()` — whatever the CLIENT last submitted — meant any string a crafted payload sent
    // was appended as a valid option; `labelFor()` returns the code itself for an unknown one and
    // can never fail, so Filament emitted NO `In` rule at all and `Rule::in` was dead on all sixteen
    // catalogue columns.
    //
    // `parking` is an ACCESS subcategory: the maintenance picker offers fourteen codes and not that
    // one. Before the fix it was refused; with the state-keyed append it saved cleanly, and
    // `TenantRequestSubcategory→trade` is request-type-scoped, so the resulting work order would
    // carry NO trade — the exact defect EG-14 was written to end. The `ValueSets` floor for this
    // column is deliberately flat across every type, so `Rule::in` was the only thing enforcing the
    // scoping.
    //
    // Every mutation on this file until now proved the carve-out FIRES. This is the one that proves
    // it stops.
    asTenant($this->asset, function () {
        Livewire::test(EditTenantRequest::class, ['record' => $this->request->getRouteKey()])
            ->fillForm(['category' => 'parking'])
            ->call('save')
            ->assertHasFormErrors(['category']);
    });

    expect($this->request->fresh()->category)->toBe('electrical');
});

it('falls through for a detached component, rather than throwing', function () {
    // `getRecord()` reaches for `$container`, a typed property with no default, so a bare
    // `Select::make()->options([...])` in a tool or a gate would fatal on a call that worked before
    // this binding existed. It is NOT a statement about ordinary Selects — a detached component
    // returns at the container check long before the registry is consulted, which is exactly why
    // the case above had to be driven through a real page.
    $select = \App\Support\Filament\CatalogueAwareSelect::make('category')
        ->options(['open' => 'Open', 'closed' => 'Closed']);

    expect($select->getOptions())->toBe(['open' => 'Open', 'closed' => 'Closed']);
});

it('binds the catalogue-aware Select in the container', function () {
    // The binding IS the fix — a Select built with `new` would behave correctly and every screen
    // would still be broken. Pinned so a refactor cannot quietly remove it.
    expect(\Filament\Forms\Components\Select::make('probe'))
        ->toBeInstanceOf(\App\Support\Filament\CatalogueAwareSelect::class);
});
