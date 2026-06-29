<?php

use App\Models\Charge;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Regression (HIGH / money): mid-month proration used Carbon 3's SIGNED +
 * fractional diffInDays with a `+1`, which undercharged every mid-month move-in
 * and billed ZERO for a last-day commencement. The factor is now sign-safe +
 * day-granular.
 */
function prorationInvoice(string $commence, float $rent = 30000)
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => $commence, 'expiry_date' => '2027-12-31',
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => $rent, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => $commence, 'is_active' => true,
    ]);
    $period = CarbonImmutable::parse($commence)->startOfMonth();

    return app(MonthlyBillingService::class)->generateForLease($lease, $period, prorate: true)['invoice'];
}

it('prorates mid-month exactly (June 16 → 15/30 = half)', function () {
    // 30000 * (15/30) = 15000.00.
    expect((float) prorationInvoice('2026-06-16')->subtotal)->toBe(15000.00);
});

it('prorates a last-day commencement to one day, not zero (June 30 → 1/30)', function () {
    // 30000 * (1/30) = 1000.00 — must NOT bill 0.
    expect((float) prorationInvoice('2026-06-30')->subtotal)->toBe(1000.00);
});

it('prorates a 31-day month correctly (March 15 → 17/31)', function () {
    // 31000 * (17/31) = 17000.00.
    expect((float) prorationInvoice('2026-03-15', 31000)->subtotal)->toBe(17000.00);
});
