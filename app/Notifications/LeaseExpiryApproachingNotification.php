<?php

namespace App\Notifications;

use App\Models\Lease;
use App\Models\Unit;
use App\Support\DocumentText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tenant-facing heads-up that a lease is approaching its expiry date, nudging the
 * tenant to start the renewal conversation. Fired once per lease by the daily
 * leases:remind-expiring command (idempotent via leases.expiry_reminder_notified_at).
 *
 * ShouldQueue: the command dispatches this INSIDE its lock+stamp transaction, so
 * queuing delivery keeps mail/push off that transaction — a delivery failure can
 * no longer roll back the stamp (which would re-notify the tenant next run).
 */
class LeaseExpiryApproachingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lease $lease) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(DocumentText::forSubject('lease.expiry_subject', $this->lease->assetId(), ['reference' => $this->lease->reference]) ?? '')
            ->line(DocumentText::for('lease.expiry_approaching', $this->lease->assetId(), [
                'unit' => $this->unitCode(),
                'days' => $this->daysUntil(),
                'date' => $this->lease->expiry_date->format('d/m/Y'),
            ]) ?? '')
            ->line(__('admin.notifications.lease_expiry_hint'));
    }

    public function toDatabase(object $notifiable): array
    {
        $days = $this->daysUntil();

        return [
            'type' => 'lease_expiry_approaching',
            'lease_id' => $this->lease->id,
            'reference' => $this->lease->reference,
            'unit' => $this->unitCode(),
            'expiry_date' => $this->lease->expiry_date->toDateString(),
            'days_until' => $days,
            'title' => __('admin.notifications.lease_expiry_title'),
            'body' => __('admin.notifications.lease_expiry_body', [
                'unit' => $this->unitCode(),
                'days' => $days,
            ]),
            'icon' => 'heroicon-o-calendar',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    protected function daysUntil(): int
    {
        // Reuse the model's canonical calculation (positive for a future expiry).
        return $this->lease->daysUntilExpiry();
    }

    protected function unitCode(): string
    {
        // withTrashed(): a unit can be soft-deleted while a lease still references
        // it (the FK only restricts hard deletes), so read through trashed rows to
        // keep this queued notification from crashing on a null relation.
        return (string) Unit::withTrashed()->whereKey($this->lease->unit_id)->value('code');
    }
}
