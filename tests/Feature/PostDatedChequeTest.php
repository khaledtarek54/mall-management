<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Services\PostDatedChequeService;
use App\Services\VoidPaymentService;

/**
 * Post-dated cheque register (strengthen item #4), v1 = register-only, settle-on-clear. The
 * invoice stays open until the cheque CLEARS; clearing records a normal cheque Payment (AR reduced
 * through Invoice::recomputeTotals). Lifecycle: held -> deposited -> cleared / bounced; cancel.
 */
function invoiceOf(Asset $asset, float $balance): Invoice
{
    $lease = makeLease(makeUnit($asset));

    return makeInvoice($lease, [
        'subtotal' => $balance, 'vat_amount' => 0, 'total' => $balance,
        'paid_amount' => 0, 'balance' => $balance, 'status' => 'issued',
    ]);
}

function pdcFor(Asset $asset, Invoice $invoice, float $amount): PostDatedCheque
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

/*
|--------------------------------------------------------------------------
| Close-out sweep 2026-07-27 — module 33 (PDC) formal CLOSED pass
|--------------------------------------------------------------------------
| An adversarial money/AR + authz sweep on the mature module. These pin the
| CLOSE_NOW fixes it surfaced.
*/

it('reverses a cleared cheque back to bounced when its clearing payment is voided (F-3)', function () {
    // The documented remedy for a cleared cheque is "void its payment". That re-opens the invoice's
    // AR — but nothing reconciled the cheque, so it stayed permanently `cleared` pointing at a
    // refunded payment, invisible to the matured-uncleared surfaces. Now the payment's saved hook
    // reverses it to `bounced`.
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $pdc = pdcFor($asset, $invoice, 5000);
    app(PostDatedChequeService::class)->clear($pdc, makeUser(), '2026-07-19');
    expect($pdc->fresh()->status)->toBe('cleared')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0);

    $payment = Payment::find($pdc->fresh()->cleared_payment_id);
    app(VoidPaymentService::class)->void($payment, 'bank returned it');

    // The register stops reporting it collected, and the invoice's AR re-opened.
    expect($pdc->fresh()->status)->toBe('bounced')
        ->and((float) $invoice->fresh()->balance)->toBe(5000.0);

    // ...and the bounced lifecycle is available again (it can be re-presented).
    app(PostDatedChequeService::class)->deposit($pdc->fresh());
    expect($pdc->fresh()->status)->toBe('deposited');
});

it('refuses to link a cheque to another tenant\'s invoice in the same property (F-2)', function () {
    // Same-property cross-TENANT: clearing would settle another tenant's invoice with this tenant's
    // payment, contaminating the per-tenant AR sub-ledger + owner statements.
    $asset = makeAsset();
    $invoiceT1 = invoiceOf($asset, 5000);          // tenant T1
    $leaseT2 = makeLease(makeUnit($asset));         // a DIFFERENT tenant, same property
    expect((int) $leaseT2->tenant_id)->not->toBe((int) $invoiceT1->tenant_id);

    expect(fn () => PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $asset->id,
        'tenant_id' => $leaseT2->tenant_id,          // T2
        'invoice_id' => $invoiceT1->id,               // T1's invoice — same property, cross-tenant
        'cheque_number' => 'CHQ-'.uniqid(),
        'amount' => 5000, 'cheque_date' => '2026-08-01', 'received_date' => '2026-07-01',
        'status' => 'held',
    ]))->toThrow(DomainException::class);
});

it('re-checks the tenant when a held cheque\'s tenant is edited after linking (F-2 dirty-trigger)', function () {
    // Editing ONLY tenant_id (invoice_id/asset_id unchanged) was the gap the property-only guard
    // missed — the tenant_id-dirty trigger now catches it.
    $asset = makeAsset();
    $pdc = pdcFor($asset, invoiceOf($asset, 5000), 5000);   // correct: T1 + T1's invoice
    $leaseT2 = makeLease(makeUnit($asset));

    expect(fn () => $pdc->update(['tenant_id' => $leaseT2->tenant_id]))->toThrow(DomainException::class);
});

it('does not over-settle an invoice when two cheques clear against it (F-1)', function () {
    // The clear() path now locks the invoice and calls assertInvoicesNotOverAllocated, mirroring
    // every other payment path — the second clear can never push paid_amount past the total.
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $a = pdcFor($asset, $invoice, 5000);
    $b = pdcFor($asset, $invoice, 5000);            // both point at the same 5000 invoice
    $svc = app(PostDatedChequeService::class);

    $svc->clear($a, makeUser(), '2026-07-19');
    $svc->clear($b->fresh(), makeUser(), '2026-07-19');

    // Settled exactly once — never 10000 on a 5000 receivable.
    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(5000.0)
        ->and((float) $invoice->balance)->toBe(0.0);
});

it('clearing against a CANCELLED invoice allocates nothing (validation sweep — receivables)', function () {
    // A cancelled invoice has left the books and can hold no receivable. clear() reads the
    // invoice's balance under the lock, and recomputeTotals() forces a cancelled invoice's
    // balance to 0, so `min(amount, balance)` allocates nothing — the cheque's money becomes an
    // unallocated on-account credit rather than settling AR that no longer exists.
    //
    // This mirrors what refitAllocationsToBalance() does on the gateway path (fittable = 0 for a
    // cancelled invoice). The behaviour was correct but had no witness; the sweep gave it one.
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $cheque = pdcFor($asset, $invoice, 5000);

    $invoice->update(['status' => 'cancelled']);
    $invoice->recomputeTotals();
    expect((float) $invoice->fresh()->balance)->toBe(0.0);

    $cleared = app(PostDatedChequeService::class)->clear($cheque, makeUser(), '2026-07-19');

    // The cheque still clears (the bank honoured it) — but nothing lands on the dead invoice.
    expect($cleared->status)->toBe(PostDatedCheque::STATUS_CLEARED);
    $payment = Payment::find($cleared->cleared_payment_id);
    expect($payment)->not->toBeNull()
        ->and((float) $payment->amount)->toBe(5000.0)
        ->and($payment->invoices()->count())->toBe(0)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(0.0);
});

it('clearing against a LIVE invoice still settles it (control for the cancelled case above)', function () {
    // Without this the refusal above would pass just as happily if clear() were a no-op.
    $asset = makeAsset();
    $invoice = invoiceOf($asset, 5000);
    $cheque = pdcFor($asset, $invoice, 5000);

    app(PostDatedChequeService::class)->clear($cheque, makeUser(), '2026-07-19');

    expect((float) $invoice->fresh()->paid_amount)->toBe(5000.0);
});
