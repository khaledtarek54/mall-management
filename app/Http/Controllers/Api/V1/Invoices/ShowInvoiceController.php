<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\InvoiceResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/invoices/{id} — full invoice detail with line items.
 *
 * Scoped through the tenant relationship: an id belonging to another tenant
 * resolves to 404 (not 403) so we don't confirm the row exists.
 */
class ShowInvoiceController extends ApiController
{
    public function __invoke(Request $request, int $id): InvoiceResource
    {
        $invoice = $request->user()->invoices()
            ->visibleToTenant()
            ->with(['items', 'lease.unit.asset', 'receivedPayments', 'writeOffs'])
            ->findOrFail($id);

        return new InvoiceResource($invoice);
    }
}
