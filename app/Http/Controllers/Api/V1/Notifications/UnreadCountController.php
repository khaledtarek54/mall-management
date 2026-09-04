<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/notifications/unread-count — lightweight badge count for the
 * tenant's unread notifications.
 */
class UnreadCountController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return $this->ok(['unread_count' => $request->user()->tenant->unreadNotifications()->count()]);
    }
}
