<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The `asset_owner` pivot — legal ownership of a property by a Jawad owner, with an
 * ownership share and a tenure window. Casting it AT SOURCE (rather than every consumer
 * re-parsing raw pivot strings) is what lets the owner-statements weighting math treat
 * `started_at`/`ended_at` as real dates and `ownership_percentage` as a number.
 *
 * Tenure: `started_at`/`ended_at` are inclusive bounds; either null = unbounded on that
 * side (owned since inception / still owned). A mid-period *sale* is modelled by setting
 * `ended_at`; a mid-period *percentage change* (a future refinement) would need separate
 * segments, which the current `unique(user_id, asset_id)` intentionally does not yet allow.
 */
#[DeletionAllowed(reason: 'parent-managed: the ownership pivot, edited from the property')]
// the asset_owner ownership pivot — one row = one owner's stake in one mall; no Filament RESOURCE, managed through AssetOwnersRelationManager on the Asset (added 2026-08-11; until then this comment described a UI that did not exist, which is how the gap stayed invisible)
#[PropertyOwned]
class AssetOwner extends Pivot
{
    protected $table = 'asset_owner';

    public $incrementing = true;

    protected $casts = [
        'ownership_percentage' => 'decimal:2',
        'started_at' => 'date',
        'ended_at' => 'date',
    ];

    /** A property is owned once over — the whole of it, never more. */
    public const WHOLE = 100.0;

    /**
     * **A property cannot be owned twice over.**
     *
     * Reported from the panel: attach an owner at 100%, attach a second at 100%, and the register
     * read *"Ownership recorded: 200.00%"* with nothing refusing it. The money path does not
     * over-pay — `GenerateOwnerStatementRunService` weights each owner `pct / Σ pct` — which is
     * exactly what makes it dangerous: at 200% an owner RECORDED at 100% is silently paid HALF the
     * net, on a statement that prints 100% beside the figure. Finalise then refuses the run for a
     * total that is not whole, so the property simply stops distributing and nobody is told why.
     *
     * **Over and under 100% are not symmetric, and that is the whole design.**
     * `FinaliseOwnerStatementRunService` deliberately enforces the WHOLE at the money path rather
     * than here, with a reason worth preserving: *a 50/50 register cannot be built in one save —
     * the first co-owner would be refused for totalling 50.* True, and it says nothing about the
     * other direction. There is no register that has to pass THROUGH 200% on its way to being
     * correct: adding a co-owner to a property already fully owned means reducing somebody first.
     * So under-100% stays freely enterable and over-100% is refused.
     *
     * **On the MODEL, not the form**, for the reason the deposit cap is: a door onto this pivot is
     * covered by existing rather than by being remembered. There are already several — the panel's
     * Attach and Edit actions and three seeders' `syncWithoutDetaching()` — and they reach the hook
     * only because BOTH sides of the relation declare `->using(self::class)`. Without that,
     * `attach()` writes straight through the query builder and fires no model event at all.
     *
     * **Overlapping tenures only.** A resale is TWO rows — the seller ended, the buyer started —
     * and they are each 100% of the property at different times. Summing the column outright would
     * refuse every property that ever changed hands, which is the opposite failure and a worse one.
     * The row's OWN persisted share is excluded, or correcting 60% down to 40% would be measured
     * against a register that still counts the 60.
     *
     * **Deliberately NOT locked**, the same call the deposit cap makes: this hook often runs with no
     * transaction around it, and a row lock released on the next statement reads as protection
     * without being any. Two operators attaching two DIFFERENT owners at 100% each can still both
     * pass — the unique index does not constrain it, since the users differ — and the tab's total
     * shows the result immediately.
     */
    protected static function booted(): void
    {
        static::saving(function (self $owner): void {
            $proposed = round(
                $owner->overlappingShareTotal($owner->started_at, $owner->ended_at)
                    + (float) $owner->ownership_percentage,
                2,
            );

            if ($proposed <= self::WHOLE) {
                return;
            }

            // ── A REGISTER THAT IS ALREADY WRONG MUST STAY CORRECTABLE ──────────────────────
            //
            // Refusing every over-100 save would DEADLOCK the exact data this guard exists to
            // catch. Two owners at 100% each: reducing the first is measured against the second's
            // 100 and refused, and so is reducing the second — on a property that is already
            // recorded wrong and cannot distribute. (Detach still works, and is the blunt way out;
            // being forced to detach an owner to correct a percentage is not a remedy.) That is the trap `#[NeverDeletable]` and the bank-account chart rule
            // both state: a guard that also blocks the correction protects nothing and breaks the
            // workflow. Staging is carrying such a register right now.
            //
            // So the test is DIRECTIONAL: a save that leaves the property no more over-owned than
            // it found it is the operator fixing this, and is allowed through.
            //
            // **Measured against the PERSISTED window, not the proposed one, and that distinction
            // is the whole guard.** Comparing both readings across the same dates makes the other
            // owners' total cancel out algebraically, so the test collapses to "the percentage went
            // down" — and the dates go unchecked. Reproduced on real data before this was fixed:
            // from a CLEAN resale register, editing the seller to clear `ended_at` AND drop 100 →
            // 99.99 in one save was ALLOWED, because 199.99 < 200 — leaving the property recorded
            // at 199.99% overlapping, which is the reported bug re-created out of correct data.
            //
            // `<=` rather than `<` so a DATE-ONLY correction on an already-over register goes
            // through: it moves no percentage, so the two readings are equal, and refusing it would
            // contradict the refusal's own advice to end a tenure.
            $persisted = round(
                $owner->overlappingShareTotal(
                    $owner->getOriginal('started_at'),
                    $owner->getOriginal('ended_at'),
                ) + (float) $owner->getOriginal('ownership_percentage'),
                2,
            );

            if ($owner->exists && $proposed <= $persisted) {
                return;
            }

            throw new DomainException(__('admin.refusals.ownership_exceeds_the_property', [
                'total' => number_format($proposed, 2),
                'remaining' => number_format(
                    max(0, self::WHOLE - $owner->overlappingShareTotal($owner->started_at, $owner->ended_at)),
                    2,
                ),
            ]));
        });
    }

