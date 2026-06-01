<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'gateway' => $this->gateway,

            // Per-invoice allocation. A single payment can clear several
            // invoices; each row carries how much of this payment landed on
            // that invoice (the invoice_payment.allocated_amount pivot value).
            'allocations' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($invoice) => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'allocated_amount' => (float) $invoice->pivot->allocated_amount,
            ])->values()),
        ];
    }
}
