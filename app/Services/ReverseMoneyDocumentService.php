<?php

namespace App\Services;

use App\Services\Accounting\LedgerPoster;
use App\Support\ReversalReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * **Reverse a money document whose undo is a soft-delete.**
 *
 * Four of the 24 posting sources carry no status column at all — `MarketingSpend`, `EmployeeAdvance`,
 * `Custody`, `FixedAsset` — so there is no `cancelled` for them to move to. Their reversal is the
 * soft-delete, which `LedgerPoster::effectivePayload()` reads as "no ledger effect" and voids the
 * entry for. That mechanism was already correct and already the documented intent: every one of
 * those models says so in its own `#[DeletionAllowed]` reason ("operational: reversed rather than
 * removed").
 *
 * **What was missing was the act, not the mechanism.** On 2026-08-28 the only way to reverse a
 * marketing spend was a plain `DeleteAction` on a relation manager — a GL document with a Delete
 * button, no reason asked, nothing recorded beyond the row vanishing from the list; and a fixed
 * asset, an employee advance and a custody float had no reversal offered anywhere. So a 1,590 spend
 * booked to the wrong budget was undone by an operator pressing Delete, and six months later nobody
 * could say whether it was an error, a duplicate, or a cost someone did not want seen.
 *
 * This is the same act with the two things Yardi requires of a reversal: **it is called a reversal**,
 * and it **records why**, into the audit trail rather than a column anyone can edit afterwards.
 *
 * **It does not decide WHETHER the document may be reversed** — that is the call site's gate, because
 * the answer differs per source (a fixed asset that has been depreciating is retired through its own
 * disposal path, not through this). It refuses only the two things that are true everywhere: a
 * document that is not a GL source at all, and one already reversed.
 */
class ReverseMoneyDocumentService
{
    /**
     * @param  string  $event  the activity event — `reversed` or `cancelled`. The word the operator
     *                         saw on the button, so the trail and the screen agree.
     */
    public function reverse(Model $document, ?string $reason = null, string $event = 'reversed'): void
    {
        if (! array_key_exists($document::class, LedgerPoster::JOURNALIZERS)) {
            throw new \DomainException(__('admin.refusals.not_a_money_document'));
        }

        if (method_exists($document, 'trashed') && $document->trashed()) {
            return; // already reversed — idempotent, like every other reversal here
        }

        DB::transaction(function () use ($document, $reason, $event) {
            // The reason FIRST, while the subject is still live. Recording it after the delete would
            // work — the trail keys on id, not on the row's existence — but a `performedOn` against a
            // model instance whose delete has already fired reads as an act on a deleted record, and
            // the ordering is the kind of thing that quietly stops working when someone adds a
            // `deleting` hook that touches the same row.
            ReversalReason::record($document, $event, $reason);

            $document->delete();
        });
    }
}
