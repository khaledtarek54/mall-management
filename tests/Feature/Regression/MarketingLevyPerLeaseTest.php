<?php

use App\Models\Charge;
use App\Services\LeaseRenewalService;
use App\Services\MarketingLevyService;

/**
 * The marketing levy is now optional per lease + rate-overridable (operator request 2026-07-19),
 * defaulting to preserve today's behaviour (on, at the global rate). createLevyCharge() is the one
 * place that syncs the `marketing` charge; it respects has_marketing_levy + marketing_levy_rate.
 */
function levyCharge($lease): ?Charge
{
    return Charge::where('lease_id', $lease->id)->where('type', 'marketing')->first();
}

it('bills the levy by default at the global rate (5%)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['base_rent_monthly' => 50000]); // has_marketing_levy defaults true
    app(MarketingLevyService::class)->createLevyCharge($lease);

    $charge = levyCharge($lease);
    expect($charge->is_active)->toBeTrue()
        ->and((float) $charge->amount)->toBe(2500.0); // 5% of 50,000
});

it('does not bill the levy when the lease opts out', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['base_rent_monthly' => 50000, 'has_marketing_levy' => false]);

    expect(app(MarketingLevyService::class)->createLevyCharge($lease))->toBeNull()
        ->and(Charge::where('lease_id', $lease->id)->where('type', 'marketing')->where('is_active', true)->exists())->toBeFalse();
});

it('uses the per-lease rate override', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'base_rent_monthly' => 50000, 'has_marketing_levy' => true, 'marketing_levy_rate' => 3,
    ]);
    app(MarketingLevyService::class)->createLevyCharge($lease);

    expect((float) levyCharge($lease)->amount)->toBe(1500.0); // 3% of 50,000, not the 5% default
});

it('deactivates the levy charge when a lease turns it off after having it', function () {
    $svc = app(MarketingLevyService::class);
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['base_rent_monthly' => 50000]);
    $svc->createLevyCharge($lease); // active
    expect(levyCharge($lease)->is_active)->toBeTrue();

    $lease->update(['has_marketing_levy' => false]);
    $svc->createLevyCharge($lease->fresh()); // opt out on edit → deactivate, don't delete

    expect(levyCharge($lease->fresh())->is_active)->toBeFalse(); // charge kept (history), just inactive
});

it('carries the opt-out and rate override forward on renewal', function () {
    // An opted-out lease with a negotiated rate — the terms must survive the renewal, else the
    // model default (levy on, global rate) would silently re-levy the tenant.
    // ->fresh() so DB defaults (escalation_rate, security_deposit, …) are populated before the
    // renewal copies them — in production renew() always receives a DB-loaded lease.
    $optedOut = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'base_rent_monthly' => 40000, 'has_marketing_levy' => false,
        'expiry_date' => now()->addMonth()->toDateString(),
    ])->fresh();
    $renewalA = app(LeaseRenewalService::class)->renew($optedOut, ['new_term_months' => 12, 'new_rent' => 40000]);
    expect($renewalA->has_marketing_levy)->toBeFalse()
        ->and(Charge::where('lease_id', $renewalA->id)->where('type', 'marketing')->where('is_active', true)->exists())->toBeFalse();

    $overridden = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'base_rent_monthly' => 40000, 'has_marketing_levy' => true, 'marketing_levy_rate' => 2,
        'expiry_date' => now()->addMonth()->toDateString(),
    ])->fresh();
    app(MarketingLevyService::class)->createLevyCharge($overridden);
    $renewalB = app(LeaseRenewalService::class)->renew($overridden, ['new_term_months' => 12, 'new_rent' => 40000]);
    expect((float) $renewalB->marketing_levy_rate)->toBe(2.0)
        ->and((float) levyCharge($renewalB)->amount)->toBe(800.0); // 2% of 40,000, not the 5% default
});
