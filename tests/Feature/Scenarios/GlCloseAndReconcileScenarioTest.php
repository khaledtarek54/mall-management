<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * GL integrity hardening — Phases 4/5 SCENARIO coverage: the period/year close gate
 * and the billing:reconcile harness, end-to-end across the real command surface.
 *
 * The narrow per-fix guards live in tests/Feature/Regression/CloseGateTest.php; these
 * cover the broader flow — open→drift→refuse→resync→close, the closed-period posting
 * refusal, the whole-year gate, and the shallow-vs-deep reconcile split — with
 * assertions on the ledger + trial balance rather than a single would-change dry-run.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

/**
 * A single-item, VAT-free invoice dated today whose header equals its item, so the
 * InvoiceJournalizer posts a balanced Dr AR / Cr Rent-revenue entry.
 */
function scenarioGlInvoice(float $amount = 10000): Invoice
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount, 'balance' => $amount,
    ]);
    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => $amount,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    return $invoice;
}

/** The current posted (non-void) entry for a source document, if any. */
function scenarioCurrentEntry(Model $source): ?JournalEntry
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'posted')
        ->latest('id')
        ->first();
}

it('closes a period once every document in it is posted and in sync', function () {
    scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $period = AccountingPeriod::forDate(now());
    expect($period->status)->toBe('open');

    $returned = app(PeriodService::class)->closePeriod($period);

    expect($returned->status)->toBe('closed')
        ->and($period->fresh()->status)->toBe('closed');
    // assertPeriodsReconciled is a no-op once everything is in sync.
    app(PeriodService::class)->assertPeriodsReconciled([$period->id]);
});

it('refuses to close a period holding a drifted document and leaves it open', function () {
    $invoice = scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // Money-neutral re-type: the posted revenue split (rent → service) drifts, but the
    // invoice TOTAL and AR are unchanged — so only the per-document gate catches it.
    $invoice->items()->first()->update(['type' => 'service_charge']);

    $period = AccountingPeriod::forDate(now());

    expect(fn () => app(PeriodService::class)->closePeriod($period))
        ->toThrow(DomainException::class);
    // The gate is read-only — the period stays open and nothing was written.
    expect($period->fresh()->status)->toBe('open');

    // assertPeriodsReconciled surfaces the same block directly.
    expect(fn () => app(PeriodService::class)->assertPeriodsReconciled([$period->id]))
        ->toThrow(DomainException::class);
});

it('closes the same period once the drift is re-synced to the ledger', function () {
    $invoice = scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    $invoice->items()->first()->update(['type' => 'service_charge']);

    $period = AccountingPeriod::forDate(now());
    expect(fn () => app(PeriodService::class)->closePeriod($period->fresh()))
        ->toThrow(DomainException::class);

    // Re-sync heals the drift (void the stale entry, re-post the current split)…
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // …and now the close goes through.
    app(PeriodService::class)->closePeriod($period->fresh());
    expect($period->fresh()->status)->toBe('closed');
});

it('refuses to post a fresh document into a CLOSED period, leaving the trial balance untouched', function () {
    // First doc posts and the period is closed clean.
    scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $period = AccountingPeriod::forDate(now());
    app(PeriodService::class)->closePeriod($period);
    expect($period->fresh()->status)->toBe('closed');

    $tbBefore = app(LedgerReportService::class)->trialBalance();
    expect($tbBefore['balanced'])->toBeTrue();

    // Two defences, and both are asserted here because they fail in different places.
    //
    // FIRST: since 2026-07-29 the document cannot even be CREATED with a date in a closed
    // period — Invoice uses GuardsPostingDate, so the refusal reaches the operator instead of
    // being logged in a queued job hours later. That is the defence that should normally fire.
    expect(fn () => scenarioGlInvoice(7777))->toThrow(DomainException::class);

    // SECOND: the ledger's own gate (JournalPostingService::assertOpenPeriodFor) still has to
    // refuse a late document that arrives some other way — one created before the close, or
    // written through a path that bypasses model events (saveQuietly, a raw insert, a
    // migration). That is what this scenario was written to prove and it still holds, so the
    // document is built quietly to get past the new front-line guard and exercise the back one.
    $lease = makeLease(makeUnit(makeAsset()));
    $late = Invoice::withoutEvents(function () use ($lease) {
        $invoice = Invoice::create([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'issued',
            'number' => 'INV-LATE-0001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'subtotal' => 7777, 'vat_amount' => 0, 'total' => 7777,
            'paid_amount' => 0, 'balance' => 7777, 'currency' => 'EGP',
        ]);

        $invoice->items()->create([
            'type' => 'base_rent', 'description' => 'Rent', 'amount' => 7777,
            'vat_rate' => 0, 'vat_amount' => 0, 'total' => 7777,
        ]);

        return $invoice;
    });

    // The sweep catches the refusal and counts it 'failed'; run it best-effort (--scheduled)
    // so the command's exit code stays green while the ledger correctly rejects the entry.
    $this->artisan('accounting:sync-ledger --all --scheduled')->assertSuccessful();

    // The late invoice never made it onto the books…
    expect(scenarioCurrentEntry($late))->toBeNull();

    // …and the trial balance is byte-for-byte what it was before the refused post.
    $tbAfter = app(LedgerReportService::class)->trialBalance();
    expect($tbAfter['balanced'])->toBeTrue()
        ->and($tbAfter['total_debit'])->toEqualWithDelta($tbBefore['total_debit'], 0.001);
});

it('gates a whole-fiscal-year close exactly like a single period', function () {
    $invoice = scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $year = FiscalYear::where('year', (int) now()->year)->firstOrFail();

    // Drift a document that lives inside the year → the year-close gate refuses…
    $invoice->items()->first()->update(['type' => 'service_charge']);
    expect(fn () => app(PeriodService::class)->closeFiscalYear($year))
        ->toThrow(DomainException::class);
    expect($year->fresh()->status)->toBe('open')
        ->and($year->periods()->where('status', 'closed')->count())->toBe(0);

    // …re-sync heals it, and the year (plus all 12 periods) closes atomically.
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    $closed = app(PeriodService::class)->closeFiscalYear($year->fresh());

    expect($closed->status)->toBe('closed')
        ->and($closed->periods()->where('status', 'open')->count())->toBe(0)
        ->and($closed->periods()->count())->toBe(12);
});

it('shallow reconcile passes on a drifted revenue split but --deep fails, and both pass once re-synced', function () {
    $invoice = scenarioGlInvoice();
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    // Re-type rent → service (same money). AR/AP control accounts are unchanged, so the
    // shallow AR tie-out still passes; only the per-document deep check sees the split drift.
    $invoice->items()->first()->update(['type' => 'service_charge']);

    $this->artisan('billing:reconcile')->assertSuccessful();       // shallow: AR ties out
    $this->artisan('billing:reconcile --deep')->assertFailed();    // deep: the split drifted

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $this->artisan('billing:reconcile')->assertSuccessful();
    $this->artisan('billing:reconcile --deep')->assertSuccessful();
});

it('both shallow and deep reconcile pass on a clean, fully-posted set of books', function () {
    scenarioGlInvoice(4000);
    scenarioGlInvoice(6000);
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $this->artisan('billing:reconcile')->assertSuccessful();
    $this->artisan('billing:reconcile --deep')->assertSuccessful();

    // And the ledger itself is balanced with the two invoices' AR on the books.
    $tb = app(LedgerReportService::class)->trialBalance();
    expect($tb['balanced'])->toBeTrue()
        ->and($tb['total_debit'])->toBeGreaterThanOrEqual(10000.0);
});
