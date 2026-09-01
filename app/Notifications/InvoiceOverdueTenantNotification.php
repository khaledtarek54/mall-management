<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\DocumentText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The chase letter, and the figure in it is the COLLECTABLE one.
 *
 * `balance` records what was owed; a partial write-off deliberately does not move it, because a
 * write-off is not a settlement channel. Quoting it here asked the tenant for money the operator had
 * already forgiven and the bad-debt entry had already relieved — and the sweep that selects these
 * invoices was fixed to net the forgiven slice while this, the sentence the tenant actually reads,
 * was not. Selecting correctly and then quoting the wrong number is worse than not fixing either.
 */
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

    /**
     * @param  int  $notice  Which notice this is (1 = first reminder). Passed in rather than read
     *                       off the invoice because the sweep writes the new level in the SAME
     *                       transaction that sends this, and a notification re-reading the row
     *                       would race its own stamp.
     * @param  bool  $isFinal  This is the notice at the configured ceiling — a final demand, which
     *                         is a different document from a reminder and says so.
     */
    public function __construct(
        public Invoice $invoice,
        public int $notice = 1,
        public bool $isFinal = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(DocumentText::forSubject($this->subjectKey(), $this->invoice->asset_id, [
                'number' => $this->invoice->number,
                'notice' => $this->notice,
            ]) ?? '')
            // The operator's own wording where they have written one, the translation key the
            // notification always used where they have not (EG-15 slice 2). Dunning is the message
            // whose WORDING is the whole artefact: a chasing email that reads as a system alert is
            // ignored, and a mall does not write to an anchor tenant what it writes to a kiosk.
            //
            // Resolved for the INVOICE's property, so a two-mall operator can chase differently in
            // each — and per locale, because `DocumentText` holds both languages on one row and
            // picks at render time. That is the opposite of the invoice LINE description, which is
            // stored prose and therefore stays English; a notification is composed when it is sent.
            ->line(DocumentText::for($this->bodyKey(), $this->invoice->asset_id, [
                'number' => $this->invoice->number,
                'days' => $this->daysOverdue(),
                'amount' => number_format($this->invoice->collectableBalance(), 2),
                'notice' => $this->notice,
            ]) ?? '');
    }

    public function toDatabase(object $notifiable): array
    {
        $days = $this->daysOverdue();

        return [
            'type' => 'invoice_overdue_reminder',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'balance' => $this->invoice->collectableBalance(),
            'days_overdue' => $days,
            'notice' => $this->notice,
            'is_final' => $this->isFinal,
            'title' => __('admin.notifications.invoice_overdue_reminder_title'),
            'body' => __('admin.notifications.invoice_overdue_reminder_body', [
                'number' => $this->invoice->number,
                'days' => $days,
                'amount' => number_format($this->invoice->collectableBalance(), 2),
            ]),
            'icon' => 'heroicon-o-banknotes',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }

    /**
     * The LAST notice is a different document — and only in the operator's own words.
     *
     * `dunning.final_notice` carries no floor of its own and falls back through
     * {@see DocumentText::FALLS_BACK_TO} to the ordinary reminder, so an install that has not
     * written a final demand simply sends the reminder again. That is deliberate: a system-composed
     * "FINAL NOTICE" in wording nobody chose is the message most likely to start an argument the
     * operator did not intend, and this is the point in a tenant relationship where tone is the
     * whole artefact.
     */
    protected function bodyKey(): string
    {
        return $this->isFinal ? 'dunning.final_notice' : 'dunning.overdue_reminder';
    }

    protected function subjectKey(): string
    {
        return $this->isFinal ? 'dunning.final_subject' : 'dunning.overdue_subject';
    }

    protected function daysOverdue(): int
    {
        // due_date is NOT NULL on invoices.
        return (int) abs($this->invoice->due_date->diffInDays(now()));
    }
}
