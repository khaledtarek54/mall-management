<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
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
            // How the payment was taken: payment_link / mobile_api / portal / admin.
            'channel' => $this->channel,
            // When the captured-payment receipt fired (null until captured).
            'receipt_at' => optional($this->receipt_notified_at)->toIso8601String(),

            // **The references a tenant needs to chase their own money**, all four rendered on the
            // portal's payment view and none of them on the wire. A tenant who paid by CHEQUE could
            // not see which cheque the mall had recorded — the single most common "did you get it?"
            // call — and one who paid by CARD had no gateway reference to quote to their own bank
            // when a charge was queried. `notes` is the operator's line about this receipt, which
            // is often where "credited against August, not September" is written down.
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'cheque_number' => $this->cheque_number,
            'cheque_clearance_date' => optional($this->cheque_clearance_date)->toDateString(),
            'notes' => $this->notes,

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
