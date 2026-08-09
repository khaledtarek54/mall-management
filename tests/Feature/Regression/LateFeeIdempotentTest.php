<?php

use App\Models\Invoice;
use App\Services\LateFeeService;

/*
|--------------------------------------------------------------------------
| Regression: LateFeeService::applyTo() idempotency guard
|--------------------------------------------------------------------------
| BUG (fixed): the "no late_fee yet" re-check used to run OUTSIDE the
| transaction, so two passes (or two concurrent runs) could both pass the
| guard and double-charge the same invoice. The fix moves the re-check
| INSIDE DB::transaction with lockForUpdate. These tests assert that a
| second applyTo() call returns false and leaves exactly one late_fee item
| with the invoice total raised only once.
*/

beforeEach(function () {
    // The SETTING, not `config('billing.*')` — and that distinction was a live bug until MF-08.
    // The admin Settings page writes BillingSettings while LateFeeService read the config file
    // (populated from env), so every late-fee value an operator saved on that screen was ignored.
    // A test that configured the config file passed while the real screen did nothing.
    $settings = app(\App\Settings\BillingSettings::class);
    $settings->late_fee_percent = 5;
    $settings->late_fee_grace_days = 7;
});

it('applies exactly one late fee on the first applyTo() call', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    $invoice = makeInvoice($lease, [
        'due_date' => '2026-01-01',
        'status' => 'overdue',
        'balance' => 10000,
    ]);

    $applied = app(LateFeeService::class)->applyTo($invoice);

    expect($applied)->toBeTrue();
    expect($invoice->fresh()->items()->where('type', 'late_fee')->count())->toBeOne();
});

it('does not double-charge on a second applyTo() with a stale model snapshot', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    $invoice = makeInvoice($lease, [
        'due_date' => '2026-01-01',
        'status' => 'overdue',
        'subtotal' => 10000,
        'total' => 10000,
        'balance' => 10000,
    ]);

    $service = app(LateFeeService::class);

    // A second "concurrent run" grabs its OWN snapshot of the invoice BEFORE
    // any fee exists. With the pre-fix guard (checked outside the transaction
    // against the passed-in, possibly stale model) this snapshot would pass
    // the "no late_fee yet" check and double-charge. The fix re-loads the row
    // with lockForUpdate inside the transaction, so it sees the fee and bails.
    $staleSnapshot = Invoice::with('items')->find($invoice->id);

    // First run applies the fee. 5% of 10000 = 500.
    expect($service->applyTo($invoice))->toBeTrue();

    $afterFirst = $invoice->fresh();
    $totalAfterFirst = (float) $afterFirst->total;
    $balanceAfterFirst = (float) $afterFirst->balance;
    expect($totalAfterFirst)->toBe(10500.0);

    // Second run, operating on the stale snapshot, must be rejected.
    expect($service->applyTo($staleSnapshot))->toBeFalse();

    $afterSecond = $invoice->fresh();

    // STILL exactly one late_fee line item — no duplicate was inserted.
    expect($afterSecond->items()->where('type', 'late_fee')->count())->toBeOne();

    // The invoice total/balance rose exactly once.
    expect((float) $afterSecond->total)->toBe($totalAfterFirst);
    expect((float) $afterSecond->balance)->toBe($balanceAfterFirst);
});
