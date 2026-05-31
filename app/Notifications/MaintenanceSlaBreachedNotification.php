<?php

namespace App\Notifications;

use App\Models\MaintenanceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a maintenance request whose
 * target_resolution_at has passed without resolution. Fired once per
 * request by the daily maintenance:scan-sla-breaches command.
 */
class MaintenanceSlaBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public MaintenanceRequest $request) {}

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
                'reference' => $this->request->reference,
                'priority' => __("admin.statuses.maintenance_priority.{$this->request->priority}"),
                'hours' => $hoursOver,
            ]),
            'icon' => 'heroicon-o-clock',
            'color' => 'danger',
        ];
    }
}
