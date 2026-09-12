<?php

namespace App\Services\Accounting;

use App\Models\LedgerAccount;
use App\Services\Reports\StatementSpread;
use App\Support\IssuingEntity;
use App\Support\JournalNarrative;
use App\Support\LedgerTree;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonInterface;
use Closure;

/**
 * Renders the financial statements (ميزان المراجعة / قائمة الدخل / قائمة المركز
 * المالي) to PDF for printing and sharing with owners + auditors. Bilingual +
 * RTL-aware, mirroring the mpdf setup used by the invoice / statement services.
 */
class LedgerReportPdfService
{
    public function __construct(private LedgerReportService $reports) {}

    /**
     * ميزان المراجعة, printed.
     *
     * **"Show accounts with no movement" travels to the printed copy.** `LedgerReportService::
     * trialBalance()` has taken `$includeZeroBalances` since RP-02, the screen has offered the
     * toggle since, and `TrialBalance::reportCsv()` gets it for free because it goes through the
     * page's own `report()`. This method did not take the flag at all, so it fell to the default:
     * measured at HEAD (2026-09-04), with the toggle ON the screen and the CSV listed every
     * postable account and the PDF listed only those with movement.
     *
     * That is the one question the toggle exists for — *"is that account really nil, or did nobody
     * map it?"* — which absence cannot answer either way, and the printed copy is what an
     * accountant ticks off against. Same rule as the unallocated notice in `render()` below:
     * fixing the screen and the CSV and not the PDF is worse than fixing neither, because the PDF
     * is the copy that leaves the building.
     *
     * Declared AFTER `$locale` so the five existing positional call sites are untouched; the page
     * passes it by name.
     *
     * `$expanded` is the fold on screen — the codes whose branches the operator opened — so the
     * printed tree is the one they were looking at (point 19, 2026-09-12). Empty is the tree
     * folded to its roots, exactly as the screen opens; a caller with no screen that wants every
     * leaf passes `LedgerTree::parentCodes()`. Named too, for the same reason.
     *
     * @param  list<string>  $expanded
     */
    public function trialBalance(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null, bool $includeZeroBalances = false, array $expanded = []): string
    {
        return $this->render('accounting.pdf.trial-balance', function () use ($assetIds, $from, $to, $includeZeroBalances, $expanded, $property, $period): array {
            $report = $this->reports->trialBalance($assetIds, $from, $to, $includeZeroBalances);

            return [
                'report' => $report,
                // The tree at the fold, resolved HERE so the template loops one list and holds no
                // opinion about which rows a fold hides.
                'nodes' => LedgerTree::visible($report['tree'], $expanded),
                'meta' => $this->meta($property, $period),
            ];
            // Landscape, as the income-statement spread is past four money columns: opening,
            // movement and closing are six. The notice window is open-ended for the reason the
            // balance sheet's is: the closing column is an *as at* figure, so what it is missing
            // is every unallocated entry up to the date, not only the month's.
        }, $assetIds, $period, $locale, landscape: true, window: [null, $to]);
    }

