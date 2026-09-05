<?php

use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Services\RemeasureUnitService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A re-measurement has to measure something different.
 *
 * Reported by the tester: the Remeasure modal accepted the area the unit already has. The operator
 * was then shown "A-01 re-measured to 1,000.00 m²" while nothing had been recorded —
 * `RemeasureUnitService` returns the row already in force rather than opening a second one saying
 * the same thing on the same day.
 *
 * **The service's silence is correct and is not what changed.** It is a deliberate, separately
 * tested idempotency (`UnitRemeasurementTest` — "a second identical row would put two answers on the
 * same day for no reason"), and it is what makes a retry or a re-run safe. What was wrong is that
 * the SCREEN reported a change that had not happened, so the refusal belongs on the modal: still
 * idempotent for a caller, and a clear answer for a person.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['total_area_sqm' => 12000, 'leasable_area_sqm' => 10000]);
});

it('refuses a re-measurement to the area the unit already has', function () {
    $unit = makeUnit($this->asset, ['area_sqm' => 1000]);
    // A unit is born with its opening measurement, so the count to hold steady is that one.
    $before = $unit->areas()->count();

    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: [
                'area_sqm' => 1000,
                'effective_from' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['area_sqm']);
    });

    expect($unit->fresh()->areas()->count())->toBe($before);
});

it('still records a genuine re-measurement', function () {
    // The control. The refusal above passes just as happily on a modal that refuses everything.
    $unit = makeUnit($this->asset, ['area_sqm' => 1000]);

    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: [
                'area_sqm' => 1120,
                'effective_from' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    expect((float) $unit->fresh()->area_sqm)->toBe(1120.0);
});

it('allows the same number when a DIFFERENT area was in force on that date', function () {
    // The over-lock control, and the reason the comparison is dated rather than against the current
    // column. A unit re-measured 300 → 400 can legitimately be corrected back to 300 as of a date
    // when 300 was not what the register said — that is a real correction, not a no-op.
    $unit = makeUnit($this->asset, ['area_sqm' => 300]);
    app(RemeasureUnitService::class)->record($unit, 400, ['effective_from' => now()->subMonths(2)->toDateString()]);

    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->fresh()->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: [
                'area_sqm' => 300,
                'effective_from' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();
    });

    expect((float) $unit->fresh()->area_sqm)->toBe(300.0);
});

it('leaves the service itself idempotent', function () {
    // The property the modal refusal must NOT have changed: a programmatic re-run stays safe and
    // silent, because that is what makes a retry harmless.
    $unit = makeUnit($this->asset, ['area_sqm' => 300]);
    $before = $unit->areas()->count();

    app(RemeasureUnitService::class)->record($unit, 300, ['effective_from' => now()->toDateString()]);

    expect($unit->fresh()->areas()->count())->toBe($before)
        ->and((float) $unit->fresh()->area_sqm)->toBe(300.0);
});
