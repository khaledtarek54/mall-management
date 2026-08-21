<?php

namespace App\Notifications;

use App\Models\VendorDocument;
use App\Models\VendorDocumentType;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a vendor compliance document that is lapsing (or has lapsed).
 *
 * Fired by `vendors:scan-document-expiry`. Two stages, because they demand different things:
 * `expiring` is "chase the renewal, you have N days"; `expired` is the consequence — and the
 * consequence differs by document. A lapsed INSURANCE certificate has already removed the vendor
 * from every assignment picker (a fact the dispatch gate otherwise enforces silently); a lapsed tax
 * card or commercial register is a finance-side compliance problem that does not stop site work.
 */
class VendorDocumentExpiringNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public VendorDocument $document, public string $stage) {}

    /**
     * Mail as well as the bell. A lapsed certificate means the vendor legally cannot be dispatched. Finding that out when
     * you next open the app is finding out too late.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $expired = $this->stage === VendorDocument::STAGE_EXPIRED;
        $blocking = $this->document->isBlocking();
        $days = (int) abs((int) $this->document->daysToExpiry());

        $body = match (true) {
            $expired && $blocking => 'admin.notifications.vendor_document_expired_blocking_body',
            $expired => 'admin.notifications.vendor_document_expired_body',
            default => 'admin.notifications.vendor_document_expiring_body',
        };

        return [
            'type' => 'vendor_document_expiry',
            'vendor_id' => $this->document->vendor_id,
            'vendor_document_id' => $this->document->id,
            'document_type' => $this->document->type,
            'stage' => $this->stage,
            // The expiry this alert is about — so a renewal is visibly a NEW alert, not a repeat.
            'expires_on' => $this->document->expires_on?->toDateString(),
            'title' => __($expired
                ? 'admin.notifications.vendor_document_expired_title'
                : 'admin.notifications.vendor_document_expiring_title'),
            'body' => __($body, [
                'vendor' => $this->document->vendor->name ?? '—',
                'document' => VendorDocumentType::labelFor($this->document->type),
                'date' => $this->document->expires_on?->format('Y-m-d') ?? '—',
                'days' => $days,
            ]),
            'icon' => 'heroicon-o-shield-exclamation',
            'color' => $expired ? 'danger' : 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
