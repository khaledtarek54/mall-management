<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Violation;
use App\Notifications\ViolationNoticeNotification;
use App\Support\OpsLog;

/**
 * Send the tenant a notice of a recorded violation (FR-REQ-17) and stamp
 * `notified_at`. A single-action service so the business logic stays out of the
 * Filament table action.
 *
 * Delivery goes through the SAME tenant-notify path every operator→tenant signal
 * uses ({@see \App\Models\Tenant::notifyPortal()}): the Tenant's mobile inbox +
 * push, and each portal login's web bell.
 *
 * FAILURE-CONTAINED: a missing recipient or a throwing send never bubbles a 500
 * up to the operator's click — it is logged and reported as an un-sent notice,
 * and `notified_at` is left null so the operator can retry. `notified_at` is
 * stamped only on a successful send.
 */
class SendViolationNoticeAction
{
    /** @return bool whether the notice is delivered (true = sent now OR already sent earlier). */
    public function handle(Violation $violation): bool
    {
        // Idempotent: a violation already notified is NOT re-sent — a repeat click (or a crafted
        // re-dispatch) is a no-op that reports success (the tenant already has the notice). A
        // PARTIAL failure leaves `notified_at` null (stamped only on full success below), so a
        // genuine retry after a failed send still runs.
        if ($violation->notified_at !== null) {
            return true;
        }

        $tenant = $violation->tenant;

        // A missing/removed tenant (e.g. soft-deleted) is a safe no-op, not a crash —
        // nothing is stamped (no delivery happened).
        if ($tenant === null) {
            OpsLog::warning('violation.notice_no_tenant', ['violation_id' => $violation->id]);

            return false;
        }

        // Larastan mistypes the BelongsTo to Model — type the local var for notifyPortal().
        /** @var Tenant $tenant */
        try {
            $tenant->notifyPortal(new ViolationNoticeNotification($violation));
        } catch (\Throwable $e) {
            // A bad recipient / push failure must not 500 the action.
            OpsLog::warning('violation.notice_failed', [
                'violation_id' => $violation->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $violation->forceFill(['notified_at' => now()])->save();

        return true;
    }
}
