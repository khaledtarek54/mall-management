<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoiceLines = $this->payment->invoices->map(
            fn ($invoice) => $invoice->number.' (EGP '.number_format((float) $invoice->pivot->allocated_amount, 2).')'
        )->implode(', ');

        return (new MailMessage)
            ->subject(__('admin.notifications.payment_received_subject', ['reference' => $this->payment->reference]))
            ->greeting(__('admin.notifications.payment_received_greeting', ['name' => $this->payment->tenant?->name ?? '']))
            ->line(__('admin.notifications.payment_received_body', [
                'amount' => 'EGP '.number_format((float) $this->payment->amount, 2),
                // admin.enums.method is the canonical map (the same one the tables and filters
                // read). The old key `admin.fields.payment_methods.*` never existed, and the
                // `?:` fallback could not catch it: a missing __() returns the key itself,
                // which is truthy — so the raw key was emailed to the tenant.
                'method' => PaymentMethod::labelFor($this->payment->method),
                'date' => $this->payment->payment_date->format('d/m/Y'),
            ]))
            ->when($invoiceLines !== '', fn (MailMessage $m) => $m->line(__('admin.notifications.payment_received_allocations', ['invoices' => $invoiceLines])))
            ->line(__('admin.notifications.payment_received_thanks'));
    }

    public function toDatabase(object $notifiable): array
    {
        $invoiceNumbers = $this->payment->invoices->pluck('number')->implode(', ');

        return [
            'type' => 'payment_received',
            'payment_id' => $this->payment->id,
            'reference' => $this->payment->reference,
            'amount' => (float) $this->payment->amount,
            'method' => $this->payment->method,
            'title' => __('admin.notifications.payment_received_title'),
            'body' => __('admin.notifications.payment_received_short', [
                'amount' => 'EGP '.number_format((float) $this->payment->amount, 2),
                'invoices' => $invoiceNumbers ?: '—',
            ]),
            'icon' => 'heroicon-o-banknotes',
            'color' => 'success',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
