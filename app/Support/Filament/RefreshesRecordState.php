<?php

namespace App\Support\Filament;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\On;

/**
 * Makes a record page show what the database actually says, after something moved it.
 *
 * **The bug this fixes, proven rather than argued.** Filament's `refreshFormData()` refills the
 * form from `$this->getRecord()->attributesToArray()` — the page's IN-MEMORY copy of the record.
 * It never re-reads. That is fine when the action mutated that copy, and it is a no-op when the
 * action handed the record to a service that re-read it, which is what every money service here
 * does, deliberately and correctly: each opens by fetching its subject afresh as a LOCKING read
 * into a new variable, and works on that from then on — a different instance from the one the
 * page is holding. (Described in prose rather than quoted as code because
 * `ConcurrencyPolicyConformanceTest` greps for the call, and a docblock naming it would register
 * this file as one that takes a lock.)
 *
 * The lock discipline requires that re-read — a guard behind a lock must itself be a locking read,
 * see CLAUDE.md, "A lock serialises writers" — so the service is not wrong. The page is: it
 * refills from an instance nothing touched. Measured on `EditInvoice::void_invoice` — the row read
 * `cancelled` / `0.00` in the database while the form on screen still read `issued` / `10,000.00`,
 * with `refreshFormData(['status', 'balance', 'notes'])` sitting in the action doing visibly
 * nothing. Nineteen call sites across eight money pages had that shape — and exactly one of them,
 * the payroll-lines listener, re-read the record first, which is what the fix generalises.
 *
 * This is the worst kind of stale, because it is stale UNDER a success toast: the operator voids
 * an invoice, is told it worked, and reads a live balance of 10,000 on a cancelled document. The
 * only fix available to them is F5, and nothing on the screen suggests it.
 *
 * **The fix is one seam, not nineteen edits.** Re-read the record before refilling, always. A page
 * then cannot be wrong about this depending on which service its action happened to call — which
 * is the property that failed, since the call sites all looked identical and correct.
 *
 * `refreshFormData()` is Filament's own method, so every existing call site is fixed by using this
 * trait and nothing else changes. `RecordStateRefreshConformanceTest` fails the build if a page
 * calls `refreshFormData()` without it.
 *
 * **The second half is the listener.** A relation manager or sibling component that re-derives
 * this record announces it with {@see RecordChanged::dispatchFrom()}; the page re-reads and
 * refills whatever it declares in `derivedStatePaths()`. Declaring nothing is still useful — the
 * re-read alone fixes any `TextEntry->state(fn ($record) => …)` on the form, which resolves at
 * render time from the record rather than from form state.
 */
trait RefreshesRecordState
{
    /**
     * The form fields on this page that are DERIVED — recomputed by a service or by a child
     * record, never typed by the operator. These are the ones that go stale when something
     * else on the screen moves the record; a field the operator types is theirs, and refilling
     * it under them would discard an edit in progress.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return [];
    }

    /**
     * Re-read the record, then refill. The re-read is the entire point — see the class docblock.
     *
     * @param  array<int, string>  $statePaths
     */
    public function refreshFormData(array $statePaths): void
    {
        $record = $this->getRecord();

        if ($record?->exists) {
            try {
                $record->refresh();
            } catch (ModelNotFoundException) {
                // The record was deleted underneath us — by a DeleteAction on this page, or by
                // another component on the same screen. There is nothing to re-read, and 404-ing
                // an operator mid-action would be a worse answer than leaving the form as it is;
                // the page they land on next resolves the record properly. `refresh()` ignores
                // global scopes, so a SOFT-deleted record is still found and still refills.
                return;
            }
        }

        parent::refreshFormData($statePaths);
    }

    /**
     * Something else on the screen re-derived this record — re-read it and refill the derived
     * fields. Only the declared paths are refilled, so an operator part-way through editing the
     * form keeps their unsaved work.
     */
    #[On(RecordChanged::EVENT)]
    public function refreshRecordState(): void
    {
        // refreshFormData() re-reads (above). Call it even with no paths so the record is fresh
        // for render-time state closures; with paths, it refills them too.
        $this->refreshFormData($this->derivedStatePaths());
    }
}
