<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\IssuingEntity;
use App\Support\LedgerTree;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * **A trial balance for a month opens with the balance brought forward** — client meeting
 * 2026-09-02, point 7: *"Debit · Credit · افتتاحي · ختامي — four columns in the trial balance."*
 *
 * Until 2026-09-11 `LedgerReportService::trialBalance()` aggregated journal lines INSIDE the
 * window only, so for any window narrower than the whole ledger it was a movement summary under
 * the trial balance's name. Measured on the demo books for August 2026: bank `11102001` printed
 * **Dr 17,000** — August's net movement — where its balance at 31 August is **Cr 1,948,000**, and
 * three accounts carrying a balance but no August entry were absent from the statement entirely.
 * The opening-balance rule existed the whole time in `accountLedger()` ("movement strictly before
 * `from`"); this report never called it.
 *
 * Yardi prints Beginning · Debits · Credits · Ending; SAP and Odoo print the same four; the Egyptian
 * ميزان المراجعة reads each of opening and closing as a debit/credit pair. So: three pairs, each
 * footing on its own, on the screen, the CSV and the PDF.
 *
 * The fixture is built so that opening, movement and closing DISAGREE on every row that matters —
 * a bank account that opened at 100,000 and moved by −17,000, an equity account that opened and
 * did not move, an expense that had no opening — because a fixture where two of the three columns
 * coincide passes with the wrong column printed under the right heading.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $r = app(AccountResolver::class);
    $post = app(JournalPostingService::class);

    // July: capital paid into the bank — history BEFORE the window.
    $post->post(['entry_date' => '2026-07-15', 'lines' => [
        ['ledger_account_id' => $r->id('bank'), 'debit' => 100000, 'credit' => 0],
        ['ledger_account_id' => $r->id('capital'), 'debit' => 0, 'credit' => 100000],
    ]]);

    // August: salaries paid from the bank — the window's own movement.
    $post->post(['entry_date' => '2026-08-10', 'lines' => [
        ['ledger_account_id' => $r->id('salaries_expense'), 'debit' => 17000, 'credit' => 0],
        ['ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 17000],
    ]]);

    $this->bank = $r->id('bank');
    $this->capital = $r->id('capital');
    $this->salaries = $r->id('salaries_expense');

    $this->from = CarbonImmutable::create(2026, 8, 1)->startOfDay();
    $this->to = CarbonImmutable::create(2026, 8, 31)->endOfDay();

    $this->row = function (array $report, int $accountId): array {
        $row = collect($report['rows'])->first(fn (array $r): bool => $r['account_id'] === $accountId);
        expect($row)->not->toBeNull("account {$accountId} is missing from the statement");

        return $row;
    };
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('carries every account forward: opening, the movement, and the closing that follows from them', function () {
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    // The bank: opened at 100,000 Dr, paid out 17,000, closes at 83,000 Dr. Three different
    // figures on one row — the whole reason a movement-only read was wrong.
    $bank = ($this->row)($report, $this->bank);
    expect($bank['opening_debit'])->toBe(100000.0)
        ->and($bank['opening_credit'])->toBe(0.0)
        ->and($bank['debit_total'])->toBe(0.0)
        ->and($bank['credit_total'])->toBe(17000.0)
        ->and($bank['debit_balance'])->toBe(83000.0)
        ->and($bank['credit_balance'])->toBe(0.0);

    // Capital: a balance and NO movement in August. The row that used to vanish.
    $capital = ($this->row)($report, $this->capital);
    expect($capital['opening_credit'])->toBe(100000.0)
        ->and($capital['debit_total'])->toBe(0.0)
        ->and($capital['credit_total'])->toBe(0.0)
        ->and($capital['credit_balance'])->toBe(100000.0);

    // Salaries: no opening, this month's charge, closes at the charge.
    $salaries = ($this->row)($report, $this->salaries);
    expect($salaries['opening_debit'])->toBe(0.0)
        ->and($salaries['opening_credit'])->toBe(0.0)
        ->and($salaries['debit_total'])->toBe(17000.0)
        ->and($salaries['debit_balance'])->toBe(17000.0);

    // All three pairs foot — and foot at the figures the fixture says, not merely at each other.
    expect($report['total_opening_debit'])->toBe(100000.0)
        ->and($report['total_opening_credit'])->toBe(100000.0)
        ->and($report['total_movement_debit'])->toBe(17000.0)
        ->and($report['total_movement_credit'])->toBe(17000.0)
        ->and($report['total_debit'])->toBe(100000.0)
        ->and($report['total_credit'])->toBe(100000.0)
        ->and($report['balanced'])->toBeTrue();
});

it('opens the whole-ledger read at nothing, exactly as every caller without a window has always had it', function () {
    // The control: with no `from` there is no "before", so opening is zero on every row and the
    // closing IS the movement. Every existing tie-out test in the suite reads the report this way.
    $report = app(LedgerReportService::class)->trialBalance();

    $bank = ($this->row)($report, $this->bank);
    expect($bank['opening_debit'])->toBe(0.0)
        ->and($bank['opening_credit'])->toBe(0.0)
        ->and($bank['debit_total'])->toBe(100000.0)
        ->and($bank['credit_total'])->toBe(17000.0)
        ->and($bank['debit_balance'])->toBe(83000.0)
        ->and($report['total_opening_debit'])->toBe(0.0)
        ->and($report['balanced'])->toBeTrue();
});

it('reads the opening exactly as the general ledger does, so the two reports cannot disagree', function () {
    // The rule this report now shares with `accountLedger()` — "movement strictly before from".
    // Asserted against that method rather than against a literal, because the literal is what
    // the fixture already proved; what matters is that a change to one reaches the other.
    $reports = app(LedgerReportService::class);
    $account = LedgerAccount::findOrFail($this->bank);

    $ledger = $reports->accountLedger($account, null, $this->from, $this->to);
    $bank = ($this->row)($reports->trialBalance(null, $this->from, $this->to), $this->bank);

    expect($ledger['opening'])->toBe($bank['opening_debit'] - $bank['opening_credit'])
        ->and($ledger['closing'])->toBe($bank['debit_balance'] - $bank['credit_balance']);
});

it('prints the three pairs on the CSV, with a totals line that foots each pair', function () {
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);
    $csv = app(ReportCsvExporter::class)->trialBalance($report);

    // code · account · type · opening Dr · opening Cr · debit · credit · closing Dr · closing Cr,
    // then the tree level LAST (2026-09-12 — `ATrialBalanceReadsAsTheChartsTreeTest`).
    expect($csv['headers'])->toHaveCount(10);

    $bankRow = collect($csv['rows'])->first(fn (array $r): bool => $r[0] === $report['rows']->firstWhere('account_id', $this->bank)['code']);
    expect(array_slice($bankRow, 3, 6))->toBe([100000.0, 0.0, 0.0, 17000.0, 83000.0, 0.0]);

    $totals = collect($csv['rows'])->first(fn (array $r): bool => $r[1] === __('admin.reports.csv.total'));
    expect(array_slice($totals, 3, 6))->toBe([100000.0, 100000.0, 17000.0, 17000.0, 100000.0, 100000.0]);
});

it('draws the three pairs onto the printed copy, landscape', function () {
    // `PdfDocument::html()` is the seam, for the reason every PDF test here uses it: nobody will
    // inflate mpdf's streams to find out whether a column is on the page.
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    $html = PdfDocument::make('accounting.pdf.trial-balance')
        ->data([
            'report' => $report,
            // The template prints the tree the service resolved (point 19); every node here.
            'nodes' => LedgerTree::visible($report['tree'], LedgerTree::parentCodes($report['tree'])),
            'meta' => ['property' => 'Consolidated', 'period' => 'Aug 2026', 'generated_on' => '31/08/2026', 'locale' => 'en'],
            ...IssuingEntity::forViewScopedTo(null),
        ])
        ->html();

    expect($html)
        ->toContain(__('admin.reports.trial_balance_columns.opening'))
        ->toContain(__('admin.reports.trial_balance_columns.movement'))
        ->toContain(__('admin.reports.trial_balance_columns.closing'))
        // The bank row's three DIFFERENT figures, in order across the page.
        ->toMatch('/100,000\.00.*17,000\.00.*83,000\.00/s');

    // …and the service turns the page: six money columns do not fit a portrait sheet.
    $source = file_get_contents(app_path('Services/Accounting/LedgerReportPdfService.php'));
    // The method body up to the next one — never a byte count, which a longer comment outgrows.
    $start = strpos($source, 'function trialBalance(');
    $method = substr($source, $start, strpos($source, 'public function', $start + 1) - $start);
    expect($method)->toContain('landscape: true');
});

it('shows the six columns on the screen, mapped from the report row for row', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    // The page is tenant-scoped, so the fixture's null-property entries are outside what it
    // shows; post the same shape INTO the mall so the table has rows to read — a column test on
    // an empty table proves only that six keys exist, and `pairColumns()` reading one key for all
    // six would pass it.
    $r = app(AccountResolver::class);
    $post = app(JournalPostingService::class);
    $post->post(['entry_date' => '2026-07-15', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $this->bank, 'debit' => 40000, 'credit' => 0],
        ['ledger_account_id' => $this->capital, 'debit' => 0, 'credit' => 40000],
    ]]);
    $post->post(['entry_date' => '2026-08-10', 'asset_id' => $asset->id, 'lines' => [
        ['ledger_account_id' => $this->salaries, 'debit' => 9000, 'credit' => 0],
        ['ledger_account_id' => $this->bank, 'debit' => 0, 'credit' => 9000],
    ]]);

    $component = Livewire::test(TrialBalance::class)
        ->set('year', 2026)
        ->set('period', '2026-08')
        ->assertOk()
        // The tree opens folded to its roots (point 19); the leaf is on show once unfolded.
        ->callAction('expand_all');

    $rows = collect($component->instance()->getTableRecords());
    $bank = $rows->first(fn (array $row): bool => $row['id'] === $this->bank);

    expect($bank)->not->toBeNull()
        ->and([$bank['opening_debit'], $bank['opening_credit'], $bank['debit_total'], $bank['credit_total'], $bank['debit_balance'], $bank['credit_balance']])
        ->toBe([40000.0, 0.0, 0.0, 9000.0, 31000.0, 0.0]);

    $columns = collect($component->instance()->getTable()->getColumns())->keys()->values()->all();
    expect($columns)->toContain('opening_debit', 'opening_credit', 'debit_total', 'credit_total', 'debit_balance', 'credit_balance');

    // Both languages, no raw key — a six-column header in Arabic is where a missing key shows.
    foreach (['en', 'ar'] as $locale) {
        foreach (['opening', 'movement', 'closing', 'opening_debit', 'closing_credit'] as $key) {
            expect(__("admin.reports.trial_balance_columns.{$key}", [], $locale))
                ->not->toContain('admin.reports');
        }
    }
});

