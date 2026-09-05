<?php

namespace App\Http\Resources\Api\V1;

use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Worded for the CALLER (UX-30). `SetApiLocale` has already resolved
            // `Accept-Language`, and on this surface the caller is the recipient.
            'description' => $this->resource->narrative(),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'vat_rate' => (float) $this->vat_rate,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,

            // **WHICH line is under argument, and what was said.** `invoices.status` can be
            // `disputed`, so the app could see that SOMETHING on the invoice was being argued about
            // and never which line or why — while the portal's invoice view has rendered
            // `disputed_reason` under the line all along.
            //
            // The tenant cannot raise a dispute on any surface: `DisputeInvoiceItemService` is
            // called only from the admin invoice actions, i.e. an operator recording what the
            // tenant said by telephone. That is deliberate, so the app's route for a billing
            // argument is `POST /me/requests` with `requestType: billing` — and this pair is how it
            // shows the tenant that the argument was heard and recorded.
            'disputed_at' => optional($this->disputed_at)->toIso8601String(),
            'disputed_reason' => $this->disputed_reason,
        ];
    }
}
