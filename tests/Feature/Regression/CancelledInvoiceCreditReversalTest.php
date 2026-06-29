<?php

use App\Models\CreditNote;
use App\Services\CreditNoteService;

/**
 * Regression (HIGH / money, hardening backlog H1): cancelling an invoice that
 * had credit applied used to LOSE that credit — credit_applied_amount was never
 * returned to the tenant. Now an offsetting credit note is issued so the
 * tenant's net credit is preserved.
 */
it('returns consumed credit when a credited invoice is cancelled (no leak)', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease); // issued, balance 11400

    $note = CreditNote::create([
        'number' => 'CN-' . uniqid(), 'tenant_id' => $tenant->id, 'lease_id' => $lease->id,
        'status' => 'issued', 'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'applied_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());
    expect((float) $invoice->fresh()->credit_applied_amount)->toBe(5000.0);

    $notesBefore = CreditNote::where('tenant_id', $tenant->id)->count();

    $invoice->fresh()->update(['status' => 'cancelled']);

    // An offsetting note was issued + the invoice's applied credit zeroed.
    expect(CreditNote::where('tenant_id', $tenant->id)->count())->toBe($notesBefore + 1)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0);

    // The 5000 consumed credit is back on a fresh issued note (net preserved).
    $offsetting = CreditNote::where('tenant_id', $tenant->id)->latest('id')->first();
    expect($offsetting->status)->toBe('issued')
        ->and((float) $offsetting->balance)->toBe(5000.0);
});

it('does NOT reverse credit when an invoice is marked credited (settlement stays consumed)', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease);

    $note = CreditNote::create([
        'number' => 'CN-' . uniqid(), 'tenant_id' => $tenant->id, 'lease_id' => $lease->id,
        'status' => 'issued', 'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'applied_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice->fresh());

    $notesBefore = CreditNote::where('tenant_id', $tenant->id)->count();

    // 'credited' is the paid-by-credit terminal state — the credit is the
    // intended settlement; reversing it here would double-refund the tenant.
    $invoice->fresh()->update(['status' => 'credited']);

    expect(CreditNote::where('tenant_id', $tenant->id)->count())->toBe($notesBefore)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(5000.0);
});
