<?php

namespace App\Services\MarketingPost;

use App\Models\MarketingPost;
use App\Models\User;
use App\Notifications\MarketingPostReviewedNotification;
use App\Support\OpsLog;
use DomainException;

/**
 * The operator clears a retailer's submission — it goes live.
 *
 * Publication itself is NOT reimplemented here: it delegates to
 * {@see PublishMarketingPostService} so the coherence checks (window ordering, artwork present,
 * not already over) are the same set on both routes to a live card. What this class adds is the
 * queue's own rules — only a pending post can be approved — and telling the retailer.
 */
class ApproveMarketingPostService
{
    public function __construct(private readonly PublishMarketingPostService $publisher) {}

    public function handle(MarketingPost $post, User $reviewer): MarketingPost
    {
        if (! $post->isAwaitingReview()) {
            throw new DomainException(__('admin.errors.marketing_post_not_pending'));
        }

        $published = $this->publisher->handle($post, $reviewer);

        $this->notifyTenant($published);

        return $published;
    }

    /**
     * Tell the retailer their offer is live. Best-effort and isolated: a notification failure must
     * not roll back an approval the operator already saw succeed — the post being live is the
     * outcome that matters, and a silent un-approve is far worse than an unsent bell row.
     */
    private function notifyTenant(MarketingPost $post): void
    {
        if (! $post->isTenantAuthored() || $post->tenant === null) {
            return;
        }

        try {
            $post->tenant->notifyPortal(new MarketingPostReviewedNotification($post));
        } catch (\Throwable $e) {
            OpsLog::warning('marketing_post.review_notice_failed', [
                'marketing_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
