<?php

use App\Filament\Admin\Pages\ReportHub;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\TaxDepreciation;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\CreateFixedAssetCategory;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\ListFixedAssetCategories;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\SavedReport;
use App\Services\Reports\DeliverSavedReportService;
use App\Settings\ModulesSettings;
use App\Support\Filament\NavigationItemMemo;
use App\Support\Modules;
use App\Support\ReportCatalogue;
use App\Support\ReportParameters;
use App\Support\TaxDepreciation as Pools;
use Database\Seeders\FixedAssetCategorySeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting 2026-09-02, point 12 — decided 2026-09-13: the income-tax depreciation schedule is
 * SWITCHED OFF on the client's install until further work.
 *
 * It is a module switch (`tax_depreciation`), not a code freeze, because the schedule is finished
 * and correct (Law 91/2005 art. 25 — `TaxDepreciationScheduleTest` pins the arithmetic) and what
 * the client is deciding is whether to LOOK at it. The switch ships ON, the market's shape — every
 * fixed-asset system in the benchmark set keeps a tax book beside the accounting one — and the
 * client's OFF is a configuration act on their box, never the code default.
 *
 * Off means: the page refuses, the sidebar and the report hub omit it, a scheduled delivery of a
 * saved view of it is refused, and the tax pool disappears from the asset form and the class
 * form/table — every door onto the feature, because a pool nothing on screen reads is a question
 * the operator cannot answer. What it must NOT touch: the book depreciation run (`fixed_assets`)
 * and the data — an asset created with the schedule off still gets a valid pool from the model's
 * floor, so the register is classified the day the switch goes back on. The schedule never posted
 * a journal entry, so there is no posting to stop; the test says so rather than implying it.
 *
 * Every refusal is paired with the ON control, because a switch that hides everything satisfies
 * the refusals alone.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(FixedAssetCategorySeeder::class);

    $this->mall = makeAsset(['code' => 'TXD']);
    $this->operator = makeUser('super_admin', [$this->mall->id]);
    $this->actingAs($this->operator);
});

/** Flip the switch the way the Settings screen does, and forget the memos the way a new request would. */
function taxScheduleSwitch(bool $on): void
{
    app(ModulesSettings::class)->fill(['tax_depreciation' => $on])->save();
    app()->forgetInstance(ModulesSettings::class);
    // The sidebar memoises each screen's visibility for the REQUEST (`NavigationItemMemo`, scoped);
    // one test is one request, so the flip must drop it or the second render answers the first.
    app(NavigationItemMemo::class)->flush();
}

/** The page classes the report hub lists for the operator, flattened across its categories. */
function taxScheduleHubPages(): array
{
    return collect(ReportCatalogue::visibleTo())->flatten(1)->pluck('page')->all();
}

/** The labels the sidebar renders for the operator, flattened. */
function taxScheduleSidebar(): array
{
    Filament::setTenant(test()->mall, isQuiet: true);

    $labels = [];
    foreach (Filament::getPanel('admin')->getNavigation() as $group) {
        foreach ((array) $group->getItems() as $item) {
            $labels[] = $item->getLabel();
        }
    }

    return $labels;
}

it('is a switch the operator can set, read through the one module registry', function () {
    // In KEYS and GROUPS — a key outside them is a guard that can never refuse.
    expect(Modules::KEYS)->toContain('tax_depreciation')
        ->and(Modules::sectionOf('tax_depreciation'))->toBe('inventory_assets')
        ->and(Modules::toggleable())->toContain('tax_depreciation')
        ->and(Modules::frozen('tax_depreciation'))->toBeFalse();

    // Ships ON — the market's answer; the client's OFF is what they SET.
    expect(Modules::enabled('tax_depreciation'))->toBeTrue();

    taxScheduleSwitch(false);
    expect(Modules::enabled('tax_depreciation'))->toBeFalse();

    // Its own switch, not a follower: the register stays on while the schedule is off.
    expect(Modules::enabled('fixed_assets'))->toBeTrue();

    taxScheduleSwitch(true);
    expect(Modules::enabled('tax_depreciation'))->toBeTrue();
});

it('offers the switch on the Settings screen under Inventory & assets, worded in both languages', function () {
    // The words must EXIST, asked with `fallback: false` — `__()` returns the KEY for a missing one,
    // on the page and in this test alike, so `assertSee(__(...))` alone passes on a raw key.
    foreach (['admin.permission_modules.tax_depreciation', 'admin.settings.modules.tax_depreciation'] as $key) {
        expect(Lang::has($key, 'en', fallback: false))->toBeTrue("{$key} has no English")
            ->and(Lang::has($key, 'ar', fallback: false))->toBeTrue("{$key} has no Arabic")
            ->and(preg_match('/\\p{Arabic}/u', __($key, [], 'ar')))->toBe(1, "{$key} carries no Arabic script");
    }

    asTenant($this->mall, function () {
        Livewire::test(Settings::class)
            ->assertSee(__('admin.permission_modules.tax_depreciation'))
            ->assertSee(__('admin.settings.modules.tax_depreciation'));
    });
});

