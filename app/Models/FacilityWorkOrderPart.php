<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
#[DeletionAllowed(reason: 'parent-managed: edited as part of the work order')]
class FacilityWorkOrderPart extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_EXTERNAL = 'external';

    public const SOURCES = [self::SOURCE_INTERNAL, self::SOURCE_EXTERNAL];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** An external purchase: nothing to approve, nothing to decrement. */
    public const STATUS_RECORDED = 'recorded';

    protected $fillable = [
        'facility_work_order_id',
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
        return $this->belongsTo(FacilityWorkOrder::class, 'facility_work_order_id');
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

    /**
     * What the part actually cost the job — a rejected request cost nothing.
     *
     * An approved draw counts **only while its movement is live**. Voiding the movement puts
     * the stock back on the shelf, so continuing to charge the job for it would overstate the
     * cost against stock that was returned (proven: on-hand went 45 → 50 while `partsCost()`
     * still reported 500). The stock ledger is the authority on whether stock moved; this
     * scope defers to it rather than trusting a status that nothing updates.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $internal) => $internal
                ->where('status', self::STATUS_APPROVED)
                ->whereHas('movement'))
            ->orWhere('status', self::STATUS_RECORDED));
    }

    /**
     * An approved draw whose movement was voided: the stock came back, but the row still says
     * "issued". Surfaced so the UI doesn't quietly lie about it.
     */
    public function movementWasVoided(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->stock_movement_id !== null
            && $this->movement === null;
    }

    /**
     * Who this draw is waiting on, in words ("a supervisor") — or null if nothing is pending.
     *
     * The tier is stored as a permission (`approvals.tier_1`), and a translation key cannot
     * carry it verbatim: `__()` reads dots as nesting, so `parts.tiers.approvals.tier_1` looks
     * for a `tiers → approvals → tier_1` that does not exist and hands back the raw key. It did
     * exactly that on every pending row until this mapped to the bare tier. An unrecognised
     * permission falls back to a vague-but-true label rather than leaking a key into the UI.
     */
    public function awaitingTierLabel(): ?string
    {
        if (! $this->isPending() || $this->required_permission === null) {
            return null;
        }

        $tier = str_replace('approvals.', '', $this->required_permission);

        if (! in_array($this->required_permission, ApprovalRule::TIERS, true)) {
            $tier = 'unknown';
        }

        return __("admin.facility.parts.tiers.{$tier}");
    }

    /**
     * FR-CM-12 — "For parts sourced from outside, the system shall determine responsibility (and
     * who bears the cost) based on who caused the part to fail, **as recorded on the work order**."
     *
     * So: the answer is not stored here, it is *read from the job's attribution*. The FRD points at
     * the work order as the place the cause is recorded, and duplicating the bearer onto every part
     * would let the two disagree the moment someone revises the finding.
     *
     * Null when nobody has ruled yet. Internal draws return null too — FR-CM-12 is explicitly
     * scoped to parts "sourced from outside"; our own stock is our own cost, and the question of
     * who pays for it is FR-CM-13's job at the repair level, not this part's.
     */
    public function costBearer(): ?string
    {
        if ($this->isInternal()) {
            return null;
        }

        return $this->workOrder?->cost_bearer;
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

            // A negative cost would make a part *reduce* the job's materials cost. The form
            // says minValue(0), but the form is one caller — the rule belongs where every
            // write passes.
            if ((float) $part->unit_cost < 0) {
                throw new InvalidArgumentException('A part unit cost cannot be negative.');
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
