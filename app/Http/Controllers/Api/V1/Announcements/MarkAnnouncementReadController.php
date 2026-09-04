<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Services\Announcements\MarkAnnouncementReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/announcements/{id}/read — stamp this tenant's read receipt.
 *
 * The receipt is the point of the whole recipient table: "has that store seen the notice" is the
 * question an operator asks the morning after a blast, and until now the honest answer was that
 * nobody could know. A notice the caller was never sent 404s (no enumeration), and re-reading is
 * idempotent — the FIRST read is what stays recorded.
 *
 * The mobile API authenticates the **Tenant company**, so there is no `TenantUser` to attribute
 * the read to; that column stays null here and is filled only by the web portal, which does know
 * which login is looking.
 */
class MarkAnnouncementReadController extends ApiController
{
    public function __invoke(Request $request, int $id, MarkAnnouncementReadAction $action): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;

        $announcement = Announcement::query()->liveFor($tenant)->findOrFail($id);

        $recipient = $action->handle($announcement, $tenant);

        return $this->ok([
            'id' => (int) $announcement->id,
            'read' => $recipient?->read_at !== null,
            'read_at' => $recipient?->read_at?->toIso8601String(),
        ]);
    }
}
