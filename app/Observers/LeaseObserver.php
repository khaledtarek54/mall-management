<?php

namespace App\Observers;

use App\Models\Lease;
use App\Models\Unit;

/**
 * Keeps the Unit ↔ Lease status graph consistent on every save/update path.
 *
 * Unit status is a projection of the leases on it:
 *   - any 'active' lease       → unit 'occupied'
 *   - any draft/pending/renewed → unit 'reserved'
 *   - otherwise                → unit 'vacant'
 *   - unit 'maintenance' is a manual override and never auto-overwritten.
 *
 * Idempotent: re-applying the projection on an already-correct unit is a
 * no-op, so the lifecycle services (LeaseCreationService / LeaseRenewalService
 * / LeaseTerminationService) and the Filament forms converge to the same
 * result without duplicating work.
 *
 * Charge seeding stays in LeaseCreationService / CreateLease::afterCreate —
 * doing it here would double-seed because the observer fires before the
 * service can add its own charges.
 */
class LeaseObserver
{
    private const RESERVED_STATUSES = ['draft', 'pending_approval', 'renewed'];

    public function created(Lease $lease): void
    {
        $this->syncUnitStatus($lease);
    }

    public function updated(Lease $lease): void
    {
        if ($lease->wasChanged('status') || $lease->wasChanged('unit_id')) {
            $this->syncUnitStatus($lease);

            // If the unit_id changed, the *previous* unit also needs to be
            // recomputed — it may have just lost its only non-terminal lease.
            $original = $lease->getOriginal('unit_id');
            if ($original && $original !== $lease->unit_id) {
                $this->recompute(Unit::find($original));
            }
        }
    }

    private function syncUnitStatus(Lease $lease): void
    {
        $this->recompute($lease->unit);
    }

    private function recompute(?Unit $unit): void
    {
        if (! $unit || $unit->status === 'maintenance') {
            return;
        }

        $leases = $unit->leases()->get(['status']);

        $target = match (true) {
            $leases->contains('status', 'active') => 'occupied',
            $leases->whereIn('status', self::RESERVED_STATUSES)->isNotEmpty() => 'reserved',
            default => 'vacant',
        };

        if ($unit->status !== $target) {
            $unit->update(['status' => $target]);
        }
    }
}
