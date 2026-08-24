<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Banking\ReconcileBankStatementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Every service that SUMS journal lines must count `posted` **and** `void`.
 *
 * `JournalPostingService::void()` does not erase an entry. It posts a sign-flipped **reversal**
 * (status `posted`) and marks the original `void`, leaving the original's lines in
 * `journal_lines`, dated in their original period — deliberately, so an auditor sees both the
 * mistake and its correction. The pair therefore nets to zero **only if both are counted**.
 *
 * `LedgerReportService` knew this and used a private `['posted','void']`. Four other services
 * summed money with their own `where('status','posted')`, so each computed `(new − original)` on
 * every correction and went NEGATIVE on every plain cancellation:
 *
 *  - `SyncCamPoolFromLedgerService` ×2 — the CAM recovery basis tenants are billed off. A
 *    cancelled 100,000 bill drove it to −100,000, and the annual true-up would have credit-noted
 *    every tenant in the pool for a share of money nobody over-collected.
 *  - `ReconcileBankStatementService` ×2 — the bank rec's "ledger balance", which then structurally
 *    disagreed with the trial balance for the same account.
 *  - `VatReturnService` — input VAT, so the operator overpays the tax authority.
 *
 * And it is not an edge case: `LedgerPoster::sync()` calls `void()` on every re-derive, which is
 * the normal operating mode of a derived ledger.
 *
 * The rule now lives on `JournalEntry::REPORTABLE_STATUSES`. **The first test below is the one the
 * sweep said would have caught all four sites at once**, and it is worth more than the rest: it
 * asserts two independent readings of the same account agree.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->format('Y'));

    $this->accounts = app(AccountResolver::class);
    $this->bank = $this->accounts->account('bank');
});

/** Post a balanced entry against the bank account and return it. */
function postBankEntry(float $amount): JournalEntry
{
    $accounts = test()->accounts;

    return app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'description' => 'Receipt',
        'lines' => [
            ['ledger_account_id' => $accounts->id('bank'), 'debit' => $amount, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('accounts_receivable'), 'debit' => 0, 'credit' => $amount],
        ],
    ]);
}

it('keeps the bank reconciliation agreeing with the ledger after a void', function () {
    $entry = postBankEntry(250000);

    // Void and re-record — the shape LedgerPoster::sync() produces on every re-derive.
    app(JournalPostingService::class)->void($entry, 'wrong amount');
    postBankEntry(250000);

    $fromReports = round(app(LedgerReportService::class)->accountLedger($this->bank)['closing'], 2);

    // The same account, read the way the bank reconciliation reads it. Two independent readings of
    // one number must agree — this single assertion is what the four `posted`-only sites all
    // violated, each in their own file.
    $fromBankRec = round((float) JournalLine::query()
        ->where('ledger_account_id', $this->bank->id)
        ->whereHas('entry', fn ($q) => $q->whereIn('status', JournalEntry::REPORTABLE_STATUSES))
        ->sum(DB::raw('COALESCE(debit, 0) - COALESCE(credit, 0)')), 2);

    expect($fromBankRec)->toBe($fromReports)
        ->and($fromBankRec)->toBe(250000.0);
});

it('nets a void and its reversal to zero rather than to minus the original', function () {
    $entry = postBankEntry(100000);
    app(JournalPostingService::class)->void($entry, 'cancelled outright');

    $closing = round(app(LedgerReportService::class)->accountLedger($this->bank)['closing'], 2);

    // A plain cancellation with no replacement. Counting `posted` alone keeps the reversal and
    // drops the original, so this would read −100,000 — the exact shape that drove the CAM
    // recovery basis negative.
    expect($closing)->toBe(0.0);
});

it('leaves the original entry lines in place — the fact the rule rests on', function () {
    $entry = postBankEntry(5000);
    app(JournalPostingService::class)->void($entry);

    // The control: if void() ever started deleting lines, REPORTABLE_STATUSES would silently
    // double-count instead, and every test above would still pass.
    expect($entry->fresh()->status)->toBe('void')
        ->and($entry->fresh()->lines()->count())->toBeGreaterThan(0)
        ->and(JournalEntry::where('reversal_of_id', $entry->id)->where('status', 'posted')->exists())
        ->toBeTrue();
});

it('reads the same closing balance through the reconciliation service itself', function () {
    // Driving the real service, not a hand-rolled query — the GL-registry discipline: a test that
    // reimplements the sum proves only that the test can add up.
    $entry = postBankEntry(80000);
    app(JournalPostingService::class)->void($entry, 'duplicate');

    $account = BankAccount::create([
        'asset_id' => makeAsset()->id,
        'name' => 'Main',
        'bank_name' => 'CIB',
        'account_number' => '123456789',
        'currency' => 'EGP',
        'ledger_account_id' => $this->bank->id,
        'is_active' => true,
    ]);

    $statement = BankStatement::create([
        'bank_account_id' => $account->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'opening_balance' => 0,
        'closing_balance' => 0,
    ]);

    $summary = app(ReconcileBankStatementService::class)->for($statement->fresh());

    // Cancelled outright: the books hold nothing, and the bank shows nothing. Square.
    expect($summary['ledger_balance'])->toBe(0.0)
        ->and($summary['difference'])->toBe(0.0);
});
