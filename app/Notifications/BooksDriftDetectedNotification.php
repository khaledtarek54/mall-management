<?php

namespace App\Notifications;

use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The general ledger has stopped agreeing with the sub-ledger it is derived from.
 *
 * `BooksReconciliationService::glTieOut()` compares GL receivables against the sum of invoice
 * balances (and GL payables against vendor-bill balances). Those are two independent computations
 * of the same money, so a delta means one of them is wrong — and neither side knows which.
 *
 * **This alert exists because the number had nowhere to go.** `accounting:sync-ledger` printed the
 * delta with `warn()` and stopped there; on cron that is `/dev/null`. Its sibling alert
 * (`LedgerSyncFailedNotification`) only fires for documents that THREW, and this class of bug
 * throws nothing — a drifting ledger with zero failed documents took the early return. So the one
 * number that says "the books no longer agree with themselves" was the one number nobody could see.
 *
 * Fired once on the transition INTO drift, not nightly: a repeated message about a known delta is a
 * message people filter, and this one has to survive being read.
 */
class BooksDriftDetectedNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public float $arDelta, public float $apDelta) {}

    /**
     * Mail as well as the bell, for the same reason as the sync-failure alert: the accountant who
     * needs this may not open /admin for days, and every day it goes unseen the two sides drift
     * further apart and the eventual reconciliation covers more ground.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'books_drift_detected',
            'ar_delta' => $this->arDelta,
            'ap_delta' => $this->apDelta,
            'title' => __('admin.notifications.books_drift_title'),
            'body' => __('admin.notifications.books_drift_body', [
                'ar' => number_format($this->arDelta, 2),
                'ap' => number_format($this->apDelta, 2),
            ]),
            'icon' => 'heroicon-o-scale',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
