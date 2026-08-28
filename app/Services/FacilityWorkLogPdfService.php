<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The facility work-log report (module 26, Phase 2 / discovery RPT-1) — a bilingual PDF
 * of preventive-maintenance work orders for a property over a date range, with a summary
 * (by status + trade) and the detail list. Scoped to the caller's visible properties.
 */
class FacilityWorkLogPdfService
{
    /**
     * The work orders in scope for the report. Restricted to `$assetIds` (null = all
     * visible / portfolio) — this is the SAME `whereIn` the resource table applies, so
     * the report can never show more than the list.
     *
     * @param  array<int>|null  $assetIds
     * @return Collection<int, FacilityWorkOrder>
     */
    public function orders(string $from, string $to, ?array $assetIds): Collection
    {
        $fromDate = CarbonImmutable::parse($from)->startOfDay();
        $toDate = CarbonImmutable::parse($to)->endOfDay();

        return FacilityWorkOrder::query()
            ->with(['asset', 'unit', 'trade'])
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->whereBetween('scheduled_for', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('scheduled_for')
            ->get();
    }

    /**
     * @param  array<int>|null  $assetIds  Restrict to these properties (null = all visible / portfolio)
     */

    /**
     * @param  array<int>|null  $assetIds  Restrict to these properties (null = all visible / portfolio)
     */
    public function build(string $from, string $to, ?array $assetIds, string $scopeLabel, ?string $locale = null): string
    {
        return PdfDocument::make('reports.facility-work-log')
            ->locale(DocumentLocale::resolve($locale))
            ->data(function () use ($from, $to, $assetIds, $scopeLabel): array {
                $orders = $this->orders($from, $to, $assetIds);

                return [
                    'orders' => $orders,
                    'summary' => [
                        'total' => $orders->count(),
                        'by_status' => $orders->countBy('status'),
                        // Grouped by the trade RECORD's label rather than by a raw code, so the
                        // summary reads in the reader's language and a renamed trade renames itself
                        // here too. It is derived INSIDE the locale for that reason — composed
                        // before the render it would carry the operator's language into an Arabic
                        // document.
                        'by_category' => $orders->countBy(fn ($o): string => $o->trade?->label() ?? '—'),
                        // The log covers ALL facility work — corrective jobs are work orders too,
                        // and a work log that omitted the faults would be the less useful half. But
                        // the reader has to be able to tell them apart, so the split is stated
                        // rather than implied.
                        'by_type' => $orders->countBy('work_order_type'),
                        'done' => $orders->where('status', 'done')->count(),
                    ],
                    'from' => CarbonImmutable::parse($from),
                    'to' => CarbonImmutable::parse($to),
                    'scopeLabel' => $scopeLabel,
                    // Scoped: filtered to ONE mall it carries that mall's logo; across several, or
                    // all, it is a portfolio document and `$scopeLabel` already says which
                    // properties it covers.
                    ...IssuingEntity::forViewScopedTo($assetIds),
                ];
            })
            ->reference($scopeLabel.' · '.$from.' – '.$to)
            ->fontSize(10)
            // The widest table in the set: it carried the narrowest page margins of any template for
            // that reason, and the renderer's default would squeeze a column off the edge.
            ->margins(['left' => 9, 'right' => 9])
            ->render();
    }

    public function filename(string $from, string $to): string
    {
        return 'facility-work-log-'.$from.'_'.$to.'.pdf';
    }
}
