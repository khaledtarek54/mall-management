<?php

use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Services\CamReconciliationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Regression (CRITICAL / money): a POSITIVE CAM true-up (tenant under-paid its
 * estimate, owes more) used to be billed as a one_time charge back-dated to
 * Jan 1 of the reconciled year. Reconciliation runs the FOLLOWING year, by which
 * point {year} is fully billed — so that charge could NEVER reach an invoice
 * (silent lost revenue). It now bills on the next monthly run.
 */
afterEach(fn () => Carbon::setTestNow());

it('bills a positive CAM true-up on the next monthly run, not a lost back-dated charge', function () {
    Carbon::setTestNow('2027-01-15'); // reconciliation runs the year AFTER 2026

    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    // actual 50000 > estimated 30000 → +20000 the tenant owes.
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000, 'status' => 'draft',
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    $charge = Charge::find($billed->billed_charge_id);
    expect((float) $charge->amount)->toBe(20000.0)
        ->and($charge->start_date->format('Y-m'))->toBe('2027-02'); // next month, not 2026

    // The next monthly run picks it up → it actually reaches an invoice.
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2027-02-01'));

    expect(InvoiceItem::where('charge_id', $charge->id)->count())->toBe(1);
});