it('refuses the page, drops it from the sidebar and the report hub when off — and offers all three when on', function () {
    // CONTROLS FIRST, on the real route: the page answers, sits in the sidebar, and is a report.
    expect(TaxDepreciation::canAccess())->toBeTrue();
    $this->get(TaxDepreciation::getUrl(tenant: $this->mall))->assertOk();
    expect(taxScheduleSidebar())->toContain(TaxDepreciation::getNavigationLabel());
    expect(taxScheduleHubPages())->toContain(TaxDepreciation::class);
    expect(ReportCatalogue::deliverableOptions())->toHaveKey('tax_depreciation');

    taxScheduleSwitch(false);

    expect(TaxDepreciation::canAccess())->toBeFalse();
    // 403, not 404 and not 500: the route exists, the module refuses it.
    $this->get(TaxDepreciation::getUrl(tenant: $this->mall))->assertForbidden();
    expect(taxScheduleSidebar())->not->toContain(TaxDepreciation::getNavigationLabel());
    expect(taxScheduleHubPages())->not->toContain(TaxDepreciation::class);
    expect(ReportCatalogue::deliverableOptions())->not->toHaveKey('tax_depreciation');
});

it('refuses a scheduled delivery of a saved tax schedule while off, and sends it while on', function () {
    Mail::fake();

    $saved = SavedReport::create([
        'report' => 'tax_depreciation',
        'name' => 'Annual tax schedule',
        'parameters' => ['year' => now()->year, ReportParameters::PROPERTY_KEY => $this->mall->id],
        'user_id' => $this->operator->id,
        'recipients' => ['auditor@outside.test'],
        'frequency' => 'monthly',
        'day_of_month' => 1,
    ]);

    // The control: with the switch on the view delivers — the same service the schedule runs.
    expect(app(DeliverSavedReportService::class)->deliver($saved))->toBeTrue();

    taxScheduleSwitch(false);

    // A schedule saved before the switch was thrown must not keep e-mailing the auditor a schedule
    // the operator decided not to keep — access is re-asked at delivery, through the page's own
    // `canAccess()`, which is where the switch lives.
    expect(app(DeliverSavedReportService::class)->deliver($saved->fresh()))->toBeFalse();
});

it('hides the tax pool on the asset form while off; a class proposal still lands and a blank stays unstated', function () {
    asTenant($this->mall, function () {
        // The control: with the switch on the field is offered, the class proposes into it, and
        // the class helper names the pool it fills in.
        Livewire::test(CreateFixedAsset::class)
            ->assertFormFieldVisible('tax_pool')
            ->assertSee(__('admin.fixed_assets.helpers.category'))
            ->fillForm(['category' => 'IT'])
            ->assertFormSet(['tax_pool' => Pools::COMPUTERS]);

        // A class registered while the switch is on, proposing a pool; and one registered while it
        // is off, which cannot — the field is hidden on its form too.
        taxScheduleSwitch(false);

        $page = Livewire::test(CreateFixedAsset::class)
            ->assertFormFieldHidden('tax_pool')
            // The helper stops naming a field that is no longer below it.
            ->assertDontSee(__('admin.fixed_assets.helpers.category'))
            ->assertSee(__('admin.fixed_assets.helpers.category_no_tax_pool'));

        $page->fillForm([
            'asset_id' => $this->mall->id, 'category' => 'IT', 'name' => 'Front-desk terminals', 'tag' => null,
            'acquisition_date' => '2026-09-01', 'acquisition_cost' => 40000, 'useful_life_months' => 48, 'funded_from' => 'cash',
        ])->call('create')->assertHasNoFormErrors();

        // A hidden field is not dehydrated, so the CLASS's proposal reaches the row through the
        // model — stated data, intact the day the switch returns.
        expect(FixedAsset::where('name', 'Front-desk terminals')->sole()->tax_pool)->toBe(Pools::COMPUTERS);

        // …while an asset whose class proposes nothing stays UNSTATED. The floor to the statutory
        // default runs only with the switch on: with it off nobody could confirm a pool, so a
        // `general` the system invented would be a figure on a tax return nobody ever looked at.
        // The schedule reads null as the law's default anyway (`TaxDepreciationScheduleTest`), and
        // the form shows the blank back when the switch returns — the review step on re-enable.
        Livewire::test(CreateFixedAssetCategory::class)
            ->fillForm(['code' => 'signage', 'name_en' => 'Signage', 'name_ar' => 'لافتات', 'tag_prefix' => 'SGN', 'default_useful_life_months' => 60])
            ->call('create')
            ->assertHasNoFormErrors();
        expect(FixedAssetCategory::where('code', 'signage')->sole()->default_tax_pool)->toBeNull();

        Livewire::test(CreateFixedAsset::class)->fillForm([
            'asset_id' => $this->mall->id, 'category' => 'signage', 'name' => 'Roof sign', 'tag' => null,
            'acquisition_date' => '2026-09-01', 'acquisition_cost' => 25000, 'useful_life_months' => 60, 'funded_from' => 'cash',
        ])->call('create')->assertHasNoFormErrors();
        expect(FixedAsset::where('name', 'Roof sign')->sole()->tax_pool)->toBeNull();

        // The control for the floor itself: with the switch ON the same blank is classified.
        taxScheduleSwitch(true);
        Livewire::test(CreateFixedAsset::class)->fillForm([
            'asset_id' => $this->mall->id, 'category' => 'signage', 'name' => 'Lobby sign', 'tag' => null,
            'acquisition_date' => '2026-09-01', 'acquisition_cost' => 5000, 'useful_life_months' => 60, 'funded_from' => 'cash',
        ])->call('create')->assertHasNoFormErrors();
        expect(FixedAsset::where('name', 'Lobby sign')->sole()->tax_pool)->toBe(Pools::default());
    });
});

