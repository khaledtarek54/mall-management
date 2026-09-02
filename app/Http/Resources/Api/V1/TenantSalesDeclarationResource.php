<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TenantSalesDeclaration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantSalesDeclaration
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
            // **Null while `declared_sales` is null, for the same reason.** The column itself is
            // NOT NULL default 0, so casting it unconditionally shipped a pre-review declaration
            // as `0` — indistinguishable on the wire from a REVIEWED period that came in below the
            // threshold and genuinely owes 0.00. Those are opposite facts ("nobody has looked at
            // this yet" vs "we looked, and nothing is due"), and the client rendered them the same.
            //
            // Keyed off `declared_sales` rather than `isLocked()` because that is the input this
            // figure is derived FROM: a rent computed over a turnover nobody has entered is not a
            // small number, it is not an answer yet. Nothing else reads this resource, and the
            // column is untouched — every internal consumer still gets its `(float)` 0.
            'calculated_percentage_rent' => $this->declared_sales !== null
                ? (float) $this->calculated_percentage_rent
                : null,
            'status' => $this->status,
            'is_locked' => $this->isLocked(),
            'declared_at' => optional($this->declared_at)->toIso8601String(),
            'locked_at' => optional($this->locked_at)->toIso8601String(),
            'lease' => $this->whenLoaded('lease', fn () => [
                'id' => $this->lease->id,
                'reference' => $this->lease->reference,
                // The shop the turnover is for. The portal shows it on both the list and the
                // detail; a tenant trading from two units could not tell their declarations apart
                // in the app, and a percentage-rent lease is exactly the kind that has two.
                'unit' => $this->lease->relationLoaded('unit') && $this->lease->unit ? [
                    'id' => (int) $this->lease->unit->id,
                    'code' => $this->lease->unit->code,
                ] : null,
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
