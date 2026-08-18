<?php

use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * GAP ANALYSIS, module 32 — a part-owner is distributed the WHOLE property net.
 *
 * `GenerateOwnerStatementRunService` weights each owner by `ownership_percentage / Σ percentage`,
 * so the shares always sum to the full net. With every owner recorded that is right. With a
 * partially-recorded ownership it is not: one owner at 50% has Σ = 50, so their weight is 50/50 = 1
 * and they are allocated 100% of the net.
 *
 * Nothing prevents that state. `AssetOwnersRelationManager` validates each row `0.01..100`
 * independently; no cross-row rule requires the shares to sum to 100, and there is no guard on the
 * `AssetOwner` pivot or in the generate/finalise services (searched repo-wide).
 *
 * **Benchmark.** Yardi Investment Manager and AppFolio owner accounting distribute an owner their
 * OWNERSHIP share; an incomplete ownership register leaves the remainder undistributed rather than
 * inflating the recorded owner's cut. Atriom's normalisation is the deliberate opposite — the
 * service comment says shares "ALWAYS sum to the full net" so that all the property money reaches
 * the owner — which is correct only under module 32's stated v1 assumption of one owner at 100%.
 * The screen does not enforce that assumption, so the operator can leave it.
 *
 * **Why it is money, not cosmetics.** `net_distributable` is what finalise posts as
 * Dr owner_distributions / Cr due_to_owner, and `owner_share` is the cap `DisbursementService`
 * pays against. A 50% owner is therefore accrued and can be paid twice what they are owed.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->post = app(JournalPostingService::class);
    $this->r = app(AccountResolver::class);
    $this->generate = app(GenerateOwnerStatementRunService::class);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 15));
});

/** Post revenue + expense for a property inside March 2026. Named for this file — see the helper-uniqueness gate. */
function postPandLForShareTest($test, int $assetId, float $revenue, float $expense): void
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

it('gives a sole 100% owner the whole net — the control', function () {
    $asset = makeAsset();
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 100]);
    postPandLForShareTest($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);

    expect((float) $run->statements->first()->owner_share)->toBe(6000.0);
});

it('does not distribute more than the ownership actually recorded', function () {
    $asset = makeAsset();
    // Jawad holds half the mall; the other half belongs to a partner not recorded in Atriom.
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 50]);
    postPandLForShareTest($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);
    $statement = $run->statements->first();

    // He owns 50% of a 6,000 net, so 3,000 is his. Today he is allocated the full 6,000.
    expect((float) $statement->ownership_percentage)->toBe(50.0)
        ->and((float) $statement->owner_share)->toBe(3000.0)
        ->and((float) $run->net_distributable)->toBe(3000.0);
})->skip('GAP ANALYSIS 2026-08-18 — proven finding, fix awaiting a decision. Today a part-owner is allocated the WHOLE net (pct/Σpct normalisation). Yardi/AppFolio distribute pct/100 and leave the remainder undistributed. Un-skip with the fix.');

it('splits two part-owners by their own shares, not by their ratio', function () {
    $asset = makeAsset();
    // 30% + 30% recorded; 40% belongs to owners not in the system.
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 30]);
    $asset->propertyOwners()->attach(makeUser('owner')->id, ['ownership_percentage' => 30]);
    postPandLForShareTest($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);

    // 30% of 6,000 each = 1,800. Today the ratio normalisation gives each 3,000.
    expect($run->statements->pluck('owner_share')->map(fn ($s) => (float) $s)->all())
        ->each->toBe(1800.0);
})->skip('GAP ANALYSIS 2026-08-18 — proven finding, fix awaiting a decision. Today a part-owner is allocated the WHOLE net (pct/Σpct normalisation). Yardi/AppFolio distribute pct/100 and leave the remainder undistributed. Un-skip with the fix.');
