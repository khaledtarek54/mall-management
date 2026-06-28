<?php

namespace App\Notifications;

use App\Models\MaintenanceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaintenanceStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(public MaintenanceRequest $request, public string $previousStatus) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.maintenance_status_subject', [
                'reference' => $this->request->reference,
                'status' => __("admin.statuses.maintenance_request.{$this->request->status}"),
            ]))
            ->greeting(__('admin.notifications.payment_received_greeting', ['name' => $this->request->tenant?->name ?? '']))
            ->line(__('admin.notifications.maintenance_status_body', [
                'title' => $this->request->title,
                'from' => __("admin.statuses.maintenance_request.{$this->previousStatus}"),
                'to' => __("admin.statuses.maintenance_request.{$this->request->status}"),
            ]))
            ->when(
                in_array($this->request->status, ['resolved', 'closed'], true) && $this->request->resolution_notes,
                fn (MailMessage $m) => $m->line(__('admin.notifications.maintenance_status_resolution', [
                    'notes' => $this->request->resolution_notes,
                ]))
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'maintenance_status_changed',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'title' => __('admin.notifications.maintenance_status_title', [
                'reference' => $this->request->reference,
            ]),
            'body' => __('admin.notifications.maintenance_status_short', [
                'title' => $this->request->title,
                'status' => __("admin.statuses.maintenance_request.{$this->request->status}"),
            ]),
            'status' => $this->request->status,
            'icon' => match ($this->request->status) {
                'resolved', 'closed' => 'heroicon-o-check-circle',
                'in_progress' => 'heroicon-o-wrench-screwdriver',
                'cancelled' => 'heroicon-o-x-circle',
                default => 'heroicon-o-wrench',
            },
            'color' => match ($this->request->status) {
                'resolved', 'closed' => 'success',
                'cancelled' => 'gray',
                'in_progress' => 'warning',
                default => 'primary',
            },
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
