<?php

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
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
