<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UnitOwnership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop the signed-in party OWNS, rather than rents.
 *
 * **Module 37's rule is that a unit owner IS a `tenants` row** — same credentials, same portal,
 * same invoices — and every other surface treats them as one. This API did not: the word
 * `unitOwnership` appeared nowhere under `app/Http/{Controllers,Resources,Actions}/Api`. So an
 * owner could sign in (to `data: []`, having no lease), be billed a monthly assessment by
 * `billing:run-assessments`, and read that invoice with `lease: null` — no unit, no floor, no
 * property. An owner of three shops saw three identical-looking bills.
 *
 * `assessment_basis` and `participation_pct` are published because they are the WHY behind the
 * figure on the invoice: an owner assessed on AREA and one assessed on a stated participation
 * share are being charged by different rules, and neither can check the bill without knowing which.
 *
 * `management_mode` says whether the operator is letting the shop on the owner's behalf. It changes
 * what the owner should expect to see elsewhere in the app — a `let` shop generates a lease and
 * rent, a `vacant` one generates only assessments — so it is the difference between an empty
 * screen that is correct and one that looks broken.
 *
 * @mixin UnitOwnership
 */
class UnitOwnershipResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            // Backed enums — `->value`, never the case, or the wire carries an object.
            'status' => $this->status?->value,
            'tenure_type' => $this->tenure_type?->value,
            'management_mode' => $this->management_mode,

            'ownership_share_pct' => (float) $this->ownership_share_pct,
            // How the service charge is apportioned to this shop, and the stated share when the
            // basis is not area. The reason the assessment is the amount it is.
            'assessment_basis' => $this->assessment_basis,
            'participation_pct' => $this->participation_pct !== null ? (float) $this->participation_pct : null,

            'purchase_date' => optional($this->purchase_date)->toDateString(),
            // The date the shop became theirs to be assessed on — `handed_over` AND covering today
            // is the predicate the assessment run bills from and the tenant-request form resolves
            // a unit with, so it is the one an owner needs to see beside a charge.
            'handover_date' => optional($this->handover_date)->toDateString(),
            'started_at' => optional($this->started_at)->toDateString(),
            'ended_at' => optional($this->ended_at)->toDateString(),
            'currency' => $this->currency,

            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => (int) $this->unit->id,
                'code' => $this->unit->code,
                'floor' => $this->unit->floor?->code,
                'category' => $this->unit->category,
                'area_sqm' => (float) $this->unit->area_sqm,
            ]),

            'property' => $this->whenLoaded('asset', fn () => [
                'id' => (int) $this->asset->id,
                'code' => $this->asset->code,
                'name' => $this->asset->name,
            ]),
        ];
    }
}
