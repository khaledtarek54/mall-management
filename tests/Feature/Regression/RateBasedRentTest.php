<?php

use App\Models\Asset;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRentChangeService;
use App\Services\LeaseSpaceChangeService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * Rent priced per m² per year (phase 8, story LS-04).
 *
 * **What was wrong.** Commercial rent is negotiated per square metre almost everywhere, and Atriom
 * stored only a flat monthly figure — `units.area_sqm` existed and priced nothing. Two consequences:
 * an operator could not compare two deals on the only basis that makes them comparable, and when the
 * let area changed the rent had to be recomputed by hand and retyped.
 *
 * **The rate lives on the LEASE; the schedule keeps storing amounts.** That split is deliberate and
 * is the phase-1 invariant: a schedule row records what was actually in force for its months, so it
 * must hold a number, not a formula that would re-answer differently later. The rate is the *term*
 * the number came from, and it is what re-derives the money when the premises move.
 *
 * **Flat stays the default**, at the column, so no lease written before this re-prices on deploy.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function ratePricedLease(Asset $asset, float $ratePerSqmYear, float $areaSqm): Lease
{
    $lease = makeLease(makeUnit($asset, ['code' => 'R-01', 'area_sqm' => $areaSqm]), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => $ratePerSqmYear,
    ])->fresh();

    // Seed the schedule the way the real create path does, so an expansion has a row to close.
    app(ChargeScheduleService::class)->setAmount(
        $lease,
        'base_rent',
        (float) $lease->base_rent_monthly,
        CarbonImmutable::parse($lease->commencement_date),
        ['name' => 'Base Rent', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0],
    );

    return $lease;
}

it('derives the monthly rent from the rate and the area', function () {
    // 120 m² at EGP 3,600/m²/yr = 432,000 a year = 36,000 a month.
    $lease = ratePricedLease(makeAsset(), 3600, 120);

    expect((float) $lease->base_rent_monthly)->toBe(36000.0);
});

it('ignores a monthly figure typed against a rate-priced lease', function () {
    // The derivation is enforced at the MODEL, not the form, so an import or a future screen cannot
    // leave a lease carrying a monthly amount that disagrees with its own rate and area.
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'R-02', 'area_sqm' => 100]), null, [
        'status' => 'active',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 1200,
        'base_rent_monthly' => 999999,   // a hand-typed lie
    ])->fresh();

    expect((float) $lease->base_rent_monthly)->toBe(10000.0);
});

it('leaves a flat lease alone', function () {
    // The default, and every lease written before LS-04. Area is recorded and prices nothing.
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'R-03', 'area_sqm' => 500]), null, [
        'status' => 'active',
        'base_rent_monthly' => 25000,
    ])->fresh();

    expect($lease->rent_pricing_basis)->toBe(Lease::RENT_FLAT)
        ->and((float) $lease->base_rent_monthly)->toBe(25000.0)
        ->and($lease->deriveBaseRentFromRate())->toBeNull();
});

it('re-prices itself when an expansion changes the let area', function () {
    // THE story. On the flat model the operator had to recompute 150 m² × 3,600 ÷ 12 by hand and
    // type it in; getting it wrong bills the wrong rent for the rest of the term.
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    $lease = ratePricedLease($asset, 3600, 120);
    $extra = makeUnit($asset, ['code' => 'R-01B', 'area_sqm' => 30]);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2026-07-01',
        'reason' => 'Took the adjacent unit.',
        // No rent stated at all — the rate is the term, so the money follows the space.
    ]);

    $lease->refresh();

    // 150 m² × 3,600 ÷ 12 = 45,000.
    expect((float) $lease->base_rent_monthly)->toBe(45000.0);

    // And the SCHEDULE carries both figures on their own dates — the old rent stays true for the
    // months it was true for, which is the whole point of phase 1.
    $rows = $lease->charges()->where('type', 'base_rent')->orderBy('start_date')->get();

    expect($rows)->toHaveCount(2)
        ->and((float) $rows->first()->amount)->toBe(36000.0)
        ->and($rows->first()->end_date->toDateString())->toBe('2026-06-30')
        ->and((float) $rows->last()->amount)->toBe(45000.0)
        ->and($rows->last()->start_date->toDateString())->toBe('2026-07-01');
});

it('re-prices downwards when space is given back', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    $lease = ratePricedLease($asset, 3600, 120);
    $extra = makeUnit($asset, ['code' => 'R-01B', 'area_sqm' => 30]);

    $service = app(LeaseSpaceChangeService::class);
    $service->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2026-07-01',
        'reason' => 'Expansion.',
    ]);
    $service->contract($lease->refresh(), [
        'unit_ids' => [$extra->id],
        'effective_from' => '2026-10-01',
        'reason' => 'Gave the annexe back.',
    ]);

    expect((float) $lease->refresh()->base_rent_monthly)->toBe(36000.0);
});

