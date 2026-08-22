<?php

use App\Models\Charge;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

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
        // The levy is exempt because the CATALOGUE says so, resolved at billing — not because a
        // `false` was frozen onto the row when the levy was set up (EG-01).
        ->and($charges->first()->vat_applicable)->toBeNull()
        ->and($charges->first()->resolvedVatRate())->toBe(0.0)
        ->and($charges->first()->is_active)->toBeTrue();
});

it('captures the levy start date from the lease commencement', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000, 'commencement_date' => '2026-02-15']);

    $charge = app(MarketingLevyService::class)->createLevyCharge($lease);

    expect($charge->start_date->toDateString())->toBe('2026-02-15')
        ->and($charge->currency)->toBe('EGP')
        ->and($charge->frequency)->toBe('monthly');
});

it('derives the property marketing budget accrual from billed marketing line items', function () {
    // The levy is no longer "incremented" via accrue(); it is DERIVED from the
    // billed marketing InvoiceItems. Billing a lease that carries a marketing
    // charge raises the budget's accrued_amount automatically (via the
    // InvoiceItem saved hook → MarketingBudget::recomputeAccrued).
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 10000, 'commencement_date' => '2026-01-01']);

    // Base rent (so there is something to bill) + the marketing levy charge.
    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 10000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);
    app(MarketingLevyService::class)->createLevyCharge($lease);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    $budget = MarketingBudget::forPeriod($asset->id, 2026)->refresh();

    // 5% of 10,000 = 500 billed → 500 accrued, nothing spent.
    expect((float) $budget->accrued_amount)->toBe(500.0)
        ->and($budget->balance())->toBe(500.0);
});

it('accumulates the derived accrual across two billed periods', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => 6000, 'commencement_date' => '2026-01-01']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 6000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);
    app(MarketingLevyService::class)->createLevyCharge($lease);

    $svc = app(MonthlyBillingService::class);
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    // 2 months × (5% of 6,000) = 600 into the single per-year budget row.
    expect(MarketingBudget::where('asset_id', $asset->id)->count())->toBe(1)
        ->and((float) MarketingBudget::forPeriod($asset->id, 2026)->refresh()->accrued_amount)->toBe(600.0);
});
