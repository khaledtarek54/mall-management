<?php

namespace App\Support;

use App\Models\Concerns\GuardsPostingDate;
use App\Models\JournalEntry;
use App\Services\Accounting\LedgerPoster;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * **A document whose journal entry sits in a CLOSED period may not have its money changed.**
 *
 * WHY THIS EXISTS, measured rather than argued. Marketing spend #1 — 1,590.00, dated 2026-08-18,
 * posted as `JE-0519` — with its August period closed at month-end. An operator opened the record
 * and retyped 2,824.00:
 *
 *   SAVE ACCEPTED.  GL still says 1,590.  The document says 2,824.  wouldChange() = true, for ever.
 *
 * Every layer behaved exactly as designed. `sync()` voided the entry into today's open period, tried
 * to re-post at the August date, and `assertOpenPeriodFor()` refused — so the transaction rolled back
 * and the books stayed intact, which is the *correct* atomicity that `CHANGE-IMPACT-PLAN.md` §9
 * already records as a non-finding. What nothing owned is the other side: the DOCUMENT had already
 * committed. The operator saw "Saved ✓" and walked away, `billing:reconcile --deep` now fails
 * permanently, `books_tie_out` on `/health` is permanently red, and `atriom:preflight` — which
 * `deploy.sh` runs on every release — blocks the next deploy for a reason unrelated to the deploy.
 *
 * **The rule had an owner in a docblock and none in code.** {@see GuardsPostingDate}
 * is `isDirty`-only on purpose: its rule is *"nobody MOVES an entry into a sealed period"*, and its
 * docblock names the immutability guards as the owner of the other rule — *"old records are read-only"*.
 * Those guards live on 7 of the 24 posting sources, and `MarketingSpend` is not one of them. This is
 * that second rule, applied to all 24 at once.
 *
 * **One wildcard seam, not a trait on 24 models** — the reasoning that put `ValueSets::guard()` and
 * `AuthorizedAction` where they are: a guard that must never be missing belongs where the
 * twenty-fifth source is covered before anyone remembers it, not in N files a conformance test then
 * has to police.
 *
 * **It is deliberately STRICTER than Yardi, and that is a stated deviation.** Voyager's control is the
 * post month: it would accept the edit and book the correction into the current open month. Atriom
 * already chose the opposite on reversals (`JournalPostingService::reversalPeriod()` prefers the
 * original period, refusing rather than silently shifting — CHANGE-IMPACT-PLAN §5), and this operator
 * reports monthly, so quietly moving August's cost into October would contradict a decision already
 * taken. Refusing is honest: it names the period and the two ways out.
 *
 * **What it does NOT block**, and each is load-bearing rather than an exception:
 *
 *  - a change that REMOVES the ledger effect — void, cancel, refund, soft-delete. Those only reverse,
 *    and a reversal always finds a period. Blocking them would make a document posted into a
 *    now-closed month impossible to void, which is the opposite of the intent.
 *  - a change the ledger cannot see — `notes`, `dunning_level`, a settlement recompute. The payload
 *    comes back identical and `sync()` no-ops.
 *  - **a date MOVING into a sealed period**, which is the OTHER rule and has its own owner.
 *    {@see GuardsPostingDate} and the service-level guards registered in
 *    `App\Support\PostingDateGuards` cover that direction, and `PostingDateGuardConformanceTest`
 *    makes every source declare which of the two forms it uses. This guard short-circuits on the
 *    EXISTING entry's period for cost — one indexed lookup instead of a journalizer run on every
 *    save — so it deliberately does not re-answer a question another registry already gates.
 *  - anything written with `saveQuietly()`. Model events do not fire, so `Invoice::recomputeTotals()`
 *    (which flips `status` to `paid` when a receipt lands on an old invoice) and the CAM true-up both
 *    pass straight through. That is not an oversight: those are system re-derivations of state the
 *    operator did not type, and every other model guard in this codebase sits in `updating` for the
 *    same reason. Receipting an invoice from a closed month must keep working.
 */
