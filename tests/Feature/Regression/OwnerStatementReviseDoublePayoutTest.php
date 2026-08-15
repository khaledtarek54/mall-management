<?php

use App\Models\AccountingPeriod;
use App\Models\Disbursement;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression (close-out sweep, HIGH money): revising a finalised owner-statement run used to be
 * gated only by isFinalised(). But revise supersedes the old statements and rebuilds fresh ones at
 * version+1 with paid_to_date = 0, while the already-PAID disbursements stay attached to the
 * superseded statement. recomputePaidToDate() sums only the statement's OWN paid disbursements, so
 * the new statement's overpayment cap (owner_share − paid_to_date) reset to the FULL share → the
 * owner could be scheduled + paid a SECOND time. The fix refuses to revise while any non-cancelled
 * disbursement exists.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'OSR-REV']);
    $this->owner = makeUser('owner');
    $this->asset->propertyOwners()->attach($this->owner->id, ['ownership_percentage' => 100, 'started_at' => '2020-01-01']);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));

    // Property net of 6000 for March (10000 rent − 4000 salaries).
    $post = app(JournalPostingService::class);
    $r = app(AccountResolver::class);
    $post->post(['entry_date' => '2026-03-10', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 10000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
    ]]);
    $post->post(['entry_date' => '2026-03-12', 'asset_id' => $this->asset->id, 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 4000],
    ]]);

    $this->run = app(FinaliseOwnerStatementRunService::class)->finalise(
        app(GenerateOwnerStatementRunService::class)->generate($this->asset, $this->march),
        $this->owner,
    );
    $this->statement = $this->run->statements()->sole();
});

it('refuses to revise a run that has an active disbursement', function () {
    // A payout has been scheduled against the statement (money about to move).
    app(DisbursementService::class)->schedule(
        $this->statement, (float) $this->statement->owner_share, Disbursement::METHOD_BANK_TRANSFER, $this->owner,
    );

    expect(fn () => app(ReviseOwnerStatementRunService::class)->revise($this->run, $this->owner))
        ->toThrow(DomainException::class);

    // The run stays finalised (not left half-superseded).
    expect($this->run->fresh()->isFinalised())->toBeTrue();
});

it('allows revise again once the disbursement is cancelled', function () {
    $svc = app(DisbursementService::class);
    $d = $svc->schedule($this->statement, 1000.0, Disbursement::METHOD_BANK_TRANSFER, $this->owner);
    $svc->cancel($d, $this->owner);

    $revised = app(ReviseOwnerStatementRunService::class)->revise($this->run, $this->owner);

    expect($revised->version)->toBe(2)
        ->and($revised->isFinalised())->toBeTrue()
        ->and($this->run->fresh()->status)->toBe(\App\Models\OwnerStatementRun::STATUS_SUPERSEDED);
});

it('never lets total scheduled payouts exceed the owner share across a revise attempt', function () {
    $svc = app(DisbursementService::class);
    // Pay out the full share on v1.
    $svc->schedule($this->statement, (float) $this->statement->owner_share, Disbursement::METHOD_BANK_TRANSFER, $this->owner);

    // Revise is blocked, so no fresh zero-paid statement is ever created to double-pay against.
    try {
        app(ReviseOwnerStatementRunService::class)->revise($this->run, $this->owner);
    } catch (DomainException) {
        // expected
    }

    $scheduledTotal = (float) Disbursement::where('status', '!=', Disbursement::STATUS_CANCELLED)->sum('amount');
    expect($scheduledTotal)->toBe((float) $this->statement->owner_share); // exactly one share's worth, not two
});
