<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Delivers an operator broadcast to a tenant's in-app bell + mobile devices.
 * Bell + push only — no email (informational blasts shouldn't fill inboxes).
 * The mobile inbox reads the Tenant's `database` rows; the push fans out to the
 * Tenant's registered devices via the Phase-1 FCM pipeline.
 */
class AnnouncementNotification extends Notification
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'icon' => 'heroicon-o-megaphone',
            'color' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
