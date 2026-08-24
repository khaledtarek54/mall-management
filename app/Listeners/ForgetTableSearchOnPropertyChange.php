<?php

namespace App\Listeners;

use Filament\Events\TenantSet;
use Illuminate\Session\Store;

/**
 * Drop every remembered table SEARCH when the operator switches property.
 *
 * ## The defect, measured
 *
 * `App\Support\TableDefaults` turns on `persistSearchInSession()` for every table in the panel,
 * which is right — a clerk who searches a list, opens a record and comes back should find their
 * search still there. What is wrong is the KEY. Filament namespaces the FILTERS session key by the
 * Filament tenant (`HasFilters::getTableFiltersSessionKey()` appends the tenant id) and namespaces
 * search, column-search and sort by the component class ALONE:
 *
 *     CanSearchRecords::getTableSearchSessionKey()  →  "tables.{md5(static::class)}_search"
 *
 * So a search follows the operator across the property switcher. Proven with two tenants on one
 * `ListInvoices`: typing `ZARA-ONLY-IN-A` in Mall A and then opening Mall B's invoice list returned
 * that same string still applied — the list comes back EMPTY, with a search box the operator has
 * long forgotten they filled. An empty list is the one state that reads as broken rather than as
 * filtered, which is why this is worth a listener rather than a note.
 *
 * ## Why here and not by overriding the key
 *
 * Overriding `getTableSearchSessionKey()` would need a trait on all sixty-seven list pages plus
 * every relation manager, and would still leave the sixty-eighth uncovered. The property switch is
 * ONE event. Clearing on it keeps the within-property convenience — which is the whole point of
 * persisting — and costs nothing on any request that does not switch.
 *
 * SORT and the column layout are deliberately left alone though their keys are just as unscoped: a
 * sort order and a set of visible columns mean the same thing in every property and can never
 * empty a list, so carrying them across is a convenience rather than a surprise.
 *
 * ## Only on an actual CHANGE
 *
 * `TenantSet` fires on every authenticated panel request, not only when the switcher is used, so
 * the listener records which property the remembered searches belong to and does nothing until
 * that differs. Without the guard it would clear the search on the very next request after it was
 * typed, which is the same bug pointing the other way.
 *
 * ## How it is wired
 *
 * By Laravel's listener AUTO-DISCOVERY, from the typed `TenantSet` parameter on `handle()` — there
 * is no `Event::listen` for it anywhere, deliberately. A first version added one in
 * `AppServiceProvider::boot()` and it registered the handler TWICE: harmless at runtime (the guard
 * above makes the second call a no-op) and quietly fatal to the test, because deleting the explicit
 * registration left the whole suite green while discovery still wired it. So
 * `RememberedTableStateDoesNotFollowTheOperatorTest` asserts the wiring directly — with nothing to
 * delete, "is this registered at all" is otherwise a property no test could fail on.
 */
class ForgetTableSearchOnPropertyChange
{
    /**
     * The session key holding which property the remembered table searches belong to.
     *
     * Namespaced under `tables.` like Filament's own keys so a session dump reads as one group.
     */
    public const PROPERTY_KEY = 'tables.search_property';

    public function handle(TenantSet $event): void
    {
        // The session STORE, not `request()->session()`. They are the same object in a real panel
        // request, and they are not under test: Livewire's test harness binds a started store to
        // the container without attaching it to the current Request, so `request()->hasSession()`
        // answers false and a listener written that way silently does nothing — which is exactly
        // how this fix first shipped green and broken. Filament stores the state through the
        // `session()` helper too, so reading it back the same way is also the honest pairing.
        $session = app()->bound('session.store') ? app('session.store') : null;

        // NOT guarded on `isStarted()`. A store can hold state and still answer false — it does
        // in the test harness, which is how the first version of this fix passed its own probe
        // while clearing nothing. The only thing worth guarding is that a session exists at all,
        // for the console and queue contexts where `setTenant()` is also called; writing to a
        // store nothing will persist is a harmless no-op there.
        if (! $session instanceof Store) {
            return;
        }

        $property = $event->getTenant()->getKey();
        $remembered = $session->get(self::PROPERTY_KEY);

        if ($remembered === $property) {
            return;
        }

        $session->put(self::PROPERTY_KEY, $property);

        // First request of a session: there is nothing remembered yet, so there is nothing to
        // clear. Recording the property is the whole job.
        if ($remembered === null) {
            return;
        }

        self::forgetSearches($session);
    }

    /**
     * Forget every table's remembered search and per-column search.
     *
     * Filament's keys are `tables.{md5}_search` and `tables.{md5}_column_search`, one pair per
     * component class, so there is no list to iterate — the session is swept by suffix. Sweeping
     * rather than enumerating is deliberate: a table this app never names (one from a package, or
     * the next resource somebody adds) is covered by being persisted at all.
     */
    public static function forgetSearches(Store $session): void
    {
        foreach (array_keys($session->get('tables', [])) as $key) {
            if (str_ends_with((string) $key, '_search') || str_ends_with((string) $key, '_column_search')) {
                $session->forget("tables.{$key}");
            }
        }
    }
}
