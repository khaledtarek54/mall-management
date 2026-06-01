<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/payments — paginated list of the tenant's payments, newest
 * first, with per-invoice allocations. Scoped to the authenticated tenant.
 */
class ListPaymentsController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->payments()
            ->with('invoices')
            ->latest('payment_date');

        if ($method = $request->query('method')) {
            $query->where('method', $method);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return PaymentResource::collection($query->paginate($this->perPage($request)));
    }
}
