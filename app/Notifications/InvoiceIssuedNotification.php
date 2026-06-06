<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pdfService = app(InvoicePdfService::class);

        return (new MailMessage)
            ->subject(__('admin.notifications.invoice_issued_subject', ['number' => $this->invoice->number]))
            ->markdown('emails.invoice-issued', [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->tenant,
                'lease' => $this->invoice->lease,
            ])
            ->attach(
                Attachment::fromData(fn () => $pdfService->build($this->invoice), $pdfService->filename($this->invoice))
                    ->withMime('application/pdf')
            );
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'invoice_issued',
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'total' => (float) $this->invoice->total,
            'due_date' => optional($this->invoice->due_date)->toDateString(),
            'title' => __('admin.notifications.invoice_issued_title'),
            'body' => __('admin.notifications.invoice_issued_body', [
                'number' => $this->invoice->number,
                'total' => 'EGP '.number_format((float) $this->invoice->total, 2),
            ]),
            'icon' => 'heroicon-o-document-text',
            'color' => 'primary',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
