<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Models\TableView;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Saved list views — a named filter/sort state for a resource list.
 *
 * The property worth testing is not "a row was written". It is that a saved view is a **bookmark,
 * not a capability**: it stores a query string, and reopening one goes through the list's own
 * scoping exactly as a hand-typed URL does. Sharing a view therefore shares a set of filters and
 * never an access grant — which is the claim the migration makes, so it is the claim under test.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->active = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addYear(),
    ]);
    $this->draft = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'draft']);

    $this->user = makeUser('manager', [$this->asset->id]);
    $this->actingAs($this->user);
});

it('saves the filters the list is currently showing, under a name', function () {
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->set('tableFilters.status.value', 'draft')
            ->callAction('saveTableView', ['name' => 'My drafts']);
    });

    $view = TableView::sole();

    expect($view->name)->toBe('My drafts')
        ->and($view->resource)->toBe('leases')
        ->and($view->user_id)->toBe($this->user->id)
        ->and($view->is_shared)->toBeFalse()
        ->and($view->state['filters']['status']['value'])->toBe('draft');
});

it('stores only the filters actually set, not every empty slot', function () {
    // Filament's filter form fills EVERY registered filter — the leases list has twelve, of which
    // one is set. Storing all twelve would pin today's filter set into the URL forever, so a
    // filter later removed from the resource would come back as a query key nothing binds.
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->set('tableFilters.status.value', 'draft')
            ->callAction('saveTableView', ['name' => 'Only status']);
    });

    expect(array_keys(TableView::sole()->state['filters']))->toBe(['status']);
});

it('reopens as a URL that really narrows the list', function () {
    Livewire::withQueryParams([]);

    $view = TableView::create([
        'resource' => 'leases',
        'name' => 'Drafts',
        'state' => ['filters' => ['status' => ['value' => 'draft']]],
        'user_id' => $this->user->id,
    ]);

    asTenant($this->asset, function () use ($view) {
        // Exactly what the menu item links to.
        $ids = Livewire::withQueryParams($view->queryParameters())
            ->test(ListLeases::class)
            ->instance()->getTableRecords()->pluck('id')->all();

        expect($ids)->toEqual([$this->draft->id]);
    });
});

it('drops any key that is not one a list page binds', function () {
    // `state` is JSON written by one version of this feature and read by a later one. Treating
    // it as an allowlist is what stops a stale or hand-edited key becoming a query parameter.
    $view = TableView::create([
        'resource' => 'leases',
        'name' => 'Odd',
        'state' => [
            'filters' => ['status' => ['value' => 'draft']],
            'tableFilters' => ['status' => ['value' => 'active']],  // the dead v3 name
            'somethingElse' => 'x',
        ],
        'user_id' => $this->user->id,
    ]);

    expect(array_keys($view->queryParameters()))->toBe(['filters']);
});

it('shows a colleague a shared view but not a private one', function () {
    $mine = TableView::create([
        'resource' => 'leases', 'name' => 'Private', 'state' => [],
        'user_id' => $this->user->id, 'is_shared' => false,
    ]);
    $shared = TableView::create([
        'resource' => 'leases', 'name' => 'Team', 'state' => [],
        'user_id' => $this->user->id, 'is_shared' => true,
    ]);

    $colleague = makeUser('manager', [$this->asset->id]);

    $visible = TableView::query()->visibleTo($colleague->id)->pluck('id');

    expect($visible)->toContain($shared->id)
        ->not->toContain($mine->id);
});

it('a shared view grants no access — the list still scopes it', function () {
    // THE claim the feature makes. A colleague assigned to a DIFFERENT property opens a view
    // whose filters name this property's leases; the list must still show them nothing.
    Livewire::withQueryParams([]);

    $otherAsset = makeAsset(['code' => 'OTH']);
    $outsider = makeUser('manager', [$otherAsset->id]);

    $shared = TableView::create([
        'resource' => 'leases',
        'name' => 'All the drafts',
        'state' => ['filters' => ['status' => ['value' => 'draft']]],
        'user_id' => $this->user->id,
        'is_shared' => true,
    ]);

    $this->actingAs($outsider);

    asTenant($otherAsset, function () use ($shared) {
        $ids = Livewire::withQueryParams($shared->queryParameters())
            ->test(ListLeases::class)
            ->instance()->getTableRecords()->pluck('id')->all();

        // The other property's draft lease is filtered IN by the view and scoped OUT by the list.
        expect($ids)->toBe([]);
    });
});

