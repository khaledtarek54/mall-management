<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/announcements/{id} — one notice, with its full body and artwork.
 *
 * The destination of the push. A notice this tenant was never sent returns **404, never 403** —
 * the no-enumeration rule: a 403 would confirm that notice id exists, which is exactly what a
 * retailer probing another mall's ids is asking.
 *
 * Reading the detail does NOT mark it read. That is a separate, explicit `POST …/read`, because
 * the app opens this endpoint to render a push preview as well as a deliberate tap, and an
 * operator reading "12 of 40 stores have seen it" must be counting people, not prefetches.
 */
class ShowAnnouncementController extends ApiController
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;

        $announcement = Announcement::query()
            ->liveFor($tenant)
            ->with([
                'recipients' => fn ($q) => $q->where('tenant_id', $tenant->getKey()),
                'asset:id,code,name',
                'media',
            ])
            ->findOrFail($id);

        return $this->ok(AnnouncementResource::make($announcement)->resolve());
    }
}
