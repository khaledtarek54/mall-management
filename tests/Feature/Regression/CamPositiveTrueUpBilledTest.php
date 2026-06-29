<?php

use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\CamReconciliationService;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Regression (CRITICAL/HIGH money): a POSITIVE CAM true-up (tenant under-paid,
 * owes more) must always reach an invoice. It used to rely on a back-dated (pass
 * 1-3) then future-dated (pass 4) one_time charge that the monthly engine could
 * never pick up — for a fully-billed reconciled year AND, critically, for an
 * ended-term-but-still-active lease (the most likely under-collected tenant),
 * which the monthly run's expiry filter excludes. It now settles IMMEDIATELY on
 * a dedicated recovery invoice at bill() time, independent of the monthly engine
 * + the lease's active/expiry state.
 */
afterEach(fn () => Carbon::setTestNow());

function billPositiveTrueUp(array $leaseAttrs = [])
{
    Carbon::setTestNow('2027-01-15'); // reconciliation runs the year AFTER 2026
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant(), $leaseAttrs);
    // actual 50000 > estimated 30000 → +20000 the tenant owes.
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);

    return $svc->bill($pool->allocations()->sole());
}

it('settles a positive true-up on a recovery invoice immediately (no monthly run needed)', function () {
    $billed = billPositiveTrueUp();

    $charge = Charge::find($billed->billed_charge_id);
    expect((float) $charge->amount)->toBe(20000.0);

    $item = InvoiceItem::where('charge_id', $charge->id)->first();
    expect($item)->not->toBeNull()
        ->and((float) $item->total)->toBe(20000.0)
        ->and($item->invoice->status)->not->toBe('cancelled');
});

it('does not let a CAM recovery invoice pre-empt the lease regular monthly invoice', function () {
    Carbon::setTestNow('2027-02-01 01:00'); // current month Feb 2027, before its monthly run
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    // Reconcile 2026 → positive true-up → recovery invoice (period 2026, not Feb 2027).
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    // The Feb-2027 monthly run must STILL create the lease's regular rent invoice —
    // the recovery invoice (period 2026) must not satisfy Feb's overlap guard.
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2027-02-01'));

    $febBilled = Invoice::where('lease_id', $lease->id)
        ->whereDate('period_start', '>=', '2027-02-01')
        ->whereDate('period_start', '<=', '2027-02-28')
        ->exists();
    expect($febBilled)->toBeTrue();

    // …and the CAM true-up is billed EXACTLY ONCE (the recovery invoice). The
    // recovery charge is is_active=false + dated to the reconciled year, so the
    // Feb run must NOT re-bill it onto the regular monthly invoice (double-bill).
    expect(InvoiceItem::where('charge_id', $billed->billed_charge_id)->count())->toBe(1);
});

it('still bills a positive true-up for an ENDED-TERM (moved-out) lease', function () {
    // Lease ran Jan–Dec 2026 and ENDED, but stays 'active' (no auto-expiry job).
    // The monthly engine would exclude it; the recovery invoice does not.
    $billed = billPositiveTrueUp(['commencement_date' => '2026-01-01', 'expiry_date' => '2026-12-31']);

    expect(InvoiceItem::where('charge_id', $billed->billed_charge_id)->count())->toBe(1)
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});
