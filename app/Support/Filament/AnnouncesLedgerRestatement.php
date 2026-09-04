<?php

namespace App\Support\Filament;

use App\Filament\Actions\LedgerEntryAction;
use Filament\Notifications\Notification;

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

        if (! $notification instanceof Notification) {
            return $notification;
        }

        // The FIGURES, not a boolean — CHANGE-IMPACT-PLAN §6.3 asked for "this will reverse EGP
        // 12,400 and re-post EGP 13,050" and the note is worth much less without them: an operator
        // who meant to change a description cannot tell a harmless re-derive from one that moves
        // the month's revenue.
        //
        // The resolve and the wording live in {@see LedgerRestatement} rather than here, because
        // `getSavedNotification()` is an `EditRecord` method and so reaches exactly the nine money
        // Edit PAGES — while a GL source is also edited from a relation manager's modal, which had
        // no notice at all. One seam, so the two surfaces cannot word it differently.
        $notice = LedgerRestatement::noticeFor($this->getRecord());

        return $notice === null ? $notification : $notification->body($notice);
    }
}
