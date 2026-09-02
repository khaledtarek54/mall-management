<?php

namespace App\Services\Accounting;

use App\Services\Reports\StatementSpread;
use App\Support\IssuingEntity;
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

    public function trialBalance(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.trial-balance', fn (): array => [
            'report' => $this->reports->trialBalance($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds, $period, $locale, window: [$from, $to]);
    }

    public function incomeStatement(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null): string
    {
        return $this->render('accounting.pdf.income-statement', fn (): array => [
            'report' => $this->reports->incomeStatement($assetIds, $from, $to),
            'meta' => $this->meta($property, $period),
        ], $assetIds, $period, $locale, window: [$from, $to]);
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
            'meta' => $this->meta($property, $period),
            // No window, so no notice: this method takes the spread ALREADY BUILT rather than the
            // dates to build it from — deliberately, so the printed columns cannot differ from the
            // ones on screen — and re-deriving a window here to count over could disagree with them.
            // The screen it was printed from carries the warning.
        ], $assetIds, $period, $locale, landscape: count($spread['spans']) > 4);
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
        ], $assetIds, $period, $locale, window: [$from, $to]);
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
     */
    /**
     * @param  array{0: CarbonInterface, 1: ?CarbonInterface}|null  $window  the period the notice counts over
     */
    private function render(string $view, Closure $data, ?array $assetIds, string $period, ?string $locale, bool $landscape = false, ?array $window = null): string
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
        $unallocated = $window === null
            ? null
            : $this->reports->unallocated($assetIds, $window[0], $window[1] ?? null);

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
