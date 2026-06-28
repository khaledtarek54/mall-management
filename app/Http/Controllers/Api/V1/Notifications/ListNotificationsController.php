<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/notifications — the tenant's in-app notification inbox,
 * newest first. Pass ?unread=1 to return only unread. (Badge count comes from
 * GET /me/notifications/unread-count or /me/summary.)
 */
class ListNotificationsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $tenant = $request->user();

        $query = $request->boolean('unread')
            ? $tenant->unreadNotifications()
            : $tenant->notifications();

        return NotificationResource::collection($query->paginate($this->perPage($request)));
    }
}
