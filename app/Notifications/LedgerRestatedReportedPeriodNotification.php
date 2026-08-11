<?php

namespace App\Notifications;

use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell + mail for the GL managers when a re-derive has just **restated a month that was already
 * reported** — an entry inside a period covered by a finalised owner statement was voided and
 * re-posted, or voided outright.
 *
 * Why this is not merely informational. The ledger here is derived, so a change to a posted
 * document corrects the books automatically and silently. That is the right behaviour and it is
 * exactly what makes this dangerous at the edges: the owner has a statement in their hand whose
 * figures no longer match the books, and nobody would know unless they happened to re-run the
 * report. The remedy is a business one — re-issue the statement, or explain the difference — so
 * the alert names the month, the statement and the document rather than trying to decide.
 *
 * Mail as well as the bell for the same reason as {@see LedgerSyncFailedNotification}: the person
 * who needs it is the accountant, and they may not open /admin for days. Recipients are the holders
 * of `journal_entries.post` — a handful of people, not a broadcast.
 */
class LedgerRestatedReportedPeriodNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(
        public string $month,
        public string $documentLabel,
        public ?string $statement = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ledger_restated_reported_period',
            'month' => $this->month,
            'document' => $this->documentLabel,
            'statement' => $this->statement,
            'title' => __('admin.notifications.ledger_restated_title', ['month' => $this->month]),
            'body' => __('admin.notifications.ledger_restated_body', [
                'document' => $this->documentLabel,
                'month' => $this->month,
                'statement' => $this->statement ?? '—',
            ]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
