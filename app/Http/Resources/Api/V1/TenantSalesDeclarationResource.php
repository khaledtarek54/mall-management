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
            // Null until staff review the attached report and enter the figure;
            // the app should show "Pending review" rather than 0.
            'declared_sales' => $this->declared_sales !== null ? (float) $this->declared_sales : null,
            'calculated_percentage_rent' => (float) $this->calculated_percentage_rent,
            'status' => $this->status,
            'is_locked' => $this->isLocked(),
            'declared_at' => optional($this->declared_at)->toIso8601String(),
            'locked_at' => optional($this->locked_at)->toIso8601String(),
            'lease' => $this->whenLoaded('lease', fn () => [
                'id' => $this->lease->id,
                'reference' => $this->lease->reference,
            ]),
            // The tenant's uploaded sales report (Spatie `sales_report`
            // collection). Absolute, authenticated, tenant-scoped stream URLs —
            // NOT public (the file can carry commercial turnover figures).
            'attachments' => $this->whenLoaded('media', fn () => $this->getMedia('sales_report')
                ->map(fn ($media) => [
                    // Cast explicitly — see the note in TenantRequestResource.
                    'id' => (int) $media->id,
                    'name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => (int) $media->size,
                    'url' => route('api.v1.me.sales.attachment', ['id' => $this->id, 'media' => $media->id]),
                ])
                ->values()),
            'has_report' => $this->whenLoaded('media', fn () => $this->getMedia('sales_report')->isNotEmpty()),
        ];
    }
}
