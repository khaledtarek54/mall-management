<?php

/*
|--------------------------------------------------------------------------
| When percentage rent is CHARGED is a lease term (2026-08-17)
|--------------------------------------------------------------------------
| `percentage_rent_frequency` is the calculation BASIS — period-by-period, or cumulative
| year-to-date. Billing was not modelled at all: the overage was invoiced the moment a declaration
| was locked, so every tenancy charged monthly whatever its contract said, and a clause reading
| "percentage rent payable quarterly in arrears" could not be expressed.
|
| Yardi carries the two separately (plus reporting frequency, a third), and this repo's own benchmark
| says it in bold: *a system that assumes they are the same cannot express the most common retail
| deal* (docs/benchmarks/yardi/03).
|
| The split is: the basis decides WHAT each month owes; the billing frequency decides WHEN — and how
| many months share — the invoice. In arrears always: a period is raised only once every month of it
| that the lease traded has been locked, because a quarter cannot be settled while a month of it is
| still unknown.
*/

use App\Models\Invoice;
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
    $this->operator = makeUser('manager', [$this->asset->id]);
    $this->svc = app(PercentageRentCalculationService::class);
});

/** Monthly basis (so each month's own overage is obvious), cadence varied per test. */
function cadenceLease($ctx, array $overrides = [])
{
    return makeLease($ctx->unit, $ctx->tenant, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-10-01',
        'expiry_date' => '2029-09-30',
        'has_percentage_rent' => true,
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_frequency' => 'monthly',
        'percentage_rent_threshold' => 1000000,
        'percentage_rent_rate' => 10,
    ], $overrides));
}

function lockMonth($ctx, $lease, string $month, float $sales): TenantSalesDeclaration
{
    $start = $month.'-01';

    $declaration = TenantSalesDeclaration::create([
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

    return $ctx->svc->lock($declaration, $ctx->operator);
}

/** Live percentage-rent invoices for a lease. */
function overageInvoices($lease)
{
    return Invoice::query()
        ->where('lease_id', $lease->id)
        ->whereNotIn('status', ['cancelled', 'credited'])
        ->whereHas('items', fn ($q) => $q->where('type', 'percentage_rent'))
        ->orderBy('period_start')
        ->get();
}

it('bills monthly by default — every lease that existed before this is untouched', function () {
    $lease = cadenceLease($this);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);

    // Two months, two invoices, each its own overage.
    expect(overageInvoices($lease)->pluck('total')->map(fn ($t) => (float) $t)->all())
        ->toBe([50000.0, 20000.0]);
});

it('raises NOTHING for a quarter still missing a month', function () {
    $lease = cadenceLease($this, ['percentage_rent_billing_frequency' => 'quarterly']);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);

    // The figures are computed and recorded; the quarter is simply not due yet.
    expect(overageInvoices($lease))->toHaveCount(0)
        ->and((float) TenantSalesDeclaration::where('lease_id', $lease->id)
            ->whereDate('period_start', '2026-10-01')->value('calculated_percentage_rent'))->toBe(50000.0);
});

it('raises ONE invoice for the whole quarter once its last month is locked', function () {
    $lease = cadenceLease($this, ['percentage_rent_billing_frequency' => 'quarterly']);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);
    lockMonth($this, $lease, '2026-12', 2000000);

    $invoices = overageInvoices($lease);

    // 50,000 + 20,000 + 100,000 — one document, spanning the quarter it settles.
    expect($invoices)->toHaveCount(1)
        ->and((float) $invoices->first()->total)->toBe(170000.0)
        ->and($invoices->first()->period_start->format('Y-m-d'))->toBe('2026-10-01')
        ->and($invoices->first()->period_end->format('Y-m-d'))->toBe('2026-12-31');
});

it('settles a part-traded quarter on the months the lease actually traded', function () {
    // Commences in NOVEMBER: Q4 is Nov–Dec for this tenancy, and must not wait for an October
    // it never traded.
    $lease = cadenceLease($this, [
        'commencement_date' => '2026-11-01',
        'percentage_rent_billing_frequency' => 'quarterly',
    ]);

    lockMonth($this, $lease, '2026-11', 1200000);
    lockMonth($this, $lease, '2026-12', 2000000);

    expect(overageInvoices($lease))->toHaveCount(1)
        ->and((float) overageInvoices($lease)->first()->total)->toBe(120000.0);
});

it('waits for the whole year on an annual cadence', function () {
    $lease = cadenceLease($this, [
        'commencement_date' => '2026-10-01',
        'percentage_rent_billing_frequency' => 'annual',
    ]);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);

    expect(overageInvoices($lease))->toHaveCount(0);

    // 2026 is Oct–Dec for this lease, so December completes the year.
    lockMonth($this, $lease, '2026-12', 2000000);

    expect(overageInvoices($lease))->toHaveCount(1)
        ->and((float) overageInvoices($lease)->first()->total)->toBe(170000.0);
});

it('un-settles the quarter when a month is voided back out of it', function () {
    $lease = cadenceLease($this, ['percentage_rent_billing_frequency' => 'quarterly']);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);
    $december = lockMonth($this, $lease, '2026-12', 2000000);

    expect(overageInvoices($lease))->toHaveCount(1);

    // December is disputed — the quarter is no longer knowable, so its invoice must come back off
    // rather than stand at a total that includes sales now withdrawn.
    $this->svc->voidLocked($december, $this->operator, 'Figures disputed by the tenant');

    expect(overageInvoices($lease))->toHaveCount(0);
});

it('keeps the annual BASIS and the billing cadence independent of each other', function () {
    // Cumulative year-to-date arithmetic, settled quarterly: both halves of the clause at once.
    $lease = cadenceLease($this, [
        'percentage_rent_frequency' => 'annual',
        'percentage_rent_threshold' => 3000000,   // an ANNUAL breakpoint
        'percentage_rent_billing_frequency' => 'quarterly',
    ]);

    lockMonth($this, $lease, '2026-10', 1500000);
    lockMonth($this, $lease, '2026-11', 1200000);

    expect(overageInvoices($lease))->toHaveCount(0);

    lockMonth($this, $lease, '2026-12', 2000000);

    // 2026 is a three-month year, so the 3,000,000 breakpoint pro-rates to 750,000.
    // Cumulative sales 4,700,000 → (4,700,000 − 750,000) × 10% = 395,000, in one quarterly invoice.
    $invoices = overageInvoices($lease);

    expect($invoices)->toHaveCount(1)
        ->and((float) $invoices->first()->total)->toBe(395000.0);
});
