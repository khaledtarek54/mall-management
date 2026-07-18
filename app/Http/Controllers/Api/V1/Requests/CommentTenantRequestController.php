<?php

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Actions\Api\V1\Maintenance\AddMaintenanceCommentAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Maintenance\CommentMaintenanceRequestRequest;
use App\Http\Resources\Api\V1\MaintenanceRequestCommentResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/maintenance-requests/{id}/comments — add a public comment.
 */
class CommentMaintenanceRequestController extends ApiController
{
    public function __invoke(
        CommentMaintenanceRequestRequest $request,
        int $id,
        AddMaintenanceCommentAction $action
    ): JsonResponse {
        $tenant = $request->user();
        $maintenanceRequest = $tenant->maintenanceRequests()->findOrFail($id);

        $comment = $action->handle($maintenanceRequest, $tenant, $request->input('body'));

        return $this->ok(
            new MaintenanceRequestCommentResource($comment),
            __('api.maintenance_comment_added'),
            201,
        );
    }
}
