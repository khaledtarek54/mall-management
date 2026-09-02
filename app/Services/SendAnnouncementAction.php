<?php

namespace App\Services;

use App\Jobs\BroadcastAnnouncement;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Notifications\AnnouncementNotification;
use App\Support\OpsLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast an announcement to every ACTIVE tenant of its target property: record the recipient
 * list, notify each of them, then stamp `status`, `sent_at` and `recipients_count`.
 *
 * A tenant is "in" the property when they hold an active lease on a unit there. Idempotent: a
 * re-run on an already-sent announcement is a no-op, so a retried {@see BroadcastAnnouncement}
 * cannot double-notify.
 *
 * **The recipient rows are written BEFORE the notifications go out, in their own transaction.**
 * That ordering is the whole reason the feed can be trusted. `AnnouncementNotification` deep-links
 * into the post, and `Announcement::liveFor()` only shows a post to a tenant who has a recipient
 * row — so a push that arrives before its row exists deep-links to a 404. Writing the list first
 * costs one statement and removes the window entirely.
 *
 * The rows are also what survives the blast: `recipients_count` is a number, and a number cannot
 * answer "has that store read it yet", which is the question an operator actually asks.
 */
class SendAnnouncementAction
{
    public function handle(Announcement $announcement): int
    {
        if ($announcement->sent_at !== null) {
            return (int) $announcement->recipients_count;
        }

        // **A BROADCAST INTO A CLOSED WINDOW REACHES A 404.** `expires_at` was accepted unvalidated,
        // so a notice could be sent with an end date already in the past — or before its own
        // `publish_at`. The blast still goes out: every tenant gets the push and the bell,
        // `announcement_recipients` records who, and then the portal's own scope
        // (`whereNull('expires_at')->orWhere('expires_at', '>=', now())`) excludes it, so the deep
        // link every one of them taps lands on nothing.
        //
        // And there is no way back. `isEditable()` is false the moment a notice is sent — correctly,
        // because it is evidence: tenants hold a notification quoting its text. So the only repair
        // is composing a second notice to explain the first, which is a worse thing to have to send
        // than the notice itself.
        //
        // Guarded HERE rather than only on the form: the scheduled sweep sends without a form, and
        // an `expires_at` that was in the FUTURE when the notice was scheduled can be in the past by
        // the time the sweep reaches it — which no form rule can see.
        if ($announcement->expires_at !== null && $announcement->expires_at->isPast()) {
            throw new DomainException(__('admin.refusals.announcement_window_closed', [
                'expired' => $announcement->expires_at->format('d/m/Y H:i'),
            ]));
        }

        $tenants = Tenant::query()
            // `units` (the lease_unit pivot), NOT `unit`: leases.unit_id is only the
            // MASTER unit, so a multi-unit lease with an additional unit in this
            // property would otherwise be missed.
            ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $announcement->asset_id))
            ->with('users') // notifyPortal fans to each portal login — avoid an N+1
            ->get();

        $this->recordRecipients($announcement, $tenants->modelKeys());

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

                continue;
            }

            // Stamped per recipient, after their own delivery succeeded, so a null `notified_at`
            // names exactly who the blast missed. The row itself stays either way: they are still
            // an intended recipient, the notice still belongs in their feed, and hiding it would
            // turn a delivery failure into a silent omission.
            $announcement->recipients()
                ->where('tenant_id', $tenant->getKey())
                ->update(['notified_at' => now()]);
        }

        // Always stamp, even on a partial blast: sent_at closes the record (the
        // guard above) and recipients_count reports who actually got it.
        $announcement->forceFill([
            'status' => Announcement::STATUS_SENT,
            'sent_at' => now(),
            'recipients_count' => $reached,
        ])->save();

        return $reached;
    }

    /**
     * Write the recipient list in one statement.
     *
     * `insertOrIgnore` against the `(announcement_id, tenant_id)` unique key rather than a
     * per-tenant `firstOrCreate`: a property with 200 retailers would otherwise cost 400 queries
     * before a single notification is sent, and the collision case here is a re-run, which the
     * `sent_at` guard has already refused.
     *
     * @param  array<int, int|string>  $tenantIds
     */
    private function recordRecipients(Announcement $announcement, array $tenantIds): void
    {
        if ($tenantIds === []) {
            return;
        }

        $now = now();

        DB::table('announcement_recipients')->insertOrIgnore(
            collect($tenantIds)->map(fn ($tenantId) => [
                'announcement_id' => $announcement->getKey(),
                'tenant_id' => $tenantId,
                'notified_at' => null,
                'read_at' => null,
                'read_by_tenant_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
}
