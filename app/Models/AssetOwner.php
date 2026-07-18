<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The `asset_owner` pivot — legal ownership of a property by a Jawad owner, with an
 * ownership share and a tenure window. Casting it AT SOURCE (rather than every consumer
 * re-parsing raw pivot strings) is what lets the owner-statements weighting math treat
 * `started_at`/`ended_at` as real dates and `ownership_percentage` as a number.
 *
 * Tenure: `started_at`/`ended_at` are inclusive bounds; either null = unbounded on that
 * side (owned since inception / still owned). A mid-period *sale* is modelled by setting
 * `ended_at`; a mid-period *percentage change* (a future refinement) would need separate
 * segments, which the current `unique(user_id, asset_id)` intentionally does not yet allow.
 */
class AssetOwner extends Pivot
{
    protected $table = 'asset_owner';

    public $incrementing = true;

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    /** True if this ownership segment is in effect on $date (default: today). */
    public function coversDate(\DateTimeInterface|string|null $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        if ($this->started_at !== null && $this->started_at->startOfDay()->gt($on)) {
            return false;
        }
        if ($this->ended_at !== null && $this->ended_at->startOfDay()->lt($on)) {
            return false;
        }

        return true;
    }
}
