<?php

namespace App\Services\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Tenant;
use App\Models\TenantUser;

/**
 * Stamp a tenant's read receipt on a notice.
 *
 * Called from the mobile API (`POST /me/announcements/{id}/read`) and from the portal when a
 * tenant opens the notice. Idempotent by design: the FIRST read is the one recorded, so a
 * retailer who re-opens a notice a week later does not reset the timestamp the operator is
 * reading. "When did they first see it" is the answerable question; "when did they last look"
 * is not one anybody asks.
 *
 * A tenant with no recipient row is a no-op, not an error. That is the shape of every legitimate
 * near-miss — a notice sent before this store's lease began, a stale id from a cached list — and
 * the caller has already refused anything that is actually a cross-tenant read (404, never 403;
 * the no-enumeration rule).
 */
class MarkAnnouncementReadAction
{
    public function handle(Announcement $announcement, Tenant $tenant, ?TenantUser $by = null): ?AnnouncementRecipient
    {
        /** @var AnnouncementRecipient|null $recipient */
        $recipient = $announcement->recipients()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        if ($recipient === null || $recipient->isRead()) {
            return $recipient;
        }

        $recipient->forceFill([
            'read_at' => now(),
            'read_by_tenant_user_id' => $by?->getKey(),
        ])->save();

        return $recipient;
    }
}
