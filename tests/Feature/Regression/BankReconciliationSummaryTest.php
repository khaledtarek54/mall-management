<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Banking\MatchBankStatementLineService;
use App\Services\Banking\ReconcileBankStatementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The reconciliation statement — slice 5, the arithmetic an auditor asks for.
 *
 *     ledger balance = statement closing + Σ(unmatched book postings) − Σ(unmatched statement lines)
 *
 * The two terms on the right are the only two ways the bank and the books can legitimately differ:
 * money the books know and the bank has not shown yet (an unpresented cheque), and money the bank
 * moved that the books have never heard of (a charge). When the identity holds, every difference is
 * accounted for by something named; the remainder when it does not is the thing nobody has explained,
 * and that number is the whole point.
 *
 * These tests are mostly about the SIGNS, because getting one backwards produces a report that
 * balances and lies.
 */
function reconFixture(): array
{
    // A leaf of its OWN, minted the way the panel mints one. This used to take `11102001`, the
    // `bank` POSTING ROLE account — which `BankAccount::assertLedgerAccountIsItsOwn()` now refuses,
    // because the role is where documents naming NO bank land and merging the two is the defect the
    // whole reconciliation module exists to avoid. The fixture still posts INTO this account, which
    // is the premise it needs: the matcher finds candidates BY the bank's chart account.
    $asset = makeAsset();
    $bankLedger = BankAccount::mintLedgerAccount('CIB — current', $asset->id);

    $account = BankAccount::create([
        'asset_id' => $asset->id,
        'name' => 'CIB — current',
        'ledger_account_id' => $bankLedger->id,
    ]);

    $statement = BankStatement::create([
        'bank_account_id' => $account->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 0,
        'closing_balance' => 0,
    ]);

    return [$account, $statement, $bankLedger];
}

function reconPosting(LedgerAccount $bankLedger, float $amount, string $date)
{
    $other = LedgerAccount::where('code', '41101001')->firstOrFail();
    $in = $amount > 0;

    $entry = app(JournalPostingService::class)->post([
        'entry_date' => $date,
        'description_en' => 'Posting',
        'lines' => [
            ['ledger_account_id' => $bankLedger->id, 'debit' => $in ? abs($amount) : 0, 'credit' => $in ? 0 : abs($amount)],
            ['ledger_account_id' => $other->id, 'debit' => $in ? 0 : abs($amount), 'credit' => $in ? abs($amount) : 0],
        ],
    ]);

    return $entry->lines->firstWhere('ledger_account_id', $bankLedger->id);
}

function reconLine(BankStatement $statement, float $amount, string $date): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $statement->id,
        'value_date' => $date,
        'amount' => $amount,
        'row_hash' => BankStatementLine::hashFor($date, $amount, null, null),
    ]);
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    app(FiscalCalendar::class)->ensureYear((int) now()->subDays(60)->year);
});

it('reconciles when everything is matched', function () {
    [, $statement, $bankLedger] = reconFixture();
    $statement->update(['closing_balance' => 1000]);

    $line = reconLine($statement, 1000, '2026-03-02');
    app(MatchBankStatementLineService::class)->match($line, reconPosting($bankLedger, 1000, '2026-03-02'));

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['ledger_balance'])->toBe(1000.0)
        ->and($report['difference'])->toBe(0.0)
        ->and($report['reconciled'])->toBeTrue();
});

it('stays reconciled with an unpresented cheque — an explanation, not a failure', function () {
    // The books wrote a cheque the bank has not shown. The books therefore hold LESS cash than the
    // bank does, and the reconciliation is still complete: the difference has a name.
    [, $statement, $bankLedger] = reconFixture();
    $statement->update(['closing_balance' => 1000]);

    $line = reconLine($statement, 1000, '2026-03-02');
    app(MatchBankStatementLineService::class)->match($line, reconPosting($bankLedger, 1000, '2026-03-02'));

    reconPosting($bankLedger, -300, '2026-03-28'); // written, not presented

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['ledger_balance'])->toBe(700.0)
        ->and($report['unmatched_book_total'])->toBe(-300.0)
        ->and($report['expected_ledger'])->toBe(700.0)
        ->and($report['reconciled'])->toBeTrue()
        ->and($report['unmatched_book_count'])->toBe(1);
});

