<?php

namespace App\Observers;

use App\Models\Lease;

/**
 * Keeps the Unit ↔ Lease status graph consistent on every save/update path.
 *
 * The 3 lifecycle services (LeaseCreationService / LeaseRenewalService /
 * LeaseTerminationService) flip Unit status explicitly. This observer
 * mirrors the projection idempotently so the standard Filament form
 * (CreateLease / EditLease) gets the same behavior without duplicating
 * code. Pattern mirrors Payment::booted() (Module 06 reference design):
 * every hook is idempotent and read-then-write, so the service path's
 * explicit work is a no-op when re-applied.
 *
 * Charge seeding is NOT done here — it's part of LeaseCreationService and
 * the form-path equivalent in CreateLease::handleRecordCreation. Putting
 * it in the observer would double-seed charges in the service path
 * (observer fires at create time, before the service can add charges).
 */
class LeaseObserver
{
    public function created(Lease $lease): void
    {
        $this->syncUnitStatus($lease);
    }

    public function updated(Lease $lease): void
    {
        if ($lease->wasChanged('status')) {
            $this->syncUnitStatus($lease);
        }
    }

    /**
     * Idempotent unit status projection. Writes are no-ops when the unit
     * is already in the target state. Only 'active' and 'terminated|
     * expired' move the unit — other lease states (draft / pending_approval
     * / renewed / cancelled) leave occupancy untouched because operators
     * may intend to keep the unit visibly occupied during those workflow
     * states (e.g. renewal in flight).
     */
    private function syncUnitStatus(Lease $lease): void
    {
        $unit = $lease->unit;

        if (! $unit) {
            return;
        }

        if ($lease->status === 'active' && $unit->status !== 'occupied') {
            $unit->update(['status' => 'occupied']);

            return;
        }

        if (in_array($lease->status, ['terminated', 'expired'], true) && $unit->status === 'occupied') {
            $unit->update(['status' => 'vacant']);
        }
    }
}
