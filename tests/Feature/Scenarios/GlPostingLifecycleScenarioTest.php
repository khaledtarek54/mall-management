<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\CreditNoteService;
use App\Services\VoidInvoiceService;
use App\Services\VoidPaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * GL integrity hardening — SCENARIO coverage: the AR money-document lifecycle END-TO-END
 * through the general ledger. Each `it()` drives real documents (issue → capture → credit →
 * void) and re-runs the REAL sweep command after every money change, asserting the books stay
 * consistent at each step: the trial balance stays balanced, `billing:reconcile` passes, the
 * journal-entry statuses track the source's state, and the AR control ties back to zero.
 *
 * These are broad, cross-document flows — the narrow per-fix guards (windowed sweep, close
 * gate, void actions, realtime flag) live in tests/Feature/Regression and are NOT duplicated.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/** Post everything to the GL (realtime sync is OFF in tests — the sweep is the posting path). */
function sync(): void
{
    test()->artisan('accounting:sync-ledger --all')->assertSuccessful();
}

/** The current (posted, non-void) journal entry for a source document, with its lines. */
function entryFor(Model $source): ?JournalEntry
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'posted')
        ->latest('id')
        ->first()?->load('lines');
}

/** Count void journal entries for a source. */
function voidEntries(Model $source): int
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'void')
        ->count();
}

/** The net debit currently sitting in the Accounts-Receivable control account. */
function arControlNet(): float
{
    $ar = app(AccountResolver::class)->id('accounts_receivable');

    $row = DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->whereIn('je.status', ['posted', 'void'])
        ->whereNull('je.deleted_at')
        ->where('jl.ledger_account_id', $ar)
        ->selectRaw('COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) as net')
        ->first();

    return round((float) $row->net, 2);
}

/** A single-item, VAT-exempt invoice whose total equals its item (so the entry balances). */
function lifecycleInvoice(int $amount = 10000, ?Lease $lease = null): Invoice
{
    $lease ??= makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, [
        'issue_date' => now()->toDateString(),
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount, 'balance' => $amount,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => $amount,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    return $invoice;
}

function tb(): array
{
    return app(LedgerReportService::class)->trialBalance();
}

it('issues an invoice: GL posts Dr AR / Cr revenue and the trial balance stays balanced', function () {
    $invoice = lifecycleInvoice(10000);
    sync();

    $entry = entryFor($invoice);
    $ar = app(AccountResolver::class)->id('accounts_receivable');
    $rent = app(AccountResolver::class)->id('rent_revenue');

    expect($entry)->not->toBeNull()
        ->and((float) $entry->lines->firstWhere('ledger_account_id', $ar)->debit)->toBe(10000.0)
        ->and((float) $entry->lines->firstWhere('ledger_account_id', $rent)->credit)->toBe(10000.0);

    $trial = tb();
    expect($trial['balanced'])->toBeTrue()
        ->and($trial['total_debit'])->toEqualWithDelta(10000.0, 0.001)
        ->and(arControlNet())->toBe(10000.0); // AR open for the full invoice

    $this->artisan('billing:reconcile')->assertSuccessful();
});

it('captures a partial then a full payment: AR ties down to zero at each step', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = lifecycleInvoice(10000, $lease);
    sync();
    expect(arControlNet())->toBe(10000.0);

    // Partial payment of 4,000 → AR down to 6,000, books still balance.
    $p1 = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 4000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $p1->invoices()->attach($invoice->id, ['allocated_amount' => 4000]);
    $invoice->recomputeTotals();
    sync();

    expect(arControlNet())->toBe(6000.0)
        ->and($invoice->fresh()->status)->toBe('partially_paid')
        ->and((float) $invoice->fresh()->balance)->toBe(6000.0)
        ->and(tb()['balanced'])->toBeTrue();

    // Settle the remaining 6,000 → AR nets to zero, invoice paid.
    $p2 = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 6000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $p2->invoices()->attach($invoice->id, ['allocated_amount' => 6000]);
    $invoice->recomputeTotals();
    sync();

    expect(arControlNet())->toBe(0.0)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and(tb()['balanced'])->toBeTrue();

    $this->artisan('billing:reconcile')->assertSuccessful();
});

it('applies a credit note to an invoice: GL records the sales-return and AR is relieved', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = lifecycleInvoice(10000, $lease);
    sync();

    // Issue a 3,000 credit note (VAT-exempt) and apply it to the invoice.
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'applied_amount' => 0, 'balance' => 3000,
    ]);
    $applied = app(CreditNoteService::class)->applyToInvoice($note, $invoice, 3000);
    sync();

    expect($applied)->toBe(3000.0)
        ->and($note->fresh()->status)->toBe('applied');

    // GL: the note posts Dr Sales Returns / Cr AR, so AR net drops by the credited amount.
    $noteEntry = entryFor($note->fresh());
    $salesReturns = app(AccountResolver::class)->id('sales_returns');
    expect($noteEntry)->not->toBeNull()
        ->and((float) $noteEntry->lines->firstWhere('ledger_account_id', $salesReturns)->debit)->toBe(3000.0);

    expect(arControlNet())->toBe(7000.0)                       // 10,000 invoice − 3,000 credit
        ->and((float) $invoice->fresh()->balance)->toBe(7000.0)
        ->and(tb()['balanced'])->toBeTrue();

    $this->artisan('billing:reconcile --deep')->assertSuccessful();
});

