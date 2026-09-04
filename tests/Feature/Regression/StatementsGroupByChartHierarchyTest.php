<?php

use App\Filament\Admin\Pages\Concerns\RendersFinancialStatement;
use App\Models\LedgerAccount;
use App\Services\Reports\ReportCsvExporter;
use App\Support\StatementGroups;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * EG-28 (second half) — a financial statement is read by the chart's own subtotals.
 *
 * The statements listed every moving account flat under its type: a balance sheet was forty-odd
 * leaf lines with one figure at the bottom, and the summary accounts the chart already models
 * (`is_postable = false`) appeared on no statement at all. **`parent_id` was read by no report.**
 * Current versus non-current, operating revenue versus other income versus sales returns — the
 * distinctions an accountant reads a statement by — were all in the chart and on none of the pages.
 *
 * The risk this pins is not that the grouping looks wrong; it is that grouping **loses or
 * duplicates money**. Every test here that touches a statement asserts the subtotals foot back to
 * the section total, because a group that silently dropped a row would still render as a tidy
 * statement.
 *
 * ## Three renderers, one helper
 *
 * The screen, the CSV and the PDF each build a statement their own way, and EG-36 shipped exactly
 * one of those out of step before this. They all resolve through `StatementGroups`, and the tests
 * below drive all three against the same chart so a fix to one cannot leave the other two behind.
 */
beforeEach(fn () => test()->seed(ChartOfAccountsSeeder::class));

/** A chart row by code — the shipped chart is the fixture, since that is what an operator has. */
function chartRow(string $code): LedgerAccount
{
    return LedgerAccount::where('code', $code)->sole();
}

/**
 * Two postable accounts per group, off the shipped chart.
 *
 * Named codes rather than `->first()`, because a one-row group deliberately gets no subtotal and a
 * fixture that happened to produce one would make these tests pass for the wrong reason.
 */
const CURRENT_ASSETS = ['11101001', '11102001'];
const NONCURRENT_ASSETS = ['12101001', '12201001'];

/** A statement row as the report service emits one. */
function statementRow(string $code, float $amount): array
{
    $a = chartRow($code);

    return [
        'account_id' => $a->id,
        'code' => $a->code,
        'name_en' => $a->name_en,
        'name_ar' => $a->name_ar,
        'amount' => $amount,
    ];
}

/** The trait under test, with nothing else attached to it. */
function statementRenderer(bool $grouped = true): object
{
    return new class($grouped)
    {
        use RendersFinancialStatement;

        public function __construct(private bool $grouped) {}

        protected function groupStatements(): bool
        {
            return $this->grouped;
        }

        /** @param  array<string, mixed>  $sections */
        public function records(array $sections): array
        {
            return $this->statementRecords($sections);
        }

        public function weight(array $record): string
        {
            return $this->statementWeight($record);
        }
    };
}

it('groups a leaf by its highest ancestor below the root, not by its immediate parent', function () {
    // A cash account sits four levels deep. Its group is `11 Current Assets` — the step below the
    // root — and emphatically not `1120` or whatever its immediate parent happens to be, which is
    // the distinction the whole feature turns on.
    $leaf = LedgerAccount::where('type', 'asset')->where('is_postable', true)
        ->where('code', 'like', '11%')->orderBy('code')->first();

    expect($leaf)->not->toBeNull();

    $groups = StatementGroups::for([[
        'account_id' => $leaf->id, 'code' => $leaf->code,
        'name_en' => $leaf->name_en, 'name_ar' => $leaf->name_ar, 'amount' => 100.0,
    ]]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['code'])->toBe('11')
        ->and($groups[0]['name_en'])->toBe('Current Assets')
        // The Arabic name comes off the chart row, so a bilingual statement needs no second list.
        ->and($groups[0]['name_ar'])->toBe('الأصول المتداولة')
        ->and($groups[0]['code'])->not->toBe($leaf->parent?->code);
});

it('splits an asset section into current and non-current, and the subtotals foot to the total', function () {
    $rows = [
        statementRow(CURRENT_ASSETS[0], 400.0),
        statementRow(CURRENT_ASSETS[1], 600.0),
        statementRow(NONCURRENT_ASSETS[0], 1500.0),
        statementRow(NONCURRENT_ASSETS[1], 2500.0),
    ];

    $groups = StatementGroups::for($rows);

    expect(array_column($groups, 'code'))->toBe(['11', '12'])
        ->and($groups[0]['total'])->toBe(1000.0)
        ->and($groups[1]['total'])->toBe(4000.0)
        // The tie-out. A grouping that drops a row still renders as a tidy statement.
        ->and(array_sum(array_column($groups, 'total')))->toBe(5000.0)
        ->and(StatementGroups::worthShowing($groups))->toBeTrue();
});

