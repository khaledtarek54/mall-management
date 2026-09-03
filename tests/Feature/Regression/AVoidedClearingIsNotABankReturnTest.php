<?php

use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Services\BillBouncedChequeFeeService;
use App\Services\PostDatedChequeService;
use App\Services\VoidPaymentService;

/**
 * SW-020 — voiding a mis-keyed clearing marked the cheque BOUNCED, and made an NSF fee billable.
 *
 * `Payment::reconcileClearedChequeOnReversal()` sent every reversal to `bounced`, on the reading
 * that a reversed clearing means the bank returned the cheque. That is true of exactly one of the
 * three acts `Payment::REVERSED_STATUSES` distinguishes — and telling them apart is the whole point
 * of keeping them apart, as that constant's own docblock says: *a receipt keyed in error* is not
 * *a cheque the bank returned*.
 *
 * It is not cosmetic. `BillBouncedChequeFeeService` refuses any status but `bounced`, so a cashier
 * clearing the wrong cheque and voiding the receipt left the tenant's honoured cheque marked as
 * returned **and an NSF fee billable on it** — a charge for a bank event that never happened.
 *
 *  - `bounced` / `failed` → the bank did not honour it. The cheque is `bounced`. Unchanged.
 *  - `voided` → keyed in error. Back to where it was: `deposited` if it had been lodged (the cheque
 *    carries `deposited_on`), `held` if it was still in the drawer. Those are the two states
 *    `PostDatedChequeService::clear()` accepts, so it can be cleared again.
 *  - `refunded` → the cheque cleared and the money went back later. The bank honoured it; a refund
 *    is its own outbound movement. Left `cleared`.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'PDC']);
    $this->lease = makeLease(makeUnit($this->asset));
    $this->invoice = makeInvoice($this->lease, ['status' => 'issued', 'total' => 5000, 'balance' => 5000]);

    $this->cheque = PostDatedCheque::create([
        // `reference` is NOT NULL and allocated by the model's own generator, not defaulted.
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->invoice->tenant_id,
        'lease_id' => $this->invoice->lease_id,
        'invoice_id' => $this->invoice->id,
        'cheque_number' => 'CHQ-'.uniqid(),
        'bank_name' => 'CIB',
        'amount' => 5000,
        'cheque_date' => now()->subDay()->toDateString(),
        'received_date' => now()->subMonth()->toDateString(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]);
});

/** The payment that cleared the cheque. */
function clearingPaymentFor(PostDatedCheque $cheque): Payment
{
    app(PostDatedChequeService::class)->clear($cheque, makeUser(), now()->toDateString());

    return Payment::findOrFail($cheque->fresh()->cleared_payment_id);
}

it('puts a mis-keyed clearing back in the drawer, not into a bank return', function () {
    $payment = clearingPaymentFor($this->cheque);

    app(VoidPaymentService::class)->void($payment, 'cleared against the wrong cheque');

    // Measured before the fix: `bounced`.
    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_HELD);
});

it('returns a LODGED cheque to the bank it was lodged with', function () {
    app(PostDatedChequeService::class)->deposit($this->cheque, null, now()->toDateString());

    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_DEPOSITED);

    $payment = clearingPaymentFor($this->cheque->fresh());
    app(VoidPaymentService::class)->void($payment, 'keyed in error');

    // Not `held`: it really is at the bank, and `deposited_on` is the row's own record of that.
    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_DEPOSITED);
});

it('refuses the NSF fee on a cheque nobody returned', function () {
    // The consequence, and the reason this is money rather than tidiness.
    $payment = clearingPaymentFor($this->cheque);
    app(VoidPaymentService::class)->void($payment, 'cleared against the wrong cheque');

    expect(fn () => app(BillBouncedChequeFeeService::class)->bill($this->cheque->fresh()))
        ->toThrow(DomainException::class);
});

it('still marks a genuine bank return as bounced', function () {
    // The control, and the case the original rule was written for: a payment that FAILED is a
    // cheque the bank did not honour, and the NSF fee is then legitimately billable.
    $payment = clearingPaymentFor($this->cheque);

    $payment->status = 'failed';
    $payment->save();

    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_BOUNCED);
});

it('leaves a REFUNDED clearing alone — the bank honoured that cheque', function () {
    // Money in, then money out. A refund is its own outbound movement and the register is right to
    // go on saying the cheque was collected; calling it a bank return would make the NSF fee
    // billable on a cheque that cleared perfectly well.
    $payment = clearingPaymentFor($this->cheque);

    $payment->status = 'refunded';
    $payment->save();

    expect($this->cheque->fresh()->status)->toBe(PostDatedCheque::STATUS_CLEARED);
});

it('still refuses a hand-edit out of cleared', function () {
    // The carve-out is identified by the clearing PAYMENT having left the received set, not by the
    // destination. Without that, widening the destinations would have let the form walk a cleared
    // cheque straight back to `held` — which is what the terminal-immutability rule refuses, and
    // its own test is what caught it.
    clearingPaymentFor($this->cheque);

    expect(fn () => $this->cheque->fresh()->update(['status' => PostDatedCheque::STATUS_HELD]))
        ->toThrow(DomainException::class);
});
