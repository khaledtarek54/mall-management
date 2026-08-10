<?php

namespace App\Notifications;

use App\Models\TenantDocument;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a tenant compliance document that is lapsing (or has lapsed).
 *
 * Fired by `tenants:scan-document-expiry`. Two stages, because they ask for different things:
 * `expiring` is "chase the renewal, you have N days"; `expired` is "this retailer is trading without
 * the cover their lease obliges them to carry".
 *
 * Unlike the vendor equivalent there is no "and they have been blocked" variant, because nothing is
 * blocked — you cannot un-let a shop over a lapsed policy. That makes the alert the *entire*
 * mechanism rather than a courtesy on top of an enforced gate, which is why it goes out by mail too.
 */
class TenantDocumentExpiringNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public TenantDocument $document, public string $stage) {}

    /**
     * Mail as well as the bell. Nothing downstream stops on a lapsed tenant certificate, so an
     * unread bell entry is the whole failure mode: the operator finds out when there is a claim.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $expired = $this->stage === TenantDocument::STAGE_EXPIRED;
        $days = (int) abs((int) $this->document->daysToExpiry());

        return [
            'type' => 'tenant_document_expiry',
            'tenant_id' => $this->document->tenant_id,
            'tenant_document_id' => $this->document->id,
            'document_type' => $this->document->type,
            'stage' => $this->stage,
            // The expiry this alert is about — so a renewal is visibly a NEW alert, not a repeat.
            'expires_on' => $this->document->expires_on?->toDateString(),
            'title' => __($expired
                ? 'admin.notifications.tenant_document_expired_title'
                : 'admin.notifications.tenant_document_expiring_title'),
            'body' => __($expired
                ? 'admin.notifications.tenant_document_expired_body'
                : 'admin.notifications.tenant_document_expiring_body', [
                    'tenant' => $this->document->tenant->name ?? '—',
                    'document' => __("admin.enums.tenant_document_type.{$this->document->type}"),
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
