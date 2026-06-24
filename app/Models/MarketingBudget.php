<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Per-property, per-year marketing fund (FR MKT-3/5). Modeled on the CAM
 * expense-pool pattern but on the income side: accrued_amount accumulates the
 * 5% marketing levy from leases; spent_amount accumulates marketing spend.
 * balance() is always derived — never hand-edited.
 */
class MarketingBudget extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['accrued_amount', 'spent_amount', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('marketing_budget');
    }

    protected $fillable = [
        'asset_id',
        'period_year',
        'accrued_amount',
        'spent_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'accrued_amount' => 'decimal:2',
        'spent_amount' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** Remaining marketing funds: accrued levies minus spend (FR MKT-5). */
    public function balance(): float
    {
        return round((float) $this->accrued_amount - (float) $this->spent_amount, 2);
    }

    /**
     * Get-or-create the budget row for an asset + year. The single entry point
     * so accrual and spend always target one row per (asset, period).
     */
    public static function forPeriod(int $assetId, int $year): self
    {
        return static::firstOrCreate([
            'asset_id' => $assetId,
            'period_year' => $year,
        ]);
    }
}
