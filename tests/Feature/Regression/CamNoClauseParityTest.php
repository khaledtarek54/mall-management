<?php

use App\Models\CamExpensePool;
use App\Models\InvoiceItem;
use App\Services\CamReconciliationService;
use Illuminate\Support\Carbon;

/**
 * KEYSTONE (CAM recovery-clause engine, slice 1 = admin fee): a pool that configures NO
 * admin fee (admin_fee_pct null — every pre-existing pool, and the default for a mall whose
 * leases don't charge one) must bill BYTE-IDENTICALLY to before the feature existed. The fee
 * is a strictly additive clause: no clause → no fee line, no fee charge, no fee VAT, no change
 * to the recovery/credit amounts or the invoice totals. If this test ever needs relaxing, the
 * admin fee stopped being a no-op for no-clause malls — stop and rethink.
 */
afterEach(fn () => Carbon::setTestNow());

function parityPool(float $actual, float $estimated): CamExpensePool
{
    Carbon::setTestNow('2027-01-15'); // reconciliation runs the year AFTER 2026
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());

    return CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => $actual, 'total_estimated_collected' => $estimated,
        'status' => 'draft',
        // admin_fee_pct intentionally left null → no clause.
    ]);
}

it('carries zero admin fee on the allocation when no clause is configured', function () {
    $pool = parityPool(50000, 30000);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $alloc = $pool->allocations()->sole();
    expect((float) $alloc->admin_fee_amount)->toBe(0.0)
        ->and((float) $alloc->admin_fee_vat_amount)->toBe(0.0);
});

it('bills a positive true-up with exactly one recovery line and no fee artifacts', function () {
    $pool = parityPool(50000, 30000); // +20000 owed
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    $items = InvoiceItem::where('charge_id', $billed->billed_charge_id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->type)->toBe('cam_recovery')
        ->and((float) $items->first()->invoice->total)->toBe(20000.0) // true-up only, no fee
        ->and($billed->billed_admin_fee_charge_id)->toBeNull()
        ->and(InvoiceItem::where('type', 'cam_admin_fee')->count())->toBe(0);
});

it('bills a negative true-up as a credit with no fee-only invoice', function () {
    $pool = parityPool(30000, 50000); // -20000 overpaid
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    expect($billed->billed_credit_note_id)->not->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->toBeNull()
        ->and(InvoiceItem::where('type', 'cam_admin_fee')->count())->toBe(0);
});

it('settles a zero true-up with no financial document at all', function () {
    $pool = parityPool(40000, 40000); // exact match
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    expect($billed->status)->toBe('billed')
        ->and($billed->billed_charge_id)->toBeNull()
        ->and($billed->billed_credit_note_id)->toBeNull()
        ->and($billed->billed_admin_fee_charge_id)->toBeNull()
        ->and(InvoiceItem::where('type', 'cam_admin_fee')->count())->toBe(0);
});
