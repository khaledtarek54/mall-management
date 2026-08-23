<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
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

        // ETA (Egyptian Tax Authority) e-invoice references — `eta_status`, `eta_submission_id`
        // and `eta_long_id`, which let the app show a "tax-registered" badge once the portal has
        // accepted the invoice — were REMOVED here on 2026-08-22 with the module-16 freeze
        // (`App\Support\Modules::FROZEN`). Nothing files an invoice any more, so the three keys could
        // only ever be null: a value the app would reasonably read as "not filed yet" rather than
        // "this system does not file". Omitted rather than nulled for exactly that reason.
        //
        // **Not a runtime gate, deliberately, and this is the one place the pattern differs.**
        // `docs/api/openapi.json` is generated from this method by Scramble, and both gated forms
        // corrupt it: a conditional spread becomes a property with an EMPTY NAME inside an
        // `anyOf`, and a post-return `if` becomes three REQUIRED properties the endpoint does not
        // send. Either way the mobile team's codegen is handed a contract that is not true. A
        // generated spec has to describe what the endpoint returns, so the keys are simply gone.
        //
        // Restoring them is part of the unfreeze checklist in docs/modules/16-eta-einvoicing.md,
        // and `EtaIsFrozenAndInvisibleTest` asserts their absence — so it turns red when the
        // freeze lifts, which is the reminder.

        return $payload;
    }
}
