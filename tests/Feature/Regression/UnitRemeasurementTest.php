<?php

use App\Models\Unit;
use App\Services\RemeasureUnitService;
use Carbon\CarbonImmutable;

/**
 * Remeasuring a shop must not rewrite what it was billed on — the real remainder of gap row 47.
 *
 * The row claimed area was "static, used only by CAM". Both halves were wrong: a LEASE's area was
 * already date-ranged and time-weighted through the `lease_unit` pivot, and CAM, the rent roll,
 * reports and rate-priced rent all read it. What was genuinely missing is narrower — a unit's OWN
 * measurement was a single column, so a re-survey moved every past period computed from it. Last
 * year's CAM reconciliation, re-run today, would apportion the pool on this year's number, and a
 * tenant's share of a year they had already been billed for would change.
 *
 * The register mirrors the charge schedule: dated rows are the truth, `units.area_sqm` is the
 * current headline, and `RemeasureUnitService` is the only thing that moves both.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('backfills every unit with an open row, changing nothing', function () {
    // The migration must be a no-op by construction: `areaOn(any date)` returns exactly what
    // `area_sqm` returned before, or the deploy itself moves historical figures.
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    expect((float) $unit->areaOn(CarbonImmutable::parse('2020-01-01')))->toBe(300.0)
        ->and((float) $unit->areaOn())->toBe(300.0);
});

it('keeps the old area true for the period it was true for', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    app(RemeasureUnitService::class)->record($unit, 320, [
        'effective_from' => '2026-06-01',
        'reason' => 'Re-survey after the demise wall moved',
    ]);

    $unit->refresh()->load('areas');

    // Before the remeasurement the shop was 300 — and a reconciliation of that period must still
    // say so, however many times it is re-run.
    expect((float) $unit->areaOn(CarbonImmutable::parse('2026-05-31')))->toBe(300.0)
        // From the effective date, 320.
        ->and((float) $unit->areaOn(CarbonImmutable::parse('2026-06-01')))->toBe(320.0)
        ->and((float) $unit->areaOn(CarbonImmutable::parse('2026-12-31')))->toBe(320.0)
        // …and the headline column follows, because the change is in force today.
        ->and((float) $unit->area_sqm)->toBe(320.0);
});

it('does not move a lease’s historical area when its unit is remeasured', function () {
    // The consequence that matters: this figure is what CAM apportions on.
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    $unit = makeUnit($asset, ['area_sqm' => 300]);
    $lease = makeLease($unit, null, ['status' => 'active', 'commencement_date' => '2026-01-01'])->fresh();

    $before = $lease->totalAreaSqmOn(CarbonImmutable::parse('2026-03-31'));

    app(RemeasureUnitService::class)->record($unit, 500, ['effective_from' => '2026-06-01']);

    expect((float) $lease->fresh()->totalAreaSqmOn(CarbonImmutable::parse('2026-03-31')))
        ->toBe((float) $before)
        ->toBe(300.0)
        // …while a period after the change sees the new measurement.
        ->and((float) $lease->fresh()->totalAreaSqmOn(CarbonImmutable::parse('2026-07-31')))->toBe(500.0);
});

it('leaves the headline alone for a remeasurement dated in the future', function () {
    // A survey agreed now but effective next quarter must not make the unit read as something it
    // does not yet measure.
    CarbonImmutable::setTestNow('2026-06-15');
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    app(RemeasureUnitService::class)->record($unit, 400, ['effective_from' => '2026-09-01']);

    expect((float) $unit->fresh()->area_sqm)->toBe(300.0)
        ->and((float) $unit->fresh()->areaOn(CarbonImmutable::parse('2026-08-31')))->toBe(300.0)
        ->and((float) $unit->fresh()->areaOn(CarbonImmutable::parse('2026-09-01')))->toBe(400.0);
});

it('refuses a measurement that would overlap the row it closes', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    app(RemeasureUnitService::class)->record($unit, 320, ['effective_from' => '2026-06-01']);

    // Dating a second change at or before the row it would close leaves two measurements claiming
    // the same day, and `areaOn()` would have to pick one arbitrarily.
    expect(fn () => app(RemeasureUnitService::class)->record($unit->fresh(), 350, ['effective_from' => '2026-06-01']))
        ->toThrow(DomainException::class);
});

it('records nothing when the measurement has not changed', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    app(RemeasureUnitService::class)->record($unit, 300, ['effective_from' => '2026-06-01']);

    // A second identical row would put two answers on the same day for no reason.
    expect($unit->fresh()->areas()->count())->toBe(1);
});

it('refuses a nonsensical area', function () {
    $unit = makeUnit(makeAsset(), ['area_sqm' => 300]);

    expect(fn () => app(RemeasureUnitService::class)->record($unit, 0))
        ->toThrow(DomainException::class);
});
