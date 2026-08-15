<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'issue_date' => optional($this->issue_date)->toDateString(),
            'due_date' => optional($this->due_date)->toDateString(),
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'subtotal' => (float) $this->subtotal,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'paid_amount' => (float) $this->paid_amount,
            // Portion of paid_amount covered by applied credit notes (vs cash).
            'credit_applied_amount' => (float) $this->credit_applied_amount,
            'balance' => (float) $this->balance,
            // **When the money arrived.** The payment-confirmation screen used to stamp the
            // DEVICE clock next to a server-polled amount and balance — on the screen tenants
            // screenshot as proof of payment — so the app dropped the line rather than print a
            // guess. This is the server's own answer: the date of the latest RECEIVED payment
            // allocated to this invoice (`receivedPayments`, the same predicate the AR uses), or
            // null while nothing has been captured.
            'paid_at' => optional(
                $this->receivedPayments->max('payment_date')
            )?->toIso8601String(),
            'currency' => $this->currency,
            'is_overdue' => $this->isOverdue(),
            'days_overdue' => $this->daysOverdue(),

            // Shareable public pay link (no login) — null once nothing is owed.
            // Lets the app surface a "share payment link" alongside in-app pay.
            'payment_link_url' => $this->isPayable() ? $this->paymentLinkUrl() : null,

            // ETA (Egyptian Tax Authority) e-invoice references — present once
            // the invoice has been accepted by the ETA portal. Useful for the
            // app to show a "tax-registered" badge.
            'eta_status' => $this->eta_status,
            'eta_submission_id' => $this->eta_submission_id,
            'eta_long_id' => $this->eta_long_id,

            // Relations — only present on the detail endpoint (eager-loaded).
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'lease' => $this->whenLoaded('lease', fn () => [
                'id' => $this->lease->id,
                'reference' => $this->lease->reference,
                'unit' => $this->lease->relationLoaded('unit') && $this->lease->unit ? [
                    'id' => $this->lease->unit->id,
                    'code' => $this->lease->unit->code,
                    'floor' => $this->lease->unit->floor?->code,
                ] : null,
            ]),
        ];
    }
}
