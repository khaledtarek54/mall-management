<?php

use App\Filament\Admin\Resources\UtilityMeters\Pages\EditUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\RelationManagers\ReadingsRelationManager;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Utility meters + meter readings — net-new scenarios.
|--------------------------------------------------------------------------
| Covers the consumption-math the ReadingsRelationManager auto-fills
| (delta vs the prior reading), the rollback/anomaly guard, the
| first-reading "no prior" case, the per-period uniqueness invariant,
| how consumption rolls into the EnergyConsumptionTrend portfolio widget,
| asset/unit scoping of meters, and the meter CRUD RBAC matrix.
|
| The auto-fill lives in `reading_value`->afterStateUpdated (live, onBlur),
| so it only fires through a *real* Livewire state update — fillForm() /
| setTableActionData() deliberately suppress update hooks. We therefore
| drive the mounted create-action's data path directly:
|     ->set('mountedActions.0.data.reading_value', X)
*/

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'HW']);
    $this->unit = makeUnit($this->asset, ['code' => 'HW-01']);

    $this->meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'meter_number' => 'HW-E-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Seed a reading directly (bypasses the form) so we can set up prior state. */
function seedReading(UtilityMeter $meter, string $date, float $value, ?float $consumption = null): MeterReading
{
    return MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => $date,
        'reading_value' => $value,
        'consumption' => $consumption ?? 0,
        'cost' => 0,
    ]);
}

/** Mount the relation-manager create action so its form state is live. */
function mountReadingCreate(UtilityMeter $meter): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ReadingsRelationManager::class, [
        'ownerRecord' => $meter,
        'pageClass' => EditUtilityMeter::class,
    ])->mountTableAction('create');
}

/*
|--------------------------------------------------------------------------
| Consumption math — happy path: sequential readings compute curr − prev.
|--------------------------------------------------------------------------
*/

it('auto-fills consumption as the delta over the prior reading', function () {
    // Prior month closed at 5000 kWh.
    seedReading($this->meter, '2026-01-01', 5000);

    asTenant($this->asset, function () {
        mountReadingCreate($this->meter)
            // Keying the current value triggers the live delta calc.
            ->set('mountedActions.0.data.reading_date', '2026-02-01')
            ->set('mountedActions.0.data.reading_value', 5250)
            ->assertTableActionDataSet(['consumption' => 250.0]);
    });
});

it('keeps a manually entered consumption instead of overwriting it with the delta', function () {
    // Operator has a corrected figure (e.g. sub-meter spillover) and keys it
    // first — the delta auto-fill must not clobber it.
    seedReading($this->meter, '2026-01-01', 5000);

    asTenant($this->asset, function () {
        mountReadingCreate($this->meter)
            ->set('mountedActions.0.data.reading_date', '2026-02-01')
            ->set('mountedActions.0.data.consumption', 999)
            ->set('mountedActions.0.data.reading_value', 5250)
            ->assertTableActionDataSet(['consumption' => 999]);
    });
});

it('computes the delta against the latest prior reading across a 3-reading chain', function () {
    seedReading($this->meter, '2026-01-01', 5000);
    seedReading($this->meter, '2026-02-01', 5250);

    asTenant($this->asset, function () {
        // March reading: delta should be 5600 - 5250 = 350, NOT 5600 - 5000.
        mountReadingCreate($this->meter)
            ->set('mountedActions.0.data.reading_date', '2026-03-01')
            ->set('mountedActions.0.data.reading_value', 5600)
            ->assertTableActionDataSet(['consumption' => 350.0]);
    });
});

/*
|--------------------------------------------------------------------------
| First reading — no prior, so no consumption is computed.
|--------------------------------------------------------------------------
*/

it('does not auto-fill consumption for the very first reading (no prior)', function () {
    asTenant($this->asset, function () {
        mountReadingCreate($this->meter)
            ->set('mountedActions.0.data.reading_date', '2026-01-01')
            ->set('mountedActions.0.data.reading_value', 5000)
            // No prior reading -> consumption left empty for the operator.
            ->assertTableActionDataSet(['consumption' => null]);
    });
});

/*
|--------------------------------------------------------------------------
| Rollback / anomaly — a reading below the prior one is NOT auto-filled,
| and because consumption is required it cannot be saved blank → flagged.
|--------------------------------------------------------------------------
*/

it('does not auto-fill consumption when the meter reads lower than the prior (rollback)', function () {
    seedReading($this->meter, '2026-01-01', 5000);

    asTenant($this->asset, function () {
        mountReadingCreate($this->meter)
            ->set('mountedActions.0.data.reading_date', '2026-02-01')
            // Lower than 5000 — a meter reset / mis-key. Delta < 0, guard skips.
            ->set('mountedActions.0.data.reading_value', 4800)
            ->assertTableActionDataSet(['consumption' => null]);
    });
});

