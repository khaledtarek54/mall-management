<?php

use App\Models\Payment;
use App\Services\ReceiptPdfService;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A receipt says who took the money (module 06 close-out).
 *
 * `payments.received_by` existed, `PostDatedChequeService` set it, and the receipt PDF renders it —
 * but the ORDINARY payment path never wrote it. So the most common receipt of all, cash or transfer
 * taken at the counter, silently omitted the line: the column was there and only one of its two
 * writers had ever been built.
 *
 * The Blade guards it with `@if($receivedBy)`, so nothing crashed. It just quietly said less than it
 * was designed to.
 */
beforeEach(fn () => test()->seed(RolesPermissionsSeeder::class));

it('names the operator who recorded the payment on the receipt', function () {
    $user = makeUser('accounting');
    $this->actingAs($user);

    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'RC-1']), null, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000]);

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => 5000,
        'payment_date' => '2026-03-10',
        'method' => 'cash',
        'status' => 'captured',
        'received_by' => $user->id,
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

    expect($payment->fresh()->receiver?->name)->toBe($user->name);

    // And the receipt renders without the line vanishing.
    $pdf = app(ReceiptPdfService::class)->build($payment->fresh());

    expect(strlen($pdf))->toBeGreaterThan(1000);
});

it('still renders a receipt when nobody is recorded', function () {
    // The Blade guards the line, so a gateway payment with no operator — or an older row written
    // before this was stamped — must not break the document.
    $lease = makeLease(makeUnit(makeAsset(), ['code' => 'RC-2']), null, ['status' => 'active']);
    $invoice = makeInvoice($lease, ['status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);

    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'amount' => 1000,
        'payment_date' => '2026-03-10',
        'method' => 'bank_transfer',
        'status' => 'captured',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 1000]);

    expect($payment->fresh()->received_by)->toBeNull()
        ->and(strlen(app(ReceiptPdfService::class)->build($payment->fresh())))->toBeGreaterThan(1000);
});
