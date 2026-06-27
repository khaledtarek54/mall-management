<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Reconciliation\BooksReconciliationService;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation harness must (a) confirm clean books tie out and (b) actually
 * CATCH a stored value that drifted from its source — otherwise it gives false comfort.
 */

function reconcile(?string $month = null): array
{
    return app(BooksReconciliationService::class)->run($month);
}

/** Create a captured payment allocated to an invoice (mirrors the Create page + S2S callback). */
function captureAllocatedPayment(Invoice $invoice, float $amount): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => $amount,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $payment->status = 'captured';
    $payment->save(); // Payment::saved → recompute on the allocated invoices

    return $payment;
}

it('confirms the books tie out on clean data', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()))); // total 11400, balance 11400
    captureAllocatedPayment($invoice, 5000); // partial → recompute: paid 5000, balance 6400

    $report = reconcile();

    expect($report['ok'])->toBeTrue();
    expect($report['controlTotals']['collected'])->toBe(5000.0);
    expect($report['controlTotals']['outstandingAR'])->toBe(6400.0);
});

it('catches a paid_amount that drifted from its source', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    captureAllocatedPayment($invoice, 5000);

    // Corrupt the stored paid_amount directly, bypassing recomputeTotals().
    DB::table('invoices')->where('id', $invoice->id)->update(['paid_amount' => 9999, 'balance' => 1401]);

    $report = reconcile();

    expect($report['ok'])->toBeFalse();
    $paid = collect($report['checks'])->firstWhere('key', 'paid_amount');
    expect($paid['passed'])->toBeFalse();
    expect($paid['discrepancies'])->toHaveCount(1);
    expect($paid['discrepancies'][0]['detail'])->toContain('9999');
});

it('catches a broken invoice composition (subtotal + VAT ≠ total)', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    DB::table('invoices')->where('id', $invoice->id)->update(['total' => 99999]);

    $report = reconcile();

    expect($report['ok'])->toBeFalse();
    expect(collect($report['checks'])->firstWhere('key', 'invoice_composition')['passed'])->toBeFalse();
});

it('includes applied credit notes in the derived paid amount', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    $invoice->credit_applied_amount = 1400;
    $invoice->recomputeTotals(); // paid = 0 payments + 1400 credit = 1400; balance 10000

    $report = reconcile();

    expect($report['ok'])->toBeTrue();
    expect($report['controlTotals']['creditApplied'])->toBe(1400.0);
});
