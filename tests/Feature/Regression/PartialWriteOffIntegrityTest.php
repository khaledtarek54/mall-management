<?php

use App\Models\Invoice;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountingSeeder;

/**
 * A partial write-off must not be repeatable past the debt, and must not break the AR tie-out.
 *
 * `WriteOffInvoiceService` deliberately does not touch `balance` — balance is derived from the
 * four settlement channels and a write-off is not one of them, so the invoice keeps recording
 * what was owed. That decision is right, and it left two consequences unhandled:
 *
 *  1. **The cap was against `balance`, which a write-off never changes.** Prior write-offs were
 *     never subtracted, and the modal re-offered the full balance as its default and max. Write
 *     off 5,000 of 20,000, then accept the default 20,000, and the invoice takes **25,000 of bad
 *     debt against a 20,000 receivable** — AR credited below the debt, the invoice flipped to
 *     `written_off` and thereby EXCLUDED from the tie-out, leaving a permanent −5,000 delta with
 *     no document behind it. `InvoiceWriteOffTest`'s "cannot be written off twice" exercised only
 *     the full path and stayed green.
 *  2. **`glTieOut()` excluded only invoices written off in FULL.** A partial one stays live, so
 *     its whole balance counted toward `expectedAr` while the GL had already been relieved of the
 *     written-off part — an AR delta from the day it was booked, permanently, with no way to clear
 *     it.
 *
 * A third, separate defect closed alongside: the payment picker filtered `balance > 0` with no
 * status filter, so a written-off invoice was offered for allocation. The sibling picker in
 * `PostDatedChequeForm` had always filtered status, which is what made it an omission.
 */
beforeEach(function () {
    $this->seed(AccountingSeeder::class);

    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])));

    // Built like `InvoiceWriteOffTest::writableInvoice()` — a flat, VAT-free 20,000 debt, so the
    // arithmetic below reads as the money it is. Not shared as a helper: Pest parallelises per
    // file and a re-declared file-scope function is a fatal redeclaration.
    $this->invoice = makeInvoice($lease, [
        'status' => 'overdue',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000,
        'paid_amount' => 0, 'balance' => 20000,
    ]);

    expect((float) $this->invoice->balance)->toBe(20000.0);
});

it('refuses a second write-off that would exceed the debt', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, [
        'amount' => 5000, 'reason' => 'uneconomic_to_pursue',
    ]);

    // The exact operator action the modal used to propose: the balance is still 20,000.
    expect(fn () => app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'amount' => 20000, 'reason' => 'uneconomic_to_pursue',
    ]))->toThrow(DomainException::class);

    expect($this->invoice->fresh()->writtenOffAmount())->toBe(5000.0);
});

it('allows partials up to the debt and retires the invoice on the last one', function () {
    $svc = app(WriteOffInvoiceService::class);

    $svc->write($this->invoice, ['amount' => 5000, 'reason' => 'settled_short']);
    $svc->write($this->invoice->fresh(), ['amount' => 15000, 'reason' => 'settled_short']);

    $fresh = $this->invoice->fresh();

    // Two partials that together clear the debt must retire it, exactly as one full write-off
    // would — comparing against the raw balance left such an invoice `overdue` forever.
    expect($fresh->writtenOffAmount())->toBe(20000.0)
        ->and($fresh->status)->toBe('written_off');
});

it('defaults a second write-off to what is left, not the balance', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, [
        'amount' => 5000, 'reason' => 'uneconomic_to_pursue',
    ]);

    // No amount supplied → the service must take the remainder, not the (unchanged) balance.
    $second = app(WriteOffInvoiceService::class)->write($this->invoice->fresh(), [
        'reason' => 'uneconomic_to_pursue',
    ]);

    expect((float) $second->amount)->toBe(15000.0)
        ->and($this->invoice->fresh()->writtenOffAmount())->toBe(20000.0);
});

it('keeps the AR tie-out square across a partial write-off', function () {
    // Drive the REAL sweep, per the GL-registry invariant — and because glTieOut() short-circuits
    // to ['configured' => false] until something has actually posted.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    $before = app(BooksReconciliationService::class)->glTieOut();

    app(WriteOffInvoiceService::class)->write($this->invoice, [
        'amount' => 5000, 'reason' => 'uneconomic_to_pursue',
    ]);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $after = app(BooksReconciliationService::class)->glTieOut();

    // The invoice stays live with its balance intact, so `expectedAr` must subtract the
    // written-off part to match the GL relief. Compare the DELTA, not the absolute: the delta is
    // what `billing:reconcile` reports and what an operator is asked to act on.
    expect($after['ar']['delta'])->toBe($before['ar']['delta']);
});

it('drops the written-off amount back out of the tie-out when the debt is recovered', function () {
    $svc = app(WriteOffInvoiceService::class);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    $before = app(BooksReconciliationService::class)->glTieOut();

    $writeOff = $svc->write($this->invoice, ['amount' => 5000, 'reason' => 'uneconomic_to_pursue']);
    $svc->reverse($writeOff);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // Reversal soft-deletes, so the sum must stop counting it — otherwise recovering a debt
    // would itself open a delta.
    expect($this->invoice->fresh()->writtenOffAmount())->toBe(0.0)
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])
        ->toBe($before['ar']['delta']);
});

it('excludes a written-off invoice from the payment allocation picker query', function () {
    app(WriteOffInvoiceService::class)->write($this->invoice, [
        'amount' => 20000, 'reason' => 'tenant_absconded',
    ]);

    // The picker's predicate, asserted directly — `callAction()` would prove nothing here.
    $offered = Invoice::query()
        ->where('tenant_id', $this->invoice->tenant_id)
        ->where('balance', '>', 0)
        ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
        ->pluck('id');

    // balance is deliberately still 20,000 — status is the only thing that says "not collectable".
    expect((float) $this->invoice->fresh()->balance)->toBe(20000.0)
        ->and($offered)->not->toContain($this->invoice->id);
});