    public function incomeStatement(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.income-statement', fn (): array => [
            'report' => $this->reports->incomeStatement($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
            // Excludes year-end closing entries, exactly as `LedgerReportService::incomeStatement()`
            // does — so the notice must not count the null-asset closing entry the statement above
            // it was never going to show.
        ], $assetIds, $period, $locale, window: [$from, $to], excludeClosing: true);
    }

    /**
     * The income statement read across several columns — month-and-year-to-date, or the twelve
     * months of a year ({@see StatementSpread}).
     *
     * Takes the spread already built rather than the dates to build it from, deliberately: the
     * screen decides which columns it is showing, and a PDF that re-derived them could hand the
     * operator a printed statement with different columns from the one they pressed the button on.
     *
     * Turned sideways past four money columns. A thirteen-column statement on a portrait page is
     * either clipped or shrunk past reading, and both are worse than a wide sheet of paper.
     *
     * @param  array<string, mixed>  $spread
     */
    public function incomeStatementSpread(array $spread, ?array $assetIds, string $property, string $period, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.income-statement-spread', fn (): array => [
            'spread' => $spread,
            // The lines the screen and the CSV print, laid out ONCE — resolved inside the data
            // closure so the headings and subtotals are worded in the document's locale, which
            // `PdfDocument` has set by the time this runs. The template has no fallback of its own:
            // this is the one door, so a test that renders the template without it fails loudly
            // rather than exercising a second copy of the layout.
            'records' => StatementSpread::records($spread),
            'meta' => $this->meta($property, $period),
            // No window, so no notice: this method takes the spread ALREADY BUILT rather than the
            // dates to build it from — deliberately, so the printed columns cannot differ from the
            // ones on screen — and re-deriving a window here to count over could disagree with them.
            // The screen it was printed from carries the warning.
        ], $assetIds, $period, $locale, landscape: count($spread['spans']) > 4);
    }

    /**
     * The general ledger — one account's statement (كشف حساب), or every account with movement in
     * the window when `$account` is null (the reports audit, 2026-09-12).
     *
     * The GL was the one ledger report with no printed form: the four statements and the trial
     * balance print, and the detail behind them — what an auditor asks for and what a month-end
     * review is read from — could only be exported one account at a time. One template for both
     * readings, so an account's page in the full ledger is its own statement's page.
     *
     * Narratives are resolved HERE, inside the document's locale, through the same
     * `JournalNarrative::resolve()` the screen and the CSV read — a template that read the prose
     * columns would print the wording frozen at post time (EG-36).
     */
    public function generalLedger(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null, ?LedgerAccount $account = null): string
    {
        return $this->render('accounting.pdf.general-ledger', function () use ($assetIds, $from, $to, $property, $period, $account): array {
            $statements = $account === null
                ? $this->reports->generalLedger($assetIds, $from, $to)
                : collect([['account' => $account] + $this->reports->accountLedger($account, $assetIds, $from, $to)]);

            $documentLocale = app()->getLocale();

            return [
                'statements' => $statements->map(fn (array $statement): array => [
                    'code' => $statement['account']->code,
                    'name' => $documentLocale === 'ar'
                        ? ($statement['account']->name_ar ?: $statement['account']->name_en)
                        : ($statement['account']->name_en ?: $statement['account']->name_ar),
                    'opening' => $statement['opening'],
                    'closing' => $statement['closing'],
                    'lines' => $statement['lines']->map(fn ($line): array => [
                        'entry_date' => $line->entry_date,
                        'entry_number' => $line->entry_number,
                        'description' => JournalNarrative::resolve(
                            $line->description_key ?? null,
                            isset($line->description_data) ? json_decode((string) $line->description_data, true) : null,
                            $line->description_en,
                            $line->description_ar,
                            $documentLocale,
                        ),
                        'debit' => (float) $line->debit,
                        'credit' => (float) $line->credit,
                        'running_balance' => (float) $line->running_balance,
                    ])->all(),
                ])->all(),
                'meta' => $this->meta($property, $period),
            ];
            // Six columns fit a portrait page. The notice window is open-ended for the trial
            // balance's reason — the OPENING balance is an *as at* figure, so what this ledger is
            // missing is every unallocated entry up to the end — and on ONE account it counts that
            // account alone, as the screen does: a cash statement warning about a null-asset entry
            // on repairs would say "not in the figures above" about money that never touched cash
            // (found by review, on the copy that leaves the building).
        }, $assetIds, $period, $locale, window: [null, $to], accountId: $account?->id);
    }

    public function balanceSheet(?array $assetIds, CarbonInterface $asOf, string $property, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.balance-sheet', fn (): array => [
            'report' => $this->reports->balanceSheet($assetIds, $asOf),
            'meta' => $this->meta($property, $asOf->format('d/m/Y')),
            // An "as at" statement reads everything up to the date, so the notice must too —
            // warning only about a selected month would understate what the page is missing.
        ], $assetIds, $asOf->format('d/m/Y'), $locale, window: [null, $asOf]);
    }

    public function cashFlow(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.cash-flow', fn (): array => [
            'report' => $this->reports->cashFlow($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds, $period, $locale, window: [$from, $to], excludeClosing: true);
    }

    public function filename(string $report, string $period): string
    {
        // `$period` is `2026` or `2026-03` — a monthly export must not land on disk under a name
        // that reads like the whole year's.
        return $report.'-'.$period.'-'.now()->format('Ymd').'.pdf';
    }

    /**
     * @return array<string, string>
     *
     * Resolved INSIDE the render's locale — `meta['locale']` is what the shared layout reads to set
     * its own direction, so composing it before the render would hand an Arabic statement the
     * operator's direction and lay the whole thing out backwards.
     */
    private function meta(string $property, string $period): array
    {
        return [
            'property' => $property,
            'period' => $period,
            'generated_on' => now()->format('d/m/Y H:i'),
            'locale' => app()->getLocale(),
        ];
    }

    /**
     * @param  Closure(): array<string, mixed>  $data
     * @param  array<int>|null  $assetIds  the report's property scope; one mall means one letterhead
     * @param  array{0: ?CarbonInterface, 1: ?CarbonInterface}|null  $window  the period the notice counts over; null = no notice
     * @param  bool  $excludeClosing  what THIS statement does with year-end closing entries
     */
    private function render(string $view, Closure $data, ?array $assetIds, string $period, ?string $locale, bool $landscape = false, ?array $window = null, bool $excludeClosing = false, ?int $accountId = null): string
    {
        // **MONEY THE STATEMENT LEAVES OUT, ON THE COPY THAT LEAVES THE BUILDING.**
        // Every ledger report scopes with `whereIn('je.asset_id', $ids)` and `whereIn` never matches
        // NULL, so an entry filed against no property is invisible in all of them — which is why
        // EG-27 put a warning on the screen. It was ONLY on the screen: the PDF, the CSV and the
        // scheduled email omitted the same money with nothing to say so, and those are the copies an
        // accountant, an owner and an auditor actually read.
        //
        // Computed HERE rather than in each statement's own data closure, and rendered by the shared
        // layout, so a sixth statement inherits the warning instead of being the one that quietly
        // omits money — the same reasoning that put `unallocatedNotice()` on the concern rather than
        // on five pages.
        // **It must count the population THIS statement shows, and take the same two answers the
        // screen took.** The income statement and the cash flow exclude year-end closing entries,
        // and the close posts a consolidated one for the null-asset bucket — so counting it here
        // sized the PDF's warning at roughly twice the money actually missing while the screen
        // beside it said the right number. One statement, two figures, and the PDF is the copy an
        // auditor reads.
        //
        // The notice carries its own `cumulative` flag, so the balance sheet's open-ended window
        // words itself as an *as at* read here exactly as it does on screen — one derivation, in
        // the method that owns the window, rather than a copy per renderer.
        $unallocated = $window === null
            ? null
            : $this->reports->unallocated($assetIds, $window[0], $window[1] ?? null, $excludeClosing, $accountId);

        return PdfDocument::make($view)
            ->locale(DocumentLocale::resolve($locale))
            ->landscape($landscape)
            // One seam for all four statements — they share `accounting.pdf.layout`, so the issuer
            // is stated here rather than in each of balanceSheet()/incomeStatement()/
            // trialBalance()/cashFlow().
            //
            // Scoped: a statement filtered to ONE mall carries that mall's logo; two or more, or
            // none, is a portfolio document and carries the operator's identity alone.
            ->data(fn (): array => [
                ...$data(),
                ...IssuingEntity::forViewScopedTo($assetIds),
                'unallocated' => $unallocated,
            ])
            ->reference($period)
            ->fontSize(10)
            ->render();
    }
}
