<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Models\Vendor;
use App\Services\Reports\WithholdingTaxReturnService;
use App\Services\WithholdingCertificatePdfService;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Pdf\DocumentLocale;
use App\Support\WithholdingTax;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * نموذج ٤١ — the withholding-tax return, per supplier.
 *
 * The withholding ENGINE has been complete and dated for months: per-vendor code → portfolio
 * default → nothing, resolved for the payment's date, charged on the VAT-exclusive share, posting
 * `Cr withholding_tax_payable`. What did not exist was the artefact — Egypt files this **quarterly
 * on Form 41**, and the supplier needs a certificate to set the amount against their own corporate
 * income tax. That absence is what kept `TaxSettings::wht_enabled` switched off: an operator cannot
 * start deducting money from suppliers that they have no way to declare or to evidence.
 *
 * ## Quarters, and the two the page also offers
 *
 * The period picker lists the four quarters of the fiscal year — derived from `FiscalYear`'s own
 * `starts_on`, not from the calendar, because an April→March mall year is ordinary in Egypt and
 * every other report on this concern already honours it. The twelve months and the whole year stay
 * available beside them, because an accountant reconciling their own filing works a month at a time
 * and the alternative is exporting a quarter and filtering it in a spreadsheet.
 *
 * ## It reports a position; it files nothing
 *
 * No ETA XML and no reproduction of the form's printed boxes. Those are the accountant's, and a
 * screen that imitated them would be a document that looks official and is not. What this gives is
 * the figure, the per-supplier detail behind it, and the tie-out against the ledger — which is what
 * an accountant reconciles their own filing against.
 */
