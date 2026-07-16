<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Notifications\LeaseExpiryApproachingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remind tenants whose active lease is approaching its expiry date, so they can
 * start the renewal conversation. Idempotent via leases.expiry_reminder_notified_at
 * — each lease reminds once. Mirrors the "expiring soon" window used by the admin
 * ExpiringLeases widget (active leases with expiry_date within the next N days).
 */
class RemindExpiringLeasesCommand extends Command
{
    protected $signature = 'leases:remind-expiring {--dry-run : Print what would be reminded without writing}';

    protected $description = 'Remind tenants about leases approaching expiry (idempotent via expiry_reminder_notified_at).';

    public function handle(): int
    {
        $days = (int) config('billing.lease_expiry_reminder_days', 90);

        $expiring = Lease::query()
            ->where('status', 'active')
            ->whereNull('expiry_reminder_notified_at')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->with(['tenant', 'unit'])
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No leases approaching expiry.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would remind tenants on {$expiring->count()} expiring lease(s):");
            foreach ($expiring as $lease) {
                /** @var Tenant $tenant */
                $tenant = $lease->tenant;
                /** @var Unit $unit */
                $unit = $lease->unit;
                $this->line(sprintf(
                    '  %s · %s · unit %s · expires %s',
                    $lease->reference,
                    $tenant->name,
                    $unit->code,
                    $lease->expiry_date->format('Y-m-d'),
                ));
            }

            return self::SUCCESS;
        }

        $reminded = 0;
        foreach ($expiring as $lease) {
            try {
                // Lock the lease + re-check the stamp inside the transaction so an
                // overlapping scan can't remind the same tenant twice.
                $sent = DB::transaction(function () use ($lease) {
                    $locked = Lease::query()->lockForUpdate()->find($lease->id);
                    if (! $locked
                        || $locked->status !== 'active'
                        || $locked->expiry_reminder_notified_at !== null) {
                        return false;
                    }

                    /** @var Tenant|null $tenant */
                    $tenant = $locked->tenant;
                    if (! $tenant) {
                        return false;
                    }

                    $tenant->notifyPortal(new LeaseExpiryApproachingNotification($locked));
                    $locked->forceFill(['expiry_reminder_notified_at' => now()])->save();

                    return true;
                });

                if ($sent) {
                    $reminded++;
                }
            } catch (\Throwable $e) {
                $this->warn("  failed on #{$lease->id}: ".$e->getMessage());
            }
        }

        $this->info("Reminded tenants on {$reminded} of {$expiring->count()} expiring lease(s).");

        return self::SUCCESS;
    }
}
