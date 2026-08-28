<?php

namespace App\Notifications;

use App\Models\FacilityWorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * **Bell to the CONTRACTOR when a job is dispatched to them** — step 6 of
 * `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`, and what closes the loop.
 *
 * Dispatching used to be an internal column change: `vendor_id` was set and the contractor found
 * out by phone, or did not. That is also what made `acknowledged_at` meaningless — you cannot
 * measure a response time from a moment the other party was never told about.
 *
 * Sent to every PORTAL contact at the vendor, not to the whole contact list: someone without a login
 * cannot act on it, and a bell they can never see is not a notification.
 *
 * Sibling of `WorkOrderAssignedNotification`, which does the same thing for our own technician. Two
 * notifications because they are two audiences with two panels — the technician's is scoped by
 * `AssignmentScope` in `/admin`, this one by `VendorScope` in `/vendor`.
 */
class WorkOrderDispatchedNotification extends Notification
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
            'type' => 'work_order_dispatched',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'priority' => $this->order->priority,
            'title' => __('vendor.notifications.dispatched_title'),
            // Names the job and the property, because a contractor working several malls needs to
            // know WHERE before anything else.
            'body' => __('vendor.notifications.dispatched_body', [
                'reference' => $this->order->reference,
                'title' => $this->order->title,
                'property' => $this->order->asset?->name ?? '—',
            ]),
            'icon' => 'heroicon-o-wrench-screwdriver',
            'color' => 'primary',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