it('resolves by code when the row carries no account id', function () {
    // The comparative income statement compares two periods and never reads the chart, so its rows
    // have a code and no id. Answering only the id form would have grouped a plain statement and
    // left its comparative twin flat — the screen disagreeing with itself over one checkbox.
    $leaf = LedgerAccount::where('code', 'like', '41%')->where('is_postable', true)->orderBy('code')->firstOrFail();

    $groups = StatementGroups::for([['code' => $leaf->code, 'amount' => 500.0]]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['code'])->toBe('41')
        ->and($groups[0]['total'])->toBe(500.0);
});

it('puts a row with no chart account in an ungrouped bucket that gets no subtotal', function () {
    $leaf = statementRow(CURRENT_ASSETS[0], 1000.0);

    // The balance sheet's synthetic net-income line: a real figure with no account behind it.
    $synthetic = ['code' => null, 'name_en' => 'Net income', 'name_ar' => 'صافي الدخل', 'amount' => 250.0];

    $groups = StatementGroups::for([$leaf, $synthetic]);

    expect($groups)->toHaveCount(2)
        // Ungrouped comes last, and is identifiable by a null code rather than by position.
        ->and($groups[1]['code'])->toBeNull()
        ->and($groups[1]['total'])->toBe(250.0)
        // It still prints and still counts — losing it would unbalance the balance sheet.
        ->and(array_sum(array_column($groups, 'total')))->toBe(1250.0);
});

it('does not group a section that has only one group', function () {
    // The subtotal would equal the section total, and a statement printing one figure twice under
    // two names reads as an error. So a single-group section renders exactly as it did before.
    $rows = [statementRow(CURRENT_ASSETS[0], 600.0), statementRow(CURRENT_ASSETS[1], 400.0)];

    expect(StatementGroups::worthShowing(StatementGroups::for($rows)))->toBeFalse();

    $records = statementRenderer()->records([
        'Assets' => ['rows' => $rows, 'total' => 1000.0, 'total_label' => 'Total assets'],
    ]);

    expect(array_filter($records, fn ($r) => $r['is_subtotal']))->toBeEmpty()
        ->and($records)->toHaveCount(3); // the two leaves, then the section total
});

it('emits subtotal rows on the screen, and they foot to the section total', function () {
    $records = statementRenderer()->records([
        'Assets' => [
            'rows' => [
                statementRow(CURRENT_ASSETS[0], 400.0), statementRow(CURRENT_ASSETS[1], 600.0),
                statementRow(NONCURRENT_ASSETS[0], 1500.0), statementRow(NONCURRENT_ASSETS[1], 2500.0),
            ],
            'total' => 5000.0,
            'total_label' => 'Total assets',
        ],
    ]);

    $subtotals = array_values(array_filter($records, fn ($r) => $r['is_subtotal']));

    expect($subtotals)->toHaveCount(2)
        ->and($subtotals[0]['account'])->toBe('Total Current Assets')
        ->and($subtotals[0]['amount'])->toBe(1000.0)
        ->and($subtotals[1]['account'])->toBe('Total Non-current Assets')
        ->and($subtotals[1]['amount'])->toBe(4000.0)
        // The section total is unchanged by grouping — that is the invariant, not the layout.
        ->and(array_sum(array_column($subtotals, 'amount')))
        ->toBe(collect($records)->firstWhere('is_total', true)['amount']);
});

it('prints a leaf, a group subtotal and a section total at three different weights', function () {
    $r = statementRenderer();

    expect($r->weight(['is_total' => false, 'is_subtotal' => false]))->toBe('normal')
        ->and($r->weight(['is_total' => false, 'is_subtotal' => true]))->toBe('medium')
        ->and($r->weight(['is_total' => true, 'is_subtotal' => false]))->toBe('bold');
});

it('never offers a drill-through on a subtotal', function () {
    // A subtotal is not an account. A link there would open the general ledger for nothing.
    $records = statementRenderer()->records([
        'Assets' => [
            'rows' => [
                statementRow(CURRENT_ASSETS[0], 400.0), statementRow(CURRENT_ASSETS[1], 600.0),
                statementRow(NONCURRENT_ASSETS[0], 1500.0), statementRow(NONCURRENT_ASSETS[1], 2500.0),
            ],
            'total' => 5000.0,
            'total_label' => 'Total assets',
        ],
    ]);

    foreach ($records as $record) {
        if ($record['is_subtotal'] || $record['is_total']) {
            expect($record['account_id'])->toBeNull();
        } else {
            // The control — a leaf still drills through, or this test would pass on a screen that
            // had lost every link.
            expect($record['account_id'])->not->toBeNull();
        }
    }
});

