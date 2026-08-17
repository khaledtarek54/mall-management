<?php

namespace App\Services\MarketingPost;

use App\Console\Commands\ExpireMarketingPostsCommand;
use App\Models\MarketingPost;
use App\Models\User;
use App\Support\OpsLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Take a post off the feed — the retirement path, and the reason a marketing post is not in
 * `DeletionPolicy::NEVER_DELETABLE` yet also should almost never be deleted.
 *
 * Archiving keeps the campaign in the register with its engagement counters intact, which is what
 * makes "which of last Ramadan's offers actually worked" answerable a year later. Deleting throws
 * that away. Both are possible; this is the one an operator should reach for, and the admin UI
 * offers it far more prominently than delete.
 *
 * Also the sweep's action: {@see ExpireMarketingPostsCommand} archives posts
 * whose window has closed, so the feed empties itself without anyone remembering to.
 */
class ArchiveMarketingPostService
{
    public function handle(MarketingPost $post, ?User $actor = null, string $reason = 'manual'): MarketingPost
    {
        return DB::transaction(function () use ($post, $actor, $reason) {
            /** @var MarketingPost $fresh */
            $fresh = MarketingPost::query()->lockForUpdate()->findOrFail($post->getKey());

            if ($fresh->status === MarketingPost::STATUS_ARCHIVED) {
                return $fresh; // Idempotent — the sweep re-running, or two operators at once.
            }

            if ($fresh->status === MarketingPost::STATUS_PENDING) {
                // A submission waiting on review is the retailer's, not ours to file away; it is
                // approved or rejected (with a reason). Archiving it would strand them with no
                // verdict and no notification.
                throw new DomainException(__('admin.errors.marketing_post_pending_not_archivable'));
            }

            $fresh->status = MarketingPost::STATUS_ARCHIVED;
            $fresh->save();

            OpsLog::info('marketing_post.archived', [
                'marketing_post_id' => $fresh->id,
                'asset_id' => $fresh->asset_id,
                'reason' => $reason,
                'by_user_id' => $actor?->getKey(),
            ]);

            return $fresh;
        });
    }
}
