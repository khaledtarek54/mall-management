<?php

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The GL tie-out for owner statements (module 27), driving the REAL services + the REAL
 * `accounting:sync-ledger` sweep (never LedgerPoster::post() directly — per the one-registry
 * invariant, that would prove only the journalizer's arithmetic). Finalising accrues
 * Dr Owner Distributions / Cr Due to Owner for the property net; the books stay balanced and
 * AR/AP are untouched; revising voids the old accrual and posts the corrected one.
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
    $this->revise = app(ReviseOwnerStatementRunService::class);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));
    $this->from = CarbonImmutable::create(2026, 3, 1);
    $this->to = CarbonImmutable::create(2026, 3, 31);
});

/** Post revenue + expense for a property inside March 2026 → net = revenue − expense. */
function postPandL($test, int $assetId, float $revenue, float $expense): void
{
    $test->post->post(['entry_date' => '2026-03-10', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('accounts_receivable'), 'debit' => $revenue, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('rent_revenue'), 'debit' => 0, 'credit' => $revenue],
    ]]);
    $test->post->post(['entry_date' => '2026-03-12', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('salaries_expense'), 'debit' => $expense, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('bank'), 'debit' => 0, 'credit' => $expense],
    ]]);
}

function ownerRunEntry(OwnerStatementRun $run): ?JournalEntry
{
    return JournalEntry::where('source_type', $run->getMorphClass())
        ->where('source_id', $run->id)
        ->where('status', 'posted')
        ->latest('id')
        ->first();
}

it('finalise + sweep accrues Dr Owner Distributions / Cr Due to Owner for the net, books balanced', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPandL($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);
    $run = $this->finalise->finalise($run, $owner);

    // A draft/finalised run posts only through the sweep — not synchronously.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $entry = ownerRunEntry($run);
    expect($entry)->not->toBeNull()
        ->and($entry->is_closing)->toBeFalse()
        ->and((string) $entry->asset_id)->toBe((string) $asset->id);

    $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
    // Only the two distribution accounts — AR/AP are NOT touched by the accrual.
    // (PHP coerces numeric-string array keys to ints, so normalise before comparing.)
    expect($lines->keys()->map(fn ($k) => (string) $k)->sort()->values()->all())->toBe(['21802001', '34101001'])
        ->and((float) $lines['34101001']->debit)->toBe(6000.0)   // Owner Distributions Dr
        ->and((float) $lines['21802001']->credit)->toBe(6000.0); // Due to Owner Cr

    // The signature tie-out: the distributed net IS the income statement's net (no fee in v1).
    $is = $this->reports->incomeStatement([$asset->id], $this->from, $this->to);
    expect((float) $run->net_distributable)->toBe(round((float) $is['net_profit'], 2))
        ->and((float) $run->net_distributable)->toBe(6000.0);

    // Due to Owner carries the 6000 liability; the books balance; AR/AP unchanged by the accrual.
    $dueToOwner = LedgerAccount::where('code', '21802001')->first();
    expect((float) $this->reports->accountLedger($dueToOwner)['closing'])->toBe(6000.0);
    expect($this->reports->trialBalance()['balanced'])->toBeTrue();
    // AR still reflects only the revenue posting (10000), never the distribution.
    $ar = LedgerAccount::where('code', '11201001')->first();
    expect((float) $this->reports->accountLedger($ar)['closing'])->toBe(10000.0);
});

it('does not post anything for a draft run (only finalised runs reach the GL)', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPandL($this, $asset->id, 10000, 4000);

    $run = $this->generate->generate($asset, $this->march); // DRAFT — not finalised
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(ownerRunEntry($run))->toBeNull();
    $dueToOwner = LedgerAccount::where('code', '21802001')->first();
    expect((float) $this->reports->accountLedger($dueToOwner)['closing'])->toBe(0.0);
});

it('revise voids the old accrual and posts the corrected one; books stay balanced', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPandL($this, $asset->id, 10000, 4000); // net 6000

    $v1 = $this->finalise->finalise($this->generate->generate($asset, $this->march), $owner);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();
    expect((float) $this->reports->accountLedger(LedgerAccount::where('code', '21802001')->first())['closing'])->toBe(6000.0);

    // The books were corrected: an extra 1000 expense lands, then we revise.
    $this->post->post(['entry_date' => '2026-03-20', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 1000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 1000],
    ]]);

    $v2 = $this->revise->revise($v1, $owner); // net now 5000
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect($v2->id)->not->toBe($v1->id)
        ->and($v2->version)->toBe(2)
        ->and((float) $v2->net_distributable)->toBe(5000.0);

    // The old run is superseded; its GL entry has been voided (net zero), v2 posts 5000.
    expect($v1->fresh()->status)->toBe('superseded');
    $dueToOwner = LedgerAccount::where('code', '21802001')->first();
    expect((float) $this->reports->accountLedger($dueToOwner)['closing'])->toBe(5000.0);
    expect($this->reports->trialBalance()['balanced'])->toBeTrue();
});

it('refuses to finalise into a closed period (posting-date guard, in the service)', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->propertyOwners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPandL($this, $asset->id, 10000, 4000);
    $run = $this->generate->generate($asset, $this->march);

    // Close March, then attempt to finalise with a March posting date.
    app(PeriodService::class)->closePeriod($this->march);

    expect(fn () => $this->finalise->finalise($run, $owner, '2026-03-31'))
        ->toThrow(DomainException::class);
    // Nothing was finalised — the run is still a draft.
    expect($run->fresh()->status)->toBe('draft');
});
