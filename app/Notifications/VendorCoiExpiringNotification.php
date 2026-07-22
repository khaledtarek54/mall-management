<?php

namespace App\Notifications;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a vendor whose Certificate of Insurance is lapsing (or has lapsed).
 *
 * Fired by `vendors:scan-coi-expiry`. Two stages, because they demand different things: `expiring`
 * is "chase the renewal, you have N days"; `expired` is "this vendor is ALREADY out of every
 * assignment picker" — a fact the compliance gate otherwise enforces silently.
 */
class VendorCoiExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(public Vendor $vendor, public string $stage) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $expired = $this->stage === Vendor::COI_STAGE_EXPIRED;
        $days = (int) abs((int) $this->vendor->coiDaysToExpiry());

        return [
            'type' => 'vendor_coi_expiry',
            'vendor_id' => $this->vendor->id,
            'stage' => $this->stage,
            // The cert date this alert is about — so a renewal is visibly a NEW alert, not a repeat.
            'coi_expires_at' => $this->vendor->coi_expires_at?->toDateString(),
            'title' => __($expired
                ? 'admin.notifications.vendor_coi_expired_title'
                : 'admin.notifications.vendor_coi_expiring_title'),
            'body' => __($expired
                ? 'admin.notifications.vendor_coi_expired_body'
                : 'admin.notifications.vendor_coi_expiring_body', [
                    'vendor' => $this->vendor->name,
                    'date' => $this->vendor->coi_expires_at?->format('Y-m-d') ?? '—',
                    'days' => $days,
                ]),
            'icon' => 'heroicon-o-shield-exclamation',
            'color' => $expired ? 'danger' : 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
