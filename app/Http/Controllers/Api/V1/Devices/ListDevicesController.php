<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\DeviceTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/devices — the devices currently registered to receive this tenant's push.
 *
 * The read half of a registration surface that only ever had writes. It exists for one reason a
 * "signed-in devices" list always exists: a tenant who lost a phone had no way to see it was still
 * registered, and no way to revoke it — `DELETE /me/devices/{id}` needs an id the client could only
 * have if it was the one that registered it.
 *
 * Safe to expose by construction: {@see DeviceTokenResource} never echoes the raw push token back,
 * which is a write-only credential from the client's side. What ships is the platform, the device
 * name the client chose, and when it registered — enough to recognise a device, not enough to
 * impersonate one.
 *
 * Deliberately NOT paginated: this is a handful of rows per tenant, and a page cursor on a list
 * nobody scrolls is a contract with no reader.
 */
class ListDevicesController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $devices = $request->user()->deviceTokens()->latest('id')->get();

        return $this->ok(DeviceTokenResource::collection($devices)->resolve());
    }
}
