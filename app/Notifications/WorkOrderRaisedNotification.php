<?php

namespace App\Notifications;

use App\Models\MaintenanceWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry when a facility WORK ORDER is raised — the auto-generated
 * preventive job the nightly `maintenance:generate-preventive` scan creates (FRD MNT-2's
 * "scheduled-service notifications generated from facilities input"), or a corrective one.
 * Without it a scheduled service was raised completely silently and sat `open` until someone
 * happened to open the board. Database channel (bell), mirroring the breach notification.
 */
class WorkOrderRaisedNotification extends Notification
{
    use Queueable;

    public function __construct(public MaintenanceWorkOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $isPreventive = $this->order->work_order_type === MaintenanceWorkOrder::TYPE_PPM;

        return [
            'type' => 'work_order_raised',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'title' => __($isPreventive ? 'admin.notifications.wo_raised_ppm_title' : 'admin.notifications.wo_raised_cm_title'),
            'body' => __('admin.notifications.wo_raised_body', [
                'reference' => $this->order->reference,
                'title' => $this->order->title,
            ]),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'color' => $isPreventive ? 'info' : 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
