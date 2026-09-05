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

/**
 * ── The default view — the second half of UX-11 ─────────────────────────────────────────────────
 *
 * *"Let a user save a table's filter+column state **and set one as their default**."* Only the
 * first half shipped: an operator could build the arrears pack, name it, share it — and still land
 * on the unfiltered list every morning and pick it out of a menu.
 *
 * The property under test is the RESOLUTION order and the escape, not that a boolean was written.
 */
it('opens the list on the default view, as a redirect to its own URL', function () {
    Livewire::withQueryParams([]);

    $view = TableView::create([
        'resource' => 'leases',
        'name' => 'Draft leases',
        'state' => ['filters' => ['status' => ['value' => 'draft']]],
        'user_id' => $this->user->id,
    ]);
    $view->makeDefault($view->user_id);

    asTenant($this->asset, function () use ($view) {
        // A redirect, never a state mutation — this trait's stated rule is that a view IS a URL,
        // and honouring it is what keeps the address bar honest and the link pasteable.
        Livewire::test(ListLeases::class)
            ->assertRedirect()
            ->assertRedirectContains('tableView='.$view->getKey())
            ->assertRedirectContains('status');
    });
});

it('does not redirect when the request already asked for something', function () {
    // The loop guard AND the escape hatch, in one property. The URL the redirect produces always
    // carries `tableView`, so a page that asked for anything must be left alone — otherwise
    // opening the default would bounce for ever.
    TableView::create([
        'resource' => 'leases', 'name' => 'Draft leases',
        'state' => ['filters' => ['status' => ['value' => 'draft']]],
        'user_id' => $this->user->id,
    ])->makeDefault($this->user->id);

    // **`assertNoRedirect()` cannot fail here and must not be used.** It asserts only on
    // `$effects['redirect']`, which Livewire populates on an UPDATE request; a redirect issued from
    // `mount()` is a plain HTTP one on the initial response, so the effect is never set and the
    // assertion passes whether or not the page redirected. Measured: with the guard below deleted
    // this test stayed green. `assertRedirect()` is not symmetric with it — on a non-Livewire
    // request it checks the RESPONSE — which is why the test above is sound and this one was not.
    // `assertOk()` falls through `Testable::__call()` to that same response.
    asTenant($this->asset, function () {
        // The menu's "All records" link — the ONE way back to the unfiltered list. A plain link
        // would carry an empty query string, which is indistinguishable from a bare page load.
        Livewire::withQueryParams(['tableView' => 'none']);
        Livewire::test(ListLeases::class)->assertOk();

        // And any ordinary filtered arrival.
        Livewire::withQueryParams(['filters' => ['status' => ['value' => 'active']]]);
        Livewire::test(ListLeases::class)->assertOk();
    });

    // THE CONTROL — with nothing asked for, the very same default does redirect. Without this the
    // two refusals above would pass on an install where the feature does not work at all.
    Livewire::withQueryParams([]);

    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)->assertRedirect();
    });
});

it('lets a personal default beat the team’s', function () {
    // A shared default is a manager saying "this is where the team starts". A personal one is
    // somebody stating a preference about their own screen. If the two disagree the person wins,
    // or marking a team view silently overrules every colleague who had already chosen.
    $manager = makeUser('manager', [$this->asset->id]);

    $team = TableView::create([
        'resource' => 'leases', 'name' => 'Team pack', 'state' => [],
        'user_id' => $manager->id, 'is_shared' => true,
    ]);
    $team->makeDefault($team->user_id);
    $mine = TableView::create([
        'resource' => 'leases', 'name' => 'Mine', 'state' => [],
        'user_id' => $this->user->id,
    ]);
    $mine->makeDefault($mine->user_id);

    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($mine->getKey())
        // …and a colleague with no preference of their own still lands on the team's.
        ->and(TableView::defaultFor('leases', $manager->id)?->getKey())->toBe($team->getKey())
        // A list nobody chose a default for opens on itself.
        ->and(TableView::defaultFor('invoices', $this->user->id))->toBeNull();
});

