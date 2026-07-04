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
 * A custody (عهدة — module 25, Treasury Phase 1): cash placed in an employee's hands
 * to spend for the company. Grant posts Dr Custodies (asset) / Cr Cash|Bank.
 * Outstanding is DERIVED = amount − Σ(settlements); never cached. `asset_id` is
 * denormalised from the custodian so the GL dimension survives the employee's archival.
 */
class Custody extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'asset_id',
        'reference',
        'amount',
        'custody_date',
        'paid_from',
        'purpose',
        'created_by_user_id',
    ];

    protected $casts = [
        'custody_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'asset_id', 'reference', 'amount', 'custody_date', 'paid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('custody');
    }

    public function employee(): BelongsTo
    {
        // withTrashed so a historical custody stays attributable after staff turnover.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustodyTransaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Settled to date = Σ of this custody's settlements (expenses + returns). */
    public function settled(): float
    {
        return round((float) $this->transactions()->sum('amount'), 2);
    }

    /** Outstanding = amount − settled (never negative). */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->amount - $this->settled()), 2);
    }

    /** The child ledger sources whose GL follows this custody's lifecycle. */
    protected function ledgerChildRelations(): array
    {
        return [$this->transactions()];
    }

    protected static function booted(): void
    {
        static::saving(function (self $custody) {
            $raw = $custody->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $custody->amount = 0;
            }
        });

        // Child-source cascade (same as EmployeeAdvance / FixedAsset): soft-delete
        // cascades to the settlements (their GL voids), stamped with the parent's own
        // deleted_at; restore targets exactly those rows. Bumping updated_at keeps them
        // in the windowed sync-ledger sweep's window. See project child-source note.
        static::deleted(function (self $custody) {
            if ($custody->isForceDeleting()) {
                return;
            }
            foreach ($custody->ledgerChildRelations() as $relation) {
                $relation->update(['deleted_at' => $custody->deleted_at, 'updated_at' => now()]);
            }
        });

        static::restoring(function (self $custody) {
            foreach ($custody->ledgerChildRelations() as $relation) {
                $relation->onlyTrashed()
                    ->where('deleted_at', $custody->deleted_at)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
            }
        });
    }
}
