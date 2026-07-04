<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function disposal(): HasOne
    {
        return $this->hasOne(FixedAssetDisposal::class);
    }

    /** The child ledger sources whose GL follows this asset's lifecycle (Phase 2/2b). */
    protected function ledgerChildRelations(): array
    {
        return [$this->depreciationEntries(), $this->disposal()];
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

        // Soft-delete cascades to the child sources (depreciation charges + disposal)
        // so the sweep voids their GL too, stamped with the parent's OWN deleted_at so
        // the restore can target exactly the rows this cascade trashed — never a row
        // trashed for another reason. Runs on `deleted` (after deleted_at is set). A
        // force-delete lets the FK cascade physically remove them (an out-of-band op —
        // see the module doc).
        static::deleted(function (self $fixedAsset) {
            if ($fixedAsset->isForceDeleting()) {
                return;
            }
            foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                $relation->update(['deleted_at' => $fixedAsset->deleted_at, 'updated_at' => now()]);
            }
        });

        // Restore ONLY the child rows this asset's delete cascaded (matched on that
        // exact deleted_at), so a row removed for another reason stays removed.
        static::restoring(function (self $fixedAsset) {
            foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                $relation->onlyTrashed()
                    ->where('deleted_at', $fixedAsset->deleted_at)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
            }
        });

        // A change to a field the child sources DERIVE from must re-flow to their GL:
        // asset_id (both re-dimension) and acquisition_cost (the disposal's Furniture
        // credit). Bump the children so the sweep re-derives them — else a re-costed or
        // re-homed asset strands its child entries (they key on their own updated_at).
        static::updated(function (self $fixedAsset) {
            if ($fixedAsset->wasChanged('asset_id') || $fixedAsset->wasChanged('acquisition_cost')) {
                foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                    $relation->withTrashed()->update(['updated_at' => now()]);
                }
            }
        });
    }
}
