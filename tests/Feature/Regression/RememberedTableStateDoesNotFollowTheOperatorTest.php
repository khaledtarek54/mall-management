<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Listeners\ForgetTableSearchOnPropertyChange;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A remembered list is a convenience until it follows you somewhere it does not belong.
 *
 * `App\Support\TableDefaults` persists filters, search, column-searches, sort and the column layout
 * for EVERY table in the panel, which is right: a clerk who filters Invoices, opens one to chase it
 * and presses back should find their filter still there. Two things were wrong with how far that
 * memory reached, and both were measured before they were fixed.
 *
 * **1. A search followed the operator across the property switcher.** Filament namespaces the
 * FILTERS session key by the Filament tenant — `HasFilters::getTableFiltersSessionKey()` appends
 * the tenant id — and namespaces search, column-search and sort by the component class alone. So
 * typing `ZARA` in Mall A and then opening Mall B's invoice list re-applied it: the list came back
 * EMPTY, with a search box the operator had long forgotten they filled. An empty list is the one
 * state that reads as broken rather than as filtered, which is why this is a defect and not a
 * quirk. `ForgetTableSearchOnPropertyChange` clears the search keys when the property actually
 * changes; SORT and the column layout are deliberately left to carry across, because they mean the
 * same thing in every property and can never empty a list.
 *
 * **2. The "All records" escape did not escape.** A saved view is a URL, and Filament restores a
 * session filter only when the corresponding property is still empty after the query string is
 * bound — so a view that NAMES a filter wins and a view that names none silently does not. Worst
 * for the menu's "All records" link, whose entire job is to get back to the plain list and which
 * carries `?tableView=none` precisely because an empty query string is indistinguishable from a
 * bare page load. Measured: `?tableView=none` came back still carrying `status = draft`.
 *
 * Every refusal here is paired with a control that must still SUCCEED — a table that remembered
 * nothing at all would satisfy both refusals and read as a pass.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->mallA = makeAsset(['code' => 'RTA', 'name' => 'Remembered A']);
    $this->mallB = makeAsset(['code' => 'RTB', 'name' => 'Remembered B']);

    // `setTenant()` WITHOUT `isQuiet` — the flag suppresses `TenantSet`, and a test that switched
    // property quietly would prove nothing about a listener bound to that event.
    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));
});

it('remembers a search within one property', function () {
    Filament::setTenant($this->mallA);
    Livewire::test(ListInvoices::class)->set('tableSearch', 'REMEMBER-ME');

    // The CONTROL. Re-opening the same list in the same property must still carry it, or the
    // refusal below would pass for the wrong reason — and the persistence this whole feature is
    // built on would be gone.
    expect(Livewire::test(ListInvoices::class)->instance()->tableSearch)->toBe('REMEMBER-ME');
});

it('drops that search the moment the operator switches property', function () {
    Filament::setTenant($this->mallA);
    Livewire::test(ListInvoices::class)->set('tableSearch', 'ZARA-ONLY-IN-A');

    Filament::setTenant($this->mallB);

    expect(Livewire::test(ListInvoices::class)->instance()->tableSearch)->toBe('');
});

it('keeps the sort across properties, deliberately', function () {
    Filament::setTenant($this->mallA);
    // `tableSort` is ONE string, `column:direction` — see App\Support\ResourceLink, which
    // documents the same trap for the query string.
    Livewire::test(ListInvoices::class)->set('tableSort', 'due_date:asc');

    Filament::setTenant($this->mallB);

    // Not an oversight. A sort order means the same thing in every property and can never empty a
    // list, so carrying it across is the convenience the persistence exists for. If this ever needs
    // to change, change it here first — a sweep that clears "everything unscoped" would take this
    // with it and nobody would notice.
    expect(Livewire::test(ListInvoices::class)->instance()->tableSort)->toBe('due_date:asc');
});

it('records which property the remembered searches belong to', function () {
    Filament::setTenant($this->mallA);

    expect(session(ForgetTableSearchOnPropertyChange::PROPERTY_KEY))->toBe($this->mallA->getKey());

    Filament::setTenant($this->mallB);

    expect(session(ForgetTableSearchOnPropertyChange::PROPERTY_KEY))->toBe($this->mallB->getKey());
});

it('does not clear the search on an ordinary request that sets the same property again', function () {
    Filament::setTenant($this->mallA);
    Livewire::test(ListInvoices::class)->set('tableSearch', 'STILL-HERE');

    // `TenantSet` fires on EVERY authenticated panel request, not only when the switcher is used.
    // Without the "did it actually change" guard the listener would clear the search on the very
    // next request after it was typed — the same bug pointing the other way.
    Filament::setTenant($this->mallA);
    Filament::setTenant($this->mallA);

    expect(Livewire::test(ListInvoices::class)->instance()->tableSearch)->toBe('STILL-HERE');
});

it('lets a saved-view link land on a clean list', function () {
    Filament::setTenant($this->mallA);

    Livewire::test(ListInvoices::class)
        ->set('tableFilters.status.value', 'draft')
        ->set('tableSearch', 'STALE');

    // The CONTROL first: with no view named, the remembered state is exactly what should come back.
    $remembered = Livewire::test(ListInvoices::class);
    expect($remembered->instance()->tableFilters['status']['value'] ?? null)->toBe('draft');
    expect($remembered->instance()->tableSearch)->toBe('STALE');

    // "All records" — the menu's escape hatch. It carries `?tableView=none` because a link to the
    // plain list has an EMPTY query string, which the default-view redirect reads as "nothing
    // asked for".
    $escaped = Livewire::withQueryParams(['tableView' => 'none'])->test(ListInvoices::class);

    expect($escaped->instance()->tableFilters['status']['value'] ?? null)->toBeNull();
    expect($escaped->instance()->tableSearch)->toBe('');
});

it('lets a view that names a filter still apply it over the cleared state', function () {
    Filament::setTenant($this->mallA);

    Livewire::test(ListInvoices::class)->set('tableFilters.status.value', 'draft');

    // A view is a URL: clearing the session must not also throw away what the link itself carries.
    // `filters`, not `tableFilters` — Filament publishes the property under an aliased name and
    // `?tableFilters[...]` is silently ignored. App\Support\ResourceLink exists for exactly this.
    $applied = Livewire::withQueryParams([
        'tableView' => 'none',
        'filters' => ['status' => ['value' => 'issued']],
    ])->test(ListInvoices::class);

    expect($applied->instance()->tableFilters['status']['value'] ?? null)->toBe('issued');
});
