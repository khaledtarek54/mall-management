<?php

namespace App\Support\Filament;

use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one event name a page or widget listens for when SOMETHING ELSE on the screen changed
 * the record it is describing.
 *
 * A Filament screen is not one Livewire component — it is several. An Edit page, each of its
 * relation managers, and each header widget are separate components with separate lifecycles,
 * and Livewire 3 re-renders a nested component only when its own round-trip runs. So a child
 * that re-derives the parent (a payroll line moving the run's net, a marketing spend moving the
 * fund's balance, a voided vendor-bill payment re-opening the bill's AR) leaves the parent's
 * figures on screen exactly as they were, and the operator's only recourse is F5. The numbers
 * are not wrong in the database; they are wrong on the glass, which is worse — nobody re-checks
 * a figure they just watched the system print.
 *
 * Livewire dispatches are global by default, so ONE event name is enough: the child says "the
 * record behind this screen moved", and whoever is describing it re-reads. Deliberately not
 * per-module event names — a second name is a second thing to forget, and this is exactly the
 * class of wiring that rots silently because a stale number looks like a real number.
 *
 * @see RefreshesRecordState   the Edit/View page side
 * @see RefreshesOnRecordChange the widget side
 */
final class RecordChanged
{
    /**
     * Prefixed because Livewire events share one global namespace with every package on the
     * page; an unprefixed `record-changed` is a name a third-party component could also pick.
     */
    public const EVENT = 'atriom-record-changed';

    /**
     * Announce that the record this screen is about has been re-derived elsewhere.
     *
     * Call it from the component that made the change — a relation manager, or a page action
     * that mutates something OTHER than its own form state.
     *
     * This used to add "the page's own actions do not need it: they refresh through
     * `RefreshesRecordState::refreshFormData()`". That was only ever true of an action that CALLS
     * `refreshFormData()` itself, and the acts that most needed it — the ones that hand the record
     * to a service which re-reads it under a lock — call nothing of the sort. See
     * {@see announceAfterAction()}, which now delivers to the announcing component too.
     */
    public static function dispatchFrom(Component $livewire): void
    {
        $livewire->dispatch(self::EVENT);
    }

    /**
     * Announce on behalf of an action that has just run.
     *
     * Called from {@see AuthorizedAction::call()} — the one seam every `Action::make()` in the
     * app already passes through, because `Action::make()` resolves out of the container. That
     * is deliberately the same seam the authorization layer uses, and for the same reason: this
     * wiring must not depend on whoever wrote the action remembering it. The thirteen commercial
     * lease actions all mutate the tenancy the Edit page and its summary widget are describing,
     * and not one of them would have carried a hand-written dispatch for long.
     *
     * `$result` is the action's return value. An action that returns a Response is handing the
     * operator a file (a PDF, a CSV) and changed nothing on screen, so it announces nothing —
     * otherwise every download would cost every listening component a pointless re-render.
     * The test is on the RETURN VALUE rather than on a list of action names, because a list is
     * a thing to keep up to date and a download is self-identifying.
     *
     * Filament's own `CreateAction` / `EditAction` / `DeleteAction` do NOT resolve through this
     * binding (`make()` resolves `static::class`, and those are subclasses), so a relation
     * manager built from them announces with `->after(fn ($livewire) => …dispatchFrom($livewire))`.
     */
    public static function announceAfterAction(?object $livewire, mixed $result): void
    {
        if ($result instanceof Response) {
            return;
        }

        if (! $livewire instanceof Component) {
            return;
        }

        // ── THE ANNOUNCER HAS TO HEAR ITSELF (2026-08-30) ────────────────────────────────────
        //
        // Livewire's `dispatch()` goes to OTHER components; the one that raised the event never
        // receives it. That was invisible while every act lived on a LIST — the list and the
        // record page are different components, so the page heard it. The moment an act moves
        // onto the record page it is the page dispatching, and the page is the only thing that
        // needed to listen.
        //
        // Measured on `EditUnit::remeasure`: the database read 412.00 and the form on screen went
        // on reading 300, under a success toast. This is the same failure `RefreshesRecordState`
        // exists for, arriving through a door it did not cover — and it was ALREADY shipped: none
        // of the thirteen commercial lease acts calls `refreshFormData()`, so `EditLease` has
        // declared nine `derivedStatePaths()` since the day it was written and not one of them has
        // ever been refilled.
        //
        // The siblings — relation managers, the summary widget — exactly as before.
        $livewire->dispatch(self::EVENT);

        // And the announcer itself, DIRECTLY rather than through the event bus. `->self()` exists
        // but is a browser round-trip: the listener would run on the NEXT request, so the operator
        // still reads the stale figure on the render they are looking at. Calling the listener
        // here refills in the same request, which is what makes the act and its result appear
        // together.
        //
        // Guarded on the trait rather than on `method_exists('refreshFormData')`: every Filament
        // record page has that method, and calling the stock one would refill from the page's
        // in-memory copy — the no-op this whole seam exists to replace.
        if (in_array(RefreshesRecordState::class, class_uses_recursive($livewire), true)) {
            $livewire->refreshRecordState();
        }
    }
}
