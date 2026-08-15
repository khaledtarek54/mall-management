<?php

namespace App\Models\Concerns\Lease;

use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * **The space a lease holds: the `lease_unit` pivot, dated area, and rate-derived rent.**
 *
 * The largest genuinely-cohesive block in `Lease`, and the only one with private helpers shared
 * across its members — which is why it moves as ONE trait rather than being split further:
 *
 *   unitsOn()              -> pivotCovers() -> pivotWindow()
 *   totalAreaSqm()         -> totalAreaSqmOn() -> unitsOn()
 *   totalAreaSqmForPeriod()-> pivotWindow() + totalAreaSqmOn()
 *   deriveBaseRentFromRate() -> totalAreaSqmOn()
 *
 * `deriveBaseRentFromRate()` lives here deliberately rather than in a rent-pricing concern: its
 * whole body is area x rate, and separating it from `totalAreaSqmOn()` would put a cross-trait hop
 * in the CAM and reporting hot path to buy nothing.
 *
 * `constrainToCurrentlyHeld()` / `constrainToNotYetReleased()` are static and `$this`-free; their
 * only callers are in `Unit`.
 *
 * **What stays on the model:** the `unit_id` column and its `$fillable`/`$casts`, the
 * `#[PropertyOwned(via: 'unit')]` attribute (a class attribute cannot live in a trait), and
 * `assetId()`, which also reads `unit()` but belongs to the billing-agreement concern.
 */
