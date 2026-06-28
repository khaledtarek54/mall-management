<?php

use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\MarketingBudget;
use App\Services\MarketingLevyService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Build a billable lease that carries BOTH a base-rent charge and the marketing
 * levy charge — the shape a real lease has once LeaseCreationService seeds it.
 */
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

    app(MarketingLevyService::class)->createLevyCharge($lease);

    return $lease;
}

it('derives the budget accrual from the billed marketing line item', function () {
    $lease = marketingLeaseWithRent(10000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));
    expect($result['status'])->toBe('created');

    // The billed marketing line item is the source of truth for the accrual.
    $marketingItem = InvoiceItem::where('invoice_id', $result['invoice']->id)
        ->where('type', 'marketing')
        ->first();

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();

    expect((float) $marketingItem->amount)->toBe(500.0)
        ->and((float) $budget->accrued_amount)->toBe((float) $marketingItem->amount)
        ->and((float) $budget->accrued_amount)->toBe(500.0);
});

it('charges the levy to the tenant — the invoice total now includes it', function () {
    $lease = marketingLeaseWithRent(10000);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-04-01'));

    // Base rent (10,000, VAT-exempt) + marketing levy (500, VAT-exempt) = 10,500.
    // The levy is a real billed line, not a silent internal accrual.
    expect((float) $result['invoice']->total)->toBe(10500.0)
        ->and((float) $result['invoice']->subtotal)->toBe(10500.0);
});

it('backfills marketing budgets from historical base rent', function () {
    $lease = marketingLeaseWithRent(8000);
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-02-01'));

    MarketingBudget::query()->update(['accrued_amount' => 0]);

    $this->artisan('marketing:backfill-budgets')->assertSuccessful();

    $budget = MarketingBudget::forPeriod($lease->unit->asset_id, 2026)->refresh();
    expect((float) $budget->accrued_amount)->toBe(400.0);
});
