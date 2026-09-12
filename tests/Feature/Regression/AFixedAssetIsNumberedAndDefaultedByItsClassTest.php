<?php

use App\Filament\Admin\Resources\FixedAssetCategories\Pages\CreateFixedAssetCategory;
use App\Filament\Admin\Resources\FixedAssetCategories\Pages\ListFixedAssetCategories;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Imports\FixedAssetImporter;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\User;
use App\Services\Accounting\TaxDepreciationService;
use App\Services\DepreciationService;
use App\Settings\AccountingSettings;
use App\Support\DepreciationProration;
use App\Support\TaxDepreciation;
use Carbon\CarbonImmutable;
use Database\Seeders\FixedAssetCategorySeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting 2026-09-02, points 11 · 13 · 14 — the accountant's fixed-asset asks.
 *
 * "Category first; the asset number comes from the category; salvage defaults to 1; the useful life
 * per category, as a rate rather than months; depreciation by days." Every register in the market
 * works from the CLASS — SAP's asset class drives the number range, the depreciation key and the
 * memo value; Yardi Fixed Assets and Odoo carry the same defaults — so the class is a catalogue row
 * (`FixedAssetCategory`, the seventh `IsCodeCatalogue`) and everything below reads from it.
 *
 * Posting stays MONTHLY: a daily journal entry is what no benchmark system does. What "daily"
 * meant is the FIRST-MONTH proration, and that is a setting with SAP's two answers.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(FixedAssetCategorySeeder::class);
    $this->asset = makeAsset(['code' => 'CLS']);
    CarbonImmutable::setTestNow('2026-09-12');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function classedAsset(int $assetId, array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => $assetId,
        'name' => 'Office chairs',
        'category' => 'furniture',
        'acquisition_date' => '2026-09-01',
        'acquisition_cost' => 60000,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ], $attrs));
}

// ── #11: the number comes from the class ──────────────────────────────────────────────────────

it('numbers an untagged asset from its class, per property, and keeps a tag that was supplied', function () {
    $a = classedAsset($this->asset->id);
    $b = classedAsset($this->asset->id, ['name' => 'Desks']);
    $c = classedAsset($this->asset->id, ['name' => 'Rack', 'category' => 'IT', 'useful_life_months' => 48]);

    expect($a->tag)->toBe('FUR-0001')
        ->and($b->tag)->toBe('FUR-0002')
        ->and($c->tag)->toBe('IT-0001');

    // Another mall numbers its own furniture from 1 — the identity the register keys on.
    $other = makeAsset(['code' => 'OTH']);
    expect(classedAsset($other->id)->tag)->toBe('FUR-0001');

    // A supplied tag is KEPT (a migrating register's own numbers), and the series carries on past it.
    $kept = classedAsset($this->asset->id, ['name' => 'Sofa', 'tag' => 'LEGACY-7']);
    expect($kept->tag)->toBe('LEGACY-7')
        ->and(classedAsset($this->asset->id, ['name' => 'Stool'])->tag)->toBe('FUR-0003');

    // A kept tag UNDER the class's prefix in another numeric shape is not a member of the series:
    // `(int) '2026-0001'` reads as 2026, and the next blank tag would have been FUR-2027.
    classedAsset($this->asset->id, ['name' => 'Carpet', 'tag' => 'FUR-2026-0001']);
    expect(classedAsset($this->asset->id, ['name' => 'Lamp'])->tag)->toBe('FUR-0004');
});

