<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\IssuingEntity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('admin.email.invoice_issued_subject', [
                'number' => $this->invoice->number,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-issued',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->invoice->tenant,
                'lease' => $this->invoice->lease,
                // Two senders render this one template — this Mailable and
                // InvoiceIssuedNotification. Both must state the issuer or the other one throws on
                // an undefined variable, which is why the conformance gate sweeps mail views too.
                ...IssuingEntity::forView($this->invoice->asset),
            ],
        );
    }
}
