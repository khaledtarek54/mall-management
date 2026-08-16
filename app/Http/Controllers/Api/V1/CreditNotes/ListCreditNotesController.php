<?php

namespace App\Http\Controllers\Api\V1\CreditNotes;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\CreditNoteResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/me/credit-notes — the tenant's credit notes, newest first.
 * Optional ?status= filter (issued / applied / void). Scoped to the tenant.
 */
class ListCreditNotesController extends ApiController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->creditNotes()
            ->visibleToTenant()
            ->with('invoice')
            ->latest('issue_date');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return CreditNoteResource::collection($query->paginate($this->perPage($request)));
    }
}