it('keeps an own saved view of the switched-off report on the hub, unlinked, so its schedule can be retired', function () {
    $mine = SavedReport::create([
        'report' => 'tax_depreciation', 'name' => 'My tax schedule',
        'parameters' => ['year' => now()->year, ReportParameters::PROPERTY_KEY => $this->mall->id],
        'user_id' => $this->operator->id, 'recipients' => ['auditor@outside.test'], 'frequency' => 'monthly', 'day_of_month' => 1,
    ]);
    $colleague = makeUser('super_admin', [$this->mall->id]);
    $theirs = SavedReport::create([
        'report' => 'tax_depreciation', 'name' => 'Shared tax schedule',
        'parameters' => ['year' => now()->year, ReportParameters::PROPERTY_KEY => $this->mall->id],
        'user_id' => $colleague->id, 'is_shared' => true,
    ]);

    asTenant($this->mall, function () use ($mine, $theirs) {
        // The control: with the switch on both views list, linked.
        Livewire::test(ReportHub::class)
            ->assertSee($mine->name)
            ->assertSee($theirs->name)
            ->assertDontSee(__('admin.report_hub.unavailable_view'));

        taxScheduleSwitch(false);

        // Off: the colleague's view is not the reader's to touch and is dropped; the reader's own is
        // listed without a link and says why — and the delete on it still works.
        $hub = Livewire::test(ReportHub::class)
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name)
            ->assertSee(__('admin.report_hub.unavailable_view'));

        // The hub's rows are arrays keyed by POSITION (`ArrayRecord::getKeyName()` fills `__key`
        // from the index), so the row is found by what it carries, not by a key it does not have.
        $row = collect($hub->instance()->getTableRecords())
            ->first(fn (array $record): bool => ($record['saved_report_id'] ?? null) === $mine->id);
        expect($row)->not->toBeNull()->and($row['url'])->toBeNull();

        $hub->callTableAction('deleteSavedView', $row['__key']);
        expect(SavedReport::whereKey($mine->id)->exists())->toBeFalse();
    });
});

it('hides the class default and its column while off, and shows both while on', function () {
    asTenant($this->mall, function () {
        Livewire::test(CreateFixedAssetCategory::class)->assertFormFieldVisible('default_tax_pool');
        Livewire::test(ListFixedAssetCategories::class)->assertTableColumnVisible('default_tax_pool');

        taxScheduleSwitch(false);

        Livewire::test(CreateFixedAssetCategory::class)->assertFormFieldHidden('default_tax_pool');
        Livewire::test(ListFixedAssetCategories::class)->assertTableColumnHidden('default_tax_pool');
    });
});

it('has never posted a journal entry, so switching it off stops no posting', function () {
    // Stated as a test rather than a sentence: the schedule is a computation attached to the
    // return. The three files that make it name no journalizer, no ledger and no write.
    $sources = [
        app_path('Support/TaxDepreciation.php'),
        app_path('Services/Accounting/TaxDepreciationService.php'),
        app_path('Filament/Admin/Pages/TaxDepreciation.php'),
    ];

    foreach ($sources as $path) {
        $source = \App\Support\PhpSource::withoutComments(file_get_contents($path));

        expect($source)->not->toContain('LedgerPoster')
            ->not->toContain('JournalEntry')
            ->not->toContain('->save(')
            ->not->toContain('::create(');
    }

    // And the book run is not this switch: `accounting:post-depreciation` belongs to the register.
    expect(\App\Support\ScheduledModules::OWNED_BY['accounting:post-depreciation'])->toBe('fixed_assets');
});