it('keeps at most one default per user per list, and clears on a blank', function () {
    $first = TableView::create([
        'resource' => 'leases', 'name' => 'First', 'state' => [],
        'user_id' => $this->user->id,
    ]);
    $first->makeDefault($first->user_id);
    $second = TableView::create([
        'resource' => 'leases', 'name' => 'Second', 'state' => [], 'user_id' => $this->user->id,
    ]);
    // A view on ANOTHER list must not be disturbed — the flag is per resource, not per user.
    $elsewhere = TableView::create([
        'resource' => 'invoices', 'name' => 'Elsewhere', 'state' => [],
        'user_id' => $this->user->id,
    ]);
    $elsewhere->makeDefault($elsewhere->user_id);

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($second) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $second->id]);
    });

    // Asserted through the RESOLVER, not the storage. These used to read `is_default` off the
    // row, which is why they all went red when where-a-list-opens moved to a per-person row —
    // they were pinned to the mechanism rather than to the claim.
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($second->getKey())
        ->and(TableView::defaultFor('invoices', $this->user->id)?->getKey())->toBe($elsewhere->getKey());

    // The explicit "no default" — the way back for an operator who changed their mind. A BLANK
    // pick is a different answer ("follow the team"), which is why the Select offers both.
    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => 'none']);
    });

    expect(TableView::defaultFor('leases', $this->user->id))->toBeNull()
        // …and the OTHER list is untouched: the answer is per (person, list).
        ->and(TableView::defaultFor('invoices', $this->user->id)?->getKey())->toBe($elsewhere->getKey());
});

it('will not make a view somebody never shared into your default', function () {
    // The option list is a UI convenience; `visibleTo` at the point of the write is the gate.
    // Asserted on behaviour, not a status code — Livewire's harness swallows abort(403).
    $someoneElse = makeUser('manager', [$this->asset->id]);

    $private = TableView::create([
        'resource' => 'leases', 'name' => 'Theirs', 'state' => [],
        'user_id' => $someoneElse->id, 'is_shared' => false,
    ]);
    $shared = TableView::create([
        'resource' => 'leases', 'name' => 'Team', 'state' => [],
        'user_id' => $someoneElse->id, 'is_shared' => true,
    ]);

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($private, $shared) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $private->id]);

        // THE CONTROL — a view they DID share can be adopted, which is the case UX-11 is about.
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $shared->id]);
    });

    // The private view was refused; the SHARED one really was adopted — which is the case UX-11
    // is about, and it now records in the adopter's own row rather than on the author's.
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($shared->getKey());
});

/*
|--------------------------------------------------------------------------
| D3-04 — the clearing is scoped to the ACTOR, not to the row's owner
|--------------------------------------------------------------------------
| `makeDefault()` cleared `where('user_id', $this->user_id)` — the id on the ROW, which is the
| actor only when somebody marks their OWN view. Adopting a colleague's SHARED view (the case
| UX-11 exists for, pinned by the test above) therefore cleared the OWNER's flags while leaving
| the actor's own personal default standing — and a personal default WINS, so the button
| appeared to do nothing while quietly breaking somebody else's landing screen.
*/
it('adopting a shared view clears MY default, never the author\'s', function () {
    $author = makeUser('manager', [$this->asset->id]);

    $authorsOwn = TableView::create([
        'resource' => 'leases', 'name' => 'Author personal', 'state' => [],
        'user_id' => $author->id,
    ]);
    $authorsOwn->makeDefault($authorsOwn->user_id);
    $authorsShared = TableView::create([
        'resource' => 'leases', 'name' => 'Author shared', 'state' => [],
        'user_id' => $author->id, 'is_shared' => true,
    ]);
    $mineWasDefault = TableView::create([
        'resource' => 'leases', 'name' => 'Mine', 'state' => [],
        'user_id' => $this->user->id,
    ]);
    $mineWasDefault->makeDefault($mineWasDefault->user_id);

    // `?tableView=none`, or the page's own mount-time redirect to the existing default fires and
    // Livewire::test() hands back a redirect instead of a component.
    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($authorsShared) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $authorsShared->id]);
    });

    // The author's own landing screen is untouched — the half that was silently destructive.
    expect(TableView::defaultFor('leases', $author->id)?->getKey())->toBe($authorsOwn->getKey());

    // …and mine gave way, so the view I just adopted is the one I actually land on. Without this
    // the personal tier still wins and the button reads as broken.
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($authorsShared->getKey())
        // …and adopting wrote nothing onto the author's row: the team's starting point is a
        // separate act with a separate gate.
        ->and($authorsShared->fresh()->is_default)->toBeFalse();
});

