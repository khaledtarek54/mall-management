<?php

use App\Filament\Admin\Pages\Concerns\RendersFinancialStatement;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\LedgerAccount;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Reports\ComparativeStatementService;
use App\Services\Reports\ReportCsvExporter;
use App\Services\Reports\StatementSpread;
use App\Support\IssuingEntity;
use App\Support\Pdf\PdfDocument;
use App\Support\StatementGroups;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A chart-group subtotal stands under its HEADING, on every rendering of a financial statement.
 *
 * Measured on the demo books (2026-09-12), the income statement's revenue section read:
 *
 *     41101001  Base Rent Revenue          7,779,200.00
 *     …
 *     Total Operating Revenue             10,345,002.01
 *     42101001  Miscellaneous Income          12,440.00
 *     43101001  Sales Returns & Allowances    -6,500.00
 *     Total operating revenue             10,350,942.01
 *
 * Two lines that read as the same total disagreeing by 5,940, because nothing above the first said
 * it closed the `41` branch, and the two one-row groups between them belonged visibly to nothing.
 * EG-28 gave every statement the chart's subtotals and none of the five renderers printed the
 * heading a subtotal is read against — the standard layout of every accounting system is heading →
 * lines → "Total <group>", and the heading is the half that makes the total legible.
 *
 * The 12-month spread's PDF was worse: its template walked the raw rows and printed NO group lines
 * at all while the screen and CSV beside it printed subtotals — the renderer drift module 17 warns
 * about, wearing a third report's name. Its layout now lives once, in `StatementSpread::records()`.
 *
 * `StatementGroups::headingFor()` is the ONE resolver; each test below drives a different renderer
 * and each is a tooth (mutation-proved: removing the heading from that renderer alone turns exactly
 * its test red).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
});

/** A statement row as the report service emits one, off the shipped chart. */
function headingFixtureRow(string $code, float $amount): array
{
    $a = LedgerAccount::where('code', $code)->sole();

    return ['account_id' => $a->id, 'code' => $a->code, 'name_en' => $a->name_en, 'name_ar' => $a->name_ar, 'amount' => $amount];
}

/** Current assets ×2 (a group with something to add up) and ONE non-current asset (a one-row group). */
function headingFixtureSection(): array
{
    return [
        'Assets' => [
            'rows' => [headingFixtureRow('11101001', 400.0), headingFixtureRow('11102001', 600.0), headingFixtureRow('12101001', 4000.0)],
            'total' => 5000.0,
            'total_label' => 'Total assets',
        ],
    ];
}

/** The screen trait with nothing else attached — plus the ledger-link seam the pages inherit. */
function headingRenderer(bool $grouped = true): object
{
    return new class($grouped)
    {
        use RendersFinancialStatement;

        public function __construct(private bool $grouped) {}

        protected function groupStatements(): bool
        {
            return $this->grouped;
        }

        protected function ledgerUrlForAccount(?int $accountId): ?string
        {
            return $accountId === null ? null : "/gl/{$accountId}";
        }

        public function records(array $sections): array
        {
            return $this->statementRecords($sections);
        }

        public function url(array $record): ?string
        {
            return $this->ledgerUrlFor($record);
        }

        public function weight(array $record): string
        {
            return $this->statementWeight($record);
        }
    };
}

