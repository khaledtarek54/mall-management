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
    public function created(Lease $lease): void
    {
        $this->ensureMasterPivot($lease);
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
