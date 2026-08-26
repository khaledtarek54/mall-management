<?php

namespace App\Models;

use App\Enums\UnitOwnershipStatus;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PropertyOwned;
use App\Support\ProjectedState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Something let alongside a lease that is NOT lettable floor area — parking, storage, signage.
 *
 * **The one invariant.** A rentable item never contributes to gross leasable area. Not to
 * `Asset::totalUnitAreaSqm()`, not to occupancy, not to the CAM denominator, not to the rent roll's
 * EGP/m²/yr. That is why this is its own table rather than a `Unit` with a category: the mistake
 * becomes structurally impossible instead of merely discouraged, and the model carries no area
 * column for a future report to find and sum. See
 * [docs/benchmarks/yardi/09-yardi-space-and-parking.md](../../docs/benchmarks/yardi/09-yardi-space-and-parking.md).
 *
 * **Generic, following Voyager.** Yardi's register is "rentable items" — garages, carports, parking
 * spaces, storage — not a parking module. A mall lets storage rooms and signage on the same terms,
 * so `type` carries the difference and one register serves all of them.
 *
 * **Money flows through the ordinary path.** An assignment does not bill anything by itself; the
 * lease gets a `parking` charge row on its schedule, which the monthly run, VAT and the GL already
 * understand. Nothing here is a second billing engine.
 */
#[DeletableWhenUnused(blockedBy: ['leases'], instead: 'set the item out of service — an item that has been let is part of the property record')]
// a parking bay / store / signage face stands in one mall; code unique per property
#[PropertyOwned]
class RentableItem extends Model
{
    use HasFactory, HasSearchText, LogsActivity, RefusesDeletionWhenReferenced, SoftDeletes;

    /** A car-parking bay — the common case, and the default. */
    public const TYPE_PARKING = 'parking';

    /** A storage room or cage let to a tenant for stock. */
    public const TYPE_STORAGE = 'storage';

    /** A signage position, totem or billboard face. */
    public const TYPE_SIGNAGE = 'signage';

    /** A kiosk pitch or cart position in the mall common area. */
    public const TYPE_KIOSK = 'kiosk';

    public const TYPES = [self::TYPE_PARKING, self::TYPE_STORAGE, self::TYPE_SIGNAGE, self::TYPE_KIOSK];

    /** Free to let. */
    public const STATUS_AVAILABLE = 'available';

    /** Held by a lease today. */
    public const STATUS_ASSIGNED = 'assigned';

    /** Withdrawn — resurfacing, a broken shutter, a signage face removed. */
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';

    public const STATUSES = [self::STATUS_AVAILABLE, self::STATUS_ASSIGNED, self::STATUS_OUT_OF_SERVICE];

    protected $fillable = [
        'asset_id',
        'area_id',
        'floor_id',
        'code',
        'type',
        'name',
        'status',
        'monthly_rate',
        'notes',
    ];

    protected $casts = [
        'monthly_rate' => 'decimal:2',
    ];

