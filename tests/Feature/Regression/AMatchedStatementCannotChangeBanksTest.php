<?php

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\Banking\MatchBankStatementLineService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * **A matched bank statement cannot be moved to another bank account** — SW-138.
 *
 * `MatchBankStatementLineService::match()` has always refused to link a statement line to a posting
 * that is not on the statement's own bank chart account, under a comment naming the reason:
 * *"matching across accounts would reconcile one bank with another bank's money and still balance —
 * the failure the whole module exists to prevent."*
 *
 * Editing the statement afterwards walked straight past it. `BankStatementForm` is the resource's
 * form on Edit as well as Create, so `bank_account_id` is an ordinary dropdown on a statement that
 * has already been reconciled, and nothing re-asked the question. Measured at HEAD (2026-09-04):
 * with one line matched to a CIB posting, `update(['bank_account_id' => $nbe->id])` was accepted.
 *
 * Both reconciliations are then wrong, in opposite directions and both silently:
 *
 *  - the CIB posting still carries its `BankMatch`, so `ReconcileBankStatementService` drops it from
 *    CIB's *"in the books, not on the statement"* items — money the books know about that the bank
 *    has not shown vanishes from the one list that exists to name it, and the identity that is
 *    supposed to leave the unexplained remainder visible balances without it;
 *  - the line counts as explained on NBE's statement, by a posting that never touched NBE.
 *
 * Every refusal below is paired with a control that must succeed: correcting an UNMATCHED
 * statement is the ordinary case and the escape the refusal names, and a guard that froze the row
 * would break the workflow instead of protecting it.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'RHM']);

    // Two banks in one mall, each with a chart leaf of its own — minted through the method the
    // ledger-account picker's own create button calls, so the fixture is what the running system
    // would produce (and `BankAccount::assertLedgerAccountIsItsOwn()` refuses anything else).
    $chart = collect([
        app(MintBankLedgerAccountService::class)->mint('CIB — re-home leaf', $this->asset->id),
        app(MintBankLedgerAccountService::class)->mint('NBE — re-home leaf', $this->asset->id),
    ])->filter()->values();

    expect($chart)->toHaveCount(2);

    $this->cib = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'CIB Current', 'bank_name' => 'CIB',
        'account_number' => '100200300', 'ledger_account_id' => $chart[0]->id, 'is_active' => true,
    ]);

    $this->nbe = BankAccount::create([
        'asset_id' => $this->asset->id, 'name' => 'NBE Collections', 'bank_name' => 'NBE',
        'account_number' => '900800700', 'ledger_account_id' => $chart[1]->id, 'is_active' => true,
    ]);

    // The premise. Without two DIFFERENT chart accounts every assertion here compares an account
    // with itself and passes for the wrong reason.
    expect($this->cib->ledger_account_id)->not->toBe($this->nbe->ledger_account_id);

    $this->statement = BankStatement::create([
        'bank_account_id' => $this->cib->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'opening_balance' => 0,
        'closing_balance' => 10_000,
    ]);

    $this->line = $this->statement->lines()->create([
        'value_date' => now()->toDateString(),
        'amount' => 10_000,
        'description' => 'Transfer in',
        'row_hash' => 'rehome-cib-1',
    ]);

    $revenue = app(AccountResolver::class)->id('rent_revenue');

    $this->bookLine = app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'asset_id' => $this->asset->id,
        'lines' => [
            ['ledger_account_id' => $this->cib->ledger_account_id, 'debit' => 10_000, 'credit' => 0],
            ['ledger_account_id' => $revenue, 'debit' => 0, 'credit' => 10_000],
        ],
    ])->lines->firstWhere('debit', '>', 0);
});

it('refuses to move a statement whose lines are already matched', function () {
    app(MatchBankStatementLineService::class)->match($this->line, $this->bookLine);

    expect(fn () => $this->statement->update(['bank_account_id' => $this->nbe->id]))
        ->toThrow(DomainException::class);

    expect($this->statement->fresh()->bank_account_id)->toBe($this->cib->id);
});

it('still lets an unreconciled statement be corrected to the right bank', function () {
    // The ordinary case, and the reason the guard asks about matches rather than about the column:
    // a file imported under the wrong account with nothing matched yet must stay correctable.
    $this->statement->update(['bank_account_id' => $this->nbe->id]);

    expect($this->statement->fresh()->bank_account_id)->toBe($this->nbe->id);
});

it('lets it move once the lines have been unmatched', function () {
    // The escape the refusal names, driven the way an operator drives it — the Unmatch row action
    // on the Lines tab calls exactly this.
    $service = app(MatchBankStatementLineService::class);
    $service->unmatch($service->match($this->line, $this->bookLine));

    $this->statement->update(['bank_account_id' => $this->nbe->id]);

    expect($this->statement->fresh()->bank_account_id)->toBe($this->nbe->id);
});

it('does not freeze the rest of a matched statement', function () {
    // A guard that made a reconciled statement read-only would break the workflow instead of
    // protecting it: the closing balance is exactly what an operator corrects from the bank's advice.
    app(MatchBankStatementLineService::class)->match($this->line, $this->bookLine);

    $this->statement->update(['closing_balance' => 12_345.67, 'notes' => 'Per the bank advice']);

    expect((float) $this->statement->fresh()->closing_balance)->toBe(12_345.67)
        ->and($this->statement->fresh()->bank_account_id)->toBe($this->cib->id);
});

it('refuses the same mistake through the front door', function () {
    // The other end of ONE invariant, pinned beside it so the two cannot drift: a posting on NBE's
    // chart account may not explain a line on CIB's statement, whichever door it arrives through.
    $revenue = app(AccountResolver::class)->id('rent_revenue');

    $nbeLine = app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'asset_id' => $this->asset->id,
        'lines' => [
            ['ledger_account_id' => $this->nbe->ledger_account_id, 'debit' => 10_000, 'credit' => 0],
            ['ledger_account_id' => $revenue, 'debit' => 0, 'credit' => 10_000],
        ],
    ])->lines->firstWhere('debit', '>', 0);

    expect(fn () => app(MatchBankStatementLineService::class)->match($this->line, $nbeLine))
        ->toThrow(DomainException::class);

    // …and the control: the posting on its OWN bank is accepted.
    expect(app(MatchBankStatementLineService::class)->match($this->line, $this->bookLine))
        ->not->toBeNull();
});
