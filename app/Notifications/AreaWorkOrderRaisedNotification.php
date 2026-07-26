<?php

namespace App\Notifications;

use App\Models\MaintenanceWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Supervisor-side bell entry when a WORK ORDER lands in a facility zone the user oversees
 * (module 30 → 11 routing) — the work-order peer of AreaRequestRaisedNotification. Routes to the
 * area's supervisors (Area::supervisors).
 *
 * Database + push, no mail — the same channel choice as the request/SLA signals: a high-frequency
 * operations event belongs on the bell (and the app), not the inbox. Push is a no-op for admin
 * Users (no device tokens) but is declared so the routing reaches the mobile app the moment a
 * supervisor is a push-capable notifiable.
 */
class AreaWorkOrderRaisedNotification extends Notification
{
    use Queueable;

    public function __construct(public MaintenanceWorkOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'area_work_order_raised',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'area_id' => $this->order->area_id,
            'area' => $this->order->areaName(),
            'unit' => $this->order->unitCode(),
            'priority' => $this->order->priority,
            'title' => __('admin.notifications.area_work_order_raised_title', [
                'area' => $this->order->areaName() ?? '—',
            ]),
            'body' => __('admin.notifications.area_work_order_raised_body', [
                'title' => $this->order->title,
                'unit' => $this->order->unitCode() ?? '—',
                'priority' => __("admin.enums.maintenance_priority.{$this->order->priority}"),
            ]),
            'icon' => 'heroicon-o-map-pin',
            'color' => $this->order->priority === 'urgent' ? 'danger' : 'warning',
            'url' => null,
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
