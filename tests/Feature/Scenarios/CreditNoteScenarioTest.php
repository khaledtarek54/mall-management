<?php

/*
|--------------------------------------------------------------------------
| CREDIT NOTES — net-new lifecycle scenarios
|--------------------------------------------------------------------------
| Complements tests/Feature/CreditNoteServiceTest.php (which covers: issue
| draft->issued + balance; single apply reduces both balances; apply caps at
| invoice balance; apply==0 when voided; void throws when applied; fully-
| applied flips to 'applied').
|
| NET-NEW coverage here, by case class:
|
|  STATE-TRANSITION — issue idempotency (issued/applied returned untouched);
|       issue of a zero-total note flips straight to 'applied'; void of an
|       unapplied note zeroes balance + stamps voided_at + flips status;
|       void is idempotent; applied_at is stamped ONCE and never moved.
|  HAPPY            — two sequential partial applies accumulate then flip to
|       'applied'; one note drained across TWO invoices of the same tenant.
|  NEGATIVE         — cannot apply a DRAFT note (status guard); cannot apply a
|       VOIDED note; apply of 0 / negative requestedAmount is a no-op; apply
|       to a fully-paid (balance 0) invoice is a no-op.
|  BOUNDARY         — over-apply: requestedAmount far above both balances caps
|       at min(note, invoice); exact-to-the-cent drain leaves balance 0;
|       applying exactly the invoice balance flips the invoice to 'paid'.
|  SCOPING          — CreditNoteResource per-property query: a lease-linked
|       note is visible only under its own asset tenant; a STANDALONE
|       (lease-less) note is visible under every property.
|  NUMBERING        — generateNumber prefix + zero-padded monthly sequence.
|
| The service is app/Services/CreditNoteService.php; model App\Models\CreditNote
| (hasBalance() = balance>0 AND status in [issued, applied]).
*/

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Services\CreditNoteService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Minimal property -> unit -> tenant -> lease -> invoice chain, plus a draft
 * credit note attached to that invoice/tenant. Returns a bag keyed by name.
 */
function cnFixtures(float $invoiceTotal = 10000, float $noteTotal = 3000, array $assetAttrs = []): array
{
    $asset = makeAsset($assetAttrs);
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    $lease = makeLease($unit, $tenant);
    $invoice = makeInvoice($lease, [
        'subtotal' => $invoiceTotal, 'vat_amount' => 0,
        'total' => $invoiceTotal, 'paid_amount' => 0, 'balance' => $invoiceTotal,
    ]);
    $note = cnDraft($tenant->id, $invoice->id, $noteTotal, $lease->id);

    return compact('asset', 'unit', 'tenant', 'lease', 'invoice', 'note');
}

/** Create a DRAFT credit note (+ a single matching item) and return it fresh. */
function cnDraft(int $tenantId, ?int $invoiceId, float $total, ?int $leaseId = null): CreditNote
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

function cnSvc(): CreditNoteService
{
    return app(CreditNoteService::class);
}

// ============================================================
// STATE-TRANSITION — issue
// ============================================================

it('issue() is idempotent: an already-issued note is returned unchanged', function () {
    ['note' => $note] = cnFixtures(noteTotal: 3000);
    $issued = cnSvc()->issue($note);
    $updatedAt = $issued->updated_at;

    $again = cnSvc()->issue($issued->fresh());

    expect($again->status)->toBe('issued')
        ->and((float) $again->balance)->toBe(3000.0)
        // early-return branch never re-saves, so updated_at is untouched.
        ->and($again->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('issue() is idempotent on an already-applied note', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(noteTotal: 2000);
    $svc = cnSvc();
    $svc->applyToInvoice($svc->issue($note)->fresh(), $invoice->fresh(), 2000);
    expect($note->fresh()->status)->toBe('applied');

    $again = $svc->issue($note->fresh());

    expect($again->status)->toBe('applied')
        ->and((float) $again->balance)->toBe(0.0);
});

it('issuing a zero-total note flips straight to applied (no balance to apply)', function () {
    ['tenant' => $tenant, 'invoice' => $i] = cnFixtures(noteTotal: 3000);
    $zero = cnDraft($tenant->id, $i->id, 0.0);

    $issued = cnSvc()->issue($zero);

    expect($issued->status)->toBe('applied')
        ->and((float) $issued->balance)->toBe(0.0);
});

// ============================================================
// STATE-TRANSITION — void
// ============================================================

it('void() on an unapplied issued note zeroes the balance, stamps voided_at and flips status', function () {
    ['note' => $note] = cnFixtures(noteTotal: 2500);
    $issued = cnSvc()->issue($note);
    expect($issued->voided_at)->toBeNull();

    $voided = cnSvc()->void($issued->fresh(), 'tenant disputed the charge');

    expect($voided->status)->toBe('void')
        ->and((float) $voided->balance)->toBe(0.0)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->notes)->toContain('Voided: tenant disputed the charge');
});

it('void() is idempotent: voiding an already-void note is a no-op', function () {
    ['note' => $note] = cnFixtures(noteTotal: 2500);
    $voided = cnSvc()->void(cnSvc()->issue($note)->fresh());
    $stamp = $voided->voided_at;

    $again = cnSvc()->void($voided->fresh());

    expect($again->status)->toBe('void')
        ->and($again->voided_at->equalTo($stamp))->toBeTrue();
});

it('can void a DRAFT note directly (no application has happened)', function () {
    ['note' => $draft] = cnFixtures(noteTotal: 1000);
    expect($draft->status)->toBe('draft');

    $voided = cnSvc()->void($draft);

    expect($voided->status)->toBe('void')
        ->and((float) $voided->balance)->toBe(0.0);
});

// ============================================================
// HAPPY — multi-apply accumulation + cross-invoice drain
// ============================================================

it('accumulates two sequential partial applies, then flips to applied when fully consumed', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 3000);
    $svc = cnSvc();
    $note = $svc->issue($note);

    $first = $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 1000);
    expect($first)->toBe(1000.0)
        ->and((float) $note->fresh()->applied_amount)->toBe(1000.0)
        ->and((float) $note->fresh()->balance)->toBe(2000.0)
        ->and($note->fresh()->status)->toBe('issued');

    $second = $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 2000);
    expect($second)->toBe(2000.0)
        ->and((float) $note->fresh()->applied_amount)->toBe(3000.0)
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and($note->fresh()->status)->toBe('applied');

    // Invoice absorbed the full 3000 across both applies.
    expect((float) $invoice->fresh()->paid_amount)->toBe(3000.0)
        ->and((float) $invoice->fresh()->balance)->toBe(7000.0)
        ->and($invoice->fresh()->status)->toBe('partially_paid');
});

