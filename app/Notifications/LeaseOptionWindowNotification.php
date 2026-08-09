<?php

namespace App\Notifications;

use App\Models\LeaseOption;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side alert for a lease option's notice window.
 *
 * Three moments, three different actions — so they must not share one vague wording:
 *
 *   - **opening** → notice may now be served. Start the conversation with the tenant.
 *   - **closing** → the deadline is near. Decide, because after it the right is gone.
 *   - **lapsed**  → it is gone. Recorded so nobody plans around an option that no longer exists.
 *
 * Mailed as well as belled: this is one of the few alerts in the system where missing it costs a
 * contracted rent step or an unplanned vacancy, and a bell nobody logs in to see is not an alert.
 */
class LeaseOptionWindowNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    /** @param  'opening'|'closing'|'lapsed'  $event */
    public function __construct(public LeaseOption $option, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $option = $this->option;
        $lease = $option->lease;
        $days = $option->daysUntilClose();

        return [
            'type' => 'lease_option_window',
            'lease_option_id' => $option->id,
            'lease_id' => $option->lease_id,
            'event' => $this->event,
            // The deadline this alert is about, so a re-dated option is visibly a NEW alert rather
            // than a repeat of the old one.
            'latest_notice_date' => $option->latest_notice_date?->toDateString(),
            'title' => __("admin.notifications.lease_option_{$this->event}_title", [
                'type' => __("admin.lease_options.types.{$option->type}"),
            ]),
            'body' => __("admin.notifications.lease_option_{$this->event}_body", [
                'type' => __("admin.lease_options.types.{$option->type}"),
                'lease' => $lease?->reference ?? '—',
                'tenant' => $lease?->tenant?->name ?? '—',
                'unit' => $lease?->unit?->code ?? '—',
                'earliest' => $option->earliest_notice_date?->format('Y-m-d') ?? '—',
                'deadline' => $option->latest_notice_date?->format('Y-m-d') ?? '—',
                'days' => abs((int) ($days ?? 0)),
            ]),
            'icon' => 'heroicon-o-calendar-days',
            // Opening is an opportunity; closing and lapsed are losses in the making or made.
            'color' => match ($this->event) {
                'opening' => 'info',
                'closing' => 'warning',
                default => 'danger',
            },
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
