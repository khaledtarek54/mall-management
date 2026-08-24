<?php

namespace App\Models;

use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One rung of a {@see UtilityTariff}'s price ladder — a price, and the day it came into force.
 *
 * A rung stays in force until the next one starts; there is no end date, because a from/to pair
 * makes overlapping and missing windows representable and this system has been bitten by exactly
 * that on charge schedules (see the migration).
 *
 * **Editing a rung that is already in force is allowed and safe.** `meter_readings.cost` is
 * computed and stored when the reading is entered and is never re-derived, so an edit changes what
 * is priced NEXT and nothing that has already been priced. That is the same origination-only rule
 * the whole money core runs on, and it is why this is not a `NeverDeletable` record: a rung posts
 * nothing and settles nothing.
 *
 * It is activity-logged all the same. "Who moved the electricity rate, when, and from what" is the
 * first question asked about a disputed recharge, and before this table the answer was a single
 * overwritten number on a meter with no record that it had ever been anything else.
 */
#[DeletionAllowed(reason: 'parent-managed: effective-dated prices on a tariff, edited from the tariff')]
// a rung on a UtilityTariff's dated ladder; shared for the same reason as its parent
#[PortfolioShared]
class UtilityTariffRate extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'utility_tariff_id',
        'rate_per_unit',
        'effective_from',
        'note',
    ];

    protected $casts = [
        'rate_per_unit' => 'decimal:4',
        'effective_from' => 'immutable_date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'utility_tariff_rate');
    }

    /** @return BelongsTo<UtilityTariff, $this> */
    public function utilityTariff(): BelongsTo
    {
        return $this->belongsTo(UtilityTariff::class);
    }
}
