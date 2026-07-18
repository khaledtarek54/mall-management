<?php

use App\Models\AccountingPeriod;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Owner statements — slice 4 (draft generation). The property's net (income − expenses) becomes
 * the owner's statement. v1: one owner per mall gets 100% (no management fee, no co-owner split).
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

/** Post revenue + expense for a property inside March 2026. */
function postPropertyPandL($test, int $assetId, float $revenue, float $expense): void
{
    if ($revenue > 0) {
        $test->post->post(['entry_date' => '2026-03-10', 'asset_id' => $assetId, 'lines' => [
            ['ledger_account_id' => $test->r->id('accounts_receivable'), 'debit' => $revenue, 'credit' => 0],
            ['ledger_account_id' => $test->r->id('rent_revenue'), 'debit' => 0, 'credit' => $revenue],
        ]]);
    }
    if ($expense > 0) {
        $test->post->post(['entry_date' => '2026-03-12', 'asset_id' => $assetId, 'lines' => [
            ['ledger_account_id' => $test->r->id('salaries_expense'), 'debit' => $expense, 'credit' => 0],
            ['ledger_account_id' => $test->r->id('bank'), 'debit' => 0, 'credit' => $expense],
        ]]);
    }
}

it('generates a draft statement giving the sole owner 100% of the property net', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPropertyPandL($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);

    expect($run->status)->toBe('draft')
        ->and((float) $run->total_revenue)->toBe(10000.0)
        ->and((float) $run->total_expense)->toBe(4000.0)
        ->and((float) $run->net_operating_income)->toBe(6000.0)
        ->and((float) $run->net_distributable)->toBe(6000.0)
        ->and($run->statements)->toHaveCount(1);

    $statement = $run->statements->first();
    expect((float) $statement->owner_share)->toBe(6000.0)
        ->and((float) $statement->weight)->toBe(1.0)
        ->and((float) $statement->ownership_percentage)->toBe(100.0)
        ->and($statement->user_id)->toBe($owner->id)
        ->and($statement->asset_id)->toBe($asset->id)
        ->and($statement->status)->toBe('draft');
});

it('regenerating a draft reuses the same run and refreshes its figures', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPropertyPandL($this, $asset->id, 10000, 4000);

    $first = $this->generate->generate($asset, $this->march);
    // Add more expense, regenerate.
    $this->post->post(['entry_date' => '2026-03-20', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('salaries_expense'), 'debit' => 1000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('bank'), 'debit' => 0, 'credit' => 1000],
    ]]);
    $second = $this->generate->generate($asset, $this->march);

    expect($second->id)->toBe($first->id)
        ->and($second->version)->toBe(1)
        ->and((float) $second->net_distributable)->toBe(5000.0)      // 10000 − 5000
        ->and($second->statements()->count())->toBe(1)              // not duplicated
        ->and((float) $second->statements->first()->owner_share)->toBe(5000.0);
});

it('generates a run with zero distributed when the property has no current owner', function () {
    $asset = makeAsset();
    postPropertyPandL($this, $asset->id, 8000, 3000);

    $run = $this->generate->generate($asset, $this->march);

    expect((float) $run->net_operating_income)->toBe(5000.0)
        ->and((float) $run->net_distributable)->toBe(0.0)
        ->and($run->statements)->toHaveCount(0);
});

it('excludes an owner whose tenure ended before the period', function () {
    $asset = makeAsset();
    $former = makeUser('owner');
    $asset->owners()->attach($former->id, ['ownership_percentage' => 100, 'ended_at' => '2026-01-31']);
    postPropertyPandL($this, $asset->id, 8000, 3000);

    $run = $this->generate->generate($asset, $this->march);

    expect((float) $run->net_distributable)->toBe(0.0)
        ->and($run->statements)->toHaveCount(0);
});

it('splits normalized across co-owners so the shares always sum to the full net (defensive)', function () {
    $asset = makeAsset();
    $a = makeUser('owner');
    $b = makeUser('owner');
    $asset->owners()->attach($a->id, ['ownership_percentage' => 60]);
    $asset->owners()->attach($b->id, ['ownership_percentage' => 40]);
    postPropertyPandL($this, $asset->id, 10000, 4000); // net 6000

    $run = $this->generate->generate($asset, $this->march);

    expect($run->statements)->toHaveCount(2)
        ->and((float) $run->net_distributable)->toBe(6000.0)
        ->and(round((float) $run->statements->sum('owner_share'), 2))->toBe(6000.0);

    $shareA = (float) $run->statements->firstWhere('user_id', $a->id)->owner_share;
    $shareB = (float) $run->statements->firstWhere('user_id', $b->id)->owner_share;
    expect($shareA)->toBe(3600.0)->and($shareB)->toBe(2400.0);
});

it('refuses to regenerate over a finalised run', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postPropertyPandL($this, $asset->id, 10000, 4000);

    $run = $this->generate->generate($asset, $this->march);
    $run->update(['status' => 'finalised']); // simulate slice-5 finalise

    expect(fn () => $this->generate->generate($asset, $this->march))
        ->toThrow(DomainException::class, 'finalised');
});
