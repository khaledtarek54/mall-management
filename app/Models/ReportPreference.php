<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator's remembered choices on one report (RP-02).
 *
 * Not activity-logged, unlike the settings it superficially resembles: this changes what one person
 * SEES, never what the system does or what anyone is charged. Logging every filter change would
 * bury the money trail the activity log exists for.
 *
 * See `App\Support\ReportPreferences` for what is stored and — more importantly — what is not.
 */
#[DeletionAllowed(reason: 'preference: one operator\'s remembered report filters')]
// One operator's remembered report filters. Belongs to the USER, not a property: the
// stored assetId IS the preference, not an ownership claim, and scoping the row itself
// would mean re-picking the mall on a report whose whole point is not re-picking it.
#[PortfolioShared]
class ReportPreference extends Model
{
    protected $fillable = ['user_id', 'report', 'parameters'];

    protected $casts = ['parameters' => 'array'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
