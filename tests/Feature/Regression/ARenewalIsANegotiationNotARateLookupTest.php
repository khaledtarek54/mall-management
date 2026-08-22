<?php

use App\Models\Lease;
use App\Services\LeaseRenewalService;
use Carbon\CarbonImmutable;

/**
 * EG-39 — a rate-priced lease renewed at a negotiated rent kept the OLD rate's figure, silently.
 *
 * `Lease::saving()` re-derives `base_rent_monthly` from rate × area on CREATE — and a renewal IS a
 * create — on the stated rule that *"a typed monthly figure cannot outrank the rate the deal was
 * struck at"*. That rule is right at origination and wrong at renewal: renewing a 250 m² unit let
 * at 4,800/m²/yr for a negotiated 110,000 saved **100,000**, with nothing on screen to say the
 * figure had been replaced.
 *
 * **The deal wins and the rate follows it** (the operator's call, 2026-08-22). A renewal is a
 * re-negotiation, so the rate is re-derived from the agreed rent and both columns stay true —
 * which also keeps the rate meaningful for the escalations that run off it.
 *
 * Found by EG-37, and how it hid is the lesson: the only rate-priced renewal fixture in the suite
 * used `rent_pricing_basis => 'rate_per_sqm'` — a value no form offers and the code never matches —
 * so that lease was treated as FLAT and the case passed.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function expiringRatePricedLease(float $areaSqm, float $ratePerSqmYear): Lease
{
    $unit = makeUnit(makeAsset(), ['area_sqm' => $areaSqm]);

    return makeLease($unit, null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => $ratePerSqmYear,
    ]);
}

it('saves the rent that was actually negotiated', function () {
    CarbonImmutable::setTestNow('2026-12-01');

    // 250 m² at 4,800/m²/yr = 100,000/month. The deal was struck at 110,000.
    $lease = expiringRatePricedLease(250, 4800);

    expect((float) $lease->base_rent_monthly)->toBe(100000.0);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 110000,
    ]);

    expect((float) $renewal->base_rent_monthly)->toBe(110000.0);
});

it('re-rates so both columns stay true', function () {
    CarbonImmutable::setTestNow('2026-12-01');

    $lease = expiringRatePricedLease(250, 4800);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 110000,
    ]);

    // 110,000 × 12 ÷ 250 — the rate the negotiated rent implies, so an escalation running off the
    // rate steps from the deal that was struck rather than from last year's.
    expect((float) $renewal->base_rent_rate_per_sqm_year)->toBe(5280.0)
        ->and($renewal->rent_pricing_basis)->toBe(Lease::RENT_RATE);
});

it('keeps the agreed figure exact when the rate cannot round back to it', function () {
    CarbonImmutable::setTestNow('2026-12-01');

    // An awkward area: the implied rate rounds to 2dp and rate × area ÷ 12 no longer lands on the
    // typed rent. The operator must see the number they negotiated, not one a rounding produced.
    $lease = expiringRatePricedLease(233.7, 4800);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 97531.11,
    ]);

    expect((float) $renewal->base_rent_monthly)->toBe(97531.11);
});

it('leaves a flat-priced renewal exactly as it was', function () {
    // The control. Most leases are flat, and this change must not touch them.
    CarbonImmutable::setTestNow('2026-12-01');

    $unit = makeUnit(makeAsset(), ['area_sqm' => 250]);
    $lease = makeLease($unit, null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'base_rent_monthly' => 100000,
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, [
        'new_term_months' => 12,
        'new_rent' => 110000,
    ]);

    expect((float) $renewal->base_rent_monthly)->toBe(110000.0)
        ->and($renewal->base_rent_rate_per_sqm_year)->toBeNull();
});

it('still lets the rate rule win at ORIGINATION', function () {
    // The half that was right and must stay right: on a NEW lease the rate the deal was struck at
    // outranks a typed monthly figure. Only a renewal is a re-negotiation.
    $unit = makeUnit(makeAsset(), ['area_sqm' => 250]);

    $lease = makeLease($unit, null, [
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 4800,
        'base_rent_monthly' => 999999,
    ]);

    expect((float) $lease->base_rent_monthly)->toBe(100000.0);
});
