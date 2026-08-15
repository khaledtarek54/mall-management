<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Per-property, per-year marketing fund (FR MKT-3/5). Modeled on the CAM
 * expense-pool pattern but on the income side: accrued_amount accumulates the
 * 5% marketing levy from leases; spent_amount is derived from the budget's
 * spends. balance() is always computed — never hand-edited.
 */
#[DeletionAllowed(reason: 'configuration: a spend envelope')]
#[PropertyOwned]
class MarketingBudget extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

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

    /** See AccountingPeriod::label() — the identity an id-reference should read as. */
    public function label(): string
    {
        return (string) $this->period_year;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function spends(): HasMany
    {
        return $this->hasMany(MarketingSpend::class);
    }

    /** Remaining marketing funds: accrued levies minus spend (FR MKT-5). */
    public function balance(): float
    {
        return round((float) $this->accrued_amount - (float) $this->spent_amount, 2);
    }

    /** Recompute spent_amount from the (non-trashed) spends — keeps it derived. */
    public function recomputeSpent(): void
    {
        $this->update(['spent_amount' => $this->spends()->sum('amount')]);
    }

    /**
     * Recompute accrued_amount from the billed marketing line items for this
     * property + year (cancelled invoices excluded). Derived from source, so it
     * reconciles and auto-reverses on invoice cancellation — never a blind
     * running total. The single source of truth for the marketing fund's income.
     */
    public function recomputeAccrued(): void
    {
        $accrued = InvoiceItem::query()
            ->where('invoice_items.type', 'marketing')
            ->whereHas('invoice', function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->whereYear('issue_date', $this->period_year)
                    ->where('invoices.asset_id', $this->asset_id);
            })
            ->sum('amount');

        $this->update(['accrued_amount' => round((float) $accrued, 2)]);
    }

    /**
     * Get-or-create the budget row for an asset + year. The single entry point
     * so accrual and spend always target one row per (asset, period).
     */
    public static function forPeriod(int $assetId, int $year): self
    {
        // Soft-delete aware: a trashed row still occupies the (asset_id,
        // period_year) unique key, so a plain firstOrCreate would collide and
        // crash the billing pipeline. Restore the existing row instead.
        $budget = static::withTrashed()->firstOrCreate([
            'asset_id' => $assetId,
            'period_year' => $year,
        ]);

        if ($budget->trashed()) {
            $budget->restore();
        }

        return $budget;
    }
}
