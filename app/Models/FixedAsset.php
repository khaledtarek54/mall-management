<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A depreciable asset the operator owns (module 23), scoped to one property.
 * Depreciation is straight-line: (acquisition_cost − salvage_value) spread over
 * useful_life_months. Accumulated depreciation is DERIVED from depreciation_entries
 * (DepreciationService), never a cached column.
 */
class FixedAsset extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'tag',
        'category',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'method',
        'funded_from',
        'status',
        'disposed_on',
        'notes',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'disposed_on' => 'date',
        'acquisition_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'name', 'tag', 'acquisition_cost', 'salvage_value', 'useful_life_months', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fixed_asset');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (the meter_readings.cost bug class).
        static::saving(function (self $fixedAsset) {
            foreach (['acquisition_cost', 'salvage_value'] as $column) {
                $raw = $fixedAsset->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $fixedAsset->{$column} = 0;
                }
            }
        });

        // --- Keep the depreciation charges' ledger entries in lock-step with the
        // parent. Each DepreciationEntry is its OWN ledger source, but the windowed
        // `accounting:sync-ledger` sweep discovers sources by their own updated_at.
        // A change to the PARENT (soft-delete / restore / re-home) does not bump the
        // children's updated_at, so without these hooks the charges' journal entries
        // strand — posted for a deleted asset, or dimensioned to the old property —
        // until a manual `--all` backfill. Bumping the children brings them back into
        // the sweep's recent window so their GL self-heals on the very next run.

        // Soft-delete cascades to the charges (so the sweep voids their GL too),
        // stamped with the parent's OWN deleted_at so the restore can target exactly
        // the rows this cascade trashed — never a charge trashed for another reason.
        // Runs on `deleted` (after deleted_at is set). A force-delete lets the FK
        // cascade physically remove them (an out-of-band op — see the module doc).
        static::deleted(function (self $fixedAsset) {
            if ($fixedAsset->isForceDeleting()) {
                return;
            }
            $fixedAsset->depreciationEntries()->update([
                'deleted_at' => $fixedAsset->deleted_at,
                'updated_at' => now(),
            ]);
        });

        // Restore ONLY the charges this asset's delete cascaded (matched on that exact
        // deleted_at), so a charge removed for another reason stays removed.
        static::restoring(function (self $fixedAsset) {
            $fixedAsset->depreciationEntries()->onlyTrashed()
                ->where('deleted_at', $fixedAsset->deleted_at)
                ->update(['deleted_at' => null, 'updated_at' => now()]);
        });

        // Re-homing to another property must re-dimension the charges' GL entries —
        // bump them so the sweep re-derives their asset_id from the new parent.
        static::updated(function (self $fixedAsset) {
            if ($fixedAsset->wasChanged('asset_id')) {
                $fixedAsset->depreciationEntries()->withTrashed()->update(['updated_at' => now()]);
            }
        });
    }
}
