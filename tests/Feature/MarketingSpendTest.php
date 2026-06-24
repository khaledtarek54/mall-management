<?php

use App\Models\MarketingBudget;

function makeBudget(int $accrued = 1000): MarketingBudget
{
    return MarketingBudget::create([
        'asset_id' => makeAsset()->id,
        'period_year' => 2026,
        'accrued_amount' => $accrued,
    ]);
}

it('decrements the budget balance when a spend is recorded', function () {
    $budget = makeBudget(1000);

    $budget->spends()->create([
        'category' => 'event',
        'description' => 'Mall festival',
        'amount' => 300,
        'spent_on' => now(),
    ]);

    expect($budget->refresh()->spent_amount)->toBe('300.00')
        ->and($budget->balance())->toBe(700.0);
});

it('recomputes spent when a spend is deleted', function () {
    $budget = makeBudget(1000);
    $first = $budget->spends()->create(['category' => 'offer', 'description' => 'A', 'amount' => 200, 'spent_on' => now()]);
    $budget->spends()->create(['category' => 'promotion', 'description' => 'B', 'amount' => 100, 'spent_on' => now()]);

    expect($budget->refresh()->spent_amount)->toBe('300.00');

    $first->delete();

    expect($budget->refresh()->spent_amount)->toBe('100.00')
        ->and($budget->balance())->toBe(900.0);
});

it('carries a receipt reference to accounting (MKT-4)', function () {
    $budget = makeBudget(1000);

    $spend = $budget->spends()->create([
        'category' => 'printed_work',
        'description' => 'Flyers',
        'amount' => 150,
        'spent_on' => now(),
        'receipt_reference' => 'RCPT-2026-001',
    ]);

    expect($spend->receipt_reference)->toBe('RCPT-2026-001');
});
