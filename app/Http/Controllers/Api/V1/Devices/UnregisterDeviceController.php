<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Actions\Api\V1\Devices\UnregisterDeviceTokenAction;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DELETE /api/v1/me/devices/{id} — remove a device's push registration.
 */
class UnregisterDeviceController extends ApiController
{
    public function __invoke(Request $request, int $id, UnregisterDeviceTokenAction $action): JsonResponse
    {
        if (! $action->handle($request->user(), $id)) {
            throw new NotFoundHttpException(__('api.not_found'));
        }

        return $this->ok(message: __('api.device_unregistered'));
    }
}
