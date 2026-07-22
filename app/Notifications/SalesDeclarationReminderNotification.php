<?php

namespace App\Notifications;

use App\Models\Lease;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds a percentage-rent tenant that they have not yet submitted a sales declaration for a closed
 * period. Sent by `sales:scan-missing-declarations`. Without this, a tenant who never uploads a
 * report silently escapes their percentage rent — no row exists to bill or alert. `period_key`
 * (YYYY-MM) makes the scan idempotent: it won't re-remind the same (lease, period).
 */
class SalesDeclarationReminderNotification extends Notification
{
    public function __construct(
        public Lease $lease,
        public string $periodLabel,
        public string $periodKey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.sales_reminder_subject', ['period' => $this->periodLabel]))
            ->line(__('admin.notifications.sales_reminder_body', ['period' => $this->periodLabel]))
            ->line(__('admin.notifications.sales_reminder_hint'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'sales_declaration_reminder',
            'lease_id' => $this->lease->id,
            'period_key' => $this->periodKey,
            'period' => $this->periodLabel,
            'title' => __('admin.notifications.sales_reminder_title'),
            'body' => __('admin.notifications.sales_reminder_body', ['period' => $this->periodLabel]),
            'icon' => 'heroicon-o-presentation-chart-line',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
