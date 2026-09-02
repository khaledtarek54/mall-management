<?php

namespace App\Services;

use App\Enums\UnitOwnershipStatus;
use App\Models\Asset;
use App\Models\DepositApplication;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

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

    /**
     * The statement of account as a PDF, in the language the TENANT reads.
     *
     * The longest document this system issues and the one most likely to run to several pages, which
     * is why {@see PdfDocument} gives it a running footer carrying the tenant's name and `page x of
     * y`: a loose sheet of somebody's ledger with no name on it cannot be filed or challenged.
     */
    public function build(
        Tenant $tenant,
        ?array $visibleAssetIds = null,
        $from = null,
        $to = null,
        ?string $locale = null,
    ): string {
        return $this->document($tenant, $visibleAssetIds, $from, $to, $locale)->render();
    }

    /**
     * The configured document, before mpdf touches it.
     *
     * Split from {@see build()} so a test can read the HTML this service actually produces —
     * including the locale it resolved — rather than re-wiring the same builder in the test and
     * proving only that the test agrees with itself. `TaxInvoiceSellerParticularsTest` kept its own
     * copy of `viewData()` once and reproduced the service's bugs faithfully instead of catching
     * them; a second copy of the BUILDER is the same mistake one layer out.
     */
    public function document(
        Tenant $tenant,
        ?array $visibleAssetIds = null,
        $from = null,
        $to = null,
        ?string $locale = null,
    ): PdfDocument {
        return PdfDocument::make('tenants.statement')
            ->locale(DocumentLocale::resolve($locale, $tenant))
            ->data(fn (): array => $this->data($tenant, $visibleAssetIds, $from, $to))
            ->reference($tenant->name)
            ->bleed();
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

        // **THE WINDOW IS PRINTED AND WAS NOT APPLIED.** `$asOf` set the date in the header and
        // bounded nothing: `$invoicesAll` had no upper bound at all, and both `$recentInvoices` and
        // every settlement query were `>= $since` with no `<=`. So `GET /me/statement?to=2026-03-31`
        // rendered *"as at 31 March"* over rows dated April, May and June — a document a tenant's
        // accountant reconciles a quarter from, listing transactions after the quarter it names.
        // `endOfDay()`, or a row dated the last day of the window is cut off by its own end date.
        $asOf = ($to !== null ? CarbonImmutable::parse($to) : CarbonImmutable::now())->endOfDay();
        $since = $from !== null
            ? CarbonImmutable::parse($from)->startOfDay()
            : $asOf->subMonths(12)->startOfMonth();

        $invoicesAll = $tenant->invoices()
            ->with(['lease.unit', 'writeOffs'])
            ->visibleToTenant()
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->get();

        // **The balances are as they stand TODAY, not as they stood on `$asOf`** — a payment made
        // after the window still shows against an invoice inside it. Reconstructing a historical
        // balance means replaying four settlement channels to a date, which is a different document
        // (an aged-debt-as-at report) and not what this one claims to be. What it does claim is
        // which TRANSACTIONS fall in the window, and that is now true.
        $openInvoices = $invoicesAll
            ->filter(fn ($i): bool => $i->collectableBalance() > 0)
            ->sortBy('due_date')
            ->values();

        $recentInvoices = $invoicesAll
            ->where('issue_date', '>=', $since)
            ->sortByDesc('issue_date')
            ->values();

        $payments = $tenant->payments()
            ->whereIn('status', Payment::RECEIVED_STATUSES)
            ->whereBetween('payment_date', [$since, $asOf])
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
            ->whereBetween('issue_date', [$since, $asOf])
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->orderByDesc('issue_date')
            ->get();

        // The OTHER two channels (AR-GL-03). `Invoice::recomputeTotals()` settles a balance through
        // FOUR of them — captured payments, an applied credit note, applied on-account tenant credit
        // and a netted security deposit — and this page listed the first two. So a statement could
        // say Total Settled 232,100 against Total Received 152,000 with the difference itemised
        // nowhere, which is worst on a final move-out statement, where netting the deposit is
        // usually the largest single settlement the tenant will see.
        //
        // ONE section rather than two more tables: both answer the same question a tenant is asking
        // ("what settled this, if not a payment or a credit note?"), both carry the same four facts,
        // and a fourth five-column table on a one-page statement buys nothing. The KIND column is
        // what makes them readable apart.
        //
        // Soft-deleted rows are excluded by the models' own `SoftDeletes`, which is what makes a
        // reversal disappear from the statement as well as from the balance.
        $settlements = collect()
            ->concat($tenant->creditApplications()
                ->with('invoice')
                ->whereBetween('entry_date', [$since, $asOf])
                ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
                ->get()
                ->map(fn (TenantCreditApplication $row): array => [
                    'kind' => __('admin.statement.settlement_kinds.tenant_credit'),
                    'date' => $row->entry_date,
                    'invoice' => $row->invoice?->number,
                    'notes' => $row->notes,
                    'amount' => (float) $row->amount,
                ]))
            ->concat(DepositApplication::query()
                ->with('invoice')
                ->where('tenant_id', $tenant->getKey())
                ->whereBetween('entry_date', [$since, $asOf])
                ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
                ->get()
                ->map(fn (DepositApplication $row): array => [
                    'kind' => __('admin.statement.settlement_kinds.deposit'),
                    'date' => $row->entry_date,
                    'invoice' => $row->invoice?->number,
                    'notes' => $row->notes,
                    'amount' => (float) $row->amount,
                ]))
            ->sortByDesc('date')
            ->values();

        $summary = [
            // The same figure `Tenant::outstandingBalance()` gives the portal headline and the API.
            // Summing `balance` here made the statement a tenant downloads disagree with the number
            // on the screen they downloaded it from.
            'outstanding' => (float) $invoicesAll->sum(fn ($i): float => $i->collectableBalance()),
            'overdue' => (float) $invoicesAll
                ->filter(fn ($i): bool => $i->collectableBalance() > 0)
                ->filter(fn ($inv) => $inv->due_date && $inv->due_date->isPast())
                ->sum('balance'),
            'total_billed' => (float) $invoicesAll->sum('total'),
            // Every channel, not just cash — which is why it is labelled "settled" and not "paid".
            'total_paid' => (float) $invoicesAll->sum('paid_amount'),
            'open_count' => $openInvoices->count(),
        ];

        // EXACTLY ONE MALL, OR THE OPERATOR. A statement can span several properties — a chain
        // leases in three malls and gets one document listing all of it — and `->first()` picked an
        // arbitrary one of them for the letterhead, which is a claim about the other two. The
        // portal chrome already answers this question the same way (see `PortalBranding`), and a
        // tenant told two different things by two of our own documents is worse than being told
        // nothing.
        //
        // Read off the INVOICES' own `asset_id`, not `leases->first()->unit->asset`: a unit OWNER is
        // a `tenants` row (module 37) and may hold no lease at all while the query below happily
        // lists their assessments, so that chain rendered a statement with no property at all.
        $assetIds = $invoicesAll->pluck('asset_id')->filter()->unique();

        // No invoices at all — a tenant who has just signed and not been billed yet, or a unit
        // OWNER who has not reached their first صيانة assessment. Their agreement still says which
        // mall this is, and dropping the letterhead for them would be a regression dressed up as
        // the new rule: the rule is about AMBIGUITY, not about having fewer documents.
        //
        // Two corrections over the first cut, each of which lost the letterhead for the very
        // case the comment above claims to cover:
        //
        //   * It counted TERMINAL leases, so a chain that left mall A for mall B resolved to two
        //     assets and fell back to nothing. A statement is about where this tenant stands NOW.
        //   * A module-37 unit owner holds no lease at all — the case the comment three lines above
        //     cites by name — and had no fallback of their own. `unit_ownerships` carries its own
        //     `asset_id`, for the same reason `invoices` does.
        //
        // Eager-loaded rather than lazy: this is a PDF path, and `$tenant->leases` followed by a
        // `unit` per lease is an N+1 that nothing on screen would ever show.
        if ($assetIds->isEmpty()) {
            // The MASTER unit answers for the whole lease: `leases.unit_id` is NOT NULL on both
            // drivers, so reading the `lease_unit` pivot as well would be a second eager load
            // proving something already known. (Checked, rather than assumed — that was the other
            // half of this review finding, and it was wrong.)
            $assetIds = $tenant->leases()
                ->whereNotIn('status', Lease::TERMINAL_STATUSES)
                ->with('unit:id,asset_id')
                ->get()
                ->pluck('unit.asset_id')
                ->merge(
                    // `handed_over`, the same predicate `PortalBranding` uses — and it has to be
                    // the same one, or a tenant's portal chrome and their statement letterhead can
                    // disagree, which is the exact failure the exactly-one-mall rule exists to
                    // prevent. It also stops a SOLD unit counting: a `transferred` ownership in
                    // Mall A plus a live one in Mall B would resolve to two assets and drop the
                    // letterhead for someone who is unambiguously in one place — the same mistake
                    // the terminal-lease filter above fixes, one relation over.
                    $tenant->unitOwnerships()
                        ->where('status', UnitOwnershipStatus::HandedOver->value)
                        ->pluck('asset_id')
                )
                ->filter()
                ->unique()
                ->values();
        }

        $asset = $assetIds->count() === 1
            ? Asset::find($assetIds->first())
            : null;

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
            'settlements' => $settlements,
        ];
    }

    public function filename(Tenant $tenant): string
    {
        return 'Statement-'.str($tenant->name)->slug().'-'.now()->format('Ymd').'.pdf';
    }
}