it('computes the report once per render, however many columns total it', function () {
    // Eight calls and sixteen GROUP-BY aggregates per mount before the memo — the subheading,
    // the rows and six summarizers each asked afresh. One is the number; two means the memo
    // key stopped covering something the answer depends on.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $calls = new ArrayObject;
    app()->bind(LedgerReportService::class, fn () => new class($calls) extends LedgerReportService
    {
        public function __construct(private ArrayObject $calls) {}

        public function trialBalance(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $includeZeroBalances = false): array
        {
            $this->calls[] = 1;

            return parent::trialBalance($assetIds, $from, $to, $includeZeroBalances);
        }
    });

    // ONE request: the mount. A `->set()` is a second Livewire request with a fresh component,
    // whose memo is legitimately empty again — so it would count two and prove nothing.
    Livewire::test(TrialBalance::class)->assertOk();

    expect(count($calls))->toBe(1);
});

it('leaves out an account whose history before the window nets to nothing, unless asked for every account', function () {
    // `aggregate()` answers for any account with a LINE, not a non-zero net. Without this filter a
    // void-and-repost pair, or every P&L account a year-end close zeroed, printed as six dashes on
    // every later month's statement — measured after `YearEndCloseService::close()`: January
    // listed every account that traded last year at nil, for ever.
    $r = app(AccountResolver::class);
    $post = app(JournalPostingService::class);
    $post->post(['entry_date' => '2026-06-01', 'lines' => [
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 5000, 'credit' => 0],
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 0, 'credit' => 5000],
    ]]);
    $post->post(['entry_date' => '2026-06-02', 'lines' => [
        ['ledger_account_id' => $r->id('rent_revenue'), 'debit' => 5000, 'credit' => 0],
        ['ledger_account_id' => $r->id('accounts_receivable'), 'debit' => 0, 'credit' => 5000],
    ]]);

    $reports = app(LedgerReportService::class);
    $ids = fn (array $report) => collect($report['rows'])->pluck('account_id');

    expect($ids($reports->trialBalance(null, $this->from, $this->to)))
        ->not->toContain($r->id('rent_revenue'))
        ->not->toContain($r->id('accounts_receivable'))
        // …and the toggle still lists them, at nil, exactly as it lists a never-posted account.
        ->and($ids($reports->trialBalance(null, $this->from, $this->to, includeZeroBalances: true)))
        ->toContain($r->id('rent_revenue'));
});

