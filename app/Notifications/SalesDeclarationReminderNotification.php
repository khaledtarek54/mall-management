<?php

namespace App\Notifications;

use App\Models\Lease;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds a tenant who OWES a sales declaration that they have not yet submitted one for a closed
 * period. Sent by `sales:scan-missing-declarations`. Without this, a tenant who never uploads a
 * report silently escapes their percentage rent — no row exists to bill or alert. `period_key`
 * (YYYY-MM) makes the scan idempotent: it won't re-remind the same (lease, period).
 *
 * The duty is `Lease::requiresSalesReporting()` and is wider than the charge (SW-254): a lease may
 * oblige the disclosure without charging on it, and telling that tenant their report is needed
 * "so your percentage rent can be finalised" names a clause their lease does not have. The body
 * follows the lease — the reason is the charge where there is one, and the reporting clause
 * where there is not.
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
            ->line($this->body())
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
            'body' => $this->body(),
            'icon' => 'heroicon-o-presentation-chart-line',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    private function body(): string
    {
        $key = $this->lease->has_percentage_rent ? 'sales_reminder_body' : 'sales_reminder_body_disclosure';

        return __('admin.notifications.'.$key, ['period' => $this->periodLabel]);
    }
}
