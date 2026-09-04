<?php

namespace App\Support\Filament;

use App\Filament\Actions\LedgerEntryAction;
use App\Services\Accounting\LedgerPoster;
use App\Support\ChangeImpact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * **What a save just did to the books, in one sentence.**
 *
 * The wording half of {@see AnnouncesLedgerRestatement}, extracted so a save that does NOT happen on
 * an Edit page can say the same thing. `getSavedNotification()` is a `Filament\Resources\Pages\
 * EditRecord` method, so the trait reaches exactly the nine money Edit PAGES and nothing else — and
 * a GL source is also edited from a relation manager's modal, where `MarketingSpend` lives. Every
 * one of that model's fillables is classified DERIVED, whose definition in {@see ChangeImpact}
 * ends *"the operator must be told"*, and nothing told them: the spend's posted entry was voided and
 * re-posted behind a plain **Saved** toast.
 *
 * A second copy of the sentence in the relation manager would have been the drift this codebase
 * keeps recording — a wording fixed on one surface and not the other — so both read from here.
 *
 * Best-effort by construction: a journalizer that cannot resolve an account throws, and a TOAST is
 * never worth failing a save that has already committed. Read-only and side-effect free, the same
 * property that lets {@see LedgerEntryAction} render its panel, so announcing
 * can never itself move the books.
 */
class LedgerRestatement
{
    /**
     * The sentence for what is now pending on this record, or null when the books did not move.
     *
     * Asked AFTER the save, deliberately: `pendingRestatement()` is `sync()`'s dry run against the
     * document's CURRENT state, so it answers *"is the ledger now out of step with what I just
     * saved"*. Asking before the write would answer about the old values.
     */
    public static function noticeFor(?Model $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        try {
            $pending = app(LedgerPoster::class)->pendingRestatement($record);
        } catch (\Throwable) {
            return null;
        }

        return $pending === null ? null : self::sentenceFor($pending);
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
