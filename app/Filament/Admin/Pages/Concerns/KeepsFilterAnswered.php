<?php

namespace App\Filament\Admin\Pages\Concerns;

/**
 * A report filter whose blank is not an answer snaps back instead of breaking the page.
 *
 * **The defect.** Filament renders a clear (×) on every `Select` unless told otherwise, and
 * clearing one sends `null` for the bound Livewire property. Assigning null to a NON-NULLABLE typed
 * property leaves it uninitialised, so the very next read throws `PropertyNotFoundException` and the
 * operator gets a 500 on a page they were only filtering. Nothing is saved, nothing is logged, and
 * the only way back is a reload. Thirteen report screens had it — every financial statement through
 * the shared bar, plus the ageing bucket, the billing-run preview, month-end close, the reports
 * index, tax depreciation, the VAT and withholding returns and the revenue forecast.
 *
 * **Two halves.** `->selectablePlaceholder(false)` removes the affordance, which is the right UX:
 * there is no such thing as "no fiscal year" for a statement, so offering the blank was offering an
 * action that cannot work. But a pinned control is a UI truth and not a gate — the value still
 * arrives in the Livewire payload — which is the same reason `PropertyField` keeps its write guard.
 * The RESTORE below is what makes the page survive that payload.
 *
 * **The nullable type is defensive, and stating that honestly matters**: mutation-testing this
 * showed the restore works even against a NON-nullable property, because `??` is isset-based and an
 * uninitialised typed property is simply not set. What the nullable type buys is independence from
 * that upstream detail — Livewire currently UNSETS rather than raising a TypeError, and a release
 * that changed its mind would otherwise turn a filter click into a 500 again. Same reasoning as
 * `FilamentActionDispatchContractTest`: do not build on an upstream behaviour you have not pinned.
 *
 * **Why restore rather than let readers cope.** `$this->year` alone has seventeen readers across the
 * statements; giving each a fallback would be seventeen chances to forget one, and the seventeenth
 * would fail as a wrong FIGURE rather than as an error. Restoring at the single point where null can
 * arrive keeps every reader's assumption true.
 *
 * A page lists what it cannot answer blank in {@see answerableFilters()}, mapping the property to
 * the value it falls back to. Anything not listed keeps Livewire's ordinary behaviour, so a filter
 * whose blank IS meaningful — `period` on the ledger bar means "full year" — is untouched.
 */
trait KeepsFilterAnswered
{
    /**
     * Filters whose blank is not an answer, and what each falls back to.
     *
     * @return array<string, mixed>
     */
    abstract protected function answerableFilters(): array;

    /**
     * Livewire's post-update hook for EVERY property, which is the one place a cleared filter lands
     * however it was cleared — the ×, a keyboard reset, or a crafted payload.
     */
    public function updated(string $property): void
    {
        $this->restoreAnsweredFilter($property);
    }

    protected function restoreAnsweredFilter(string $property): void
    {
        $answers = $this->answerableFilters();

        if (! array_key_exists($property, $answers)) {
            return;
        }

        if (($this->{$property} ?? null) === null) {
            $this->{$property} = $answers[$property];
        }
    }
}
