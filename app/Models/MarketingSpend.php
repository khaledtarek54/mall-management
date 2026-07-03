<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A marketing spend item (FR MKT-1/4). Belongs to a MarketingBudget; any
 * change keeps the budget's spent_amount in sync via recomputeSpent() (MKT-5).
 */
class MarketingSpend extends Model
{
    use LogsActivity, SoftDeletes;

    public const CATEGORIES = ['offer', 'promotion', 'event', 'printed_work', 'other'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['marketing_budget_id', 'category', 'amount', 'paid_from', 'receipt_reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('marketing_spend');
    }

    protected $fillable = [
        'marketing_budget_id',
        'category',
        'description',
        'amount',
        'paid_from',
        'spent_on',
        'receipt_reference',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(MarketingBudget::class, 'marketing_budget_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard: a blank/cleared paid_from must never persist as null into
        // the NOT-NULL column (the meter_readings.cost / leases.has_percentage_rent
        // bug class). Default to cash so the GL journalizer always resolves an account.
        static::saving(function (self $spend) {
            if (blank($spend->paid_from)) {
                $spend->paid_from = 'cash';
            }
        });

        // Keep the parent budget's spent_amount derived on every change.
        $sync = fn (self $spend) => $spend->budget?->recomputeSpent();

        static::saved($sync);
        static::deleted($sync);
        static::restored($sync);
    }
}
