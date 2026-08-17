<?php

/*
|--------------------------------------------------------------------------
| A declaration records WHAT its sales figure is net of (2026-08-17)
|--------------------------------------------------------------------------
| `declared_sales` was one number with no stated basis. Percentage rent is charged on it, and if a
| tenant reports the VAT-inclusive figure their POS prints by default the charge is wrong — badly,
| because the breakpoint is subtracted FIRST:
|
|     breakpoint 12,000,000 @ 7%
|     net sales      15,000,000  →  overage 210,000
|     same, VAT-inc  17,100,000  →  overage 357,000     ← a 70% over-charge
|
| The defect was never a wrong figure. It was an UNKNOWABLE one: nothing recorded whether the number
| was gross or net, so nobody could tell which of those two rows they were looking at.
|
| These tests pin the certificate — gross, the deductions, and the net — and that `declared_sales`
| still means exactly what every calculation already reads it to mean.
*/

use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use App\Support\SalesExclusions;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->svc = app(PercentageRentCalculationService::class);
});

function basisLease($ctx, array $overrides = [])
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 100000,
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 12000000,
        'percentage_rent_rate' => 7,
    ], $overrides));
}

function basisDeclaration($lease, array $attrs)
{
    return TenantSalesDeclaration::create(array_merge([
        'lease_id' => $lease->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'calculated_percentage_rent' => 0,
        'status' => 'submitted',
        'declared_at' => now(),
        'declared_by_type' => $lease->tenant::class,
        'declared_by_id' => $lease->tenant_id,
    ], $attrs));
}

it('derives the net from the gross and its deductions', function () {
    $lease = basisLease($this);

    $declaration = basisDeclaration($lease, [
        'gross_sales' => 5000000,
        'sales_exclusions' => ['vat' => 614035.09, 'returns' => 40000],
    ]);

    expect((float) $declaration->fresh()->declared_sales)->toBe(4345964.91);
});

it('takes the VAT WITHIN a figure, never the VAT on top of it', function () {
    // gross − gross ÷ 1.14, not gross × 14%. At 5,000,000 the wrong formula deducts 700,000
    // instead of 614,035.09 — over-deducting by a factor of 1.14 and under-charging the landlord.
    expect(SalesExclusions::vatWithin(5000000, 14.0))->toBe(614035.09)
        ->and(round(5000000 - 614035.09, 2))->toBe(4385964.91);   // the net grosses back up correctly
});

it('leaves a declaration recorded the old way exactly as it was', function () {
    $lease = basisLease($this);

    // Gross null = the shape every existing declaration has. Nothing derives, nothing moves.
    $declaration = basisDeclaration($lease, ['declared_sales' => 4000000]);

    expect((float) $declaration->fresh()->declared_sales)->toBe(4000000.0)
        ->and($declaration->fresh()->gross_sales)->toBeNull();
});

it('refuses deductions larger than the turnover they come off', function () {
    $lease = basisLease($this);

    // A typo or a misread column. Flooring it at zero silently would bill percentage rent on a
    // figure nobody can reconcile back to the tenant's certificate.
    expect(fn () => basisDeclaration($lease, [
        'gross_sales' => 100000,
        'sales_exclusions' => ['vat' => 90000, 'returns' => 50000],
    ]))->toThrow(DomainException::class);
});

it('ignores an exclusion type that is not in the catalogue', function () {
    expect(SalesExclusions::total(['vat' => 100, 'invented_deduction' => 5000]))->toBe(100.0);
});

it('charges percentage rent on the NET, which is the whole point', function () {
    $lease = basisLease($this);

    // 17,100,000 reported VAT-inclusive → 15,000,000 net → (15,000,000 − 12,000,000) × 7%.
    $declaration = basisDeclaration($lease, [
        'gross_sales' => 17100000,
        'sales_exclusions' => ['vat' => SalesExclusions::vatWithin(17100000, 14.0)],
    ]);

    $declaration = $declaration->fresh();

    expect((float) $declaration->declared_sales)->toBe(15000000.0)
        ->and($this->svc->calculate($declaration))->toBe(210000.0);

    // …and what it would have been had the gross figure been taken at face value.
    $naive = basisDeclaration($lease, [
        'period_start' => '2027-06-01',
        'period_end' => '2027-06-30',
        'declared_sales' => 17100000,
    ]);

    expect($this->svc->calculate($naive->fresh()))->toBe(357000.0);
});

it('records on the LEASE which deductions its clause grants', function () {
    $lease = basisLease($this, [
        'percentage_rent_sales_exclusions' => ['returns', 'gift_cards'],
    ]);

    expect($lease->fresh()->percentage_rent_sales_exclusions)->toBe(['returns', 'gift_cards'])
        // VAT needs no grant — a shop collects it for the state, so it was never its sales.
        ->and(SalesExclusions::ALWAYS_ALLOWED)->toBe(['vat']);
});
