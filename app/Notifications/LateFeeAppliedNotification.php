<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\LateFeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tenant-facing alert that a late fee was added to an overdue invoice. Dispatched
 * once per invoice from inside {@see LateFeeService::applyTo()}'s transaction, so
 * the notification is committed atomically with the fee (on the database queue the
 * job row rolls back with the fee if the tx fails — no charge-without-notice gap).
 * ShouldQueue keeps the mail/push delivery off the request thread and retriable,
 * and isolates a single recipient's failure from the rest of the fan-out.
 */
class LateFeeAppliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.late_fee_applied_subject', ['number' => $this->invoice->number]))
            ->line(__('admin.notifications.late_fee_applied_mail', [
                'fee' => 'EGP '.number_format($this->fee(), 2),
                'number' => $this->invoice->number,
                'balance' => 'EGP '.number_format((float) $this->invoice->balance, 2),
            ]))
            ->line(__('admin.notifications.late_fee_applied_hint'));
    }

    public function toDatabase(object $notifiable): array
    {
        $fee = $this->fee();

        return [
            'type' => 'late_fee_applied',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'fee' => $fee,
            'balance' => (float) $this->invoice->balance,
            'title' => __('admin.notifications.late_fee_applied_title'),
            'body' => __('admin.notifications.late_fee_applied_body', [
                'fee' => 'EGP '.number_format($fee, 2),
                'number' => $this->invoice->number,
                'balance' => 'EGP '.number_format((float) $this->invoice->balance, 2),
            ]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    /** The late-fee amount on this invoice (invoices carry at most one). */
    protected function fee(): float
    {
        return (float) $this->invoice->items()->where('type', 'late_fee')->value('amount');
    }
}
