<?php

/*
|--------------------------------------------------------------------------
| A question worth asking twice is asked once
|--------------------------------------------------------------------------
| Every report takes filters — an as-at date, a fiscal year and month, a property, an account, an
| ageing bucket — and none of them were rememberable. "AR ageing as at last month-end for Atriom
| Walk" was six clicks, and the operator who ran it on the third of every month rebuilt it on the
| third of every month.
|
| The property that matters most is the one that is easy to get wrong: **a saved view is a bookmark,
| not a capability.** Sharing one publishes a set of filters, and the filters carry a PROPERTY —
| so a shared view must never become a way to hand somebody a report for a mall they cannot see.
| Two independent things enforce that, and both are tested here: the hub asks the report page's own
| `canAccess()` before listing a view, and the report re-clamps every parameter it is handed exactly
| as it does for a URL typed by hand.
*/

use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Pages\ReportHub;
use App\Filament\Admin\Pages\VatReturn;
use App\Models\SavedReport;
use App\Support\ReportParameters;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'SV']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('snapshots exactly the filters a report declares', function () {
    // Read by reflection from the page's own public scalar properties, so a report that grows a
    // filter has it saved without anyone registering it. Filament hangs plenty of other public
    // state on every page; none of it is a report parameter.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    $page = new ArAging;
    $page->mount();
    $page->bucket = 'd_61_90';
    $page->asOf = '2026-03-31';

    $snapshot = ReportParameters::snapshotForSavedView($page);

    // Plus the property the view was taken in — a reserved key, not a declared filter. Most report
    // pages carry no `$assetId` and scope by the Filament tenant instead, which reproduces nothing
    // in a queue worker; capturing it here is what lets a scheduled delivery render the mall the
    // operator was standing in rather than the whole portfolio.
    //
    // It is on `snapshotForSavedView()` and NOT on `snapshot()`, because the plain one also feeds
    // `ReportPreferences::remember()` — where the key is unreachable (`apply()` skips anything the
    // page does not declare) and actively harmful: that consumer deletes its row when the snapshot
    // comes back empty, so a key that is always present meant an operator who deselected the mall
    // kept a preference for it for ever.
    expect($snapshot)->toBe([
        'bucket' => 'd_61_90',
        'asOf' => '2026-03-31',
        ReportParameters::PROPERTY_KEY => $this->asset->id,
    ]);
});

it('re-opens a report on the filters that were saved', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    $url = ReportParameters::urlFor(ArAging::class, ['bucket' => 'd_61_90', 'asOf' => '2026-03-31']);

    expect($url)->toContain('bucket=d_61_90')
        ->and($url)->toContain('asOf=2026-03-31');
});

it('drops a filter the report no longer has', function () {
    // Applying is deliberately lossy. A report's filters change as it grows, and a saved view that
    // half-matches is worse than one whose stale keys are ignored — the operator sees a report they
    // recognise with one filter defaulted, rather than a page that throws on a property nobody
    // declares any more.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($this->asset);

    $page = new ArAging;
    $page->mount();

    ReportParameters::apply($page, ['bucket' => 'd_90_plus', 'a_filter_we_removed' => 'x']);

    expect($page->bucket)->toBe('d_90_plus');

    // …and the URL builder drops it too, rather than emitting a query string the page ignores.
    expect(ReportParameters::urlFor(ArAging::class, ['bucket' => 'd_1_30', 'gone' => 'x']))
        ->not->toContain('gone');
});

it('saves a view from the report page itself', function () {
    $user = makeUser('super_admin');
    $this->actingAs($user);
    Filament::setTenant($this->asset);

    Livewire::test(ArAging::class)
        ->set('bucket', 'd_31_60')
        ->callAction('saveReportView', data: ['name' => 'Month-end 31–60', 'is_shared' => false])
        ->assertHasNoActionErrors();

    $view = SavedReport::sole();

    expect($view->name)->toBe('Month-end 31–60')
        ->and($view->report)->toBe('ar_aging')
        ->and($view->user_id)->toBe($user->id)
        ->and($view->parameters['bucket'])->toBe('d_31_60')
        ->and($view->is_shared)->toBeFalse();
});

it('keeps a private view private and publishes a shared one', function () {
    $mine = makeUser('super_admin');
    $theirs = makeUser('super_admin');

    SavedReport::create(['report' => 'ar_aging', 'name' => 'Mine', 'parameters' => [], 'user_id' => $mine->id, 'is_shared' => false]);
    SavedReport::create(['report' => 'ar_aging', 'name' => 'Theirs private', 'parameters' => [], 'user_id' => $theirs->id, 'is_shared' => false]);
    SavedReport::create(['report' => 'ar_aging', 'name' => 'Theirs shared', 'parameters' => [], 'user_id' => $theirs->id, 'is_shared' => true]);

    $this->actingAs($mine);
    Filament::setTenant($this->asset);

    $visible = SavedReport::visibleTo($mine->id)->pluck('name')->all();

    expect($visible)->toContain('Mine', 'Theirs shared')
        ->and($visible)->not->toContain('Theirs private');
});

