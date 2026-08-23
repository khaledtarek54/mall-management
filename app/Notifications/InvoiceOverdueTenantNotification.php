<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\DocumentText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tenant-facing reminder that one of their invoices is past due and still unpaid.
 * Fired once per invoice by the daily billing:remind-overdue-tenants command
 * (idempotent via invoices.tenant_overdue_notified_at) — the tenant counterpart
 * to {@see InvoiceOverdueOwnerNotification}, tracked on a separate stamp.
 *
 * ShouldQueue: the command dispatches this INSIDE its lock+stamp transaction, so
 * queuing delivery keeps mail/push off that transaction — a delivery failure can
 * no longer roll back the stamp (which would re-notify already-reached recipients).
 */
class InvoiceOverdueTenantNotification extends Notification implements ShouldQueue
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
            ->subject(__('admin.notifications.invoice_overdue_reminder_subject', ['number' => $this->invoice->number]))
            // The operator's own wording where they have written one, the translation key the
            // notification always used where they have not (EG-15 slice 2). Dunning is the message
            // whose WORDING is the whole artefact: a chasing email that reads as a system alert is
            // ignored, and a mall does not write to an anchor tenant what it writes to a kiosk.
            //
            // Resolved for the INVOICE's property, so a two-mall operator can chase differently in
            // each — and per locale, because `DocumentText` holds both languages on one row and
            // picks at render time. That is the opposite of the invoice LINE description, which is
            // stored prose and therefore stays English; a notification is composed when it is sent.
            ->line(DocumentText::for('dunning.overdue_reminder', $this->invoice->asset_id, [
                'number' => $this->invoice->number,
                'days' => $this->daysOverdue(),
                'amount' => number_format((float) $this->invoice->balance, 2),
            ]) ?? '');
    }

    public function toDatabase(object $notifiable): array
    {
        $days = $this->daysOverdue();

        return [
            'type' => 'invoice_overdue_reminder',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'balance' => (float) $this->invoice->balance,
            'days_overdue' => $days,
            'title' => __('admin.notifications.invoice_overdue_reminder_title'),
            'body' => __('admin.notifications.invoice_overdue_reminder_body', [
                'number' => $this->invoice->number,
                'days' => $days,
                'amount' => number_format((float) $this->invoice->balance, 2),
            ]),
            'icon' => 'heroicon-o-banknotes',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    protected function daysOverdue(): int
    {
        // due_date is NOT NULL on invoices.
        return (int) abs($this->invoice->due_date->diffInDays(now()));
    }
}
