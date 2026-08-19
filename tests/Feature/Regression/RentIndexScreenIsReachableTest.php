<?php

/*
|--------------------------------------------------------------------------
| The index register has to be reachable, or the CPI clause feeds on nothing
|--------------------------------------------------------------------------
| This project's recurring failure is a capability with no surface — one sweep found four fully
| built, fully tested services that nothing could start. A CPI clause is worse than most: the sweep
| refuses to invent a figure, so with no way to ENTER one the whole feature is a lease field that
| never does anything, and it fails silently by design.
|
| So the screens are driven, not merely registered.
*/

use App\Filament\Admin\Resources\RentIndices\Pages\CreateRentIndex;
use App\Filament\Admin\Resources\RentIndices\Pages\ListRentIndices;
use App\Models\RentIndex;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

it('lists the register for the role that maintains it', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));

    $row = RentIndex::create(['code' => 'EGY_CPI', 'period' => '2026-09-01', 'value' => 119.6]);

    // The panel is property-scoped, so a screen needs a current property even when the RECORD is
    // portfolio-shared — the register is national, the panel it is read in is not.
    Filament::setTenant($this->asset);

    Livewire::test(ListRentIndices::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$row]);
});

it('records a figure through the real form', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(CreateRentIndex::class)
        ->fillForm([
            // Lower case on the way in — the form upper-cases it, so two spellings cannot become
            // two indices that look identical in a dropdown and never match each other.
            'code' => 'egy_cpi',
            // Mid-month, to prove the period normalises to the first.
            'period' => '2026-10-17',
            'value' => 121.4,
            'published_on' => '2026-11-10',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = RentIndex::sole();

    expect($row->code)->toBe('EGY_CPI')
        ->and($row->period->toDateString())->toBe('2026-10-01')
        ->and((float) $row->value)->toBe(121.4);
});

/**
 * The permission split, driven rather than asserted about: leasing maintains the register because
 * the person reading the CAPMAS release administers the leases that follow it; accounting reads it
 * because the resulting step lands in their books as an ordinary rent change.
 */
it('lets accounting read the register but not add to it', function () {
    $accounting = makeUser('accounting', [$this->asset->id]);

    expect($accounting->can('rent_indices.view'))->toBeTrue()
        ->and($accounting->can('rent_indices.create'))->toBeFalse();
});

/** A role with no business here cannot open it at all. */
it('withholds the register from a role that has no use for it', function () {
    $technician = makeUser('technician', [$this->asset->id]);

    expect($technician->can('rent_indices.view'))->toBeFalse();
});
