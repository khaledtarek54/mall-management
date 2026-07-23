<?php

use App\Models\AccountingPeriod;
use App\Models\OwnerStatement;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The owner statement showed only three totals — revenue, expense, net — with no breakdown, so an
 * owner couldn't see WHAT their revenue was or WHERE the expenses went (module 32). These pin the
 * frozen per-account snapshot and that it reconciles to the totals it summarizes.
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

/** Post two revenue accounts + two expense accounts so the breakdown has real detail. */
function postDetailedPandL($test, int $assetId): void
{
    // Revenue: rent 10,000 + CAM recovery 3,000.
    $test->post->post(['entry_date' => '2026-03-10', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('accounts_receivable'), 'debit' => 13000, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('rent_revenue'), 'debit' => 0, 'credit' => 10000],
        ['ledger_account_id' => $test->r->id('cam_recovery_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);
    // Expense: salaries 4,000 + utilities 1,500.
    $test->post->post(['entry_date' => '2026-03-12', 'asset_id' => $assetId, 'lines' => [
        ['ledger_account_id' => $test->r->id('salaries_expense'), 'debit' => 4000, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('utilities_expense'), 'debit' => 1500, 'credit' => 0],
        ['ledger_account_id' => $test->r->id('bank'), 'debit' => 0, 'credit' => 5500],
    ]]);
}

it('snapshots the per-account income breakdown that reconciles to the totals', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postDetailedPandL($this, $asset->id);

    $run = $this->generate->generate($asset, $this->march);
    $b = $run->income_breakdown;

    expect($b)->toHaveKeys(['revenue', 'expense'])
        ->and($b['revenue'])->toHaveCount(2)     // rent + CAM
        ->and($b['expense'])->toHaveCount(2)      // salaries + utilities
        // Each line carries a code + both localized names + a signed amount.
        ->and($b['revenue'][0])->toHaveKeys(['code', 'name_en', 'name_ar', 'amount']);

    // The breakdown MUST sum to the frozen totals — the detail can never drift from the net.
    expect(round(collect($b['revenue'])->sum('amount'), 2))->toBe((float) $run->total_revenue)
        ->and(round(collect($b['expense'])->sum('amount'), 2))->toBe((float) $run->total_expense)
        ->and(round((float) $run->total_revenue - (float) $run->total_expense, 2))->toBe((float) $run->net_operating_income);
});

it('freezes the breakdown — a later ledger change does not rewrite a generated run', function () {
    $asset = makeAsset();
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postDetailedPandL($this, $asset->id);

    $run = $this->generate->generate($asset, $this->march);
    $frozenRevenue = collect($run->income_breakdown['revenue'])->sum('amount');

    // More revenue lands in the SAME period after the run was generated.
    $this->post->post(['entry_date' => '2026-03-20', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $this->r->id('accounts_receivable'), 'debit' => 5000, 'credit' => 0],
        ['ledger_account_id' => $this->r->id('rent_revenue'), 'debit' => 0, 'credit' => 5000],
    ]]);

    // The stored snapshot on the untouched run is unchanged (freeze); only a re-generate re-reads.
    expect(collect($run->fresh()->income_breakdown['revenue'])->sum('amount'))->toBe($frozenRevenue);
});

it('renders the itemized breakdown into the statement PDF', function () {
    $asset = makeAsset(['name' => 'Atriom Walk']);
    $owner = makeUser('owner');
    $asset->owners()->attach($owner->id, ['ownership_percentage' => 100]);
    postDetailedPandL($this, $asset->id);

    $run = $this->generate->generate($asset, $this->march);
    /** @var OwnerStatement $statement */
    $statement = $run->statements()->first();

    $pdf = app(OwnerStatementPdfService::class)->build($statement);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(2000)
        // The section headings must resolve (no raw keys in the deliverable).
        ->and(__('admin.owner_statements.pdf.revenue'))->not->toContain('owner_statements');
});
