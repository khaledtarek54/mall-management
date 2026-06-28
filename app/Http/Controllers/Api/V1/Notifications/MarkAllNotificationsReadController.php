<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/notifications/read-all — mark every unread notification read.
 */
class MarkAllNotificationsReadController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->ok(null, __('admin.notifications.marked_read'));
    }
}