it('rejects a rollback reading when the operator leaves consumption blank (required guard)', function () {
    seedReading($this->meter, '2026-01-01', 5000);

    asTenant($this->asset, function () {
        Livewire::test(ReadingsRelationManager::class, [
            'ownerRecord' => $this->meter,
            'pageClass' => EditUtilityMeter::class,
        ])
            ->callTableAction('create', data: [
                'reading_date' => '2026-02-01',
                'reading_value' => 4800,
                'cost' => 0, // sidestep the unrelated cost-NOT-NULL bug (see skipped test)
                // consumption deliberately omitted — auto-fill won't help a rollback.
            ])
            ->assertHasTableActionErrors(['consumption' => 'required']);
    });

    // Nothing persisted.
    expect(MeterReading::where('utility_meter_id', $this->meter->id)
        ->whereDate('reading_date', '2026-02-01')->count())->toBe(0);
});

it('allows a rollback reading when the operator keys a corrected consumption explicitly', function () {
    seedReading($this->meter, '2026-01-01', 5000);

    asTenant($this->asset, function () {
        Livewire::test(ReadingsRelationManager::class, [
            'ownerRecord' => $this->meter,
            'pageClass' => EditUtilityMeter::class,
        ])
            ->callTableAction('create', data: [
                'reading_date' => '2026-02-01',
                'reading_value' => 4800,
                'consumption' => 120, // operator's corrected figure post-reset
                'cost' => 0,          // sidestep the cost-NOT-NULL bug (see skipped test)
            ])
            ->assertHasNoTableActionErrors();
    });

    expect((float) MeterReading::where('utility_meter_id', $this->meter->id)
        ->whereDate('reading_date', '2026-02-01')->value('consumption'))->toBe(120.0);
});

/*
|--------------------------------------------------------------------------
| BUG: leaving cost blank crashes the create with a 500 (NOT NULL).
|--------------------------------------------------------------------------
| The `cost` form field is optional (not required, no default) but the
| `meter_readings.cost` column is NOT NULL. Filament dehydrates an omitted
| numeric field to null, and the DDL `default(0)` is bypassed by the
| explicit NULL — so a normal "I don't have the cost yet" reading throws
| "NOT NULL constraint failed: meter_readings.cost". Fix: default the
| field to 0 / make the column nullable / required.
*/

