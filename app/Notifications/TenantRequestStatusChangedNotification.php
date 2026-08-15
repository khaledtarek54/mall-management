<?php

namespace App\Notifications;

use App\Models\TenantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantRequestStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantRequest $request, public string $previousStatus) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.request_status_subject', [
                'type' => $this->request->typeLabel(),
                'reference' => $this->request->reference,
                'status' => __("admin.statuses.tenant_request.{$this->request->status}"),
            ]))
            ->greeting(__('admin.notifications.payment_received_greeting', ['name' => $this->request->tenant?->name ?? '']))
            ->line(__('admin.notifications.request_status_body', [
                'type' => $this->request->typeLabel(),
                'title' => $this->request->title,
                'from' => __("admin.statuses.tenant_request.{$this->previousStatus}"),
                'to' => __("admin.statuses.tenant_request.{$this->request->status}"),
            ]))
            ->when(
                in_array($this->request->status, ['resolved', 'closed'], true) && $this->request->resolution_notes,
                fn (MailMessage $m) => $m->line(__('admin.notifications.request_status_resolution', [
                    'notes' => $this->request->resolution_notes,
                ]))
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'request_status_changed',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'title' => __('admin.notifications.request_status_title', [
                'type' => $this->request->typeLabel(),
                'reference' => $this->request->reference,
            ]),
            // A request that asked for something announces its ANSWER, not its lifecycle. "…is now
            // Resolved" is technically true of a refusal and reads as a yes, which is the same
            // mistake the permit card was making in a different place.
            'body' => $this->request->decision !== null
                ? __('admin.notifications.request_decision_short', [
                    'title' => $this->request->title,
                    'decision' => __("admin.statuses.tenant_request_decision.{$this->request->decision}"),
                ])
                : __('admin.notifications.request_status_short', [
                    'title' => $this->request->title,
                    'status' => __("admin.statuses.tenant_request.{$this->request->status}"),
                ]),
            'status' => $this->request->status,
            // Shipped so the app can badge the row without re-fetching the request.
            'decision' => $this->request->decision,
            'icon' => match (true) {
                $this->request->wasRejected() => 'heroicon-o-x-circle',
                in_array($this->request->status, ['resolved', 'closed'], true) => 'heroicon-o-check-circle',
                $this->request->status === 'in_progress' => 'heroicon-o-wrench-screwdriver',
                $this->request->status === 'cancelled' => 'heroicon-o-x-circle',
                default => 'heroicon-o-wrench',
            },
            'color' => match (true) {
                // A refusal is not a success, whatever the status says.
                $this->request->wasRejected() => 'danger',
                in_array($this->request->status, ['resolved', 'closed'], true) => 'success',
                $this->request->status === 'cancelled' => 'gray',
                $this->request->status === 'in_progress' => 'warning',
                default => 'primary',
            },
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
