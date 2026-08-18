<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A shelf, rack or bay inside one warehouse.
 *
 * The warehouse says which mall's storeroom; this says where in it. Master data rather than a
 * string on the movement, because a free-text location drifts on the first typo — `A-03-2` and
 * `A032` become two shelves that both look real, and the count splits between them with nothing to
 * reconcile against.
 *
 * **Property-owned THROUGH the warehouse.** A bin has no `asset_id` of its own; it belongs to
 * exactly one warehouse and the warehouse belongs to a property, so a second copy of the column
 * would be a second answer to which mall this shelf is in.
 *
 * Deletable only while nothing has moved through it. A bin with stock history is part of the
 * inventory record — retire it with `is_active` instead, which keeps the movements readable.
 */
#[DeletableWhenUnused(blockedBy: ['movements'], instead: 'a bin with stock history is part of the inventory record — deactivate it instead')]
#[PropertyOwned(via: 'warehouse')]
class Bin extends Model
{
    use HasFactory, RefusesDeletionWhenReferenced, SoftDeletes;

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** `A-03-2 — Filters` when it is named, the bare code when it is not. */
    public function label(): string
    {
        return $this->name ? "{$this->code} — {$this->name}" : (string) $this->code;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * What this bin currently holds, per item — DERIVED from the movements.
     *
     * Never stored. A per-bin quantity column would be a second truth about the same stock, and the
     * first movement recorded outside this model's knowledge would desynchronise it silently — the
     * same reason `InvoiceItemSettlement` refuses to store a per-line balance.
     *
     * @return Collection<int, object>
     */
    public function onHandByItem()
    {
        return $this->movements()
            ->selectRaw('inventory_item_id, SUM(quantity) as on_hand')
            ->groupBy('inventory_item_id')
            ->havingRaw('SUM(quantity) <> 0')
            ->get();
    }
}