it('refuses to delete a view belonging to somebody else, and does delete your own', function () {
    // Asserted on BEHAVIOUR, not on a status code. Livewire's test harness swallows the
    // `abort(403)` raised inside an action — `assertStatus(403)` reads 200 — which is the trap
    // CLAUDE.md records about `callAction()`. What matters is whether the row survives.
    //
    // Paired with an authorised control on purpose: a refusal test passes just as happily when
    // the action is a no-op for everyone, and "nothing was deleted" would then prove nothing.
    $someoneElse = makeUser('manager', [$this->asset->id]);

    $theirs = TableView::create([
        'resource' => 'leases', 'name' => 'Theirs', 'state' => [],
        'user_id' => $someoneElse->id, 'is_shared' => true,   // visible to everyone…
    ]);
    $mine = TableView::create([
        'resource' => 'leases', 'name' => 'Mine', 'state' => [],
        'user_id' => $this->user->id,
    ]);

    asTenant($this->asset, function () use ($theirs, $mine) {
        Livewire::test(ListLeases::class)
            ->callAction('deleteSavedView', ['view_id' => $theirs->id]);

        Livewire::test(ListLeases::class)
            ->callAction('deleteSavedView', ['view_id' => $mine->id]);
    });

    // …a shared view is readable, never removable by the reader.
    expect(TableView::whereKey($theirs->id)->exists())->toBeTrue();
    // The control: the same action, on a view this user owns, really does delete.
    expect(TableView::whereKey($mine->id)->exists())->toBeFalse();
});

/**
 * Every list the trait is wired into still renders.
 *
 * The header actions are built during render — the saved-view menu runs a query and maps rows to
 * link actions — so a mistake here does not fail a unit test, it 500s the page an operator opens.
 * Cheap: one GET each, no per-page fixture.
 */
it('renders every list page that carries saved views', function (string $page) {
    $asset = makeAsset(['code' => 'REN']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    // One saved view and one shared view present, so the menu is BUILT rather than skipped by
    // its `visible()` — an empty menu would render trivially and prove nothing.
    TableView::create(['resource' => 'leases', 'name' => 'Mine', 'state' => [], 'user_id' => auth()->id()]);
    TableView::create(['resource' => 'leases', 'name' => 'Team', 'state' => [], 'user_id' => $this->user->id, 'is_shared' => true]);

    asTenant($asset, function () use ($page, $asset) {
        $this->get($page::getUrl(panel: 'admin', tenant: $asset))->assertSuccessful();
    });
})->with([
    ListInvoices::class,
    ListLeases::class,
    ListPayments::class,
    ListTenantRequests::class,
    ListUnits::class,
    ListTenants::class,
    ListFacilityWorkOrders::class,
]);

/*
|--------------------------------------------------------------------------
| The columns are part of the view (EG-32 / S-5)
|--------------------------------------------------------------------------
|
| A saved view stored filters, sort, search and tab — everything about what the list was showing
| EXCEPT the columns, which is the one part an operator had to redo by hand. Filament persists a
| column layout in the SESSION, so it survived a page reload and nothing else: it did not travel
| with a shared view, did not come back tomorrow, and applying a named view left whatever the
| browser happened to be showing.
|
| That is what S-5 called "no user-defined columns", and the finding is only half right — this app
| marks 173 columns toggleable and Filament v4 ships the manager. Work orders offer 13 optional
| columns and tenant requests 10. What was missing was making the choice durable and shareable.
|
| The claim under test is the same one the rest of this file makes: a view is a BOOKMARK, not a
| capability. Columns travel as an id in the URL and the layout is rebuilt from the READER's own
| table, so a view saved by someone with wider rights cannot turn on a column their colleague's
| table does not have.
*/

/** The list's default column state with one column's toggle flipped. */
function columnStateWith(array $default, string $name, bool $isToggled): array
{
    return collect($default)->map(function (array $item) use ($name, $isToggled): array {
        if (isset($item['columns'])) {
            $item['columns'] = collect($item['columns'])->map(fn (array $c): array => toggledOff($c, $name, $isToggled))->all();

            return $item;
        }

        return toggledOff($item, $name, $isToggled);
    })->all();
}

function toggledOff(array $column, string $name, bool $isToggled): array
{
    if (($column['name'] ?? null) === $name) {
        $column['isToggled'] = $isToggled;
    }

    return $column;
}

it('saves the columns the list is currently showing', function () {
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);

        // Turn one of the thirteen optional columns off, the way the column manager does.
        $page->call('applyTableColumnManager', columnStateWith($page->get('tableColumns'), 'area.name', false))
            ->callAction('saveTableView', ['name' => 'Without area']);
    });

    $columns = TableView::sole()->columnState();

    expect($columns)->toHaveKey('area.name')
        ->and($columns['area.name'])->toBeFalse()
        // The control: a column left alone is recorded as ON, not omitted. A view states the whole
        // layout, so opening it is deterministic rather than "whatever was already showing".
        ->and($columns['priority'])->toBeTrue();
});

