<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A scheduled report, as an attachment.
 *
 * CSV rather than a rendered table in the body: the recipient is an accountant or an owner who will
 * pivot it, reconcile it against their own figures, or hand it to an auditor — none of which can be
 * done to an HTML table in an email. It is the same CSV the export button produces, byte for byte,
 * so a delivered copy and a downloaded one can never disagree.
 */
class SavedReportDelivered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $filename,
        public string $csv,
        public int $rowCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.saved_report.subject', ['name' => $this->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.saved-report', with: [
            'name' => $this->name,
            'rowCount' => $this->rowCount,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->csv, $this->filename)->withMime('text/csv'),
        ];
    }
}
