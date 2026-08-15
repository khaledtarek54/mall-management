<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This mall is running out of this part" (FR-INV-03).
 *
 * One row per (item, property), reused for the life of the shortage: it opens when stock falls to
 * the reorder level, and resolves when the stock comes back. Not one row per firing — that would
 * make "is this mall still short of filters?" a question about the newest of N rows.
 */
#[DeletionAllowed(reason: 'operational: a transient alert')]
#[PropertyOwned]
class LowStockAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_item_id', 'asset_id', 'on_hand', 'reorder_level', 'notified_at', 'resolved_at',
    ];

    protected $casts = [
        'on_hand' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