it('applied_at is stamped on the first apply and never moved by a later apply', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 3000);
    $svc = cnSvc();
    $note = $svc->issue($note);

    // Freeze, apply, then advance the clock so a (buggy) re-stamp would differ.
    Carbon::setTestNow('2026-03-01 09:00:00');
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 1000);
    $firstStamp = $note->fresh()->applied_at;
    expect($firstStamp)->not->toBeNull();

    Carbon::setTestNow('2026-03-02 18:30:00'); // a day+ later
    $svc->applyToInvoice($note->fresh(), $invoice->fresh(), 1000);

    expect($note->fresh()->applied_at->equalTo($firstStamp))->toBeTrue()
        ->and($note->fresh()->applied_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 09:00:00');

    Carbon::setTestNow();
});

it('drains one credit note across two invoices of the same tenant', function () {
    ['tenant' => $t, 'lease' => $lease, 'invoice' => $inv1, 'note' => $note] =
        cnFixtures(invoiceTotal: 2000, noteTotal: 5000);

    // A second invoice on the same lease/tenant.
    $inv2 = makeInvoice($lease, [
        'subtotal' => 4000, 'vat_amount' => 0,
        'total' => 4000, 'paid_amount' => 0, 'balance' => 4000,
    ]);

    $svc = cnSvc();
    $note = $svc->issue($note);

    // First invoice (2000) is fully covered; 3000 credit remains.
    $a1 = $svc->applyToInvoice($note->fresh(), $inv1->fresh());
    expect($a1)->toBe(2000.0)
        ->and($inv1->fresh()->status)->toBe('paid')
        ->and((float) $note->fresh()->balance)->toBe(3000.0)
        ->and($note->fresh()->status)->toBe('issued');

    // Remaining 3000 applied to the 4000 invoice -> 1000 still owed.
    $a2 = $svc->applyToInvoice($note->fresh(), $inv2->fresh());
    expect($a2)->toBe(3000.0)
        ->and((float) $inv2->fresh()->balance)->toBe(1000.0)
        ->and($inv2->fresh()->status)->toBe('partially_paid')
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and($note->fresh()->status)->toBe('applied');
});

// ============================================================
// NEGATIVE — invalid source/target states
// ============================================================

it('cannot apply a DRAFT note: status guard blocks it, invoice untouched', function () {
    ['note' => $draft, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 3000);
    expect($draft->status)->toBe('draft')
        ->and($draft->hasBalance())->toBeFalse(); // balance>0 but status not issued/applied

    $applied = cnSvc()->applyToInvoice($draft, $invoice->fresh(), 1000);

    expect($applied)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(10000.0)
        ->and((float) $draft->fresh()->applied_amount)->toBe(0.0)
        ->and($draft->fresh()->status)->toBe('draft');
});

it('cannot apply a VOIDED note: returns 0 and leaves the invoice untouched', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 2000);
    $svc = cnSvc();
    $voided = $svc->void($svc->issue($note)->fresh());

    $applied = $svc->applyToInvoice($voided->fresh(), $invoice->fresh());

    expect($applied)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(10000.0)
        ->and($invoice->fresh()->status)->toBe('issued');
});

it('applying a non-positive requestedAmount is a no-op', function (float $req) {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 3000);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh(), $req);

    expect($applied)->toBe(0.0)
        ->and((float) $note->fresh()->applied_amount)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(10000.0)
        ->and($note->fresh()->status)->toBe('issued');
})->with([
    'zero' => [0.0],
    'negative' => [-500.0],
]);

