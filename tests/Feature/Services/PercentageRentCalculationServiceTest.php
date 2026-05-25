<?php

use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;

beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->tenant = makeTenant();
});

function declarationFor($unit, $tenant, array $leaseAttrs, float $sales): TenantSalesDeclaration
{
    $lease = makeLease($unit, $tenant, $leaseAttrs);

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
    ]);
}

it('returns zero when the lease has no percentage rent', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => false,
    ], 100000);

    expect(app(PercentageRentCalculationService::class)->calculate($decl))->toBe(0.0);
});

it('artificial breakpoint: (sales - threshold) * rate', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
        'base_rent_monthly' => 10000,
    ], 100000);

    // (100000 - 50000) * 0.05 = 2500
    expect(app(PercentageRentCalculationService::class)->calculate($decl))->toBe(2500.0);
});

it('natural breakpoint: sales * rate - base_rent', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'natural_breakpoint',
        'percentage_rent_rate' => 8,
        'base_rent_monthly' => 10000,
    ], 200000);

    // 200000 * 0.08 - 10000 = 16000 - 10000 = 6000
    expect(app(PercentageRentCalculationService::class)->calculate($decl))->toBe(6000.0);
});

it('floors percentage rent at zero (no negative charges)', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5,
    ], 50000);

    expect(app(PercentageRentCalculationService::class)->calculate($decl))->toBe(0.0);
});

it('locks a declaration and creates the percentage-rent charge', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ], 100000);

    $locker = makeUser('manager');

    $locked = app(PercentageRentCalculationService::class)->lock($decl, $locker, 'reviewed');

    expect($locked->status)->toBe('locked');
    expect($locked->locked_at)->not->toBeNull();
    expect($locked->locked_by_user_id)->toBe($locker->id);
    expect((float) $locked->calculated_percentage_rent)->toBe(2500.0);
    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(1);
});

it('locking is idempotent', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 50000,
        'percentage_rent_rate' => 5,
    ], 100000);

    $locker = makeUser('manager');
    $svc = app(PercentageRentCalculationService::class);

    $svc->lock($decl, $locker);
    $svc->lock($decl->fresh(), $locker);

    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(1);
});

it('skips charge creation when calculated amount is zero', function () {
    $decl = declarationFor($this->unit, $this->tenant, [
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => 100000,
        'percentage_rent_rate' => 5,
    ], 50000);

    app(PercentageRentCalculationService::class)->lock($decl, makeUser('manager'));

    expect($decl->lease->charges()->where('type', 'percentage_rent')->count())->toBe(0);
});
