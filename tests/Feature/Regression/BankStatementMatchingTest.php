<?php

use App\Models\BankAccount;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Banking\MatchBankStatementLineService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Matching a bank statement to the books — slice 3, where the control actually lands.
 *
 * A match is an ANNOTATION: it posts nothing and moves no balance. That is the property that keeps
 * the reconciliation screen from becoming a back door into the GL, and it is asserted here rather
 * than asserted in a comment.
 *
 * The guards are the substance. Matching the wrong account, the wrong direction, or the same posting
 * twice would all still *balance* — the reconciliation would look finished and be false, which is
 * the exact failure the module exists to prevent.
 */
function bankFixture(): array
{
    $asset = makeAsset();
    // A leaf of its OWN, minted the way the panel mints one. This used to take `11102001`, the
    // `bank` POSTING ROLE account — which `BankAccount::assertLedgerAccountIsItsOwn()` now refuses,
    // because the role is where documents naming NO bank land and merging the two is the defect the
    // whole reconciliation module exists to avoid. The fixture still posts INTO this account, which
    // is the premise it needs: the matcher finds candidates BY the bank's chart account.
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

    return [$asset, $account, $statement, $bankLedger];
}

/**
 * A posted entry with one bank leg — the shape every money source produces.
 *
 * Built through the REAL posting engine rather than by hand. A line on a posted entry is immutable,
 * so hand-crafting one is a state production cannot reach; driving `JournalPostingService` also
 * means these tests exercise the balanced-or-reject validation every real posting goes through.
 */
function bookPosting(LedgerAccount $bankLedger, float $amount, string $date, ?int $assetId = null): JournalLine
{
    $other = LedgerAccount::where('code', '41101001')->firstOrFail(); // Rent revenue
    $in = $amount > 0;

    $entry = app(JournalPostingService::class)->post([
        'entry_date' => $date,
        'description_en' => 'Test posting',
        'asset_id' => $assetId,
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

function statementLine(BankStatement $statement, float $amount, string $date, ?string $ref = null): BankStatementLine
{
    return BankStatementLine::create([
        'bank_statement_id' => $statement->id,
        'value_date' => $date,
        'amount' => $amount,
        'reference' => $ref,
        'row_hash' => BankStatementLine::hashFor($date, $amount, $ref, null),
    ]);
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The posting engine refuses a date with no open period, so the calendar has to exist.
    app(FiscalCalendar::class)->ensureYear(2026);
});

it('matches a statement line to the posting that explains it, and posts nothing', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 12000, '2026-03-02', 'TRF-1');
    $posting = bookPosting($bankLedger, 12000, '2026-03-02');

    $entriesBefore = JournalEntry::count();

    app(MatchBankStatementLineService::class)->match($line, $posting);

    expect(BankMatch::count())->toBe(1)
        // The claim that makes matching safe: annotating explains money, it never moves it.
        ->and(JournalEntry::count())->toBe($entriesBefore)
        ->and(app(MatchBankStatementLineService::class)->coverage($line)['fully'])->toBeTrue();
});

it('refuses to explain one posting twice', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $first = statementLine($statement, 500, '2026-03-02');
    $second = statementLine($statement, 500, '2026-03-03');
    $posting = bookPosting($bankLedger, 500, '2026-03-02');

    $service = app(MatchBankStatementLineService::class);
    $service->match($first, $posting);

    // Reporting the same money as verified twice is the failure this module exists to prevent, and
    // it would leave the reconciliation looking finished.
    expect(fn () => $service->match($second, $posting))->toThrow(DomainException::class);
});

it('refuses a posting belonging to a different bank account', function () {
    [, , $statement] = bankFixture();
    $line = statementLine($statement, 500, '2026-03-02');

    // A posting on the CASH account, not this bank's ledger account.
    $cash = LedgerAccount::where('code', '11101001')->firstOrFail();
    $foreign = bookPosting($cash, 500, '2026-03-02');

    // Matching across accounts reconciles one bank with another's money and still balances.
    expect(fn () => app(MatchBankStatementLineService::class)->match($line, $foreign))
        ->toThrow(DomainException::class);
});

