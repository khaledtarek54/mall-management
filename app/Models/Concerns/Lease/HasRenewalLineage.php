<?php

namespace App\Models\Concerns\Lease;

use App\Models\Lease;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **Where a lease came from: `previous_lease_id` and the type axis derived from it.**
 *
 * Small and completely closed — four members over one nullable FK. `leaseType()` derives NEW vs
 * RENEWAL rather than reading a stored column, deliberately: a `type` column would be a second
 * source of truth for a fact the FK already answers, and the two would drift the first time a
 * renewal was created by a path that forgot to set it.
 */
trait HasRenewalLineage
{
    /**
     * The lease TYPE axis — how the lease came about, which is separate from its status.
     *
     * Derived from `previous_lease_id` rather than stored; see {@see leaseType()} for why a column
     * would be a second source of truth for a fact the database already holds.
     */
    public const TYPE_NEW = 'new';

    public const TYPE_RENEWAL = 'renewal';

    public function previousLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'previous_lease_id');
    }

    /**
     * How this lease came about — Yardi's lease TYPE axis, which is separate from its status.
     *
     * **Derived, never stored.** Yardi keeps type as its own column, and the gap analysis originally
     * called for one here (row 42). It is not needed: `previous_lease_id` is already written by
     * `LeaseRenewalService` and read by two relations, so "is this a renewal" is a fact the database
     * already holds. A `lease_type` column would be a second source of truth for it, and the two
     * would disagree the first time a renewal was created by any path that forgot to set it.
     *
     * Only two values, deliberately. Yardi also types a lease as an *expansion*, but that is a shape
     * Atriom does not have: taking extra space here adds units to the SAME lease and records a
     * `LeaseEvent::TYPE_EXPANSION`, rather than originating a second lease. Holdover is likewise a
     * state the lease enters, not the way it began.
     */
    public function leaseType(): string
    {
        return $this->previous_lease_id !== null ? self::TYPE_RENEWAL : self::TYPE_NEW;
    }

    public function isRenewal(): bool
    {
        return $this->leaseType() === self::TYPE_RENEWAL;
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Lease::class, 'previous_lease_id');
    }
}
