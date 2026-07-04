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
 * An advance or loan (سلفة) paid to an employee (module 24, Phase 2). Posts to the GL
 * as Dr Employee Advances (receivable) / Cr Cash|Bank. Outstanding is DERIVED =
 * amount − Σ(repayments); never a cached column. `asset_id` is denormalised from the
 * employee at creation so the GL dimension survives the employee being archived.
 */
class EmployeeAdvance extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'asset_id',
        'type',
        'amount',
        'advance_date',
        'paid_from',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'advance_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'asset_id', 'type', 'amount', 'advance_date', 'paid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('employee_advance');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Repaid to date = Σ of this advance's repayments. */
    public function repaid(): float
    {
        return round((float) $this->repayments()->sum('amount'), 2);
    }

    /** Outstanding balance = amount − repaid (never negative). */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->amount - $this->repaid()), 2);
    }

    /** The child ledger sources whose GL follows this advance's lifecycle. */
    protected function ledgerChildRelations(): array
    {
        return [$this->repayments()];
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for amount (blank form field must not send null).
        static::saving(function (self $advance) {
            $raw = $advance->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $advance->amount = 0;
            }
        });

        // Child-source cascade (same as FixedAsset): soft-delete cascades to the
        // repayments (their GL voids), stamped with the parent's OWN deleted_at so the
        // restore targets exactly those rows. Bumping their updated_at keeps them in the
        // windowed sync-ledger sweep's window so their GL self-heals. See
        // docs/modules/24-hr-employees.md + the child-source-windowed-sweep note.
        static::deleted(function (self $advance) {
            if ($advance->isForceDeleting()) {
                return;
            }
            foreach ($advance->ledgerChildRelations() as $relation) {
                $relation->update(['deleted_at' => $advance->deleted_at, 'updated_at' => now()]);
            }
        });

        static::restoring(function (self $advance) {
            foreach ($advance->ledgerChildRelations() as $relation) {
                $relation->onlyTrashed()
                    ->where('deleted_at', $advance->deleted_at)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
            }
        });
    }
}
