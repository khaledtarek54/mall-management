<?php

namespace App\Models;

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
