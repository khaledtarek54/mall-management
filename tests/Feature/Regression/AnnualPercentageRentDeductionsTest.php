<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;

/**
 * An ANNUAL percentage-rent lease must be billed net of its deduction clause.
 *
 * `calculate()` applies `netOfDeductions()`. `retrueAnnualYear()` built its marginal straight from
 * `overage()` and never called it — and `retrueAnnualYear()` is the path that BILLS. So a lease on
 * `percentage_rent_frequency = 'annual'` carrying `percentage_rent_deductible_types` was **charged
 * gross while every screen showed net**: the declaration's own `calculated_percentage_rent`, the
 * plain-language breakdown, the estimate. The tenant's contract said the deduction was theirs.
 *
 * *"Percentage rent is payable to the extent it exceeds CAM and real-estate tax paid in the same
 * period"* is a common clause, and the deduction is often the larger number — so this is not a
 * rounding difference, it is the whole charge on a marginal year.
 *
 * The cumulative arithmetic stays GROSS on purpose (see `netOfDeductions`): the deduction is a
 * clause about what the tenant already paid, not about how the breakpoint works, so it must not
 * perturb the marginals that have to keep summing to the year's overage.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function annualDeductionLease(array $attrs = []): Lease
{
    return makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_rate' => 10,
        'percentage_rent_threshold' => 1000000,
        'percentage_rent_frequency' => 'annual',
    ], $attrs))->fresh();
}

function annualDeductionDeclare(Lease $lease, float $sales, string $month): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create([
        'lease_id' => $lease->id,
        'period_start' => "{$month}-01",
        'period_end' => CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString(),
        'declared_sales' => $sales,
        'declared_at' => now(),
        'status' => 'submitted',
    ]);
}

/** A billed service charge sitting inside the declaration's period — the deductible. */
function annualDeductionServiceCharge(Lease $lease, string $month, float $amount): void
{
    $start = CarbonImmutable::parse("{$month}-01");

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => $start->toDateString(),
        'due_date' => $start->addDays(7)->toDateString(),
        'period_start' => $start->toDateString(),
        'period_end' => $start->endOfMonth()->toDateString(),
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount,
        'paid_amount' => 0, 'balance' => $amount,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);
}

/** What the lease was actually invoiced in percentage rent, across every live invoice. */
function annualDeductionBilled(Lease $lease): float
{
    return round((float) InvoiceItem::query()
        ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->where('invoices.lease_id', $lease->id)
        ->whereNotIn('invoices.status', ['cancelled', 'credited'])
        ->where('invoice_items.type', 'percentage_rent')
        ->sum('invoice_items.amount'), 2);
}

it('bills an annual lease NET of its deduction clause', function () {
    $lease = annualDeductionLease(['percentage_rent_deductible_types' => ['service_charge']]);

    // 1,500,000 of sales → 500,000 over the breakpoint → 50,000 gross at 10%.
    annualDeductionServiceCharge($lease, '2026-06', 20000);
    $declaration = annualDeductionDeclare($lease, 1500000, '2026-06');

    app(PercentageRentCalculationService::class)->lock($declaration, makeUser('super_admin'));

    // 50,000 gross − 20,000 billed service charge = 30,000. It was billing 50,000.
    expect(annualDeductionBilled($lease))->toBe(30000.0);
});

it('agrees with what the screens show', function () {
    // The specific harm: `calculate()` (which feeds the declaration figure and the breakdown) netted
    // while the billing path did not, so the operator had no way to see the discrepancy.
    $lease = annualDeductionLease(['percentage_rent_deductible_types' => ['service_charge']]);
    annualDeductionServiceCharge($lease, '2026-06', 20000);
    $declaration = annualDeductionDeclare($lease, 1500000, '2026-06');

    $shown = app(PercentageRentCalculationService::class)->calculate($declaration);
    app(PercentageRentCalculationService::class)->lock($declaration, makeUser('super_admin'));

    expect(annualDeductionBilled($lease))->toBe($shown)
        ->and((float) $declaration->fresh()->calculated_percentage_rent)->toBe($shown);
});

it('still bills the full overage when the lease has no deduction clause', function () {
    // The paired control. Netting must not be achieved by charging less across the board — without
    // this, a fix that simply subtracted something would pass the case above.
    $lease = annualDeductionLease();
    annualDeductionServiceCharge($lease, '2026-06', 20000);
    $declaration = annualDeductionDeclare($lease, 1500000, '2026-06');

    app(PercentageRentCalculationService::class)->lock($declaration, makeUser('super_admin'));

    expect(annualDeductionBilled($lease))->toBe(50000.0);
});

it('keeps the cumulative marginals gross, netting only what each month owes', function () {
    // The invariant the fix must not break: `$runningPrior` accumulates SALES, so a deduction in one
    // month cannot shift where the breakpoint falls for the next.
    //
    // June 700,000 → cumulative 700,000, still under the 1,000,000 breakpoint → 0 owed.
    // July 800,000 → cumulative 1,500,000 → the marginal carries the whole 50,000 overage.
    // A 20,000 service charge in JULY nets that month to 30,000; June owes nothing either way.
    $lease = annualDeductionLease(['percentage_rent_deductible_types' => ['service_charge']]);
    annualDeductionServiceCharge($lease, '2026-07', 20000);

    $svc = app(PercentageRentCalculationService::class);
    $user = makeUser('super_admin');

    $svc->lock(annualDeductionDeclare($lease, 700000, '2026-06'), $user);
    $svc->lock(annualDeductionDeclare($lease, 800000, '2026-07'), $user);

    expect(annualDeductionBilled($lease))->toBe(30000.0);
});

it('floors a month at zero rather than crediting the tenant', function () {
    // A deduction larger than the overage owes nothing; it does not become a refund. Otherwise the
    // re-true would issue a negative percentage-rent line and hand the tenant back their own
    // service charge.
    $lease = annualDeductionLease(['percentage_rent_deductible_types' => ['service_charge']]);
    annualDeductionServiceCharge($lease, '2026-06', 90000);
    $declaration = annualDeductionDeclare($lease, 1500000, '2026-06');

    app(PercentageRentCalculationService::class)->lock($declaration, makeUser('super_admin'));

    expect(annualDeductionBilled($lease))->toBe(0.0);
});
