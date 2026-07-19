<?php

namespace App\Observers;

use App\Models\Lease;
use App\Models\Unit;

/**
 * Keeps the Unit ↔ Lease status graph consistent on every save path.
 *
 * Unit status is a projection of the leases that include the unit (via the
 * lease_unit pivot, so multi-unit leases count):
 *   - any 'active' lease         → 'occupied'
 *   - any draft/pending/renewed  → 'reserved'
 *   - otherwise                  → 'vacant'
 *   - 'maintenance' is a manual override and is never auto-overwritten.
 *
 * leases.unit_id is the MASTER unit; the observer mirrors it into a master
 * lease_unit row so the single-unit code paths (which only set unit_id) stay
 * correct. Multi-unit edits go through Lease::syncUnits(), which owns the pivot
 * and recomputation itself.
 *
 * Idempotent: re-applying the projection on an already-correct unit is a no-op.
 */
class LeaseObserver
{
    /**
     * Unit ids captured at `deleting` so `deleted` can recompute them. STATIC because Eloquent may
     * resolve a fresh observer instance per event, so instance state would not survive from
     * `deleting` to `deleted`. Keyed by lease id + unset after use, so it self-cleans.
     *
     * @var array<int|string, array<int>>
     */
    private static array $pendingUnitIds = [];

    public function created(Lease $lease): void
    {
        $this->ensureMasterPivot($lease);
        $this->recomputeUnits($lease);
    }

    /**
     * Capture the attached units BEFORE the delete lands. forceDelete() internally calls delete(),
     * so deleting/deleted fire for BOTH soft and hard deletes — and on a hard delete the lease_unit
     * pivot is cascaded away by the time `deleted` runs, so we must read the unit ids here first.
     */
    public function deleting(Lease $lease): void
    {
        self::$pendingUnitIds[$lease->getKey()] = $lease->units()->pluck('units.id')->all();
    }

    /**
     * A deleted lease must free the units it held. Recompute each captured unit: allLeases()
     * (belongsToMany) excludes the now-gone/trashed lease, so a unit with no other live lease
     * re-projects to 'vacant'. Without this, a deleted active lease stranded its units at 'occupied'
     * forever — inflating owner-facing occupancy and hiding them from the lease picker. The fallback
     * to $lease->units() covers a soft delete (pivot still present) if `deleting` didn't run first.
     */
    public function deleted(Lease $lease): void
    {
        $ids = self::$pendingUnitIds[$lease->getKey()] ?? $lease->units()->pluck('units.id')->all();
        unset(self::$pendingUnitIds[$lease->getKey()]);
        Unit::whereIn('id', $ids)->get()->each->recomputeStatus();
    }

    /** Restoring the lease re-projects its units (back to occupied/reserved). */
    public function restored(Lease $lease): void
    {
        $this->recomputeUnits($lease);
    }

    public function updated(Lease $lease): void
    {
        if (! $lease->wasChanged('status') && ! $lease->wasChanged('unit_id')) {
            return;
        }

        if ($lease->wasChanged('unit_id')) {
            // Single-unit reassignment: drop the old unit, promote the new one.
            $original = (int) $lease->getOriginal('unit_id');
            if ($original && $original !== (int) $lease->unit_id) {
                $lease->units()->detach($original);
                Unit::find($original)?->recomputeStatus();
            }
            $this->ensureMasterPivot($lease);
        }

        $this->recomputeUnits($lease);
    }

    /** Mirror leases.unit_id into a master row in lease_unit. */
    private function ensureMasterPivot(Lease $lease): void
    {
        if (! $lease->unit_id) {
            return;
        }

        $lease->units()->syncWithoutDetaching([
            $lease->unit_id => ['is_master' => true],
        ]);
    }

    /** Recompute occupancy for every unit attached to the lease. */
    private function recomputeUnits(Lease $lease): void
    {
        $lease->units()->get()->each->recomputeStatus();
    }
}
