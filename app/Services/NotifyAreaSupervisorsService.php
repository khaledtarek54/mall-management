<?php

namespace App\Services;

use App\Models\Area;
use App\Models\TenantRequest;
use App\Notifications\AreaRequestRaisedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Routes a freshly-created request to its facility zone's supervisors (module 30 → 11).
 *
 * Runs **alongside** the department routing, not instead of it: a request can trigger both fan-outs
 * — the department team gets its notification (TenantRequestService::notifyOperators), the zone's
 * supervisors get theirs. This is *notification only* — the coordinator still owns assignment; a
 * supervisor is informed a request landed in their area, not auto-assigned to it.
 *
 * Invoked from TenantRequest's `created` model event — the single hook every create path (admin
 * Filament, tenant portal, mobile API) passes through, since admin creation never touches
 * TenantRequestService. Idempotent and safe: no area, a trashed area, or no supervisors is a no-op,
 * and every failure is contained so a bad recipient can never break request creation (mirrors the
 * Throwable-wrapped fan-outs in TenantRequestService).
 */
class NotifyAreaSupervisorsService
{
    public function notify(TenantRequest $request): void
    {
        try {
            if ($request->area_id === null) {
                return;
            }

            /** @var Area|null $area */
            $area = $request->area()->first();

            if ($area === null) {
                return;
            }

            $supervisors = $area->supervisors()->get();

            if ($supervisors->isEmpty()) {
                return;
            }

            Notification::send($supervisors, new AreaRequestRaisedNotification($request));
        } catch (\Throwable $e) {
            Log::warning('Area supervisor notification fan-out failed', [
                'request_id' => $request->id,
                'area_id' => $request->area_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
