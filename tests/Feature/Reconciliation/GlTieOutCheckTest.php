<?php

use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The reconcile harness now asserts the general ledger's AR/AP control accounts tie
 * to the source documents (folded in from accounting:sync-ledger's printout). The
 * check is all-time only and self-skips when the GL isn't configured/populated.
 */
function glReconcile(?string $month = null): array
{
    return app(BooksReconciliationService::class)->run($month);
}

function glCheck(array $report): ?array
{
    return collect($report['checks'])->firstWhere('key', 'gl_tie_out');
}

it('passes when the general ledger ties to the source AR', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    app(LedgerPoster::class)->sync($invoice->fresh());

    $report = glReconcile();
    $gl = glCheck($report);

    expect($gl)->not->toBeNull();
    expect($gl['passed'])->toBeTrue();
    expect($report['ok'])->toBeTrue();
});

it('catches a general ledger that has drifted from the source (cancelled but not re-synced)', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    app(LedgerPoster::class)->sync($invoice->fresh()); // GL AR = invoice total

    // Cancel the invoice but do NOT re-sync the GL: source AR drops to 0 while the
    // ledger still carries the receivable → the tie-out must catch the drift.
    $invoice->update(['status' => 'cancelled']);

    $report = glReconcile();
    $gl = glCheck($report);

    expect($gl['passed'])->toBeFalse();
    expect($report['ok'])->toBeFalse();
    expect($gl['discrepancies'][0]['ref'])->toBe('Accounts Receivable');
    expect($gl['discrepancies'][0]['detail'])->toContain('delta');
});

it('skips the GL check when the ledger is not configured', function () {
    // No chart / mappings / postings — nothing to tie out.
    makeInvoice(makeLease(makeUnit(makeAsset())));

    $report = glReconcile();

    expect(glCheck($report))->toBeNull();
    expect($report['ok'])->toBeTrue();
});

it('skips the GL check for a month-scoped run (GL balances are cumulative)', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    app(LedgerPoster::class)->sync($invoice->fresh());

    $report = glReconcile(now()->format('Y-m'));

    expect(glCheck($report))->toBeNull();
});

it('catches a GL that has drifted from the source AP (bill cancelled, not re-synced)', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $bill = \App\Models\VendorBill::create([
        'vendor_id' => \App\Models\Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000, 'balance' => 2000,
    ]);
    app(LedgerPoster::class)->sync($bill->fresh()); // GL AP = 2000

    $bill->update(['status' => 'cancelled']); // source AP → 0, GL not re-synced

    $gl = glCheck(glReconcile());
    expect($gl['passed'])->toBeFalse();
    expect(collect($gl['discrepancies'])->pluck('ref'))->toContain('Accounts Payable');
});
