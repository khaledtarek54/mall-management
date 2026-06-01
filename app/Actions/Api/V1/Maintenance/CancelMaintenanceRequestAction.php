<?php

namespace App\Actions\Api\V1\Maintenance;

use App\Models\MaintenanceRequest;
use App\Services\MaintenanceRequestService;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-initiated cancellation. The underlying service allows cancelling from
 * several states, but the tenant-facing rule is narrower: cancel only before
 * staff have started work (submitted / acknowledged). That extra guard is the
 * reason this is an action rather than a bare service call.
 */
class CancelMaintenanceRequestAction
{
    /** Statuses a tenant may still cancel from. */
    public const CANCELLABLE = ['submitted', 'acknowledged'];

    public function __construct(private MaintenanceRequestService $service) {}

    public function handle(MaintenanceRequest $request): MaintenanceRequest
    {
        if (! in_array($request->status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages([
                'status' => [__('api.maintenance_cannot_cancel')],
            ]);
        }

        return $this->service->transition($request, 'cancelled');
    }
}