it('applying to an already-paid invoice (balance 0) is a no-op, note balance preserved', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 3000);
    $invoice->update(['paid_amount' => 10000, 'balance' => 0, 'status' => 'paid']);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh());

    expect($applied)->toBe(0.0)
        ->and((float) $note->fresh()->balance)->toBe(3000.0)
        ->and($note->fresh()->status)->toBe('issued');
});

// ============================================================
// BOUNDARY — over-apply caps + exact drain
// ============================================================

it('over-apply: a requestedAmount far above both balances caps at min(note, invoice)', function () {
    // note 4000, invoice 2500 -> the cap is the invoice balance.
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 2500, noteTotal: 4000);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh(), 999999.0);

    expect($applied)->toBe(2500.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and((float) $note->fresh()->balance)->toBe(1500.0)
        ->and($note->fresh()->status)->toBe('issued');
});

it('over-apply where the NOTE is the smaller side caps at the note balance', function () {
    // note 1200, invoice 10000 -> the cap is the note balance.
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 10000, noteTotal: 1200);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh(), 999999.0);

    expect($applied)->toBe(1200.0)
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and($note->fresh()->status)->toBe('applied')
        ->and((float) $invoice->fresh()->balance)->toBe(8800.0)
        ->and($invoice->fresh()->status)->toBe('partially_paid');
});

it('applying exactly the invoice balance drains the invoice to paid with no residual', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 3000, noteTotal: 5000);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh(), 3000.0);

    expect($applied)->toBe(3000.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and((float) $note->fresh()->balance)->toBe(2000.0); // 5000 - 3000 left over
});

it('a cent-level apply drains exactly to zero (decimal:2 precision)', function () {
    ['note' => $note, 'invoice' => $invoice] = cnFixtures(invoiceTotal: 100.07, noteTotal: 100.07);
    $note = cnSvc()->issue($note);

    $applied = cnSvc()->applyToInvoice($note->fresh(), $invoice->fresh());

    expect($applied)->toBe(100.07)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and((float) $note->fresh()->balance)->toBe(0.0)
        ->and($note->fresh()->status)->toBe('applied')
        ->and($invoice->fresh()->status)->toBe('paid');
});

// ============================================================
// SCOPING — CreditNoteResource per-property query
// ============================================================

it('scopes a lease-linked credit note to its own property and hides the other property\'s note', function () {
    ensureAllPropertiesAsset();
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $aLease = makeLease(makeUnit($a));
    $bLease = makeLease(makeUnit($b));
    $aInvoice = makeInvoice($aLease, ['subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);
    $bInvoice = makeInvoice($bLease, ['subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000]);

    $aNote = cnDraft($aLease->tenant_id, $aInvoice->id, 500, $aLease->id);
    $bNote = cnDraft($bLease->tenant_id, $bInvoice->id, 500, $bLease->id);

    asTenant($a, function () use ($aNote, $bNote) {
        $ids = CreditNoteResource::getEloquentQuery()->pluck('id')->all();
        expect($ids)->toContain($aNote->id)
            ->and($ids)->not->toContain($bNote->id);
    });

    asTenant($b, function () use ($aNote, $bNote) {
        $ids = CreditNoteResource::getEloquentQuery()->pluck('id')->all();
        expect($ids)->toContain($bNote->id)
            ->and($ids)->not->toContain($aNote->id);
    });
});

it('scopes a STANDALONE (lease-less) credit note to the properties where its tenant leases', function () {
    ensureAllPropertiesAsset();
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    // A tenant who leases ONLY in property A, with a tenant-level (lease-less) credit note.
    $tenant = makeTenant();
    makeLease(makeUnit($a), $tenant);
    $standalone = cnDraft($tenant->id, null, 750, null);

    asTenant($a, function () use ($standalone) {
        // Visible under A — the tenant has a presence here.
        expect(CreditNoteResource::getEloquentQuery()->pluck('id')->all())->toContain($standalone->id);
    });
    asTenant($b, function () use ($standalone) {
        // NOT visible under B — the tenant doesn't lease here (this used to leak portfolio-wide,
        // exposing another property's tenant + credit amount and letting them void/issue it).
        expect(CreditNoteResource::getEloquentQuery()->pluck('id')->all())->not->toContain($standalone->id);
    });
});

// ============================================================
// NUMBERING — generated number prefix + monthly sequence
// ============================================================

it('generates sequential, zero-padded numbers within a property + month', function () {
    $asset = makeAsset(['code' => 'HW']);
    $lease = makeLease(makeUnit($asset));
    $tenant = $lease->tenant;

    $first = cnDraft($tenant->id, null, 100, $lease->id);
    $second = cnDraft($tenant->id, null, 100, $lease->id);

    expect($first->number)->toBe('CN-HW-202602-0001')
        ->and($second->number)->toBe('CN-HW-202602-0002');
});

it('falls back to the AW prefix when the note has no lease to derive an asset code', function () {
    $tenant = makeTenant();
    $note = cnDraft($tenant->id, null, 100, null);

    expect($note->number)->toBe('CN-AW-202602-0001');
});
