<?php

use App\Models\MarketingBudget;
use App\Settings\MarketingSettings;

it('defaults the marketing levy rate to 5%', function () {
    expect(app(MarketingSettings::class)->levy_rate_percent)->toBe(5.0);
});

it('derives balance as accrued minus spent', function () {
    $asset = makeAsset();

    $budget = MarketingBudget::create([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'accrued_amount' => 1000,
        'spent_amount' => 350,
    ]);

    expect($budget->balance())->toBe(650.0);
});

it('gets or creates one budget per asset and period', function () {
    $asset = makeAsset();

    $a = MarketingBudget::forPeriod($asset->id, 2026);
    $b = MarketingBudget::forPeriod($asset->id, 2026);

    expect($a->id)->toBe($b->id)
        ->and(MarketingBudget::count())->toBe(1)
        ->and($a->balance())->toBe(0.0);
});
