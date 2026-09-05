<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CreditNoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a credit note — WHAT the operator credited.
 *
 * The portal's credit-note view has rendered these since it shipped and the API sent only the
 * totals, so a tenant reading the app saw *"CN-AW-0003 · 1,500.00 · adjustment"* and could not tell
 * which charge had been credited. `reason` is a one-word classification, not an explanation.
 *
 * Shaped exactly like {@see InvoiceItemResource} on purpose: a credit line and an invoice line
 * answer the same question from opposite sides, and a client that renders one should render the
 * other with the same widget.
 *
 * @mixin CreditNoteItem
 */
class CreditNoteItemResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Worded for the CALLER — see `InvoiceItemResource`.
            'description' => $this->resource->narrative(),
            'amount' => (float) $this->amount,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
        ];
    }
}
