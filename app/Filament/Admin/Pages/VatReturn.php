<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Pages\Concerns\ScopesLedgerReport;
use App\Services\Reports\VatReturnService;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * الإقرار الضريبي — the VAT return position for a period.
 *
 * **`VatReturnService` was complete, documented, tested — and had zero callers.** No page, no
 * route, no nav entry, no command. Its fifteen sibling report services all had a page; the one
 * report Egypt requires *monthly* was the only one an operator could not open, and `ROADMAP.md`
 * recorded it as shipped. This is that page.
 *
 * It reports a POSITION and files nothing. The output and input figures come from the ledger,
 * because the ledger is the single source of truth; the documents are used only to check it, and
 * the taxable-base split can only come from the documents because the GL knows revenue by account
 * and not by tax treatment.
 *
 * The tie-out is the point of the screen, so it is the subheading rather than a figure buried in a
 * row: when the ledger's output VAT and the documents' own VAT disagree, something has gone
 * unposted or been posted twice, and a return is the last chance to catch that before the number
 * becomes a position the operator has taken.
 */
class VatReturn extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;
    use ScopesLedgerReport;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 27;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'vat-return';

    /** Recomputed twice per render otherwise — records() and getSubheading() both need it. */
    private ?array $cachedReport = null;

    /**
     * Set when the report cannot be built because the chart is not wired — see {@see report()}.
     */
    private ?string $configurationError = null;

    /**
     * A return with nothing in it, used only when {@see report()} could not be built.
     *
     * Every figure is zero and NO row is rendered from it ({@see Table()} returns an empty record
     * set once `$configurationError` is set) — it exists so the accessors have a shape to read,
     * never so an operator sees a zero they might file.
     */
    private const EMPTY_REPORT = [
        'period_start' => null,
        'period_end' => null,
        'output_vat' => 0.0,
        'input_vat' => 0.0,
        'net_payable' => 0.0,
        'output_vat_documents' => 0.0,
        'output_vat_difference' => 0.0,
        'ties_out' => true,
        'base_standard' => 0.0,
        'base_zero_rated' => 0.0,
        'base_exempt' => 0.0,
        'unclassified_lines' => 0,
    ];

    public function getTitle(): string
    {
        return __('admin.reports.vat_return_title');
    }

    /**
     * The tie-out, stated where it cannot be missed.
     *
     * `ties_out` compares the ledger's output VAT against the documents' own. A mismatch is not a
     * rounding curiosity — it means an invoice or credit note is unposted, or something posted
     * twice — so it belongs beside the title and not in a footnote.
     */
    public function getSubheading(): ?string
    {
        // A posting role this report reads is unmapped — say which, and stop. See report().
        if ($this->configurationError !== null) {
            return '✗ '.__('admin.reports.vat_unavailable', ['reason' => $this->configurationError]);
        }

        $report = $this->report();

        $check = $report['ties_out']
            ? '✓ '.__('admin.reports.vat_ties_out')
            : '✗ '.__('admin.reports.vat_does_not_tie', [
                'difference' => number_format($report['output_vat_difference'], 2),
            ]);

        $subheading = $check.' · '.__('admin.reports.vat_net_payable', [
            'amount' => number_format($report['net_payable'], 2),
        ]);

        // Lines raised before the tax catalogue existed carry no code, so they are split by rate —
        // which cannot separate zero-rated from exempt. Said here rather than left implicit: "0
        // zero-rated supplies" is a fact about the period, and this is the difference between that
        // and a limit of the data. The person signing the return is entitled to know which they
        // are looking at.
        if ($report['unclassified_lines'] > 0) {
            $subheading .= ' · ⚠ '.__('admin.reports.vat_unclassified_note', [
                'count' => $report['unclassified_lines'],
            ]);
        }

        return $subheading;
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            // CSV only, deliberately. A VAT return is worked in a spreadsheet and handed to an
            // accountant who reconciles it against their own figures; a PDF of it would look like
            // a filed document, which this is not.
            ...$this->exportActions(),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.vat_return');
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
     * **Why this is caught here rather than left to throw.** `AccountResolver` refuses an unmapped
     * posting role with a `DomainException`, which everywhere else in this system renders as a
     * toast. Not here: the refusal is raised while the TABLE renders, so Blade wraps it in a
     * `ViewException` and the handler that recognises a refusal never sees one — the operator got a
     * 500 on a screen Egypt requires monthly. An incomplete posting map is not an exotic state; it
     * is one `ConfigurationHealth` has a check for, and loading the operator's real chart of
     * accounts is still ahead of us.
     *
     * **And the figures must NOT fall back to zero.** A VAT return showing 0 because a role is
     * unmapped is far worse than one that refuses: it is a filing position, it looks answered, and
     * nothing on the screen says the number is missing rather than nil. So the rows go away
     * entirely and the subheading says which role is unmapped — no figure an operator could sign.
     *
     * @return array<string, mixed>
     */
    protected function report(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        try {
            return $this->cachedReport = app(VatReturnService::class)->for(
                CarbonImmutable::instance($this->periodStart()),
                CarbonImmutable::instance($this->periodEnd()),
                // The service takes ONE asset id, not a set: a VAT return is filed per registration,
                // and the operator's registration covers the portfolio. A property filter here would
                // invite someone to file a per-mall return, which is not a thing.
                null,
            );
        } catch (DomainException $e) {
            $this->configurationError = $e->getMessage();

            return $this->cachedReport = self::EMPTY_REPORT;
        }
    }

    /**
     * The report as CSV, callable without a browser — see App\Contracts\DeliverableReport.
     *
     * The export action and scheduled delivery both go through this, so an emailed copy is
     * byte-for-byte the report an operator would have downloaded.
     */
    public function reportCsv(): array
    {
        $r = $this->report();

        return [
            'filename' => "vat-return-{$this->periodSlug()}",
            'headers' => [__('admin.reports.vat_line'), __('admin.fields.amount')],
            'rows' => [
                [__('admin.reports.vat_base_standard'), number_format($r['base_standard'], 2, '.', '')],
                [__('admin.reports.vat_base_zero_rated'), number_format($r['base_zero_rated'], 2, '.', '')],
                [__('admin.reports.vat_base_exempt'), number_format($r['base_exempt'], 2, '.', '')],
                [__('admin.reports.vat_output'), number_format($r['output_vat'], 2, '.', '')],
                [__('admin.reports.vat_input'), number_format($r['input_vat'], 2, '.', '')],
                [__('admin.reports.vat_net_payable_label'), number_format($r['net_payable'], 2, '.', '')],
                [__('admin.reports.vat_output_documents'), number_format($r['output_vat_documents'], 2, '.', '')],
                [__('admin.reports.vat_difference'), number_format($r['output_vat_difference'], 2, '.', '')],
            ],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $r = $this->report();

                // No figures at all when the chart cannot answer. A zeroed VAT return reads as a
                // filed position; an empty one beside the subheading's reason reads as what it is.
                if ($this->configurationError !== null) {
                    return [];
                }

                return [
                    ['id' => 'base_standard', 'line' => __('admin.reports.vat_base_standard'), 'amount' => $r['base_standard'], 'note' => __('admin.reports.vat_base_standard_note')],
                    // A taxable supply at 0% is not an exempt one. Separate lines on the return,
                    // and separable here only because the line carries its tax code.
                    ['id' => 'base_zero_rated', 'line' => __('admin.reports.vat_base_zero_rated'), 'amount' => $r['base_zero_rated'], 'note' => __('admin.reports.vat_base_zero_rated_note')],
                    ['id' => 'base_exempt', 'line' => __('admin.reports.vat_base_exempt'), 'amount' => $r['base_exempt'], 'note' => __('admin.reports.vat_base_exempt_note')],
                    ['id' => 'output', 'line' => __('admin.reports.vat_output'), 'amount' => $r['output_vat'], 'note' => __('admin.reports.vat_output_note')],
                    ['id' => 'input', 'line' => __('admin.reports.vat_input'), 'amount' => $r['input_vat'], 'note' => __('admin.reports.vat_input_note')],
                    ['id' => 'net', 'line' => __('admin.reports.vat_net_payable_label'), 'amount' => $r['net_payable'], 'note' => __('admin.reports.vat_net_note')],
                    ['id' => 'check', 'line' => __('admin.reports.vat_output_documents'), 'amount' => $r['output_vat_documents'], 'note' => __('admin.reports.vat_documents_note')],
                ];
            })
            ->columns([
                TextColumn::make('line')
                    ->label(__('admin.reports.vat_line'))
                    ->weight(fn (array $record): ?string => $record['id'] === 'net' ? 'bold' : null)
                    // The "why" beside each figure, because a return is signed by someone who has
                    // to understand what they are signing.
                    ->description(fn (array $record): string => $record['note']),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight(fn (array $record): ?string => $record['id'] === 'net' ? 'bold' : null),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateHeading(__('admin.reports.no_movements'))
            ->emptyStateDescription(__('admin.reports.no_movements_hint'));
    }
}