it('stores only the columns the operator can actually choose', function () {
    // A fixed column records no decision — Filament forces its toggle back on when it re-syncs —
    // so storing it would be storing noise that reads as a decision, and would pin today's fixed
    // set into a row read a year from now.
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        Livewire::test(ListFacilityWorkOrders::class)->callAction('saveTableView', ['name' => 'Everything']);
    });

    $columns = TableView::sole()->columnState();

    expect($columns)->toHaveKey('area.name')          // toggleable
        ->and($columns)->not->toHaveKey('reference'); // fixed
});

it('reopens a view on the columns it was saved with', function () {
    $view = TableView::create([
        'resource' => 'facility-work-orders',
        'name' => 'Without area',
        'state' => ['columns' => ['area.name' => false, 'priority' => true]],
        'user_id' => $this->user->id,
        'is_shared' => false,
    ]);

    Livewire::withQueryParams(['tableView' => $view->id]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);

        expect($page->instance()->isTableColumnToggledHidden('area.name'))->toBeTrue()
            // Paired control — a refusal-shaped assertion passes just as happily when the whole
            // mechanism is a no-op that hides everything.
            ->and($page->instance()->isTableColumnToggledHidden('priority'))->toBeFalse();
    });
});

it('opens a view that states no columns on the list defaults', function () {
    // Views saved before this shipped carry no column state. They open on the defaults rather than
    // inheriting whatever the browser was showing: a view is a named state a colleague must be able
    // to open and see what you saw, and "whatever your session had" is not a state anyone named.
    $view = TableView::create([
        'resource' => 'facility-work-orders',
        'name' => 'Legacy',
        'state' => ['filters' => ['status' => ['value' => 'open']]],
        'user_id' => $this->user->id,
        'is_shared' => false,
    ]);

    asTenant($this->asset, function () {
        // Dirty the session first, or this test proves nothing: "opens on the defaults" is also
        // what a completely inert feature produces. Filament persists a layout in the session, so
        // turning a column off here is the state the legacy view has to overrule.
        Livewire::withQueryParams([]);
        $dirty = Livewire::test(ListFacilityWorkOrders::class);
        $dirty->call('applyTableColumnManager', columnStateWith($dirty->get('tableColumns'), 'priority', false));

        expect($dirty->instance()->isTableColumnToggledHidden('priority'))->toBeTrue();
    });

    Livewire::withQueryParams(['tableView' => $view->id]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);
        $default = $page->instance()->getDefaultTableColumnState();

        foreach ($default as $item) {
            foreach ($item['columns'] ?? [$item] as $column) {
                expect($page->instance()->isTableColumnToggledHidden($column['name']))
                    ->toBe(! $column['isToggled']);
            }
        }
    });
});

it('cannot introduce a column the reader’s own table does not have', function () {
    // The security property. A shared view is rebuilt from the READER's default column state, so a
    // name that table does not carry is never introduced and a fixed column cannot be forced off.
    $colleague = makeUser('manager', [$this->asset->id]);

    $view = TableView::create([
        'resource' => 'facility-work-orders',
        'name' => 'Crafted',
        'state' => ['columns' => [
            'not_a_column_on_this_table' => true,
            'reference' => false,   // a FIXED column — not the operator's to switch off
            'area.name' => false,   // a genuine choice, which must still be honoured
        ]],
        'user_id' => $colleague->id,
        'is_shared' => true,
    ]);

    Livewire::withQueryParams(['tableView' => $view->id]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);

        $names = [];
        foreach ($page->get('tableColumns') as $item) {
            foreach ($item['columns'] ?? [$item] as $column) {
                $names[] = $column['name'];
            }
        }

        expect($names)->not->toContain('not_a_column_on_this_table')
            ->and($page->instance()->isTableColumnToggledHidden('reference'))->toBeFalse()
            // The control: the legitimate half of the same view still applies.
            ->and($page->instance()->isTableColumnToggledHidden('area.name'))->toBeTrue();
    });
});