it('saves a reading with cost left blank', function () {
    asTenant($this->asset, function () {
        Livewire::test(ReadingsRelationManager::class, [
            'ownerRecord' => $this->meter,
            'pageClass' => EditUtilityMeter::class,
        ])
            ->callTableAction('create', data: [
                'reading_date' => '2026-02-01',
                'reading_value' => 5000,
                'consumption' => 0,
                // cost omitted — should default to 0, not 500.
            ])
            ->assertHasNoTableActionErrors();
    });

    expect((float) MeterReading::where('utility_meter_id', $this->meter->id)
        ->whereDate('reading_date', '2026-02-01')->value('cost'))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Per-period uniqueness — one reading per (meter, date).
|--------------------------------------------------------------------------
*/

it('rejects a second reading on the same date for the same meter (unique period)', function () {
    seedReading($this->meter, '2026-02-01', 5000);

    expect(fn () => seedReading($this->meter, '2026-02-01', 5100))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows the same reading date on a different meter', function () {
    seedReading($this->meter, '2026-02-01', 5000);

    $otherMeter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'HW-W-' . uniqid(),
        'type' => 'water',
        'status' => 'active',
        'unit_of_measurement' => 'm3',
    ]);

    seedReading($otherMeter, '2026-02-01', 80);

    expect(MeterReading::whereDate('reading_date', '2026-02-01')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Consumption → reporting: rolls into the EnergyConsumptionTrend widget,
| summed per (month, meter type) and scoped to the active property.
|--------------------------------------------------------------------------
*/

it('sums consumption per meter type into the energy trend widget for the current property', function () {
    $month = now()->startOfMonth()->toDateString();

    // Two electric readings this month on this property's meters.
    seedReading($this->meter, $month, 5250, consumption: 250);
    $elec2 = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'HW-E2-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);
    seedReading($elec2, $month, 1100, consumption: 100);

    // A water reading — different series.
    $water = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'HW-W2-' . uniqid(),
        'type' => 'water',
        'status' => 'active',
        'unit_of_measurement' => 'm3',
    ]);
    seedReading($water, $month, 60, consumption: 60);

    $data = asTenant($this->asset, function () {
        $widget = new \App\Filament\Admin\Widgets\EnergyConsumptionTrend;

        return (fn () => $this->getData())->call($widget);
    });

    // Datasets are ordered electric, water, gas; values are 12 months ending now.
    $electric = $data['datasets'][0]['data'];
    $waterSeries = $data['datasets'][1]['data'];

    // Current month is the last bucket.
    expect(end($electric))->toBe(350.0)   // 250 + 100 summed for the type
        ->and(end($waterSeries))->toBe(60.0);
});

it('excludes another property\'s meter readings from this property\'s energy trend', function () {
    $month = now()->startOfMonth()->toDateString();
    seedReading($this->meter, $month, 5250, consumption: 250);

    // A different property entirely.
    $other = makeAsset(['code' => 'CP']);
    $otherMeter = UtilityMeter::create([
        'asset_id' => $other->id,
        'meter_number' => 'CP-E-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);
    seedReading($otherMeter, $month, 9999, consumption: 9999);

    $data = asTenant($this->asset, function () {
        $widget = new \App\Filament\Admin\Widgets\EnergyConsumptionTrend;

        return (fn () => $this->getData())->call($widget);
    });

    $electric = $data['datasets'][0]['data'];

    // Only HW's 250 — CP's 9999 must not leak in.
    expect(end($electric))->toBe(250.0);
});

/*
|--------------------------------------------------------------------------
| Scoping — meters belong to their asset; the list is property-scoped.
|--------------------------------------------------------------------------
*/

it('scopes the meter list to the active property only', function () {
    $other = makeAsset(['code' => 'CP']);
    UtilityMeter::create([
        'asset_id' => $other->id,
        'meter_number' => 'CP-E-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
    ]);

    $this->actingAs(makeUser('super_admin'));

    $ids = asTenant($this->asset, fn () => scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all());

    expect($ids)->toContain($this->meter->id)
        ->and($ids)->not->toContain(
            UtilityMeter::where('meter_number', 'like', 'CP-E-%')->value('id'),
        );
});

it('shows meters from every property when All-Properties is active', function () {
    $other = makeAsset(['code' => 'CP']);
    $cpMeter = UtilityMeter::create([
        'asset_id' => $other->id,
        'meter_number' => 'CP-E-' . uniqid(),
        'type' => 'electric',
        'status' => 'active',
    ]);

    $this->actingAs(makeUser('super_admin'));
    $all = ensureAllPropertiesAsset();

    $ids = asTenant($all, fn () => scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all());

    expect($ids)->toContain($this->meter->id)
        ->and($ids)->toContain($cpMeter->id);
});

it('binds a reading to its parent meter and that meter to its asset/unit', function () {
    $reading = seedReading($this->meter, '2026-02-01', 5000);

    expect($reading->meter->is($this->meter))->toBeTrue()
        ->and($reading->meter->asset->is($this->asset))->toBeTrue()
        ->and($reading->meter->unit->is($this->unit))->toBeTrue()
        ->and($this->meter->isCommonArea())->toBeFalse();
});

it('treats a unit-less meter as a common-area meter', function () {
    $common = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'HW-CA-' . uniqid(),
        'type' => 'water',
        'status' => 'active',
    ]);

    expect($common->isCommonArea())->toBeTrue()
        ->and($common->unit_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Audit-fixed asset picker — the form defaults & locks asset to the active
| property so a meter can't be created against another property by mistake.
|--------------------------------------------------------------------------
*/

it('defaults and locks the meter asset picker to the active property', function () {
    asTenant($this->asset, function () {
        Livewire::test(\App\Filament\Admin\Resources\UtilityMeters\Pages\CreateUtilityMeter::class)
            ->assertSet('data.asset_id', $this->asset->id)
            ->assertFormFieldDisabled('asset_id');
    });
});

/*
|--------------------------------------------------------------------------
| RBAC — meter CRUD permission matrix.
|--------------------------------------------------------------------------
*/

it('lets operations view and create meters but not delete them', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    expect(UtilityMeterResource::canViewAny())->toBeTrue()
        ->and(UtilityMeterResource::canCreate())->toBeTrue()
        ->and(UtilityMeterResource::canEdit($this->meter))->toBeTrue()
        ->and(UtilityMeterResource::canDelete($this->meter))->toBeFalse();
});

it('denies leasing and accounting any access to the meter resource', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    expect(UtilityMeterResource::canViewAny())->toBeFalse();

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    expect(UtilityMeterResource::canViewAny())->toBeFalse();
});

it('reserves meter deletion for super_admin only', function () {
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    expect(UtilityMeterResource::canDelete($this->meter))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(UtilityMeterResource::canDelete($this->meter))->toBeTrue();
});
