<?php

/*
|--------------------------------------------------------------------------
| A CPI clause could be recorded and never applied (2026-08-19)
|--------------------------------------------------------------------------
| `escalation_type = 'cpi'` has existed since 2024 and the sweep deliberately SKIPPED it, because
| there is no machine-readable Egyptian CPI feed and inventing an index number is inventing data —
| a rent step is money a tenant pays. That refusal was right; what was missing was somewhere for the
| real number to live.
|
| **The benchmark specifies this exactly** (`docs/benchmarks/yardi/01-yardi-lease-administration.md`
| §4): an index-method escalation carries an **index source**, a **publication lag** and a **base
| index value**, plus the floor and ceiling Atriom already had. Scenario **S4** adds the Egyptian
| verdict, and it is the reason the collar is load-bearing rather than decorative:
|
| > "In Egypt, where CPI has run 20–35%, a collar is not optional — an uncollared CPI clause is a
| > clause no tenant signs. Any CPI work must ship the collar with it, or it is worse than nothing."
|
| So the register records what was published; the sweep resolves a percentage from it; and the
| existing collar clamps that percentage before a single pound moves. Below, in that order.
*/

use App\Models\Lease;
use App\Models\RentIndex;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

function publishIndex(string $period, float $value, string $code = 'EGY_CPI'): RentIndex
{
    return RentIndex::create([
        'code' => $code,
        'period' => $period,
        'value' => $value,
        'published_on' => CarbonImmutable::parse($period)->addMonth()->day(10)->toDateString(),
    ]);
}

/**
 * S4's lease: escalates on 1 January against the **September** index.
 *
 * The lag is FOUR months, not three — January minus four lands on September. Worth stating because
 * the first draft of this test said three, read October, found nothing published and skipped; the
 * code was right and the fixture was wrong, which is the more common way round.
 */
function cpiLease($ctx, array $attrs = []): Lease
{
    return makeLease(makeUnit($ctx->asset), null, array_merge([
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
        'escalation_type' => 'cpi',
        'escalation_index_code' => 'EGY_CPI',
        'escalation_index_base_value' => 100,
        'escalation_index_lag_months' => 4,
        'next_escalation_date' => '2027-01-01',
    ], $attrs));
}

it('escalates by the published index movement', function () {
    $lease = cpiLease($this);

    // September 2026 = 112 against a base of 100 → 12%. September because the step is on
    // 1 January and the clause reads four months back.
    publishIndex('2026-09-01', 112);

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(112000.0);
});

/**
 * S4, with the numbers the benchmark uses: floor 3%, ceiling 8%. Egyptian CPI at 12% is exactly the
 * case that makes the ceiling the operative term — without it the tenant pays a step they never
 * agreed to.
 */
it('clamps a runaway index to the contractual ceiling', function () {
    $lease = cpiLease($this, ['escalation_ceiling_rate' => 8, 'escalation_floor_rate' => 3]);

    publishIndex('2026-09-01', 112); // 12% raw

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(108000.0);
});

/** The other half of the collar: an index that barely moved still pays the contracted minimum. */
it('lifts a flat index to the contractual floor', function () {
    $lease = cpiLease($this, ['escalation_ceiling_rate' => 8, 'escalation_floor_rate' => 3]);

    publishIndex('2026-09-01', 100.5); // 0.5% raw

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(103000.0);
});

/**
 * **The refusal that matters.** Voyager generates the escalation row *when the index publishes*.
 * Until the figure exists there is nothing to apply, and the answer is to wait — never to guess,
 * never to fall back to the stated `escalation_rate`, and never to treat a missing figure as zero
 * and roll the anniversary past it.
 */
it('waits rather than inventing a number when the index has not published', function () {
    $lease = cpiLease($this);

    // Nothing published for September 2026.
    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0)
        // And critically the anniversary has NOT rolled: a skipped step must still be due, or the
        // year the statistic was late is a year the tenant never pays for.
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-01-01');
});

/** The sweep runs daily, so the step lands the day the figure does. */
it('applies the step on the run after the index is published', function () {
    $lease = cpiLease($this);
    $service = app(RentEscalationService::class);

    $service->runForToday(CarbonImmutable::parse('2027-01-01'));
    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0);

    publishIndex('2026-09-01', 110);
    $service->runForToday(CarbonImmutable::parse('2027-01-15'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(110000.0);
});

/**
 * The base rolls forward, so the second year measures year-on-year rather than
 * cumulative-since-commencement. Voyager offers both readings; this codebase already resolves
 * compounding one way — a percentage step multiplies the current rent — and two opposite
 * conventions under one word is how an escalation type comes to mean something nobody agreed.
 */
it('measures the next step from the last index, not from commencement', function () {
    $lease = cpiLease($this);
    $service = app(RentEscalationService::class);

    publishIndex('2026-09-01', 110);
    $service->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(110000.0)
        // The base moved to the figure this step used.
        ->and((float) $lease->fresh()->escalation_index_base_value)->toBe(110.0);

    // 121 against 110 is another 10% — not 21% against the original 100.
    publishIndex('2027-09-01', 121);
    $service->runForToday(CarbonImmutable::parse('2028-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(121000.0);
});

/** An incomplete clause is not a licence to guess — no index named means no step. */
it('skips a cpi lease that names no index', function () {
    $lease = cpiLease($this, ['escalation_index_code' => null]);
    publishIndex('2026-09-01', 112);

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0);
});

/** A zero or missing base cannot be divided into — an infinite step is not better than none. */
it('skips a cpi lease with no base index value', function () {
    $lease = cpiLease($this, ['escalation_index_base_value' => null]);
    publishIndex('2026-09-01', 112);

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0);
});

/**
 * The publication lag is what makes the clause expressible at all. With no lag this lease reads
 * January's own index — which on 1 January has certainly not been published — so a lease that
 * states three months and one that states none must resolve different figures.
 */
it('reads the index the lag points at, not the anniversary month', function () {
    $lease = cpiLease($this, ['escalation_index_lag_months' => 0]);

    publishIndex('2026-09-01', 112);   // what a 3-month lag would have read
    publishIndex('2027-01-01', 105);   // the anniversary month itself

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(105000.0);
});

/** A stated-percentage lease is untouched by any of this — the two methods stay separate. */
it('leaves a fixed-percent lease alone', function () {
    $lease = makeLease(makeUnit($this->asset), null, [
        'base_rent_monthly' => 100000,
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2027-01-01',
        'expiry_date' => '2030-12-31',
    ]);

    publishIndex('2026-09-01', 999); // irrelevant to a stated clause

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-01'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0);
});

/** The register answers "not published yet" as null, never as zero. */
it('reports an unpublished period as null rather than zero', function () {
    publishIndex('2026-09-01', 112);

    expect(RentIndex::valueFor('EGY_CPI', CarbonImmutable::parse('2026-09-01')))->toBe(112.0)
        ->and(RentIndex::valueFor('EGY_CPI', CarbonImmutable::parse('2026-10-01')))->toBeNull()
        ->and(RentIndex::valueFor('OTHER', CarbonImmutable::parse('2026-09-01')))->toBeNull();
});
