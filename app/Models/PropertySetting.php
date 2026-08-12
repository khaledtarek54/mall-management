<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One setting a property answers differently from the portfolio.
 *
 * A row is an OVERRIDE: its absence is the normal state and means "whatever the portfolio says",
 * never zero. See `App\Support\PropertySettings` for the three-tier resolution and for why only an
 * explicit allow-list may be overridden at all.
 *
 * Activity-logged for the same reason the portfolio settings are: these are money figures — a late
 * fee, a payment term — and "who changed this, for which mall, and from what" is the first question
 * asked about one.
 */
class PropertySetting extends Model
{
    use LogsActivity;

    protected $fillable = ['asset_id', 'group', 'name', 'payload'];

    protected $casts = ['payload' => 'json'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'group', 'name', 'payload'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('property_setting');
    }

    protected static function booted(): void
    {
        $flush = fn (self $row) => \App\Support\PropertySettings::forgetCache($row->asset_id);

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
