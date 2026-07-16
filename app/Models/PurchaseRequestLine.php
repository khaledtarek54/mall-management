<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One line of a procurement request (FR-PROC-01: "item(s), quantity, and justification").
 *
 * A line is EITHER a catalog item (which becomes stock on receipt, FR-PROC-04) OR free text for
 * something we do not stock — the module's own preamble covers "spare parts, consumables, and
 * services", and a service is not stock. Never both: the two would disagree about what was bought.
 */
class PurchaseRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id', 'inventory_item_id', 'description',
        'quantity', 'unit_cost', 'line_value', 'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'line_value' => 'decimal:2',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** The receipt this line produced (FR-WH-02's "linked procurement reference", from the line's side). */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    /** Does this line become stock when it arrives? */
    public function isStockable(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function label(): string
    {
        return $this->isStockable()
            ? trim(($this->item?->sku ?? '').' — '.($this->item?->name ?? ''), ' —')
            : (string) $this->description;
    }

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            if ($line->inventory_item_id === null && blank($line->description)) {
                throw new InvalidArgumentException('A purchase line must name a catalog item or describe what is being bought.');
            }

            if ($line->inventory_item_id !== null && filled($line->description)) {
                throw new InvalidArgumentException('A purchase line is either a catalog item or free text, not both.');
            }

            if ((float) $line->quantity <= 0) {
                throw new InvalidArgumentException('A purchase line quantity must be greater than zero.');
            }

            // A negative cost would make a line *reduce* what the request is worth — and the total
            // is what the approval tier is judged on (FR-PROC-02).
            if ((float) $line->unit_cost < 0) {
                throw new InvalidArgumentException('A purchase line unit cost cannot be negative.');
            }

            // Derived on every write path, not just the form.
            $line->line_value = round((float) $line->quantity * (float) $line->unit_cost, 2);
        });

        // The parent's total is what someone approves, so it can never lag its lines.
        static::saved(fn (self $line) => $line->request?->recomputeTotal());
        static::deleted(fn (self $line) => $line->request?->recomputeTotal());
    }
}
