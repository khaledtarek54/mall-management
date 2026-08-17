<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\JournalEntry;
use App\Services\Banking\ImportBankStatementService;
use Illuminate\Database\QueryException;

/**
 * Bank statement import — slice 2 of bank reconciliation.
 *
 * A statement is EVIDENCE, not accounting: it is the only record in the system that comes from
 * outside it. `billing:reconcile` re-derives the books from the documents, so it agrees with a wrong
 * document; only the bank can disagree. These tests are about ingesting the bank's version
 * faithfully — matching it to the books is slice 3.
 *
 * The one that matters most is the re-import: operators export overlapping ranges and import them
 * again, and a statement that doubles on the second import is evidence nobody can trust.
 */
function statementFor(array $attrs = []): BankStatement
{
    $account = BankAccount::create([
        'asset_id' => makeAsset()->id,
        'name' => 'CIB — current',
    ]);

    return BankStatement::create(array_merge([
        'bank_account_id' => $account->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 0,
        'closing_balance' => 0,
    ], $attrs));
}

it('imports rows and posts absolutely nothing', function () {
    $statement = statementFor();

    $result = app(ImportBankStatementService::class)->import($statement, [
        ['value_date' => '2026-03-02', 'amount' => 12000, 'reference' => 'TRF-1', 'description' => 'Rent — shop 12'],
        ['value_date' => '2026-03-05', 'amount' => -450.75, 'reference' => 'CHG', 'description' => 'Account fee'],
    ]);

    expect($result['imported'])->toBe(2)
        ->and($statement->lines()->count())->toBe(2)
        ->and($statement->movement())->toBe(11549.25)
        // The claim that makes this slice safe to ship on its own.
        ->and(JournalEntry::count())->toBe(0);
});

it('is idempotent — re-importing the same export changes nothing', function () {
    $statement = statementFor();
    $rows = [
        ['value_date' => '2026-03-02', 'amount' => 12000, 'reference' => 'TRF-1', 'description' => 'Rent'],
        ['value_date' => '2026-03-05', 'amount' => -450.75, 'reference' => 'CHG', 'description' => 'Fee'],
    ];

    app(ImportBankStatementService::class)->import($statement, $rows);
    $second = app(ImportBankStatementService::class)->import($statement, $rows);

    expect($second['imported'])->toBe(0)
        ->and($second['skipped'])->toBe(2)
        ->and($statement->lines()->count())->toBe(2);
});

it('keeps a bank\'s GENUINE duplicate, which is why the hash counts occurrences', function () {
    // Two identical fees on one day is a real thing a bank does. Hashing only the content would
    // collapse them into one and quietly lose money from the evidence — and the statement's own
    // arithmetic would then fail to balance, blaming the operator for the importer's shortcut.
    $statement = statementFor();
    $rows = [
        ['value_date' => '2026-03-05', 'amount' => -50, 'reference' => 'FEE', 'description' => 'Card fee'],
        ['value_date' => '2026-03-05', 'amount' => -50, 'reference' => 'FEE', 'description' => 'Card fee'],
    ];

    app(ImportBankStatementService::class)->import($statement, $rows);

    expect($statement->lines()->count())->toBe(2)
        ->and($statement->movement())->toBe(-100.0);

    // …and re-importing that same file still adds nothing.
    app(ImportBankStatementService::class)->import($statement, $rows);

    expect($statement->lines()->count())->toBe(2);
});

it('skips zero-value rows rather than failing the whole import', function () {
    // Real exports carry balance-brought-forward and header rows. Refusing the file over one is how
    // an operator stops using the feature.
    $statement = statementFor();

    $result = app(ImportBankStatementService::class)->import($statement, [
        ['value_date' => '2026-03-01', 'amount' => 0, 'description' => 'Balance brought forward'],
        ['value_date' => '2026-03-02', 'amount' => 500, 'description' => 'Transfer'],
    ]);

    expect($result['imported'])->toBe(1)->and($result['skipped'])->toBe(1);
});

it('reads a debit/credit pair as one signed amount', function () {
    $csv = <<<'CSV'
    Date,Description,Reference,Debit,Credit,Balance
    2026-03-02,Rent received,TRF-1,,"12,000.00","12,000.00"
    2026-03-05,Account fee,CHG,"450.75",,"11,549.25"
    CSV;

    $rows = app(ImportBankStatementService::class)->parseCsv($csv);

    // Money out is negative, so the statement's own arithmetic holds with no further sign handling.
    expect($rows[0]['amount'])->toBe(12000.0)
        ->and($rows[1]['amount'])->toBe(-450.75)
        ->and($rows[0]['reference'])->toBe('TRF-1')
        ->and($rows[1]['running_balance'])->toBe(11549.25);
});

it('accepts the number formats a bank export actually uses', function () {
    $csv = <<<'CSV'
    date,details,amount
    2026-03-02,In,"1,234.56"
    2026-03-03,Out,(789.10)
    CSV;

    $rows = app(ImportBankStatementService::class)->parseCsv($csv);

    // Parenthesised negatives are how most statements print money out.
    expect($rows[0]['amount'])->toBe(1234.56)
        ->and($rows[1]['amount'])->toBe(-789.10);
});

it('refuses a CSV it cannot understand, instead of importing nothing quietly', function () {
    // A silent zero-row import reads as "the bank had no transactions", which is a lie the operator
    // would act on.
    expect(fn () => app(ImportBankStatementService::class)->parseCsv("foo,bar\n1,2"))
        ->toThrow(DomainException::class);

    expect(fn () => app(ImportBankStatementService::class)->parseCsv('date,amount'))
        ->toThrow(DomainException::class);
});

it('checks the bank\'s own arithmetic, which is the cheapest signal that a file was mis-read', function () {
    $statement = statementFor(['opening_balance' => 1000, 'closing_balance' => 12549.25]);

    app(ImportBankStatementService::class)->import($statement, [
        ['value_date' => '2026-03-02', 'amount' => 12000, 'reference' => 'TRF-1'],
        ['value_date' => '2026-03-05', 'amount' => -450.75, 'reference' => 'CHG'],
    ]);

    expect($statement->refresh()->isSelfConsistent())->toBeTrue();

    // A sign convention read backwards, a truncated file, a half-mapped column — all land here.
    $statement->update(['closing_balance' => 99999]);

    expect($statement->refresh()->isSelfConsistent())->toBeFalse();
});

it('cannot hold two statements for the same account and period', function () {
    $statement = statementFor();

    expect(fn () => BankStatement::create([
        'bank_account_id' => $statement->bank_account_id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 0, 'closing_balance' => 0,
    ]))->toThrow(QueryException::class);
});
