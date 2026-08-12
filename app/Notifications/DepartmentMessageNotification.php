<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Inter-department message (FR DEPT-2) — a bell entry sent to the members of a
 * target department when another department contacts them.
 */
class DepartmentMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public string $body, public string $fromLabel) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'department_message',
            'title' => 'Message from '.$this->fromLabel,
            'body' => $this->body,
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
