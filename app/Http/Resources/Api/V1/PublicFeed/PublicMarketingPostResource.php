<?php

namespace App\Http\Resources\Api\V1\PublicFeed;

use App\Models\MarketingPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an unauthenticated shopper is allowed to see of a marketing post.
 *
 * **This is an allowlist, and it is deliberately a separate class from anything the admin or
 * tenant surfaces use.** A shared resource with `when($isAdmin, ...)` branches is one careless
 * edit away from leaking, and the leak would be invisible in review — the reviewer sees a field
 * added, not an audience widened. Here, every field a stranger can read is written out by hand,
 * so adding one is a decision someone had to type.
 *
 * Deliberately absent, and each for a reason:
 *   - `status`, `review_notes`, `reviewed_by`, `submitted_by_*` — the mall's internal workflow.
 *     `review_notes` in particular is a critique of a retailer's artwork written for the retailer.
 *   - `display_from` / `display_until` — scheduling, not a promise. The shopper is shown the
 *     VALIDITY window, which is what the card claims; broadcasting the display schedule tells
 *     competitors when a campaign was planned.
 *   - `view_count` / `click_count` — the mall's commercial performance data.
 *   - anything at all from the `tenants` row beyond {@see PublicStoreResource}'s own allowlist.
 *
 * @mixin MarketingPost
 */
class PublicMarketingPostResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => (string) $this->type,

            // Both languages always shipped, rather than one resolved server-side: the app knows
            // its own locale and can switch without a round trip, and an Arabic field that was
            // never filled falls back client-side rather than becoming a blank card.
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

            // The promise, not the schedule — see the docblock.
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'is_featured' => (bool) $this->is_featured,

            'cta_label' => $this->cta_label,
            'cta_label_ar' => $this->cta_label_ar,
            'cta_url' => $this->cta_url,

            // Cast explicitly. Scramble types an un-cast accessor as `string`, and a Dart client
            // that expects a String and receives an int throws at parse time — the mobile-contract
            // trap this project has already been bitten by.
            'hero_url' => $this->heroUrl(),
            'gallery_urls' => $this->galleryUrls(),

            'store' => $this->whenLoaded(
                'tenant',
                fn () => $this->tenant ? new PublicStoreResource($this->tenant) : null
            ),
        ];
    }
}
