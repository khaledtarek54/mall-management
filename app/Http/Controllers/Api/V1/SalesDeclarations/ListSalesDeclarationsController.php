<?php

namespace App\Http\Controllers\Api\V1\SalesDeclarations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantSalesDeclarationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/sales-declarations — the tenant's declarations across all
 * their leases (reached through the leases table), newest period first.
 */
class ListSalesDeclarationsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->salesDeclarations()
            ->with(['lease.unit', 'media'])
            ->orderByDesc('period_start');

        if ($status = $request->query('status')) {
            $query->where('tenant_sales_declarations.status', $status);
        }

        return TenantSalesDeclarationResource::collection($query->paginate($this->perPage($request)));
    }
}
