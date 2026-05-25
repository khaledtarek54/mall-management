<?php

use App\Models\Charge;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

it('generates invoices for every active lease in the period', function () {
    $asset = makeAsset();
    $unit1 = makeUnit($asset);
    $unit2 = makeUnit($asset);

    $leaseA = makeLease($unit1, attrs: ['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31']);
    $leaseB = makeLease($unit2, attrs: ['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31']);

    Charge::create([
        'lease_id' => $leaseA->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 5000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2025-01-01',
        'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $leaseB->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 3000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2025-01-01',
        'is_active' => true,
    ]);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    expect($stats['leases_considered'])->toBe(2);
    expect($stats['created'])->toBe(2);
    expect($stats['failed'])->toBe(0);
});

it('is idempotent: a second run for the same period creates no duplicates', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, attrs: ['commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31']);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 5000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2025-01-01',
        'is_active' => true,
    ]);

    $period = CarbonImmutable::parse('2026-04-01');
    $first = app(MonthlyBillingService::class)->runForPeriod($period);
    $second = app(MonthlyBillingService::class)->runForPeriod($period);

    expect($first['created'])->toBe(1);
    expect($second['created'])->toBe(0);
    expect($second['skipped'])->toBe(1);
});

it('skips inactive leases', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    makeLease($unit, attrs: ['status' => 'draft']);
    makeLease($unit, attrs: ['status' => 'terminated']);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-05-01'));

    expect($stats['leases_considered'])->toBe(0);
    expect($stats['created'])->toBe(0);
});

it('skips leases that have not commenced yet', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    makeLease($unit, attrs: [
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2028-12-31',
    ]);

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-06-01'));

    expect($stats['leases_considered'])->toBe(0);
});
