<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell alert for the GL managers (super_admin / accounting) when the sync-ledger run
 * could not post one or more documents — almost always a document whose period (and the
 * current period) is closed, so its entry can't be reconciled until a period is reopened.
 *
 * This is the safety net for the "closed-period reversal trap": the scheduled sweep is
 * best-effort (it stays exit-0 so the cron isn't perpetually red), but a failure must NOT
 * be silent. Fired by SyncLedgerCommand, de-duplicated so a persistent failure alerts once
 * (on the run where the count first appears / changes), not every night.
 */
class LedgerSyncFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public int $failedCount) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ledger_sync_failed',
            'failed_count' => $this->failedCount,
            'title' => __('admin.notifications.ledger_sync_failed_title'),
            'body' => __('admin.notifications.ledger_sync_failed_body', ['count' => $this->failedCount]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
