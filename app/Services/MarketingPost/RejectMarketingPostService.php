<?php

namespace App\Services\MarketingPost;

use App\Models\MarketingPost;
use App\Models\User;
use App\Notifications\MarketingPostReviewedNotification;
use App\Support\OpsLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The operator refuses a retailer's submission — it goes back to the retailer with a reason.
 *
 * **The reason is mandatory, and that is the whole point of the class.** A retailer told only
 * "rejected" resubmits the same artwork, the operator rejects it again, and the queue becomes a
 * loop that both sides blame on the other. Requiring the note costs one sentence at review time
 * and is the difference between a workflow and a wall.
 *
 * Rejection returns the post to the retailer's control (`rejected` is tenant-editable), so they
 * can fix it and resubmit — the post is never destroyed and the thread is never lost.
 */
class RejectMarketingPostService
{
    public function handle(MarketingPost $post, User $reviewer, string $reason): MarketingPost
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(__('admin.errors.marketing_post_reject_needs_reason'));
        }

        $rejected = DB::transaction(function () use ($post, $reviewer, $reason) {
            /** @var MarketingPost $fresh */
            $fresh = MarketingPost::query()->lockForUpdate()->findOrFail($post->getKey());

            if (! $fresh->isAwaitingReview()) {
                // Includes the case where a colleague approved it a second ago. Refusing rather
                // than un-publishing is deliberate: pulling a live offer is `archive`, a separate
                // and more visible act.
                throw new DomainException(__('admin.errors.marketing_post_not_pending'));
            }

            $fresh->status = MarketingPost::STATUS_REJECTED;
            $fresh->reviewed_by = $reviewer->getKey();
            $fresh->reviewed_at = now();
            $fresh->review_notes = $reason;
            $fresh->save();

            return $fresh;
        });

        $this->notifyTenant($rejected);

        return $rejected;
    }

    /** Best-effort, isolated — see the note on ApproveMarketingPostService::notifyTenant(). */
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
