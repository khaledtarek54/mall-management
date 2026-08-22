<?php

use App\Filament\Admin\Pages\IncomeStatement;
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

it('names the account on every comparative row, instead of a code beside a blank', function () {
    // Found reviewing EG-28. `line()` read `$row['label']`, and NEITHER source emits that key —
    // `LedgerReportService::statementRow()` and `BudgetService::asIncomeStatement()` both return
    // `name_en` / `name_ar`. So every row of this statement rendered as a code and an empty name,
    // on all three bases, for the life of the screen. The plain income statement resolves the name
    // by locale; the comparative one is the same statement with two more columns and never did.
    plPosting(10000, '2026-03-10');

    $row = collect(app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    )['rows'])->firstWhere('code', '41101001');

    $account = LedgerAccount::where('code', '41101001')->sole();

    expect($row['label'])->toBe('Base Rent Revenue')
        ->and($row['label'])->not->toBe('')
        // Both languages ride along, so the page picks the way every other screen does rather than
        // the service deciding for it.
        ->and($row['name_ar'])->toBe($account->name_ar)
        // And the id, so a comparison can be opened in the ledger like its plain twin. It was
        // dropped by `line()`, not absent because a comparison has nothing to open.
        ->and($row['account_id'])->toBe($account->id);
});

it('gives the comparative statement the same chart subtotals as the plain one', function () {
    // EG-28. The comparative rows carry a code and no id, which `StatementGroups` resolves either
    // way — so one checkbox cannot leave this screen laid out differently from the statement it is
    // a comparison of.
    plPosting(10000, '2026-03-10');

    $bank = LedgerAccount::where('code', '11102001')->firstOrFail();

    // Two accounts under `41 Operating Revenue` and one under `42 Other Income`. Both halves of the
    // rule are on screen at once: 41 has something to add up and gets a subtotal, 42 is a one-row
    // group and does not.
    foreach ([['41102001', 4000], ['42101001', 1500]] as [$code, $amount]) {
        app(JournalPostingService::class)->post([
            'entry_date' => '2026-03-11',
            'description_en' => 'Revenue '.$code,
            'lines' => [
                ['ledger_account_id' => $bank->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => LedgerAccount::where('code', $code)->firstOrFail()->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);
    }

    $page = new IncomeStatement;
    $records = $page->comparativeRecords(app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    ));

    $subtotals = collect($records)->filter(fn ($r) => $r['is_subtotal'])->values();
    $subtotal = $subtotals->first();

    // Exactly one — `42 Other Income` has a single row, which already is its own subtotal.
    expect($subtotals)->toHaveCount(1)
        ->and($subtotal)->not->toBeNull()
        ->and($subtotal['account'])->toBe('Total Operating Revenue')
        ->and($subtotal['amount'])->toBe(14000.0)
        // A subtotal compares too, or it would be the one line on a comparative statement with
        // nothing to compare against. Nothing was posted in the prior span.
        ->and($subtotal['prior'])->toBe(0.0)
        ->and($subtotal['change'])->toBe(14000.0)
        // Null, not 0% or infinity, against a zero prior — the rule the column already formats by.
        ->and($subtotal['change_pct'])->toBeNull();
});
