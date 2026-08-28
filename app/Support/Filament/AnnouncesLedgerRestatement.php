<?php

namespace App\Support\Filament;

use App\Filament\Actions\LedgerEntryAction;
use App\Services\Accounting\LedgerPoster;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * **"Saved" is not enough when the save moved the books.**
 *
 * Atriom's ledger is DERIVED (CHANGE-IMPACT-PLAN §2): change a posted document and a queued job
 * voids its journal entry and posts a fresh one, described as *"Superseded by an updated document"*.
 * The correction is real, correct — and completely silent. Until 2026-08-28 an operator who changed
 * the bank account on a captured receipt, re-allocated it across different invoices, or re-dated an
 * expense saw a plain **Saved** toast and nothing else, while the general ledger was rewritten
 * behind them within seconds.
 *
 * §6.3 of that plan built the drift NOTE — visible on the ledger panel, if you opened it. This is
 * the half that reaches the person who caused it, at the moment they caused it, which is the case
 * §9 F3 identified as uncovered and left open.
 *
 * **Not a notification, deliberately.** F3 measured and declined an alert per re-derive: it would
 * fire on every late fee and every CAM run, and an alert arriving dozens of times a month is one
 * nobody reads. This is a line on a toast the operator is already looking at — it costs them nothing
 * when they expected it and tells them something when they did not.
 *
 * **Asked AFTER the save, not before.** `wouldChange()` is `sync()`'s dry run against the document's
 * CURRENT state, so asking it here answers "is the ledger now out of step with what I just saved" —
 * which is the true statement. Asking before the write would answer about the old values.
 *
 * Read-only and side-effect free: no lock, no write (the same property that lets
 * {@see LedgerEntryAction} render the panel), so the toast can never itself move the books.
 */
trait AnnouncesLedgerRestatement
{
    protected function getSavedNotification(): ?Notification
    {
        $notification = parent::getSavedNotification();
        $record = $this->getRecord();

        if (! $notification instanceof Notification || ! $record instanceof Model) {
            return $notification;
        }

        // Best-effort. A journalizer that cannot resolve an account throws, and a TOAST is never
        // worth failing a save that already committed — the operator would see an error page after
        // a successful write, which is the most confusing outcome available.
        try {
            // The FIGURES, not a boolean — CHANGE-IMPACT-PLAN §6.3 asked for "this will reverse EGP
            // 12,400 and re-post EGP 13,050" and the note is worth much less without them: an
            // operator who meant to change a description cannot tell a harmless re-derive from one
            // that moves the month's revenue.
            $pending = app(LedgerPoster::class)->pendingRestatement($record);
        } catch (\Throwable) {
            return $notification;
        }

        if ($pending === null) {
            return $notification;
        }

        return $notification->body(self::sentenceFor($pending));
    }

    /**
     * Three shapes, three sentences, because they are three different things happening to the books
     * and an operator acts differently on each: a first post, a reversal, or a reversal followed by
     * a re-post at a new figure.
     *
     * @param  array{from: ?float, to: ?float, date: ?string}  $pending
     */
    private static function sentenceFor(array $pending): string
    {
        $money = fn (float $amount): string => 'EGP '.number_format($amount, 2);

        return match (true) {
            $pending['from'] === null => __('admin.notifications.ledger_will_post', [
                'amount' => $money((float) $pending['to']),
            ]),
            $pending['to'] === null => __('admin.notifications.ledger_will_reverse', [
                'amount' => $money((float) $pending['from']),
            ]),
            // Same figure, different month — a re-dated document. "Reversed EGP 1,000 and re-posted
            // at EGP 1,000" reads as a no-op and hides the only thing that moved, which is the
            // PERIOD: one month's P&L understates and another overstates, by construction, and no
            // control account moves so the AR/AP tie-out cannot see it either. Say the month.
            abs((float) $pending['from'] - (float) $pending['to']) < 0.005 => __('admin.notifications.ledger_will_move_month', [
                'amount' => $money((float) $pending['to']),
                'month' => $pending['date'] === null
                    ? '—'
                    : CarbonImmutable::parse($pending['date'])->format('m/Y'),
            ]),
            default => __('admin.notifications.ledger_will_repost', [
                'from' => $money((float) $pending['from']),
                'to' => $money((float) $pending['to']),
            ]),
        };
    }
}