class SealedPeriod
{
    /**
     * Refuse a save that would leave this document's ledger entry stranded in a sealed period.
     *
     * @throws DomainException
     */
    public static function guard(Model $model): void
    {
        if (! $model->exists || ! array_key_exists($model::class, LedgerPoster::JOURNALIZERS)) {
            return;
        }

        // Cheap pre-filter, and the reason this costs nothing on the hot path: only a field the
        // ledger actually reads can strand an entry. `ChangeImpact` already decided which those are
        // per source, so this is an array lookup — no query, no journalizer — and it returns on
        // every ordinary save (a note, a dunning level, a communication stamp).
        if (! self::touchesTheLedger($model)) {
            return;
        }

        // One indexed lookup before anything expensive. The overwhelming majority of money documents
        // being edited are in an OPEN month, and they stop here.
        $entry = JournalEntry::query()
            ->where('source_type', $model->getMorphClass())
            ->where('source_id', $model->getKey())
            ->where('status', 'posted')
            ->latest('id')
            ->first();

        // **A document with no entry yet can still be ISSUED INTO a sealed month.** The early return
        // was `! $entry`, and a draft has no entry — `InvoiceJournalizer` returns null for one — so
        // this guard skipped exactly the document that was about to gain an entry. Reachable since
        // a draft can outlive a period close (SW-215): raise a draft dated 10 March, close March, in
        // May set it to Issued. The save commits, `SyncDocumentToLedger` then refuses at
        // `assertOpenPeriodFor()` and only LOGS, and the result is AR on the document with nothing
        // in the ledger — `billing:reconcile --deep` permanently red, `books_tie_out` red, and
        // `atriom:preflight` blocking the next deploy for a reason unrelated to the deploy. Verbatim
        // the failure this class was written to end.
        //
        // `GuardsPostingDate` cannot see it either: that one is `isDirty($column)` by design, and
        // issuing a draft moves no date.
        //
        // **The narrow question is "is this document BECOMING postable", not "did its status move".**
        // `status` dirty alone was the first attempt and it is too wide: `issued → overdue` creates
        // no entry that did not exist, and the late-fee flow does exactly that to an original invoice
        // whose own month has since closed — so it refused a legitimate April penalty because
        // January was sealed, which is the very case `LateFeeRecognisedWhenIncurredTest` exists to
        // keep working.
        //
        // Asked of the ORIGINAL attributes: a document that posted nothing before and posts
        // something now is one gaining an entry. `status` dirty stays as the cheap pre-filter, so
        // the extra journalizer call is paid only by a status change on an unposted row.
        if (! $entry) {
            if (! $model->isDirty('status')) {
                return;
            }

            $before = (clone $model)->setRawAttributes($model->getRawOriginal(), sync: true);

            // FAIL OPEN, for the same reason the `sealedPeriodBlocking()` call below does and with
            // the same consequence if it is forgotten: a journalizer THROWS when the chart cannot
            // answer it — an unmapped posting role, a retired account — and letting that escape
            // turns an incomplete accounting setup into a hard failure on every ordinary status
            // change of every money document. Measured when it was missing: 66 red across the
            // regression suite, none of them about sealed periods.
            try {
                if (app(LedgerPoster::class)->postsAnything($before)) {
                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('Sealed-period guard could not tell whether '.$model::class.' #'.$model->getKey().' was already postable: '.$e->getMessage());

                return;
            }
        } elseif (! PostingDate::isClosed($entry->entry_date)) {
            return;
        }

        // The authoritative answer, and it belongs to the poster: only `LedgerPoster` holds both
        // `effectivePayload()` and `matches()`, which are the two halves of the decision `sync()`
        // itself makes. Asking it here is what stops this guard drifting away from the engine.
        try {
            $blocked = app(LedgerPoster::class)->sealedPeriodBlocking($model);
        } catch (\Throwable $e) {
            // FAIL OPEN, deliberately. A journalizer throws when the chart cannot answer it — an
            // unmapped posting role, a retired account. Refusing the operator's save because the
            // ACCOUNTING setup is incomplete would block ordinary work for a reason they cannot act
            // on and did not cause, and it is a strictly worse failure than the drift this prevents.
            Log::warning('Sealed-period guard could not evaluate '.$model::class.' #'.$model->getKey().': '.$e->getMessage());

            return;
        }

        if ($blocked === null) {
            return;
        }

        throw new DomainException(__('admin.posting.errors.sealed_period_edit', [
            'month' => $blocked->format('Y-m'),
        ]));
    }

    /**
     * Is any dirty attribute one the ledger reads?
     *
     * REFUSED and DERIVED are exactly the verdicts `ChangeImpact` defines as reaching a journal
     * line, a date or the property dimension. NEUTRAL and DESCRIPTIVE explicitly do not — a
     * DESCRIPTIVE field reaches the entry's text, and `matches()` deliberately does not compare
     * text. An UNCLASSIFIED field is treated as touching the ledger: `ChangeImpactConformanceTest`
     * fails the build on one, so it can only exist mid-change, and erring toward the check is the
     * safe direction (the poster then gives the real answer anyway).
     */
    private static function touchesTheLedger(Model $model): bool
    {
        foreach (array_keys($model->getDirty()) as $field) {
            $verdict = ChangeImpact::verdictFor($model::class, $field);

            if ($verdict === ChangeImpact::REFUSED || $verdict === ChangeImpact::DERIVED || $verdict === null) {
                return true;
            }
        }

        return false;
    }
}
