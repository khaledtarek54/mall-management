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

    $snapshot = ReportParameters::snapshot($page);

    expect($snapshot)->toBe(['bucket' => 'd_61_90', 'asOf' => '2026-03-31']);
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
    // The load-bearing one. Sharing publishes FILTERS; it must not publish the report. `marketing`
    // cannot open the VAT return, so a colleague sharing a VAT view must not put it on their hub.
    $accountant = makeUser('accounting');

    SavedReport::create([
        'report' => 'vat_return', 'name' => 'Q1 VAT', 'parameters' => [],
        'user_id' => $accountant->id, 'is_shared' => true,
    ]);

    $this->actingAs(makeUser('marketing'));
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

    expect($page->assetId)->toBeNull();
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
