<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One append-only stock ledger row. `quantity` is SIGNED (positive adds stock,
 * negative removes it); on-hand is derived by summing these — never a cached
 * count. Corrections are a new `adjustment` movement, not an edit (the stock
 * history stays intact). Value = |quantity| × unit_cost drives the GL costing
 * (Phase 3).
 *
 * ALWAYS write through StockMovementService (record/receive/adjust) — it forces
 * the sign by type. A direct StockMovement::create() bypasses that and can store
 * a wrong-signed quantity, corrupting on-hand.
 */
class StockMovement extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /** Positive movements ADD stock; negative movements REMOVE it. */
    public const TYPES = ['receipt', 'consumption', 'adjustment', 'transfer_in', 'transfer_out'];

    public const ADDS_STOCK = ['receipt', 'transfer_in'];

    public const REMOVES_STOCK = ['consumption', 'transfer_out'];

    protected $fillable = [
        'warehouse_id',
        'inventory_item_id',
        'type',
        'quantity',
        'unit_cost',
        'reference',
        'source_type',
        'source_id',
        'moved_by_user_id',
        'moved_on',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'moved_on' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['warehouse_id', 'inventory_item_id', 'type', 'quantity', 'unit_cost', 'reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('stock_movement');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Money value of the stock moved (always non-negative). */
    public function value(): float
    {
        return round(abs((float) $this->quantity) * (float) $this->unit_cost, 2);
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money/quantity columns (the meter_readings.cost class).
        static::saving(function (self $movement) {
            foreach (['quantity', 'unit_cost'] as $column) {
                $raw = $movement->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $movement->{$column} = 0;
                }
            }
        });
    }
}