    /** Mirrors the DB defaults so a freshly built model reads the same as a reloaded one. */
    protected $attributes = [
        'type' => self::TYPE_PARKING,
        'status' => self::STATUS_AVAILABLE,
        'monthly_rate' => 0,
    ];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** Which floor it stands on — the same register the units use, so the two cannot disagree. */
    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * The leases holding this item.
     *
     * `morphedByMany` since 2026-08-19 — see {@see holdingsOn} for why the holder is an agreement
     * rather than a lease.
     *
     * @return MorphToMany<Lease, $this>
     */
    public function leases(): MorphToMany
    {
        return $this->morphedByMany(Lease::class, 'holder', 'rentable_item_holdings')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * The unit ownerships holding this item — an owner-occupier who bought his shop and rents a bay
     * with it.
     *
     * @return MorphToMany<UnitOwnership, $this>
     */
    public function ownerships(): MorphToMany
    {
        return $this->morphedByMany(UnitOwnership::class, 'holder', 'rentable_item_holdings')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * Is this item held by ANY live agreement on the given day?
     *
     * **This is the double-let guard, and it must see every kind of holder.** Until 2026-08-19 it
     * asked only about leases, which was correct while a lease was the only agreement that could
     * hold a bay — and would have become a real double-let the moment an ownership could, because a
     * bay held by an owner would have looked free to the next lease.
     *
     * Date-ranged like the premises (`lease_unit`): an item released at the end of March is still
     * held on 31 March and free on 1 April. A null `effective_to` is open-ended.
     *
     * "Live" differs by holder, so it is asked per holder rather than flattened: a lease counts
     * while `active` or `pending_approval`, an ownership while it is not `transferred` — a sold-on
     * unit's former owner holds nothing.
     *
     * @param  array{type: string, id: int}|null  $ignore  a holding to disregard, so re-assigning
     *                                                     an item to its own current holder is not
     *                                                     refused as a clash with itself
     */
    public function isHeldOn(?CarbonImmutable $on = null, ?array $ignore = null): bool
    {
        $on = $on ?? CarbonImmutable::now();
        $date = $on->toDateString();

        $dated = fn ($q) => $q
            ->where(fn ($w) => $w->whereNull('rentable_item_holdings.effective_from')
                ->orWhereDate('rentable_item_holdings.effective_from', '<=', $date))
            ->where(fn ($w) => $w->whereNull('rentable_item_holdings.effective_to')
                ->orWhereDate('rentable_item_holdings.effective_to', '>=', $date));

        $heldByLease = $this->leases()
            ->when(
                $ignore && $ignore['type'] === 'lease',
                fn ($q) => $q->where('leases.id', '!=', $ignore['id']),
            )
            ->whereIn('leases.status', ['active', 'pending_approval'])
            ->where($dated)
            ->exists();

        if ($heldByLease) {
            return true;
        }

        return $this->ownerships()
            ->when(
                $ignore && $ignore['type'] === 'unit_ownership',
                fn ($q) => $q->where('unit_ownerships.id', '!=', $ignore['id']),
            )
            ->where('unit_ownerships.status', '!=', UnitOwnershipStatus::Transferred->value)
            ->where($dated)
            ->exists();
    }

    /**
     * Is this item spoken for — held OPEN-ENDEDLY by a live agreement?
     *
     * The twin of {@see isHeldOn()}, asking the other question. `isHeldOn()` is date-ranged and
     * answers *"is it occupied on this day"* — the double-let guard. This one answers *"is it off
     * the market"*, which is what `status` records, and the two genuinely differ: a bay released
     * effective 30 June is still HELD in April and already AVAILABLE for the operator to re-let
     * from July. `AssignRentableItemService::release()` has always worked that way and a regression
     * test pins it.
     *
     * So the predicate is the holding with no end date. A closed holding is a tenancy running out;
     * an open one is a bay nobody can offer.
     *
     * "Live" differs by holder for the same reasons `isHeldOn()` gives, so it is asked per holder
     * rather than flattened — and that is what makes this fix work at all: after `leases:expire`
     * moves a lease to `expired`, its holdings are still OPEN (nothing closes them), and only the
     * holder's own liveness says the bay is free.
     */
    public function isSpokenFor(): bool
    {
        $heldByLease = $this->leases()
            ->whereIn('leases.status', ['active', 'pending_approval'])
            ->wherePivotNull('effective_to')
            ->exists();

        if ($heldByLease) {
            return true;
        }

        return $this->ownerships()
            ->where('unit_ownerships.status', '!=', UnitOwnershipStatus::Transferred->value)
            ->wherePivotNull('effective_to')
            ->exists();
    }

    /**
     * Re-derive `status` from the holdings — the projection, and the ONE place it is decided.
     *
     * `status` is a stored column that is a function of TODAY, which is the shape
     * {@see ProjectedState} exists for: it goes wrong on a day when nothing happened.
     * A lease reaching its expiry date is not a write, so nothing fired here — measured, a bay whose
     * lease expired kept saying `assigned` for ever, and an operator filtering the register for
     * *Available* to find a free bay could not see it. The bay was re-lettable throughout
     * (`RentableItemOptions::lettable()` rejects on `isHeldOn()`, never on this column), so the
     * damage was to the register rather than to the letting — which is exactly why nobody hit it.
     *
     * `out_of_service` is never overwritten: it is a manual override, the same rule
     * `Unit::recomputeStatus()` applies to `maintenance`, and re-asserting it here would be a
     * second opinion on the same decision.
     */
    public function recomputeStatus(): void
    {
        if ($this->status === self::STATUS_OUT_OF_SERVICE) {
            return;
        }

        $target = $this->isSpokenFor() ? self::STATUS_ASSIGNED : self::STATUS_AVAILABLE;

        if ($this->status !== $target) {
            $this->update(['status' => $target]);
        }
    }

    /** What an operator reads on a screen: the code, plus the name where one was given. */
    public function label(): string
    {
        return $this->name ? "{$this->code} · {$this->name}" : $this->code;
    }

    /** @return array<int, string|null> */
    public function searchTextSources(): array
    {
        return [$this->code, $this->name, $this->type, $this->notes];
    }

    /**
     * Named log. Without `useLogName()` spatie files every entry under `default`, and the activity
     * log rendered the raw key `admin.activity.subjects.default` — the existing subject-label gate
     * could not see it, because it enumerates models that CALL useLogName and this one did not.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'rentable_item');
    }
}