it('stays reconciled with a bank charge the books have not recorded', function () {
    // The mirror case, and the sign that is easiest to get backwards: the bank has taken money the
    // books do not know about, so the books show MORE than the bank.
    [, $statement, $bankLedger] = reconFixture();
    $statement->update(['closing_balance' => 950]);

    $line = reconLine($statement, 1000, '2026-03-02');
    app(MatchBankStatementLineService::class)->match($line, reconPosting($bankLedger, 1000, '2026-03-02'));

    reconLine($statement, -50, '2026-03-31'); // charge, unmatched

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['ledger_balance'])->toBe(1000.0)
        ->and($report['unmatched_statement_total'])->toBe(-50.0)
        ->and($report['expected_ledger'])->toBe(1000.0)
        ->and($report['reconciled'])->toBeTrue();
});

it('reports the remainder when something is genuinely unexplained', function () {
    // The number the whole exercise exists to produce: money on neither side's terms.
    [, $statement, $bankLedger] = reconFixture();
    $statement->update(['closing_balance' => 1234]);

    reconPosting($bankLedger, 1000, '2026-03-02');

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['reconciled'])->toBeFalse()
        ->and($report['difference'])->toBe(-1234.0);
});

it('counts an outstanding cheque from an EARLIER month', function () {
    // Windowing outstanding items to the period is the classic way to make a reconciliation balance
    // that should not: a cheque written in February and still unpresented in March is outstanding
    // in March too.
    [, $statement, $bankLedger] = reconFixture();
    app(FiscalCalendar::class)->ensureYear(2026);
    $statement->update(['closing_balance' => 0]);

    reconPosting($bankLedger, -400, '2026-02-15');

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['unmatched_book_total'])->toBe(-400.0)
        ->and($report['ledger_balance'])->toBe(-400.0)
        ->and($report['reconciled'])->toBeTrue();
});

it('ignores postings dated after the period', function () {
    [, $statement, $bankLedger] = reconFixture();
    $statement->update(['closing_balance' => 0]);

    reconPosting($bankLedger, 5000, '2026-04-10'); // next month's business

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['ledger_balance'])->toBe(0.0)
        ->and($report['unmatched_book_total'])->toBe(0.0)
        ->and($report['reconciled'])->toBeTrue();
});

it('says an unmapped account cannot be reconciled rather than reporting zeroes', function () {
    // A zeroed report reads as "reconciled", which is the one thing it must never say by accident.
    [$account, $statement] = reconFixture();
    $account->update(['ledger_account_id' => null]);

    $report = app(ReconcileBankStatementService::class)->for($statement->refresh());

    expect($report['mapped'])->toBeFalse()
        ->and($report['reconciled'])->toBeFalse();
});

it('ages an unexplained line, and stays silent once it is explained', function () {
    // The point of the ageing: a line the bank moved that the books still cannot explain after a
    // month is not a backlog item, it is a question nobody has asked.
    [, $statement, $bankLedger] = reconFixture();

    $stale = reconLine($statement, 500, now()->subDays(45)->toDateString());
    $fresh = reconLine($statement, 200, now()->subDays(3)->toDateString());

    expect($stale->ageInDays())->toBeGreaterThanOrEqual(45)
        ->and($statement->agedUnmatchedCount())->toBe(1)
        ->and($statement->lines()->unmatchedOlderThan(30)->pluck('id')->all())->toBe([$stale->id]);

    // Explaining it takes it off the worklist — the count is about UNMATCHED age, not age.
    app(MatchBankStatementLineService::class)->match(
        $stale,
        reconPosting($bankLedger, 500, now()->subDays(45)->toDateString()),
    );

    expect($statement->refresh()->agedUnmatchedCount())->toBe(0)
        // …and the fresh line is still not stale, so the threshold means something.
        ->and($fresh->ageInDays())->toBeLessThan(30);
});
