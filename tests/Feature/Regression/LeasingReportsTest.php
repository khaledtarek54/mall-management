<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\Reports\ReportService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * The two leasing reports the benchmark asked for (phase 7, stories RR-02 and RR-04).
 *
 * **RR-02, the expiration schedule** — when the mall's income rolls off. The rent roll says what it
 * earns today; nothing said when that stops, so a year with 30% of the income expiring was
 * invisible until somebody sorted the lease table by hand.
 *
 * **RR-04, occupancy cost %** — who is in trouble before they miss a payment. Every input already
 * existed; the number was produced nowhere.
 *
 * The two traps pinned here are both about what a zero MEANS: a holdover lease must not be counted
 * as having rolled off in a past year (its rent is live), and a tenant who has declared no sales
 * must read as UNKNOWN rather than as the healthiest tenant in the mall.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function leasingReportLease(array $attrs, float $rent, float $area = 100): Lease
{
    $asset = $attrs['asset'] ?? makeAsset();
    unset($attrs['asset']);

    $lease = makeLease(makeUnit($asset, ['area_sqm' => $area]), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2027-01-01',
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2027-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('groups expiries by year with the area and annual rent at risk', function () {
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();

    leasingReportLease(['asset' => $asset, 'expiry_date' => '2028-12-31'], 10000, 100);
    leasingReportLease(['asset' => $asset, 'expiry_date' => '2028-09-30'], 20000, 200);
    leasingReportLease(['asset' => $asset, 'expiry_date' => '2030-12-31'], 30000, 300);

    $schedule = app(ReportService::class)->expirationSchedule(null, $asset->id);

    $y2028 = $schedule->firstWhere('bucket', '2028');
    $y2030 = $schedule->firstWhere('bucket', '2030');

    expect($y2028['leases'])->toBe(2)
        ->and($y2028['area_sqm'])->toBe(300.0)
        ->and($y2028['annual_rent'])->toBe(360000.0)          // (10,000 + 20,000) × 12
        // Half the mall's area and half its income is up in 2028 — the number the report exists for.
        ->and($y2028['share_of_area_pct'])->toBe(50.0)
        ->and($y2030['annual_rent'])->toBe(360000.0);
});

it('puts a holdover in its own bucket rather than a past year', function () {
    // The trap. A lease past its term but still trading has NOT rolled off — its rent is live and
    // its space occupied — so counting it under 2027 would understate both this year's risk and
    // today's income, and would bury the one row a leasing manager should act on today.
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();

    leasingReportLease(['asset' => $asset, 'expiry_date' => '2027-12-31'], 10000, 100);   // expired, still active
    leasingReportLease(['asset' => $asset, 'expiry_date' => '2029-12-31'], 20000, 200);

    $schedule = app(ReportService::class)->expirationSchedule(null, $asset->id);

    expect($schedule->firstWhere('bucket', '2027'))->toBeNull()
        ->and($schedule->firstWhere('bucket', 'holdover')['leases'])->toBe(1)
        ->and($schedule->firstWhere('bucket', 'holdover')['annual_rent'])->toBe(120000.0)
        // …and it sorts first, because it is the one needing a decision now.
        ->and($schedule->first()['bucket'])->toBe('holdover');
});

it('computes occupancy cost as billed cost over declared sales', function () {
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();

    $lease = leasingReportLease([
        'asset' => $asset, 'expiry_date' => '2030-12-31', 'has_percentage_rent' => true,
    ], 10000, 100);

    // 100,000 of occupancy cost billed across the window…
    $invoice = makeInvoice($lease, ['period_start' => '2028-03-01', 'period_end' => '2028-03-31', 'status' => 'issued']);
    $invoice->items()->delete();
    $invoice->items()->create(['description' => 'Rent', 'type' => 'base_rent', 'amount' => 80000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 80000]);
    $invoice->items()->create(['description' => 'Service', 'type' => 'service_charge', 'amount' => 20000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 20000]);

    // …against 500,000 of declared sales.
    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2028-03-01', 'period_end' => '2028-03-31',
        'declared_sales' => 500000, 'status' => 'declared', 'declared_at' => '2028-04-01',
    ]);

    $row = app(ReportService::class)
        ->occupancyCost(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'), $asset->id)
        ->firstWhere('lease_id', $lease->id);

    expect($row['occupancy_cost'])->toBe(100000.0)
        ->and($row['declared_sales'])->toBe(500000.0)
        ->and($row['occupancy_cost_pct'])->toBe(20.0)
        ->and($row['months_declared'])->toBe(1)
        ->and($row['has_estimates'])->toBeFalse();
});

it('excludes penalties, because a late fee is not a cost of occupying the space', function () {
    // Folding a late fee in would make a tenant's occupancy look expensive because they paid late
    // rather than because their rent is high — which inverts the signal the report exists to give.
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();
    $lease = leasingReportLease(['asset' => $asset, 'expiry_date' => '2030-12-31', 'has_percentage_rent' => true], 10000, 100);

    $invoice = makeInvoice($lease, ['period_start' => '2028-03-01', 'period_end' => '2028-03-31', 'status' => 'issued']);
    $invoice->items()->delete();
    $invoice->items()->create(['description' => 'Rent', 'type' => 'base_rent', 'amount' => 100000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100000]);
    $invoice->items()->create(['description' => 'Late fee', 'type' => 'late_fee', 'amount' => 50000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 50000]);
    $invoice->items()->create(['description' => 'Fine', 'type' => 'violation_fine', 'amount' => 25000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 25000]);

    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2028-03-01', 'period_end' => '2028-03-31',
        'declared_sales' => 500000, 'status' => 'declared', 'declared_at' => '2028-04-01',
    ]);

    $row = app(ReportService::class)
        ->occupancyCost(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'), $asset->id)
        ->firstWhere('lease_id', $lease->id);

    expect($row['occupancy_cost'])->toBe(100000.0)
        ->and($row['occupancy_cost_pct'])->toBe(20.0);
});

