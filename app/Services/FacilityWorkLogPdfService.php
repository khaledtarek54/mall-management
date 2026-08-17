<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Support\IssuingEntity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The facility work-log report (module 26, Phase 2 / discovery RPT-1) — a bilingual PDF
 * of preventive-maintenance work orders for a property over a date range, with a summary
 * (by status + category) and the detail list. Scoped to the caller's visible properties.
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
            ->with(['asset', 'unit'])
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->whereBetween('scheduled_for', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('scheduled_for')
            ->get();
    }

    /**
     * @param  array<int>|null  $assetIds  Restrict to these properties (null = all visible / portfolio)
     */
    public function build(string $from, string $to, ?array $assetIds, string $scopeLabel): string
    {
        $orders = $this->orders($from, $to, $assetIds);

        $summary = [
            'total' => $orders->count(),
            'by_status' => $orders->countBy('status'),
            'by_category' => $orders->countBy('category'),
            // The log covers ALL facility work — corrective jobs are work orders too, and a
            // work log that omitted the faults would be the less useful half. But the reader
            // has to be able to tell them apart, so the split is stated rather than implied.
            'by_type' => $orders->countBy('work_order_type'),
            'done' => $orders->where('status', 'done')->count(),
        ];

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('reports.facility-work-log', [
            'orders' => $orders,
            'summary' => $summary,
            'from' => CarbonImmutable::parse($from),
            'to' => CarbonImmutable::parse($to),
            'scopeLabel' => $scopeLabel,
            // No asset: the log may span the whole portfolio, and `$scopeLabel` already states
            // which properties it covers.
            ...IssuingEntity::forView(),
        ])->render();

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
            'default_font' => $isRtl ? 'xbriyaz' : 'dejavusans',
            'default_font_size' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(string $from, string $to): string
    {
        return 'facility-work-log-'.$from.'_'.$to.'.pdf';
    }
}
