<?php

use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Support\RowActionPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **An act that moved to the record page still does everything it did on the row.**
 *
 * Moving a verb off a list is four separate ways to break it and only one of them is visible:
 *
 *  1. The act is no longer reachable at all — the page 403s for the role that holds it. Four
 *     resources fail exactly this and deliberately kept their verbs on the row; see
 *     {@see RowActionPolicy}.
 *  2. The act renders but its closure never runs, so the operator gets a toast and no change.
 *  3. It runs, and the FORM underneath goes on showing the old value — `refreshFormData()` refills
 *     from the page's in-memory copy, and every service here re-reads its subject under a lock into
 *     a NEW instance, so the refill is a no-op that reads as a fix. That is why the receiving page
 *     takes `RefreshesRecordState` and declares `derivedStatePaths()`.
 *  4. It is still on the list as well, so there are two definitions to keep in step.
 *
 * Each act moved gets all four assertions here. Building the action in a test proves none of them —
 * a Filament action builds its schema on MOUNT, so a page renders perfectly and fatals on the click.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('a unit re-measurement', function () {
    beforeEach(function () {
        $this->unit = makeUnit($this->asset, ['code' => 'A-101', 'area_sqm' => 300]);
        $this->actingAs(makeUser('leasing', [$this->asset->id]));
        Filament::setTenant($this->asset);
    });

    afterEach(fn () => Filament::setTenant(null, isQuiet: true));

    it('runs from the unit’s own page and moves the area', function () {
        Livewire::test(EditUnit::class, ['record' => $this->unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: [
                'area_sqm' => 355.5,
                'effective_from' => now()->toDateString(),
                'reason' => 'Post-fit-out survey',
            ])
            ->assertHasNoActionErrors();

        expect((float) $this->unit->fresh()->area_sqm)->toBe(355.5)
            // The versioned row is the point of the act — a bare column write bypasses it.
            ->and($this->unit->fresh()->areas()->count())->toBeGreaterThan(0);
    });

    it('shows the new area on the form it just changed', function () {
        // The failure this catches is silent: the row moves, the toast says it worked, and the
        // operator goes on reading 300 in the field a few centimetres below the button.
        Livewire::test(EditUnit::class, ['record' => $this->unit->getRouteKey()])
            ->assertFormSet(['area_sqm' => 300])
            ->callAction(TestAction::make('remeasure'), data: [
                'area_sqm' => 412,
                'effective_from' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors()
            // The ARRAY form, never a closure. `assertFormSet(fn ($state) => …)` ignores what the
            // closure returns: this exact assertion passed against a form still reading 300, which
            // is how the staleness survived being "tested".
            ->assertFormSet(['area_sqm' => 412]);
    });

    it('is no longer offered on the list', function () {
        // Two surfaces means two definitions, and the one nobody is looking at goes stale. The
        // list keeps View and Edit; it does not keep the act.
        Livewire::test(ListUnits::class)
            ->assertTableActionDoesNotExist('remeasure');
    });
});

it('refuses the re-measurement to a role that cannot edit the unit', function () {
    $unit = makeUnit($this->asset, ['code' => 'B-201', 'area_sqm' => 120]);

    // A viewer holds units.view and not units.edit, so the record page is refused outright —
    // the outermost of the four layers, and the one that made four other resources un-movable.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])->assertForbidden();

    expect((float) $unit->fresh()->area_sqm)->toBe(120.0);

    Filament::setTenant(null, isQuiet: true);
});
