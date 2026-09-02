<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CamAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tenant's own share of a year's common-area cost.
 *
 * **CAM had no API surface at all** — a whole portal resource (list, detail and a statement PDF)
 * with nothing opposite it — while the annual reconciliation puts `cam_recovery` and
 * `cam_admin_fee` lines straight onto the invoice. So the app showed a large, once-a-year charge
 * with no way to see the pool, the share, the estimates already paid, or the statement that
 * explains it. Every one of those became a telephone call.
 *
 * The three figures are one subtraction and the client must not do it itself: `allocated_amount` is
 * this party's share of the pool, `estimated_paid` is what they were billed monthly on account
 * across the year, and `true_up_amount` is the difference — POSITIVE means they owe more, NEGATIVE
 * means a credit is coming back. `true_up_amount` is stored rather than derived here for the same
 * reason the income statement's variance column is derived from its own two columns and never
 * queried twice: a figure the tenant can re-compute from the two beside it must equal the one the
 * operator actually billed.
 *
 * `unit` reads from EITHER parent. A CAM allocation belongs to a lease **or** to a unit ownership —
 * a unit owner is a participant in his own right — and resolving through `lease` alone is precisely
 * what left an owner billed a true-up whose basis he could not see.
 *
 * @mixin CamAllocation
 */
class CamAllocationResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'status' => $this->status,

            'period_year' => $this->whenLoaded('pool', fn () => (int) $this->pool->period_year),
            // What the mall actually spent on the common areas that year — the denominator the
            // share is a percentage OF. Without it "your share is 1.8%" is a number the tenant
            // cannot check anything against.
            'total_actual_expense' => $this->whenLoaded('pool', fn () => (float) $this->pool->total_actual_expense),

            'pro_rata_share_pct' => (float) $this->pro_rata_share_pct,
            'allocated_amount' => (float) $this->allocated_amount,
            'estimated_paid' => (float) $this->estimated_paid,
            // Positive: the tenant owes the difference. Negative: it comes back as a credit note.
            'true_up_amount' => (float) $this->true_up_amount,
            'currency' => 'EGP',

            'property' => $this->whenLoaded('pool', fn () => $this->pool->relationLoaded('asset') && $this->pool->asset ? [
                'id' => (int) $this->pool->asset->id,
                'code' => $this->pool->asset->code,
                'name' => $this->pool->asset->name,
            ] : null),

            // The shop this share was calculated for, from whichever agreement carries it.
            'unit' => $this->unitPayload(),

            // Which agreement it is — so the app can label an owner's assessment as such rather
            // than showing a blank lease reference, which is what the portal's own View page does
            // today (a known open row).
            'agreement' => $this->lease
                ? ['kind' => 'lease', 'reference' => $this->lease->reference]
                : ($this->unitOwnership ? ['kind' => 'ownership', 'reference' => $this->unitOwnership->reference] : null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function unitPayload(): ?array
    {
        $unit = $this->lease?->unit ?? $this->unitOwnership?->unit;

        if ($unit === null) {
            return null;
        }

        return [
            'id' => (int) $unit->id,
            'code' => $unit->code,
            // `floors.code` — a scalar, never the Floor object. Same rule as LeaseResource.
            'floor' => $unit->floor?->code,
        ];
    }
}
