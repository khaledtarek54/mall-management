<?php

namespace App\Notifications;

use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Permits to work left open past their window, at one property.
 *
 * Mailed as well as belled, on the test the rest of the system applies: does missing it cost
 * something on a clock? Here it costs more than money — an unclosed hot-work permit is the record
 * an insurer asks for after a fire, and the window it refers to has already passed.
 *
 * Counted per property rather than one notification per permit: four open permits in a plant room
 * is one situation for one person to walk down and resolve, and four bells is how the fifth gets
 * ignored.
 */
class WorkPermitOverdueNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public int $count, public int $assetId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'work_permit_overdue',
            'asset_id' => $this->assetId,
            'count' => $this->count,
            'title' => __('admin.notifications.work_permit_overdue_title'),
            'body' => __('admin.notifications.work_permit_overdue_body', ['count' => $this->count]),
            'icon' => 'heroicon-o-shield-exclamation',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
