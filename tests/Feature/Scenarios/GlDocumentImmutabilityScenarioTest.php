<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\VoidInvoiceService;
use App\Services\VoidPaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * GL integrity hardening (Phases 0–5) — DOCUMENT IMMUTABILITY & THE VOID CORRECTION PATH.
 *
 * Broad, cross-cutting coverage of the "you don't edit a posted money document, you void
 * and re-issue it" contract: model-layer immutability guards, the end-to-end void →
 * AR-reversal → GL-void correction flow, the state gates that block an unsafe void, and the
 * RBAC/state-transition surface on the Filament edit-page void actions.
 *
 * The narrow per-fix regression guards (FinalizedDocumentLockTest, VoidActionsTest) already
 * pin the primitives — this file exercises the WHOLE flow and the boundaries around it.
 */

/** Post the ledger the way the test env demands (real command; realtime sync is OFF). */
function syncLedger(): void
{
    test()->artisan('accounting:sync-ledger --all')->assertSuccessful();
}

/**
 * A balanced, GL-postable issued invoice (total == its single item, VAT 0).
 *
 * The line is derived from the header override rather than hard-coded, because the header is
 * now derived from the LINE (Invoice::syncTotalsFromItems) — hard-coding 10,000 here and then
 * overriding the header to 8,000 produced a fixture that contradicted itself, and the model
 * quite rightly resolved it in the items' favour.
 */
function postableInvoice(array $overrides = [])
{
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, array_merge([
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ], $overrides));
    $amount = round((float) $invoice->subtotal, 2);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    return $invoice->refresh();
}