it('refuses a match in the wrong direction', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $moneyIn = statementLine($statement, 500, '2026-03-02');
    $moneyOut = bookPosting($bankLedger, -500, '2026-03-02');

    // Explaining a receipt with a payment: same amount, opposite meaning.
    expect(fn () => app(MatchBankStatementLineService::class)->match($moneyIn, $moneyOut))
        ->toThrow(DomainException::class);
});

it('lets one statement line be explained by two postings', function () {
    // A bank shows one line for two cheques banked together, which is why a line carries many
    // matches while a posting carries one.
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 800, '2026-03-02');
    $service = app(MatchBankStatementLineService::class);

    $service->match($line, bookPosting($bankLedger, 500, '2026-03-02'));

    $partial = $service->coverage($line);
    expect($partial['matched'])->toBe(500.0)
        ->and($partial['outstanding'])->toBe(300.0)
        ->and($partial['fully'])->toBeFalse();

    $service->match($line, bookPosting($bankLedger, 300, '2026-03-02'));

    expect($service->coverage($line)['fully'])->toBeTrue();
});

it('offers candidates on the right side of the ledger, nearest amount first', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 1000, '2026-03-10');

    bookPosting($bankLedger, -1000, '2026-03-10');  // money out — wrong direction
    $exact = bookPosting($bankLedger, 1000, '2026-03-10');
    bookPosting($bankLedger, 950, '2026-03-11');
    bookPosting($bankLedger, 1000, '2026-01-01');   // far outside the tolerance

    $candidates = app(MatchBankStatementLineService::class)->candidatesFor($line);

    expect($candidates)->toHaveCount(2)
        ->and($candidates->first()->id)->toBe($exact->id); // closest amount leads
});

it('drops a candidate once it has been matched', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 1000, '2026-03-10');
    $posting = bookPosting($bankLedger, 1000, '2026-03-10');

    $service = app(MatchBankStatementLineService::class);
    expect($service->candidatesFor($line))->toHaveCount(1); // control

    $service->match($line, $posting);

    expect($service->candidatesFor(statementLine($statement, 1000, '2026-03-11')))->toHaveCount(0);
});

it('ignores a voided entry, which explains nothing', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 1000, '2026-03-10');
    $posting = bookPosting($bankLedger, 1000, '2026-03-10');

    app(JournalPostingService::class)->void($posting->entry);

    // A voided entry has been reversed; its reversal is a separate line that can be matched itself.
    expect(app(MatchBankStatementLineService::class)->candidatesFor($line))->toHaveCount(0);
});

it('says plainly that an unmapped bank account has no candidates', function () {
    // Not an empty list dressed up as "nothing to match" — an account nobody has mapped to the
    // chart cannot be reconciled at all, and slice 1 deliberately left that link optional.
    [, $account, $statement, $bankLedger] = bankFixture();
    bookPosting($bankLedger, 1000, '2026-03-10');
    $account->update(['ledger_account_id' => null]);

    $line = statementLine($statement, 1000, '2026-03-10');

    expect(app(MatchBankStatementLineService::class)->candidatesFor($line))->toHaveCount(0);
});

it('unmatches without touching the ledger', function () {
    [, , $statement, $bankLedger] = bankFixture();
    $line = statementLine($statement, 1000, '2026-03-10');
    $posting = bookPosting($bankLedger, 1000, '2026-03-10');

    $service = app(MatchBankStatementLineService::class);
    $match = $service->match($line, $posting);
    $entries = JournalEntry::count();

    $service->unmatch($match);

    expect(BankMatch::count())->toBe(0)
        ->and(JournalEntry::count())->toBe($entries)
        // …and the posting is a candidate again, so a mis-linked match is fully recoverable.
        ->and($service->candidatesFor($line))->toHaveCount(1);
});
