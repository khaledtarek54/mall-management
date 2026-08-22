<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseSpaceChangeService;
use Carbon\CarbonImmutable;

/**
 * EG-40 — a rate-priced lease in holdover lost its uplift the moment it took more space.
 *
 * `base_rent_rate_per_sqm_year` stays CONTRACTUAL, which is what a rate means, and
 * `holdover_rate_pct` is the premium recorded on top of it. That split is right: re-rating on
 * conversion would bake a temporary penalty into the contractual rate and lose what the parties
 * actually agreed.
 *
 * What was wrong is that every derivation FROM the rate ignored the premium.
 * `LeaseSpaceChangeService` re-derives when a rate-priced lease takes more space — that is the whole
 * point of holding a rate rather than an amount — so taking an extra unit mid-holdover silently
 * dropped the rent back to 100% of the contracted figure. A negotiated uplift, gone, with nothing on
 * screen to say so.
 *
 * Recorded as needing an operator's call and then re-read: the question is not *"should the rate
 * follow the premium"* (no — that is what `holdover_rate_pct` is for) but *"does the derivation
 * honour it"* (it must). That reframing is what made it a fix rather than a row.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/** The conversion holds over from the schedule, so a rate-priced lease still needs its rent row. */
function ratePricedHoldoverLease(float $areaSqm, float $rate): Lease
{
    $unit = makeUnit(makeAsset(['code' => 'MALL-'.uniqid()]), ['area_sqm' => $areaSqm]);

    $lease = makeLease($unit, null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => $rate,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $lease->base_rent_monthly, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('keeps the uplift when a lease in holdover takes more space', function () {
    CarbonImmutable::setTestNow('2027-01-15');

    $lease = ratePricedHoldoverLease(250, 4800);
    $asset = $lease->unit->asset;

    // 250 × 4,800 ÷ 12 = 100,000 contracted.
    expect((float) $lease->base_rent_monthly)->toBe(100000.0);

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150,
        'effective_from' => '2027-01-01',
        'reason' => 'Tenant is negotiating a renewal.',
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(150000.0);

    // Now take an extra 50 m². Contracted would be 300 × 4,800 ÷ 12 = 120,000; at the negotiated
    // 150% holdover that is 180,000. Before EG-40 this re-derived to 120,000 and the uplift
    // vanished.
    $extra = makeUnit($asset, ['area_sqm' => 50]);

    app(LeaseSpaceChangeService::class)->expand($lease->fresh(), [
        'unit_ids' => [$extra->id],
        'effective_from' => '2027-02-01',
        'reason' => 'Tenant took the adjoining shop.',
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(180000.0);
});

it('prices a date before the conversion at the contracted rate', function () {
    // The premium starts when the holdover does. A derivation for an earlier day is still the
    // contracted figure, the same way the area is read as it stood on that day.
    CarbonImmutable::setTestNow('2027-01-15');

    $lease = ratePricedHoldoverLease(250, 4800);

    app(ConvertLeaseToHoldoverService::class)->convert($lease, [
        'rate_pct' => 150,
        'effective_from' => '2027-01-01',
        'reason' => 'Tenant is negotiating a renewal.',
    ]);

    $lease = $lease->fresh();

    expect($lease->deriveBaseRentFromRate(CarbonImmutable::parse('2026-06-01')))->toBe(100000.0)
        ->and($lease->deriveBaseRentFromRate(CarbonImmutable::parse('2027-02-01')))->toBe(150000.0);
});

it('leaves a lease that never entered holdover alone', function () {
    // The control: the premium column carries onto a renewal even though the STATE does not, so a
    // lease with a pct and no `holdover_from` must price at its contracted rate.
    CarbonImmutable::setTestNow('2026-06-01');

    $unit = makeUnit(makeAsset(), ['area_sqm' => 250]);
    $lease = makeLease($unit, null, [
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 4800,
        'holdover_rate_pct' => 150,
    ]);

    expect((float) $lease->base_rent_monthly)->toBe(100000.0)
        ->and($lease->deriveBaseRentFromRate())->toBe(100000.0);
});