it('touches no other person\'s row — including a shared view that is its owner\'s own default', function () {
    // The trap a first version of this fix fell into. `defaultFor()`'s PERSONAL tier does not
    // exclude shared rows, so a view somebody owns AND has shared is simultaneously their personal
    // default and the team's. Clearing "other shared defaults" — which reads as tidy — therefore
    // wipes a preference its owner stated, which is the exact bug D3-04 is about, through a
    // different door. Two team defaults resolving by row id is the better trade.
    $author = makeUser('manager', [$this->asset->id]);

    $authorsSharedDefault = TableView::create([
        'resource' => 'leases', 'name' => 'Author shared AND default', 'state' => [],
        'user_id' => $author->id, 'is_shared' => true,
    ]);
    $authorsSharedDefault->makeDefault($authorsSharedDefault->user_id);

    $colleague = makeUser('manager', [$this->asset->id]);
    $colleaguesShared = TableView::create([
        'resource' => 'leases', 'name' => 'Colleague shared', 'state' => [],
        'user_id' => $colleague->id, 'is_shared' => true,
    ]);

    Livewire::withQueryParams(['tableView' => 'none']);

    // An unrelated third person adopts the colleague's view.
    asTenant($this->asset, function () use ($colleaguesShared) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $colleaguesShared->id]);
    });

    // The author never touched anything and must still land where they set it.
    expect(TableView::defaultFor('leases', $author->id)?->getKey())->toBe($authorsSharedDefault->getKey());
});

/*
|--------------------------------------------------------------------------
| D3-04 — where a list opens is a fact about a PERSON
|--------------------------------------------------------------------------
| `is_default` answered two questions at once, and a view its owner had shared AND marked was both
| at the same time. A row per (person, list) separates them: a `table_view_id` is where I start,
| a NULL one is the explicit "no default for me", and the flag keeps only the team's meaning.
*/
it('lets somebody with no view of their own escape a team default', function () {
    // THE CASE THE PIVOT EXISTS FOR. There was nowhere to record "not for me" — the clear button
    // only unset the actor's OWN views, so a reader following somebody else's shared default was
    // returned to it on every page load, for ever, and the button reported success each time.
    $manager = makeUser('manager', [$this->asset->id]);

    $team = TableView::create([
        'resource' => 'leases', 'name' => 'Team arrears', 'state' => [],
        'user_id' => $manager->id, 'is_shared' => true,
    ]);
    $team->makeTeamDefault();

    // I own no view at all and I follow the team's.
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($team->getKey());

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => 'none']);
    });

    expect(TableView::defaultFor('leases', $this->user->id))->toBeNull()
        // …and the team's own starting point is untouched for everybody else.
        ->and(TableView::defaultFor('leases', $manager->id)?->getKey())->toBe($team->getKey());

    // …and BLANK is the third answer, not a synonym for either: it puts them back to following
    // whatever the team does. Two states rendered identically before this, so a follower could
    // not tell which they were in and submitting picked one for them.
    asTenant($this->asset, function () {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => null]);
    });

    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($team->getKey());
});

it('treats a deleted or unshared view as no answer, not as a broken one', function () {
    // A stored pointer can outlive what it points at. Falling through to the team default is the
    // safe reading; opening a list on a view the reader may no longer see is not.
    $manager = makeUser('manager', [$this->asset->id]);

    $team = TableView::create([
        'resource' => 'leases', 'name' => 'Team', 'state' => [],
        'user_id' => $manager->id, 'is_shared' => true,
    ]);
    $team->makeTeamDefault();

    $adopted = TableView::create([
        'resource' => 'leases', 'name' => 'Their other view', 'state' => [],
        'user_id' => $manager->id, 'is_shared' => true,
    ]);
    $adopted->makeDefault($this->user->id);

    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($adopted->getKey());

    // Unshared out from under me — I can no longer see it, so I follow the team again.
    $adopted->forceFill(['is_shared' => false])->save();
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($team->getKey());

    // Deleted outright — the FK CASCADES, taking the follower's row with it, which reads as "has
    // not chosen" and NOT as the stored NULL that means "no default for me". Nulling here is
    // exactly what must not happen: it would opt every follower of a deleted shared view out of
    // the team default too.
    $adopted->delete();
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($team->getKey());
});

