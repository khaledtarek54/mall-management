<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The `asset_user` pivot — which admin-panel users operate a property, with a job title at that
 * property and a tenure window. The twin of {@see AssetOwner}, which is legal OWNERSHIP.
 *
 * It had no pivot model at all until 2026-09-05, so `assigned_at` and `ended_at` came back as raw
 * strings while the ownership pivot beside it returned real dates — and "is this assignment still
 * running?" had no definition anywhere, because staff tenure gates nothing today
 * (`AssignedAssets::idsFor()` says so in as many words: *"Staff assignments stay all-time — that
 * tenure is a separate concern"*). The Assigned Staff register now shows whether each person is
 * still working here, and that answer had to come from somewhere; a second hand-rolled date
 * comparison beside `AssetOwner::coversDate()` is how two readings of one idea start disagreeing.
 *
 * Tenure: `assigned_at`/`ended_at` are inclusive bounds; either null = unbounded on that side
 * (assigned since inception / still assigned). A person who leaves is recorded with an `ended_at`,
 * never by detaching the row — detaching erases that they were ever here.
 */
#[DeletionAllowed(reason: 'parent-managed: the staff-assignment pivot, edited from the property')]
#[PropertyOwned]
class AssetUser extends Pivot
{
    protected $table = 'asset_user';

    public $incrementing = true;

    protected $casts = [
        'assigned_at' => 'date',
        'ended_at' => 'date',
    ];

    /**
     * True if this assignment is in effect on $date (default: today).
     *
     * Deliberately identical in shape to {@see AssetOwner::coversDate()}, including the
     * `startOfDay()` on every side: comparing a date column against an instant is what made a
     * one-day tenure read as invalid on the form (see `App\Support\Filament\TenureRange`), and the
     * same trap applies to reading one back.
     */
    public function coversDate(\DateTimeInterface|string|null $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        if ($this->assigned_at !== null && $this->assigned_at->startOfDay()->gt($on)) {
            return false;
        }
        if ($this->ended_at !== null && $this->ended_at->startOfDay()->lt($on)) {
            return false;
        }

        return true;
    }

    /** Has this assignment ENDED, as opposed to not having started yet? */
    public function hasEnded(?\DateTimeInterface $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        return $this->ended_at !== null && $this->ended_at->startOfDay()->lt($on);
    }
}