it('warns about every unallocated entry up to the date, not only the month\'s, because the closing column is as-at', function () {
    // A null-property entry dated BEFORE the window sits in nobody's opening once the statement
    // is scoped to a mall. Bounded to the month, the notice reported nothing about it — silent on
    // exactly the month whose opening it was missing from. `BalanceSheet::unallocatedRange()`
    // widens for the same reason; the trial balance now does too, on the screen/CSV and the PDF.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    // The fixture's July and August entries carry no property — they ARE the unallocated ones.
    $csv = Livewire::test(TrialBalance::class)->set('year', 2026)->set('period', '2026-08')->instance()->reportCsv();
    $heading = __('admin.journal_entries.unallocated.heading');
    $notice = collect($csv['rows'])->first(fn ($row) => is_string($row[0] ?? null) && str_starts_with($row[0], $heading));

    // 100,000 (July) + 17,000 (August) of debits — the July entry is the one a month-bounded
    // notice could not see.
    expect($notice)->not->toBeNull('the trial balance CSV carried no unallocated notice')
        ->and($notice[0])->toContain('117,000.00');

    // …and the PDF asks the same open-ended question.
    $asked = new ArrayObject;
    app()->bind(LedgerReportService::class, fn () => new class($asked) extends LedgerReportService
    {
        public function __construct(private ArrayObject $asked) {}

        public function unallocated(?array $assetIds, ?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $excludeClosing = false, ?int $accountId = null): ?array
        {
            $this->asked[] = $from === null;

            return parent::unallocated($assetIds, $from, $to, $excludeClosing, $accountId);
        }
    });
    app(LedgerReportPdfService::class)->trialBalance([$asset->id], $this->from, $this->to, 'Mall', 'Aug 2026');

    expect($asked->getArrayCopy())->toBe([true]);
});

