<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TenantSalesDeclaration
 */
class TenantSalesDeclarationResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'period_label' => $this->periodLabel(),
            'declared_sales' => (float) $this->declared_sales,
            'calculated_percentage_rent' => (float) $this->calculated_percentage_rent,
            'status' => $this->status,
            'is_locked' => $this->isLocked(),
            'declared_at' => optional($this->declared_at)->toIso8601String(),
            'locked_at' => optional($this->locked_at)->toIso8601String(),
            'lease' => $this->whenLoaded('lease', fn () => [
                'id' => $this->lease->id,
                'reference' => $this->lease->reference,
            ]),
        ];
    }
}
