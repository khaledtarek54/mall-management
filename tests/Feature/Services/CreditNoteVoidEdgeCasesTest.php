<?php

/*
|--------------------------------------------------------------------------
| CREDIT NOTES — void/apply EDGE cases not covered elsewhere
|--------------------------------------------------------------------------
| Complements tests/Feature/CreditNoteServiceTest.php and
| tests/Feature/Scenarios/CreditNoteScenarioTest.php. Those already cover the
| happy paths, idempotency, the apply caps, the draft/voided apply guards and
| the bare "void throws when applied" assertion.
|
| What is genuinely UNCOVERED and asserted here:
|  - VOID-OF-APPLIED is BLOCKED (the real v1 rule) AND the throw leaves BOTH
|    sides untouched: the note keeps its issued/applied state + applied_amount,
|    and the invoice keeps its credit_applied_amount / paid_amount / balance
|    (Invoice::recomputeTotals shows NO reversal — you offset, you don't void).
|  - the partial-apply variant of the same: a partially-applied note still
|    refuses to void and the invoice's partial settlement is preserved.
|  - void() early-returns (no exception) for an already-void note even if a
|    DomainException path exists — order-of-guards check.
|  - applyToInvoice() on a FULLY-DRAINED 'applied' note (balance 0) returns 0
|    and touches nothing (hasBalance() guard via the balance>0 leg).
|  - issue() of an exactly-zero note never produces an apply-able note.
|
| Service: app/Services/CreditNoteService.php — void() throws DomainException
| when applied_amount > 0; the single AR source of truth is
| Invoice::recomputeTotals (paid = captured payments + credit_applied_amount).
*/

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Services\CreditNoteService;

/** Minimal property→unit→tenant→lease→invoice + a DRAFT credit note. */
function cnEdgeFixtures(float $invoiceTotal = 10000, float $noteTotal = 3000): array
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, [
        'subtotal' => $invoiceTotal, 'vat_amount' => 0,
        'total' => $invoiceTotal, 'paid_amount' => 0, 'balance' => $invoiceTotal,
    ]);
    $note = cnEdgeDraft($tenant->id, $invoice->id, $noteTotal, $lease->id);

    return compact('asset', 'unit', 'tenant', 'lease', 'invoice', 'note');
}

function cnEdgeDraft(int $tenantId, ?int $invoiceId, float $total, ?int $leaseId = null): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => $tenantId,
        'invoice_id' => $invoiceId,
        'lease_id' => $leaseId,
        'status' => 'draft',
        'issue_date' => '2026-02-15',
        'reason' => 'adjustment',
        'subtotal' => $total, 'vat_amount' => 0,
        'total' => $total, 'applied_amount' => 0, 'balance' => $total,
        'currency' => 'EGP',
    ]);
    CreditNoteItem::create([
        'credit_note_id' => $note->id,
        'description' => 'Adjustment',
        'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
    ]);

    return $note->refresh();
}

function cnEdgeSvc(): CreditNoteService
{
    return app(CreditNoteService::class);
}

// ============================================================
// VOID-OF-APPLIED is blocked AND leaves both sides untouched
// ============================================================

it('void() throws on a FULLY-applied note and leaves the invoice settlement intact (no reversal)', function () {
    ['note' => $note, 'invoice' => $invoice] = cnEdgeFixtures(invoiceTotal: 10000, noteTotal: 2000);
    $svc = cnEdgeSvc();
    $note = $svc->issue($note);

    // Drain the whole note onto the invoice → note flips to 'applied'.
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 2000);

    $invoiceBefore = $invoice->fresh();
    expect($invoiceBefore->status)->toBe('partially_paid')
        ->and((float) $invoiceBefore->credit_applied_amount)->toBe(2000.0)
        ->and((float) $invoiceBefore->paid_amount)->toBe(2000.0)
        ->and((float) $invoiceBefore->balance)->toBe(8000.0)
        ->and($note->fresh()->status)->toBe('applied');

    // The rule: you cannot void an applied note — issue an offsetting note.
    expect(fn () => $svc->void($note->fresh()))
        ->toThrow(\DomainException::class, 'Cannot void a credit note that has already been applied');

    // recomputeTotals reflects NO reversal — the credit still settles the AR.
    $invoice->refresh();
    $invoice->recomputeTotals();
    $invoiceAfter = $invoice->fresh();
    expect((float) $invoiceAfter->credit_applied_amount)->toBe(2000.0)
        ->and((float) $invoiceAfter->paid_amount)->toBe(2000.0)
        ->and((float) $invoiceAfter->balance)->toBe(8000.0)
        ->and($invoiceAfter->status)->toBe('partially_paid');
});

