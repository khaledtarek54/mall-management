<?php

namespace App\Models\Concerns;

use App\Support\ChangeImpact;
use App\Support\Translate;
use DomainException;

/**
 * **The money on a committed document is immutable — you reverse it, you do not retype it.**
 *
 * This is the Yardi standard, and until 2026-08-28 it was enforced on **7 of the 24 posting
 * sources**. On the other seventeen every money field was classified `DERIVED` — *"editable; the
 * posted entry is voided and re-posted to match"* — with **no model-level guard at all**, so the
 * only thing between an operator and a restated month was a `disabled()` on a form. An importer,
 * the API, a console command or a second screen walks straight past that. `FixedAsset`'s edit form
 * had **zero** `disabled()` calls, so the acquisition cost of a capitalised, depreciating asset
 * retyped freely — and the depreciation entries already posted kept the OLD cost basis, leaving two
 * truths about one asset.
 *
 * ## The locked list is the REGISTRY, not a copy of it
 *
 * `ChangeImpact::refusedFields()` is the single statement of which fields may not move once a
 * document is committed, and this reads it directly. So promoting a field from `DERIVED` to
 * `REFUSED` **is** the lock — there is no second list to keep in step, and
 * `ChangeImpactConformanceTest` already proves every REFUSED field by dirtying it on a committed
 * fixture, which makes that gate the witness for this trait too.
 *
 * ## Guarding on the ORIGINAL state, not the new one
 *
 * `isCommittedMoney()` is asked of the record as it was BEFORE this save. Asking the in-memory model
 * would refuse the very transition that commits it — approving a payroll run writes `status` and the
 * amounts in one save, and a guard reading the new status would call that run committed and refuse
 * its own approval. The seven hand-written guards this generalises all learned that the same way;
 * `Payment`'s says so in as many words.
 *
 * ## What it deliberately does not catch
 *
 * `saveQuietly()`. Model events do not fire, so `Payroll::recomputeFromLines()` and every other
 * system re-derivation passes through untouched. That is the same boundary every other model guard
 * in this codebase sits on, and it is what keeps the distinction meaningful: this refuses what a
 * PERSON typed, not what the system computed.
 */
trait RefusesRestatementOfCommittedMoney
{
    /**
     * Is this document past the point where its money may be changed?
     *
     * Answer it about the PERSISTED record — use `getOriginal()` for any status this save might be
     * moving. Each implementation should read like the `committed` sentence in
     * `ChangeImpact::POLICY`, because that sentence is what the conformance gate builds its fixture
     * from: if the two disagree, the gate proves a refusal in a state the app never reaches.
     */
    abstract public function isCommittedMoney(): bool;

    public static function bootRefusesRestatementOfCommittedMoney(): void
    {
        static::updating(function (self $model) {
            $refused = ChangeImpact::refusedFields(static::class);

            // Cheapest possible exit, and it runs on every save of every one of these documents:
            // nothing dirty is locked, so there is no reason to ask the more expensive question.
            $dirty = array_values(array_filter($refused, fn (string $field) => $model->isDirty($field)));

            if ($dirty === [] || ! $model->isCommittedMoney()) {
                return;
            }

            throw new DomainException(__('admin.refusals.immutable_committed_money', [
                // The FIELD as the screen names it, never the raw column. An operator told
                // "acquisition_cost is immutable" has been handed half a sentence of database
                // schema; `admin.fields.*` is the catalogue the forms label from, so the refusal
                // and the form say the same word — in Arabic too.
                'field' => Translate::orHumanized('admin.fields.'.$dirty[0], $dirty[0]),
            ]));
        });
    }
}