function capturedPayment(int $tenantId, float $amount)
{
    return Payment::create([
        'reference' => 'P-'.uniqid(), 'tenant_id' => $tenantId, 'amount' => $amount,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

// ---------------------------------------------------------------------------
// (1) Model-layer immutability guards — beyond the fields the regression pins.
// ---------------------------------------------------------------------------

it('freezes a finalized invoice\'s lease (AR dimension) yet allows a forward status transition', function () {
    $invoice = postableInvoice(); // issued
    $otherLease = makeLease(makeUnit(makeAsset()));

    // lease_id is a GL/AR dimension — immutable once finalized (regression pins issue_date +
    // tenant_id; lease_id is the third guarded field and is exercised here end-to-end).
    expect(fn () => $invoice->update(['lease_id' => $otherLease->id]))
        ->toThrow(DomainException::class);
    expect($invoice->fresh()->lease_id)->not->toBe($otherLease->id);

    // A forward workflow transition on the SAME document is legitimate and must pass.
    $invoice->fresh()->update(['status' => 'disputed']);
    expect($invoice->fresh()->status)->toBe('disputed');
});

it('lets a draft invoice be freely re-homed and then finalized (the guard is finalized-only)', function () {
    $draft = makeInvoice(makeLease(makeUnit(makeAsset())), ['status' => 'draft']);
    $newLease = makeLease(makeUnit(makeAsset()));

    // Draft is a scratch document — re-homing + a later issue_date edit are both allowed,
    // and draft → issued must not trip the immutability guard.
    $draft->update(['lease_id' => $newLease->id, 'issue_date' => now()->subMonth()->toDateString()]);
    expect($draft->fresh()->lease_id)->toBe($newLease->id);

    $draft->fresh()->update(['status' => 'issued']);
    expect($draft->fresh()->status)->toBe('issued');
});

it('locks a captured payment\'s amount but never blocks the initiated→captured capture', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $payment = Payment::create([
        'reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 4000,
        'method' => 'card', 'status' => 'initiated', 'payment_date' => now()->toDateString(),
    ]);

    // The gateway capture (initiated→captured) writes status; the guard keys off the ORIGINAL
    // status so this can never be mistaken for an edit-after-capture.
    $payment->update(['status' => 'captured']);
    expect($payment->fresh()->status)->toBe('captured');

    // Now the cash is immutable — amount and payment_date are frozen.
    expect(fn () => $payment->fresh()->update(['amount' => 9999]))->toThrow(DomainException::class);
    expect((float) $payment->fresh()->amount)->toBe(4000.0);

    // …but a captured→failed chargeback (a status-only forward move) is still allowed.
    $payment->fresh()->update(['status' => 'failed']);
    expect($payment->fresh()->status)->toBe('failed');
});

// ---------------------------------------------------------------------------
// (2) The void CORRECTION path — full flow: AR reverses AND the GL entry voids.
// ---------------------------------------------------------------------------

it('voids an issued invoice end-to-end: AR leaves the books and the sweep voids its GL entry', function () {
    $invoice = postableInvoice();
    syncLedger();

    // Baseline: exactly one posted entry, books balanced.
    expect(JournalEntry::where('source_id', $invoice->id)
        ->where('source_type', $invoice->getMorphClass())->where('status', 'posted')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();

    app(VoidInvoiceService::class)->void($invoice, 'duplicate of last month');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->notes)->toContain('duplicate of last month');

    // The journalizer returns no effect for a cancelled invoice → the sweep VOIDS the entry,
    // and the trial balance stays balanced (the reversal is symmetric).
    syncLedger();
    expect(JournalEntry::where('source_id', $invoice->id)
        ->where('source_type', $invoice->getMorphClass())->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('voids a captured payment end-to-end: the invoice AR re-opens and its GL leg voids', function () {
    $invoice = postableInvoice();
    $payment = capturedPayment($invoice->tenant_id, 10000);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 10000]);
    $invoice->recomputeTotals(); // paid 10000 → balance 0, status paid
    syncLedger();

    expect((float) $invoice->fresh()->balance)->toBe(0.0);
    expect(JournalEntry::where('source_id', $payment->id)
        ->where('source_type', $payment->getMorphClass())->where('status', 'posted')->count())->toBe(1);

    app(VoidPaymentService::class)->void($payment, 'bounced cheque');

    // Refunded receipt → recomputeTotals sums only CAPTURED payments, so AR re-opens in full.
    expect($payment->fresh()->status)->toBe('refunded')
        ->and((float) $invoice->fresh()->balance)->toBe(10000.0)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(0.0);

    // The payment journalizer returns no effect for a non-captured payment → the sweep voids
    // the Dr Bank / Cr AR leg; the invoice's own AR entry stays posted (still a live receivable).
    syncLedger();
    expect(JournalEntry::where('source_id', $payment->id)
        ->where('source_type', $payment->getMorphClass())->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('supports the void-then-re-issue correction: a fresh invoice posts a NEW, balanced entry', function () {
    $wrong = postableInvoice(['subtotal' => 8000, 'total' => 8000, 'balance' => 8000]); // header == its line
    syncLedger();

    app(VoidInvoiceService::class)->void($wrong, 'wrong amount — re-issuing');
    expect($wrong->fresh()->status)->toBe('cancelled');

    // Re-issue at the correct figure on the SAME lease (the supported correction, not an edit).
    $reissued = makeInvoice($wrong->lease, [
        'issue_date' => now()->toDateString(),
        'subtotal' => 12000, 'vat_amount' => 0, 'total' => 12000, 'balance' => 12000,
    ]);
    $reissued->items()->create([
        'type' => 'base_rent', 'description' => 'Rent (corrected)',
        'amount' => 12000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 12000,
    ]);

    syncLedger();

    // Old entry voided, new entry posted, books balanced — the correction left no orphan AR.
    expect(JournalEntry::where('source_id', $wrong->id)->where('status', 'void')->count())->toBe(1);
    expect(JournalEntry::where('source_id', $reissued->id)->where('status', 'posted')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// (3) The state gates that BLOCK an unsafe void (each is a distinct DomainException).
// ---------------------------------------------------------------------------

it('blocks the wrong voids: draft invoice, ETA-filed invoice, captured-cash invoice, non-captured payment', function () {
    // (a) A draft is deleted, not voided.
    $draft = makeInvoice(makeLease(makeUnit(makeAsset())), ['status' => 'draft']);
    expect(fn () => app(VoidInvoiceService::class)->void($draft))->toThrow(DomainException::class);

    // (b) A tax invoice already filed with ETA must be corrected via a credit note, not an
    // internal void (that would diverge the books from what ETA holds).
    $filed = postableInvoice();
    $filed->forceFill(['eta_status' => 'valid'])->saveQuietly();
    expect(fn () => app(VoidInvoiceService::class)->void($filed->fresh()))->toThrow(DomainException::class);
    expect($filed->fresh()->status)->not->toBe('cancelled');

    // (c) An invoice carrying captured CASH — refund the payment first.
    $withCash = postableInvoice();
    $pay = capturedPayment($withCash->tenant_id, 10000);
    $pay->invoices()->attach($withCash->id, ['allocated_amount' => 10000]);
    $withCash->recomputeTotals();
    expect(fn () => app(VoidInvoiceService::class)->void($withCash->fresh()))->toThrow(DomainException::class);
    expect($withCash->fresh()->status)->not->toBe('cancelled');

    // (d) VoidPaymentService only reverses a CAPTURED payment — an initiated one can't be voided.
    $lease = makeLease(makeUnit(makeAsset()));
    $initiated = Payment::create([
        'reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 500,
        'method' => 'card', 'status' => 'initiated', 'payment_date' => now()->toDateString(),
    ]);
    expect(fn () => app(VoidPaymentService::class)->void($initiated))->toThrow(DomainException::class);
    expect($initiated->fresh()->status)->toBe('initiated');
});

it('is idempotent — voiding an already-cancelled invoice / refunded payment is a no-op, not a throw', function () {
    $invoice = postableInvoice();
    app(VoidInvoiceService::class)->void($invoice, 'first void');
    // Second call on the terminal record returns quietly (already off the books).
    app(VoidInvoiceService::class)->void($invoice->fresh());
    expect($invoice->fresh()->status)->toBe('cancelled');

    $lease = makeLease(makeUnit(makeAsset()));
    $payment = capturedPayment($lease->tenant_id, 300);
    app(VoidPaymentService::class)->void($payment, 'first refund');
    app(VoidPaymentService::class)->void($payment->fresh()); // no-op
    expect($payment->fresh()->status)->toBe('refunded');
});

// ---------------------------------------------------------------------------
// (4) RBAC / state-transition on the Filament edit-page void actions.
// ---------------------------------------------------------------------------

describe('void actions on the Filament edit pages', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->asset = makeAsset();
        // setTenant() fires TenantSet(panel, user) — there must be an authed user first, so
        // establish one here; each test re-acts as the role it needs (that doesn't re-fire it).
        $this->actingAs(makeUser('super_admin'));
        Filament::setTenant($this->asset);
    });

    afterEach(fn () => Filament::setTenant(null, isQuiet: true));

    it('a super_admin sees void_invoice on an eligible invoice and can void it with a reason', function () {
        $this->actingAs(makeUser('super_admin'));
        $invoice = makeInvoice(makeLease(makeUnit($this->asset)), ['total' => 5000, 'balance' => 5000]);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('void_invoice')
            ->callAction('void_invoice', ['reason' => 'billed in error'])
            ->assertHasNoActionErrors();

        expect($invoice->fresh()->status)->toBe('cancelled')
            ->and($invoice->fresh()->notes)->toContain('billed in error');
    });

    it('hides void_invoice from a state that is ineligible: a draft and a captured-cash invoice', function () {
        $this->actingAs(makeUser('super_admin')); // full permission — hidden purely by RECORD STATE

        // A draft has no GL entry to reverse — void is for issued documents only.
        $draft = makeInvoice(makeLease(makeUnit($this->asset)), ['status' => 'draft', 'total' => 5000, 'balance' => 5000]);
        Livewire::test(EditInvoice::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('void_invoice');

        // An invoice with captured cash must have the payment refunded first — void is suppressed.
        $lease = makeLease(makeUnit($this->asset));
        $withCash = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
        $pay = capturedPayment($lease->tenant_id, 5000);
        $pay->invoices()->attach($withCash->id, ['allocated_amount' => 5000]);
        $withCash->recomputeTotals();
        Livewire::test(EditInvoice::class, ['record' => $withCash->fresh()->getRouteKey()])
            ->assertActionHidden('void_invoice');
    });

    it('a super_admin sees void_payment on a captured payment but not on a refunded one', function () {
        $this->actingAs(makeUser('super_admin'));
        $lease = makeLease(makeUnit($this->asset));
        $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);

        $captured = capturedPayment($lease->tenant_id, 5000);
        $captured->invoices()->attach($invoice->id, ['allocated_amount' => 5000]); // brings it into scope
        Livewire::test(EditPayment::class, ['record' => $captured->getRouteKey()])
            ->assertActionVisible('void_payment')
            ->callAction('void_payment', ['reason' => 'refunded to card'])
            ->assertHasNoActionErrors();
        expect($captured->fresh()->status)->toBe('refunded');

        // Now refunded → the action is gone (nothing left to reverse).
        Livewire::test(EditPayment::class, ['record' => $captured->fresh()->getRouteKey()])
            ->assertActionHidden('void_payment');
    });

    it('a viewer (no invoices.edit / payments.edit) is 403-blocked from the edit pages that host the void actions', function () {
        // The void actions are gated on {module}.edit — the SAME permission that gates the edit
        // page itself. A read-only viewer can never reach the void action: the page 403s first.
        $this->actingAs(makeUser('viewer'));
        $lease = makeLease(makeUnit($this->asset));
        $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
        $payment = capturedPayment($lease->tenant_id, 5000);
        $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->assertForbidden();
        Livewire::test(EditPayment::class, ['record' => $payment->getRouteKey()])->assertForbidden();
    });
});
