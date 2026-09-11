<?php

namespace App\Services;

use App\Enums\UnitOwnershipStatus;
use App\Models\Asset;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\DepositBilling;
use App\Support\DocumentText;
use App\Support\IssuingEntity;
use App\Support\Pdf\DocumentLocale;
use App\Support\Pdf\PdfDocument;
use App\Support\TenantLedger;
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
        //
        // **THE BOUND APPLIES ONLY WHEN THE CALLER STATED ONE**, and the first version of this fix
        // did not make that distinction — which broke the default path that every ordinary download
        // takes. A FUTURE `issue_date` is a first-class state here, not an exotic one: both billing
        // runs carry explicit code for it (*"never let an invoice be born overdue"*), and
        // `billing:run-monthly --period=2026-10` produces a whole month of them. Bounding the
        // default at today therefore dropped them from the statement while the portal's invoice
        // LIST — which has no such bound — still showed them, and `Tenant::outstandingBalance()`
        // still counted them: measured, the statement said 50,000 outstanding where the screen the
        // tenant downloaded it from said 100,000. That divergence is the exact one this service's
        // own docblock says the figure exists to prevent, re-introduced through a different door.
        //
        // `endOfDay()` on the stated bound. Every one of these columns is a plain DATE on MySQL, so
        // it changes nothing there — it matters on SQLite, which keeps a full `Y-m-d H:i:s` string
        // in a date-typed column, and it is the honest bound to write for a column that may one day
        // carry a time.
        $bounded = $to !== null;
        $asOf = ($bounded ? CarbonImmutable::parse($to) : CarbonImmutable::now())->endOfDay();
        $since = $from !== null
            ? CarbonImmutable::parse($from)->startOfDay()
            : $asOf->subMonths(12)->startOfMonth();

        $invoicesAll = $tenant->invoices()
            ->with(['lease.unit', 'writeOffs'])
            ->visibleToTenant()
            ->whereNotIn('status', ['cancelled', 'credited', 'written_off'])
            ->when($bounded, fn ($q) => $q->whereDate('issue_date', '<=', $asOf->toDateString()))
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->get();

        // **The per-invoice figures are as they stand TODAY, not as they stood on `$asOf`** — a
        // payment made after the window still shows against an invoice inside it. The LEDGER below
        // does close as at the date, because a running balance is exactly that replay and costs
        // nothing here; the per-invoice breakdown and the overdue tile are not replayed (an
        // aged-debt-as-at report is a different document), so on a statement bounded in the past
        // the template dates them "as of today" beside the ledger's own closing.
        $openInvoices = $invoicesAll
            ->filter(fn ($i): bool => $i->collectableBalance() > 0)
            ->sortBy('due_date')
            ->values();

        // **THE STATEMENT IS THE LEDGER, PRINTED** (meeting 2026-09-02, points 5·6·8·9·10). Until
        // 2026-09-11 this page was a different document from the tenant's on-screen ledger — open
        // invoices, credits, payments and "other settlements" as four tables, no balance brought
        // forward, no running balance, a payment row that named its rail and nothing it settled,
        // and the deposit HELD printed nowhere. The client asked for the كشف حساب every Egyptian
        // accountant reconciles from: date · reference · description · debit · credit · balance,
        // opening with the balance forward. Yardi's tenant statement is the same shape. So the
        // rows come from `TenantLedger` — the one derivation the screen already shows — and the
        // two cannot disagree in figure or in grain. `$since` is where the balance forward is
        // struck; `$asOf` bounds the rows only when the caller stated a window, for the reason
        // above.
        $ledger = TenantLedger::statement($tenant, $visibleAssetIds, $since, $bounded ? $asOf : null);

        // **The deposit is a LIABILITY, not a receivable, so it has its own account** — printed,
        // held, and kept out of the running balance above. Yardi shows it as "deposit on hand"
        // beside the ledger for the same reason. Only RECORDED movements (a cancelled one is not
        // money), and a deposit netted at move-out appears here as the other half of the ledger's
        // credit. A deposit BILLED on an invoice and since paid is held too (`Lease::depositHeld()`
        // counts it through `settledDepositBillings()`), so it gets a row of its own, dated at the
        // invoice — without it the account printed a header, an empty body and a footer holding
        // 99,000 (found by the review of this change).
        $leases = $tenant->leases()
            ->with(['unit:id,asset_id', 'deposits', 'depositApplications.invoice:id,number', 'depositBillings'])
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $visibleAssetIds)))
            ->get();

        $allDepositMovements = $leases
            ->flatMap(fn (Lease $lease) => $lease->deposits
                ->where('status', 'recorded')
                ->map(fn ($row): array => [
                    'date' => $row->transaction_date,
                    'kind' => __("admin.statement.deposit_kinds.{$row->type}"),
                    'reference' => $row->number ?? $lease->reference,
                    'lease' => $lease->reference,
                    'in' => $row->type === 'receipt' ? round((float) $row->amount, 2) : 0.0,
                    'out' => $row->type === 'receipt' ? 0.0 : round((float) $row->amount, 2),
                ])
                ->concat($lease->depositApplications->map(fn (DepositApplication $row): array => [
                    'date' => $row->entry_date ?? $row->created_at,
                    'kind' => __('admin.statement.deposit_kinds.applied'),
                    'reference' => $row->invoice?->number ?? '',
                    'lease' => $lease->reference,
                    'in' => 0.0,
                    'out' => round((float) $row->amount, 2),
                ]))
                ->concat($lease->depositBillings
                    ->map(fn (Invoice $invoice): array => [
                        'date' => $invoice->issue_date,
                        'kind' => __('admin.statement.deposit_kinds.billed'),
                        'reference' => $invoice->number,
                        'lease' => $lease->reference,
                        // What has been SETTLED on the deposit line to date, net of credit relief —
                        // the same reading `depositHeld()` makes. The row is dated at the invoice,
                        // though the money may have arrived later: it is the document the tenant
                        // can quote, and the account still foots to the pot either way.
                        'in' => round(DepositBilling::heldOn($invoice), 2),
                        'out' => 0.0,
                    ])
                    ->filter(fn (array $row): bool => $row['in'] > 0)))
            ->filter(fn (array $row): bool => $row['date'] !== null && (! $bounded || ! $row['date']->gt($asOf)))
            ->sortBy('date')
            ->values();

        $depositMovements = $allDepositMovements->filter(fn (array $row): bool => ! $row['date']->lt($since))->values();

        // `Lease::depositHeld()` — the ONE definition of the pot: receipts, less refunds, forfeits
        // and anything netted, plus a deposit billed and since paid. The account's opening is
        // DERIVED from it — held, less the window's own movements — rather than re-summed from the
        // history, so the account foots to the pot by construction: opening + in − out = held,
        // whatever was recorded before `$since` and however it was recorded.
        $held = round((float) $leases->sum(fn (Lease $lease): float => $lease->depositHeld()), 2);

        $deposit = [
            'rows' => $depositMovements,
            'opening' => round($held - (float) $depositMovements->sum('in') + (float) $depositMovements->sum('out'), 2),
            'held' => $held,
        ];

        // Two totals, because a statement has two sides (point 5 — «إجمالي المستحقات لكم وعليكم»).
        // DUE FROM YOU is the ledger's own closing balance — what the invoices say is collectable,
        // GROSS of any credit note not yet applied. `Tenant::outstandingBalance()`, the portal and
        // API headline, NETS unapplied notes; the difference is exactly the "credit notes not yet
        // applied" line under HELD FOR YOU, so the two documents agree once the reader adds the
        // two sides — which is why both sides are printed. DUE TO YOU is what the operator holds
        // for this tenant and has not netted: the deposit, credit notes issued and not yet
        // applied, and money paid on account — each already answered by its own one definition.
        $creditNotesUnapplied = round((float) $tenant->creditNotes()
            ->where('status', 'issued')
            ->when($visibleAssetIds !== null, fn ($q) => $q->whereIn('asset_id', $visibleAssetIds))
            ->sum('balance'), 2);
        $creditOnAccount = round($tenant->creditBalance($visibleAssetIds), 2);

        $summary = [
            'due_from_tenant' => $ledger['closing'],
            // Σ collectable over the invoices as they stand TODAY — kept under its old key for the
            // readers that already ask for it. Equal to `due_from_tenant` on an unbounded
            // statement; on one bounded in the past the ledger closes AS AT the date and this does
            // not, which is why the template dates the tiles it draws from here.
            'outstanding' => (float) $invoicesAll->sum(fn ($i): float => $i->collectableBalance()),
            'overdue' => (float) $invoicesAll
                ->filter(fn ($i): bool => $i->collectableBalance() > 0)
                ->filter(fn ($inv) => $inv->due_date && $inv->due_date->isPast())
                ->sum(fn ($i): float => $i->collectableBalance()),
            'deposit_held' => $deposit['held'],
            'credit_notes_unapplied' => $creditNotesUnapplied,
            'credit_on_account' => $creditOnAccount,
            'due_to_tenant' => round($deposit['held'] + $creditNotesUnapplied + $creditOnAccount, 2),
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
            'ledger' => $ledger,
            'deposit' => $deposit,
            'openInvoices' => $openInvoices,
            // True when the per-invoice figures are struck on a later day than the ledger's
            // closing — a statement bounded in the past — so the template can say so.
            'figuresAsOfToday' => $bounded && $asOf->lt(CarbonImmutable::now()->startOfDay()),
            'today' => CarbonImmutable::now(),
            // The operator's own footer, per property — "valid for 7 days", whatever they wrote
            // (point 10); the floor is the sentence this document always carried.
            'footerText' => DocumentText::for('statement.footer', $asset?->id),
        ];
    }

    public function filename(Tenant $tenant): string
    {
        return 'Statement-'.str($tenant->name)->slug().'-'.now()->format('Ymd').'.pdf';
    }
}
