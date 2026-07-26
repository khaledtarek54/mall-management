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

    /**
     * Billed = it produced a recharge invoice that still posts revenue.
     *
     * ONLY a `cancelled` invoice frees the reading to re-bill, because cancelling is the one status
     * that makes InvoiceJournalizer void the GL entry (draft/cancelled → no payload). A `credited`
     * invoice KEEPS its AR + utility_revenue posting, so treating it as "free to re-bill" would post
     * utility_revenue a second time with nothing offsetting the first — an unoffset double-count.
     */
    public function isBilled(): bool
    {
        $invoice = $this->billedInvoice;

        return $invoice instanceof Invoice && $invoice->status !== 'cancelled';
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

        // A billed reading is the EVIDENCE for a live recharge invoice, and there are no soft-deletes
        // here — a raw delete would hard-erase the row and orphan the invoice (destroying the only
        // back-link). Refuse it at the model layer so no dispatch path (crafted or not) can bypass the
        // UI guard. Correct a billed reading by cancelling its invoice first, which frees it.
        static::deleting(function (self $reading) {
            if ($reading->isBilled()) {
                return false;
            }
        });
    }
}
