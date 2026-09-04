<?php

/*
|--------------------------------------------------------------------------
| Regression — a mistyped estimate was a 500, not a message
|--------------------------------------------------------------------------
| `facility_work_orders.est_labour_hours` is `decimal(8,2)` and both forms that collect it carried
| `->minValue(0)` and no ceiling. Measured 2026-09-04 on the dev MySQL, in a TEMPORARY table so no
| real row was touched: inserting `1000000` into a `decimal(8,2)` raises
| `SQLSTATE[22003] … 1264 Out of range value` — a `QueryException`, i.e. the 500 page, after the
| operator has pressed Save and lost the form. `999999.99` is accepted; `9999.99` is accepted.
|
| **The suite could never have seen the crash.** SQLite has no decimal precision to overflow, so the
| same create succeeds there — the "green here, fatal on the real database" split CLAUDE.md records
| for `GREATEST` and for the CHECK constraints SQLite drops. What CAN be asserted on SQLite is the
| REFUSAL: a form that states a ceiling refuses the figure before any driver sees it.
*/

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\CreateFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\FacilityWorkOrder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'EST']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses an estimate the column cannot hold, on the form rather than in the driver', function () {
    Livewire::test(CreateFacilityWorkOrder::class)
        ->fillForm([
            'asset_id' => $this->asset->id,
            'title' => 'Chiller overhaul',
            'trade_id' => tradeId('hvac'),
            'priority' => 'high',
            'scheduled_for' => '2026-10-01',
            'est_labour_hours' => 1_000_000,
        ])
        ->call('create')
        ->assertHasFormErrors(['est_labour_hours']);

    expect(FacilityWorkOrder::count())->toBe(0);
});

it('still accepts an estimate any real overhaul could need', function () {
    // The control. A ceiling that refused ordinary work would be worse than the 500 it replaces:
    // 480 hours is three people for four weeks, which a mall plans for an annual shutdown.
    Livewire::test(CreateFacilityWorkOrder::class)
        ->fillForm([
            'asset_id' => $this->asset->id,
            'title' => 'Annual chiller overhaul',
            'trade_id' => tradeId('hvac'),
            'priority' => 'high',
            'scheduled_for' => '2026-10-01',
            'est_labour_hours' => 480,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) FacilityWorkOrder::sole()->est_labour_hours)->toBe(480.0);
});

it('states one ceiling, and both doors onto the field read it', function () {
    // The corrective form is reached only through an action modal, and a schema is built ON MOUNT
    // — so this reads the component the static factory actually returns rather than trusting that
    // the two files were edited together.
    $field = collect(CorrectiveWorkOrderForm::fields(null))
        ->first(fn ($component): bool => $component instanceof TextInput
            && $component->getName() === 'est_labour_hours');

    expect($field)->not->toBeNull()
        ->and($field->getMaxValue())->toBe(FacilityWorkOrder::MAX_EST_LABOUR_HOURS);
});

it('keeps the ceiling inside what the column can hold', function () {
    // The bound this exists for, stated as a fact rather than as a number nobody re-checks:
    // decimal(8,2) tops out at 999,999.99, so the operator ceiling has to sit under it — and far
    // enough above ordinary work that no real estimate is refused.
    expect(FacilityWorkOrder::MAX_EST_LABOUR_HOURS)
        ->toBeLessThan(1_000_000)
        ->toBeGreaterThan(1_000);
});
