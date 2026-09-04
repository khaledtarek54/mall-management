<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Support\IssuingEntity;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **"Show accounts with no movement" reaches the printed copy** — SW-140.
 *
 * `LedgerReportService::trialBalance()` has taken `$includeZeroBalances` since RP-02 and the screen
 * has offered the toggle since; `TrialBalance::reportCsv()` gets it for free because it goes through
 * the page's own `report()`. `LedgerReportPdfService::trialBalance()` did not take the flag AT ALL,
 * so it fell to the default — measured at HEAD (2026-09-04): with the toggle on, the screen and the
 * CSV listed every postable account and the PDF listed only those with movement.
 *
 * That is the one question the toggle exists for — *"is that account really nil, or did nobody map
 * it?"* — which absence cannot answer either way, and the PDF is the copy an accountant ticks off
 * against. Same rule as SW-133's unallocated notice: fixing the screen and the CSV and not the PDF
 * is worse than fixing neither, because the PDF is the copy that leaves the building.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $resolver = app(AccountResolver::class);

    app(JournalPostingService::class)->post(['entry_date' => '2026-03-01', 'lines' => [
        ['ledger_account_id' => $resolver->id('accounts_receivable'), 'debit' => 3000, 'credit' => 0],
        ['ledger_account_id' => $resolver->id('rent_revenue'), 'debit' => 0, 'credit' => 3000],
    ]]);

    // An account with NO movement in the window — the whole population the toggle exists to show.
    $this->nil = LedgerAccount::findOrFail($resolver->id('deposits_held'));

    $this->from = CarbonImmutable::create(2026, 1, 1);
    $this->to = CarbonImmutable::create(2026, 12, 31);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('asks the report for the unmoved accounts when the operator asked for them', function () {
    // **The PDF service DIRECTLY, never through the page.** The page asks the report service itself,
    // correctly and always has, so a recorder driven through `download_pdf` fills up with the
    // SCREEN's argument and passes with this fix deleted — the trap SW-133's own test records.
    $asked = new ArrayObject;

    app()->bind(LedgerReportService::class, fn () => new class($asked) extends LedgerReportService
    {
        public function __construct(private ArrayObject $asked) {}

        public function trialBalance(?array $assetIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, bool $includeZeroBalances = false): array
        {
            $this->asked[] = $includeZeroBalances;

            return parent::trialBalance($assetIds, $from, $to, $includeZeroBalances);
        }
    });

    $pdf = app(LedgerReportPdfService::class);

    $pdf->trialBalance(null, $this->from, $this->to, 'Consolidated', '2026', includeZeroBalances: true);
    $pdf->trialBalance(null, $this->from, $this->to, 'Consolidated', '2026');

    // Both directions: the flag travels, and its ABSENCE still means the short statement.
    expect($asked->getArrayCopy())->toBe([true, false]);
});

it('prints the list the operator is looking at', function () {
    // The page half. Stubbed at the PDF service rather than matched on Mockery arguments, so the
    // parameter is bound by PHP itself and a named argument cannot be read positionally by mistake.
    $this->seed(RolesPermissionsSeeder::class);

    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    $seen = new ArrayObject;

    app()->bind(LedgerReportPdfService::class, fn () => new class($seen, app(LedgerReportService::class)) extends LedgerReportPdfService
    {
        public function __construct(private ArrayObject $seen, LedgerReportService $reports)
        {
            parent::__construct($reports);
        }

        public function trialBalance(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null, bool $includeZeroBalances = false): string
        {
            $this->seen[] = $includeZeroBalances;

            return '%PDF-1.4';
        }
    });

    Livewire::test(TrialBalance::class)
        ->set('year', 2026)
        ->set('includeZeroBalances', true)
        ->callAction('download_pdf')
        ->assertHasNoActionErrors();

    expect($seen->getArrayCopy())->toBe([true]);
});

it('draws the nil account onto the page', function () {
    // The premise for both halves above: the template really renders a row that carries zero on
    // BOTH sides. `PdfDocument::html()` is the seam for exactly this — a test that had to inflate a
    // PDF's compressed streams to find out whether an account is on the page would never be written.
    $render = fn (array $report): string => PdfDocument::make('accounting.pdf.trial-balance')
        ->data([
            'report' => $report,
            'meta' => ['property' => 'Consolidated', 'period' => '2026', 'generated_on' => '01/01/2026', 'locale' => 'en'],
            ...IssuingEntity::forViewScopedTo(null),
        ])
        ->html();

    $reports = app(LedgerReportService::class);

    expect($render($reports->trialBalance(null, $this->from, $this->to, true)))
        ->toContain($this->nil->code)
        // …and without the flag it is genuinely absent, so the assertion above is about the toggle
        // rather than about a code that happens to appear anywhere on the page.
        ->and($render($reports->trialBalance(null, $this->from, $this->to)))
        ->not->toContain($this->nil->code);
});