it('says the books do not balance when the OPENING pair fails to foot, even though the closing pair does', function () {
    // `JournalPostingService` refuses an unbalanced entry, so through the front door all three
    // pairs foot or none does — which is exactly why a flag reading the closing pair alone
    // survived every fixture. The case that tells them apart is a corrupt half-line BEFORE the
    // window offset by another INSIDE it: the closing balances still foot, the opening ones do
    // not, and a reader of the August statement is looking at a ledger that is wrong. Written
    // straight into the tables, because that is the only way such a state arises — a migration
    // or a hand fix — and the flag exists for precisely the state no service would produce.
    $r = app(AccountResolver::class);
    $half = fn (string $date) => DB::table('journal_entries')->insertGetId([
        'number' => 'JE-CORRUPT-'.$date, 'entry_date' => $date, 'status' => 'posted',
        'description_en' => 'corrupt half-entry', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $before = $half('2026-07-20');
    DB::table('journal_lines')->insert(['journal_entry_id' => $before, 'ledger_account_id' => $r->id('bank'), 'debit' => 500, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $within = $half('2026-08-20');
    DB::table('journal_lines')->insert(['journal_entry_id' => $within, 'ledger_account_id' => $r->id('bank'), 'debit' => 0, 'credit' => 500, 'created_at' => now(), 'updated_at' => now()]);

    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    expect($report['total_debit'])->toBe($report['total_credit'])       // closing foots…
        ->and($report['total_opening_debit'])->not->toBe($report['total_opening_credit']) // …opening does not…
        ->and($report['balanced'])->toBeFalse();                          // …and the flag says so.
});
