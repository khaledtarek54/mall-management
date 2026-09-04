<?php

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * GROSS AND LEASABLE AREA ARE A LOAD FACTOR, NOT TWO UNRELATED NUMBERS.
 *
 * The property form took both with `numeric()->minValue(0)` and no cross-field rule, so a mall could
 * be recorded as letting more space than it contains. Measured at HEAD: 800 gross against 1,000
 * leasable saved without complaint, and `Asset::leasableEfficiencyPct()` — the load factor the
 * properties table prints beside them — returned 125%.
 *
 * The money reading is `CamReconciliationService`, which takes the DECLARED leasable area as the GLA
 * denominator of the whole recovery: inflate it and every tenant's share shrinks, so the mall
 * under-recovers its common costs and nothing anywhere reports a fault.
 *
 * Driven through the REAL create and edit pages, because a rule built in a test proves nothing about
 * the page that has to enforce it. Every refusal is paired with a control that must succeed —
 * including the one that matters most here: BLANK IS NOT ZERO, and a mall that has only ever
 * recorded its GLA must still save.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(Asset::query()->where('code', Asset::ALL_PROPERTIES_CODE)->first());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a leasable area larger than the building it is in', function () {
    Livewire::test(CreateAsset::class)
        ->fillForm([
            'name' => 'Overlet Mall',
            'code' => 'OVLT',
            'type' => 'mall',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency' => 'EGP',
            'total_area_sqm' => 800,
            'leasable_area_sqm' => 1000,
        ])
        ->call('create')
        ->assertHasFormErrors(['leasable_area_sqm']);

    expect(Asset::query()->where('code', 'OVLT')->exists())->toBeFalse();
});

it('saves a property whose leasable area fits — the control', function () {
    Livewire::test(CreateAsset::class)
        ->fillForm([
            'name' => 'Atriom North',
            'code' => 'NRTH',
            'type' => 'mall',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency' => 'EGP',
            'total_area_sqm' => 1000,
            'leasable_area_sqm' => 800,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::query()->where('code', 'NRTH')->firstOrFail();

    expect(round((float) $asset->leasableEfficiencyPct(), 1))->toEqual(80.0);
});

it('still saves a property whose building has not been measured — blank is NOT zero', function () {
    // The control the naive fix breaks. Both columns are optional and the GLA is usually the number
    // an operator has first; `leasableEfficiencyPct()` answers null rather than 0% for exactly this
    // case. Filament's own `->lte()` would refuse this outright, which is why the rule is a closure.
    Livewire::test(CreateAsset::class)
        ->fillForm([
            'name' => 'Atriom South',
            'code' => 'STH',
            'type' => 'mall',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency' => 'EGP',
            'total_area_sqm' => null,
            'leasable_area_sqm' => 800,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::query()->where('code', 'STH')->firstOrFail();

    expect((float) $asset->leasable_area_sqm)->toEqual(800.0)
        ->and($asset->leasableEfficiencyPct())->toBeNull();
});

it('refuses shrinking the building below what is already let — the other door', function () {
    // Guarding only the leasable field leaves this open, and it is the likelier edit: the GLA was
    // right and someone re-keys the gross area from a new survey.
    $asset = makeAsset(['code' => 'SHRK', 'total_area_sqm' => 1000, 'leasable_area_sqm' => 800]);
    Filament::setTenant($asset);

    Livewire::test(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->fillForm(['total_area_sqm' => 500])
        ->call('save')
        ->assertHasFormErrors(['total_area_sqm']);

    expect((float) $asset->fresh()->total_area_sqm)->toEqual(1000.0);
});

it('lets an ordinary remeasurement through on the edit page — the control', function () {
    $asset = makeAsset(['code' => 'GROW', 'total_area_sqm' => 1000, 'leasable_area_sqm' => 800]);
    Filament::setTenant($asset);

    Livewire::test(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->fillForm(['total_area_sqm' => 1200])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $asset->fresh()->total_area_sqm)->toEqual(1200.0);
});