it('reports an unknown ratio, not zero, when a tenant has declared no sales', function () {
    // The trap. Zero would sort the tenant who files nothing as the healthiest in the mall — the
    // exact opposite of the truth, since a tenant who stops declaring is usually the one in trouble.
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();
    $lease = leasingReportLease(['asset' => $asset, 'expiry_date' => '2030-12-31', 'has_percentage_rent' => true], 10000, 100);

    $invoice = makeInvoice($lease, ['period_start' => '2028-03-01', 'period_end' => '2028-03-31', 'status' => 'issued']);
    $invoice->items()->delete();
    $invoice->items()->create(['description' => 'Rent', 'type' => 'base_rent', 'amount' => 100000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100000]);

    $row = app(ReportService::class)
        ->occupancyCost(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'), $asset->id)
        ->firstWhere('lease_id', $lease->id);

    expect($row['occupancy_cost'])->toBe(100000.0)
        ->and($row['declared_sales'])->toBe(0.0)
        ->and($row['occupancy_cost_pct'])->toBeNull();
});

it('flags a ratio built on estimated sales', function () {
    // An estimate makes the ratio soft, and the operator should see that before acting on it.
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();
    $lease = leasingReportLease(['asset' => $asset, 'expiry_date' => '2030-12-31', 'has_percentage_rent' => true], 10000, 100);

    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2028-03-01', 'period_end' => '2028-03-31',
        'declared_sales' => 500000, 'is_estimate' => true, 'status' => 'declared', 'declared_at' => '2028-04-01',
    ]);

    $row = app(ReportService::class)
        ->occupancyCost(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'), $asset->id)
        ->firstWhere('lease_id', $lease->id);

    expect($row['has_estimates'])->toBeTrue();
});

it('ignores cancelled invoices, because nobody is being asked for that money', function () {
    CarbonImmutable::setTestNow('2028-06-15');
    $asset = makeAsset();
    $lease = leasingReportLease(['asset' => $asset, 'expiry_date' => '2030-12-31', 'has_percentage_rent' => true], 10000, 100);

    foreach ([['issued', 100000], ['cancelled', 900000]] as [$status, $amount]) {
        $invoice = makeInvoice($lease, ['period_start' => '2028-03-01', 'period_end' => '2028-03-31', 'status' => $status]);
        $invoice->items()->delete();
        $invoice->items()->create(['description' => 'Rent', 'type' => 'base_rent', 'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount]);
    }

    TenantSalesDeclaration::create([
        'lease_id' => $lease->id, 'period_start' => '2028-03-01', 'period_end' => '2028-03-31',
        'declared_sales' => 500000, 'status' => 'declared', 'declared_at' => '2028-04-01',
    ]);

    $row = app(ReportService::class)
        ->occupancyCost(CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'), $asset->id)
        ->firstWhere('lease_id', $lease->id);

    expect($row['occupancy_cost'])->toBe(100000.0);
});
