<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A spare part on a work order (FR-CM-09/10/11, FR-INV-04).
 *
 * `source` is the fact the system previously could not express: an internal draw was
 * implied by a StockMovement existing, and an externally bought part was simply absent.
 * Now both are recorded, so "what did we buy outside this month?" has an answer.
 */
class MaintenanceWorkOrderPart extends Model
{
    use HasFactory, LogsActivity;

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_EXTERNAL = 'external';

    public const SOURCES = [self::SOURCE_INTERNAL, self::SOURCE_EXTERNAL];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** An external purchase: nothing to approve, nothing to decrement. */
    public const STATUS_RECORDED = 'recorded';

    protected $fillable = [
        'maintenance_work_order_id',
        'source',
        'inventory_item_id',
        'warehouse_id',
        'description',
        'vendor_id',
        'reference',
        'quantity',
        'unit_cost',
        'value',
        'status',
        'required_permission',
        'requested_by_user_id',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
        'stock_movement_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'value' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['source', 'inventory_item_id', 'quantity', 'unit_cost', 'value', 'status', 'required_permission', 'decision_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('work_order_part');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function isInternal(): bool
    {
        return $this->source === self::SOURCE_INTERNAL;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** What the part actually cost the job — a rejected request cost nothing. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_RECORDED]);
    }

    /** A label that works for both sources: a SKU, or the free-text description. */
    public function label(): string
    {
        return $this->isInternal()
            ? trim(($this->item?->sku ?? '').' — '.($this->item?->name ?? ''), ' —')
            : (string) $this->description;
    }

    protected static function booted(): void
    {
        static::saving(function (self $part) {
            if (! in_array($part->source, self::SOURCES, true)) {
                throw new InvalidArgumentException(
                    "Unknown part source '{$part->source}'; expected one of: ".implode(', ', self::SOURCES).'.'
                );
            }

            // A zero or negative draw is meaningless and would post a zero-value movement
            // the GL rejects.
            if ((float) $part->quantity <= 0) {
                throw new InvalidArgumentException('A part quantity must be greater than zero.');
            }

            if ($part->isInternal()) {
                // Internal means "out of OUR stock" — without both, there is no draw to make.
                if ($part->inventory_item_id === null || $part->warehouse_id === null) {
                    throw new InvalidArgumentException('An internal part must name the item and the warehouse it comes from.');
                }
            } elseif (blank($part->description)) {
                // External has no SKU in our catalog — that is what makes it external — so
                // the description is the only record of what was actually bought.
                throw new InvalidArgumentException('An external part must describe what was bought.');
            }

            // Derived on every write path, not just the form: the value is what the approval
            // ladder is judged on (FR-CM-11), so it must never drift from qty × cost.
            $part->value = round((float) $part->quantity * (float) $part->unit_cost, 2);
        });
    }
}
