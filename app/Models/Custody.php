<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
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
#[DeletionAllowed(reason: 'operational: settled through SettleCustodyService')]
#[PropertyOwned]
#[PostingDateGuardedBy(guard: \App\Services\GrantCustodyService::class)]
class Custody extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

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

    /**
     * The عهدة reference and what it was advanced for.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
            $this->purpose,
        ];
    }

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

            if (! $custody->exists) {
                return;
            }

            // ── The custodian is fixed from the moment of the grant ───────────────────────────
            // `asset_id` is denormalised FROM the custodian, so moving the employee moves the
            // books dimension with it — a settled عهدة's entries would land in another property.
            // The module doc states this as a fact ("locked on edit so the books dimension can't
            // drift"); it was `->disabled()` on CustodyForm and nothing else.
            if ($custody->isDirty(['employee_id', 'asset_id'])) {
                throw new \DomainException(__('admin.custodies.errors.custodian_fixed'));
            }

            // ── Grant terms lock once the عهدة has been settled against ───────────────────────
            // The doc's own parenthesis is the failure scenario: "editing them would misstate
            // outstanding". Outstanding is DERIVED (amount − Σ settlements), so lowering `amount`
            // under what is already settled makes it NEGATIVE — the register showing a custodian
            // owing money never granted to them. The grant's journal entry (Dr Custodies /
            // Cr Cash|Bank) also re-derives at the new figure while the settlements' credits do
            // not move, so Custodies stops netting to zero as the عهدة is spent; and `paid_from`
            // decides WHICH account was credited, after the cash has already left it.
            //
            // "Once SETTLED", not "on grant": a عهدة keyed at the wrong figure must stay fixable
            // until it is spent against. Purpose and reference carry no money and no dimension, so
            // they stay editable — an operator must be able to record what it turned out to be for.
            //
            // At the model because the form is one writer of several (import, console, API, a
            // future screen). Same finding and same fix as module 23's disposed-asset freeze
            // (module 25 close-out, 2026-08-11).
            if ($custody->isDirty(['amount', 'custody_date', 'paid_from'])
                && $custody->transactions()->exists()) {
                throw new \DomainException(__('admin.custodies.errors.terms_locked_once_settled'));
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
