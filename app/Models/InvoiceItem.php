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
