<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * كشف حساب المالك (تشغيل) — an owner-statement RUN: one property, one accounting period,
 * the accounting truth for what the property earned and what its owner is owed. Finalising
 * a run posts the GL accrual (slice 5) and freezes its figures; its child OwnerStatements
 * are the per-owner deliverable. v1 has one owner per mall who gets 100% of the net, so
 * `net_distributable = net_operating_income` (no management fee, no co-owner split).
 */
class OwnerStatementRun extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALISED = 'finalised';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_FINALISED, self::STATUS_SUPERSEDED];

    public const BASIS_ACCRUAL = 'accrual';
    public const BASIS_CASH = 'cash';
    public const BASES = [self::BASIS_ACCRUAL, self::BASIS_CASH];

    protected $fillable = [
        'reference',
        'asset_id',
        'accounting_period_id',
        'period_start',
        'period_end',
        'posting_date',
        'basis',
        'total_revenue',
        'total_expense',
        'net_operating_income',
        'net_distributable',
        'version',
        'supersedes_id',
        'status',
        'finalised_at',
        'finalised_by_user_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'posting_date' => 'date',
        'total_revenue' => 'decimal:2',
        'total_expense' => 'decimal:2',
        'net_operating_income' => 'decimal:2',
        'net_distributable' => 'decimal:2',
        'version' => 'integer',
        'finalised_at' => 'datetime',
    ];

    /** NOT-NULL-safe defaults so a partial create can never write a null money/status column. */
    protected $attributes = [
        'basis' => self::BASIS_ACCRUAL,
        'total_revenue' => 0,
        'total_expense' => 0,
        'net_operating_income' => 0,
        'net_distributable' => 0,
        'version' => 1,
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $run) {
            if (! in_array($run->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Invalid owner_statement_run status '{$run->status}'.");
            }
            if (! in_array($run->basis, self::BASES, true)) {
                throw new \InvalidArgumentException("Invalid owner_statement_run basis '{$run->basis}'.");
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'asset_id', 'accounting_period_id', 'status', 'net_distributable', 'version'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('owner_statement_run');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;

        // Bump until free — the bare count is race-prone (mirrors OwnerRequest / the money helpers).
        $candidate = sprintf('OSR-%s-%04d', $year, $count);
        $attempts = 0;
        while (static::withTrashed()->where('reference', $candidate)->exists() && $attempts < 50) {
            $candidate = sprintf('OSR-%s-%04d', $year, ++$count);
            $attempts++;
        }

        return $candidate;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalised(): bool
    {
        return $this->status === self::STATUS_FINALISED;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(OwnerStatement::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by_user_id');
    }
}
