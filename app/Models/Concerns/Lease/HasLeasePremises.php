<?php

namespace App\Models\Concerns\Lease;

use App\Models\Unit;
use App\Services\LeaseSpaceChangeService;
// For the `@param Builder|BelongsToMany` docblocks — unimported, `Builder` would resolve to
// App\Models\Concerns\Lease\Builder, which does not exist.
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 *   repriceFromPremises()  -> deriveBaseRentFromRate()
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
     * The two ways a base rent is priced. Named as a set because `ValueSets` enforces the column and
     * the form offers it, and those two lists must be the same list.
     *
     * @var list<string>
     */
    public const RENT_PRICING_BASES = [self::RENT_FLAT, self::RENT_RATE];

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

        $contracted = round((float) $this->base_rent_rate_per_sqm_year * $area / 12, 2);

        // ── A lease in HOLDOVER is priced at its uplift, not at its contracted rate (EG-40) ────
        //
        // `base_rent_rate_per_sqm_year` stays CONTRACTUAL — that is what a rate means, and
        // `holdover_rate_pct` is the premium recorded on top of it, exactly as
        // `ConvertLeaseToHoldoverService` records it. Re-rating on conversion would bake a
        // temporary penalty into the contractual rate and lose what the parties actually agreed.
        //
        // But every derivation from that rate has to honour the premium, or it under-prices the
        // holdover. `LeaseSpaceChangeService` re-derives from the rate when a rate-priced lease
        // takes more space, so taking an extra unit mid-holdover silently dropped the rent back to
        // 100% of the contracted figure — an uplift the operator had negotiated, gone, with nothing
        // on screen to say so.
        //
        // Applied the same way the conversion applies it (premium on the contracted figure, each
        // step rounded) so the two cannot produce different numbers for the same lease, and only
        // from `holdover_from` — a date before the conversion is still contracted.
        $premium = (float) $this->holdover_rate_pct;

        if ($this->holdover_from === null || $premium <= 0) {
            return $contracted;
        }

        $asOf = $on ?? CarbonImmutable::now();

        return $asOf->startOfDay()->lt($this->holdover_from->startOfDay())
            ? $contracted
            : round($contracted * $premium / 100, 2);
    }

    /**
     * Re-price a rate-priced lease from the premises it actually holds — **at origination only**.
     *
     * `Lease::saving` derives `base_rent_monthly` on CREATE, and it reads
     * {@see deriveBaseRentFromRate()}, which reads the `lease_unit` pivot. On a create that pivot
     * does not exist yet: `LeaseObserver` writes the master row in `created`, and any ADDITIONAL
     * unit is attached later still — so the derivation fell through to its own master-unit
     * fallback and a lease opening on two shops was priced on one of them. Measured: A-03 (90 m²)
     * plus A-04 (120 m²) at 1,000/m²/yr saved 7,500 a month where 17,500 was due, and because the
     * charge ladder, the marketing levy and the deposit are all built FROM that column, every
     * figure the lease produced for its whole term inherited it.
     *
     * The fallback is not the bug and must stay — a lease built in a test or by an importer that
     * never touches the pivot has to price on something. What was missing is a caller re-asking
     * once the premises are known.
     *
     * **Origination only, and the guard is not decorative.** Re-deriving a lease that has BILLED
     * restates months already invoiced from a rent nobody agreed to for them; that act needs an
     * EFFECTIVE DATE, which is the whole reason {@see LeaseSpaceChangeService}
     * exists — it re-derives at a date, closes the old charge row and opens the new one. So this
     * refuses the moment an invoice exists rather than trusting its callers to only be creates.
     *
     * Silent on a flat lease: a negotiated sum is not a function of area, and inferring one from
     * the space it happens to cover would restate the deal.
     *
     * @return bool whether the rent actually moved — the caller has to seed the charges from the
     *              corrected figure, so it needs to know
     */
    public function repriceFromPremises(): bool
    {
        $derived = $this->deriveBaseRentFromRate();

        if ($derived === null || (float) $this->base_rent_monthly === $derived) {
            return false;
        }

        if ($this->invoices()->exists()) {
            return false;
        }

        $this->base_rent_monthly = $derived;

        // A plain save, not saveQuietly: `Lease::saving` recomputes `security_deposit` from the
        // rent and the agreed multiple, and a deposit left at three months of the OLD rent is the
        // same defect one column along. The rate-derivation hook does not fight this — it treats a
        // dirty `base_rent_monthly` on an update as a figure the caller stated.
        $this->save();

        return true;
    }

    /**
     * The rate a negotiated monthly rent implies — the inverse of {@see deriveBaseRentFromRate()}.
     *
     * Lives beside its twin so the two cannot drift: one is `rate × area ÷ 12` and this is
     * `rent × 12 ÷ area`, and a second copy of either in a service is how they come to disagree.
     *
     * Used when a renewal is struck at a NEGOTIATED figure (EG-39). A renewal is a re-negotiation,
     * so the deal wins and the rate follows it — the opposite of origination, where the rate the
     * deal was struck at outranks a typed number.
     *
     * Null when this lease is not rate-priced or has no area to divide by, which is the same pair
     * of refusals its twin makes.
     */
    public function deriveRateFromBaseRent(float $monthlyRent, ?CarbonImmutable $on = null): ?float
    {
        if ($this->rent_pricing_basis !== self::RENT_RATE || $monthlyRent <= 0) {
            return null;
        }

        $area = $this->totalAreaSqmOn($on ?? CarbonImmutable::now());

        if ($area <= 0) {
            return null;
        }

        return round($monthlyRent * 12 / $area, 2);
    }

    /**
     * The CONTRACTED rate implied by an EFFECTIVE rent — the premium-aware inverse (SW-049).
     *
     * `deriveRateFromBaseRent()` is deliberately NOT taught about the premium, and the reason is
     * that its two callers pass semantically different rents. `LeaseRenewalService` passes a rent
     * the parties NEGOTIATED for the new term (EG-39: the deal wins and the rate follows it), and
     * that figure is already contractual even when the lease it renews is in holdover — stripping
     * a premium there would divide a freely agreed number by 1.5 and understate every renewal rate
     * struck off a holdover, which is EG-39's own defect re-created one column along.
     *
     * `LeaseRentChangeService` passes the rent the lease is BILLING, which on a converted holdover
     * carries the uplift. Feeding that to the shared helper wrote the premium INTO the contractual
     * rate: measured, a 157,500 escalation on a 150% holdover of 250 m² produced 7,560/m²/yr where
     * 5,040 is the contracted figure — inflated by exactly ×1.5, and every later rate→rent
     * derivation then compounds it.
     *
     * Same inputs and the same rounding order as {@see deriveBaseRentFromRate()}, run backwards, so
     * a rent set and then re-read comes back to the rate it was derived from. Only from
     * `holdover_from` — a rent effective before the conversion is still contracted.
     */
    public function deriveContractedRateFromEffectiveRent(float $effectiveRent, ?CarbonImmutable $on = null): ?float
    {
        $premium = (float) $this->holdover_rate_pct;
        $asOf = $on ?? CarbonImmutable::now();

        $inHoldover = $this->holdover_from !== null
            && $premium > 0
            && ! $asOf->startOfDay()->lt($this->holdover_from->startOfDay());

        return $this->deriveRateFromBaseRent(
            $inHoldover ? round($effectiveRent * 100 / $premium, 2) : $effectiveRent,
            $asOf,
        );
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
     * contraction is recorded — this returns exactly `totalAreaSqm()` **for a lease that ran the
     * whole period**, so nothing that exists today changes basis.
     *
     * **The lease's OWN term narrows the window too (2026-08-17).** The weighting used to read the
     * `lease_unit` pivot alone — and that pivot is dated only when an expansion or contraction is
     * recorded, so it is null on an ordinary lease. A lease commencing 1 October therefore drew a
     * FULL year's recovery share: measured on a 500,000 pool, a 100 m² lease three months in took
     * 23.81% / 119,048 where the day-weighted answer is ~7.25% / ~36,232. It over-recovered from the
     * arriving tenant and under-charged the sitting ones, because the denominator counted the
     * newcomer's full area as well. Yardi computes recovery on the days occupied within the period;
     * the mechanism here was half-wired rather than absent.
     *
     * The END is clamped only once the lease has actually ENDED. An `active` lease past its expiry
     * date is in **holdover** — still trading, still consuming common area — and clamping it would
     * hand its months to nobody.
     */
    public function totalAreaSqmForPeriod(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $this->loadMissing('units');

        $days = $start->diffInDays($end) + 1;

        if ($days <= 0) {
            return $this->totalAreaSqmOn($start);
        }

        [$leaseFrom, $leaseTo] = $this->occupancyWindow();

        // Narrow the requested period to the part of it this lease actually occupied, once, before
        // the per-unit loop — every unit is held through the lease, never beyond it.
        $start = $leaseFrom && $leaseFrom->greaterThan($start) ? $leaseFrom : $start;
        $end = $leaseTo && $leaseTo->lessThan($end) ? $leaseTo : $end;

        if ($end->lessThan($start)) {
            return 0.0;     // the lease and the period do not overlap at all
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
     * The window this lease actually occupied its premises: `[from, to]`, either side nullable.
     *
     * `to` is the expiry date ONLY once the lease has ended. Termination writes the termination date
     * onto `expiry_date`, so a terminated lease's window is exactly what it occupied; an `active`
     * lease past its expiry is in holdover and has no end yet.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function occupancyWindow(): array
    {
        $ended = in_array($this->status, ['terminated', 'expired', 'renewed', 'cancelled'], true);

        return [
            $this->commencement_date ? CarbonImmutable::instance($this->commencement_date) : null,
            $ended && $this->expiry_date ? CarbonImmutable::instance($this->expiry_date) : null,
        ];
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