it('groups the CSV export the same way the screen groups', function () {
    // EG-36's lesson: a statement whose own export is laid out differently is the failure. The CSV
    // takes the same helper, so the two cannot drift.
    $csv = app(ReportCsvExporter::class)->balanceSheet([
        'assets' => collect([
            statementRow(CURRENT_ASSETS[0], 400.0), statementRow(CURRENT_ASSETS[1], 600.0),
            statementRow(NONCURRENT_ASSETS[0], 1500.0), statementRow(NONCURRENT_ASSETS[1], 2500.0),
        ]),
        'total_assets' => 5000.0,
        'liabilities' => collect(),
        'total_liabilities' => 0.0,
        'equity' => collect(),
        'total_equity' => 0.0,
        'net_income' => 0.0,
        // The two figures the export now foots on (SW-182). This fixture is assets-only, so the
        // sheet deliberately does not balance and the check line reads ✗.
        'total_equity_and_liabilities' => 0.0,
        'balanced' => false,
    ]);

    $accounts = array_column($csv['rows'], 2);

    expect($accounts)->toContain('Total Current Assets')
        ->and($accounts)->toContain('Total Non-current Assets');

    // The subtotals foot to the section subtotal the CSV already printed.
    $groupTotals = collect($csv['rows'])
        ->filter(fn ($r) => str_starts_with((string) $r[2], 'Total '))
        ->sum(fn ($r) => (float) $r[3]);

    expect($groupTotals)->toBe(5000.0);
});

it('does not group the cash-flow statement, whose sections are activities and not chart branches', function () {
    // Operating legitimately mixes revenue, receivables, payables and depreciation from five
    // different roots. Subtotalling those by root would print headings that say nothing about cash.
    $rows = [
        statementRow(CURRENT_ASSETS[0], 400.0), statementRow(CURRENT_ASSETS[1], 600.0),
        statementRow('21101001', -400.0), statementRow('21102001', -200.0),
    ];

    // The helper still finds two groups — the opt-out is the renderer's decision, not the helper's.
    expect(StatementGroups::worthShowing(StatementGroups::for($rows)))->toBeTrue();

    $records = statementRenderer(grouped: false)->records([
        'Operating' => ['rows' => $rows, 'total' => 600.0, 'total_label' => 'Net cash from operating'],
    ]);

    expect(array_filter($records, fn ($r) => $r['is_subtotal']))->toBeEmpty()
        ->and($records)->toHaveCount(5);
});

it('renders the shared PDF partial with its subtotals, and again without them', function () {
    $rows = [
        statementRow(CURRENT_ASSETS[0], 400.0), statementRow(CURRENT_ASSETS[1], 600.0),
        statementRow(NONCURRENT_ASSETS[0], 1500.0), statementRow(NONCURRENT_ASSETS[1], 2500.0),
    ];

    $args = ['rows' => $rows, 'total' => 5000.0, 'totalLabel' => 'Total assets', 'locale' => 'en'];

    $grouped = view('accounting.pdf._statement-section', $args)->render();
    $flat = view('accounting.pdf._statement-section', $args + ['grouped' => false])->render();

    expect($grouped)->toContain('Total Current Assets')
        ->toContain('subtotal-row')
        // The total the section foots to is on both, grouped or not.
        ->toContain('Total assets')
        ->and($flat)->not->toContain('Total Current Assets')
        ->and($flat)->toContain('Total assets');
});

it('gives a one-row group no subtotal, because the row already is one', function () {
    // The same rule `worthShowing()` applies to a section, one level down. Without it a balance
    // sheet prints "Share capital 500,000" and then "Total Capital 500,000" — four lines for two
    // facts, and the row's own name says more than the heading repeating its figure.
    $groups = StatementGroups::for([
        statementRow(CURRENT_ASSETS[0], 400.0),
        statementRow(CURRENT_ASSETS[1], 600.0),
        statementRow(NONCURRENT_ASSETS[0], 4000.0),
    ]);

    expect($groups[0]['code'])->toBe('11')
        ->and($groups[0]['show_subtotal'])->toBeTrue()
        ->and($groups[1]['code'])->toBe('12')
        ->and($groups[1]['show_subtotal'])->toBeFalse();

    $records = statementRenderer()->records([
        'Assets' => [// `array_merge`, never `+` — PHP's `+` keeps the LEFT operand's keys and would silently
            // drop the second group entirely, leaving this test green for the wrong reason.
            'rows' => array_merge($groups[0]['rows'], $groups[1]['rows']), 'total' => 5000.0, 'total_label' => 'Total assets'],
    ]);

    // Exactly one subtotal, on the group that has something to add up.
    $subtotals = array_values(array_filter($records, fn ($r) => $r['is_subtotal']));

    expect($subtotals)->toHaveCount(1)
        ->and($subtotals[0]['account'])->toBe('Total Current Assets');
});
