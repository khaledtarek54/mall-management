<?php

use App\Models\AccountingPeriod;
use App\Models\Disbursement;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\OwnerStatement;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Owner disbursements (module 27, slice 6) — the payout that clears the Due-to-Owner liability
 * the statement accrued. Drives the REAL services + the REAL accounting:sync-ledger sweep:
 * paying posts Dr Due to Owner / Cr Bank; Due to Owner nets to zero once fully paid; the cap
 * (owner's share) is enforced; partials work; a scheduled payout can be cancelled.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->reports = app(LedgerReportService::class);
    $this->generate = app(GenerateOwnerStatementRunService::class);
    $this->finalise = app(FinaliseOwnerStatementRunService::class);
    $this->disburse = app(DisbursementService::class);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));
    $this->operator = makeUser('manager');
});

function dueToOwnerBalance($test): float
{
    return (float) $test->reports->accountLedger(LedgerAccount::where('code', '21802001')->first())['closing'];
}

/** Build a finalised statement whose owner is owed `$net` (revenue = net + 4000, expense = 4000). */
function finalisedOwnerStatement($test, float $net = 6000): OwnerStatement
{
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 100]);

    $revenue = $net + 4000;
    $test->post->post(['entry_date' => '2026-03-10', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $test->r->id('accounts_receivable'), 'debit' => $revenue, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('rent_revenue'), 'debit' => 0, 'credit' => $revenue],
    ]]);
    $test->post->post(['entry_date' => '2026-03-12', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $test->r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    $run = $test->finalise->finalise($test->generate->generate($asset, $test->march), $owner);
    $test->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    return $run->statements->first()->fresh();
}

it('pays the owner in full: Dr Due to Owner / Cr Bank, and Due to Owner nets to zero', function () {
    $statement = finalisedOwnerStatement($this, 6000);
    expect(dueToOwnerBalance($this))->toBe(6000.0); // accrued, unpaid

    $d = $this->disburse->schedule($statement, 6000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    $d = $this->disburse->approve($d, $this->operator);
    $d = $this->disburse->markPaid($d, $this->operator, '2026-03-31', 'TRX-1');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $entry = JournalEntry::where('source_type', $d->getMorphClass())
        ->where('source_id', $d->id)->where('status', 'posted')->first();
    $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => (string) $l->account->code);
    expect((float) $lines['21802001']->debit)->toBe(6000.0)   // Due to Owner cleared
        ->and((float) $lines['11102001']->credit)->toBe(6000.0); // Bank out

    // Due to Owner nets to zero; the statement is fully paid; books balance.
    expect(dueToOwnerBalance($this))->toBe(0.0)
        ->and((float) $statement->fresh()->paid_to_date)->toBe(6000.0)
        ->and($statement->fresh()->outstanding())->toBe(0.0)
        ->and($this->reports->trialBalance()['balanced'])->toBeTrue();
});

it('supports partial payouts that draw the liability down to zero', function () {
    $statement = finalisedOwnerStatement($this, 6000);

    $d1 = $this->disburse->schedule($statement, 4000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    $this->disburse->markPaid($this->disburse->approve($d1, $this->operator), $this->operator, '2026-03-31');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(dueToOwnerBalance($this))->toBe(2000.0)
        ->and((float) $statement->fresh()->paid_to_date)->toBe(4000.0)
        ->and($statement->fresh()->outstanding())->toBe(2000.0);

    $d2 = $this->disburse->schedule($statement->fresh(), 2000, Disbursement::METHOD_CASH, $this->operator);
    $this->disburse->markPaid($this->disburse->approve($d2, $this->operator), $this->operator, '2026-03-31');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(dueToOwnerBalance($this))->toBe(0.0)
        ->and($statement->fresh()->outstanding())->toBe(0.0)
        ->and($this->reports->trialBalance()['balanced'])->toBeTrue();
});

it('caps a disbursement at the amount still owed', function () {
    $statement = finalisedOwnerStatement($this, 6000);

    expect(fn () => $this->disburse->schedule($statement, 7000, Disbursement::METHOD_BANK_TRANSFER, $this->operator))
        ->toThrow(DomainException::class);

    // A second scheduled payout can't overshoot the remaining balance either.
    $d1 = $this->disburse->schedule($statement, 4000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    $this->disburse->markPaid($this->disburse->approve($d1, $this->operator), $this->operator, '2026-03-31');

    expect(fn () => $this->disburse->schedule($statement->fresh(), 2500, Disbursement::METHOD_BANK_TRANSFER, $this->operator))
        ->toThrow(DomainException::class); // only 2000 remains
});

it('enforces the lifecycle: cannot pay before approval, and a cancelled payout frees the balance', function () {
    $statement = finalisedOwnerStatement($this, 6000);

    $d = $this->disburse->schedule($statement, 6000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    expect(fn () => $this->disburse->markPaid($d, $this->operator, '2026-03-31'))
        ->toThrow(DomainException::class); // not approved yet

    $this->disburse->cancel($d, $this->operator);
    expect($d->fresh()->status)->toBe('cancelled');

    // Cancelling a scheduled payout posted nothing; the full balance is still available.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    expect(dueToOwnerBalance($this))->toBe(6000.0);

    // And it can be rescheduled + paid.
    $d2 = $this->disburse->schedule($statement->fresh(), 6000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    $this->disburse->markPaid($this->disburse->approve($d2, $this->operator), $this->operator, '2026-03-31');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    expect(dueToOwnerBalance($this))->toBe(0.0);
});

it('is idempotent: re-running the sweep does not double-post a paid disbursement', function () {
    $statement = finalisedOwnerStatement($this, 6000);
    $d = $this->disburse->schedule($statement, 6000, Disbursement::METHOD_BANK_TRANSFER, $this->operator);
    $this->disburse->markPaid($this->disburse->approve($d, $this->operator), $this->operator, '2026-03-31');

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(dueToOwnerBalance($this))->toBe(0.0)
        ->and(JournalEntry::where('source_type', $d->getMorphClass())->where('source_id', $d->id)->where('status', 'posted')->count())->toBe(1);
});
