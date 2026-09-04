<?php

namespace App\Http\Controllers\Api\V1\SalesDeclarations;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\TenantSalesDeclarationResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/sales-declarations/{id} — declaration detail. Scoped through
 * the tenant's leases, so another tenant's declaration resolves to 404.
 */
class ShowSalesDeclarationController extends ApiController
{
    public function __invoke(Request $request, int $id): TenantSalesDeclarationResource
    {
        $declaration = $request->user()->tenant->salesDeclarations()
            ->with(['lease.unit', 'media'])
            ->findOrFail($id);

        return new TenantSalesDeclarationResource($declaration);
    }
}
