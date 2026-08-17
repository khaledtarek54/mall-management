<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditNote
 */
class CreditNoteResource extends JsonResource
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
            'reason' => $this->reason,
            'subtotal' => (float) $this->subtotal,
            'vat_amount' => (float) $this->vat_amount,
            'total' => (float) $this->total,
            'applied_amount' => (float) $this->applied_amount,
            'balance' => (float) $this->balance,
            'currency' => $this->currency,
            'issue_date' => optional($this->issue_date)->toDateString(),
            'applied_at' => optional($this->applied_at)->toIso8601String(),

            // The invoice this credit was raised against (null for standalone
            // tenant-level credits). Only present when eager-loaded.
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'number' => $this->invoice->number,
            ] : null),
        ];
    }
}
