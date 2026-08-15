<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One month's straight-line rent adjustment for one lease (story RA-02).
 *
 * Posted Dr Deferred Rent / Cr Rental Income when recognition exceeds billing, and the reverse when
 * it does not. Created only by `PostStraightLineRentService`; there is no Filament resource, because
 * it is a derived accounting entry rather than something an operator authors.
 *
 * Soft-deleted to reverse, the same shape as every other adjustment document here — the poster sees
 * a trashed source and voids its entry.
 */
#[DeletionAllowed(reason: 'parent-managed: soft-deleted to reverse a month\'s rent-recognition adjustment (PostStraightLineRentService::reverseFrom), which voids its journal entry — the path a forward-only re-derivation uses after an amendment')]
class StraightLineRentAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'lease_id', 'asset_id', 'period',
        'billed_amount', 'straight_line_amount', 'adjustment_amount', 'entry_date',
    ];

    protected $casts = [
        'period' => 'date',
        'entry_date' => 'date',
        'billed_amount' => 'decimal:2',
        'straight_line_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
    ];

    /** The column this document's GL entry is dated from (LedgerRealtimeSync::SOURCE_DATE_COLUMNS). */
    public static function postingDateColumn(): string
    {
        return 'entry_date';
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
