<?php

use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;

/**
 * Regression (HIGH / money): a negative CAM true-up (the tenant over-paid its
 * estimate) used to be billed as a negative one-off Charge dated 1 Jan. If the
 * credit exceeded January's other charges, the invoice total went negative and
 * Invoice::recomputeTotals() floored it to 0 — silently LOSING the credit (it
 * never carried forward). A negative true-up is now an issued CreditNote, so the
 * credit is preserved and the books still tie.
 */
it('preserves a large negative CAM true-up as a credit note, not a lost negative charge', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => 100]));
    // Over-paid heavily: actual 10k, estimated 60k → -50k true-up (far exceeds a
    // month's rent, the scenario that used to lose the credit).
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 10000, 'total_estimated_collected' => 60000, 'status' => 'draft',
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();
    expect((float) $alloc->true_up_amount)->toBeLessThan(0);

    $billed = $svc->bill($alloc);

    // Credit preserved on an issued credit note — nothing floored away.
    $note = CreditNote::find($billed->billed_credit_note_id);
    expect($note)->not->toBeNull()
        ->and($note->status)->toBe('issued')
        ->and((float) $note->balance)->toBe(50000.0)
        ->and($note->tenant_id)->toBe($lease->tenant_id);

    // No negative charge leaked onto the lease.
    expect(Charge::where('lease_id', $lease->id)->where('amount', '<', 0)->count())->toBe(0);

    // The books still tie out (the CAM check accepts a credit-note-backed allocation).
    expect(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('auto-applies the CAM credit to the lease open invoices (nets what is owed)', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset, ['area_sqm' => 100]);
    $lease = makeLease($unit, makeTenant());
    $invoice = makeInvoice($lease); // issued, balance 11400

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 10000, 'total_estimated_collected' => 60000, 'status' => 'draft',
    ]); // → -50000 credit

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $billed = $svc->bill($pool->allocations()->sole());

    // The credit netted against the open invoice (regression: it used to sit
    // unapplied, so the tenant kept paying full invoices).
    expect((float) $invoice->fresh()->credit_applied_amount)->toBe(11400.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);

    // Remainder preserved on the note (50000 − 11400), books still tie.
    $note = CreditNote::find($billed->billed_credit_note_id);
    expect((float) $note->balance)->toBe(38600.0)
        ->and(app(BooksReconciliationService::class)->run()['ok'])->toBeTrue();
});

it('re-billing a credit allocation is a no-op (no duplicate credit note)', function () {
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]));
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 10000, 'total_estimated_collected' => 60000, 'status' => 'draft',
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $alloc = $pool->allocations()->sole();

    $first = $svc->bill($alloc);
    $second = $svc->bill($alloc->fresh());

    expect($second->billed_credit_note_id)->toBe($first->billed_credit_note_id)
        ->and(CreditNote::where('lease_id', $alloc->lease_id)->count())->toBe(1);
});
