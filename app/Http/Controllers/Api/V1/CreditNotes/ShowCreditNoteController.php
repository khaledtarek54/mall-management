<?php

namespace App\Http\Controllers\Api\V1\CreditNotes;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\CreditNoteResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/credit-notes/{id} — a single credit note belonging to the
 * tenant. Another tenant's (or a missing) id returns 404 — no cross-tenant
 * enumeration (matches the invoice/payment convention).
 */
class ShowCreditNoteController extends ApiController
{
    public function __invoke(Request $request, int $id): CreditNoteResource
    {
        $creditNote = $request->user()->creditNotes()->visibleToTenant()->with(['invoice', 'items'])->find($id);

        if (! $creditNote) {
            abort(404);
        }

        return new CreditNoteResource($creditNote);
    }
}
