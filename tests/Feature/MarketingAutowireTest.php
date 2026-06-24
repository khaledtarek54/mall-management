<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

function marketingLeaseWithRent(float $rent): Lease
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, null, ['base_rent_monthly' => $rent, 'commencement_date' => '2026-01-01']);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $rent,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);

    return $lease;
}

it('accrues 5% of billed base rent into the marketing budget', function () {
    $lease = marketingLeaseWithRent(10000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    expect($result['status'])->toBe('created');

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->where('period_year', 2026)->first();
    expect($budget)->not->toBeNull()
        ->and((float) $budget->accrued_amount)->toBe(500.0);
});

it('does not add a tenant line item — invoice total is unchanged', function () {
    $lease = marketingLeaseWithRent(10000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    // Base rent is VAT-exempt, so the invoice total equals the rent exactly:
    // the levy is an internal accrual, not a billed line.
    expect((float) $result['invoice']->total)->toBe(10000.0);
});

it('backfills marketing budgets from historical base rent', function () {
    $lease = marketingLeaseWithRent(8000);
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-02-01'));

    MarketingBudget::query()->update(['accrued_amount' => 0]);

    $this->artisan('marketing:backfill-budgets')->assertSuccessful();

    $budget = MarketingBudget::where('asset_id', $lease->unit->asset_id)->where('period_year', 2026)->first();
    expect((float) $budget->accrued_amount)->toBe(400.0);
});
