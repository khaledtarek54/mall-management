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
            'title' => $submitted ? 'New owner request' : 'Owner request updated',
            'body' => $submitted
                ? "{$this->request->reference}: {$this->request->subject}"
                : "{$this->request->reference} is now {$this->request->status}.",
            'icon' => 'heroicon-o-inbox',
            'color' => $this->request->priority === 'high' ? 'warning' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
