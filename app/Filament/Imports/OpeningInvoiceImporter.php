<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Load the receivables that were already outstanding on the day Atriom took over.
 *
 * ## Why open items and not a balance
 *
 * Until 2026-08-11 there was **no way at all** to load opening AR: the GL side could be entered as
 * a manual journal, but the tenant side had to be hand-keyed invoice by invoice. So the choice was
 * between a cutover nobody could complete and one that arrived as a single number per tenant.
 *
 * A single number is the wrong shape, and not by a little. Aging buckets, the dunning ladder,
 * statements, and per-invoice payment allocation all work on **documents**: a lump sum has no
 * invoice number to quote to a retailer who disputes it, no due date to age against, and nothing
 * for a payment to allocate to. Yardi and MRI both load open items at cutover for exactly these
 * reasons, and an Egyptian mall operator's first task on day one is chasing arrears.
 *
 * So each row here is a real invoice: number, issue date, due date, amount. It ages, it appears on
 * the statement, it can be part-paid, credited, disputed or written off like any other.
 *
 * ## Why it does not post to the general ledger
 *
 * The revenue behind an opening item was earned **before Atriom existed**. It belongs to the
 * previous system's books, and it is already inside the opening trial balance the accountant loads
 * as one manual journal entry (`Dr Accounts Receivable / Cr Opening Balance Equity`). If these
 * invoices also posted, the same revenue would be recognised twice and AR would be double the debt.
 *
 * `invoices.is_opening_balance` marks them, and `InvoiceJournalizer` returns no payload — the same
 * mechanism a draft already uses. **The two sides then prove each other:** `glTieOut()` counts
 * these invoices in `expectedAr` while the accountant's entry supplies GL AR, so
 * `billing:reconcile` going green after the import means "the receivables I loaded equal the
 * receivables my accountant says I have". Without that, a migration that silently loaded 90% of
 * the debt looks exactly like one that worked.
 *
 * ## What this deliberately does not do
 *
 * It does not create leases, tenants or units — those are the other three importers, and this one
 * runs last. It does not accept a paid invoice: an opening item is by definition still outstanding,
 * and loading settled history is a different job (and usually an unnecessary one).
 */
class OpeningInvoiceImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20', static::assetInScopeRule()])
                ->fillRecordUsing(function (Invoice $record, string $state): void {
                    // No-op: a lookup key. The invoice reaches its property through lease.unit.
                }),

            ImportColumn::make('tenant_email')
                ->label(__('admin.tables.tenant.email'))
                ->requiredMapping()
                ->rules(['required', 'email', 'exists:tenants,email'])
                ->fillRecordUsing(function (Invoice $record, string $state): void {
                    // No-op: resolved in resolveRecord().
                }),

            // The operator's OWN invoice number, carried across verbatim. This is the number the
            // retailer has on the paperwork they are being chased for, so inventing a new one
            // would make the first collections call unanswerable.
            ImportColumn::make('number')
                ->label(__('admin.fields.invoice_number'))
                ->requiredMapping()
                ->rules(['required', 'max:50']),

            ImportColumn::make('issue_date')
                ->label(__('admin.fields.issue_date'))
                ->requiredMapping()
                ->rules(['required', 'date']),

            ImportColumn::make('due_date')
                ->label(__('admin.fields.due_date'))
                ->requiredMapping()
                // Aging is computed from this, so a cutover without it produces a book of debt
                // that is not yet overdue — which is the opposite of the truth.
                ->rules(['required', 'date']),

            ImportColumn::make('amount')
                ->label(__('admin.fields.amount'))
                ->numeric()
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0.01'])
                ->fillRecordUsing(function (Invoice $record, $state): void {
                    // No-op: written as an invoice ITEM in afterCreate(), so the document has a
                    // line an operator and a tenant can both read. An invoice with totals and no
                    // items renders as an empty PDF and breaks InvoiceItemSettlement.
                }),

            ImportColumn::make('vat_amount')
                ->label(__('admin.fields.vat_amount'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0'])
                ->fillRecordUsing(function (Invoice $record, $state): void {
                    // No-op: folded into the item in afterCreate(). Carried across as-is rather
                    // than recomputed — an issued invoice keeps the rate it was billed at, and
                    // that rule does not stop applying because the invoice came from elsewhere.
                }),

            ImportColumn::make('description')
                ->label(__('admin.fields.description'))
                ->rules(['nullable', 'max:255'])
                ->fillRecordUsing(function (Invoice $record, $state): void {
                    // No-op: becomes the item's description.
                }),
        ];
    }

    public function resolveRecord(): ?Invoice
    {
        $asset = static::resolveVisibleAsset(
            is_string($this->data['asset_code'] ?? null) ? $this->data['asset_code'] : null
        );

        $tenant = Tenant::where('email', $this->data['tenant_email'] ?? null)->first();

        if (! $asset || ! $tenant) {
            return null;
        }

        // The tenant's lease at this property. An opening invoice needs one because `lease.unit`
        // is how every report reaches the property dimension — an invoice with no lease is
        // invisible to per-property AR and to the owner statement.
        $lease = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('commencement_date')
            ->first();

        if (! $lease) {
            return null;
        }

        // Idempotent on the operator's own invoice number, which is unique per their books and is
        // also this table's UNIQUE key. Re-running a partial cutover is the normal thing to do.
        $invoice = Invoice::firstOrNew(['number' => $this->data['number']]);

        $invoice->lease_id = $lease->id;
        $invoice->tenant_id = $tenant->id;
        $invoice->is_opening_balance = true;

        // Outstanding by definition — an opening item that was already settled is history, not a
        // receivable, and loading it would overstate both AR and the tenant's statement.
        $invoice->status = 'issued';
        $invoice->paid_amount = 0;
        $invoice->credit_applied_amount = 0;

        // `period_start`/`period_end` are NOT NULL, and an opening item's period is not knowable
        // from the previous system's export. The issue month is the honest stand-in: it is the
        // month the debt was raised in, it keeps `alreadyBilledForMonth()` from mistaking this for
        // a recurring invoice (that probe excludes `other`-typed lines anyway), and it never
        // claims a precision the source data does not have.
        $issued = CarbonImmutable::parse($this->data['issue_date']);
        $invoice->period_start = $issued->startOfMonth()->toMutable();
        $invoice->period_end = $issued->endOfMonth()->toMutable();

        $amount = round((float) ($this->data['amount'] ?? 0), 2);
        $vat = round((float) ($this->data['vat_amount'] ?? 0), 2);

        $invoice->subtotal = $amount;
        $invoice->vat_amount = $vat;
        $invoice->total = round($amount + $vat, 2);
        $invoice->balance = $invoice->total;

        return $invoice;
    }

    /**
     * Give the invoice a line.
     *
     * A header with no items is not a document: the PDF renders empty, the tenant cannot see what
     * they are being chased for, and `InvoiceItemSettlement` — which derives every per-line figure
     * from `paid_amount` — has nothing to derive against when the debt is part-paid.
     */
    protected function afterCreate(): void
    {
        /** @var Invoice $invoice */
        $invoice = $this->record;

        $amount = round((float) ($this->data['amount'] ?? 0), 2);
        $vat = round((float) ($this->data['vat_amount'] ?? 0), 2);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            // `other` is the honest type: the operator's previous system did not classify this
            // line in Atriom's charge-code vocabulary, and guessing `base_rent` would put migrated
            // history into rent revenue reporting that never earned it. It also posts nothing —
            // see the class docblock.
            'type' => 'other',
            'description' => $this->data['description']
                ?? __('admin.imports.opening_invoice_line', ['date' => CarbonImmutable::parse($invoice->issue_date)->format('d/m/Y')]),
            'amount' => $amount,
            'vat_rate' => $amount > 0 ? round($vat / $amount * 100, 2) : 0,
            'vat_amount' => $vat,
            'total' => round($amount + $vat, 2),
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your opening-balance import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body.' Now run `php artisan billing:reconcile` — the AR tie-out is what proves the figures match your accountant\'s opening trial balance.';
    }

    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }

    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
