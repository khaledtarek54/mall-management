<?php

namespace App\Notifications;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestComment;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A public comment was added to a maintenance request. Adapts to the
 * recipient:
 *
 *  - Tenant recipient (a staff member commented): mail + bell, mirrors the
 *    status-change notification surface.
 *  - Staff recipient (the tenant commented): bell only — high-frequency
 *    operations channel, same treatment as PortalMaintenanceSubmitted.
 *
 * Internal staff-only notes never trigger this; the service guards on that.
 */
class MaintenanceCommentAddedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MaintenanceRequest $request,
        public MaintenanceRequestComment $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof Tenant ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.maintenance_comment_subject', [
                'reference' => $this->request->reference,
            ]))
            ->greeting(__('admin.notifications.payment_received_greeting', [
                'name' => $this->request->tenant?->name ?? '',
            ]))
            ->line(__('admin.notifications.maintenance_comment_body', [
                'title' => $this->request->title,
                'comment' => $this->comment->body,
            ]));
    }

    public function toDatabase(object $notifiable): array
    {
        // Staff bell entry when the tenant is the author.
        if (! $notifiable instanceof Tenant) {
            return [
                'type' => 'maintenance_comment_added',
                'request_id' => $this->request->id,
                'reference' => $this->request->reference,
                'title' => __('admin.notifications.maintenance_comment_staff_title'),
                'body' => __('admin.notifications.maintenance_comment_staff_body', [
                    'tenant' => $this->request->tenant?->name ?? '—',
                    'reference' => $this->request->reference,
                    'title' => $this->request->title,
                    'comment' => Str::limit($this->comment->body, 120),
                ]),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => 'info',
            ];
        }

        // Tenant bell entry when a staff member is the author.
        return [
            'type' => 'maintenance_comment_added',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'title' => __('admin.notifications.maintenance_comment_title', [
                'reference' => $this->request->reference,
            ]),
            'body' => __('admin.notifications.maintenance_comment_short', [
                'title' => $this->request->title,
            ]),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => 'primary',
        ];
    }
}
