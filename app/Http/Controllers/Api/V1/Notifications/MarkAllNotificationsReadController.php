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
        // Method form = one bulk UPDATE (the property form hydrates every row and
        // issues one UPDATE each). No read_at observer needs the model events.
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(null, __('admin.notifications.marked_read'));
    }
}
