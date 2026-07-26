<?php

namespace App\Services;

use App\Models\Area;
use App\Models\MaintenanceWorkOrder;
use App\Models\TenantRequest;
use App\Notifications\AreaRequestRaisedNotification;
use App\Notifications\AreaWorkOrderRaisedNotification;
use Illuminate\Notifications\Notification as BaseNotification;
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
    /** Route a freshly-created tenant request to its zone's supervisors. */
    public function notify(TenantRequest $request): void
    {
        $this->dispatch($request->area_id, new AreaRequestRaisedNotification($request), [
            'request_id' => $request->id,
            'area_id' => $request->area_id,
        ]);
    }

    /**
     * Route a freshly-created WORK ORDER to its zone's supervisors — the work-order half of the
     * routing (module 30 → 11). Same channel + fail-safety as request routing; notify, not assign.
     */
    public function notifyWorkOrder(MaintenanceWorkOrder $order): void
    {
        $this->dispatch($order->area_id, new AreaWorkOrderRaisedNotification($order), [
            'work_order_id' => $order->id,
            'area_id' => $order->area_id,
        ]);
    }

    /**
     * The shared fan-out: send $notification to the zone's supervisors. Idempotent + fail-safe — no
     * area, a trashed area (the default relation excludes it), or no supervisors is a no-op, and
     * every failure is contained so a bad recipient can never break request / work-order creation.
     *
     * @param  array<string,mixed>  $logContext
     */
    private function dispatch(?int $areaId, BaseNotification $notification, array $logContext): void
    {
        try {
            if ($areaId === null) {
                return;
            }

            /** @var Area|null $area */
            $area = Area::find($areaId);

            if ($area === null) {
                return;
            }

            $supervisors = $area->supervisors()->get();

            if ($supervisors->isEmpty()) {
                return;
            }

            Notification::send($supervisors, $notification);
        } catch (\Throwable $e) {
            Log::warning('Area supervisor notification fan-out failed', $logContext + [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
