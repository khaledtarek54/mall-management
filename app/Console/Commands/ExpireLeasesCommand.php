<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Unit;
use App\Support\OpsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move a lease whose term has run out to `expired`, and re-project the units it held.
 *
 * ## Why this had to exist
 *
 * There was a `vendors:expire-contracts` sweep for vendor contracts and **no equivalent for
 * leases**, so a lease whose `expiry_date` had passed stayed `active` indefinitely unless a person
 * renewed, terminated or held it over. Measured on a lease that expired 2026-01-31, seven months
 * later (pre-staging QA, F-04):
 *
 *  - the lease still read `active`, and the unit still read `occupied` — so `occupancyRate()`,
 *    `areaOccupancyRate()`, the occupancy map and the rent roll all overstated occupancy;
 *  - **the unit could not be re-let**: `LeaseCreationService` refused with "this unit already has
 *    an active lease" on a shop that was physically empty;
 *  - `RentEscalationService` kept stepping its rent, writing schedule rows for years the tenancy
 *    did not cover (fixed separately, in that service's own query — the two guards protect
 *    different things and neither substitutes for the other);
 *  - the deposit stayed "held" forever and no final account was ever prompted.
 *
 * Invoices were never at risk: `MonthlyBillingService` refuses an ended lease with `lease_ended`.
 * What was wrong was the STATE, and everything that reads it.
 *
 * ## Two things it does, for one reason
 *
 * The second half is F-05, and it is the same bug seen from the other end. `Unit::recomputeStatus()`
 * is correctly DATE-AWARE, but it only ever runs on a lease event, on the unit pages, or from
 * `LeaseSpaceChangeService` — **nothing runs on a schedule**. So a give-back effective 1 January,
 * recorded in August, leaves `units.status = 'occupied'` on 1 January and every day after, until
 * something unrelated happens to touch that lease. Confirmed by simulating the date: the projection
 * answered "no lease currently holds this unit" while the stored column still said `occupied`.
 *
 * Both are a stored value going stale on a date boundary that no write crossed, which is exactly
 * what a nightly sweep is for. Doing them in one command keeps the ordering right: expire first,
 * then re-project, so a unit freed by an expiry is caught in the same run.
 *
 * Idempotent and lock-safe on the project's terms for a scheduled scan: each row is locked and its
 * condition re-checked inside its own transaction, and `update()` is used rather than a mass update
 * so the activity trail and `LeaseObserver` both fire.
 */
class ExpireLeasesCommand extends Command
{
    protected $signature = 'leases:expire
        {--dry-run : Print what would change without writing}';

    protected $description = 'Expire active leases past their term, and re-project any unit whose occupancy has gone stale.';

    public function handle(): int
    {
        $expired = $this->expireLeases();
        $reprojected = $this->reprojectUnits();

        if (! $this->option('dry-run')) {
            OpsLog::info('Lease expiry sweep complete', [
                'expired' => $expired,
                'units_reprojected' => $reprojected,
            ]);
        }

        return self::SUCCESS;
    }

    /** @return int leases moved to `expired` */
    private function expireLeases(): int
    {
        // A CONVERTED HOLDOVER is excluded, and that exclusion is the whole subtlety here: its
        // expiry is deliberately in the past — `holdover_from` is what makes it billable at all —
        // so expiring it would end a tenancy the operator has explicitly chosen to continue, and
        // stop the billing that decision exists to keep running.
        $candidates = Lease::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', today())
            ->whereNull('holdover_from');

        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No active leases past their expiry date.');

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would expire {$count} lease(s):");
            $candidates->with('tenant:id,name', 'unit:id,code')->get()->each(function (Lease $l) {
                $this->line(sprintf(
                    '  #%d %s · %s · unit %s · expired %s',
                    $l->id,
                    $l->reference ?: '—',
                    $l->tenant?->name ?? '—',
                    $l->unit?->code ?? '—',
                    $l->expiry_date->format('Y-m-d'),
                ));
            });

            return 0;
        }

        $updated = 0;

        foreach ($candidates->select('id')->get() as $row) {
            DB::transaction(function () use ($row, &$updated) {
                /** @var Lease|null $lease */
                $lease = Lease::whereKey($row->id)->lockForUpdate()->first();

                if ($lease
                    && $lease->status === 'active'
                    && $lease->expiry_date
                    && $lease->expiry_date->isBefore(today())
                    && blank($lease->holdover_from)) {
                    // The observer re-projects the units off the back of this status change.
                    $lease->update(['status' => 'expired']);
                    $updated++;
                }
            });
        }

        $this->info("Expired {$updated} lease(s).");

        return $updated;
    }

    /**
     * Re-project any unit whose STORED status no longer matches what the projection says.
     *
     * Compared rather than blindly recomputed, so the run reports what it actually moved instead of
     * touching every unit in the portfolio every night. `recomputeStatus()` is itself a no-op when
     * the target equals the stored value, so this is belt and braces — but a sweep that says
     * "re-projected 0" on a quiet night is one an operator can read.
     *
     * `maintenance` is never touched: that status is a manual override the projection already
     * refuses to overwrite, and re-asserting it here would be a second opinion on the same rule.
     *
     * @return int units whose status changed
     */
    private function reprojectUnits(): int
    {
        $changed = 0;

        Unit::query()
            ->where('status', '!=', 'maintenance')
            ->with('allLeases')
            ->chunkById(200, function ($units) use (&$changed) {
                foreach ($units as $unit) {
                    /** @var Unit $unit */
                    $before = $unit->status;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    $unit->recomputeStatus();

                    if ($unit->fresh()->status !== $before) {
                        $changed++;
                    }
                }
            });

        if (! $this->option('dry-run')) {
            $this->info("Re-projected {$changed} unit(s) whose occupancy had gone stale.");
        }

        return $changed;
    }
}
