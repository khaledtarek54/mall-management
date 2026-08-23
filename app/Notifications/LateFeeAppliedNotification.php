<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\LateFeeService;
use App\Support\DocumentText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tenant-facing alert that a late fee has been charged. Dispatched once from inside
 * {@see LateFeeService::applyTo()}'s transaction, so the notification is committed atomically with
 * the fee (on the database queue the job row rolls back with the fee if the tx fails — no
 * charge-without-notice gap). ShouldQueue keeps the mail/push delivery off the request thread and
 * retriable, and isolates a single recipient's failure from the rest of the fan-out.
 *
 * **TWO invoices, and the tenant needs both.** Since 2026-08-11 the fee is its own dated document
 * rather than a line appended to the overdue one, so the message has to say what is now owed
 * (`$feeInvoice`) AND which invoice went unpaid to cause it (`$overdueInvoice`). Naming only one
 * would leave the tenant an invoice they cannot account for, or a penalty with no bill behind it.
 */
class LateFeeAppliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $feeInvoice, public Invoice $overdueInvoice) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // The OVERDUE invoice in the subject: that is the one the tenant recognises and the one
            // they need to act on. The fee invoice is a document they have never seen before.
            ->subject(DocumentText::forSubject('dunning.late_fee_subject', $this->overdueInvoice->asset_id, ['number' => $this->overdueInvoice->number]) ?? '')
            ->line(DocumentText::for('dunning.late_fee_applied', $this->overdueInvoice->asset_id, [
                'fee' => 'EGP '.number_format($this->fee(), 2),
                'number' => $this->overdueInvoice->number,
                'balance' => 'EGP '.number_format((float) $this->overdueInvoice->balance, 2),
            ]) ?? '')
            ->line(__('admin.notifications.late_fee_invoice_line', [
                'number' => $this->feeInvoice->number,
                // `due_date` is NOT NULL on invoices — no fallback to hedge against.
                'due' => $this->feeInvoice->due_date->format('d/m/Y'),
            ]))
            ->line(__('admin.notifications.late_fee_applied_hint'));
    }

    public function toDatabase(object $notifiable): array
    {
        $fee = $this->fee();

        return [
            'type' => 'late_fee_applied',
            // Deep-links to the OVERDUE invoice — the thing to pay. `fee_invoice_id` carries the
            // new document alongside it rather than replacing it.
            'invoice_id' => $this->overdueInvoice->id,
            'invoice_number' => $this->overdueInvoice->number,
            'fee_invoice_id' => $this->feeInvoice->id,
            'fee_invoice_number' => $this->feeInvoice->number,
            'fee' => $fee,
            'balance' => (float) $this->overdueInvoice->balance,
            'title' => __('admin.notifications.late_fee_applied_title'),
            'body' => __('admin.notifications.late_fee_applied_body', [
                'fee' => 'EGP '.number_format($fee, 2),
                'number' => $this->overdueInvoice->number,
                'balance' => 'EGP '.number_format((float) $this->overdueInvoice->balance, 2),
            ]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    /** The fee charged — read off the fee invoice, which carries exactly one such line. */
    protected function fee(): float
    {
        return (float) $this->feeInvoice->items()->where('type', 'late_fee')->value('amount');
    }
}
