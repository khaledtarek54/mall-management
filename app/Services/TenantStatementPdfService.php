<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tenant;
use App\Support\IssuingEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class TenantStatementPdfService
{
    /**
     * @param  array<int>|null  $visibleAssetIds  Restrict the statement to these properties. Pass
     *                                            TenantScope::visibleAssetIds() from the ADMIN surface so a property-restricted operator can't
     *                                            read a shared tenant's AR in a mall they can't see. Pass null (the default) for the tenant's
     *                                            OWN statement (portal / mobile API) — the tenant is entitled to their whole-company view.
     *                                            Note: null also means "unrestricted" (super_admin), matching visibleAssetIds()'s null.
     * @param  CarbonInterface|null  $from  Start of the window. Defaults to 12 months back.
     * @param  CarbonInterface|null  $to  End of the window. Defaults to today.
     *
     * The window is a PARAMETER because the statement used to hard-code a trailing 12 months and
     * report nothing about what it covered — so a client printed a period computed from the DEVICE
     * clock beside a PDF the server built. Either the caller states the window or it gets the
     * documented default; nobody has to guess.
     */
    public function build(
        Tenant $tenant,
        ?array $visibleAssetIds = null,
        $from = null,
        $to = null,
    ): string {
        $data = $this->data($tenant, $visibleAssetIds, $from, $to);

        $isRtl = app()->getLocale() === 'ar';

        $html = View::make('tenants.statement', $data)->render();

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

    /**
     * Everything the statement states, before it becomes a PDF.
     *
     * Separated from `build()` so the figures can be asserted without rendering — a test that has to
     * parse mPDF output to find out whether a credit note is listed will not be written, and the one
     * defect this document has had was a settlement that appeared in the totals and nowhere else.
     *
     * @param  array<int>|null  $visibleAssetIds
     * @return array<string, mixed>
     */
    public function data(
        Tenant $tenant,
        ?array $visibleAssetIds = null,
        $from = null,
        $to = null,
    ): array {
        $tenant->loadMissing(['leases.unit.asset']);

        $asOf = $to !== null ? CarbonImmutable::parse($to) : CarbonImmutable::now();
        $since = $from !== null
            ? CarbonImmutable::parse($from)->startOfDay()
            : $asOf->subMonths(12)->startOfMonth();

        $invoicesAll = $tenant->invoices()
            ->with('lease.unit')
            ->visibleToTenant()
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
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
            ->whereIn('status', Payment::RECEIVED_STATUSES)
            ->where('payment_date', '>=', $since)
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereHas('invoices', fn ($u) => $u->whereIn('invoices.asset_id', $visibleAssetIds)))
            ->orderByDesc('payment_date')
            ->get();

        // The settlements that are NOT payments. An invoice's balance falls through four channels
        // and this document listed exactly one of them, so `total_paid` — which counts all four —
        // could not be reconciled from anything printed on the page. Measured on a terminated lease:
        // Total Billed 532,600, Total Settled 232,100, Total Received 152,000, and the missing
        // 80,100 was an applied credit note that appeared nowhere. A tenant cannot query a number
        // they cannot see, and "your statement is wrong" is the call it produces.
        //
        // `visibleToTenant()` for the same reason the invoice query has it: this service renders the
        // portal's and the mobile API's statement too, and `credit_notes.status` DEFAULTS to draft at
        // the column — a note that has not been issued is not the tenant's business. Void notes claim
        // nothing, so they are excluded as well; an applied or issued one is a real document.
        $credits = $tenant->creditNotes()
            ->with('invoice')
            ->visibleToTenant()
            ->where('status', '!=', 'void')
            ->whereDate('issue_date', '>=', $since)
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->orderByDesc('issue_date')
            ->get();

        $summary = [
            'outstanding' => (float) $invoicesAll->sum('balance'),
            'overdue' => (float) $invoicesAll
                ->where('balance', '>', 0)
                ->filter(fn ($inv) => $inv->due_date && $inv->due_date->isPast())
                ->sum('balance'),
            'total_billed' => (float) $invoicesAll->sum('total'),
            // Every channel, not just cash — which is why it is labelled "settled" and not "paid".
            'total_paid' => (float) $invoicesAll->sum('paid_amount'),
            'open_count' => $openInvoices->count(),
        ];

        // Not `leases->first()?->unit?->asset`: a unit OWNER is a `tenants` row (module 37) and may
        // hold no lease at all, while the invoice query below happily lists their assessments — so
        // that chain rendered their statement with no property and no issuer block. The invoices'
        // own `asset_id` is the answer, and it is NOT NULL.
        $asset = $tenant->leases->first()?->unit?->asset
            ?? $invoicesAll->first()?->asset;

        return [
            'tenant' => $tenant,
            'asset' => $asset,
            ...IssuingEntity::forView($asset),
            'asOf' => $asOf,
            'since' => $since,
            'summary' => $summary,
            'openInvoices' => $openInvoices,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
            'credits' => $credits,
        ];
    }

    public function filename(Tenant $tenant): string
    {
        return 'Statement-'.str($tenant->name)->slug().'-'.now()->format('Ymd').'.pdf';
    }
}
