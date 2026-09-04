<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\PaymentResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/payments/{id} — payment detail with allocation breakdown.
 * Cross-tenant ids resolve to 404 via the relationship scope.
 */
class ShowPaymentController extends ApiController
{
    public function __invoke(Request $request, int $id): PaymentResource
    {
        $payment = $request->user()->tenant->payments()
            ->with('invoices')
            ->findOrFail($id);

        return new PaymentResource($payment);
    }
}
