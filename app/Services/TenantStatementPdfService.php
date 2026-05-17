<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class TenantStatementPdfService
{
    public function build(Tenant $tenant): string
    {
        $tenant->loadMissing(['leases.unit.asset']);

        $asOf = CarbonImmutable::now();
        $since = $asOf->subMonths(12)->startOfMonth();

        $invoicesAll = $tenant->invoices()
            ->with('lease.unit')
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

        $payments = $tenant->payments()
            ->where('status', 'captured')
            ->where('payment_date', '>=', $since)
            ->orderByDesc('payment_date')
            ->get();

        $summary = [
            'outstanding' => (float) $invoicesAll->sum('balance'),
            'overdue' => (float) $invoicesAll
                ->where('balance', '>', 0)
                ->filter(fn ($inv) => $inv->due_date && $inv->due_date->isPast())
                ->sum('balance'),
            'total_billed' => (float) $invoicesAll->sum('total'),
            'total_paid' => (float) $invoicesAll->sum('paid_amount'),
            'open_count' => $openInvoices->count(),
        ];

        $asset = $tenant->leases->first()?->unit?->asset;

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('tenants.statement', [
            'tenant' => $tenant,
            'asset' => $asset,
            'asOf' => $asOf,
            'since' => $since,
            'summary' => $summary,
            'openInvoices' => $openInvoices,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
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

    public function filename(Tenant $tenant): string
    {
        return 'Statement-'.str($tenant->name)->slug().'-'.now()->format('Ymd').'.pdf';
    }
}