it('still honours a rent the parties actually negotiated', function () {
    // The derivation is a DEFAULT, not a cage. A landlord who agrees a blended rate for the enlarged
    // premises states the total, and that is what is billed — the rate stops being the authority the
    // moment the parties have agreed something else.
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    $lease = ratePricedLease($asset, 3600, 120);
    $extra = makeUnit($asset, ['code' => 'R-01B', 'area_sqm' => 30]);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2026-07-01',
        'new_total_rent' => 41000,
        'reason' => 'Blended rate for the enlarged premises.',
    ]);

    expect((float) $lease->refresh()->base_rent_monthly)->toBe(41000.0);
});

it('does not re-derive a flat lease when its area changes', function () {
    // The mirror. A flat lease was priced as a lump sum for reasons the system does not know, and
    // silently re-pricing it from an area that was never part of the deal would be a live bug.
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['code' => 'F-01', 'area_sqm' => 120]), null, [
        'status' => 'active',
        'base_rent_monthly' => 36000,
    ])->fresh();
    $extra = makeUnit($asset, ['code' => 'F-01B', 'area_sqm' => 30]);

    app(LeaseSpaceChangeService::class)->expand($lease, [
        'unit_ids' => [$extra->id],
        'effective_from' => '2026-07-01',
        'reason' => 'Space handed over rent-free during fit-out; the money follows later.',
    ]);

    expect((float) $lease->refresh()->base_rent_monthly)->toBe(36000.0);
});

it('refuses to price from an area of zero', function () {
    // A unit with no recorded area cannot price anything. Better to keep the typed figure than to
    // silently bill nothing.
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'R-04', 'area_sqm' => 0]), null, [
        'status' => 'active',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 3600,
        'base_rent_monthly' => 12000,
    ])->fresh();

    expect($lease->deriveBaseRentFromRate())->toBeNull()
        ->and((float) $lease->base_rent_monthly)->toBe(12000.0);
});

it('reports the contracted rate on the rent roll beside the effective one', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $asset = makeAsset();
    ratePricedLease($asset, 3600, 120);

    $row = app(ReportService::class)->rentRoll(CarbonImmutable::parse('2026-06-15'), $asset->id)->sole();

    // They agree on a lease with nothing sitting between the rate and the bill; a gap is the signal
    // that an abatement, a step or a hand edit has moved the effective figure.
    expect($row['contracted_rate_per_sqm_year'])->toBe(3600.0)
        ->and($row['rent_per_sqm_year'])->toBe(3600.0);
});

it('escalates the contracted rate along with the rent', function () {
    // Left alone, an escalation would raise the monthly figure and leave the lease advertising a
    // rate it no longer bills — and the rent roll's contracted-vs-effective comparison would show a
    // gap that means nothing. A 7% step raises both by 7%.
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = ratePricedLease(makeAsset(), 3600, 120);

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 38520,        // 36,000 + 7%
        'effective_from' => '2027-01-01',
        'reason' => 'Contracted annual escalation.',
    ]);

    expect((float) $lease->refresh()->base_rent_rate_per_sqm_year)->toBe(3852.0)   // 3,600 + 7%
        ->and((float) $lease->base_rent_monthly)->toBe(38520.0);
});

it('lets the operator re-rate a lease and derives the rent from the new rate', function () {
    // The other direction, and the one a leasing manager actually negotiates in: state the rate,
    // and the money follows.
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = ratePricedLease(makeAsset(), 3600, 120);

    // No amount at all — the shape the Change Rent modal actually sends for a rate-priced lease,
    // where the monthly field is hidden.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_rate_per_sqm_year' => 4200,
        'effective_from' => '2027-01-01',
        'reason' => 'Renegotiated at market.',
    ]);

    expect((float) $lease->refresh()->base_rent_monthly)->toBe(42000.0)
        ->and((float) $lease->base_rent_rate_per_sqm_year)->toBe(4200.0);
});

it('does not invent a rate for a flat lease when its rent changes', function () {
    CarbonImmutable::setTestNow('2026-06-15');
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'F-09', 'area_sqm' => 120]), null, [
        'status' => 'active',
        'base_rent_monthly' => 36000,
    ])->fresh();

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 38520,
        'reason' => 'Escalation.',
    ]);

    expect($lease->refresh()->base_rent_rate_per_sqm_year)->toBeNull();
});
