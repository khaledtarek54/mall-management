<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/notifications/{id}/read — mark a single notification read.
 * Scoped to the tenant's own notifications, so another tenant's (or a missing)
 * id returns 404 — no cross-tenant enumeration.
 */
class MarkNotificationReadController extends ApiController
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            abort(404);
        }

        $notification->markAsRead();

        return $this->ok(null, __('admin.notifications.marked_read'));
    }
}
