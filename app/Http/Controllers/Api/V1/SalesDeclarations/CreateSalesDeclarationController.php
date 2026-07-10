<?php

namespace App\Http\Controllers\Api\V1\SalesDeclarations;

use App\Actions\Api\V1\Sales\CreateSalesDeclarationAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Sales\CreateSalesDeclarationRequest;
use App\Http\Resources\Api\V1\TenantSalesDeclarationResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/me/sales-declarations — submit a declaration for a
 * percentage-rent lease. The action enforces ownership, percentage-rent
 * eligibility, and one-declaration-per-period.
 */
class CreateSalesDeclarationController extends ApiController
{
    public function __invoke(
        CreateSalesDeclarationRequest $request,
        CreateSalesDeclarationAction $action
    ): JsonResponse {
        $declaration = $action->handle(
            $request->user(),
            $request->payload(),
            $request->attachments(),
        );

        return $this->ok(
            new TenantSalesDeclarationResource($declaration->load('lease', 'media')),
            __('api.sales_declaration_created'),
            201,
        );
    }
}
