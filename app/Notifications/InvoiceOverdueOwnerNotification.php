<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Jawad-owner bell entry for an overdue (late-paid) invoice on a property they
 * own. Fired once per invoice by the daily billing:scan-overdue-invoices
 * command (idempotent via invoices.owner_overdue_notified_at).
 */
class InvoiceOverdueOwnerNotification extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $daysOverdue = (int) abs($this->invoice->due_date?->diffInDays(now()) ?? 0);

        return [
            'type' => 'invoice_overdue',
            'invoice_id' => $this->invoice->id,
            'number' => $this->invoice->number,
            'balance' => (float) $this->invoice->balance,
            'days_overdue' => $daysOverdue,
            'title' => __('admin.notifications.invoice_overdue_title'),
            'body' => __('admin.notifications.invoice_overdue_body', [
                'number' => $this->invoice->number,
                'days' => $daysOverdue,
                'amount' => number_format((float) $this->invoice->balance, 2),
            ]),
            'icon' => 'heroicon-o-banknotes',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