it('offers the team toggle only to the owner of a shared view', function () {
    // Where the TEAM starts is a decision about everyone's screen. Asked of the predicate the
    // toggle's visibility, its default and the write all read, so the three cannot drift.
    $mineShared = TableView::create([
        'resource' => 'leases', 'name' => 'Mine, shared', 'state' => [],
        'user_id' => $this->user->id, 'is_shared' => true,
    ]);
    $minePrivate = TableView::create([
        'resource' => 'leases', 'name' => 'Mine, private', 'state' => [],
        'user_id' => $this->user->id,
    ]);
    $theirsShared = TableView::create([
        'resource' => 'leases', 'name' => 'Theirs, shared', 'state' => [],
        'user_id' => makeUser('manager', [$this->asset->id])->id, 'is_shared' => true,
    ]);

    Livewire::withQueryParams(['tableView' => 'none']);

    // The predicate all three sites read — the toggle's `visible()`, its `default()` and the
    // write. Filament's `getMountedActionSchema()` is protected, so a modal field's visibility
    // cannot be read from outside without reflection; the end-to-end proof that the WRITE honours
    // this lives in the smuggling case below, which drives the real action.
    asTenant($this->asset, function () use ($mineShared, $minePrivate, $theirsShared) {
        $page = Livewire::test(ListLeases::class)->instance();

        expect($page->ownedSharedView($mineShared->getKey()))->not->toBeNull()
            // A view I own but never shared: a "team default" nobody else could see.
            ->and($page->ownedSharedView($minePrivate->getKey()))->toBeNull()
            // Somebody else's shared view: adopting it is mine to do, publishing it is not.
            ->and($page->ownedSharedView($theirsShared->getKey()))->toBeNull();
    });
});

it('does not let a reader set the team default by smuggling the toggle', function () {
    // A hidden Toggle still arrives in the Livewire payload, so the write re-asks rather than
    // trusting it — the rule this codebase states for every pinned or hidden control.
    $author = makeUser('manager', [$this->asset->id]);
    $theirs = TableView::create([
        'resource' => 'leases', 'name' => 'Theirs', 'state' => [],
        'user_id' => $author->id, 'is_shared' => true,
    ]);

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($theirs) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $theirs->id, 'is_team_default' => true]);
    });

    // Adopted for me…
    expect(TableView::defaultFor('leases', $this->user->id)?->getKey())->toBe($theirs->getKey())
        // …and NOT published as the team's.
        ->and($theirs->fresh()->is_default)->toBeFalse();
});

it('cannot UNPUBLISH somebody else\'s team default by adopting it', function () {
    // **The half the smuggling test above cannot reach, and the one that proves the guard.**
    // Filament does not dehydrate a hidden component, so an extra payload key never arrives and
    // the refusal comes from upstream — measured: deleting the ownership check left all 34 cases
    // green. What the check actually protects is the OTHER branch: a submit with the toggle off
    // clears `is_default`, and without the guard a reader adopting a shared view would strip the
    // team's starting point on their way past.
    $author = makeUser('manager', [$this->asset->id]);
    $theirs = TableView::create([
        'resource' => 'leases', 'name' => 'Team pack', 'state' => [],
        'user_id' => $author->id, 'is_shared' => true,
    ]);
    $theirs->makeTeamDefault();

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($theirs) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $theirs->id, 'is_team_default' => false]);
    });

    expect($theirs->fresh()->is_default)->toBeTrue()
        // …and a colleague who has stated nothing still follows it.
        ->and(TableView::defaultFor('leases', makeUser('manager', [$this->asset->id])->id)?->getKey())
        ->toBe($theirs->getKey());
});

it('lets the OWNER unpublish their own team default — the control', function () {
    // Without this, the refusal above passes just as happily on a write that can never clear the
    // flag at all, and "the team default survived" would prove nothing.
    $mine = TableView::create([
        'resource' => 'leases', 'name' => 'Mine, shared', 'state' => [],
        'user_id' => $this->user->id, 'is_shared' => true,
    ]);
    $mine->makeTeamDefault();

    Livewire::withQueryParams(['tableView' => 'none']);

    asTenant($this->asset, function () use ($mine) {
        Livewire::test(ListLeases::class)
            ->callAction('chooseDefaultView', ['view_id' => $mine->id, 'is_team_default' => false]);
    });

    expect($mine->fresh()->is_default)->toBeFalse();
});
