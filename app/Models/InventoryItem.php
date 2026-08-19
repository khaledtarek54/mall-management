<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A catalog item (shared reference data). Stock is tracked PER warehouse via
 * stock_movements; on-hand for a warehouse = SUM(quantity) of this item's
 * movements there (see StockMovementService::onHand).
 */
#[DeletableWhenUnused(blockedBy: ['movements'], instead: 'deactivate the item — its movements are what the stock valuation was built from')]
// shared SKU catalog; stock is per-Warehouse
#[PortfolioShared]
class InventoryItem extends Model
{
    use HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit',
        'unit_cost',
        'reorder_level',
        'reorder_quantity',
        'is_active',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'reorder_level' => 'decimal:3',
        'reorder_quantity' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    /**
     * SKU and item name.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->sku,
            $this->name,
            $this->category,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'name', 'category', 'unit', 'unit_cost', 'reorder_level', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('inventory_item');
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
        // NOT-NULL guard: blank/cleared money inputs must never persist as null into
        // NOT-NULL columns (the meter_readings.cost bug class). Read the raw attribute
        // (a decimal cast throws on '').
        static::saving(function (self $item) {
            foreach (['unit_cost', 'reorder_level'] as $column) {
                $raw = $item->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $item->{$column} = 0;
                }
            }
        });
    }
}
