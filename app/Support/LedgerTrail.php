<?php

namespace App\Support;

use App\Models\JournalEntry;
use App\Services\Accounting\LedgerPoster;
use Illuminate\Database\Eloquent\Model;
use App\Models\JournalLine;
use App\Models\LedgerAccount;

/**
 * The read model behind "what did this document do to the ledger?".
 *
 * Every piece of this was already in the database — `journal_entries.source_type/source_id`,
 * `reversal_of_id`, the lines, the post-month override — and none of it was on any screen. An
 * operator could not see an invoice's entry, and an accountant looking at an entry could not get
 * back to the document that caused it. The link existed only in the entry's description text.
 *
 * That matters more here than in a system that posts once. Atriom's ledger is DERIVED: change a
 * posted document and a queued job voids the entry and posts a fresh one, with the description
 * "Superseded by an updated document" and no notification to anyone. The correction is real, correct
 * and completely silent. This is what makes it visible — the chain of what was posted, what
 * superseded it, and whether the document has drifted since.
 *
 * Read-only and side-effect free: `wouldChange()` is `LedgerPoster::sync()`'s dry run — no lock, no
 * write — so rendering this can never move the books.
 */
class LedgerTrail
{
    /**
     * @return array{
     *     posts: bool,
     *     entry: ?JournalEntry,
     *     reversal: ?JournalEntry,
     *     superseded_by: ?JournalEntry,
     *     history: array<int, JournalEntry>,
     *     drifted: bool,
     *     restates_reported: bool,
     *     reported_reason: ?string,
     *     post_month: ?\Carbon\CarbonImmutable,
     * }
     */
    public static function for(Model $source): array
    {
        $posts = array_key_exists($source::class, LedgerPoster::JOURNALIZERS);

        if (! $posts) {
            return [
                'posts' => false, 'entry' => null, 'reversal' => null, 'superseded_by' => null,
                'history' => [], 'drifted' => false, 'post_month' => null,
                'restates_reported' => false, 'reported_reason' => null,
            ];
        }

        $history = JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->with('lines.account', 'asset')
            ->orderBy('id')
            ->get();

        // The entry that speaks for the document TODAY. A voided entry has been superseded — by an
        // edit, a cancel or a delete — and the one that matters is the live one, if there is one.
        /** @var ?JournalEntry $entry */
        $entry = $history->firstWhere('status', 'posted') ?? $history->last();

        // Reversals point AT an entry rather than at the source, so they are never in `history`.
        /** @var ?JournalEntry $reversal */
        $reversal = $entry && $entry->status === 'void'
            ? JournalEntry::query()->where('reversal_of_id', $entry->id)->with('lines.account')->latest('id')->first()
            : null;

        /** @var ?JournalEntry $supersededBy */
        $supersededBy = $entry && $entry->status === 'void'
            ? $history->first(fn (JournalEntry $e) => $e->id > $entry->id && $e->status === 'posted')
            : null;

        // Derived once: wouldChange() re-builds the document's payload, so asking twice would
        // double the cost of rendering the panel for no new information.
        $drifted = app(LedgerPoster::class)->wouldChange($source);

        return [
            'posts' => true,
            'entry' => $entry,
            'reversal' => $reversal,
            'superseded_by' => $supersededBy,
            'history' => $history->all(),
            // True when the document has moved since it was posted, so the next sync will correct
            // the ledger. Not an error — it is the system working — but the operator should know
            // the statements are a few seconds behind their edit rather than wrong.
            'drifted' => $drifted,
            'post_month' => PostMonth::isOverridden($source) ? PostMonth::forSource($source) : null,
            // The entry sits in a month an owner has already been given a statement for, and the
            // document has moved since — so correcting it will restate a figure someone is holding.
            // Reported is not the same as closed: a statement is usually issued weeks before the
            // period is sealed, and that gap is where this bites.
            'restates_reported' => $entry !== null && $drifted
                && ReportedPeriod::isReported($entry->entry_date, $entry->asset_id),
            'reported_reason' => $entry !== null
                ? ReportedPeriod::reasonFor($entry->entry_date, $entry->asset_id)
                : null,
        ];
    }

    /**
     * The entry's lines as display rows: "11201001 · Accounts Receivable   Dr 11,400.00".
     *
     * @return array<int, string>
     */
    public static function lineRows(?JournalEntry $entry): array
    {
        if (! $entry) {
            return [];
        }

        $rows = [];

        foreach ($entry->lines as $line) {
            if (! $line instanceof JournalLine) {
                continue;
            }

            $debit = (float) $line->debit;
            $side = $debit > 0
                ? __('admin.fields.debit').' '.number_format($debit, 2)
                : __('admin.fields.credit').' '.number_format((float) $line->credit, 2);

            $account = $line->getRelationValue('account');
            $code = $account instanceof LedgerAccount ? $account->code : '—';
            $name = $account instanceof LedgerAccount ? $account->displayName() : '—';

            $rows[] = trim($code.' · '.$name.'   '.$side);
        }

        return $rows;
    }
}
