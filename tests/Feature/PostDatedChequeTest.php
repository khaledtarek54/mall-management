<?php

use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Services\PostDatedChequeService;

/**
 * Post-dated cheque register (strengthen item #4), v1 = register-only, settle-on-clear. The
 * invoice stays open until the cheque CLEARS; clearing records a normal cheque Payment (AR reduced
 * through Invoice::recomputeTotals). Lifecycle: held -> deposited -> cleared / bounced; cancel.
 */
function invoiceOf(App\Models\Asset $asset, float $balance): App\Models\Invoice
{
    $lease = makeLease(makeUnit($asset));

    return makeInvoice($lease, [
        'subtotal' => $balance, 'vat_amount' => 0, 'total' => $balance,
        'paid_amount' => 0, 'balance' => $balance, 'status' => 'issued',
    ]);
}

function pdcFor(App\Models\Asset $asset, App\Models\Invoice $invoice, float $amount): PostDatedCheque
{
    return PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $asset->id,
        'tenant_id' => $invoice->tenant_id,
        'lease_id' => $invoice->lease_id,
        'invoice_id' => $invoice->id,
        'cheque_number' => 'CHQ-'.uniqid(),
        'amount' => $amount,
        'cheque_date' => '2026-08-01',
        'received_date' => '2026-07-01',
        'status' => 'held',
    ]);
}

it('clears a cheque: records a cheque payment and reduces the linked invoice balance', function () {
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $pdc = pdcFor($asset, $invoice, 5000);

    app(PostDatedChequeService::class)->clear($pdc, makeUser(), '2026-07-19');

    $pdc->refresh();
    expect($pdc->status)->toBe('cleared')
        ->and($pdc->cleared_payment_id)->not->toBeNull()
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);

    $payment = Payment::find($pdc->cleared_payment_id);
    expect($payment->method)->toBe('cheque')
        ->and($payment->status)->toBe('captured')
        ->and((float) $payment->amount)->toBe(5000.0);
});

it('allocates only up to the invoice balance (surplus is not over-paid)', function () {
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 3000);
    $pdc = pdcFor($asset, $invoice, 5000); // cheque bigger than the invoice

    app(PostDatedChequeService::class)->clear($pdc, makeUser(), '2026-07-19');

    // Invoice fully paid; the payment carries the full 5000 but only 3000 is allocated.
    expect((float) $invoice->fresh()->balance)->toBe(0.0);
    $payment = Payment::find($pdc->fresh()->cleared_payment_id);
    expect((float) $payment->invoices()->first()->pivot->allocated_amount)->toBe(3000.0);
});

it('deposits then clears through the lifecycle', function () {
    $asset = makeAsset();
    $pdc = pdcFor($asset, invoiceOf($asset, 5000), 5000);
    $svc = app(PostDatedChequeService::class);

    $svc->deposit($pdc);
    expect($pdc->fresh()->status)->toBe('deposited');

    $svc->clear($pdc->fresh(), makeUser(), '2026-07-19');
    expect($pdc->fresh()->status)->toBe('cleared');
});

it('bounces a cheque without touching the invoice, and it can be re-presented', function () {
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $pdc = pdcFor($asset, $invoice, 5000);
    $svc = app(PostDatedChequeService::class);

    $svc->bounce($svc->deposit($pdc));
    expect($pdc->fresh()->status)->toBe('bounced')
        ->and((float) $invoice->fresh()->balance)->toBe(5000.0); // never reduced

    // A bounced cheque can be re-deposited.
    $svc->deposit($pdc->fresh());
    expect($pdc->fresh()->status)->toBe('deposited');
});

it('cancels a held cheque but refuses to cancel a cleared one', function () {
    $asset = makeAsset();
    $held = pdcFor($asset, invoiceOf($asset, 5000), 5000);
    $svc = app(PostDatedChequeService::class);

    $svc->cancel($held);
    expect($held->fresh()->status)->toBe('cancelled');

    $cleared = pdcFor($asset, invoiceOf($asset, 5000), 5000);
    $svc->clear($cleared, makeUser(), '2026-07-19');
    expect(fn () => $svc->cancel($cleared->fresh()))->toThrow(DomainException::class);
});

it('makes a cleared cheque terminal-immutable', function () {
    $asset = makeAsset();
    $pdc = pdcFor($asset, invoiceOf($asset, 5000), 5000);
    app(PostDatedChequeService::class)->clear($pdc, makeUser(), '2026-07-19');

    expect(fn () => $pdc->fresh()->update(['status' => 'held']))->toThrow(DomainException::class);
});
