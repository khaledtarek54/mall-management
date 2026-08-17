<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\PublicFeed\PublicMarketingPostResource;
use App\Models\MarketingPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marketing post as its OWN retailer sees it — everything the public resource shows, plus the
 * workflow state that belongs to them.
 *
 * The extra fields over {@see PublicMarketingPostResource}
 * are exactly the ones a submitter needs and a stranger must not have: what state their
 * submission is in, whether they can still edit it, and — the important one — WHY it was
 * rejected. This is a separate class rather than a conditional branch inside the public resource
 * for the reason given there: an audience widened by a careless edit does not look like a mistake
 * in review.
 *
 * @mixin MarketingPost
 */
class MarketingPostResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => (string) $this->type,
            'status' => (string) $this->status,
            'audience' => (string) $this->audience,

            'title' => (string) $this->title,
            'title_ar' => $this->title_ar,
            'summary' => $this->summary,
            'summary_ar' => $this->summary_ar,
            'body' => $this->body,
            'body_ar' => $this->body_ar,
            'terms' => $this->terms,
            'terms_ar' => $this->terms_ar,
            'discount_label' => $this->discount_label,
            'discount_label_ar' => $this->discount_label_ar,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'is_featured' => (bool) $this->is_featured,

            'cta_label' => $this->cta_label,
            'cta_label_ar' => $this->cta_label_ar,
            'cta_url' => $this->cta_url,

            'hero_url' => $this->heroUrl(),
            'gallery_urls' => $this->galleryUrls(),

            // ---- Workflow. The retailer's own view of where their submission stands.
            'is_editable' => $this->isEditableByTenant(),
            'is_awaiting_review' => $this->isAwaitingReview(),
            // The rejection reason. Sending this is the entire reason rejection demands one — a
            // retailer told only "rejected" resubmits the same artwork.
            'review_notes' => $this->review_notes,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),

            // Their own campaign's numbers. Indicative, not audited — see RecordPostClickController.
            'view_count' => (int) $this->view_count,
            'click_count' => (int) $this->click_count,

            'property' => $this->whenLoaded('asset', fn () => [
                'code' => (string) $this->asset->code,
                'name' => (string) $this->asset->name,
            ]),
        ];
    }
}
