<?php

namespace App\Notifications;

use App\Models\MarketingPost;
use App\Services\MarketingPost\RejectMarketingPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a retailer the verdict on the offer they submitted — approved and live, or rejected with
 * the reason. One notification for both outcomes, because to the retailer they are the same
 * event ("the mall looked at my offer") and two classes would only duplicate the routing.
 *
 * Bell + push, never email — same channel choice as {@see AnnouncementNotification}. The rejection
 * REASON is carried in the payload, not just the fact: a retailer told only "rejected" resubmits
 * the same artwork, which is the loop {@see RejectMarketingPostService}
 * exists to prevent.
 */
class MarketingPostReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(public MarketingPost $post) {}

    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->post->isPublished();

        return [
            'type' => 'marketing_post_reviewed',
            'marketing_post_id' => $this->post->id,
            'status' => $this->post->status,
            'title' => $approved
                ? __('api.marketing_post_approved_title')
                : __('api.marketing_post_rejected_title'),
            'body' => $approved
                ? __('api.marketing_post_approved_body', ['title' => $this->post->title])
                : __('api.marketing_post_rejected_body', [
                    'title' => $this->post->title,
                    'reason' => $this->post->review_notes ?: '—',
                ]),
            'icon' => $approved ? 'heroicon-o-check-badge' : 'heroicon-o-x-circle',
            'color' => $approved ? 'success' : 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
