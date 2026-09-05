<?php

use App\Filament\Admin\RelationManagers\AssetUnitsRelationManager;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Imports\UnitImporter;
use App\Services\RemeasureUnitService;
use App\Support\AreaFitsTheProperty;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A unit cannot measure more than the whole lettable part of the mall it sits in.
 *
 * Reported by the tester: on a property whose Leasable Area read 0, unit A-01 saved at 1,000 m² with
 * no warning. Nothing errors when this happens, which is why it would never be reported as a bug in
 * the unit register — the damage lands on `CamReconciliationService`, which apportions a recovery
 * pool by area, so a unit larger than the building takes above a 100% share and every other tenant
 * is under-charged.
 *
 * THREE doors write `units.area_sqm` and all three are covered here: the create form, the remeasure
 * service (the path that exists precisely to change the number later), and the importer. Enumerated
 * by grepping for the column rather than from the diff that fixed the first one.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['name' => 'Plaza Mall', 'total_area_sqm' => 1200, 'leasable_area_sqm' => 900]);
});

it('refuses a unit larger than the property on the create form', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateUnit::class)
            ->fillForm([
                'asset_id' => $this->asset->id,
                'code' => 'A-01',
                'area_sqm' => 1000,
                'category' => 'retail',
                'status' => 'vacant',
            ])
            ->call('create')
            ->assertHasFormErrors(['area_sqm']);
    });
});

it('still accepts a unit that fits', function () {
    // The control — every refusal here passes just as happily on a form that refuses everything.
    asTenant($this->asset, function () {
        Livewire::test(CreateUnit::class)
            ->fillForm([
                'asset_id' => $this->asset->id,
                'code' => 'A-02',
                'area_sqm' => 850,
                'category' => 'retail',
                'status' => 'vacant',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    expect($this->asset->units()->where('code', 'A-02')->exists())->toBeTrue();
});

it('refuses a RE-MEASUREMENT that takes a unit past the property', function () {
    // The second door, and the one that matters most: it exists to change the area AFTER creation,
    // so a create-time-only rule would leave the hole open on the path built for the job.
    $unit = makeUnit($this->asset, ['area_sqm' => 400]);

    expect(fn () => app(RemeasureUnitService::class)->record($unit, 1000))
        ->toThrow(DomainException::class);

    expect((float) $unit->fresh()->area_sqm)->toBe(400.0);
});

it('still records a re-measurement that fits', function () {
    $unit = makeUnit($this->asset, ['area_sqm' => 400]);

    app(RemeasureUnitService::class)->record($unit, 880);

    expect((float) $unit->fresh()->area_sqm)->toBe(880.0);
});

it('stays silent when the property has never stated a leasable area', function () {
    // Null means "not measured", and a ceiling of zero would refuse every unit on a property nobody
    // has measured — turning a missing figure into an unusable register. Legacy and imported rows
    // predate the requirement AssetForm now enforces.
    $unmeasured = makeAsset(['total_area_sqm' => null, 'leasable_area_sqm' => null]);

    expect(AreaFitsTheProperty::exceeds(9999.0, $unmeasured))->toBeFalse()
        ->and(AreaFitsTheProperty::exceeds(9999.0, $this->asset))->toBeTrue()
        // A property whose leasable area is a legacy 0 is "not measured" too, not "zero-sized".
        ->and(AreaFitsTheProperty::exceeds(9999.0, makeAsset(['leasable_area_sqm' => 0])))->toBeFalse()
        // Exactly equal fits: a single-unit property is an ordinary arrangement.
        ->and(AreaFitsTheProperty::exceeds(900.0, $this->asset))->toBeFalse();
});

it('says the recorded total out loud on the property, and flags it when it does not fit', function () {
    // The "warn" half of the card. Units that ADD UP to more than the property are deliberately NOT
    // refused — a re-survey lands one unit at a time, and a total-based refusal would lock the
    // operator out of correcting the very rows that put it over.
    makeUnit($this->asset, ['area_sqm' => 500]);
    makeUnit($this->asset, ['area_sqm' => 300]);

    $within = asTenant($this->asset, fn () => Livewire::test(AssetUnitsRelationManager::class, [
        'ownerRecord' => $this->asset,
        'pageClass' => EditAsset::class,
    ])->instance()->getTable()->getDescription());

    expect($within)->toContain('800.00')->toContain('900.00');

    makeUnit($this->asset, ['area_sqm' => 400]); // 1,200 recorded against 900 leasable

    $over = asTenant($this->asset, fn () => Livewire::test(AssetUnitsRelationManager::class, [
        'ownerRecord' => $this->asset->fresh(),
        'pageClass' => EditAsset::class,
    ])->instance()->getTable()->getDescription());

    expect($over)->toContain('1,200.00')
        ->and($over)->not->toBe($within);
});

it('refuses an oversized unit on import, and a zero-area one', function () {
    // The third door. It carried `min:0` — a weaker bound than the form's, so a zero-area unit
    // could be imported into a register that had always refused one.
    $rules = collect(UnitImporter::getColumns())
        ->firstWhere(fn ($column) => $column->getName() === 'area_sqm')
        ->getDataValidationRules();

    expect($rules)->toContain('min:0.01')
        ->and($rules)->not->toContain('min:0');

    $rule = collect($rules)->first(fn ($r) => is_object($r));
    expect($rule)->not->toBeNull();

    $failed = null;
    $rule->setData(['asset_code' => $this->asset->code])
        ->validate('area_sqm', 1000, function ($message) use (&$failed) {
            $failed = $message;
        });
    expect($failed)->toBeString();

    $ok = null;
    $rule->setData(['asset_code' => $this->asset->code])
        ->validate('area_sqm', 800, function ($message) use (&$ok) {
            $ok = $message;
        });
    expect($ok)->toBeNull();
});
