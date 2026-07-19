<?php

use App\Models\Payment;
use App\Services\ReceiptPdfService;

/**
 * Payment receipt voucher (سند قبض) — a printable/downloadable proof of a cash/cheque/transfer
 * receipt (MVP for a cash-heavy Egyptian operator). Read-only: it renders the payment amount + its
 * invoice allocation, never touches AR/GL. Mirrors the invoice PDF (Mpdf + RTL font switch).
 */
function receiptPayment(): Payment
{
    $tenant = makeTenant(['name' => 'Nour Retail', 'phone' => '0100 000 0000']);
    $lease = makeLease(makeUnit(makeAsset(['name' => 'Atriom Walk'])), $tenant, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'total' => 11400, 'balance' => 11400, 'paid_amount' => 0]);

    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 11400, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 11400]);

    return $payment->fresh();
}

it('renders a valid PDF receipt for a received payment', function () {
    $pdf = app(ReceiptPdfService::class)->build(receiptPayment());

    expect($pdf)->toBeString()
        ->and(substr($pdf, 0, 4))->toBe('%PDF')   // real PDF bytes
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('renders the Arabic (RTL) receipt without error', function () {
    app()->setLocale('ar');
    $pdf = app(ReceiptPdfService::class)->build(receiptPayment());

    expect(substr($pdf, 0, 4))->toBe('%PDF');
    app()->setLocale('en');
});

it('names the file after the payment reference', function () {
    $payment = receiptPayment();
    expect(app(ReceiptPdfService::class)->filename($payment))->toBe($payment->reference.'.pdf');
});

it('renders a receipt that splits across multiple invoices with an on-account remainder', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $a = makeInvoice($lease, ['status' => 'issued', 'total' => 5000, 'balance' => 5000]);
    $b = makeInvoice($lease, ['status' => 'issued', 'total' => 3000, 'balance' => 3000]);

    // Pays 10,000: 5,000 → A, 3,000 → B, 2,000 unallocated (on account).
    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 10000, 'currency' => 'EGP',
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now(),
    ]);
    $payment->invoices()->attach($a->id, ['allocated_amount' => 5000]);
    $payment->invoices()->attach($b->id, ['allocated_amount' => 3000]);

    $pdf = app(ReceiptPdfService::class)->build($payment->fresh());
    expect(substr($pdf, 0, 4))->toBe('%PDF');
});
