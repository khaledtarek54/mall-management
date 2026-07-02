<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Regression: a posted document that was later SOFT-DELETED used to orphan its
 * journal entry — the document-driven sweep skips trashed rows (SoftDeletes global
 * scope), so the entry stayed 'posted' and the GL overstated AR/revenue forever.
 * Now `sync()` treats a trashed source as having no ledger effect and voids it, and
 * the sweep visits trashed rows (withTrashed) so deleted docs self-heal to void.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->poster = app(LedgerPoster::class);
});

function voidTestInvoice(): \App\Models\Invoice
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);

    return $invoice;
}

it('voids a posted entry when its source document is soft-deleted', function () {
    $invoice = voidTestInvoice();
    $this->poster->sync($invoice->fresh());
    expect(JournalEntry::where('status', 'posted')->count())->toBe(1);

    $invoice->delete(); // soft-delete: the in-memory instance is now trashed()

    expect($this->poster->sync($invoice))->toBeNull();

    // Original entry voided; the reversal nets the source to zero, books still balance.
    $entries = JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->get();
    expect($entries->where('status', 'void')->count())->toBe(1);

    $tb = app(LedgerReportService::class)->trialBalance();
    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('voids a deleted document from a closed period via the current open period', function () {
    // Post an invoice dated in an earlier month, then close that month.
    $pastDate = now()->subMonths(2)->startOfMonth();
    app(FiscalCalendar::class)->ensureYear((int) $pastDate->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => $pastDate->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
    $this->poster->sync($invoice->fresh());

    // Close the invoice's own period; the current period stays open.
    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate($pastDate));

    $invoice->delete();

    // Like any reversal, the void posts into the current open period (the original is closed).
    expect($this->poster->sync($invoice))->toBeNull();

    $entries = JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->get();
    expect($entries->where('status', 'void')->count())->toBe(1);
    expect(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
    expect(app(LedgerReportService::class)->trialBalance()['total_debit'])->toEqualWithDelta(0.0, 0.001);
});

it('is idempotent — re-syncing an already-voided deleted document does nothing new', function () {
    $invoice = voidTestInvoice();
    $this->poster->sync($invoice->fresh());
    $invoice->delete();
    $this->poster->sync($invoice); // first sweep voids it

    $countAfterVoid = JournalEntry::count();
    expect($this->poster->sync($invoice))->toBeNull(); // second sweep: no-op
    expect(JournalEntry::count())->toBe($countAfterVoid);
});
