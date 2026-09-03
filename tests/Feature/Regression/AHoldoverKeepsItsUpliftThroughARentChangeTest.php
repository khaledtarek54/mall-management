<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseRentChangeService;
use Carbon\CarbonImmutable;

/**
 * **EG-40's third door.** A lease in holdover is priced at its uplift, and every derivation from the
 * rate has to honour it — `base_rent_rate_per_sqm_year` stays CONTRACTUAL, because that is what a
 * rate means, and `holdover_rate_pct` is the premium on top.
 *
 * `LeaseSpaceChangeService` was taught that in August. `LeaseRentChangeService` was not: it did the
 * arithmetic inline and the word `holdover` appeared nowhere in the file. The model hook cannot
 * compensate — `Lease::saving` deliberately skips re-derivation when this service states a rent,
 * under a comment saying it "must not be second-guessed".
 *
 * Both directions were wrong, in opposite directions:
 *
 *   (A) RATE → RENT under-charges. The Change Rent modal PREFILLS the contractual rate and requires
 *       it on a rate-priced lease, so even a save that only meant to change the service charge
 *       re-states 4,800 and writes the contracted rent back. Measured on 250 m² at 4,800/m²/yr under
 *       a 150% holdover: **150,000 → 100,000**, a silent 50,000/month drop.
 *
 *   (B) RENT → RATE inflates the contract. `RentEscalationService` keeps holdovers in scope on
 *       purpose and passes the UPLIFTED rent, so the inverse wrote the premium into the contractual
 *       rate — 157,500 gave **7,560/m²/yr where 5,040 is contracted**, ×1.5 exactly, and every later
 *       rate→rent derivation compounds it.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function heldOverRateLease(): Lease
{
    $lease = makeLease(makeUnit(makeAsset(), ['area_sqm' => 250]), null, [
        'status' => 'active', 'start_date' => '2024-01-01',
        'expiry_date' => CarbonImmutable::now()->subMonths(2)->endOfMonth()->toDateString(),
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 4800,
        'base_rent_monthly' => 100000,
        'holdover_rate_pct' => 150,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 100000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => '2024-01-01', 'is_active' => true,
    ]);

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['reason' => 'Still trading.']);

    return $lease->fresh();
}

it('keeps the uplift when the same rate is re-stated', function () {
    CarbonImmutable::setTestNow('2026-09-02');
    $lease = heldOverRateLease();

    expect((float) $lease->base_rent_monthly)->toBe(150000.0);

    // The shape that makes this dangerous: the operator did not intend a rent change at all. The
    // modal prefills the contractual rate and requires it, so it comes back as typed.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_rate_per_sqm_year' => 4800,
        'effective_from' => CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
        'reason' => 'Service charge revision.',
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(150000.0);
});

it('prices a NEGOTIATED rate at the uplift too', function () {
    CarbonImmutable::setTestNow('2026-09-02');
    $lease = heldOverRateLease();

    // 5,000/m²/yr on 250 m² is 104,166.67 contracted; the holdover bills 150% of it.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_rate_per_sqm_year' => 5000,
        'effective_from' => CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
        'reason' => 'Renegotiated while holding over.',
    ]);

    $after = $lease->fresh();

    expect((float) $after->base_rent_monthly)->toBe(156250.01)
        // …and the RATE stays contractual, which is the whole of EG-40.
        ->and((float) $after->base_rent_rate_per_sqm_year)->toBe(5000.0);
});

it('does not write the premium into the contractual rate', function () {
    CarbonImmutable::setTestNow('2026-09-02');
    $lease = heldOverRateLease();

    // What `RentEscalationService` does: it steps the BILLING rent, 150,000 → 157,500.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 157500,
        'effective_from' => CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
        'reason' => 'Automatic rent escalation +5%.',
    ]);

    $after = $lease->fresh();

    expect((float) $after->base_rent_rate_per_sqm_year)->toBe(5040.0)
        ->and((float) $after->base_rent_monthly)->toBe(157500.0);
});

it('prices a change effective BEFORE the holdover at the contracted rate', function () {
    // The cutoff, and it runs both ways. `holdover_from` is the day the uplift starts; a rent change
    // effective before it belongs to the contracted period and must carry no premium — the same rule
    // `deriveBaseRentFromRate()` already applies, which is why the inverse has to apply it too or
    // the two disagree about the same lease on the same day.
    CarbonImmutable::setTestNow('2026-09-02');
    $lease = heldOverRateLease();

    expect($lease->holdover_from->toDateString())->toBe('2026-08-01');

    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 105000,
        'effective_from' => '2026-07-01',            // before the holdover began
        'reason' => 'Back-dated correction to the contracted term.',
    ]);

    // 105,000 × 12 ÷ 250 = 5,040 — the rent as stated, with nothing divided out of it.
    expect((float) $lease->fresh()->base_rent_rate_per_sqm_year)->toBe(5040.0);
});

it('leaves an ordinary lease exactly as it was', function () {
    // The control: no holdover, so neither direction may move. Every rate-priced lease on every
    // install goes through this path, and none of them should change.
    $lease = makeLease(makeUnit(makeAsset(), ['area_sqm' => 250]), null, [
        'status' => 'active', 'start_date' => '2024-01-01', 'expiry_date' => '2030-12-31',
        'rent_pricing_basis' => Lease::RENT_RATE,
        'base_rent_rate_per_sqm_year' => 4800, 'base_rent_monthly' => 100000,
    ]);

    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_rate_per_sqm_year' => 6000,
        'effective_from' => CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
        'reason' => 'Agreed increase.',
    ]);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(125000.0);

    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 130000,
        'effective_from' => CarbonImmutable::now()->addMonths(2)->startOfMonth()->toDateString(),
        'reason' => 'Agreed increase.',
    ]);

    expect((float) $lease->fresh()->base_rent_rate_per_sqm_year)->toBe(6240.0);
});

it('does not strip the premium off a NEGOTIATED renewal rent', function () {
    // The trap the fix had to avoid. `LeaseRenewalService` passes a rent the parties agreed for the
    // NEW term through `deriveRateFromBaseRent()`, and that figure is already contractual even when
    // the lease it renews is holding over. Teaching the SHARED helper about the premium would
    // divide it by 1.5 and understate every renewal rate struck off a holdover — EG-39's own defect
    // re-created one column along. That is why the inverse is a second method.
    CarbonImmutable::setTestNow('2026-09-02');
    $lease = heldOverRateLease();

    $renewed = app(LeaseRenewalService::class)->renew($lease, [
        'commencement_date' => CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
        'new_term_months' => 12,
        'new_rent' => 110000,
        'reason' => 'Renewed after holding over.',
    ]);

    // 110,000 × 12 ÷ 250 = 5,280 — the rate the deal implies, not 3,520.
    expect((float) $renewed->base_rent_rate_per_sqm_year)->toBe(5280.0);
});
