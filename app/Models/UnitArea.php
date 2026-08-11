<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measured area of a unit, for a period.
 *
 * The register behind {@see Unit::areaOn()}. A unit has exactly one open row; remeasuring closes it
 * the day before the new measurement takes effect and opens the next — the same shape as a charge
 * schedule row, and for the same reason: what a period was billed on must stay readable after the
 * number changes.
 *
 * `effective_from` null means "since always", which is what every backfilled opening row carries.
 * That is what makes the migration a no-op: `areaOn(any date)` returns exactly what `area_sqm`
 * returned before.
 */
class UnitArea extends Model
{
    protected $fillable = [
        'unit_id',
        'area_sqm',
        'effective_from',
        'effective_to',
        'reason',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** Is this the measurement in force on the given day? */
    public function coversDate(CarbonImmutable $on): bool
    {
        $on = $on->startOfDay();

        if ($this->effective_from !== null && CarbonImmutable::instance($this->effective_from)->startOfDay()->greaterThan($on)) {
            return false;
        }

        if ($this->effective_to !== null && CarbonImmutable::instance($this->effective_to)->startOfDay()->lessThan($on)) {
            return false;
        }

        return true;
    }
}
