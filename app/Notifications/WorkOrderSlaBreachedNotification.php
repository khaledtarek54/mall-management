<?php

namespace App\Notifications;

use App\Models\FacilityWorkOrder;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a corrective job whose SLA target has passed (FR-CM-08).
 * Fired once per order by `facility:scan-sla-breaches`.
 *
 * Mirrors TenantRequestSlaBreachedNotification (module 11's tenant requests) — same shape,
 * different subject: this one is about the facility's own work orders.
 */
class WorkOrderSlaBreachedNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public FacilityWorkOrder $order) {}

    /**
     * Mail as well as the bell. Same clock as the tenant-request breach — a corrective job past its target is a commitment
     * already broken, not an FYI.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $hoursOver = $this->order->hoursOverSla();

        return [
            'type' => 'work_order_sla_breached',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'priority' => $this->order->priority,
            'hours_over_sla' => $hoursOver,
            'title' => __('admin.notifications.wo_sla_breached_title'),
            'body' => __('admin.notifications.wo_sla_breached_body', [
                'reference' => $this->order->reference,
                'equipment' => $this->order->equipment?->code ?? $this->order->title,
                'priority' => __("admin.facility.priorities.{$this->order->priority}"),
                'hours' => $hoursOver,
            ]),
            'icon' => 'heroicon-o-clock',
            'color' => 'danger',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a toast auto-deletes the row after ~6s)
        ];
    }
}
