<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/invoices — paginated list of the tenant's own invoices.
 *
 * Filters: status, period_from / period_until (against issue_date).
 * Always scoped to the authenticated tenant — a client-supplied tenant_id is
 * never trusted.
 */
class ListInvoicesController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->tenant->invoices()
            ->visibleToTenant()
            ->with([
                'lease.unit',
                // An owner's assessment has no lease — this is where its shop comes from.
                'unitOwnership.unit.floor',
                'receivedPayments',
                // `InvoiceResource` calls `isPayable()`, which nets prior write-offs — one
                // aggregate per row without this, and per_page goes to 100.
                'writeOffs',
            ])
            ->latest('issue_date');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->date('period_from')) {
            $query->whereDate('issue_date', '>=', $from);
        }

        if ($until = $request->date('period_until')) {
            $query->whereDate('issue_date', '<=', $until);
        }

        return InvoiceResource::collection($query->paginate($this->perPage($request)));
    }
}
