<?php

/*
|--------------------------------------------------------------------------
| REGRESSION — applied credit survives a later payment recompute
|--------------------------------------------------------------------------
| BUG (fixed): a credit applied to an invoice was erased when a later payment
| triggered Invoice::recomputeTotals(), because that method summed ONLY the
| `captured` payments pivot — the applied credit was not durably folded into
| paid_amount/balance.
|
| FIX: invoices.credit_applied_amount column; CreditNoteService::applyToInvoice
| bumps it then calls recomputeTotals(); recomputeTotals() adds it to the
| payments-pivot sum. So a subsequent payment recompute keeps, not erases, the
| credit.
|
| Setup mirrors tests/Feature/Scenarios/CreditNoteScenarioTest.php (cnDraft) and
| tests/Feature/Scenarios/PaymentScenarioTest.php (captured-payment pivot).
*/

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CreditNoteService;

/** Future-due so an unpaid recompute stays `issued`, never auto-flips overdue. */
function cnDriftInvoice(float $total): Invoice
{
    $lease = makeLease(makeUnit(makeAsset()));

    return makeInvoice($lease, [
        'due_date' => now()->addDays(30),
        'subtotal' => $total, 'vat_amount' => 0,
        'total' => $total, 'paid_amount' => 0, 'balance' => $total,
        'status' => 'issued',
    ]);
}

it('keeps an applied credit when a later captured payment recomputes the invoice', function () {
    $invoice = cnDriftInvoice(1000);
    $svc = app(CreditNoteService::class);

    // Issue a 300 credit note for this tenant/invoice and apply it.
    $note = CreditNote::create([
        'tenant_id' => $invoice->tenant_id,
        'invoice_id' => $invoice->id,
        'lease_id' => $invoice->lease_id,
        'status' => 'draft',
        'issue_date' => '2026-02-15',
        'reason' => 'adjustment',
        'subtotal' => 300, 'vat_amount' => 0,
        'total' => 300, 'applied_amount' => 0, 'balance' => 300,
        'currency' => 'EGP',
    ]);

    $applied = $svc->applyToInvoice($svc->issue($note)->fresh(), $invoice->fresh(), 300.0);

    // After the credit: 300 settled, 700 still owed.
    expect($applied)->toBe(300.0);
    $invoice->refresh();
    expect((float) $invoice->credit_applied_amount)->toBe(300.0)
        ->and((float) $invoice->paid_amount)->toBe(300.0)
        ->and((float) $invoice->balance)->toBe(700.0)
        ->and($invoice->status)->toBe('partially_paid');

    // Now capture a 700 payment allocated to the SAME invoice. This is the
    // recompute that used to wipe the credit (paid would reset to 700, leaving
    // a phantom 300 balance).
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => 700,
        'currency' => 'EGP',
        'method' => 'bank_transfer',
        'status' => 'captured',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 700]);
    $invoice->recomputeTotals();

    $invoice->refresh();
    // The credit is NOT erased: payment 700 + credit 300 = 1000 settled.
    expect((float) $invoice->credit_applied_amount)->toBe(300.0)
        ->and((float) $invoice->paid_amount)->toBe(1000.0)
        ->and((float) $invoice->balance)->toBe(0.0)
        ->and($invoice->status)->toBe('paid');
});
