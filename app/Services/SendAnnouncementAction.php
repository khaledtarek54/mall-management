<?php

namespace App\Services;

use App\Jobs\BroadcastAnnouncement;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Notifications\AnnouncementNotification;
use App\Support\OpsLog;

/**
 * Broadcast an announcement to every ACTIVE tenant of its target property, then
 * stamp sent_at + recipients_count. A tenant is "in" the property when they hold
 * an active lease on a unit there. Idempotent: a re-run on an already-sent
 * announcement is a no-op (so a retried {@see BroadcastAnnouncement}
 * can't double-notify).
 */
class SendAnnouncementAction
{
    public function handle(Announcement $announcement): int
    {
        if ($announcement->sent_at !== null) {
            return (int) $announcement->recipients_count;
        }

        $tenants = Tenant::query()
            // `units` (the lease_unit pivot), NOT `unit`: leases.unit_id is only the
            // MASTER unit, so a multi-unit lease with an additional unit in this
            // property would otherwise be missed.
            ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $announcement->asset_id))
            ->with('users') // notifyPortal fans to each portal login — avoid an N+1
            ->get();

        $reached = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenant->notifyPortal(new AnnouncementNotification($announcement));
                $reached++;
            } catch (\Throwable $e) {
                // Isolate the failure: one bad recipient must not abort the blast and
                // strand the record un-stamped — the job is tries=1, so the operator's
                // only recovery would be recomposing, which re-spams everyone already
                // reached. Log and carry on.
                OpsLog::warning('announcement.recipient_failed', [
                    'announcement_id' => $announcement->id,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Always stamp, even on a partial blast: sent_at closes the record (the
        // guard above) and recipients_count reports who actually got it.
        $announcement->forceFill([
            'sent_at' => now(),
            'recipients_count' => $reached,
        ])->save();

        return $reached;
    }
}
