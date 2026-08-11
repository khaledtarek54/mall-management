<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'charge_id',
        'description',
        'type',
        'amount',
        'vat_rate',
        'vat_amount',
        'total',
        'disputed_at',
        'disputed_reason',
        'disputed_by_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'disputed_at' => 'datetime',
    ];

    /** A line the tenant is formally arguing about (story MF-07). */
    public function isDisputed(): bool
    {
        return $this->disputed_at !== null;
    }


    /**
     * Bump the parent invoice's updated_at on any item write. The invoice's GL entry
     * is DERIVED from its items (InvoiceJournalizer splits revenue by item type), but
     * the windowed `accounting:sync-ledger` sweep discovers sources by their OWN
     * updated_at. A money-neutral item edit — e.g. re-typing base_rent→service_charge,
     * which remaps the revenue account without changing the invoice total — would
     * otherwise leave invoice.updated_at untouched, stranding the wrong revenue split
     * in the GL until a manual --all backfill. Touching the invoice pulls it into the
     * sweep's recent window so the entry re-derives. (GL integrity hardening — F3.)
     */
    protected $touches = ['invoice'];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $amount = (float) ($item->amount ?? 0);
            $rate = (float) ($item->vat_rate ?? 0);
            $item->vat_amount = round($amount * $rate / 100, 2);
            $item->total = round($amount + (float) $item->vat_amount, 2);
        });

        // The invoice header is DERIVED from these lines — moving a line moves the header with
        // it, on every path (form repeater, billing services, API, import, console). Without this
        // an item write left `invoices.subtotal/vat_amount/total` untouched, so AR (from the
        // header) and GL revenue (from the items) drifted apart silently.
        // See Invoice::syncTotalsFromItems() for why the rule lives at the model layer.
        $syncInvoiceHeader = function (self $item) {
            $item->invoice?->syncTotalsFromItems();
        };
        static::saved($syncInvoiceHeader);
        static::deleted($syncInvoiceHeader);

        // A 'marketing' line item funds the property's marketing budget — keep the
        // budget's accrued_amount DERIVED from these items (mirrors recomputeSpent).
        $syncMarketing = function (self $item) {
            if ($item->type !== 'marketing') {
                return;
            }
            $invoice = $item->invoice;
            $assetId = $invoice?->lease?->unit?->asset_id;
            $year = $invoice?->issue_date?->year;
            if ($assetId && $year) {
                MarketingBudget::forPeriod($assetId, (int) $year)->recomputeAccrued();
            }
        };
        static::saved($syncMarketing);
        static::deleted($syncMarketing);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
