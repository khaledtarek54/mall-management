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
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $amount = (float) ($item->amount ?? 0);
            $rate = (float) ($item->vat_rate ?? 0);
            $item->vat_amount = round($amount * $rate / 100, 2);
            $item->total = round($amount + (float) $item->vat_amount, 2);
        });

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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
