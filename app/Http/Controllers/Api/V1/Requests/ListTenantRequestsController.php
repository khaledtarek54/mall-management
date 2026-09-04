<?php

namespace App\Http\Controllers\Api\V1\Requests;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/requests — paginated list, newest first.
 */
class ListTenantRequestsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->tenant->tenantRequests()
            ->with(['unit', 'media'])
            ->latest('submitted_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return TenantRequestResource::collection($query->paginate($this->perPage($request)));
    }
}
