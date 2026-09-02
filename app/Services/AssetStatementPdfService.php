<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;

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
    /**
     * The property statement as a PDF, in the language the OWNER reads.
     *
     * No recipient object: the statement is about a property, and the reader is whichever operator
     * or owner asked for it — so it follows the request's own language, which is the right answer for
     * a document generated on demand by the person about to read it.
     */
    public function build(Asset $asset, ?string $locale = null): string
    {
        return PdfDocument::make('assets.statement')
            ->locale(DocumentLocale::resolve($locale))
            ->data(fn (): array => $this->data($asset))
            ->reference($asset->name)
            ->render();
    }

    /**
     * Everything the statement template needs, resolved once.
     *
     * Split out for the reason {@see InvoicePdfService::viewData()} was: mpdf is a renderer, and the
     * DOCUMENT is what anyone wants to assert on. Without a seam here the only way to test what an
     * owner statement says was to re-derive the data in the test — which is how the invoice's own
     * test came to reproduce a bug faithfully instead of catching it.
     */
    public function data(Asset $asset): array
    {
        $asset->loadMissing(['units.leases.tenant']);

        $asOf = CarbonImmutable::now();
        $since = $asOf->subMonths(12)->startOfMonth();

        // Every invoice at the property that is really on the books.
        //
        // **`draft` and `written_off` were both missing from this filter**, and they fail in
        // opposite directions on the one document Jawad reads:
        //
        //  - `invoices.status` DEFAULTS to `draft` at the column, so an invoice nobody has issued —
        //    a working figure, an abandoned one — was billed to the owner as revenue and chased on
        //    their arrears list. The tenant is never shown a draft (`TenantVisibility`); the owner
        //    was.
        //  - a WRITE-OFF deliberately leaves `balance` standing, because balance is derived from the
        //    four settlement channels and a write-off is none of them. So `where('balance', '>', 0)`
        //    two lines down put already-relieved bad debt on the owner's outstanding list — money
        //    the operator has formally given up and the ledger has already expensed.
        //
        // Every sibling AR read excludes both: `TenantLedger`, `TenantStatementPdfService`,
        // `DepositBilling`, `InvoiceSettlement`. This was the one that did not.
        $invoicesAll = Invoice::query()
            ->where('asset_id', $asset->id)
            ->with(['lease.unit', 'tenant', 'writeOffs'])
            ->whereNotIn('status', ['draft', 'cancelled', 'credited'])
            ->get();

        // **COLLECTABLE, not `balance`** — the third term. `balance` says what was OWED and `status`
        // says whether the document left the books; a PARTIAL write-off is neither, so a filter on
        // `balance` alone chases the operator's own forgiveness. `written_off` above catches only the
        // full ones.
        $openInvoices = $invoicesAll
            ->filter(fn (Invoice $inv): bool => $inv->collectableBalance() > 0)
            ->sortBy('due_date')
            ->values();

        $recentInvoices = $invoicesAll
            ->where('issue_date', '>=', $since)
            ->sortByDesc('issue_date')
            ->values();

        $payments = Payment::query()
            ->whereHas('invoices', fn ($q) => $q->where('invoices.asset_id', $asset->id))
            ->whereIn('status', Payment::RECEIVED_STATUSES)
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
                'balance' => (float) $invoices->sum(fn (Invoice $inv): float => $inv->collectableBalance()),
                'oldest_due' => $invoices->min('due_date'),
            ])
            ->sortByDesc('balance')
            ->take(10)
            ->values();

        $summary = [
            'outstanding' => (float) $invoicesAll->sum(fn (Invoice $inv): float => $inv->collectableBalance()),
            'overdue' => (float) $invoicesAll
                ->filter(fn (Invoice $inv): bool => $inv->collectableBalance() > 0
                    && $inv->due_date && $inv->due_date->isPast())
                ->sum(fn (Invoice $inv): float => $inv->collectableBalance()),
            'total_billed' => (float) $invoicesAll->sum('total'),
            'total_paid' => (float) $invoicesAll->sum('paid_amount'),
            'open_count' => $openInvoices->count(),
            'units_total' => $asset->units->count(),
            'units_occupied' => $asset->units->where('status', 'occupied')->count(),
        ];

        return [
            'asset' => $asset,
            'asOf' => $asOf,
            'since' => $since,
            'summary' => $summary,
            'openInvoices' => $openInvoices,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
            'delinquentTenants' => $delinquentTenants,
            ...IssuingEntity::forView($asset),
        ];
    }

    public function filename(Asset $asset): string
    {
        return 'Property-Statement-'.str($asset->code)->slug().'-'.now()->format('Ymd').'.pdf';
    }
}
