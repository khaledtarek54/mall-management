<?php

use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\RelationManagers\AssetUnitsRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Exports\UnitExporter;
use App\Filament\Imports\UnitImporter;
use App\Models\Unit;
use App\Services\LeaseAgreementPdfService;
use App\Services\RemeasureUnitService;
use App\Services\Reports\ReportService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ExportCells;
use Tests\Support\UnitImports;

/**
 * **A unit carries its NET area beside its GROSS one** — client meeting 2026-09-02, point 20:
 * *"gross w net le msa7a le unit"*.
 *
 * `units.area_sqm` is the GROSS area: the chargeable figure — rent per m², the recovery share and
 * every occupancy number read it, and it stays the only measure any money rule reads. The new
 * `net_area_sqm` is the part inside the demise, what the tenant actually occupies once shared
 * corridors, columns and service space are taken out — INFORMATIONAL, printed beside the gross on
 * the register, the property's Units tab, the lease agreement and the rent roll, with the load
 * factor (gross ÷ net) under it. The market's shape: a space carries a rentable and a usable area
 * with the load factor between them, and charges run on the rentable one.
 *
 * Blank is NOT MEASURED, not zero — a factor against a missing figure is unknown, not 1.0. A stated
 * net never exceeds the gross, and `Unit::netAreaExceedsGross()` is the ONE predicate: the model's
 * hook, the unit form, the Remeasure modal and the importer all read it, so the four doors cannot
 * disagree. Not dated like `unit_areas`: nothing apportions on it, so a change has no past period
 * to protect — which is also why it is editable on Edit where the gross is not.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['name' => 'Plaza Mall', 'total_area_sqm' => 1200, 'leasable_area_sqm' => 900]);
    $this->unit = makeUnit($this->asset, ['code' => 'A-01', 'area_sqm' => 100, 'net_area_sqm' => 85]);
});

it('keeps the net inside the gross at the model, whichever of the two moves, and stays quiet on a blank', function () {
    // Setting a net above the gross.
    $refused = fn () => $this->unit->forceFill(['net_area_sqm' => 120])->save();
    expect($refused)->toThrow(DomainException::class, Unit::netAreaRefusal(120, 100));

    // Every other refusal here is a controlled statement; the sentence names both figures.
    expect(Unit::netAreaRefusal(120, 100))->toContain('120.00')->toContain('100.00');

    // A blank net constrains nothing — not measured is not zero.
    $this->unit->refresh()->forceFill(['net_area_sqm' => null])->save();
    expect($this->unit->fresh()->net_area_sqm)->toBeNull()
        ->and($this->unit->fresh()->loadFactor())->toBeNull();

    // The load factor is gross ÷ net, to two places.
    $this->unit->forceFill(['net_area_sqm' => 85])->save();
    expect($this->unit->fresh()->loadFactor())->toBe(1.18);

    // A STATED net has to measure something — zero is not "not measured", blank is.
    expect(fn () => $this->unit->fresh()->forceFill(['net_area_sqm' => 0])->save())
        ->toThrow(DomainException::class, __('admin.errors.unit_area_not_positive'));

    // The predicate itself, both ways round, and quiet on either blank.
    expect(Unit::netAreaExceedsGross(85, 100))->toBeFalse()
        ->and(Unit::netAreaExceedsGross(100, 100))->toBeFalse()
        ->and(Unit::netAreaExceedsGross(100.01, 100))->toBeTrue()
        ->and(Unit::netAreaExceedsGross(null, 100))->toBeFalse()
        ->and(Unit::netAreaExceedsGross(85, null))->toBeFalse();
});

it('asks for the net on the create form beside the gross, refuses one above it, and lets it be edited later where the gross cannot', function () {
    asTenant($this->asset, function () {
        // Refused in the form, on its own field, in words.
        Livewire::test(CreateUnit::class)
            ->fillForm(['asset_id' => $this->asset->id, 'code' => 'B-01', 'area_sqm' => 200, 'net_area_sqm' => 250, 'category' => 'retail', 'status' => 'vacant'])
            ->call('create')
            ->assertHasFormErrors(['net_area_sqm']);

        // The control: a net inside the gross saves, and a blank one saves as not measured.
        Livewire::test(CreateUnit::class)
            ->fillForm(['asset_id' => $this->asset->id, 'code' => 'B-01', 'area_sqm' => 200, 'net_area_sqm' => 170, 'category' => 'retail', 'status' => 'vacant'])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreateUnit::class)
            ->fillForm(['asset_id' => $this->asset->id, 'code' => 'B-02', 'area_sqm' => 50, 'category' => 'retail', 'status' => 'vacant'])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Unit::where('code', 'B-01')->sole();
        expect((float) $created->net_area_sqm)->toBe(170.0)
            ->and(Unit::where('code', 'B-02')->sole()->net_area_sqm)->toBeNull();

        // On EDIT the gross is a dated record and locked; the net is informational and open.
        $edit = Livewire::test(EditUnit::class, ['record' => $created->getRouteKey()]);
        $edit->assertFormFieldDisabled('area_sqm')->assertFormFieldEnabled('net_area_sqm');

        $edit->fillForm(['net_area_sqm' => 160])->call('save')->assertHasNoFormErrors();
        expect((float) $created->fresh()->net_area_sqm)->toBe(160.0)
            // …and no dated area row was written for it — the gross's history is untouched.
            ->and($created->areas()->count())->toBe(1)
            ->and((float) $created->fresh()->area_sqm)->toBe(200.0);

        // The same refusal on Edit, against the locked gross the form still carries.
        Livewire::test(EditUnit::class, ['record' => $created->getRouteKey()])
            ->fillForm(['net_area_sqm' => 210])->call('save')->assertHasFormErrors(['net_area_sqm']);
    });
});

it('carries the net with a re-survey, and refuses a gross shrunk below a net that was not re-stated', function () {
    $service = app(RemeasureUnitService::class);

    // A survey that shrinks the gross below the standing net (85) is refused with the way out —
    // unless the net is re-stated with it.
    expect(fn () => $service->record($this->unit, 80, ['effective_from' => now()->toDateString()]))
        ->toThrow(DomainException::class, Unit::netAreaRefusal(85, 80));
    expect((float) $this->unit->fresh()->area_sqm)->toBe(100.0);

    $service->record($this->unit, 80, ['effective_from' => now()->toDateString(), 'net_area_sqm' => 70]);
    expect((float) $this->unit->fresh()->area_sqm)->toBe(80.0)
        ->and((float) $this->unit->fresh()->net_area_sqm)->toBe(70.0)
        ->and($this->unit->areas()->count())->toBe(2);

    // A survey that CONFIRMS the gross and corrects the net still records the net — the no-change
    // branch that opens no second dated row must not drop it.
    $service->record($this->unit->fresh(), 80, ['effective_from' => now()->toDateString(), 'net_area_sqm' => 72]);
    expect((float) $this->unit->fresh()->net_area_sqm)->toBe(72.0)
        ->and($this->unit->areas()->count())->toBe(2);

    // An absent key leaves the net alone; null clears it.
    $service->record($this->unit->fresh(), 90, ['effective_from' => now()->addDay()->toDateString()]);
    expect((float) $this->unit->fresh()->net_area_sqm)->toBe(72.0);
    $service->record($this->unit->fresh(), 95, ['effective_from' => now()->addDays(2)->toDateString(), 'net_area_sqm' => null]);
    expect($this->unit->fresh()->net_area_sqm)->toBeNull();

    // …and through the Remeasure modal itself, which asks for both and defaults the net to the
    // unit's own. A fresh unit, so the dated rows above cannot get in the way: gross 100 / net 60.
    $unit = makeUnit($this->asset, ['code' => 'A-09', 'area_sqm' => 100, 'net_area_sqm' => 60]);
    asTenant($this->asset, function () use ($unit) {
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->mountAction(TestAction::make('remeasure'))
            ->assertActionDataSet(['net_area_sqm' => 60.0]);

        // A fresh component per call: a mounted modal left open would make the next call resolve
        // `remeasure` as a CHILD of itself.
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 50, 'net_area_sqm' => 55, 'effective_from' => now()->toDateString()])
            ->assertHasActionErrors(['net_area_sqm']);

        // The page REFILLS the net after the act, or the next Save writes the stale one back
        // (found by the review: a survey that moved the net up left the old figure on the form,
        // and a plain Save reverted it under a success toast). ARRAY form — the closure form of
        // this assertion ignores what it returns.
        $page = Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 50, 'net_area_sqm' => 45, 'effective_from' => now()->toDateString()])
            ->assertHasNoActionErrors()
            ->assertFormSet(['area_sqm' => '50.00', 'net_area_sqm' => '45.00']);
        $page->call('save')->assertHasNoFormErrors();
        expect((float) $unit->fresh()->net_area_sqm)->toBe(45.0);

        // A survey that CONFIRMS the gross and corrects the net is accepted from the modal too —
        // the unchanged-gross refusal stands down when the net moved — and one that changes
        // neither is still refused as nothing to record.
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 50, 'net_area_sqm' => 44, 'effective_from' => now()->toDateString()])
            ->assertHasNoActionErrors();
        expect((float) $unit->fresh()->net_area_sqm)->toBe(44.0);
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 50, 'net_area_sqm' => 44, 'effective_from' => now()->toDateString()])
            ->assertHasActionErrors(['area_sqm']);

        // A survey dated AHEAD does not move the gross today, so the net — which is undated and
        // lands today — is judged against TODAY's gross (50), not the survey's (120): 110 is
        // refused on its own field, 48 is accepted and lands now while the gross waits.
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 120, 'net_area_sqm' => 110, 'effective_from' => now()->addDays(5)->toDateString()])
            ->assertHasActionErrors(['net_area_sqm']);
        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->callAction(TestAction::make('remeasure'), data: ['area_sqm' => 120, 'net_area_sqm' => 48, 'effective_from' => now()->addDays(5)->toDateString()])
            ->assertHasNoActionErrors();
    });
    expect((float) $unit->fresh()->area_sqm)->toBe(50.0)
        ->and((float) $unit->fresh()->net_area_sqm)->toBe(48.0);
});

it('imports the net beside the gross, refuses one above it in words, and clears it on a blank cell', function () {
    asTenant($this->asset, function () {
        $refusal = UnitImports::row($this->asset, ['asset_code' => $this->asset->code, 'code' => 'C-01', 'area_sqm' => '100', 'net_area_sqm' => '120']);
        expect($refusal)->toBeString('the row was accepted with a net above its gross')
            ->and($refusal)->toContain('120.00')->toContain('100.00')
            ->and($refusal)->not->toContain('SQLSTATE');

        $imported = UnitImports::row($this->asset, ['asset_code' => $this->asset->code, 'code' => 'C-01', 'area_sqm' => '100', 'net_area_sqm' => '82.5']);
        expect($imported)->toBeInstanceOf(Unit::class, "the import refused the row: {$imported}")
            ->and((float) $imported->net_area_sqm)->toBe(82.5);

        // A re-import whose file has NO gross column (an absent key here = an unmapped column; a
        // MAPPED blank cell is null, which the gross has always refused) keeps the unit's own
        // gross, and the pair is still asked of the row as it will be saved — the hook, not a
        // cell rule, is what can see both.
        $refusal = UnitImports::row($this->asset, ['asset_code' => $this->asset->code, 'code' => 'C-01', 'net_area_sqm' => '150']);
        expect($refusal)->toBeString('a net above the unit\'s standing gross was accepted on re-import')
            ->and($refusal)->toContain('150.00');

        // A mapped blank cell CLEARS — the column is settable back through the door that set it.
        $cleared = UnitImports::row($this->asset, ['asset_code' => $this->asset->code, 'code' => 'C-01', 'area_sqm' => '100', 'net_area_sqm' => '']);
        expect($cleared)->toBeInstanceOf(Unit::class)
            ->and($cleared->net_area_sqm)->toBeNull();
    });

    // The exporter carries the same column under the importer's own label, AFTER the columns the
    // file always had, so the round trip maps by label and a template on the old positions holds.
    $cells = ExportCells::row(UnitExporter::class, $this->unit->refresh());
    expect($cells)->toHaveKey('net_area_sqm')
        ->and((string) $cells['net_area_sqm'])->toBe('85.00');
    $names = collect(UnitExporter::getColumns())->map(fn ($c) => $c->getName())->values();
    expect($names->search('net_area_sqm'))->toBeGreaterThan($names->search('status'));
    $label = collect(UnitImporter::getColumns())->firstWhere(fn ($c) => $c->getName() === 'net_area_sqm')->getLabel();
    expect(collect(UnitExporter::getColumns())->firstWhere(fn ($c) => $c->getName() === 'net_area_sqm')->getLabel())->toBe($label);

    // A template exported under the old "Area" header still maps onto the gross.
    $gross = collect(UnitImporter::getColumns())->firstWhere(fn ($c) => $c->getName() === 'area_sqm');
    expect($gross->getGuesses())->toContain('area')->toContain('gross area');
});

it('shows the net beside the gross on the register and the property tab, with the load factor under it', function () {
    asTenant($this->asset, function () {
        $list = Livewire::test(ListUnits::class)->assertOk();
        $list->assertCanRenderTableColumn('net_area_sqm')
            ->assertTableColumnFormattedStateSet('net_area_sqm', '85', $this->unit)
            ->assertSee(__('admin.tables.unit.load_factor', ['factor' => '1.18']));

        Livewire::test(AssetUnitsRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => EditAsset::class])
            ->assertOk()
            ->assertCanRenderTableColumn('net_area_sqm')
            ->assertSee(__('admin.tables.unit.load_factor', ['factor' => '1.18']));
    });

    // Both languages, no raw key — and the gross is now called what it is, beside the net.
    foreach (['en', 'ar'] as $locale) {
        foreach (['area', 'net_area', 'load_factor'] as $key) {
            expect(__("admin.tables.unit.{$key}", ['factor' => '1.18'], $locale))->not->toContain('admin.tables');
        }
        expect(__('admin.helpers.unit_net_area', [], $locale))->not->toContain('admin.helpers')
            ->and(__('admin.refusals.unit_net_area_exceeds_gross', ['net' => 1, 'gross' => 2], $locale))->not->toContain('admin.refusals');
    }
    expect(__('admin.tables.unit.area', [], 'en'))->not->toBe('Area')
        ->and(__('admin.tables.unit.net_area', [], 'ar'))->toMatch('/\p{Arabic}/u');
});

it('prints the net on the lease agreement beside the gross, only when a let unit states one, and the rent stays priced on the gross', function () {
    $lease = makeLease($this->unit, makeTenant(['name' => 'Cilantro']), ['status' => 'active', 'base_rent_monthly' => 12000]);
    // The rent roll prices from the charge ladder, not the lease column.
    $lease->charges()->create(['name' => 'Rent', 'type' => 'base_rent', 'amount' => 12000, 'currency' => 'EGP', 'frequency' => 'monthly', 'start_date' => now()->subMonth()->toDateString(), 'is_active' => true]);
    $html = app(LeaseAgreementPdfService::class)->document($lease->fresh(), 'en')->html();

    expect($html)->toContain(__('admin.tables.unit.net_area', [], 'en'))
        ->toMatch('/100\.00.*?85\.00/s');

    // The rent roll carries it too, and the per-m² comparison figure stays on the GROSS.
    $row = app(ReportService::class)->rentRoll(null, $this->asset->id)->firstWhere('lease_id', $lease->id);
    expect($row['area_sqm'])->toBe(100.0)
        ->and($row['net_area_sqm'])->toBe(85.0)
        ->and($row['base_rent'])->toBe(12000.0)
        ->and($row['rent_per_sqm_year'])->toBe(round(12000 * 12 / 100, 2));

    asTenant($this->asset, function () use ($lease) {
        $page = Livewire::test(RentRoll::class)->assertOk()->assertCanRenderTableColumn('net_area_sqm');
        $csv = $page->instance()->reportCsv();
        expect(end($csv['headers']))->toBe(__('admin.tables.unit.net_area'))
            ->and(collect($csv['rows'])->first(fn ($r) => $r[2] === $lease->reference))->toContain(85.0);
    });

    // A second unit on the lease with NO net: the column still prints (one unit states one), that
    // row reads a dash, and the TOTAL is a dash too — a total over a mix of measured and unmeasured
    // shops would read as a smaller premises than the parties agreed. The rent roll says null.
    $second = makeUnit($this->asset, ['code' => 'A-02', 'area_sqm' => 40]);
    $lease->units()->attach($second->id);
    $html = app(LeaseAgreementPdfService::class)->document($lease->fresh(), 'en')->html();
    expect($html)->toContain(__('admin.tables.unit.net_area', [], 'en'))
        ->toMatch('/140\.00<\/td>\s*<td class="num">—<\/td>/');
    expect(app(ReportService::class)->rentRoll(null, $this->asset->id)->firstWhere('lease_id', $lease->id)['net_area_sqm'])->toBeNull();
    $lease->units()->detach($second->id);

    // With no net stated on any let unit the column does not print at all — a heading over a
    // column of dashes reads as a missing measurement rather than an unmeasured one.
    $this->unit->forceFill(['net_area_sqm' => null])->save();
    $html = app(LeaseAgreementPdfService::class)->document($lease->fresh(), 'en')->html();
    expect($html)->not->toContain(__('admin.tables.unit.net_area', [], 'en'));
    expect(app(ReportService::class)->rentRoll(null, $this->asset->id)->firstWhere('lease_id', $lease->id)['net_area_sqm'])->toBeNull();
});