it('counts a series past its padding and past a retired asset', function () {
    classedAsset($this->asset->id, ['tag' => 'FUR-9999']);
    $next = classedAsset($this->asset->id);
    expect($next->tag)->toBe('FUR-10000');

    // Asked of the ALLOCATOR, not of the saved tag: the collision loop behind it masks a wrong
    // answer end to end (a string sort names `FUR-9999` the highest, proposes `FUR-10000`, finds it
    // taken and walks up to the right number anyway — EG-10's own shape), so only the proposal
    // itself can show the ordering is right. `DocumentSeriesOrderingConformanceTest` reads the
    // source for the same reason.
    expect(FixedAsset::generateTag($this->asset->id, 'FUR'))->toBe('FUR-10001');

    // Soft-deleted rows keep their number reserved — again asked of the proposal, which the loop
    // would otherwise rescue.
    $next->delete();
    expect(FixedAsset::generateTag($this->asset->id, 'FUR'))->toBe('FUR-10001')
        ->and(classedAsset($this->asset->id)->tag)->toBe('FUR-10001');
});

it('leaves an asset with no class or a class with no series needing a tag, in words', function () {
    // A legacy value with no row proposes nothing — the model still refuses a lifeless row in words.
    expect(fn () => FixedAsset::create([
        'asset_id' => $this->asset->id, 'name' => 'Odd one', 'category' => 'HVAC',
        'acquisition_date' => '2026-09-01', 'acquisition_cost' => 1000, 'useful_life_months' => null,
        'method' => 'straight_line', 'funded_from' => 'cash',
    ]))->not->toThrow(DomainException::class); // HVAC proposes 120 months

    FixedAssetCategory::where('code', 'HVAC')->sole()->update(['default_useful_life_months' => null]);
    FixedAssetCategory::flushCatalogue();

    expect(fn () => FixedAsset::create([
        'asset_id' => $this->asset->id, 'name' => 'Lifeless', 'category' => 'HVAC',
        'acquisition_date' => '2026-09-01', 'acquisition_cost' => 1000,
        'method' => 'straight_line', 'funded_from' => 'cash',
    ]))->toThrow(DomainException::class, __('admin.fixed_assets.errors.useful_life_required'));
});

// ── #13 · #14: the class proposes, the row decides ─────────────────────────────────────────────

it('proposes the memo value, the life and the tax pool from the class, and never overwrites a figure stated', function () {
    $proposed = classedAsset($this->asset->id);
    expect((float) $proposed->salvage_value)->toBe(1.0)          // SAP's memo value
        ->and($proposed->useful_life_months)->toBe(60)
        ->and($proposed->tax_pool)->toBe(TaxDepreciation::GENERAL)
        ->and($proposed->annualRatePct())->toBe(20.0);

    // A class proposing NO pool: the row still states one — the statutory default, the reading
    // `TaxDepreciationService` would give a blank — so the form shows a pool back.
    FixedAssetCategory::where('code', 'vehicles')->sole()->update(['default_tax_pool' => null]);
    expect(classedAsset($this->asset->id, ['name' => 'Van', 'category' => 'vehicles'])->tax_pool)->toBe(TaxDepreciation::default());

    // Stated wins — including an explicit zero salvage.
    $stated = classedAsset($this->asset->id, ['salvage_value' => 0, 'useful_life_months' => 36, 'tax_pool' => TaxDepreciation::COMPUTERS]);
    expect((float) $stated->salvage_value)->toBe(0.0)
        ->and($stated->useful_life_months)->toBe(36)
        ->and($stated->tax_pool)->toBe(TaxDepreciation::COMPUTERS);

    // Revising the class afterwards leaves every asset already registered alone — including one
    // whose pool was deliberately left blank, saved again for a rename after the class gained one.
    $unpooled = classedAsset($this->asset->id, ['name' => 'Unpooled', 'tax_pool' => null]);
    expect($unpooled->tax_pool)->toBe(TaxDepreciation::GENERAL); // proposed at birth
    $unpooled->forceFill(['tax_pool' => null])->saveQuietly();

    FixedAssetCategory::where('code', 'furniture')->sole()->update(['default_salvage_value' => 500, 'default_useful_life_months' => 84, 'default_tax_pool' => TaxDepreciation::BUILDINGS]);
    FixedAssetCategory::flushCatalogue();
    $unpooled->fresh()->update(['name' => 'Renamed']);
    expect((float) $proposed->fresh()->salvage_value)->toBe(1.0)
        ->and($proposed->fresh()->useful_life_months)->toBe(60)
        ->and($unpooled->fresh()->tax_pool)->toBeNull()
        ->and(classedAsset($this->asset->id, ['name' => 'Later'])->useful_life_months)->toBe(84);
});