trait HasLeasePremises
{
    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * All units this lease covers (master + additional) via lease_unit.
     * leases.unit_id stays the MASTER unit (see masterUnit()).
     *
     * @return BelongsToMany<Unit, $this>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'lease_unit')
            // The premises are date-ranged, like the rent (LE-02). NULL on either side means
            // unbounded, which is what every pre-existing pivot row means.
            ->withPivot('is_master', 'effective_from', 'effective_to')
            ->withTimestamps();
    }

    /**
     * The units this lease held on a given day.
     *
     * @return Collection<int, Unit>
     */
    public function unitsOn(CarbonImmutable $on): Collection
    {
        $this->loadMissing('units');

        /** @var Collection<int, Unit> $units */
        $units = collect($this->units->all());

        return $units->filter(fn (Unit $unit): bool => self::pivotCovers($unit, $on))->values();
    }

    /**
     * Does this unit's `lease_unit` window cover the given day? NULL on either side is unbounded,
     * which is what every pivot row written before LE-02 means.
     *
     * The pivot is read through `getAttribute()` rather than `->pivot` so the access is one static
     * analysis can see; it is a dynamic relation attribute either way.
     */
    private static function pivotCovers(Unit $unit, CarbonImmutable $on): bool
    {
        [$from, $to] = self::pivotWindow($unit);

        return ! ($from && $from->greaterThan($on)) && ! ($to && $to->lessThan($on));
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private static function pivotWindow(Unit $unit): array
    {
        $pivot = $unit->getAttribute('pivot');

        $from = $pivot?->getAttribute('effective_from');
        $to = $pivot?->getAttribute('effective_to');

        return [
            $from ? CarbonImmutable::parse($from) : null,
            $to ? CarbonImmutable::parse($to) : null,
        ];
    }

    /**
     * Constrain a `lease_unit` query to the pivot rows in force TODAY (LE-02).
     *
     * Lives on Lease rather than Unit because `Unit::allLeases()` is a BelongsToMany whose builder
     * resolves against THIS model — a scope on Unit is unreachable from it.
     *
     * The premises are date-ranged now, so a unit handed back in a contraction has a pivot row that
     * has closed. Occupancy and the double-booking guard must read only the rows still in force, or
     * released space stays permanently unlettable and the mall cannot re-let its own unit.
     * `Unit::allLeases()` itself stays UNFILTERED on purpose: DeletionPolicy uses it to mean "was
     * this unit ever leased", and a unit with history must stay undeletable forever.
     *
     * Every pivot row that exists today has NULL on both sides, so this matches all of them.
     *
     * @param  Builder|BelongsToMany  $query
     */
    public static function constrainToCurrentlyHeld($query)
    {
        $today = now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('lease_unit.effective_from')->orWhere('lease_unit.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('lease_unit.effective_to')->orWhere('lease_unit.effective_to', '>=', $today));
    }

    /**
     * Constrain a `lease_unit` query to rows that have not been RELEASED — in force today **or
     * starting in the future**. This is the double-booking question, and it is deliberately a
     * different predicate from {@see constrainToCurrentlyHeld()}.
     *
     * An expansion agreed in September to take effect in November leaves the unit unoccupied until
     * November — so occupancy says vacant, correctly. But the space is **spoken for**, and asking
     * the occupancy question at the booking guard would let a second lease take it in October and
     * collide with the expansion on 1 November. Only a row whose `effective_to` has PASSED — space
     * genuinely handed back — frees a unit.
     *
     * @param  Builder|BelongsToMany  $query
     */
    public static function constrainToNotYetReleased($query)
    {
        return $query->where(
            fn ($q) => $q->whereNull('lease_unit.effective_to')
                ->orWhere('lease_unit.effective_to', '>=', now()->toDateString())
        );
    }

    /** The master unit — the lease's primary unit (= leases.unit_id). */
    public function masterUnit(): BelongsTo
    {
        return $this->unit();
    }

    /**
     * The lease's TOTAL leased area — every unit on the lease, not just the master.
     *
     * Anything that apportions a cost by floor area (CAM, and any future recovery basis) must use
     * this, never `$lease->unit->area_sqm`. Reading the master alone understates a multi-unit
     * lease by its whole non-master footprint, and because the CAM denominator is built the same
     * way the shares still sum to 100% — so Σ(allocated) = total_actual_expense stays green while
     * the DISTRIBUTION between tenants is wrong. A tie-out assertion cannot see that; assert the
     * SHARE. (Found by the Yardi benchmark, docs/benchmarks/yardi/04-scenarios.md S5.)
     *
     * Falls back to the master unit when the pivot is empty — pre-observer rows, and any lease
     * built in a test without going through LeaseObserver::ensureMasterPivot().
     */
    public function totalAreaSqm(): float
    {
        return $this->totalAreaSqmOn(CarbonImmutable::now());
    }

    /** Rent typed as a flat monthly figure — the default, and every lease written before LS-04. */
    public const RENT_FLAT = 'flat';

    /** Rent negotiated as a rate per m² per year; the monthly figure is DERIVED from the area. */
    public const RENT_RATE = 'rate';

    /**
     * The monthly base rent implied by a per-m² rate (story LS-04).
     *
     * `rate × area ÷ 12`. Null unless the lease is actually priced that way, so a caller cannot
     * accidentally re-price a flat lease from an area that was never part of its deal.
     */
    public function deriveBaseRentFromRate(?CarbonImmutable $on = null): ?float
    {
        if ($this->rent_pricing_basis !== self::RENT_RATE || (float) $this->base_rent_rate_per_sqm_year <= 0) {
            return null;
        }

        $area = $this->totalAreaSqmOn($on ?? CarbonImmutable::now());

        if ($area <= 0) {
            return null;
        }

        return round((float) $this->base_rent_rate_per_sqm_year * $area / 12, 2);
    }

    /**
     * The lease's total leased area as it stood on a given day (LE-02).
     *
     * Two things vary with the date and both are honoured here: WHICH units the lease held (the
     * `lease_unit` pivot), and how big each one MEASURED (`unit_areas`). Only the first used to be
     * dated, so remeasuring a shop silently rewrote every past period this figure feeds — CAM
     * apportionment above all.
     */
    public function totalAreaSqmOn(CarbonImmutable $on): float
    {
        $fromPivot = (float) $this->unitsOn($on)->sum(fn (Unit $unit) => $unit->areaOn($on));

        return $fromPivot > 0 ? $fromPivot : (float) ($this->unit?->areaOn($on) ?? 0);
    }

    /**
     * The lease's **time-weighted** area across a period — the basis a recovery reconciliation
     * must apportion on.
     *
     * A tenant who took an extra 300 m² on 1 November has not occupied it for the year, and should
     * not carry a whole year of that space's CAM. Yardi re-bases the share from the amendment date;
     * over an annual pool that is the same thing as weighting each unit by the days it was held.
     *
     * For every lease whose pivot rows are unbounded — which is all of them until an expansion or
     * contraction is recorded — this returns exactly `totalAreaSqm()`, so nothing that exists today
     * changes basis.
     */
    public function totalAreaSqmForPeriod(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $this->loadMissing('units');

        $days = $start->diffInDays($end) + 1;

        if ($days <= 0) {
            return $this->totalAreaSqmOn($start);
        }

        $weighted = 0.0;

        foreach ($this->units as $unit) {
            /** @var Unit $unit */
            [$from, $to] = self::pivotWindow($unit);

            $heldFrom = $from && $from->greaterThan($start) ? $from : $start;
            $heldTo = $to && $to->lessThan($end) ? $to : $end;

            if ($heldTo->lessThan($heldFrom)) {
                continue;   // not held at any point in this period
            }

            // The area IN FORCE across the held window, not the unit's current measurement.
            // `area_sqm` is the denormalised TODAY figure, so reading it here apportioned a past
            // year on a wall that moved after that year ended — and because numerator and
            // denominator moved together the tie-out stayed green while the distribution between
            // tenants was wrong. m²·days composes the two weightings (how long the unit was HELD,
            // and what it MEASURED) without rounding in between.
            $weighted += $unit->areaSqmDaysBetween($heldFrom, $heldTo) / $days;
        }

        if ($weighted > 0) {
            return round($weighted, 4);
        }

        // Empty pivot (pre-observer rows, and any lease built without ensureMasterPivot). Fall back
        // to the master unit — but dated the SAME way as the loop above, or this method would answer
        // a past period with today's measurement for exactly the legacy rows least likely to have
        // been re-measured deliberately.
        return $this->unit
            ? round($this->unit->areaSqmDaysBetween($start, $end) / $days, 4)
            : 0.0;
    }

    /**
     * Set the full unit set for this lease and designate one master, keeping
     * leases.unit_id (= master) in sync and recomputing occupancy for every
     * affected unit. The master defaults to the first id when not supplied.
     *
     * @param  array<int>  $unitIds
     */
    public function syncUnits(array $unitIds, ?int $masterUnitId = null): void
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            return;
        }

        $master = in_array((int) $masterUnitId, $unitIds, true) ? (int) $masterUnitId : $unitIds[0];
        $previous = $this->units()->pluck('units.id')->all();

        $pivot = [];
        foreach ($unitIds as $id) {
            $pivot[$id] = ['is_master' => $id === $master];
        }
        $this->units()->sync($pivot);

        if ((int) $this->unit_id !== $master) {
            $this->forceFill(['unit_id' => $master])->saveQuietly();
        }

        Unit::whereIn('id', array_unique([...$previous, ...$unitIds]))
            ->get()
            ->each
            ->recomputeStatus();
    }
}
