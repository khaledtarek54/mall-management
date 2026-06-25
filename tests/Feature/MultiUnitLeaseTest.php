<?php

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('mirrors a single-unit lease into one master pivot row and occupies the unit', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'vacant']);

    $lease = makeLease($unit, null, ['status' => 'active']);

    $units = $lease->units()->get();
    expect($units)->toHaveCount(1)
        ->and($units->first()->id)->toBe($unit->id)
        ->and((bool) $units->first()->pivot->is_master)->toBeTrue()
        ->and($unit->fresh()->status)->toBe('occupied');
});

it('spans several units under one master and occupies all of them', function () {
    $asset = makeAsset();
    $master = makeUnit($asset, ['code' => 'A-01', 'status' => 'vacant']);
    $extra = makeUnit($asset, ['code' => 'A-02', 'status' => 'vacant']);

    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    $ids = $lease->units()->pluck('units.id')->all();
    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($master->id)
        ->and($ids)->toContain($extra->id)
        ->and((int) $lease->fresh()->unit_id)->toBe($master->id)   // master mirrored to unit_id
        ->and($master->fresh()->status)->toBe('occupied')
        ->and($extra->fresh()->status)->toBe('occupied');          // the additional unit is occupied too

    // exactly one master flag
    expect($lease->units()->wherePivot('is_master', true)->count())->toBe(1);
});

it('moves the master designation and mirrors it to leases.unit_id', function () {
    $asset = makeAsset();
    $a = makeUnit($asset, ['code' => 'A-01']);
    $b = makeUnit($asset, ['code' => 'A-02']);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $b->id);   // promote B to master

    expect((int) $lease->fresh()->unit_id)->toBe($b->id)
        ->and($lease->fresh()->masterUnit->code)->toBe('A-02');
});

it('frees a unit when it is removed from the lease', function () {
    $asset = makeAsset();
    $a = makeUnit($asset);
    $b = makeUnit($asset);

    $lease = makeLease($a, null, ['status' => 'active']);
    $lease->syncUnits([$a->id, $b->id], $a->id);
    expect($b->fresh()->status)->toBe('occupied');

    $lease->syncUnits([$a->id], $a->id);            // drop B

    expect($lease->units()->pluck('units.id')->all())->toBe([$a->id])
        ->and($b->fresh()->status)->toBe('vacant')   // freed
        ->and($a->fresh()->status)->toBe('occupied');
});

it('adds an additional unit through the edit form and occupies it', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    $master = makeUnit($asset, ['code' => 'A-01', 'status' => 'vacant']);
    $extra = makeUnit($asset, ['code' => 'A-02', 'status' => 'vacant']);
    $lease = makeLease($master, null, ['status' => 'active']);

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->assertFormSet(['additional_unit_ids' => []])      // none yet (prefill)
        ->fillForm(['additional_unit_ids' => [$extra->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lease->units()->pluck('units.id')->all())->toContain($extra->id)
        ->and($extra->fresh()->status)->toBe('occupied');   // additional unit now occupied
});

it('lists additional units in the leases table', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    $asset = makeAsset(['code' => 'HW']);
    Filament::setTenant($asset);

    $master = makeUnit($asset, ['code' => 'A-01']);
    $extra = makeUnit($asset, ['code' => 'A-02']);
    $lease = makeLease($master, null, ['status' => 'active']);
    $lease->syncUnits([$master->id, $extra->id], $master->id);

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertSee('A-02');   // additional unit surfaced in the row description
});
