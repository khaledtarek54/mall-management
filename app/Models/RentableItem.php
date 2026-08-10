<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

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

    /** @return BelongsToMany<Lease, $this> */
    public function leases(): BelongsToMany
    {
        return $this->belongsToMany(Lease::class, 'lease_rentable_item')
            ->withPivot(['effective_from', 'effective_to', 'monthly_rate'])
            ->withTimestamps();
    }

    /**
     * Is this item held by a lease on the given day?
     *
     * Date-ranged like the premises (`lease_unit`): an item released at the end of March is still
     * held on 31 March, and free on 1 April. A null `effective_to` means open-ended.
     */
    public function isHeldOn(?CarbonImmutable $on = null, ?int $ignoreLeaseId = null): bool
    {
        $on = $on ?? CarbonImmutable::now();

        return $this->leases()
            ->when($ignoreLeaseId, fn ($q, $id) => $q->where('leases.id', '!=', $id))
            ->whereIn('leases.status', ['active', 'pending_approval'])
            ->where(fn ($q) => $q->whereNull('lease_rentable_item.effective_from')
                ->orWhereDate('lease_rentable_item.effective_from', '<=', $on->toDateString()))
            ->where(fn ($q) => $q->whereNull('lease_rentable_item.effective_to')
                ->orWhereDate('lease_rentable_item.effective_to', '>=', $on->toDateString()))
            ->exists();
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
}
