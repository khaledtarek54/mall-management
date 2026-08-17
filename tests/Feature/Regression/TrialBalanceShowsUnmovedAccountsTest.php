<?php

/*
|--------------------------------------------------------------------------
| An account with no entries is absent, not zero (RP-02)
|--------------------------------------------------------------------------
| `LedgerReportService::aggregate()` starts from `journal_lines`, so an account nobody has posted to
| never appears in a ledger report at all. That is the right DEFAULT — a trial balance of 400 rows,
| 300 of them zero, is harder to read rather than more complete.
|
| It is the wrong answer for the one thing a trial balance is FOR. An accountant reconciling asks
| "is the deposits-held account really nil, or did I forget to map it?" — and absence answers
| neither. Yardi offers the same switch, and offers it on the trial balance rather than the income
| statement, where a hundred zero revenue accounts would be noise.
|
| The row that had to be got right: a zero row must not be able to move the totals, or turning the
| switch on would break the tie-out this report exists to prove.
*/

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(AccountingSeeder::class);
});

it('leaves an unposted account out by default', function () {
    $report = app(LedgerReportService::class)->trialBalance();

    expect($report['rows'])->toHaveCount(0);
});

it('lists every postable account when asked to', function () {
    $report = app(LedgerReportService::class)->trialBalance(includeZeroBalances: true);

    $postable = LedgerAccount::where('is_postable', true)->count();

    expect($postable)->toBeGreaterThan(0)
        ->and($report['rows'])->toHaveCount($postable);
});

it('shows a zero account at zero in both columns', function () {
    $report = app(LedgerReportService::class)->trialBalance(includeZeroBalances: true);
    $row = $report['rows']->first();

    expect($row['debit_balance'])->toBe(0.0)
        ->and($row['credit_balance'])->toBe(0.0);
});

it('cannot move the totals or the tie-out', function () {
    // The property that matters. Zero rows are structural; if they could shift `balanced`, turning
    // the switch on would break the very check the trial balance exists to prove.
    $without = app(LedgerReportService::class)->trialBalance();
    $with = app(LedgerReportService::class)->trialBalance(includeZeroBalances: true);

    expect($with['total_debit'])->toBe($without['total_debit'])
        ->and($with['total_credit'])->toBe($without['total_credit'])
        ->and($with['balanced'])->toBe($without['balanced']);
});

it('does not list an account twice when it has moved', function () {
    // The join risk: an account WITH movement must come from the aggregate and must not also be
    // added as a zero row, or it would appear twice and double its own balance in the totals.
    $asset = makeAsset();
    $debit = LedgerAccount::where('type', 'asset')->where('is_postable', true)->firstOrFail();
    $credit = LedgerAccount::where('type', 'revenue')->where('is_postable', true)->firstOrFail();

    $entry = JournalEntry::create([
        'asset_id' => $asset->id, 'entry_date' => '2026-03-10',
        'description' => 'x', 'status' => 'draft', 'source_type' => 'manual',
    ]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $debit->id, 'debit' => 500, 'credit' => 0]);
    JournalLine::create(['journal_entry_id' => $entry->id, 'ledger_account_id' => $credit->id, 'debit' => 0, 'credit' => 500]);
    $entry->update(['status' => 'posted']);

    $report = app(LedgerReportService::class)->trialBalance(includeZeroBalances: true);
    $ids = $report['rows']->pluck('account_id');

    expect($ids->count())->toBe($ids->unique()->count())
        ->and($report['total_debit'])->toBe(500.0)
        ->and($report['balanced'])->toBeTrue();
});

it('keeps the accounts in code order', function () {
    // A trial balance is read down the code column; appending the zero rows at the end would make
    // it unreadable exactly when it is longest.
    $report = app(LedgerReportService::class)->trialBalance(includeZeroBalances: true);
    $codes = $report['rows']->pluck('code')->all();

    expect($codes)->toBe(collect($codes)->sort()->values()->all());
});
