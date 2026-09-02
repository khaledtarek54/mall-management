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
            // **What the tenant will actually be charged, which is not always `balance`.**
            //
            // A write-off deliberately leaves `balance` standing — it is not one of the four
            // settlement channels — so `payableAmount()` is `balance` net of anything forgiven, and
            // it is what EVERY money path already uses: the Paymob session, the pivot allocation,
            // the session-reuse comparison, the demo capture and the public pay page. It was on
            // none of them until 2026-09-01 and on this payload until now, so the app could print
            // 10,000 from `balance`, open checkout, and have the tenant charged 4,000 with nothing
            // on screen explaining the difference.
            //
            // `balance` STAYS, and the two are different questions: `balance` is what was owed and
            // is what an accountant reconciles against the invoice; this is what may still be
            // collected. Show this one on a Pay button.
            'payable_amount' => $this->payableAmount(),
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
            // The operator's note ON the document. Rendered on the portal's invoice view since it
            // shipped and never put on the wire, so a mobile tenant read a different invoice from
            // the one their colleague read on the web.
            'notes' => $this->notes,
            'is_overdue' => $this->isOverdue(),
            'days_overdue' => $this->daysOverdue(),

            // Shareable public pay link (no login) — null once nothing is owed.
            // Lets the app surface a "share payment link" alongside in-app pay.
            'payment_link_url' => $this->isPayable() ? $this->paymentLinkUrl() : null,

            // Relations — only present on the detail endpoint (eager-loaded).
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            // The shop this document is FOR, through whichever agreement raised it. The client can
            // reach it through `lease` or `unit_ownership` below, and should not have to: the
            // portal learnt the same lesson the hard way, where reading `lease.unit.code` directly
            // "rendered every owner assessment with a blank unit". One accessor, one answer.
            'unit_code' => $this->unitCode(),

            // **An owner's assessment carries no lease**, and until now it carried nothing else
            // either: `invoices.lease_id` is nullable and `unit_ownership_id` exists precisely so a
            // unit owner with no tenancy can be billed. `whenLoaded` guards the null, so nothing
            // crashed — the invoice simply rendered with no unit, no floor and no property, and an
            // owner of three shops saw three identical-looking bills.
            'unit_ownership' => $this->whenLoaded('unitOwnership', fn () => [
                'id' => (int) $this->unitOwnership->id,
                'reference' => $this->unitOwnership->reference,
                'unit' => $this->unitOwnership->relationLoaded('unit') && $this->unitOwnership->unit ? [
                    'id' => (int) $this->unitOwnership->unit->id,
                    'code' => $this->unitOwnership->unit->code,
                    'floor' => $this->unitOwnership->unit->floor?->code,
                ] : null,
            ]),

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