class WithholdingTaxReturn extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    /**
     * Aliased, because `parent::` does NOT reach a trait method — `Page` has no `periodOptions()`,
     * and a class method of the same name simply wins over the trait's. Without the alias the four
     * overrides below called into nothing and the page threw
     * "Method WithholdingTaxReturn::periodOptions does not exist" while the FILTER FORM rendered,
     * which is a 500 on open rather than anything a test of the service would see.
     */
    use ScopesLedgerReport {
        periodOptions as protected monthlyPeriodOptions;
        periodStart as protected monthlyPeriodStart;
        periodEnd as protected monthlyPeriodEnd;
        periodLabel as protected monthlyPeriodLabel;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScissors;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'withholding-tax-return';

    /** Recomputed on every accessor otherwise — records(), the subheading and the CSV all need it. */
    private ?array $cachedReport = null;

    /** Set when the report cannot be built because the chart is not wired — see {@see report()}. */
    private ?string $configurationError = null;

    private const EMPTY_REPORT = [
        'period_start' => null,
        'period_end' => null,
        'withheld_documents' => 0.0,
        'withheld_ledger' => 0.0,
        'difference' => 0.0,
        'ties_out' => true,
        'remitted' => 0.0,
        'outstanding' => 0.0,
        'suppliers' => [],
    ];

    public function getTitle(): string
    {
        return __('admin.reports.wht_return_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.wht_return');
    }

    /**
     * The tie-out and the position, where they cannot be missed.
     *
     * `ties_out` compares what was DEDUCTED from suppliers against what the LEDGER says is owed to
     * the ETA. A mismatch means something was withheld and never posted, or posted twice, and this
     * is the last screen before that becomes a filing position somebody signs.
     *
     * When withholding is switched off the subheading says so rather than showing a confident zero:
     * a nil return and a feature that has never been turned on are different facts.
     */
    public function getSubheading(): ?string
    {
        if ($this->configurationError !== null) {
            return '✗ '.__('admin.reports.wht_unavailable', ['reason' => $this->configurationError]);
        }

        $report = $this->report();

        $check = $report['ties_out']
            ? '✓ '.__('admin.reports.wht_ties_out')
            : '✗ '.__('admin.reports.wht_does_not_tie', [
                'difference' => number_format($report['difference'], 2),
            ]);

        $subheading = $check.' · '.__('admin.reports.wht_outstanding_note', [
            'amount' => number_format($report['outstanding'], 2),
        ]);

        if (! WithholdingTax::enabled()) {
            $subheading .= ' · ⚠ '.__('admin.reports.wht_disabled_note');
        }

        return $subheading;
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            $this->certificateAction(),
            // CSV, like the VAT return: this is worked in a spreadsheet and handed to an accountant
            // who reconciles it against their own figures. The one PDF here is the CERTIFICATE,
            // which really is a document issued to a third party.
            ...$this->exportActions(),
        ];
    }

    /**
     * Issue one supplier's certificate for the selected period.
     *
     * A header action rather than a row action, because a certificate is issued for a PERIOD and a
     * supplier, and the period is the thing the page is already scoped to — putting it on the row
     * would make it look like a property of that row's figures.
     */
    protected function certificateAction(): Action
    {
        return Action::make('withholding_certificate')
            ->label(__('admin.reports.wht_certificate'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->visible(fn (): bool => $this->canViewReports())
            ->schema([
                Select::make('vendor_id')
                    ->label(__('admin.fields.vendor'))
                    ->options(fn (): array => $this->suppliersWithheldFrom())
                    ->required()
                    ->native(false)
                    ->helperText(__('admin.reports.wht_certificate_hint')),
                // The certificate goes to the SUPPLIER, who hands it to their own accountant to
                // claim the tax already deducted from them — so the language is theirs, not the
                // clerk's. Defaulted from the chosen vendor's stored preference, which is why it is
                // `->live()`-reactive on the picker above rather than a fixed default.
                Radio::make(PdfDownloadAction::LANGUAGE_FIELD)
                    ->label(__('admin.pdf.language'))
                    ->options(DocumentLocale::options())
                    ->default(fn (): string => DocumentLocale::resolve())
                    ->required()
                    ->in(array_keys(DocumentLocale::options())),
            ])
            ->action(function (array $data): StreamedResponse {
                abort_unless($this->canViewReports(), 403);

                $vendor = Vendor::query()->findOrFail((int) $data['vendor_id']);

                $pdf = app(WithholdingCertificatePdfService::class)->build(
                    $vendor,
                    CarbonImmutable::instance($this->periodStart()),
                    CarbonImmutable::instance($this->periodEnd()),
                    DocumentLocale::resolve($data[PdfDownloadAction::LANGUAGE_FIELD] ?? null, $vendor),
                );

                $name = 'wht-certificate-'.$vendor->id.'-'.$this->periodSlug().'.pdf';

                return response()->streamDownload(fn () => print $pdf, $name);
            });
    }

    /**
     * The suppliers this period actually withheld from — not every vendor on the books.
     *
     * A certificate for a supplier nothing was withheld from is a document stating zero, which is
     * worse than no document: it reads as a claim that nothing was deducted when the real answer
     * may be that the period is wrong.
     *
     * @return array<int, string>
     */
    protected function suppliersWithheldFrom(): array
    {
        return collect($this->report()['suppliers'])
            ->mapWithKeys(fn (array $row): array => [$row['vendor_id'] => $row['vendor']])
            ->all();
    }

    /**
     * The four quarters of the fiscal year, then its months, then the year.
     *
     * Quarters come first because Form 41 is filed on them. They are derived from the fiscal year's
     * own start rather than the calendar, for the same reason the months are: an April→March year
     * is ordinary here, and a report that silently used calendar quarters on one would be three
     * months out.
     *
     * @return array<string, string>
     */
    protected function periodOptions(): array
    {
        $start = $this->fiscalYearStart()->copy()->startOfMonth();
        $quarters = [];

        for ($q = 1; $q <= 4; $q++) {
            $from = $start->copy()->addMonths(($q - 1) * 3);
            $to = $from->copy()->addMonths(2)->endOfMonth();

            $quarters[$this->year.'-Q'.$q] = __('admin.reports.wht_quarter', [
                'n' => $q,
                'from' => $from->translatedFormat('M'),
                'to' => $to->translatedFormat('M Y'),
            ]);
        }

        return $quarters + $this->monthlyPeriodOptions();
    }

    protected function periodStart(): Carbon
    {
        return $this->selectedQuarter()[0] ?? $this->monthlyPeriodStart();
    }

    protected function periodEnd(): Carbon
    {
        return $this->selectedQuarter()[1] ?? $this->monthlyPeriodEnd();
    }

    protected function periodLabel(): string
    {
        return $this->selectedQuarterNumber() !== null
            ? __('admin.reports.wht_quarter_label', ['n' => $this->selectedQuarterNumber(), 'year' => $this->year])
            : $this->monthlyPeriodLabel();
    }

    /**
     * The selected quarter's boundaries, or `[null, null]` when the period is not a quarter.
     *
     * A two-element array rather than a nullable one so the callers above stay one line each; the
     * base class answers for a month or the whole year.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function selectedQuarter(): array
    {
        $q = $this->selectedQuarterNumber();

        if ($q === null) {
            return [null, null];
        }

        $from = $this->fiscalYearStart()->copy()->startOfMonth()->addMonths(($q - 1) * 3)->startOfDay();

        return [$from, $from->copy()->addMonths(2)->endOfMonth()->endOfDay()];
    }

    private function selectedQuarterNumber(): ?int
    {
        if (! is_string($this->period) || ! preg_match('/^\d{4}-Q([1-4])$/', $this->period, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * No notice here: a return filed per REGISTRATION omits nothing.
     *
     * The inherited warning says in bold *"They are NOT in the figures above"*, and on the five
     * property-scoped statements that is true — `aggregate()` narrows with
     * `whereIn('je.asset_id', …)` and `whereIn` never matches NULL. This page deliberately passes a
     * null asset (see `report()` below), and the service applies the filter as
     * `->when($assetId, …)`, so with null there is no asset predicate at all and those entries ARE
     * counted here.
     *
     * Left inherited it told an accountant that a statutory filing position understates what they
     * owe when it does not, and pointed them at a remedy — re-file the document against a mall —
     * that would make the return WRONG. Opted out here rather than removed from the concern,
     * because a sixth property-scoped statement should still inherit the warning instead of being
     * the one that quietly omits money.
     */
    protected function unallocatedNotice(): ?array
    {
        return null;
    }

    /**
     * The return, or an empty one and a stated reason when the chart cannot answer.
     *
     * Same handling as the VAT return, and for the same reason: `AccountResolver` refuses an
     * unmapped posting role with a `DomainException` raised while the TABLE renders, which Blade
     * wraps in a `ViewException` so the refusal handler never sees it — a 500 on a screen the
     * operator needs quarterly. And the figures must not fall back to zero: a withholding return
     * showing nothing because `withholding_tax_payable` is unmapped looks answered.
     *
     * @return array<string, mixed>
     */
    protected function report(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        try {
            return $this->cachedReport = app(WithholdingTaxReturnService::class)->for(
                CarbonImmutable::instance($this->periodStart()),
                CarbonImmutable::instance($this->periodEnd()),
                // One tax registration covers the portfolio, so there is no per-mall Form 41 to
                // file. Same decision as the VAT return.
                null,
            );
        } catch (DomainException $e) {
            $this->configurationError = $e->getMessage();

            return $this->cachedReport = self::EMPTY_REPORT;
        }
    }

    /** @return array<string, mixed> */
    public function reportCsv(): array
    {
        $r = $this->report();

        $rows = [];

        foreach ($r['suppliers'] as $row) {
            $rows[] = [
                $row['vendor'],
                $row['tax_id'] ?? '',
                $row['tax_code'] ?? '',
                (string) $row['payments'],
                number_format($row['base'], 2, '.', ''),
                number_format($row['effective_rate'], 2, '.', ''),
                number_format($row['withheld'], 2, '.', ''),
            ];
        }

        // The tie-out travels WITH the detail. An exported list of suppliers that does not say
        // whether it agreed with the ledger is a number somebody files without the caveat.
        $rows[] = ['', '', '', '', '', __('admin.reports.wht_total'), number_format($r['withheld_documents'], 2, '.', '')];
        $rows[] = ['', '', '', '', '', __('admin.reports.wht_ledger'), number_format($r['withheld_ledger'], 2, '.', '')];
        $rows[] = ['', '', '', '', '', __('admin.reports.wht_difference'), number_format($r['difference'], 2, '.', '')];

        return $this->withUnallocatedNotice([
            'filename' => "withholding-tax-return-{$this->periodSlug()}",
            'headers' => [
                __('admin.fields.vendor'),
                __('admin.fields.tax_id'),
                __('admin.reports.wht_code'),
                __('admin.reports.wht_payments'),
                __('admin.reports.wht_base'),
                __('admin.reports.wht_rate'),
                __('admin.reports.wht_withheld'),
            ],
            'rows' => $rows,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->configurationError !== null) {
                    return [];
                }

                return collect($this->report()['suppliers'])
                    ->map(fn (array $row): array => $row + ['id' => $row['vendor_id']])
                    ->all();
            })
            ->columns([
                TextColumn::make('vendor')->label(__('admin.fields.vendor'))->weight('bold')
                    // The supplier's tax registration is what the ETA matches the return against, so
                    // a missing one is a filing problem and belongs beside the name, not hidden.
                    ->description(fn (array $record): string => $record['tax_id'] ?: __('admin.reports.wht_no_tax_id')),
                TextColumn::make('tax_code')->label(__('admin.reports.wht_code'))->badge()->placeholder('—'),
                TextColumn::make('payments')->label(__('admin.reports.wht_payments'))->alignEnd(),
                TextColumn::make('base')->label(__('admin.reports.wht_base'))->money('EGP')->alignEnd(),
                TextColumn::make('effective_rate')
                    ->label(__('admin.reports.wht_rate'))
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).'%'),
                TextColumn::make('withheld')->label(__('admin.reports.wht_withheld'))->money('EGP')->alignEnd()->weight('bold'),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-scissors')
            ->emptyStateHeading(__('admin.reports.wht_none'))
            ->emptyStateDescription(__('admin.reports.wht_none_hint'));
    }
}