it('never lists a shared view for a report the reader cannot open', function () {
    // The load-bearing one. Sharing publishes FILTERS; it must not publish the report. `leasing`
    // cannot open the VAT return, so a colleague sharing a VAT view must not put it on their hub.
    //
    // `leasing` rather than `marketing`, which this used until 2026-08-26: gating `OccupancyMap`
    // (it had none, and printed every retailer's name) left marketing with ZERO visible reports, so
    // `ReportHub::canAccess()` — `visibleTo() !== []` — refuses it outright and the mount 403s.
    // That is correct: an empty hub should not be offered. But it makes marketing useless as the
    // actor HERE, because this test needs someone who reaches the hub and is still refused one
    // report on it. Leasing sees four categories and not the VAT return.
    $accountant = makeUser('accounting');

    SavedReport::create([
        'report' => 'vat_return', 'name' => 'Q1 VAT', 'parameters' => [],
        'user_id' => $accountant->id, 'is_shared' => true,
    ]);

    $this->actingAs(makeUser('leasing'));
    Filament::setTenant($this->asset);

    expect(VatReturn::canAccess())->toBeFalse();

    Livewire::test(ReportHub::class)
        ->assertOk()
        ->assertDontSee('Q1 VAT');

    // The control — the accountant, who CAN open it, does see it. Without this the assertion above
    // would pass just as happily if saved views never rendered at all.
    $this->actingAs($accountant);

    Livewire::test(ReportHub::class)
        ->assertOk()
        ->assertSee('Q1 VAT');
});

it('re-clamps a property a saved view carries', function () {
    // The second, independent guard. Even a view whose report the reader CAN open must not widen
    // their property scope: the parameters go back through the same clamping a hand-typed URL does.
    $other = makeAsset(['code' => 'OTHER']);
    $restricted = makeUser('manager');
    $restricted->assignedAssets()->sync([$this->asset->id]);

    $this->actingAs($restricted);
    Filament::setTenant($this->asset);

    $page = new GeneralLedger;
    request()->merge(['assetId' => $other->id, 'year' => 2026]);
    $page->mount();

    // The saved view's foreign property does not survive; what it collapses to is the operator's
    // own mall rather than null, so the pinned picker names the property the figures came from.
    expect($page->assetId)->toBe($this->asset->id)
        ->and($page->assetId)->not->toBe($other->id);
});

it('lets an operator delete their own view and not somebody else\'s', function () {
    $mine = makeUser('super_admin');
    $theirs = makeUser('super_admin');

    $own = SavedReport::create(['report' => 'ar_aging', 'name' => 'Mine', 'parameters' => [], 'user_id' => $mine->id]);
    $shared = SavedReport::create(['report' => 'ar_aging', 'name' => 'Theirs', 'parameters' => [], 'user_id' => $theirs->id, 'is_shared' => true]);

    $this->actingAs($mine);
    Filament::setTenant($this->asset);

    $page = new ReportHub;
    $method = new ReflectionMethod($page, 'ownsSavedView');
    $method->setAccessible(true);

    expect($method->invoke($page, ['saved_report_id' => $own->id]))->toBeTrue()
        // A shared view belongs to whoever saved it. Removing it from under a colleague because it
        // appeared in your list is not a delete anyone asked for.
        ->and($method->invoke($page, ['saved_report_id' => $shared->id]))->toBeFalse()
        ->and($method->invoke($page, ['saved_report_id' => null]))->toBeFalse();
});

it('ignores a saved view whose report has left the catalogue', function () {
    // A report removed from the catalogue leaves its views orphaned. Rendering one would be a row
    // that goes nowhere; the query drops it.
    $user = makeUser('super_admin');
    SavedReport::create(['report' => 'a_report_we_deleted', 'name' => 'Orphan', 'parameters' => [], 'user_id' => $user->id]);

    expect(SavedReport::catalogued()->pluck('name')->all())->not->toContain('Orphan');
});

