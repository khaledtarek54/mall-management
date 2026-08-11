<?php

use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Reports\ComparativeStatementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The income statement beside the period before it.
 *
 * A single period's P&L says what happened; it cannot say whether that is normal. 180,000 of
 * maintenance is unremarkable next to 175,000 last month and alarming next to 40,000.
 *
 * The tests worth having are about the COMPARISON WINDOW and the edges: a period must compare
 * against one of equal length (comparing 31 days to 28 invents a variance that is really just
 * February), an account that stopped must still appear, and a percentage against zero must be
 * refused rather than rendered as infinity.
 */
function plPosting(float $revenue, string $date): void
{
    $bank = LedgerAccount::where('code', '11102001')->firstOrFail();
    $rent = LedgerAccount::where('code', '41101001')->firstOrFail();

    app(JournalPostingService::class)->post([
        'entry_date' => $date,
        'description_en' => 'Revenue',
        'lines' => [
            ['ledger_account_id' => $bank->id, 'debit' => $revenue, 'credit' => 0],
            ['ledger_account_id' => $rent->id, 'debit' => 0, 'credit' => $revenue],
        ],
    ]);
}

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

it('compares against the immediately preceding span of the SAME length', function () {
    // March has 31 days; the comparison must be the 31 days before it, not "February". Comparing a
    // 31-day month against a 28-day one invents a variance that is really just the calendar.
    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($report['prior_to'])->toBe('2026-02-28')
        ->and($report['prior_from'])->toBe('2026-01-29');  // 31 days, ending the day before March
});

it('shows the change and the percentage between two periods', function () {
    plPosting(10000, '2026-03-10');
    plPosting(8000, '2026-02-10');

    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-28'),
    );

    $revenue = $report['totals']['revenue'];

    expect($revenue['current'])->toBe(10000.0)
        ->and($revenue['prior'])->toBe(8000.0)
        ->and($revenue['change'])->toBe(2000.0)
        ->and($revenue['change_pct'])->toBe(25.0)
        // The NET line too: the first version of this read `net` where the statement provides
        // `net_profit`, so with a defensive `?? 0` it would have compared 0 to 0 for ever and
        // rendered "no change" every month. Asserting only the revenue total would not have caught
        // it — PHPStan did.
        ->and($report['totals']['net']['current'])->toBe(10000.0)
        ->and($report['totals']['net']['prior'])->toBe(8000.0);
});

it('refuses a percentage against zero rather than rendering infinity', function () {
    // "New this period" is a real answer and a percentage cannot express it. Rendering ∞ or 100%
    // would both be lies, and one of them is the kind people quote in a meeting.
    plPosting(5000, '2026-03-10');

    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-28'),
    );

    expect($report['totals']['revenue']['prior'])->toBe(0.0)
        ->and($report['totals']['revenue']['change'])->toBe(5000.0)
        ->and($report['totals']['revenue']['change_pct'])->toBeNull();
});

it('keeps an account that had activity LAST period and none this one', function () {
    // The change most worth seeing: a revenue stream that stopped. Dropping the row because the
    // current period has nothing to show would hide exactly that.
    plPosting(7000, '2026-02-10');

    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-28'),
    );

    $stopped = collect($report['rows'])->firstWhere('prior', 7000.0);

    expect($stopped)->not->toBeNull()
        ->and($stopped['current'])->toBe(0.0)
        ->and($stopped['change'])->toBe(-7000.0);
});

it('reads both periods through the SAME income-statement definition', function () {
    // Built on LedgerReportService rather than beside it: one definition of revenue and expense,
    // queried twice. A second implementation would drift, and the drift would surface as a variance
    // nobody could explain.
    plPosting(1000, '2026-03-10');

    $report = app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-28'),
    );

    expect($report['current']['total_revenue'])->toBe(1000.0)
        ->and($report['totals']['revenue']['current'])->toBe(1000.0);
});
