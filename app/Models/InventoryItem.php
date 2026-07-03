<?php

namespace App\Models;

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
class InventoryItem extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit',
        'unit_cost',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'reorder_level' => 'decimal:3',
        'is_active' => 'boolean',
    ];

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
