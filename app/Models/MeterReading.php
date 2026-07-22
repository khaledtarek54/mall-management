<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'utility_meter_id',
        'reading_date',
        'reading_value',
        'consumption',
        'cost',
        'notes',
        'billed_invoice_id',
        'billed_at',
    ];

    protected $casts = [
        'reading_date' => 'date',
        'reading_value' => 'decimal:2',
        'consumption' => 'decimal:2',
        'cost' => 'decimal:2',
        'billed_at' => 'datetime',
    ];

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }

    /** The recharge invoice this reading produced (null until it is billed). */
    public function billedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'billed_invoice_id');
    }

    /** Billed = it produced a recharge invoice that is still live (a cancelled one frees it to re-bill). */
    public function isBilled(): bool
    {
        $invoice = $this->billedInvoice;

        return $invoice instanceof Invoice && ! in_array($invoice->status, ['cancelled', 'credited'], true);
    }

    protected static function booted(): void
    {
        // cost is NOT NULL (DDL default 0); a blank/optional form field
        // dehydrates null, which bypasses the default — coerce it to 0.
        static::saving(function (self $reading) {
            if ($reading->cost === null) {
                $reading->cost = 0;
            }
        });
    }
}
