<?php

namespace App\Services;

use App\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Property-level statement of account for the Owner Portal.
 *
 * Aggregates every invoice / payment across every lease at the property
 * for the trailing 12 months, plus an outstanding-AR snapshot. Mirrors
 * the per-tenant statement service that powers the tenant + admin views;
 * the difference is the data shape — owners care about portfolio-level
 * financials per property, not per-tenant ledgers.
 */
class AssetStatementPdfService
{
    public function build(Asset $asset): string
    {
        $asset->loadMissing(['units.leases.tenant']);

        $asOf = CarbonImmutable::now();
        $since = $asOf->subMonths(12)->startOfMonth();

        // Every non-cancelled invoice across every lease at the property.
        $invoicesAll = \App\Models\Invoice::query()
            ->whereHas('lease.unit', fn ($q) => $q->where('asset_id', $asset->id))
            ->with(['lease.unit', 'tenant'])
            ->whereNotIn('status', ['cancelled', 'credited'])
            ->get();

        $openInvoices = $invoicesAll
            ->where('balance', '>', 0)
            ->sortBy('due_date')
            ->values();

        $recentInvoices = $invoicesAll
            ->where('issue_date', '>=', $since)
            ->sortByDesc('issue_date')
            ->values();

        $payments = \App\Models\Payment::query()
            ->whereHas('invoices.lease.unit', fn ($q) => $q->where('asset_id', $asset->id))
            ->whereIn('status', \App\Models\Payment::RECEIVED_STATUSES)
            ->where('payment_date', '>=', $since)
            ->with('tenant')
            ->orderByDesc('payment_date')
            ->get();

        // Per-tenant outstanding so the owner can see who owes what at a glance.
        $delinquentTenants = $openInvoices
            ->groupBy(fn ($inv) => $inv->tenant?->name ?? '—')
            ->map(fn ($invoices, $name) => [
                'name' => $name,
                'count' => $invoices->count(),
                'balance' => (float) $invoices->sum('balance'),
                'oldest_due' => $invoices->min('due_date'),
            ])
            ->sortByDesc('balance')
            ->take(10)
            ->values();

        $summary = [
            'outstanding' => (float) $invoicesAll->sum('balance'),
            'overdue' => (float) $invoicesAll
                ->where('balance', '>', 0)
                ->filter(fn ($inv) => $inv->due_date && $inv->due_date->isPast())
                ->sum('balance'),
            'total_billed' => (float) $invoicesAll->sum('total'),
            'total_paid' => (float) $invoicesAll->sum('paid_amount'),
            'open_count' => $openInvoices->count(),
            'units_total' => $asset->units->count(),
            'units_occupied' => $asset->units->where('status', 'occupied')->count(),
        ];

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('assets.statement', [
            'asset' => $asset,
            'asOf' => $asOf,
            'since' => $since,
            'summary' => $summary,
            'openInvoices' => $openInvoices,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
            'delinquentTenants' => $delinquentTenants,
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
            'default_font_size' => 10.5,
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

    public function filename(Asset $asset): string
    {
        return 'Property-Statement-' . str($asset->code)->slug() . '-' . now()->format('Ymd') . '.pdf';
    }
}