it('reads one life two ways — months stored, the rate a year derived, in both directions', function () {
    expect(FixedAsset::annualRateFor(60))->toBe(20.0)
        ->and(FixedAsset::annualRateFor(36))->toBe(33.33)
        ->and(FixedAsset::annualRateFor(0))->toBeNull()
        ->and(FixedAsset::monthsForAnnualRate(20))->toBe(60)
        ->and(FixedAsset::monthsForAnnualRate(25))->toBe(48)
        ->and(FixedAsset::monthsForAnnualRate(33.33))->toBe(36)
        // A non-positive rate answers no life (the form's own floor refuses it; nothing is
        // silently rounded to one month), and a life under a year is a rate above 100%.
        ->and(FixedAsset::monthsForAnnualRate(0))->toBeNull()
        ->and(FixedAsset::monthsForAnnualRate(200))->toBe(6)
        ->and(FixedAsset::annualRateFor(6))->toBe(200.0);
});

// ── #14: the first month by days, posting still monthly ───────────────────────────────────────

it('charges the acquisition month by days under the setting, whole by default, and still sums to the base', function () {
    $service = app(DepreciationService::class);

    // Bought on the 20th of a 30-day month, 12,000 over 12 months, no memo value: 1,000 a month.
    $whole = classedAsset($this->asset->id, ['acquisition_date' => '2026-09-20', 'acquisition_cost' => 12000, 'salvage_value' => 0, 'useful_life_months' => 12]);
    $service->run(CarbonImmutable::parse('2026-09-01'), [$this->asset->id]);
    expect((float) $whole->depreciationEntries()->whereDate('period_month', '2026-09-01')->sole()->amount)->toBe(1000.0);

    // `refresh()` first: the settings singleton was loaded during the suite's migrations, before
    // this property's row existed, and Spatie refuses to save a value it loaded from a default.
    app(AccountingSettings::class)->refresh()->fill(['depreciation_proration' => DepreciationProration::DAYS])->save();

    $byDays = classedAsset($this->asset->id, ['name' => 'Prorated', 'acquisition_date' => '2026-10-20', 'acquisition_cost' => 12000, 'salvage_value' => 0, 'useful_life_months' => 12]);
    $service->run(CarbonImmutable::parse('2026-10-01'), [$this->asset->id]);

    // 12 of 31 days.
    expect((float) $byDays->depreciationEntries()->sole()->amount)->toBe(387.1);

    // Twelve more whole months, then the balance in a thirteenth — one entry a month, never a day.
    foreach (range(1, 13) as $n) {
        $service->run(CarbonImmutable::parse('2026-10-01')->addMonths($n), [$this->asset->id]);
    }

    $entries = $byDays->depreciationEntries()->orderBy('period_month')->get();
    expect($entries)->toHaveCount(13)
        ->and((float) $entries[1]->amount)->toBe(1000.0)
        ->and((float) $entries[12]->amount)->toBe(612.9)
        ->and(round((float) $entries->sum('amount'), 2))->toBe(12000.0)
        ->and($byDays->fresh()->accumulatedDepreciation())->toBe(12000.0);

    // The convention is read at RUN time and sizes the acquisition month only: the asset acquired
    // under the old convention keeps its whole first month, and its later months are whole too.
    expect((float) $whole->depreciationEntries()->whereDate('period_month', '2026-09-01')->sole()->amount)->toBe(1000.0)
        ->and((float) $whole->depreciationEntries()->whereDate('period_month', '2026-10-01')->sole()->amount)->toBe(1000.0);
});

it('clamps a typo in the setting to whole months rather than charging nothing', function () {
    app(AccountingSettings::class)->refresh()->fill(['depreciation_proration' => 'weekly'])->save();
    expect(DepreciationProration::current())->toBe(DepreciationProration::FULL_MONTH);
});