/** Post revenue into two chart groups — `41 Operating Revenue` (two rows) and `42 Other Income` (one). */
function headingFixturePostings(string $on = '2026-03-10', ?int $assetId = null): void
{
    $bank = LedgerAccount::where('code', '11102001')->firstOrFail();

    foreach ([['41101001', 10000], ['41102001', 4000], ['42101001', 1500]] as [$code, $amount]) {
        app(JournalPostingService::class)->post([
            'entry_date' => $on,
            'asset_id' => $assetId,
            'description_en' => 'Revenue '.$code,
            'lines' => [
                ['ledger_account_id' => $bank->id, 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => LedgerAccount::where('code', $code)->firstOrFail()->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);
    }
}

it('prints a heading above every group on the screen — the one-row group too', function () {
    $records = headingRenderer()->records(headingFixtureSection());

    // The reading order IS the assertion: heading · leaf · leaf · subtotal · heading · leaf · total.
    $kinds = array_map(fn (array $r): string => match (true) {
        $r['is_heading'] => 'heading:'.$r['code'],
        $r['is_subtotal'] => 'subtotal',
        $r['is_total'] => 'total',
        default => 'leaf:'.$r['code'],
    }, $records);

    expect($kinds)->toBe([
        'heading:11', 'leaf:11101001', 'leaf:11102001', 'subtotal',
        // `12` has one row, so it keeps NO subtotal — the row already is one — but it still gets its
        // heading, or that row reads as a stray under "Total Current Assets".
        'heading:12', 'leaf:12101001',
        'total',
    ]);

    $heading = $records[0];
    $r = headingRenderer();

    expect($heading['account'])->toBe('Current Assets')
        // No figure of its own: the group's total is the subtotal beneath.
        ->and($heading['amount'])->toBeNull()
        // A summary account has no ledger to open — the leaves beneath it do.
        ->and($r->url($heading))->toBeNull()
        ->and($r->url($records[1]))->toBe('/gl/'.$records[1]['account_id'])
        // Four kinds of line at four weights, so the eye can tell them apart.
        ->and($r->weight($heading))->toBe('semibold')
        ->and($r->weight($records[3]))->toBe('medium')
        ->and($r->weight($records[6]))->toBe('bold');
});

it('prints no heading where grouping is not shown', function () {
    // One group in the section: its subtotal would equal the section total, so `worthShowing()`
    // suppresses grouping — and a heading over the whole section would be the section label twice.
    $oneGroup = ['Assets' => ['rows' => [headingFixtureRow('11101001', 400.0), headingFixtureRow('11102001', 600.0)], 'total' => 1000.0, 'total_label' => 'Total assets']];

    expect(collect(headingRenderer()->records($oneGroup))->where('is_heading', true))->toBeEmpty()
        // The cash-flow statement opts out of chart grouping altogether (its sections are ACTIVITIES).
        ->and(collect(headingRenderer(grouped: false)->records(headingFixtureSection()))->where('is_heading', true))->toBeEmpty()
        ->and(StatementGroups::headingFor(['code' => null, 'name_en' => '', 'name_ar' => ''], 'en'))->toBeNull();
});

it('brackets the comparative reading the same way', function () {
    headingFixturePostings();

    $records = (new IncomeStatement)->comparativeRecords(app(ComparativeStatementService::class)->incomeStatement(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    ));

    $revenue = collect($records)->filter(fn (array $r): bool => in_array($r['code'], ['41', '41101001', '41102001', '42', '42101001'], true) || $r['is_subtotal'])->values();

    expect($revenue->map(fn (array $r) => $r['is_heading'] ? 'heading:'.$r['code'] : ($r['is_subtotal'] ? 'subtotal' : 'leaf:'.$r['code']))->all())
        ->toBe(['heading:41', 'leaf:41101001', 'leaf:41102001', 'subtotal', 'heading:42', 'leaf:42101001']);

    $heading = $revenue->first();

    // A heading compares nothing: every figure column is blank, in this reading as in the plain one.
    expect($heading['account'])->toBe('Operating Revenue')
        ->and([$heading['amount'], $heading['prior'], $heading['change'], $heading['change_pct'], $heading['account_id']])
        ->toBe([null, null, null, null, null]);
});

it('brackets the twelve-month spread on screen, in the CSV and in print', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    headingFixturePostings('2026-01-10', $asset->id);
    headingFixturePostings('2026-02-10', $asset->id);

    $months = collect(['2026-01', '2026-02'])->map(function (string $month): array {
        $start = CarbonImmutable::parse($month.'-01');

        return ['key' => 'm'.str_replace('-', '', $month), 'label' => $start->format('M'), 'from' => $start, 'to' => $start->endOfMonth()->endOfDay()];
    })->all();

    $spread = app(StatementSpread::class)->incomeStatement($months);
    $records = StatementSpread::records($spread);

    $revenue = collect($records)->filter(fn (array $r): bool => in_array($r['code'], ['41', '41101001', '41102001', '42', '42101001'], true) || $r['is_subtotal'])->values();

    // The screen's rows: heading → leaves → subtotal, then the one-row group under its own heading.
    expect($revenue->map(fn (array $r) => $r['is_heading'] ? 'heading:'.$r['code'] : ($r['is_subtotal'] ? 'subtotal' : 'leaf:'.$r['code']))->all())
        ->toBe(['heading:41', 'leaf:41101001', 'leaf:41102001', 'subtotal', 'heading:42', 'leaf:42101001'])
        // Every column blank on the heading — a spread row carries a cell per span and a heading
        // must carry none of them as 0.00.
        ->and([$revenue[0]['a_m202601'], $revenue[0]['a_m202602']])->toBe([null, null])
        ->and($revenue[3]['a_m202601'])->toBe(14000.0);

    // The printed spread reads the SAME records — it used to walk the raw rows and print no group
    // lines at all. Heading before its leaves, subtotal after them, on the page.
    $html = PdfDocument::make('accounting.pdf.income-statement-spread')
        ->data([
            'spread' => $spread,
            'records' => $records,
            'meta' => ['property' => 'A mall', 'period' => '2026', 'generated_on' => '01/01/2026', 'locale' => 'en'],
            ...IssuingEntity::forViewScopedTo(null),
        ])
        ->html();

    $at = fn (string $needle, int $offset = 0): int => strpos($html, $needle, $offset) ?: throw new RuntimeException("Not printed: {$needle}");

    // The heading ROWS, located by their markup and not by their words: the shared layout's
    // stylesheet mentions `.group-heading` on every statement (so a bare `toContain` was green with
    // the row deleted), and the section label "Operating revenue" differs from the group heading
    // "Operating Revenue" by case alone, which a wording change would erase.
    $firstHeading = $at('<tr class="group-heading">');
    $secondHeading = $at('<tr class="group-heading">', $firstHeading + 1);

    expect($firstHeading)->toBeLessThan($at('Base Rent Revenue'))
        ->and($at('Base Rent Revenue'))->toBeLessThan($at('Total Operating Revenue'))
        ->and($at('Total Operating Revenue'))->toBeLessThan($secondHeading)
        ->and($secondHeading)->toBeLessThan($at('Miscellaneous Income'))
        // …and the second heading names the one-row group it opens.
        ->and(substr($html, $secondHeading, 300))->toContain('Other Income');

    // …and the CSV blanks the heading's cells rather than writing a row of 0.00 a SUM would count.
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $csv = Livewire::test(IncomeStatement::class)
        ->set('year', 2026)
        ->set('spread', IncomeStatement::SPREAD_MONTHLY)
        ->instance()
        ->reportCsv();

    $headingRow = collect($csv['rows'])->first(fn (array $r): bool => $r[1] === '41' && $r[2] === 'Operating Revenue');
    $leafRow = collect($csv['rows'])->first(fn (array $r): bool => $r[1] === '41101001');

    expect($headingRow)->not->toBeNull()
        ->and(array_unique(array_slice($headingRow, 3)))->toBe([''])
        ->and((float) $leafRow[3 + 0])->toBe(10000.0);

    Filament::setTenant(null, isQuiet: true);
});

it('prints the spread through the real PDF service — the one door that hands the template its lines', function () {
    // The template has no fallback of its own, so if the service stopped passing `records` the
    // download would be a Blade fatal, not a flat statement. `%PDF` from the real renderer is the
    // whole assertion; the layout itself is proved on the HTML seam above.
    headingFixturePostings('2026-01-10');

    $months = collect(['2026-01', '2026-02'])->map(function (string $month): array {
        $start = CarbonImmutable::parse($month.'-01');

        return ['key' => 'm'.str_replace('-', '', $month), 'label' => $start->format('M'), 'from' => $start, 'to' => $start->endOfMonth()->endOfDay()];
    })->all();

    $spread = app(StatementSpread::class)->incomeStatement($months);

    $pdf = app(LedgerReportPdfService::class)->incomeStatementSpread($spread, null, 'All properties', 'FY2026', 'en');

    expect($pdf)->toStartWith('%PDF');
});

it('carries the heading into the CSV and the printed statement', function () {
    $csv = app(ReportCsvExporter::class)->balanceSheet([
        'assets' => collect([headingFixtureRow('11101001', 400.0), headingFixtureRow('11102001', 600.0), headingFixtureRow('12101001', 4000.0)]),
        'total_assets' => 5000.0,
        'liabilities' => collect(), 'total_liabilities' => 0.0,
        'equity' => collect(), 'total_equity' => 0.0,
        'net_income' => 0.0, 'total_equity_and_liabilities' => 0.0, 'balanced' => false,
    ]);

    $accounts = array_column($csv['rows'], 2);
    $index = fn (string $account): int => array_search($account, $accounts, true) !== false
        ? array_search($account, $accounts, true)
        : throw new RuntimeException("Not exported: {$account}");

    // Heading, its two leaves, its subtotal; then the one-row group under its heading.
    expect($index('Current Assets'))->toBeLessThan($index('Main Cashier'))
        ->and($index('Main Cashier'))->toBeLessThan($index('Total Current Assets'))
        ->and($index('Total Current Assets'))->toBeLessThan($index('Non-current Assets'))
        ->and($index('Non-current Assets'))->toBeLessThan($index('Furniture & Equipment'))
        // The heading row names its group's code and carries a BLANK amount, never 0.00.
        ->and($csv['rows'][$index('Current Assets')][1])->toBe('11')
        ->and($csv['rows'][$index('Current Assets')][3])->toBe('');

    $args = ['rows' => headingFixtureSection()['Assets']['rows'], 'total' => 5000.0, 'totalLabel' => 'Total assets', 'locale' => 'en'];
    $grouped = view('accounting.pdf._statement-section', $args)->render();
    $flat = view('accounting.pdf._statement-section', $args + ['grouped' => false])->render();

    $at = fn (string $needle): int => strpos($grouped, $needle) ?: throw new RuntimeException("Not printed: {$needle}");

    expect($grouped)->toContain('<tr class="group-heading">')
        ->and($at('Current Assets'))->toBeLessThan($at('Main Cashier'))
        ->and($at('Total Current Assets'))->toBeLessThan($at('Non-current Assets'))
        ->and($at('Non-current Assets'))->toBeLessThan($at('Furniture &amp; Equipment'))
        // Ungrouped (the cash-flow shape) prints neither heading nor subtotal.
        ->and($flat)->not->toContain('group-heading')
        ->and($flat)->not->toContain('Total Current Assets');
});

it('names the heading in the reader\'s language', function () {
    $group = StatementGroups::for([headingFixtureRow('11101001', 400.0)])[0];

    expect(StatementGroups::headingFor($group, 'en'))->toBe('Current Assets')
        ->and(StatementGroups::headingFor($group, 'ar'))->toBe(LedgerAccount::where('code', '11')->sole()->name_ar)
        ->and(StatementGroups::headingFor($group, 'ar'))->toMatch('/\p{Arabic}/u');
});
