<?php

use App\Models\Lease;

it('computes total monthly amount as base + service charge', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, attrs: [
        'base_rent_monthly' => 10000,
        'service_charge_monthly' => 2000,
    ]);

    expect($lease->totalMonthlyAmount())->toBe(12000.0);
});

it('annualises monthly amount × 12', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, attrs: [
        'base_rent_monthly' => 5000,
        'service_charge_monthly' => 1000,
    ]);

    expect($lease->annualValue())->toBe(72000.0);
});

it('isActive() returns true only when status is active', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);

    $active = makeLease($unit, attrs: ['status' => 'active']);
    $draft = makeLease($unit, attrs: ['status' => 'draft']);
    $terminated = makeLease($unit, attrs: ['status' => 'terminated']);

    expect($active->isActive())->toBeTrue();
    expect($draft->isActive())->toBeFalse();
    expect($terminated->isActive())->toBeFalse();
});

it('flags leases expiring within the supplied window', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);

    $expiringSoon = makeLease($unit, attrs: [
        'expiry_date' => now()->addDays(45),
        'status' => 'active',
    ]);

    $expiringLater = makeLease($unit, attrs: [
        'expiry_date' => now()->addDays(120),
        'status' => 'active',
    ]);

    expect($expiringSoon->isExpiringSoon(90))->toBeTrue();
    expect($expiringLater->isExpiringSoon(90))->toBeFalse();
});

it('counts days until expiry', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, attrs: ['expiry_date' => now()->addDays(30)]);

    expect($lease->daysUntilExpiry())->toBeGreaterThanOrEqual(29)
        ->toBeLessThanOrEqual(31);
});

it('generates a unique reference per asset code', function () {
    $ref1 = Lease::generateReference('HW');
    $ref2 = Lease::generateReference('PA');

    expect($ref1)->toContain('HW')->and($ref2)->toContain('PA');
    expect($ref1)->not->toBe($ref2);
});
