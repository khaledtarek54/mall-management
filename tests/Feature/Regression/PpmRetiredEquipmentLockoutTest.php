<?php

use App\Filament\Admin\Resources\ServicePlans\Pages\EditServicePlan;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Models\Equipment;
use App\Models\ServicePlan;
use App\Models\FacilityWorkOrder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Regression guard — retiring a machine must not deadlock the records that name it.
 *
 * Found by the adversarial review of phase 2c. The equipment Select filtered its options to
 * `->active()`, and Filament derives an `in:` rule from a Select's options and validates the
 * **currently stored value** against it (`Select::getInValidationRuleValues()` → blank label
 * → `Rule::in([])`, which always fails). So the moment ops decommissioned a machine —
 * `is_active = false`, or a soft delete, both ordinary lifecycle events — every save of its
 * plan failed with "The selected equipment is invalid.", *including the attempt to
 * deactivate the plan*. The record deadlocked with no UI escape while the nightly scan kept
 * raising work orders against a machine that no longer existed.
 *
 * Nothing about this is visible from the model layer; only driving the real form finds it.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RET']);
    $this->machine = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'CH-01',
        'name_en' => 'Chiller', 'name_ar' => 'مبرد', 'category' => 'hvac',
    ]);
});

function retiredPlan(array $attrs = []): ServicePlan
{
    return ServicePlan::create(array_merge([
        'asset_id' => test()->asset->id,
        'title' => 'Chiller service',
        'category' => 'hvac',
        'plan_type' => 'fixed',
        'equipment_id' => test()->machine->id,
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => '2026-07-01',
        'is_active' => true,
    ], $attrs));
}

/* ---- the plan must stay editable ---------------------------------------- */

it('can still edit a fixed plan after its machine is deactivated', function () {
    $plan = retiredPlan();
    $this->machine->update(['is_active' => false]);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($plan) {
        Livewire::test(EditServicePlan::class, ['record' => $plan->id])
            ->fillForm(['title' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($plan->fresh()->title)->toBe('Renamed');
});

it('can still retire a plan whose machine was deactivated', function () {
    // The escape hatch that was itself blocked: with the plan unsavable, an operator could
    // not even stop it from generating.
    $plan = retiredPlan();
    $this->machine->update(['is_active' => false]);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($plan) {
        Livewire::test(EditServicePlan::class, ['record' => $plan->id])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($plan->fresh()->is_active)->toBeFalse();
});

it('can still edit a plan after its machine is soft-deleted', function () {
    $plan = retiredPlan();
    $this->machine->delete();
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($plan) {
        Livewire::test(EditServicePlan::class, ['record' => $plan->id])
            ->fillForm(['title' => 'Still editable'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($plan->fresh()->title)->toBe('Still editable');
});

/* ---- the work order must stay editable ---------------------------------- */

it('can still edit an open work order after its machine is deactivated', function () {
    $order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'equipment_id' => $this->machine->id,
        'title' => 'Legit', 'category' => 'hvac', 'status' => 'open', 'scheduled_for' => '2026-07-01',
    ]);
    $this->machine->update(['is_active' => false]);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($order) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $order->id])
            ->fillForm(['title' => 'Rescheduled'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    // The machine it was against is still recorded — blanking the field to escape the
    // lockout would have destroyed exactly the FR-PPM-03 fact the column exists to hold.
    expect($order->fresh()->title)->toBe('Rescheduled');
    expect($order->fresh()->equipment_id)->toBe($this->machine->id);
});

/* ---- the retired machine must not be offered for NEW records ------------ */

it('does not offer a retired machine when creating a new plan', function () {
    // The record's own stored value is included; a retired machine must not be a choice
    // for anything else, or the filter would be pointless.
    $this->machine->update(['is_active' => false]);
    $live = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'GEN-01',
        'name_en' => 'Generator', 'name_ar' => 'مولد',
    ]);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($live) {
        Livewire::test(\App\Filament\Admin\Resources\ServicePlans\Pages\CreateServicePlan::class)
            ->fillForm(['asset_id' => $this->asset->id])
            ->assertFormFieldExists('equipment_id', fn ($f) => array_keys($f->getOptions()) === [$live->id]);
    });
});
