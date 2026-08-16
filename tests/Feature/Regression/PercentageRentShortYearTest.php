<?php

/*
|--------------------------------------------------------------------------
| A short percentage-rent year gets a short breakpoint (2026-08-16)
|--------------------------------------------------------------------------
| An annual breakpoint is a whole year's figure. Applied unchanged to a year the lease only traded
| part of, it is unreachable: a lease commencing 1 October carried a 12,000,000 breakpoint against
| three months of trading, owed no percentage rent at all in its first year, and the clock then reset
| on 1 January. A straight under-bill of the landlord's share — and a silent one, because the tenant
| simply never crosses a line nobody is looking at.
|
| The market rule is to pro-rate the breakpoint for the short year, and the NATURAL breakpoint proves
| why it must be: it is defined as annual base rent ÷ rate, and a tenant who occupies three months
| pays three months of base rent, so the sales at which the percentage would have covered that rent
| are a quarter as many.
|
| Applied by annualising the sales — `overage_short(S) = f × overage(S ÷ f)` — which is the same
| arithmetic as scaling the breakpoint for artificial and natural, and is the only form that also
| works for a tiered ladder, which has no single breakpoint to scale.
|
| Deviation stated: pro-rated by whole MONTHS, not days. Sales are declared per month, so a lease
| commencing on the 20th still files a full October declaration; a day-share breakpoint would measure
| one grain against another.
*/

use App\Models\LeasePercentageRentTier;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->svc = app(PercentageRentCalculationService::class);
});

/** A lease trading only Oct–Dec of its first calendar year. */
function shortYearLease($ctx, array $overrides = [])
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'term_months' => 36,
        'base_rent_monthly' => 72000,
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 12000000,
        'percentage_rent_rate' => 7,
    ], $overrides));
}

function shortYearDeclaration($lease, string $month, float $sales): TenantSalesDeclaration
{
    $start = $month.'-01';

    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => $start,
        'period_end' => CarbonImmutable::parse($start)->endOfMonth()->toDateString(),
        'declared_sales' => $sales,
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $lease->tenant::class,
        'declared_by_id' => $lease->tenant_id,
    ]);
}

it('pro-rates an artificial breakpoint to the months the lease actually traded', function () {
    $lease = shortYearLease($this);

    // Three months of 2026 → the 12,000,000 annual breakpoint applies as 3,000,000.
    // (4,000,000 − 3,000,000) × 7% = 70,000. Before this, 4,000,000 vs 12,000,000 → nothing at all.
    $declaration = shortYearDeclaration($lease, '2026-10', 4000000);

    expect($this->svc->calculate($declaration))->toBe(70000.0);
});

it('leaves a full calendar year exactly as it was', function () {
    $lease = shortYearLease($this);

    // 2027 is twelve months of the term — the full breakpoint, unscaled.
    $declaration = shortYearDeclaration($lease, '2027-01', 13000000);

    expect($this->svc->calculate($declaration))->toBe(70000.0);
});

it('pro-rates a natural breakpoint through the base rent that was actually payable', function () {
    $lease = shortYearLease($this, ['percentage_rent_calculation_type' => 'natural_breakpoint']);

    // sales × rate − (months of base rent) = 4,000,000 × 7% − (3 × 72,000) = 280,000 − 216,000.
    $declaration = shortYearDeclaration($lease, '2026-10', 4000000);

    expect($this->svc->calculate($declaration))->toBe(64000.0);
});

it('scales a tiered ladder too — the shape a single breakpoint cannot express', function () {
    $lease = shortYearLease($this, ['percentage_rent_calculation_type' => 'tiered']);

    LeasePercentageRentTier::create([
        'lease_id' => $lease->id, 'from_amount' => 0, 'to_amount' => 12000000, 'rate' => 0,
    ]);
    LeasePercentageRentTier::create([
        'lease_id' => $lease->id, 'from_amount' => 12000000, 'to_amount' => null, 'rate' => 7,
    ]);

    // The whole ladder scales by 3/12, so the 0% band ends at 3,000,000 for this short year.
    $declaration = shortYearDeclaration($lease, '2026-10', 4000000);

    expect($this->svc->calculate($declaration))->toBe(70000.0);
});

it('does not touch a MONTHLY lease — its breakpoint is already a monthly figure', function () {
    $lease = shortYearLease($this, [
        'percentage_rent_frequency' => 'monthly',
        'percentage_rent_threshold' => 1000000,
    ]);

    // (4,000,000 − 1,000,000) × 7% — no year to be short of.
    $declaration = shortYearDeclaration($lease, '2026-10', 4000000);

    expect($this->svc->calculate($declaration))->toBe(210000.0);
});

it('pro-rates the FINAL year too, where the term ends part-way through it', function () {
    $lease = shortYearLease($this);   // expires 30/09/2029 → nine months of 2029

    // Breakpoint 12,000,000 × 9/12 = 9,000,000; (10,000,000 − 9,000,000) × 7% = 70,000.
    $declaration = shortYearDeclaration($lease, '2029-01', 10000000);

    expect($this->svc->calculate($declaration))->toBe(70000.0);
});

it('explains the breakpoint it actually used, not the full-year figure', function () {
    $lease = shortYearLease($this);
    $declaration = shortYearDeclaration($lease, '2026-10', 4000000);

    $explain = $this->svc->explain($declaration);

    // A screen showing 12,000,000 beside a charge computed against 3,000,000 is how a correct
    // invoice comes to look like a mistake — and how a wrong one escapes notice.
    expect($explain['breakpoint'])->toBe(3000000.0)
        ->and($explain['year_factor'])->toBe(0.25);
});
