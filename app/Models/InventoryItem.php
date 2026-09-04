<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
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
        return ActivityLogging::for($this, 'inventory_item');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Does this item state a minimum at all?
     *
     * **A `reorder_level` of 0 means "we do not track a minimum for this", never "alert whenever
     * it hits zero"** — otherwise every catalogue item a mall has never stocked is permanently
     * short. `ScanLowStockCommand` has said exactly that in writing since it shipped
     * (`->where('reorder_level', '>', 0)`), and the LIST answered the opposite question about the
     * same items, twice: the on-hand column coloured `0 <= 0` DANGER and the low-stock filter's
     * `reorder_level >= sum(quantity)` was TRUE. So the reorder worklist opened on every item the
     * mall had never stocked, each painted red, and none of them would ever produce an alert.
     *
     * Measured 2026-09-04. `InventoryItemForm` defaults `reorder_level` to 0, so that is not an
     * exotic row — it is every item created through the panel by somebody with no threshold to
     * type. Against `mall_management_qa`, `0 >= coalesce(sum(quantity), 0)` holds for every item
     * whose scoped on-hand is 0, i.e. every catalogue item a given mall has never stocked; all
     * twelve demo items carry a positive level, which is why the demo data never showed it.
     *
     * One predicate, three readers — the column's colour, the filter and the scan — so the
     * worklist, the red figure and the bell can no longer disagree about which items are short.
     * The PHP and SQL forms cannot be collapsed (the filter compares against a correlated
     * subquery); `AnItemWithNoReorderLevelIsNotShortTest` asserts they answer alike.
     */
    public function tracksAReorderLevel(): bool
    {
        return (float) $this->reorder_level > 0;
    }

    /** Is this item short at that on-hand figure? An item with no stated minimum never is. */
    public function isLowAt(float $onHand): bool
    {
        return $this->tracksAReorderLevel() && $onHand <= (float) $this->reorder_level;
    }

    /** The query twin of {@see tracksAReorderLevel()} — items that state a minimum at all. */
    public function scopeTracksAReorderLevel(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('reorder_level'), '>', 0);
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
