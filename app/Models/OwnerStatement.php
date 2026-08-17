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
 * كشف حساب المالك — the per-owner deliverable within a run: the owner's share of the
 * property's net for the period (`owner_share`), the confidential statement they receive.
 * v1 = one owner at 100%, so `owner_share = run.net_distributable` and `weight = 1`. Not a
 * GL source (the run posts the accrual); `paid_to_date` is recomputed from disbursements,
 * never set by hand.
 */
#[DeletionAllowed(reason: 'parent-managed: force-deleted when its run is rebuilt')]
// per-owner child; asset_id denormalized for uniform auto-scope
#[PropertyOwned]
class OwnerStatement extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALISED = 'finalised';

    public const STATUS_SENT = 'sent';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_FINALISED, self::STATUS_SENT, self::STATUS_SUPERSEDED];

    protected $fillable = [
        'reference',
        'owner_statement_run_id',
        'asset_id',
        'user_id',
        'ownership_percentage',
        'tenure_from',
        'tenure_to',
        'weight',
        'owner_share',
        'share_revenue',
        'share_expense',
        'paid_to_date',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'tenure_from' => 'date',
        'tenure_to' => 'date',
        'weight' => 'decimal:6',
        'owner_share' => 'decimal:2',
        'share_revenue' => 'decimal:2',
        'share_expense' => 'decimal:2',
        'paid_to_date' => 'decimal:2',
        'sent_at' => 'datetime',
    ];

    /** NOT-NULL-safe defaults — these figures are service-computed, never form-entered. */
    protected $attributes = [
        'ownership_percentage' => 0,
        'weight' => 0,
        'owner_share' => 0,
        'share_revenue' => 0,
        'share_expense' => 0,
        'paid_to_date' => 0,
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $statement) {
            if (! in_array($statement->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Invalid owner_statement status '{$statement->status}'.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'owner_statement_run_id', 'user_id', 'status', 'owner_share', 'sent_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('owner_statement');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        $candidate = sprintf('OS-%s-%04d', $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('OS-%s-%04d', $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }

    /** The still-unpaid amount this owner is owed (never negative). */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->owner_share - (float) $this->paid_to_date, 2));
    }

    /**
     * Recompute `paid_to_date` from the sum of PAID disbursements and persist it. The single
     * source of truth for how much of this statement has been paid — never hand-set (mirrors
     * Invoice::recomputeTotals()'s discipline for AR).
     */
    public function recomputePaidToDate(): void
    {
        $paid = (float) $this->disbursements()
            ->where('status', Disbursement::STATUS_PAID)
            ->sum('amount');

        $this->forceFill(['paid_to_date' => round($paid, 2)])->saveQuietly();
    }

    /** @return BelongsTo<OwnerStatementRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(OwnerStatementRun::class, 'owner_statement_run_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
