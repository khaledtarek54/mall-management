<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/announcements — the mall-news feed: every notice this tenant was sent that is
 * still live, pinned first then newest. Pass `?unread=1` for the unread ones only.
 *
 * This is the surface that turns an announcement from a notification into something a tenant can
 * come back to. Before it, the notice existed only as a bell row: once it scrolled out of the
 * inbox there was no way to read it again, and the record was not retrievable by the app at all.
 *
 * Visibility is {@see Announcement::scopeLiveFor} and nothing else — the same predicate the detail
 * endpoint, the unread badge and the portal table use.
 */
class ListAnnouncementsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        $query = Announcement::query()
            ->liveFor($tenant)
            // Constrained to THIS tenant's row: the resource reads its own read receipt off the
            // relation, and an unconstrained load would hand every recipient's receipt to
            // whoever asked.
            ->with([
                'recipients' => fn ($q) => $q->where('tenant_id', $tenant->getKey()),
                'asset:id,code,name',
                'media',
            ])
            ->feedOrder();

        if ($request->boolean('unread')) {
            $query->whereHas('recipients', fn ($q) => $q
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('read_at'));
        }

        return AnnouncementResource::collection($query->paginate($this->perPage($request)));
    }
}
