<?php

use App\Models\Charge;
use App\Services\MarketingLevyService;

it('computes the levy as 5% of base rent', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000]);

    expect(app(MarketingLevyService::class)->amountFor($lease))->toBe(500.0);
});

it('creates an idempotent VAT-exempt marketing levy charge on the lease', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 8000]);

    $svc = app(MarketingLevyService::class);
    $svc->createLevyCharge($lease);
    $svc->createLevyCharge($lease);

    $charges = Charge::where('lease_id', $lease->id)->where('type', 'marketing')->get();

    expect($charges)->toHaveCount(1)
        ->and((float) $charges->first()->amount)->toBe(400.0)
        ->and($charges->first()->vat_applicable)->toBeFalse()
        ->and($charges->first()->is_active)->toBeTrue();
});

it('accrues the levy into the property marketing budget', function () {
    $asset = makeAsset();

    $svc = app(MarketingLevyService::class);
    $svc->accrue($asset->id, 2026, 500);
    $budget = $svc->accrue($asset->id, 2026, 300);

    expect($budget->accrued_amount)->toBe('800.00')
        ->and($budget->balance())->toBe(800.0);
});
