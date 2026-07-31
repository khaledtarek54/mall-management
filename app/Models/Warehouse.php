<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A stock location, scoped to one property (asset). Free-form category so the
 * operator can model spare-parts stores, machine stores, daily-consumable stores,
 * or whatever structure they run. On-hand quantity is derived from stock_movements.
 */
class Warehouse extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'code',
        'category',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'name', 'code', 'category', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('warehouse');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        // Re-home cascade: a warehouse's property (asset_id) is the books dimension of
        // every stock movement it holds (InventoryMovementJournalizer reads
        // warehouse->asset_id). Movements are their OWN ledger sources, discovered by
        // the windowed `accounting:sync-ledger` sweep via their own updated_at — a
        // change to the PARENT warehouse never bumps them, so a re-homed warehouse
        // would strand every movement's GL on the old property until a manual --all.
        // Bump them so the sweep re-derives their dimension on the next run.
        // (GL integrity hardening — Phase 0, F9; mirrors FixedAsset's child cascade.)
        //
        // NOTE — deliberately NO soft-delete cascade here (unlike VendorBill→payments):
        // a stock movement is a completed historical fact (inventory value already
        // moved), so it must NOT unwind when its warehouse is soft-deleted. That intent
        // is enforced on the other side too — `StockMovement::warehouse()` uses
        // withTrashed() so a movement keeps resolving its asset (and its GL) after the
        // warehouse is trashed. Only the re-home (dimension change) needs to propagate.
        static::updated(function (self $warehouse) {
            if ($warehouse->wasChanged('asset_id')) {
                $warehouse->movements()->withTrashed()->update(['updated_at' => now()]);
            }
        });
    }
}