it('void() throws on a PARTIALLY-applied note and the partial settlement is preserved', function () {
    ['note' => $note, 'invoice' => $invoice] = cnEdgeFixtures(invoiceTotal: 10000, noteTotal: 3000);
    $svc = cnEdgeSvc();
    $note = $svc->issue($note);

    // Apply only part of the note — it stays 'issued' with a residual balance.
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 1200);
    expect($note->fresh()->status)->toBe('issued')
        ->and((float) $note->fresh()->applied_amount)->toBe(1200.0)
        ->and((float) $note->fresh()->balance)->toBe(1800.0);

    expect(fn () => $svc->void($note->fresh()))->toThrow(\DomainException::class);

    // Both sides are exactly as they were before the failed void.
    $noteAfter = $note->fresh();
    expect($noteAfter->status)->toBe('issued')
        ->and((float) $noteAfter->applied_amount)->toBe(1200.0)
        ->and((float) $noteAfter->balance)->toBe(1800.0)
        ->and($noteAfter->voided_at)->toBeNull();

    $invoiceAfter = $invoice->fresh();
    expect((float) $invoiceAfter->credit_applied_amount)->toBe(1200.0)
        ->and((float) $invoiceAfter->paid_amount)->toBe(1200.0)
        ->and((float) $invoiceAfter->balance)->toBe(8800.0)
        ->and($invoiceAfter->status)->toBe('partially_paid');
});

it('the failed void of an applied note does not stamp voided_at or change status', function () {
    ['note' => $note, 'invoice' => $invoice] = cnEdgeFixtures(invoiceTotal: 5000, noteTotal: 1000);
    $svc = cnEdgeSvc();
    $note = $svc->issue($note);
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 1000); // fully applied

    try {
        $svc->void($note->fresh(), 'attempted void');
    } catch (\DomainException) {
        // expected
    }

    $after = $note->fresh();
    expect($after->status)->toBe('applied')
        ->and($after->voided_at)->toBeNull()
        ->and((float) $after->balance)->toBe(0.0)
        ->and((string) $after->notes)->not->toContain('attempted void');
});

// ============================================================
// void() guard ORDER — already-void short-circuits before applied-check
// ============================================================

it('void() on an already-void note returns it untouched without throwing', function () {
    ['note' => $note] = cnEdgeFixtures(noteTotal: 2000);
    $svc = cnEdgeSvc();
    $voided = $svc->void($svc->issue($note)->fresh());
    $stamp = $voided->voided_at;
    expect($voided->status)->toBe('void');

    // No exception, no re-stamp — the status==='void' early return wins.
    $again = $svc->void($voided->fresh(), 'second attempt');

    expect($again->status)->toBe('void')
        ->and($again->voided_at->equalTo($stamp))->toBeTrue()
        ->and((string) $again->notes)->not->toContain('second attempt');
});

// ============================================================
// applyToInvoice() on a fully-drained 'applied' note is a no-op
// ============================================================

it('applying a FULLY-drained applied note (balance 0) returns 0 and touches nothing', function () {
    ['note' => $note, 'invoice' => $invoice] = cnEdgeFixtures(invoiceTotal: 10000, noteTotal: 2000);
    $svc = cnEdgeSvc();
    $note = $svc->issue($note);

    // Drain the note completely against a first slice of the invoice balance.
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 2000);
    expect($note->fresh()->status)->toBe('applied')
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and($note->fresh()->hasBalance())->toBeFalse(); // balance>0 leg fails

    $invoiceBefore = $invoice->fresh();

    // Try to apply the (now empty) note again — the hasBalance() guard returns 0.
    $applied = $svc->applyToInvoice($note->fresh(), $invoice->fresh());

    expect($applied)->toBe(0.0)
        ->and((float) $note->fresh()->applied_amount)->toBe(2000.0)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe((float) $invoiceBefore->credit_applied_amount)
        ->and((float) $invoice->fresh()->balance)->toBe((float) $invoiceBefore->balance)
        ->and($invoice->fresh()->status)->toBe($invoiceBefore->status);
});

// ============================================================
// issuing a zero-total note never yields an apply-able note
// ============================================================

it('a zero-total note issues straight to applied and applyToInvoice is a no-op', function () {
    ['tenant' => $tenant, 'invoice' => $invoice] = cnEdgeFixtures();
    $svc = cnEdgeSvc();
    $zero = cnEdgeDraft($tenant->id, $invoice->id, 0.0);

    $issued = $svc->issue($zero);
    expect($issued->status)->toBe('applied')
        ->and((float) $issued->balance)->toBe(0.0)
        ->and($issued->hasBalance())->toBeFalse();

    $applied = $svc->applyToInvoice($issued->fresh(), $invoice->fresh());

    expect($applied)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(10000.0)
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0);
});