it('allocates one payment across TWO invoices in different properties: each AR settles on its own books', function () {
    // Same tenant, two leases in two different malls — a cross-property receipt.
    $tenant = makeTenant();
    $assetA = makeAsset();
    $assetB = makeAsset();
    $leaseA = makeLease(makeUnit($assetA), $tenant);
    $leaseB = makeLease(makeUnit($assetB), $tenant);
    $invA = lifecycleInvoice(10000, $leaseA);
    $invB = lifecycleInvoice(6000, $leaseB);
    sync();

    // One captured payment covering both invoices in full.
    $payment = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $tenant->id, 'amount' => 16000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $payment->invoices()->attach($invA->id, ['allocated_amount' => 10000]);
    $payment->invoices()->attach($invB->id, ['allocated_amount' => 6000]);
    $invA->recomputeTotals();
    $invB->recomputeTotals();
    sync();

    expect($invA->fresh()->status)->toBe('paid')
        ->and($invB->fresh()->status)->toBe('paid')
        ->and(arControlNet())->toBe(0.0)          // consolidated AR fully settled
        ->and(tb()['balanced'])->toBeTrue();

    // Each property's receivables relieved on its own asset dimension. The AR dimension lives
    // on the LINE (the invoice AR line inherits the invoice's asset; the payment credits AR per
    // allocation-asset even when the receipt spans properties), so filter on jl.asset_id.
    $ar = app(AccountResolver::class)->id('accounts_receivable');
    foreach ([$assetA->id, $assetB->id] as $assetId) {
        $net = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->where('jl.asset_id', $assetId)
            ->where('jl.ledger_account_id', $ar)
            ->selectRaw('COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) as net')
            ->value('net');
        expect(round((float) $net, 2))->toBe(0.0);
    }

    $this->artisan('billing:reconcile')->assertSuccessful();
});

it('voids a captured payment: the payment entry voids and AR re-opens on the books', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = lifecycleInvoice(5000, $lease);
    sync();

    $payment = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $invoice->recomputeTotals();
    sync();
    expect(arControlNet())->toBe(0.0)                     // paid → AR relieved
        ->and(entryFor($payment))->not->toBeNull();

    // Refund/void the captured payment → its journalizer now returns no effect, so the sweep voids the leg.
    app(VoidPaymentService::class)->void($payment, 'chargeback');
    sync();

    expect($payment->fresh()->status)->toBe('refunded')
        ->and(voidEntries($payment))->toBe(1)             // the Dr Bank / Cr AR leg is voided
        ->and(entryFor($payment))->toBeNull()             // no live posted entry remains
        ->and((float) $invoice->fresh()->balance)->toBe(5000.0) // AR re-opened
        ->and(arControlNet())->toBe(5000.0)
        ->and(tb()['balanced'])->toBeTrue();

    $this->artisan('billing:reconcile')->assertSuccessful();
});

it('voids an issued invoice: its entry voids and the reversal nets AR back to zero', function () {
    $invoice = lifecycleInvoice(10000);
    sync();
    expect(arControlNet())->toBe(10000.0);

    app(VoidInvoiceService::class)->void($invoice, 'billed twice');
    sync();

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and(voidEntries($invoice))->toBe(1)             // the AR/revenue entry is voided
        ->and(entryFor($invoice))->toBeNull();

    // Voided entry stays on the books, offset by the reversing entry → AR nets back to zero.
    expect(arControlNet())->toBe(0.0)
        ->and(tb()['balanced'])->toBeTrue()
        ->and(tb()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('runs the full lifecycle end to end and passes the deep reconcile at the final state', function () {
    // Issue two invoices for one tenant, partially pay one, credit-note the other, then void
    // a payment — exercising every posting path — and assert the DEEP reconcile is clean.
    $tenant = makeTenant();
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), $tenant);
    $invA = lifecycleInvoice(10000, $lease);
    $invB = lifecycleInvoice(8000, $lease);
    sync();

    // Partial payment on A.
    $pay = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $tenant->id, 'amount' => 4000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $pay->invoices()->attach($invA->id, ['allocated_amount' => 4000]);
    $invA->recomputeTotals();

    // Credit note on B.
    $note = CreditNote::create([
        'tenant_id' => $tenant->id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000, 'applied_amount' => 0, 'balance' => 2000,
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $invB, 2000);
    sync();

    // Now reverse the payment on A.
    app(VoidPaymentService::class)->void($pay, 'bounced');
    sync();

    // Expected AR: A back to 10,000 (payment reversed) + B 6,000 (8,000 − 2,000 credit) = 16,000.
    expect(arControlNet())->toBe(16000.0)
        ->and((float) $invA->fresh()->balance)->toBe(10000.0)
        ->and((float) $invB->fresh()->balance)->toBe(6000.0)
        ->and(tb()['balanced'])->toBeTrue();

    // The deep reconcile verifies EVERY posting document's entry matches its current state.
    $this->artisan('billing:reconcile --deep')->assertSuccessful();
});
