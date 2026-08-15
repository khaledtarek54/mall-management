<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A maintainable asset — the AC unit, escalator or pump itself (module 26, FR-PPM-03/04/05).
 *
 * The grain Atriom was missing: `Asset` is the mall, `Unit` is a storefront, `FixedAsset` is
 * a depreciation record. This is the machine. Every one carries a `code` unique within its
 * property, and `parent_id` gives components their sub-codes (ESC-01 → ESC-01-MOT).
 *
 * `fixed_asset_id` optionally ties a machine to its accounting twin, so finance and
 * maintenance keep separate registers (and separate RBAC) without double data entry.
 *
 * Tree pattern follows `LedgerAccount` (parent/children + a saving guard), but the parent is
 * chosen explicitly rather than derived from the code string: equipment codes are free-form
 * operator conventions with no reliable delimiter, unlike a numeric chart of accounts.
 */
#[DeletionAllowed(reason: 'configuration: an asset register entry with no ledger of its own')]
class Equipment extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    /** "equipment" is uncountable — Laravel would infer this, but the reader shouldn't have to know that. */
    protected $table = 'equipment';

    protected $fillable = [
        'asset_id',
        'parent_id',
        'code',
        'name_en',
        'name_ar',
        'category',
        'criticality',
        'unit_id',
        'location',
        'fixed_asset_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * How much it matters when this machine stops. Three values, not five: a scale nobody can apply
     * consistently is a field that gets left on its default.
     */
    public const CRITICAL = 'critical';       // trading stops, or someone is unsafe

    public const IMPORTANT = 'important';     // a service degrades

    public const ROUTINE = 'routine';         // everything else

    public const CRITICALITIES = [self::CRITICAL, self::IMPORTANT, self::ROUTINE];

    /**
     * The work-order priority a fault on this machine STARTS at.
     *
     * **A default, never an override.** If the person raising the job says `low`, they get `low` —
     * they can see the machine and the system cannot. What criticality buys is that nobody has to
     * remember which chiller matters at 2am; what it must not buy is the system quietly disagreeing
     * with an operator who was explicit, which is how people learn to distrust a field.
     */
    public function defaultWorkOrderPriority(): string
    {
        return match ($this->criticality) {
            self::CRITICAL => 'urgent',
            self::IMPORTANT => 'high',
            default => 'medium',
        };
    }

    /** NOT-NULL with no form field on some paths — never let a blank toggle send null. */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Asset code, both names, and where the machine physically stands.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->code,
            $this->name_en,
            $this->name_ar,
            $this->category,
            $this->location,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['asset_id', 'parent_id', 'code', 'name_en', 'name_ar', 'category', 'unit_id', 'location', 'fixed_asset_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('equipment');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Spare parts that fit this machine (FR-PPM-05). */
    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'equipment_inventory_item')->withTimestamps();
    }

    /** Preventive schedules servicing this machine (FR-PPM-01/03). */
    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(ServicePlan::class);
    }

    /** Jobs raised against this machine — its maintenance history. */
    public function workOrders(): HasMany
    {
        return $this->hasMany(FacilityWorkOrder::class);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Display label: "ESC-01 — Main escalator". */
    public function label(): string
    {
        return $this->code.' — '.($this->name_en ?: $this->name_ar);
    }

    /**
     * Every ancestor, nearest first. Walks with a visited-set so a pre-existing cycle in the
     * data (or one introduced outside the model) terminates instead of hanging the request.
     *
     * @return array<int,int> ancestor ids
     */
    public function ancestorIds(): array
    {
        $ids = [];
        $seen = [];
        $parentId = $this->parent_id;

        while ($parentId !== null && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $ids[] = (int) $parentId;
            $parentId = static::withTrashed()->whereKey($parentId)->value('parent_id');
        }

        return $ids;
    }

    /**
     * Ids that must never be offered as this record's parent: itself and everything beneath
     * it. Used to scope the parent picker and by the saving guard.
     *
     * @return array<int,int>
     */
    public function selfAndDescendantIds(): array
    {
        if (! $this->exists) {
            return [];
        }

        $ids = [(int) $this->getKey()];
        $frontier = [(int) $this->getKey()];

        // Iterative, not recursive: one query per depth level rather than per node.
        while ($frontier !== []) {
            $next = static::withTrashed()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $next = array_values(array_diff(array_map('intval', $next), $ids)); // cycle-safe
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }

    protected static function booted(): void
    {
        static::saving(function (self $equipment) {
            // NOT-NULL with a default: a blank or unknown value falls back to the safe end of the
            // scale rather than the alarming one. Guessing `critical` would page someone at 2am for a
            // hand dryer, and that is how an alert channel stops being read.
            if (! in_array($equipment->criticality, self::CRITICALITIES, true)) {
                $equipment->criticality = self::ROUTINE;
            }

            // Moving a machine between properties must take its whole tree or nothing.
            // The parent-side rule below only fires on the CHILD's save, so without this a
            // parent could walk to another property and leave its components behind —
            // Mall A's motor hanging off Mall B's escalator, and surfacing in the wrong
            // property's register (the table renders `parent.code`).
            //
            // Blocked rather than cascaded: Filament wraps neither create nor save in a
            // transaction, so a cascade that hit the unique(asset_id, code) index partway
            // would commit the parent's move and strand the children — the very split this
            // guards against. Detaching the sub-codes first is explicit and cannot corrupt.
            if ($equipment->exists && $equipment->isDirty('asset_id')) {
                if ($equipment->children()->withTrashed()->exists()) {
                    throw new InvalidArgumentException(
                        "Equipment #{$equipment->getKey()} has sub-codes; move or detach them before moving it to another property."
                    );
                }

                // Same principle, one level out: a machine may not walk away from the plans
                // and jobs that reference it. A plan's equipment must live in the plan's own
                // property, so a move would leave the plan permanently invalid — and the
                // work-order history would claim a machine that was never in that mall.
                if ($equipment->maintenancePlans()->withTrashed()->exists() || $equipment->workOrders()->withTrashed()->exists()) {
                    throw new InvalidArgumentException(
                        "Equipment #{$equipment->getKey()} is referenced by maintenance plans or work orders; it cannot be moved to another property."
                    );
                }
            }

            if ($equipment->parent_id === null) {
                return;
            }

            if ($equipment->exists && (int) $equipment->parent_id === (int) $equipment->getKey()) {
                throw new InvalidArgumentException('Equipment cannot be its own parent.');
            }

            /** @var self|null $parent */
            $parent = static::withTrashed()->find($equipment->parent_id);

            if (! $parent) {
                throw new InvalidArgumentException("Parent equipment #{$equipment->parent_id} does not exist.");
            }

            // Property isolation: a cross-property parent would let Mall A's escalator own
            // Mall B's motor, and the child would then be reachable from the wrong property's
            // tree. The DB can't express this, so the model is the enforcement point.
            if ((int) $parent->asset_id !== (int) $equipment->asset_id) {
                throw new InvalidArgumentException(
                    "Parent equipment #{$parent->id} belongs to another property; a sub-code must sit under a parent in the same property."
                );
            }

            // Acyclicity: re-parenting a node under its own descendant would orphan the whole
            // branch from every root and hang any tree walk that lacks a visited-set.
            if ($equipment->exists && in_array((int) $equipment->parent_id, $equipment->selfAndDescendantIds(), true)) {
                throw new InvalidArgumentException('Equipment cannot be parented under itself or one of its own sub-codes.');
            }
        });
    }
}
