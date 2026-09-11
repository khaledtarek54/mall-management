<?php

namespace App\Notifications;

use App\Models\Lease;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A reservation lapsed: a draft or pending lease held its shop past `reserved_until` with the
 * deposit still unpaid, `leases:expire` cancelled it, and the unit is back on the market
 * (meeting 2026-09-02, point 1 — Yardi's unit-hold expiry). Sent to the property's leasing team,
 * because the shop they were holding for a tenant is free again and somebody should know before
 * the next enquiry.
 */
class ReservationLapsedNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    /**
     * @param  string|null  $reservedUntil  the day the hold ran out — passed in, because the row's
     *                                      own `reserved_until` is cleared the moment it leaves the
     *                                      awaiting state, and the alert is about the date it had.
     */
    public function __construct(public Lease $lease, public ?string $reservedUntil = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $lease = $this->lease;

        return [
            'type' => 'reservation_lapsed',
            'lease_id' => $lease->id,
            'title' => __('admin.notifications.reservation_lapsed_title', [
                'unit' => $lease->unit?->code ?? '—',
            ]),
            'body' => __('admin.notifications.reservation_lapsed_body', [
                'lease' => $lease->reference ?? '—',
                'tenant' => $lease->tenant?->name ?? '—',
                'unit' => $lease->unit?->code ?? '—',
                'date' => $this->reservedUntil ?? $lease->reserved_until?->format('Y-m-d') ?? '—',
            ]),
            'icon' => 'heroicon-o-calendar-days',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
