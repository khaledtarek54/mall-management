<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TenantRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantRequest
 */
class MaintenanceRequestResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'request_type' => $this->request_type?->value,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            // The type's sub-category (electrical, parking, lease_copy…); null
            // for types that have none (inquiry, billing query).
            'category' => $this->category,
            'channel' => $this->channel,
            'is_open' => $this->isOpen(),
            'is_overdue' => $this->isOverdue(),
            // Whether the tenant may still cancel — true only before staff
            // start work. Mirrors the cancel endpoint's guard so the app can
            // show/hide the button without a round-trip.
            'can_cancel' => in_array($this->status, ['submitted', 'acknowledged'], true),
            // Whether the tenant can submit a satisfaction rating — true once the
            // request is resolved/closed. Mirrors the rate endpoint's guard.
            'can_rate' => in_array($this->status, \App\Services\TenantRequestService::RATEABLE, true),
            'csat_rating' => $this->csat_rating,
            'csat_comment' => $this->csat_comment,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'acknowledged_at' => optional($this->acknowledged_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'target_resolution_at' => optional($this->target_resolution_at)->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'floor' => $this->unit->floor,
            ] : null),
            'comments' => MaintenanceRequestCommentResource::collection($this->whenLoaded('comments')),
            // Attachments uploaded by tenant or staff (Spatie media library,
            // `attachments` collection). Absolute URLs so the app can render
            // images / open PDFs directly. Only images + PDF are accepted on
            // upload, so the app never receives a type it can't preview.
            'attachments' => $this->whenLoaded('media', fn () => $this->getMedia('attachments')
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    // Authenticated, tenant-scoped stream — NOT a public URL (H2).
                    'url' => route('api.v1.me.maintenance.attachment', ['id' => $this->id, 'media' => $media->id]),
                ])
                ->values()),
        ];
    }
}
