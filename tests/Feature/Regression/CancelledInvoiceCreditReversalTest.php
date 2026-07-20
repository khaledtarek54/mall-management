<?php

use App\Models\CreditNote;
use App\Services\CreditNoteService;

/**
 * Regression (HIGH / money): cancelling an invoice that had credit applied must return that credit
 * to the tenant WITHOUT double-counting the sales-return. The original design issued a SECOND
 * offsetting note, so both it and the still-`applied` original posted Dr Sales Returns / Cr AR —
 * doubling the return and driving AR negative. Now the original application is UN-APPLIED: its note
 * is restored to available (its single, original GL entry now correctly represents the returned
 * credit), the invoice's applied credit is zeroed, and NO second note is created.
 */
use App\Models\CreditNoteApplication;

it('un-applies consumed credit when a credited invoice is cancelled (no second note, no double-count)', function () {
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
    expect((float) $invoice->fresh()->credit_applied_amount)->toBe(5000.0)
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(1);

    $notesBefore = CreditNote::where('tenant_id', $tenant->id)->count();

    $invoice->fresh()->update(['status' => 'cancelled']);

    // No SECOND note — the SAME note is restored to available, and the invoice's credit is zeroed.
    expect(CreditNote::where('tenant_id', $tenant->id)->count())->toBe($notesBefore)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0);

    $note->refresh();
    expect($note->status)->toBe('issued')
        ->and((float) $note->balance)->toBe(5000.0)       // 5000 credit back on the ORIGINAL note
        ->and((float) $note->applied_amount)->toBe(0.0)
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(0); // application reversed
});

it('un-applies credit + zeros balance when cancelling the SAME (stale) in-memory instance', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease); // balance 11400

    $note = CreditNote::create([
        'number' => 'CN-' . uniqid(), 'tenant_id' => $tenant->id, 'lease_id' => $lease->id,
        'status' => 'issued', 'issue_date' => now(), 'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'applied_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
    // applyToInvoice mutates a SEPARATELY-locked copy → $invoice in-memory stays stale (credit=0).
    app(CreditNoteService::class)->applyToInvoice($note, $invoice);

    $notesBefore = CreditNote::where('tenant_id', $tenant->id)->count();

    // Cancel the SAME stale instance — the un-apply must still fire (reads the DB).
    $invoice->update(['status' => 'cancelled']);

    expect(CreditNote::where('tenant_id', $tenant->id)->count())->toBe($notesBefore) // no offsetting note
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0) // not a phantom 11400
        ->and((float) $note->fresh()->balance)->toBe(5000.0); // credit returned to the original note
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