    /**
     * What the OTHER owners hold across any part of this row's tenure.
     *
     * Deliberately the widest reading — any overlap at all, not a weighted average — because a
     * property being owned 150% for a single day is still a register nobody can distribute from.
     */
    public function overlappingShareTotal(mixed $from = null, mixed $to = null): float
    {
        return round((float) static::query()
            ->where('asset_id', $this->asset_id)
            ->when($this->getKey(), fn (Builder $q, $id) => $q->whereKeyNot($id))
            ->overlapping($from, $to)
            ->sum('ownership_percentage'), 2);
    }

    /**
     * Tenures that share any day with `[$from, $to]` — nulls are unbounded on that side.
     *
     * The same shape as {@see UnitOwnership::scopeOverlapping()}, and named alike on purpose: it is
     * the same question about the other ownership table, and two spellings of one range test is how
     * they come to disagree about a resale.
     */
    public function scopeOverlapping(Builder $query, mixed $from = null, mixed $to = null): void
    {
        // A null bound on the ROW being tested means unbounded, so the clause it would produce is
        // true for every row — omitted rather than written as `1=1`.
        if ($to !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('started_at')
                ->orWhereDate('started_at', '<=', Carbon::parse($to)->toDateString()));
        }

        if ($from !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('ended_at')
                ->orWhereDate('ended_at', '>=', Carbon::parse($from)->toDateString()));
        }
    }

    /**
     * True once this tenure has run out — the owner SOLD, and is no longer one.
     *
     * A COPY of {@see AssetUser::hasEnded()}, not a shared seam — and said that way deliberately,
     * because three methods above this file's own `scopeOverlapping()` docblock warns that "two
     * spellings of one range test is how they come to disagree". Nothing structural keeps these two
     * equal; what does is that BOTH sides pin the boundary by test.
     *
     * **`lt()`, so the last day of a tenure is still OWNED.** That is the whole reason this exists
     * rather than `! coversDate()`, and it has to agree with `Asset::propertyOwnersOn()` and
     * `User::currentOwnedAssets()` — the second of which GRANTS an owner their access — or the
     * badge and the grant diverge on the day somebody sells. Distinct from `! coversDate()`,
     * which is also true of a tenure that has not STARTED yet — a former owner and an incoming one
     * are opposite facts and a badge that merged them would be worse than none.
     */
    public function hasEnded(?\DateTimeInterface $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        return $this->ended_at !== null && $this->ended_at->startOfDay()->lt($on);
    }

    /** True if this ownership segment is in effect on $date (default: today). */
    public function coversDate(\DateTimeInterface|string|null $date = null): bool
    {
        $on = ($date !== null ? Carbon::parse($date) : Carbon::today())->startOfDay();

        if ($this->started_at !== null && $this->started_at->startOfDay()->gt($on)) {
            return false;
        }
        if ($this->ended_at !== null && $this->ended_at->startOfDay()->lt($on)) {
            return false;
        }

        return true;
    }
}