it('ignores a view id that is not this user’s to open, without refusing the page', function () {
    $stranger = makeUser('manager', [makeAsset()->id]);

    $private = TableView::create([
        'resource' => 'facility-work-orders',
        'name' => 'Not shared',
        'state' => ['columns' => ['area.name' => false]],
        'user_id' => $stranger->id,
        'is_shared' => false,
    ]);

    Livewire::withQueryParams(['tableView' => $private->id]);

    asTenant($this->asset, function () {
        // The list opens on its defaults. Deliberately not a 403: the id names a display
        // preference, not a record, and a deleted bookmark should not take the page down with it.
        Livewire::test(ListFacilityWorkOrders::class)
            ->assertOk()
            ->tap(fn ($page) => expect($page->instance()->isTableColumnToggledHidden('area.name'))->toBeFalse());
    });
});

it('pins Filament’s own refusal to switch off a column that is not toggleable', function () {
    // The upstream half of the previous test's guarantee, pinned as a CONTRACT.
    //
    // `applySavedViewColumns()` carries its own `isToggleable` check, and deleting it leaves the
    // security test green — because `HasColumnManager::syncTableColumnStateItemAttributes()` forces
    // `isToggled` back to true for a fixed column. That makes upstream the layer actually doing the
    // work, and an upstream implementation detail is exactly the thing that changes in a release
    // and silently removes a protection. So this asserts Filament's behaviour directly, the same
    // way `FilamentActionDispatchContractTest` pins hidden-implies-disabled.
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);

        // Hand the column manager a state that switches a FIXED column off.
        $page->call('applyTableColumnManager', columnStateWith($page->get('tableColumns'), 'reference', false));

        expect($page->instance()->isTableColumnToggledHidden('reference'))->toBeFalse()
            // The control: the same call on a genuinely toggleable column IS honoured, so this is
            // not passing because `applyTableColumnManager` ignored the whole state.
            ->and($page->instance()->isTableColumnToggledHidden('area.name'))->toBeFalse();

        $page->call('applyTableColumnManager', columnStateWith($page->get('tableColumns'), 'area.name', false));

        expect($page->instance()->isTableColumnToggledHidden('area.name'))->toBeTrue();
    });
});

it('saves the ORDER the columns were in, and reopens on it', function () {
    // Columns became reorderable with EG-32's last slice. Without this a saved view restored the
    // toggles and silently reset the order to the resource's own — so a colleague opening a shared
    // view saw a different layout from the one that was saved, which is the whole point of a view.
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        $page = Livewire::test(ListFacilityWorkOrders::class);
        $state = $page->get('tableColumns');

        // Move the last column to the front, the way the manager's drag does.
        $moved = array_merge([array_pop($state)], $state);
        $movedName = $moved[0]['name'];

        $page->call('applyTableColumnManager', $moved, true)
            ->callAction('saveTableView', ['name' => 'My order']);

        expect(TableView::sole()->columnOrder()[0])->toBe($movedName);
    });

    $view = TableView::sole();
    Livewire::withQueryParams(['tableView' => $view->id]);

    asTenant($this->asset, function () use ($view) {
        $reopened = collect(Livewire::test(ListFacilityWorkOrders::class)->get('tableColumns'))
            ->pluck('name')->all();

        expect($reopened[0])->toBe($view->columnOrder()[0])
            // Every column still present — reordering must not drop one.
            ->and(count($reopened))->toBe(count($view->columnOrder()));
    });
});

it('keeps a column the saved order never mentioned, at the end', function () {
    // A view saved before a column was added to the resource must not make that column vanish;
    // the operator gets it last rather than not at all.
    $view = TableView::create([
        'resource' => 'facility-work-orders',
        'name' => 'Partial order',
        // Only two names, in reverse of the list's own order.
        'state' => ['column_order' => ['priority', 'reference']],
        'user_id' => $this->user->id,
        'is_shared' => false,
    ]);

    Livewire::withQueryParams(['tableView' => $view->id]);

    asTenant($this->asset, function () {
        $names = collect(Livewire::test(ListFacilityWorkOrders::class)->get('tableColumns'))
            ->pluck('name')->all();

        expect(array_slice($names, 0, 2))->toBe(['priority', 'reference'])
            // The unmentioned ones are still there, after the two the view named.
            ->and(count($names))->toBeGreaterThan(2);
    });
});
