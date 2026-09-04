<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\Banking\MatchBankStatementLineService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * **The reconciliation workspace answers from the eager load it already paid for.**
 *
 * `MatchBankStatementLineService::coverage()` re-queried a line's matches on every call, and
 * `LinesRelationManager` asks it four times for every row it draws — the age cell, the match-state
 * cell, that cell's colour and the Match action's `visible()` — over a query that has already run
 * `->with('matches.journalLine.entry')`. Eight throwaway statements a row.
 *
 * **The measurement is the assertion.** Reading the code tells you a query is issued; only counting
 * them tells you the eager load is being used, which is why this file counts rather than inspects.
 *
 * **Why preferring the loaded relation is allowed here and not everywhere.** `coverage()` decides
 * nothing: it words a badge and hides a button. Every refusal in this module rests on `match()`'s
 * own `lockForUpdate()` read of the journal line, so no money and no guard reads this figure —
 * the condition the rest of this codebase states for the same trick on `Invoice::writeOffs`.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The posting engine refuses a date with no open period, so the calendar has to exist.
    app(FiscalCalendar::class)->ensureYear(2026);
});

/** A mall, its bank, that bank's OWN chart leaf, and a statement to hang lines on. */
function bankCoverageFixture(): array
{
    $asset = makeAsset();

    // A leaf of its own, minted the way the picker's create button mints one —
    // `BankAccount::assertLedgerAccountIsItsOwn()` refuses the generic `bank` posting role.
    $bankLedger = app(MintBankLedgerAccountService::class)->mint('CIB — current', $asset->id);

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

    return [$statement, $bankLedger];
}

function bankCoverageLine(BankStatement $statement, float $amount, string $date, ?string $ref = null): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $statement->id,
        'value_date' => $date,
        'amount' => $amount,
        'reference' => $ref,
        'row_hash' => BankStatementLine::hashFor($date, $amount, $ref, null),
    ]);
}

/** A posted entry with one bank leg, built through the real posting engine. */
function bankCoveragePosting(LedgerAccount $bankLedger, float $amount, string $date): JournalLine
{
    $other = LedgerAccount::where('code', '41101001')->firstOrFail(); // Rent revenue
    $in = $amount > 0;

    $entry = app(JournalPostingService::class)->post([
        'entry_date' => $date,
        'description_en' => 'Coverage fixture posting',
        'lines' => [
            [
                'ledger_account_id' => $bankLedger->id,
                'debit' => $in ? abs($amount) : 0,
                'credit' => $in ? 0 : abs($amount),
            ],
            [
                'ledger_account_id' => $other->id,
                'debit' => $in ? 0 : abs($amount),
                'credit' => $in ? abs($amount) : 0,
            ],
        ],
    ]);

    return $entry->lines->firstWhere('ledger_account_id', $bankLedger->id);
}

/**
 * How many statements the database was asked for while `$work` ran.
 *
 * Laravel offers no way to REMOVE a query listener, so each call registers another one — harmless,
 * because each increments its own counter and only the newest is ever read.
 */
function bankCoverageQueriesDuring(Closure $work): int
{
    $count = 0;

    DB::listen(function () use (&$count) {
        $count++;
    });

    $work();

    return $count;
}

it('asks the database nothing when the workspace has already loaded the matches', function () {
    [$statement, $bankLedger] = bankCoverageFixture();
    $line = bankCoverageLine($statement, 12000, '2026-03-02', 'TRF-1');
    $posting = bankCoveragePosting($bankLedger, 12000, '2026-03-02');

    $service = app(MatchBankStatementLineService::class);
    $service->match($line, $posting);

    // CONTROL — with nothing loaded the answer is still right, and it still costs queries. Without
    // this the zero-query assertion below would pass just as happily on a method that returned
    // nonsense without touching the database.
    $cold = BankStatementLine::findOrFail($line->id);
    $coldQueries = bankCoverageQueriesDuring(fn () => $service->coverage($cold));
    $coldAnswer = $service->coverage(BankStatementLine::findOrFail($line->id));

    expect($coldQueries)->toBeGreaterThan(0)
        ->and($coldAnswer['fully'])->toBeTrue()
        ->and($coldAnswer['matched'])->toBe(12000.0)
        ->and($coldAnswer['outstanding'])->toBe(0.0);

    // THE MEASUREMENT — the workspace's own eager load, then the four questions it asks per row.
    $warm = BankStatementLine::with('matches.journalLine.entry')->findOrFail($line->id);

    $warmQueries = bankCoverageQueriesDuring(function () use ($service, $warm) {
        for ($i = 0; $i < 4; $i++) {
            $service->coverage($warm);
        }
    });

    expect($warmQueries)->toBe(0)
        ->and($service->coverage($warm))->toBe($coldAnswer);
});

it('reads a partly explained line the same way loaded or not', function () {
    [$statement, $bankLedger] = bankCoverageFixture();
    // One bank line covering two cheques: the case the badge exists for.
    $line = bankCoverageLine($statement, 12000, '2026-03-02', 'TRF-2');
    $posting = bankCoveragePosting($bankLedger, 5000, '2026-03-02');

    $service = app(MatchBankStatementLineService::class);
    $service->match($line, $posting);

    $cold = $service->coverage(BankStatementLine::findOrFail($line->id));
    $warm = $service->coverage(BankStatementLine::with('matches.journalLine.entry')->findOrFail($line->id));

    expect($warm)->toBe($cold)
        ->and($warm['matched'])->toBe(5000.0)
        ->and($warm['outstanding'])->toBe(7000.0)
        ->and($warm['fully'])->toBeFalse();
});

it('still answers correctly when the matches are loaded without their postings', function () {
    // The half-loaded shape: `matches` present, `journalLine` not. It must lazy-read rather than
    // silently count the line as unexplained — which is what a naive `?? collect()` would do.
    [$statement, $bankLedger] = bankCoverageFixture();
    $line = bankCoverageLine($statement, 9000, '2026-03-03', 'TRF-3');
    $posting = bankCoveragePosting($bankLedger, 9000, '2026-03-03');

    $service = app(MatchBankStatementLineService::class);
    $service->match($line, $posting);

    $half = BankStatementLine::with('matches')->findOrFail($line->id);

    expect($service->coverage($half)['fully'])->toBeTrue()
        ->and($service->coverage($half)['matched'])->toBe(9000.0);
});
