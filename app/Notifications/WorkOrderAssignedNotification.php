<?php

namespace App\Notifications;

use App\Models\FacilityWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell entry to the technician a work order was just assigned to. Sharper than it looks:
 * `FacilityWorkOrderResource` applies `AssignmentScope` (FR-USR-04), so an `operations`
 * technician sees ONLY the work orders assigned to them — yet nothing pinged them when one
 * landed. A coordinator assigns an urgent CM; the technician found it only next time they
 * happened to open the panel. Database channel (bell), fired once on assignment.
 */
class WorkOrderAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public FacilityWorkOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'work_order_assigned',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'priority' => $this->order->priority,
            'title' => __('admin.notifications.wo_assigned_title'),
            'body' => __('admin.notifications.wo_assigned_body', [
                'reference' => $this->order->reference,
                'title' => $this->order->title,
                'priority' => __("admin.facility.priorities.{$this->order->priority}"),
            ]),
            'icon' => 'heroicon-o-user-plus',
            'color' => 'primary',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
