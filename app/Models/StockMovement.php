<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\StockMovementService;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
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
#[NeverDeletable(correction: 'post a correcting movement; the original is what the GL was built from')]
#[PropertyOwned(via: 'warehouse')]
#[PostingDateGuardedBy(guard: StockMovementService::class)]
class StockMovement extends Model
{
    use HasFactory, HasSearchText, LogsActivity, RefusesDeletionOfCommittedRecords, SoftDeletes;

    /** Positive movements ADD stock; negative movements REMOVE it. */
    public const TYPES = ['receipt', 'consumption', 'adjustment', 'transfer_in', 'transfer_out'];

    public const ADDS_STOCK = ['receipt', 'transfer_in'];

    public const REMOVES_STOCK = ['consumption', 'transfer_out'];

    protected $fillable = [
        'warehouse_id',
        // Which shelf inside that warehouse. Nullable by design — an operator who does not rack
        // their storeroom pays nothing for bins, and every movement written before they existed
        // has none.
        'bin_id',
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

    /**
     * The source document reference the movement came from.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->reference,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'stock_movement');
    }

    public function warehouse(): BelongsTo
    {
        // withTrashed so a live movement stays GL-attributable (asset_id resolves)
        // after its warehouse is soft-deleted — else its journal entry would be voided
        // by the sweep while on-hand still counts it. (Matches Custody/PayrollLine::employee.)
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class);
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
