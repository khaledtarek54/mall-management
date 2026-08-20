<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantRequest
 */
class TenantRequestResource extends JsonResource
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
            'can_rate' => in_array($this->status, TenantRequestService::RATEABLE, true),
            // Whether the tenant may accept or dispute the resolution — true only while
            // `resolved`, which is NARROWER than can_rate: rating is feedback after the fact and
            // stays open on a closed request, confirming is a control before closure. Mirrors the
            // confirm/dispute endpoints' guard so the app shows both buttons or neither.
            'can_confirm' => in_array($this->status, TenantRequestService::CONFIRMABLE, true),
            // Null on a closed request means the operator or the auto-close timer shut it, not the
            // tenant — so a client can say "you confirmed this" only when they actually did.
            'confirmed_at' => optional($this->confirmed_at)->toIso8601String(),
            'csat_rating' => $this->csat_rating,
            'csat_comment' => $this->csat_comment,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'acknowledged_at' => optional($this->acknowledged_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'target_resolution_at' => optional($this->target_resolution_at)->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,

            // ---- The ANSWER, for requests that asked for something.
            //
            // Until 2026-08-15 the seven statuses were all a client had, so the app inferred an
            // outcome from the lifecycle — `resolved`/`closed` → "Approved" — and a staff
            // REJECTION read to the tenant as an approval on the card they show a guard.
            //
            // `requires_decision` is shipped so the client can tell the two nulls apart: a
            // maintenance ticket has no answer because it was never a question, while a permit
            // with a null decision is a row that predates this field. **Neither may render as
            // approved.**
            'requires_decision' => $this->requiresDecision(),
            'decision' => $this->decision,
            // Why it was refused. The whole reason a rejection demands one.
            'decision_reason' => $this->decision_reason,
            'decided_at' => optional($this->decided_at)->toIso8601String(),

            // ---- The permit's window. These columns have existed since 2026-07-18 and were
            // operator-editable in admin, but were never put on the wire — so the app derived a
            // validity from what the TENANT typed while the mall's authoritative answer sat
            // unread. `valid_*` is the permit's own validity; `scheduled_*` is when the work or
            // visit is booked for.
            'valid_from' => optional($this->valid_from)->toDateString(),
            'valid_to' => optional($this->valid_to)->toDateString(),
            'scheduled_from' => optional($this->scheduled_from)->toIso8601String(),
            'scheduled_to' => optional($this->scheduled_to)->toIso8601String(),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'floor' => $this->unit->floor?->code,
            ] : null),
            'comments' => TenantRequestCommentResource::collection($this->whenLoaded('comments')),
            // Attachments uploaded by tenant or staff (Spatie media library,
            // `attachments` collection). Absolute URLs so the app can render
            // images / open PDFs directly. Only images + PDF are accepted on
            // upload, so the app never receives a type it can't preview.
            'attachments' => $this->whenLoaded('media', fn () => $this->getMedia('attachments')
                ->map(fn ($media) => [
                    // id + size are cast explicitly: the media model's props are
                    // untyped, so Scramble published them as `string` while the
                    // wire carried ints — the client decoded `as String` and
                    // threw, taking the whole request list down with it.
                    'id' => (int) $media->id,
                    'name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => (int) $media->size,
                    // Authenticated, tenant-scoped stream — NOT a public URL (H2).
                    'url' => route('api.v1.me.requests.attachment', ['id' => $this->id, 'media' => $media->id]),
                ])
                ->values()),
        ];
    }
}
