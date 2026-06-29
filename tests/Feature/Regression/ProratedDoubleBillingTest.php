<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Regression (HIGH / money): a prorated first-month invoice stores
 * period_start = the mid-month commencement, but the double-billing guard used
 * to match only an exact "= month start". So the scheduled monthly run billed
 * the same month a SECOND time (full), ~1.5× rent for one calendar month. The
 * guard is now period-OVERLAP-aware.
 */
it('does not double-bill the month after a prorated first-month invoice', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, [
        'commencement_date' => '2026-06-15', // mid-month → first invoice prorates
        'expiry_date' => '2027-12-31',
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-06-15',
        'is_active' => true,
    ]);

    $june = CarbonImmutable::parse('2026-06-01');
    $svc = app(MonthlyBillingService::class);

    // 1) Admin prorates the first-month invoice (period_start = 2026-06-15).
    expect($svc->generateForLease($lease, $june, prorate: true)['status'])->toBe('created');

    // 2) The scheduled monthly run for June must NOT bill it again.
    $stats = $svc->runForPeriod($june);

    $juneInvoices = Invoice::where('lease_id', $lease->id)
        ->whereDate('period_start', '>=', '2026-06-01')
        ->whereDate('period_start', '<=', '2026-06-30')
        ->count();

    expect($juneInvoices)->toBe(1)                       // exactly ONE June invoice
        ->and($stats['skipped'])->toBeGreaterThanOrEqual(1);
});

it('also blocks a second generate for the same month with prorate off', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, [
        'commencement_date' => '2026-06-15', 'expiry_date' => '2027-12-31',
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-06-15',
        'is_active' => true,
    ]);

    $june = CarbonImmutable::parse('2026-06-01');
    $svc = app(MonthlyBillingService::class);

    $svc->generateForLease($lease, $june, prorate: true);
    $second = $svc->generateForLease($lease, $june, prorate: false);

    expect($second['status'])->toBe('skipped');
});
