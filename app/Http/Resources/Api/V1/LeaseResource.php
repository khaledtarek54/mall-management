<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RentableItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Lease
 */
class LeaseResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'commencement_date' => optional($this->commencement_date)->toDateString(),
            'expiry_date' => optional($this->expiry_date)->toDateString(),
            'term_months' => $this->term_months,
            'base_rent_monthly' => (float) $this->base_rent_monthly,
            'service_charge_monthly' => (float) $this->service_charge_monthly,
            'total_monthly_amount' => $this->totalMonthlyAmount(),
            'currency' => $this->currency,
            // Parking bays let alongside the premises. Already modelled as `rentableItems` (a
            // bay is not lettable AREA, which is why the two relations are kept apart) — it was
            // simply never published, so the app omitted its parking-allocation card rather than
            // invent a number.
            'parking_spots' => $this->whenLoaded(
                'rentableItems',
                fn () => $this->rentableItems
                    ->where('type', RentableItem::TYPE_PARKING)
                    ->count(),
            ),
            'has_percentage_rent' => (bool) $this->has_percentage_rent,
            'percentage_rent_rate' => $this->has_percentage_rent ? (float) $this->percentage_rent_rate : null,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                // `floors.code` — a STRING like "G" or "1", which is what this field has always
                // carried. `units.floor` was a string column until it was replaced by the Floor
                // register; `$unit->floor` is now the RELATION, so emitting it raw put a whole
                // Floor object on the wire where the mobile client expects a scalar.
                'floor' => $this->unit->floor?->code,
                'category' => $this->unit->category,
                'area_sqm' => (float) $this->unit->area_sqm,
                'asset' => $this->unit->relationLoaded('asset') && $this->unit->asset ? [
                    'id' => $this->unit->asset->id,
                    'name' => $this->unit->asset->name,
                    'code' => $this->unit->asset->code,
                ] : null,
            ]),
        ];
    }
}