it('remembers the report’s COLUMN layout too, not just its filters', function () {
    // S-5: "23 catalogued reports … no user-defined columns". Filament's column manager was already
    // there; what was missing was DURABILITY — `ReportParameters::snapshot()` reads the page's own
    // public scalar properties and deliberately excludes trait-provided ones, so `$tableColumns` was
    // invisible to it and a saved report reset its columns on every open.
    //
    // Driven on RENT ROLL because it is a report that actually offers a choice (3 toggleable
    // columns). An earlier cut of this test ran on AR ageing, which offers none, and skipped its
    // own assertions — it passed with the feature deleted, which is the whole failure mode this
    // codebase keeps recording.
    $user = makeUser('super_admin', [$this->asset->id]);
    $this->actingAs($user);

    asTenant($this->asset, function () {
        Livewire::withQueryParams([]);

        $page = Livewire::test(RentRoll::class);
        $state = $page->get('tableColumns');
        $target = collect($state)->first(fn (array $c) => $c['isToggleable'] ?? false);

        // The premise, asserted rather than assumed: without a toggleable column there is nothing
        // to remember and everything below would pass for the wrong reason.
        expect($target)->not->toBeNull();

        $page->call('applyTableColumnManager', collect($state)
            ->map(fn (array $c): array => $c['name'] === $target['name']
                ? array_merge($c, ['isToggled' => false])
                : $c)
            ->all())
            ->callAction('saveReportView', ['name' => 'Fewer columns']);

        $saved = SavedReport::sole();

        expect($saved->parameters)->toHaveKey('columns')
            ->and($saved->parameters['columns'][$target['name']])->toBeFalse()
            // The order rides along as its own key — a different question from which columns show.
            ->and($saved->parameters)->toHaveKey('column_order');

        // …and reopening on the hub's link restores it.
        Livewire::withQueryParams(['savedReport' => $saved->id]);

        $reopened = Livewire::test(RentRoll::class)->instance();

        expect($reopened->isTableColumnToggledHidden($target['name']))->toBeTrue()
            // Paired control: a column the view left ON is still on, so this is the saved layout
            // being applied rather than everything being hidden.
            ->and($reopened->isTableColumnToggledHidden($state[0]['name']))->toBeFalse();
    });
});

it('opens a report on its own columns when the saved view names none', function () {
    // A view saved before this shipped states no layout, and must not silently inherit whatever the
    // session held — the same rule a resource list's saved view follows.
    $user = makeUser('super_admin', [$this->asset->id]);
    $this->actingAs($user);

    $saved = SavedReport::create([
        'report' => 'rent_roll',
        'name' => 'Legacy',
        'parameters' => ['assetId' => $this->asset->id],
        'user_id' => $user->id,
        'is_shared' => false,
    ]);

    asTenant($this->asset, function () use ($saved) {
        // Dirty the session first, or "opens on the defaults" is also what a completely inert
        // feature produces. Filament persists a layout in the session, so turning a column off here
        // is the state the legacy view has to overrule.
        Livewire::withQueryParams([]);
        $dirty = Livewire::test(RentRoll::class);
        $state = $dirty->get('tableColumns');
        $target = collect($state)->first(fn (array $c) => $c['isToggleable'] ?? false);
        $dirty->call('applyTableColumnManager', collect($state)
            ->map(fn (array $c): array => $c['name'] === $target['name']
                ? array_merge($c, ['isToggled' => ! ($c['isToggled'] ?? true)])
                : $c)
            ->all());

        Livewire::withQueryParams(['savedReport' => $saved->id]);
        $page = Livewire::test(RentRoll::class);

        foreach ($page->instance()->getDefaultTableColumnState() as $item) {
            foreach ($item['columns'] ?? [$item] as $column) {
                expect($page->instance()->isTableColumnToggledHidden($column['name']))
                    ->toBe(! $column['isToggled']);
            }
        }
    });
});

it('carries the saved report id on the hub link, so the columns travel with it', function () {
    $user = makeUser('super_admin', [$this->asset->id]);
    $this->actingAs($user);

    $saved = SavedReport::create([
        'report' => 'rent_roll',
        'name' => 'Mine',
        'parameters' => ['assetId' => $this->asset->id],
        'user_id' => $user->id,
        'is_shared' => false,
    ]);

    // Inside a tenant, because `getUrl()` on a tenant-scoped panel needs one and `urlFor()`
    // deliberately rescues to '#' rather than throwing when it cannot build a link.
    asTenant($this->asset, function () use ($saved) {
        expect(ReportParameters::urlFor(RentRoll::class, $saved->parameters, $saved->id))
            ->toContain('savedReport='.$saved->id)
            // The control: without an id the link is unchanged, so nothing gained a stray param.
            ->and(ReportParameters::urlFor(RentRoll::class, $saved->parameters))
            ->not->toContain('savedReport');
    });
});
