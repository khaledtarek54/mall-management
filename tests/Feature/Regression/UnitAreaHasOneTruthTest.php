<?php

use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Models\UnitArea;
use App\Services\RemeasureUnitService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A unit's measured area has ONE truth: the dated `unit_areas` rows. `units.area_sqm` is the
 * denormalised CURRENT measurement and may only move to what those rows already say.
 *
 * THE BUG (validation sweep — spacing, 2026-08-11). Area versioning shipped so that remeasuring a
 * shop stops rewriting what was already billed: `Unit::areaOn()` answers from the row in force on a
 * date, and `RemeasureUnitService` closes one row and opens the next under a lock. Its docblock
 * states the invariant plainly — *"this service is the only thing that may move it"*.
 *
 * Nothing enforced that. `UnitForm` kept a plain editable `area_sqm` TextInput on **Edit**, so an
 * operator changing the area there moved the column and wrote **no `UnitArea` row at all** (the
 * opening row is written by a `created` hook, which does not fire on update). The result is the
 * two-truths state the feature exists to prevent, and it splits along the worst possible line:
 *
 *   - CAM apportions on `areaOn()` — it keeps the OLD area, so recoveries are unchanged;
 *   - every current-state surface reads `area_sqm` — the unit register, the lease's area, the
 *     `/api/v1` lease payload, the reports — and shows the NEW one.
 *
 * So the operator sees the change take effect everywhere they look while the money quietly ignores
 * it. That is strictly worse than either answer alone: a wrong number is found, a number that is
 * right in one place and wrong in another is argued about.
 *
 * The fix mirrors what the same problem already taught this codebase about rent
 * (`leases.base_rent_monthly` ← `LeaseRentChangeService`, with the form's rent fields read-only on
 * Edit): the column is read-only on Edit and the operator uses the Remeasure action, with a model
 * guard behind it so an import or the console cannot drift it either.
 *
 * THE GUARD IS NOT A FLAG. `RemeasureUnitService` writes the dated row BEFORE it touches the
 * column, so at the moment of that write the rows already agree with the new value — the model can
 * simply ask whether they do. A legacy unit with no rows keeps the existing lenient fallback, which
 * is what lets pre-versioning data behave exactly as it did.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'AREA1']);
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);
});

it('refuses a direct area edit that no dated row supports', function () {
    expect(fn () => $this->unit->update(['area_sqm' => 250]))
        ->toThrow(DomainException::class);

    // Neither truth moved — the column and the dated row still agree on 100.
    $this->unit->refresh();
    expect(round((float) $this->unit->area_sqm, 2))->toBe(100.00)
        ->and(round($this->unit->areaOn(), 2))->toBe(100.00);
});

it('lets the remeasure service move the column, because it moves the rows first', function () {
    // The control: the guard must not block the one path that is supposed to work.
    app(RemeasureUnitService::class)->record($this->unit, 250, [
        'effective_from' => CarbonImmutable::now()->toDateString(),
        'reason' => 'Post-fit-out re-survey',
    ]);

    $this->unit->refresh();
    expect(round((float) $this->unit->area_sqm, 2))->toBe(250.00)
        ->and(round($this->unit->areaOn(), 2))->toBe(250.00)
        ->and(UnitArea::where('unit_id', $this->unit->id)->count())->toBe(2);
});

it('keeps history intact — yesterday still measures what it measured', function () {
    app(RemeasureUnitService::class)->record($this->unit, 250, [
        'effective_from' => CarbonImmutable::now()->toDateString(),
    ]);

    // The whole point of versioning: a period already billed does not move.
    expect(round($this->unit->fresh()->areaOn(CarbonImmutable::now()->subMonth()), 2))->toBe(100.00);
});

it('allows a save that leaves the area alone', function () {
    // The guard is on the COLUMN moving, not on saving the unit — renaming a shop must still work.
    expect(fn () => $this->unit->update(['description' => 'Corner unit, mall entrance']))
        ->not->toThrow(DomainException::class);
});

it('leaves a legacy unit with no dated rows alone', function () {
    // Pre-versioning rows (and factories that write only the column) must behave exactly as they
    // did — areaOn() falls back to the column, so there is no second truth to protect.
    $legacy = makeUnit($this->asset, ['area_sqm' => 80]);
    UnitArea::where('unit_id', $legacy->id)->delete();

    expect(fn () => $legacy->update(['area_sqm' => 95]))->not->toThrow(DomainException::class);
    expect(round((float) $legacy->fresh()->area_sqm, 2))->toBe(95.00);
});

it('refuses a negative area from any writer', function () {
    // The form carries minValue(0); the service refuses <= 0. Nothing stood behind either for an
    // import or the console, and a negative area would apportion CAM by a negative share.
    expect(fn () => makeUnit($this->asset, ['area_sqm' => -50]))
        ->toThrow(DomainException::class);
});

it('does not let the Edit screen move the area', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(EditUnit::class, ['record' => $this->unit->getRouteKey()])
        ->fillForm(['area_sqm' => 999])
        ->call('save');

    // Whether the field is refused or simply not submitted, the outcome is the one that matters.
    expect(round((float) $this->unit->fresh()->area_sqm, 2))->toBe(100.00);

    Filament::setTenant(null, isQuiet: true);
});

it('gives the operator a working path — the Remeasure action records a dated row', function () {
    // The other half of the fix, and the part that makes the guard safe to ship.
    // `RemeasureUnitService` shipped with the versioning feature and had NO caller anywhere in
    // app/ — no action, no controller, no command. The register existed and nothing could add to
    // it. Locking `area_sqm` without this would have left operators unable to record a re-survey
    // at all, which is a worse system than the one with the bug.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // The act moved to the unit's own page on 2026-08-30 — the list FINDS, the record ACTS.
    Livewire::test(EditUnit::class, ['record' => $this->unit->getRouteKey()])
        ->callAction('remeasure', data: [
            'area_sqm' => 320,
            'effective_from' => CarbonImmutable::now()->toDateString(),
            'reason' => 'Wall moved during fit-out',
        ]);

    $this->unit->refresh();
    expect(round((float) $this->unit->area_sqm, 2))->toBe(320.00)
        ->and(round($this->unit->areaOn(), 2))->toBe(320.00)
        ->and(round($this->unit->areaOn(CarbonImmutable::now()->subMonth()), 2))->toBe(100.00)
        ->and(UnitArea::where('unit_id', $this->unit->id)->whereNotNull('effective_to')->count())->toBe(1);

    Filament::setTenant(null, isQuiet: true);
});
