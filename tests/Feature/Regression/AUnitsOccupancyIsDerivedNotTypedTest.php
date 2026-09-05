<?php

use App\Filament\Admin\Resources\RentableItems\Pages\EditRentableItem;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Models\RentableItem;
use App\Models\Unit;
use App\Support\ProjectedState;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A unit's occupancy is a PROJECTION, so the form must not offer it as a choice.
 *
 * Reported by the tester with a screen recording: set unit A-01's status to Occupied, press Save,
 * read "Saved", go back to the list — Vacant. `Unit::recomputeStatus()` derives vacant/reserved/
 * occupied from the leases holding the unit, and `EditUnit::afterSave()` re-projects on every save,
 * so the operator's entry was discarded on the same request that accepted it.
 *
 * The projection is CORRECT and is not what changed — `EditUnit` has a comment describing that
 * self-healing as intended. The defect was the form offering four states when only two of them are
 * a person's to state. A control that silently throws away what you typed is worse than one that
 * does not offer the choice.
 *
 * `RentableItem` had the identical shape (`assigned` is projected, `out_of_service` is the
 * override) and is covered here too — found by grepping for the shape, not from the tester's card.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

it('offers only the states an operator may declare when creating a unit', function () {
    asTenant($this->asset, function () {
        $options = Livewire::test(CreateUnit::class)
            ->instance()
            ->form
            ->getFlatFields()['status']
            ->getOptions();

        expect(array_keys($options))->toBe(['vacant', 'maintenance'])
            ->and($options)->not->toHaveKey('occupied')
            ->and($options)->not->toHaveKey('reserved');
    });
});

it('will not let an operator type a unit into occupancy', function () {
    // The tester's exact act. Filament derives a Select's `Rule::in` from the options it resolved,
    // so a state the form no longer offers is refused rather than accepted-then-reverted.
    $unit = makeUnit($this->asset, ['status' => 'vacant']);

    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['status' => 'occupied'])
            ->call('save')
            ->assertHasFormErrors(['status']);
    });

    expect($unit->fresh()->status)->toBe('vacant');
});

it('still lets an operator take a vacant unit out of service, and put it back', function () {
    // The control, and the whole point of keeping the field: `maintenance` IS a person's statement
    // and the projector honours it. Both directions, because a fix that froze the field would
    // satisfy the refusal above and break the only workflow it exists for.
    $unit = makeUnit($this->asset, ['status' => 'vacant']);

    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['status' => 'maintenance'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($unit->fresh()->status)->toBe('maintenance');

        Livewire::test(EditUnit::class, ['record' => $unit->fresh()->getRouteKey()])
            ->fillForm(['status' => 'vacant'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($unit->fresh()->status)->toBe('vacant');
});

it('shows an occupied unit its real state, disabled, and does not blank it on save', function () {
    // The lockout half. Narrowing the options without this would leave a unit the projector has
    // put into `occupied` unable to be labelled — so Filament would refuse EVERY save of that
    // record, on a field nobody touched. Disabled means it renders the truth and is not dehydrated,
    // so an unrelated edit still saves and the column is left to the projector.
    // A GENUINELY occupied unit — one an active lease holds. Storing `occupied` on a unit with no
    // lease would be self-healed to `vacant` by the projector, correctly, and the test would then
    // be asserting against a state the system does not believe in.
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    makeLease($unit, makeTenant(), ['status' => 'active']);
    $unit->refresh()->recomputeStatus();
    expect($unit->fresh()->status)->toBe('occupied');
    $unit->refresh();

    asTenant($this->asset, function () use ($unit) {
        $field = Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->instance()
            ->form
            ->getFlatFields()['status'];

        expect($field->isDisabled())->toBeTrue()
            ->and(array_keys($field->getOptions()))->toBe(['occupied']);

        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['description' => 'Corner shop, mall entrance'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($unit->fresh()->status)->toBe('occupied')
        ->and($unit->fresh()->description)->toBe('Corner shop, mall entrance');
});

it('applies the same rule to a rentable item', function () {
    // The second door. `assigned` is the projector's answer there and the form offered it.
    $item = RentableItem::create([
        'asset_id' => $this->asset->id,
        'code' => 'P-001',
        'name' => 'Bay P-001',
        'type' => 'parking',
        'status' => RentableItem::STATUS_AVAILABLE,
        'monthly_rate' => 500,
    ]);

    asTenant($this->asset, function () use ($item) {
        $options = Livewire::test(EditRentableItem::class, ['record' => $item->getRouteKey()])
            ->instance()
            ->form
            ->getFlatFields()['status']
            ->getOptions();

        expect(array_keys($options))->toBe(['available', 'out_of_service'])
            ->and($options)->not->toHaveKey('assigned');
    });
});

it('registers the declarable set for every projection it governs', function () {
    // The registry is the single statement of which values are a person's; a projection added
    // without one would silently fall back to an empty list and offer nothing at all.
    foreach (ProjectedState::PROJECTIONS as $key => $projection) {
        expect($projection['declarable'])->toBeArray()->not->toBeEmpty("projection {$key}");
    }

    expect(ProjectedState::isProjected(Unit::class, 'occupied'))->toBeTrue()
        ->and(ProjectedState::isProjected(Unit::class, 'reserved'))->toBeTrue()
        ->and(ProjectedState::isProjected(Unit::class, 'vacant'))->toBeFalse()
        ->and(ProjectedState::isProjected(Unit::class, 'maintenance'))->toBeFalse()
        // A record with no status yet (a create form) is not "projected" — it is unset.
        ->and(ProjectedState::isProjected(Unit::class, null))->toBeFalse();
});
