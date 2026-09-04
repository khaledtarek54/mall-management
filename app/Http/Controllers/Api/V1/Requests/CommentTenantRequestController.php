<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Actions\Api\V1\Requests\AddTenantRequestCommentAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Requests\CommentTenantRequestRequest;
use App\Http\Resources\Api\V1\TenantRequestCommentResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/requests/{id}/comments — add a public comment.
 */
class CommentTenantRequestController extends ApiController
{
    public function __invoke(
        CommentTenantRequestRequest $request,
        int $id,
        AddTenantRequestCommentAction $action
    ): JsonResponse {
        $tenant = $request->user()->tenant;
        $tenantRequest = $tenant->tenantRequests()->findOrFail($id);

        $comment = $action->handle($tenantRequest, $tenant, $request->input('body'));

        return $this->ok(
            new TenantRequestCommentResource($comment),
            __('api.request_comment_added'),
            201,
        );
    }
}
