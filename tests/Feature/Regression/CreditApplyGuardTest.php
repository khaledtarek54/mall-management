<?php

use App\Models\CreditNote;
use App\Services\CreditNoteService;

/**
 * A credit note must only be applied to a live, payable invoice. Applying it to
 * a cancelled / disputed / draft invoice would consume the credit's balance
 * against a row that isn't collecting — silently leaking the credit.
 */
it('refuses to apply a credit to a non-payable invoice (no leak)', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant)); // issued, balance > 0
    // Disputed keeps the balance but is not payable.
    $invoice->forceFill(['status' => 'disputed'])->saveQuietly();

    $note = CreditNote::create([
        'number' => 'CN-'.uniqid(), 'tenant_id' => $tenant->id, 'status' => 'issued',
        'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'applied_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);

    $applied = app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    expect($applied)->toBe(0.0)
        ->and($note->fresh()->balance)->toEqual(1000.0)        // credit untouched
        ->and($note->fresh()->status)->toBe('issued')
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0);
});

it('still applies a credit to a live issued invoice', function () {
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant)); // issued

    $note = CreditNote::create([
        'number' => 'CN-'.uniqid(), 'tenant_id' => $tenant->id, 'status' => 'issued',
        'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP',
    ]);

    $applied = app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    expect($applied)->toBeGreaterThan(0.0)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBeGreaterThan(0.0);
});
