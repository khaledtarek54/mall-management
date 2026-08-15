<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit OWNER carries his share of the common cost.
 *
 * Measured before this was written (`OwnedUnitIsAbsentFromCamTest`): on a mall half let and half
 * sold, with a 100,000 pool, the one tenant was allocated **100%** and billed **100,000** where a
 * just share of his own half is 50,000. `CamReconciliationService` builds its participants from
 * LEASES, so a sold unit was neither allocated a share nor counted in the denominator — the owner
 * used the common area and the remaining tenants paid for it.
 *
 * And the pool tied out exactly while doing it. Σ allocated = total expense by construction on the
 * occupied basis, so the books-check was satisfied and the reconciliation report showed nothing
 * amiss. A tie-out proves the money was fully apportioned; it cannot notice that it was apportioned
 * over the wrong set of parties.
 *
 * ## The design, because it is not obvious
 *
 * **An owner's monthly صيانة IS his CAM estimate.** A tenant pays a monthly service-charge estimate
 * and settles it once a year against actuals; an owner pays a monthly assessment. They are the same
 * economic act — recovery of common costs — and they are already the same `service_charge` charge
 * type, which is what `estimateBilledFor()` sums. So an ownership joins the pool as an ordinary
 * participant whose `estimated_paid` is the assessments it was billed that year, and it settles with
 * a true-up like anybody else.
 *
 * What an ownership does NOT bring is CAM CLAUSE machinery — a stated share, a ceiling, a
 * controllable-cost carve-out, banked carry-forward headroom. Those are terms negotiated into a
 * LEASE. A sale has none of them, so an ownership takes the plain pro-rata path and the cap block is
 * skipped rather than answered with neutral values.
 *
 * Exactly one of `lease_id` / `unit_ownership_id` is set, enforced on the model rather than as a
 * CHECK — SQLite drops CHECKs on any later `->change()` to the table.
 *
 * @see docs/plans/08-unit-owners.md §5.7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->foreignId('unit_ownership_id')->nullable()->after('lease_id')->constrained()->restrictOnDelete();
            $table->index('unit_ownership_id');
        });

        // `lease_id` becomes optional in the same breath. Its unique index (if any) is left alone:
        // a unit can be resold, so a pool legitimately holds two allocations for one unit in
        // different tenures, and the participant set is keyed by AGREEMENT rather than by unit.
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $owned = \Illuminate\Support\Facades\DB::table('cam_allocations')->whereNull('lease_id')->count();

        if ($owned > 0) {
            throw new RuntimeException(
                "Cannot reverse: {$owned} CAM allocation(s) belong to a unit ownership rather than a lease. "
                .'Deciding what happens to those recoveries comes first.'
            );
        }

        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('unit_ownership_id');
        });
    }
};
