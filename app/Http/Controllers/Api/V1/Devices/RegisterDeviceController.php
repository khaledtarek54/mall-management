<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Actions\Api\V1\Devices\RegisterDeviceTokenAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Devices\RegisterDeviceRequest;
use App\Http\Resources\Api\V1\DeviceTokenResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/devices — register/refresh a push token for this device.
 */
class RegisterDeviceController extends ApiController
{
    public function __invoke(RegisterDeviceRequest $request, RegisterDeviceTokenAction $action): JsonResponse
    {
        $device = $action->handle($request->user()->tenant, $request->payload());

        return $this->ok(new DeviceTokenResource($device), __('api.device_registered'), 201);
    }
}
