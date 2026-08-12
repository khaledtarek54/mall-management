<?php

namespace App\Notifications;

use App\Models\OwnerRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell entry for owner (Jawad) requests — to the operator team on submit
 * (FR OWN-1), and back to the owner when the operator updates it (FR OWN-2).
 */
class OwnerRequestNotification extends Notification
{
    use Queueable;

    public function __construct(public OwnerRequest $request, public string $event = 'submitted') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $submitted = $this->event === 'submitted';

        return [
            'type' => 'owner_request',
            'event' => $this->event,
            'owner_request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'subject' => $this->request->subject,
            'status' => $this->request->status,
            'title' => __($submitted
                ? 'admin.notifications.owner_request_submitted_title'
                : 'admin.notifications.owner_request_updated_title'),
            'body' => $submitted
                ? __('admin.notifications.owner_request_submitted_body', [
                    'reference' => $this->request->reference,
                    'subject' => $this->request->subject,
                ])
                // The STATUS is translated too, not interpolated raw. A half-Arabic sentence
                // ("أصبح REQ-004 الآن in_progress") is what interpolating an enum value gets you,
                // and it is the commonest way a "translated" string stays half-English.
                : __('admin.notifications.owner_request_updated_body', [
                    'reference' => $this->request->reference,
                    'status' => __("admin.owner_requests.statuses.{$this->request->status}"),
                ]),
            'icon' => 'heroicon-o-inbox',
            'color' => $this->request->priority === 'high' ? 'warning' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
