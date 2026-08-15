<?php

namespace App\Notifications;

use App\Models\TenantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry when a tenant submits a request via
 * the portal. Routes to manager + operations users assigned to
 * the unit's asset. Mail intentionally skipped — high-frequency operations
 * channel, the bell is the right surface.
 */
class PortalRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'portal_request_submitted',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'tenant' => $this->request->tenant?->name,
            'unit' => $this->request->unit?->code,
            'priority' => $this->request->priority,
            'title' => __('admin.notifications.portal_request_submitted_title', [
                'type' => $this->request->typeLabel(),
            ]),
            'body' => __('admin.notifications.portal_request_submitted_body', [
                'tenant' => $this->request->tenant?->name ?? '—',
                'unit' => $this->request->unit?->code ?? '—',
                'title' => $this->request->title,
                'priority' => __("admin.enums.work_priority.{$this->request->priority}"),
            ]),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'color' => $this->request->priority === 'urgent' ? 'danger' : 'warning',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
