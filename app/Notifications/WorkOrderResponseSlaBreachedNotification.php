<?php

namespace App\Notifications;

use App\Models\FacilityWorkOrder;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Nobody has taken a corrective job on, and the response window has passed.
 *
 * The counterpart to `WorkOrderSlaBreachedNotification`, and the reason it had to exist. FR-CM-07
 * starts the RESOLUTION clock at acceptance, so an engineer is never charged for the time a job sat
 * in a queue — a good rule that left queue time accountable to nobody. Combined with `open → done`
 * being a legal hop, a job that never passed through acceptance had no deadline at all: the scan,
 * the penalty gate, the filter and the dashboard skipped it permanently, and not clicking Start was
 * a silent way to waive a vendor's penalty.
 *
 * This is the unanswered half. Fired once per order, off its own stamp — a job answered late but
 * fixed on time is a different conversation from one answered on time and fixed late, and one
 * alert must not silence the other.
 *
 * Mail as well as the bell, and to owners too: an unanswered urgent job is the failure mode a
 * tenant escalates over, and by the time somebody opens the board it is already the complaint.
 */
class WorkOrderResponseSlaBreachedNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public FacilityWorkOrder $order) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $hoursOver = $this->order->hoursOverResponseSla();

        return [
            'type' => 'work_order_response_sla_breached',
            'work_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'priority' => $this->order->priority,
            'hours_over_response_sla' => $hoursOver,
            'title' => __('admin.notifications.wo_response_sla_breached_title'),
            'body' => __('admin.notifications.wo_response_sla_breached_body', [
                'reference' => $this->order->reference,
                'equipment' => $this->order->equipment?->code ?? $this->order->title,
                'priority' => __("admin.facility.priorities.{$this->order->priority}"),
                'hours' => $hoursOver,
            ]),
            'icon' => 'heroicon-o-bell-alert',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
