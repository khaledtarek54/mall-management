<?php

namespace App\Services\MarketingPost;

use App\Models\MarketingPost;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place a marketing post becomes visible to the public.
 *
 * Both routes into publication go through here — the operator composing a mall-wide post and
 * hitting Publish, and the operator approving a retailer's submission ({@see ApproveMarketingPostService},
 * which delegates). That matters because publication is where the checks live: an approve path
 * with its own copy of them is an approve path that drifts, and the drift shows up as a live card
 * with an end date in the past.
 *
 * The checks are refusals ({@see DomainException} → a toast and a redirect back, never a 500),
 * because every one of them is something the operator did that isn't allowed rather than a fault.
 */
class PublishMarketingPostService
{
    public function handle(MarketingPost $post, ?User $actor = null): MarketingPost
    {
        return DB::transaction(function () use ($post, $actor) {
            // Re-read under a lock: two reviewers clearing the same approval queue is the ordinary
            // case, not the exotic one, and the second must not re-stamp published_at (which would
            // move the post to the top of a feed ordered by it).
            /** @var MarketingPost $fresh */
            $fresh = MarketingPost::query()->lockForUpdate()->findOrFail($post->getKey());

            if ($fresh->status === MarketingPost::STATUS_PUBLISHED) {
                return $fresh; // Idempotent — someone else got there first.
            }

            $this->assertPublishable($fresh);

            $fresh->status = MarketingPost::STATUS_PUBLISHED;
            $fresh->published_at = $fresh->published_at ?? now();
            $fresh->reviewed_by = $actor?->getKey() ?? $fresh->reviewed_by;
            $fresh->reviewed_at = now();
            // A rejection reason left over from a previous round would otherwise still be sitting
            // on a live post, and the portal shows it to the tenant.
            $fresh->review_notes = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * What has to be true before shoppers see it. Deliberately narrow: this is not form
     * validation (the form does that), it is the set of things that make a PUBLISHED post
     * incoherent — the ones that would still be wrong if the row arrived from the API.
     */
    public function assertPublishable(MarketingPost $post): void
    {
        if (blank($post->title)) {
            throw new DomainException(__('admin.errors.marketing_post_needs_title'));
        }

        if ($post->starts_at && $post->ends_at && $post->ends_at->lt($post->starts_at)) {
            throw new DomainException(__('admin.errors.marketing_post_window_backwards'));
        }

        if ($post->display_from && $post->display_until && $post->display_until->lt($post->display_from)) {
            throw new DomainException(__('admin.errors.marketing_post_display_backwards'));
        }

        // Publishing something whose window already closed puts a card in the register that the
        // expiry sweep archives on its next run — the operator sees "Published ✓" and then finds
        // it archived, with nothing saying why. Refuse it and say so instead.
        if ($post->hasExpired()) {
            throw new DomainException(__('admin.errors.marketing_post_already_over'));
        }

        // An offer card is artwork first. A feed row with no image renders as a grey box in every
        // mall app there is, so it is a refusal rather than a warning — but only for the shopper
        // audience, since a tenants-only notice is read as text.
        if ($post->audience !== MarketingPost::AUDIENCE_TENANTS && $post->getFirstMedia(MarketingPost::HERO_COLLECTION) === null) {
            throw new DomainException(__('admin.errors.marketing_post_needs_hero'));
        }
    }
}