// ── The doors: the form, the importer, the class's own screen ─────────────────────────────────

describe('through the panel', function () {
    beforeEach(function () {
        $this->actingAs(makeUser('accounting', [$this->asset->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    it('offers the class first, prefills what it proposes, keeps the rate in step, and allocates the tag on save', function () {
        asTenant($this->asset, function () {
            $page = Livewire::test(CreateFixedAsset::class);
            // Category before name: the order the accountant asked for and SAP's.
            $html = $page->html();
            $category = strpos($html, 'data.category');
            $name = strpos($html, 'data.name');
            // Both present, then in that order — `false < int` is true, so a missing field must
            // not read as "first".
            expect($category)->not->toBeFalse()->and($name)->not->toBeFalse()->and($category)->toBeLessThan($name);

            // IT rather than HVAC, because IT's pool is NOT the statutory default: the form carried
            // a `general` default of its own once, so `blank()` was never true and the class's pool
            // never reached the field — HVAC (general) could not have shown it.
            $page->fillForm(['category' => 'IT'])
                ->assertFormSet([
                    'useful_life_months' => 48,
                    'salvage_value' => 1.0,
                    'tax_pool' => TaxDepreciation::COMPUTERS,
                    'annual_rate_pct' => 25.0,
                ]);

            // The pair follows itself both ways; a figure typed is not overwritten by a re-pick.
            $page->fillForm(['annual_rate_pct' => 20])->assertFormSet(['useful_life_months' => 60]);
            $page->fillForm(['useful_life_months' => 36])->assertFormSet(['annual_rate_pct' => 33.33]);
            $page->fillForm(['category' => 'furniture'])->assertFormSet(['useful_life_months' => 36, 'salvage_value' => 1.0, 'tax_pool' => TaxDepreciation::COMPUTERS]);

            $page->fillForm([
                'asset_id' => $this->asset->id, 'name' => 'Lobby chiller', 'tag' => null,
                'acquisition_date' => '2026-09-01', 'acquisition_cost' => 240000, 'funded_from' => 'bank',
            ])->call('create')->assertHasNoFormErrors();

            $created = FixedAsset::where('name', 'Lobby chiller')->sole();
            expect($created->tag)->toBe('FUR-0001')
                ->and($created->useful_life_months)->toBe(36)
                ->and((float) $created->salvage_value)->toBe(1.0)
                ->and($created->tax_pool)->toBe(TaxDepreciation::COMPUTERS);

            // Edit shows the rate beside the months, and a class outside the catalogue is refused.
            Livewire::test(EditFixedAsset::class, ['record' => $created->getKey()])
                ->assertFormSet(['annual_rate_pct' => 33.33])
                ->fillForm(['category' => 'no-such-class'])
                ->call('save')
                ->assertHasFormErrors(['category']);
        });
    });

    it('registers and edits an asset whose life is shorter than a year — the rate reads above 100%', function () {
        asTenant($this->asset, function () {
            // The derived rate is validated although it is never stored (Filament's default for a
            // non-dehydrated field), so a ceiling of 100% on it refused every short-lived asset on
            // create and locked its Edit page on a rename.
            Livewire::test(CreateFixedAsset::class)->fillForm([
                'asset_id' => $this->asset->id, 'category' => 'IT', 'name' => 'Trade-show laptops', 'tag' => null,
                'acquisition_date' => '2026-09-01', 'acquisition_cost' => 60000, 'useful_life_months' => 6, 'funded_from' => 'cash',
            ])->call('create')->assertHasNoFormErrors();

            $short = FixedAsset::where('name', 'Trade-show laptops')->sole();
            expect($short->useful_life_months)->toBe(6)->and($short->annualRatePct())->toBe(200.0);

            Livewire::test(EditFixedAsset::class, ['record' => $short->getKey()])
                ->assertFormSet(['annual_rate_pct' => 200.0])
                ->fillForm(['name' => 'Event laptops'])
                ->call('save')
                ->assertHasNoFormErrors();
            expect($short->fresh()->name)->toBe('Event laptops');
        });
    });

    it('refuses a cleared tag on edit as a form error, never as the column\'s NOT NULL', function () {
        asTenant($this->asset, function () {
            $chairs = classedAsset($this->asset->id);

            Livewire::test(EditFixedAsset::class, ['record' => $chairs->getKey()])
                ->fillForm(['tag' => ''])
                ->call('save')
                ->assertHasFormErrors(['tag']);
            expect($chairs->fresh()->tag)->toBe('FUR-0001');
        });
    });

    it('requires a class for a new asset, and not for a housekeeping edit of a row that never had one', function () {
        asTenant($this->asset, function () {
            Livewire::test(CreateFixedAsset::class)->fillForm([
                'asset_id' => $this->asset->id, 'name' => 'Unclassed', 'category' => null,
                'acquisition_date' => '2026-09-01', 'acquisition_cost' => 1000, 'useful_life_months' => 12, 'funded_from' => 'cash',
            ])->call('create')->assertHasFormErrors(['category']);

            $legacy = classedAsset($this->asset->id, ['category' => null, 'tag' => 'OLD-1', 'useful_life_months' => 12]);
            Livewire::test(EditFixedAsset::class, ['record' => $legacy->getKey()])
                ->fillForm(['name' => 'Renamed'])
                ->call('save')
                ->assertHasNoFormErrors();
            expect($legacy->fresh()->name)->toBe('Renamed')->and($legacy->fresh()->category)->toBeNull();
        });
    });

    it('lists the class by its label and the book rate on the register, in both languages, with no raw keys', function () {
        classedAsset($this->asset->id, ['tag' => 'FUR-1']);

        asTenant($this->asset, function () {
            Livewire::test(ListFixedAssets::class)
                ->assertSee('Furniture')->assertDontSee('admin.fixed_assets')->assertDontSee('admin.fields');

            app()->setLocale('ar');
            Livewire::test(ListFixedAssets::class)
                ->assertSee('أثاث')->assertDontSee('admin.fixed_assets')->assertDontSee('admin.fields');
            Livewire::test(CreateFixedAsset::class)
                ->assertSee('فئة')->assertDontSee('admin.fixed_assets')->assertDontSee('admin.fields');
        });
    });

    it('has a screen of its own where a class is added with a derived prefix, in both languages', function () {
        Filament::setTenant($this->asset);

        Livewire::test(ListFixedAssetCategories::class)
            ->assertOk()->assertSee('FUR-0001')->assertSee('Furniture')
            ->assertDontSee('admin.fixed_asset_categories')->assertDontSee('admin.fields');

        Livewire::test(CreateFixedAssetCategory::class)->fillForm([
            'code' => 'signage', 'name_en' => 'Signage', 'name_ar' => 'لافتات',
            'default_useful_life_months' => 60, 'default_salvage_value' => 1, 'default_tax_pool' => TaxDepreciation::GENERAL,
        ])->call('create')->assertHasNoFormErrors();

        $row = FixedAssetCategory::where('code', 'signage')->sole();
        expect($row->tag_prefix)->toBe('SIGN')
            ->and(classedAsset($this->asset->id, ['category' => 'signage'])->tag)->toBe('SIGN-0001');

        app()->setLocale('ar');
        Livewire::test(ListFixedAssetCategories::class)
            ->assertSee('لافتات')->assertDontSee('admin.fixed_asset_categories')->assertDontSee('admin.fields');
    });

    it('is the accountant\'s screen — leasing cannot open it', function () {
        Filament::setTenant($this->asset);
        Livewire::test(ListFixedAssetCategories::class)->assertOk();

        auth()->logout();
        session()->flush();
        $this->actingAs(makeUser('leasing', [$this->asset->id]));
        Filament::setTenant($this->asset);
        Livewire::test(ListFixedAssetCategories::class)->assertForbidden();
    });
});

describe('through the importer', function () {
    beforeEach(function () {
        $this->import = Import::create([
            'completed_at' => null, 'file_name' => 'fa.csv', 'file_path' => 'fa.csv',
            'importer' => FixedAssetImporter::class, 'processed_rows' => 0, 'total_rows' => 1, 'successful_rows' => 0,
            'user_id' => User::factory()->create()->id,
        ]);
    });

    it('keeps a supplied tag, allocates a blank one, takes the class\'s proposals for blanks, and refuses a class it does not know', function () {
        $row = function (array $data): void {
            $map = collect(array_keys($data))->mapWithKeys(fn ($k) => [$k => $k])->all();
            (new FixedAssetImporter($this->import, $map, []))($data);
        };

        $row(['asset_code' => 'CLS', 'tag' => 'CH-7', 'name' => 'Old chiller', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '100000', 'opening_accumulated_depreciation' => '30000', 'useful_life_months' => '', 'salvage_value' => '']);
        $kept = FixedAsset::where('name', 'Old chiller')->sole();
        expect($kept->tag)->toBe('CH-7')
            ->and($kept->useful_life_months)->toBe(120)
            ->and((float) $kept->salvage_value)->toBe(1.0);

        $row(['asset_code' => 'CLS', 'tag' => '', 'name' => 'Untagged chiller', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '100000', 'opening_accumulated_depreciation' => '30000', 'useful_life_months' => '96', 'salvage_value' => '0']);
        $allocated = FixedAsset::where('name', 'Untagged chiller')->sole();
        expect($allocated->tag)->toBe('HVAC-0001')
            ->and($allocated->useful_life_months)->toBe(96)
            ->and((float) $allocated->salvage_value)->toBe(0.0);

        expect(fn () => $row(['asset_code' => 'CLS', 'tag' => 'X-1', 'name' => 'Odd', 'category' => 'no-such-class', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '1000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '12']))
            ->toThrow(ValidationException::class);

        // No class and no life: refused before it reaches the model's NOT NULL.
        expect(fn () => $row(['asset_code' => 'CLS', 'tag' => 'X-2', 'name' => 'Lifeless', 'category' => '', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '1000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '']))
            ->toThrow(ValidationException::class);

        // No class and no tag: nothing can number it, refused the same way.
        expect(fn () => $row(['asset_code' => 'CLS', 'tag' => '', 'name' => 'Unnumbered', 'category' => '', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '1000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '12']))
            ->toThrow(ValidationException::class);

        // A RE-IMPORT (the tag matched) with a blank life keeps the life the row already has —
        // Filament fills a blank as null otherwise, and the column is NOT NULL.
        $row(['asset_code' => 'CLS', 'tag' => 'CH-7', 'name' => 'Old chiller (renamed)', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '100000', 'opening_accumulated_depreciation' => '30000', 'useful_life_months' => '', 'salvage_value' => '']);
        expect($kept->fresh()->name)->toBe('Old chiller (renamed)')
            ->and($kept->fresh()->useful_life_months)->toBe(120);

        // A class proposing no life, and a blank cell: refused in the ONE exception whose words
        // `ImportCsv` writes into the failed-rows file — every other throwable is a message-less row.
        FixedAssetCategory::where('code', 'HVAC')->sole()->update(['default_useful_life_months' => null]);
        expect(fn () => $row(['asset_code' => 'CLS', 'tag' => 'X-3', 'name' => 'Lifeless class', 'category' => 'HVAC', 'acquisition_date' => '2023-01-01',
            'acquisition_cost' => '1000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '']))
            ->toThrow(RowImportFailedException::class, __('admin.fixed_assets.errors.useful_life_required'));
        expect(FixedAsset::where('name', 'Lifeless class')->exists())->toBeFalse();
    });
});

// ── The migration that rowed the register, and the tax page that reads the same schedule ─────

it('leaves the shipped classes to the seeder and rewrites the register to the spelling it rowed', function () {
    // An install that already HELD assets: the shipped codes in use, in more than one spelling,
    // beside a free-text value nobody shipped. The catalogue is emptied to what the migration sees.
    FixedAssetCategory::query()->delete();
    foreach ([['Chiller', 'HVAC'], ['Old chiller', 'hvac'], ['Desk', ' Furniture & Fittings '], ['Chair', 'Furniture & Fittings']] as [$name, $category]) {
        DB::table('fixed_assets')->insert([
            'asset_id' => $this->asset->id, 'name' => $name, 'tag' => 'T-'.$name, 'category' => $category,
            'acquisition_date' => '2025-01-01', 'acquisition_cost' => 1000, 'salvage_value' => 0, 'useful_life_months' => 12,
            'method' => 'straight_line', 'funded_from' => 'cash', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    (require database_path('migrations/2026_09_12_400000_a_fixed_asset_category_is_a_row_that_numbers_the_asset.php'))->backfillFromRegister();

    // No row for a shipped code — the seeder owns it and would otherwise have found it already
    // rowed with NO life, NO pool and a derived prefix on exactly the installs that have assets.
    expect(FixedAssetCategory::whereRaw('lower(code) = ?', ['hvac'])->exists())->toBeFalse()
        ->and(DB::table('fixed_assets')->whereIn('name', ['Chiller', 'Old chiller'])->pluck('category')->unique()->all())->toBe(['HVAC']);

    // The operator's own value is rowed ONCE, trimmed, and every spelling in the register now
    // reads exactly as the row does — the value-set guard compares strictly.
    $own = FixedAssetCategory::where('code', 'Furniture & Fittings')->sole();
    expect($own->tag_prefix)->toBe('FURN')->and((float) $own->default_salvage_value)->toBe(1.0)
        ->and(DB::table('fixed_assets')->whereIn('name', ['Desk', 'Chair'])->pluck('category')->unique()->all())->toBe(['Furniture & Fittings']);

    $this->seed(FixedAssetCategorySeeder::class);
    $hvac = FixedAssetCategory::where('code', 'HVAC')->sole();
    expect($hvac->default_useful_life_months)->toBe(120)->and($hvac->tag_prefix)->toBe('HVAC')->and($hvac->default_tax_pool)->toBe(TaxDepreciation::GENERAL);

    // And the rewritten rows save: the guard accepts what the register now carries.
    FixedAsset::where('name', 'Old chiller')->sole()->update(['notes' => 'still fine']);
    FixedAsset::where('name', 'Desk')->sole()->update(['notes' => 'still fine']);
});

it('projects the book charge on the tax page from the same schedule the ledger posts', function () {
    app(AccountingSettings::class)->refresh()->fill(['depreciation_proration' => DepreciationProration::DAYS])->save();

    // Acquired on the 20th: September takes 11/30 of 1,000, December is whole — and a copy of the
    // arithmetic that charged four whole months read 4,000 where the ledger posts 3,366.67.
    $asset = classedAsset($this->asset->id, ['acquisition_date' => '2026-09-20', 'acquisition_cost' => 12001, 'salvage_value' => 1, 'useful_life_months' => 12]);
    $book = app(DepreciationService::class);
    foreach (['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'] as $month) {
        $book->run(CarbonImmutable::parse($month), [$this->asset->id]);
    }

    $posted = round((float) $asset->depreciationEntries()->sum('amount'), 2);
    expect($posted)->toBe(3366.67);

    $schedule = app(TaxDepreciationService::class)->schedule(2026, [$this->asset->id]);
    expect($schedule['book_total'])->toBe($posted);

    // The year the schedule runs out: the thirteenth, partial month is counted, so the book total
    // over the life is the depreciable base and not one month more.
    expect(app(TaxDepreciationService::class)->schedule(2027, [$this->asset->id])['book_total'])->toBe(round(12000 - 3366.67, 2));
});
