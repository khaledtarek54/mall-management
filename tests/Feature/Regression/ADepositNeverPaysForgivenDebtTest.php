<?php

use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\MoveOutStatementService;
use Tests\Support\MoveOut;

/**
 * **A forgiven slice of a debt must not be taken out of the tenant's deposit.**
 *
 * `ApplyDepositToInvoiceService::apply()` capped the draw at `(float) $locked->balance`. A write-off
 * is not one of the four settlement channels, so it deliberately leaves `balance` standing — which
 * means the cap was the amount BILLED, not the amount still owed, and the forgiven part came out of
 * the departing tenant's security deposit.
 *
 * It over-charges in CASH, at the one moment there is no recovery path: the tenant has gone. And it
 * did so while the move-out statement beside it — which reads `collectableBalance()` — told the
 * operator, and the tenant who signs it, a smaller number. Two documents, one settlement, and the
 * one that moved the money was the wrong one.
 *
 * `collectableBalanceForUpdate()` is the LOCKING twin, which matters as much as the netting: a plain
 * read inside that transaction is answered from the snapshot taken before it waited on the lock.
 */
beforeEach(function () {
    $this->lease = MoveOut::lease(100000);
    $this->service = app(ApplyDepositToInvoiceService::class);

    $this->invoice = makeInvoice($this->lease, [
        'asset_id' => $this->lease->unit->asset_id,
        'status' => 'overdue', 'issue_date' => '2030-11-01', 'due_date' => '2030-11-10',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);
});

/** Forgive `$amount` of the fixture invoice. */
function forgive(float $amount): void
{
    InvoiceWriteOff::create([
        'invoice_id' => test()->invoice->id,
        'tenant_id' => test()->invoice->tenant_id,
        'amount' => $amount,
        'entry_date' => '2030-11-20',
        'reason' => 'Goodwill on the disputed portion.',
    ]);
}

it('draws only what is still owed, not what was billed', function () {
    forgive(6000);

    expect($this->service->apply($this->lease->fresh(), $this->invoice->fresh()))->toBe(4000.0);
});

it('agrees with the statement the tenant signs', function () {
    // The two must not disagree: one of them moves the money and the other is the document.
    forgive(6000);

    $applied = $this->service->apply($this->lease->fresh(), $this->invoice->fresh());
    $statement = app(MoveOutStatementService::class)->for($this->lease->fresh());

    expect($applied)->toBe(4000.0)
        // …and the deposit still holds the 6,000 that was never owed.
        ->and($statement['deposit_held'])->toBe(96000.0);
});

it('still settles an ordinary arrear in full', function () {
    // The control: nothing forgiven, so the whole debt comes off the deposit exactly as before.
    expect($this->service->apply($this->lease->fresh(), $this->invoice->fresh()))->toBe(10000.0);
});

it('draws nothing at all against a debt forgiven in full', function () {
    forgive(10000);

    expect($this->service->apply($this->lease->fresh(), $this->invoice->fresh()))->toBe(0.0)
        ->and(app(MoveOutStatementService::class)->for($this->lease->fresh())['deposit_held'])->toBe(100000.0);
});
