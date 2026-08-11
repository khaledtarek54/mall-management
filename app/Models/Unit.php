<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use RefusesDeletionWhenReferenced, HasFactory, HasSearchText, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'area_id',
        'code',
        'floor_id',
        'category',
        'area_sqm',
        'status',
        'description',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
    ];

    /**
     * Unit code is the whole identity of a unit — `A-102`, `G-15`. Floor rides along so
     * "ground A-1" narrows the way an operator says it.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        // The floor CODE, via the register. This blob is a pure function of the row's own
        // attributes everywhere else in the app; `floor_id` is one, and reading the code through it
        // is the one relation hop the search policy allows for a lookup that cannot be renamed
        // without its own rebuild. `atriom:rebuild-search` after renaming a floor.
        return [
            $this->code,
            // `$this->floor()->value('code')`, not `$this->floor?->code`: the latter reads the
            // ATTRIBUTE when one is present and the relation only when it is not, which is exactly
            // the ambiguity that made dropping the old column visible. This always means the
            // relation.
            $this->floor()->value('code'),
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Which floor this unit stands on — selected from the property's register, not typed.
     *
     *
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** The facility zone this unit sits in (module 30) — nullable; a unit may have no zone. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * Every lease that includes this unit (master OR additional) via the
     * lease_unit pivot. This is the occupancy-relevant relationship for
     * multi-unit leases — leases() only finds leases where this is the master.
     */
    public function allLeases(): BelongsToMany
    {
        return $this->belongsToMany(Lease::class, 'lease_unit')->withPivot('is_master');
    }

    /**
     * Is this unit covered by an ACTIVE lease — as master OR as an additional unit? The
     * double-booking guard must consult the lease_unit pivot (the source of truth for which units a
     * lease covers), NOT the denormalized leases.unit_id master pointer: a unit held only as an
     * additional unit in a multi-unit lease has a pivot row but is not any lease's unit_id, so a
     * unit_id-only check would let a second lease re-book it. Pass the lease being validated to
     * exclude itself on edit.
     */
    public function isActivelyLeased(?int $excludeLeaseId = null): bool
    {
        // NOT-YET-RELEASED, not currently-held: a future-dated expansion has already spoken for
        // the unit even though nobody occupies it yet, and letting a second lease take it in the
        // gap is exactly the double-booking this guard exists to stop.
        return Lease::constrainToNotYetReleased(
            $this->allLeases()->where('leases.status', 'active')
        )
            ->when($excludeLeaseId, fn ($q, $id) => $q->where('leases.id', '!=', $id))
            ->exists();
    }

    /**
     * Options that tie this unit up until they are resolved (story OP-03).
     *
     * An expansion right, ROFR, ROFO or purchase option on ANOTHER tenant's lease means this space
     * is spoken for even while it reads as vacant. `LeaseOption::encumbersUnit()` has known this
     * since options shipped and nothing consulted it, so the lease-creation picker offered
     * encumbered units as freely as any other — which is how the same space gets promised twice.
     *
     * Only OPEN options: an exercised, waived or lapsed one encumbers nothing, and treating it as
     * if it did would block space the mall is free to let.
     *
     * @return HasMany<LeaseOption, $this>
     */
    public function encumbrances(): HasMany
    {
        return $this->hasMany(LeaseOption::class)
            ->where('status', 'open')
            ->whereIn('type', LeaseOption::ENCUMBERING_TYPES);
    }

    /** Is this unit spoken for by an option on someone else's lease? */
    public function isEncumbered(?int $exceptLeaseId = null): bool
    {
        return $this->encumbrances
            ->when($exceptLeaseId !== null, fn ($c) => $c->where('lease_id', '!=', $exceptLeaseId))
            ->isNotEmpty();
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    /**
     * Every unit gets its opening measurement the moment it exists.
     *
     * Without this a unit created AFTER the migration has no register at all, `areaOn()` falls
     * through to the `area_sqm` column, and a remeasurement — which updates that column — appears
     * to have been true for all time. That is the exact bug the register exists to prevent, and it
     * would have shipped for every new unit while looking correct for every migrated one.
     *
     * `effective_from` null means "since always", matching the backfilled rows.
     */
    protected static function booted(): void
    {
        // A measured area is never negative. The form carries minValue(0) and
        // RemeasureUnitService refuses <= 0, but nothing stood behind either for an import or the
        // console — and a negative area apportions CAM by a negative share, which is a credit to
        // one tenant paid for by every other tenant in the pool.
        static::saving(function (self $unit) {
            if ($unit->area_sqm !== null && (float) $unit->area_sqm < 0) {
                throw new \DomainException(__('admin.errors.unit_area_not_positive'));
            }
        });

        // ── A unit's floor and zone belong to the unit's own property ──────────────────────────
        //
        // Both are guarded on the Filament pages (`UnitResource::assertFloorInScope` /
        // `assertAreaInScope`), and Filament's own relationship-Select validation refuses an
        // out-of-scope pick from the form. Neither of those reaches a raw write: measured, a plain
        // `Unit::create([... 'asset_id' => A, 'floor_id' => <a floor of B>])` went straight
        // through (validation sweep, spacing, 2026-08-11). Any import, console command, factory or
        // future screen is that path.
        //
        // A unit on another mall's floor puts the shop in the wrong building on the stacking plan
        // and merges two properties into one row wherever floors group. A unit tagged with another
        // mall's ZONE is worse — area routing fans tenant requests out to that zone's supervisors,
        // so mall-A request data reaches mall-B staff (module 30 → 11).
        //
        // Checked only when the link or the property moves, so an ordinary save costs nothing.
        static::saving(function (self $unit) {
            if (! $unit->isDirty(['floor_id', 'area_id', 'asset_id'])) {
                return;
            }

            if ($unit->floor_id !== null
                && ! Floor::whereKey($unit->floor_id)->where('asset_id', $unit->asset_id)->exists()) {
                throw new \DomainException(__('admin.errors.unit_floor_other_property'));
            }

            if ($unit->area_id !== null
                && ! Area::whereKey($unit->area_id)->where('asset_id', $unit->asset_id)->exists()) {
                throw new \DomainException(__('admin.errors.unit_area_other_property'));
            }
        });

        // ── `area_sqm` is DERIVED from the dated rows, and may only move to what they say ───────
        //
        // Area versioning exists so that remeasuring a shop stops rewriting what was already
        // billed: `areaOn()` answers from the row in force on a date, and `RemeasureUnitService`
        // closes one row and opens the next under a lock. Its docblock states that it is the only
        // thing that may move this column — and nothing enforced it. `UnitForm` kept a plain
        // editable `area_sqm` on Edit, and the `created` hook below does not fire on update, so an
        // ordinary edit moved the column and wrote no dated row at all.
        //
        // That splits the truth along the worst line: CAM apportions on `areaOn()` and keeps the
        // OLD area, while the unit register, the lease's area, the /api/v1 payload and the reports
        // all read this column and show the NEW one. The operator sees the change take effect
        // everywhere they look while the money quietly ignores it.
        //
        // No re-entrancy flag is needed: the service writes the dated row BEFORE it touches the
        // column, so by the time it saves, the rows already agree. Asking whether they agree is
        // both the guard and the definition.
        //
        // A unit with NO dated rows is left alone — `areaOn()` falls back to this column, so there
        // is no second truth to protect, and that is what keeps pre-versioning rows (and factories
        // that write only the column) behaving exactly as they did.
        static::updating(function (self $unit) {
            if (! $unit->isDirty('area_sqm')) {
                return;
            }

            // Fresh read: a stale loaded relation would compare against rows from before the
            // service just wrote one.
            $unit->unsetRelation('areas');

            if ($unit->areas()->count() === 0) {
                return;
            }

            if (round((float) $unit->area_sqm, 2) !== round($unit->areaOn(), 2)) {
                throw new \DomainException(__('admin.errors.unit_area_use_remeasure'));
            }
        });

        static::created(function (self $unit) {
            $unit->areas()->create([
                'area_sqm' => (float) ($unit->area_sqm ?? 0),
                'effective_from' => null,
                'effective_to' => null,
                'reason' => 'Opening measurement',
            ]);
        });
    }

    /**
     * The measured area over time — one open-ended row per unit until it is remeasured.
     *
     * @return HasMany<UnitArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(UnitArea::class)->orderByRaw('effective_from IS NULL DESC, effective_from');
    }

    /**
     * The area in force on a date — what a period's CAM must apportion on.
     *
     * Remeasuring a shop used to rewrite history: last year's reconciliation, recomputed today,
     * apportioned on the new number. Now the row in force on that day answers, and only a date
     * AFTER a remeasurement sees the new figure.
     *
     * Falls back to `area_sqm` when no row covers the date. That is not defensive padding — it is
     * what keeps a unit created before this table (or by a factory that writes only the column)
     * behaving exactly as it did.
     */
    public function areaOn(?\Carbon\CarbonImmutable $on = null): float
    {
        $on = ($on ?? \Carbon\CarbonImmutable::now())->startOfDay();
        $date = $on->toDateString();

        $row = $this->relationLoaded('areas')
            ? $this->areas->first(fn (UnitArea $a) => $a->coversDate($on))
            : $this->areas()
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->orderByRaw('effective_from IS NULL, effective_from DESC')
                ->first();

        return $row ? (float) $row->area_sqm : (float) ($this->area_sqm ?? 0);
    }

    /**
     * Σ(area in force × days) across a window — the m²·days this unit contributed to a period.
     *
     * The day-weighted counterpart of {@see areaOn()}. `areaOn()` answers "what did it measure on
     * this date", which is the right question for a point in time and the wrong one for a YEAR: a
     * pool covering 2025 must apportion on what each shop measured through 2025, and a wall that
     * moved in July splits the year between two measurements.
     *
     * Callers divide by the window's day count to get an average area. Kept as m²·days rather than
     * an average so a caller weighting by a HELD sub-window (a lease that took the unit in
     * November) can compose the two weightings without rounding twice.
     *
     * Days no dated row covers — the gap before the first row on a unit created before the register
     * existed — fall back to the denormalised column, which is exactly what `areaOn()` does for the
     * same case, so a property with no remeasurement history answers precisely as it did before.
     */
    public function areaSqmDaysBetween(\Carbon\CarbonImmutable $from, \Carbon\CarbonImmutable $to): float
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lessThan($from)) {
            return 0.0;
        }

        $windowDays = $from->diffInDays($to) + 1;
        $fallback = (float) ($this->area_sqm ?? 0);

        $rows = $this->relationLoaded('areas') ? $this->areas : $this->areas()->get();

        if ($rows->isEmpty()) {
            return $fallback * $windowDays;
        }

        $total = 0.0;
        $covered = 0;

        foreach ($rows as $row) {
            /** @var UnitArea $row */
            $rowFrom = $row->effective_from
                ? \Carbon\CarbonImmutable::parse($row->effective_from)->startOfDay()
                : $from;
            $rowTo = $row->effective_to
                ? \Carbon\CarbonImmutable::parse($row->effective_to)->startOfDay()
                : $to;

            $start = $rowFrom->greaterThan($from) ? $rowFrom : $from;
            $end = $rowTo->lessThan($to) ? $rowTo : $to;

            if ($end->lessThan($start)) {
                continue; // this measurement was not in force at any point in the window
            }

            $days = $start->diffInDays($end) + 1;
            $total += (float) $row->area_sqm * $days;
            $covered += $days;
        }

        if ($covered < $windowDays) {
            $total += $fallback * ($windowDays - $covered);
        }

        return $total;
    }

    public function utilityMeters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class)
                    ->where('status', 'active')
                    ->latest('commencement_date');
    }

    public function currentTenant(): ?Tenant
    {
        return $this->activeLease?->tenant;
    }

    /**
     * Project this unit's status from the leases that include it (via the
     * lease_unit pivot, so multi-unit leases count). 'maintenance' is a manual
     * override and never auto-overwritten. Idempotent.
     */
    public function recomputeStatus(): void
    {
        if ($this->status === 'maintenance') {
            return;
        }

        // Two questions, two predicates (LE-02). CURRENTLY HELD decides occupancy: a unit released
        // by a contraction is vacant even though the lease that held it is still active on its
        // remaining units. NOT YET RELEASED decides whether it is spoken for: space an expansion
        // takes in November is not occupied in September, but it is not free either — it is
        // RESERVED, which is what a leasing manager needs to see before marketing it.
        $statuses = Lease::constrainToCurrentlyHeld($this->allLeases())->pluck('leases.status');
        $committed = Lease::constrainToNotYetReleased($this->allLeases())->pluck('leases.status');

        $target = match (true) {
            $statuses->contains('active') => 'occupied',
            $statuses->intersect(['draft', 'pending_approval', 'renewed'])->isNotEmpty() => 'reserved',
            $committed->intersect(['active', 'draft', 'pending_approval', 'renewed'])->isNotEmpty() => 'reserved',
            default => 'vacant',
        };

        if ($this->status !== $target) {
            $this->update(['status' => $target]);
        }
    }

    // Get display name like "Haya Walk · A-01"
    public function fullName(): string
    {
        return "{$this->asset->name} · {$this->code}";
    }
}
