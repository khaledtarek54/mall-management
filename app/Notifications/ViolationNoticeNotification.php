<?php

namespace App\Notifications;

use App\Models\Violation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Delivers a violation notice to the tenant (FR-REQ-17). Bell + push only — no
 * email — matching the operator→tenant broadcast channel choice
 * (AnnouncementNotification / AreaRequestRaisedNotification): the mobile inbox
 * reads the Tenant's `database` rows and the push fans out to registered
 * devices via the FCM pipeline. `push` derives its title/body from
 * toDatabase(), so no toPush() override is needed.
 */
class ViolationNoticeNotification extends Notification
{
    use Queueable;

    public function __construct(public Violation $violation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'violation_notice',
            'violation_id' => $this->violation->id,
            'reference' => $this->violation->reference,
            'title' => __('admin.notifications.violation_notice_title'),
            'body' => __('admin.notifications.violation_notice_body', [
                'reference' => $this->violation->reference,
                'description' => $this->violation->description,
            ]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
