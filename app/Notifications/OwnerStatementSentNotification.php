<?php

namespace App\Notifications;

use App\Models\OwnerStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell entry to the owner (Jawad) when the operator sends them a finalised statement
 * (module 32). Database channel — the owner sees it in the admin bell, scoped to them.
 */
class OwnerStatementSentNotification extends Notification
{
    use Queueable;

    public function __construct(public OwnerStatement $statement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $property = $this->statement->run?->asset?->name ?? '';

        return [
            'type' => 'owner_statement',
            'owner_statement_id' => $this->statement->id,
            'reference' => $this->statement->reference,
            'title' => __('admin.owner_statements.notification.sent_title'),
            'body' => __('admin.owner_statements.notification.sent_body', [
                'reference' => $this->statement->reference,
                'property' => $property,
            ]),
            'icon' => 'heroicon-o-document-text',
            'color' => 'success',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
