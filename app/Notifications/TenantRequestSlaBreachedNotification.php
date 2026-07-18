<?php

namespace App\Notifications;

use App\Models\TenantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a maintenance request whose
 * target_resolution_at has passed without resolution. Fired once per
 * request by the daily requests:scan-sla-breaches command.
 */
class TenantRequestSlaBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $hoursOver = (int) abs($this->request->target_resolution_at?->diffInHours(now()) ?? 0);

        return [
            'type' => 'maintenance_sla_breached',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'priority' => $this->request->priority,
            'hours_over_sla' => $hoursOver,
            'title' => __('admin.notifications.sla_breached_title'),
            'body' => __('admin.notifications.sla_breached_body', [
                'type' => $this->request->typeLabel(),
                'reference' => $this->request->reference,
                'priority' => __("admin.enums.maintenance_priority.{$this->request->priority}"),
                'hours' => $hoursOver,
            ]),
            'icon' => 'heroicon-o-clock',
            'color' => 'danger',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
