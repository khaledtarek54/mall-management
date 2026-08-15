<?php

use App\Models\Equipment;
use App\Models\ServicePlan;
use App\Models\FacilityWorkOrder;
use App\Models\TenantRequest;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Services\RaiseCorrectiveWorkOrderService;

/**
 * Asset criticality — how much it matters when this machine stops.
 *
 * A chiller serving the food court and a hand dryer in a back corridor were both just "equipment",
 * so every fault arrived at the same priority and the coordinator triaged from memory.
 *
 * **The tests that matter are the ones proving it CHANGES something.** A criticality field that only
 * renders a badge is a field that stays on its default for ever — so the substance here is that a
 * fault on a critical machine starts at urgent, a PM round on one does too, and neither overrides an
 * operator who was explicit.
 */
function criticalEquipment(string $criticality, ?int $assetId = null): Equipment
{
    $asset = $assetId ? \App\Models\Asset::find($assetId) : makeAsset();

    return Equipment::create([
        'asset_id' => $asset->id,
        'code' => 'EQ-'.uniqid(),
        'name_en' => 'Chiller',
        'name_ar' => 'مبرّد',
        'category' => 'hvac',
        'criticality' => $criticality,
    ]);
}

it('maps the scale to where a job starts', function () {
    // The decision itself, stated once. Everything below is about it reaching the real paths.
    expect(criticalEquipment(Equipment::CRITICAL)->defaultWorkOrderPriority())->toBe('urgent')
        ->and(criticalEquipment(Equipment::IMPORTANT)->defaultWorkOrderPriority())->toBe('high')
        // Routine keeps the previous fixed default, so nothing that exists today changes behaviour.
        ->and(criticalEquipment(Equipment::ROUTINE)->defaultWorkOrderPriority())->toBe('medium');
});

it('starts a fault raised from a tenant request on critical equipment at urgent', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    makeLease($unit, $tenant);
    $equipment = criticalEquipment(Equipment::CRITICAL, $asset->id);

    /** @var TenantRequest $request */
    $request = makeTenantRequest([
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'title' => 'No cooling',
        'description' => 'Chiller down',
        'category' => 'hvac',
    ]);

    $order = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($request, [
        'execution_type' => 'internal',
        'equipment_id' => $equipment->id,
    ]);

    expect($order->priority)->toBe('urgent');
});

it('takes the higher of the tenant\'s view and the machine\'s, never the lower', function () {
    // A tenant reporting "low" on a critical chiller is reporting their disruption, not the
    // business's exposure. Taking the higher of the two can only raise a job, never quietly lower
    // one — and the operator can still state a priority outright, which is the next test.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    makeLease($unit, $tenant);
    $equipment = criticalEquipment(Equipment::CRITICAL, $asset->id);

    $request = makeTenantRequest([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'title' => 'Warm air', 'description' => 'Not cooling well', 'category' => 'hvac',
        'priority' => 'low',
    ]);

    $order = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($request, [
        'execution_type' => 'internal',
        'equipment_id' => $equipment->id,
    ]);

    expect($order->priority)->toBe('urgent');
});

it('never overrides a priority the operator stated', function () {
    // The line between helpful and untrustworthy. Whoever raises the job can see the machine and the
    // system cannot; a system that quietly disagrees with an explicit choice teaches people to
    // distrust the field.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    makeLease($unit, $tenant);
    $equipment = criticalEquipment(Equipment::CRITICAL, $asset->id);

    $request = makeTenantRequest([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'title' => 'Scratched panel', 'description' => 'Cosmetic', 'category' => 'hvac',
    ]);

    $order = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($request, [
        'execution_type' => 'internal',
        'equipment_id' => $equipment->id,
        'priority' => 'low',
    ]);

    expect($order->priority)->toBe('low');
});

it('falls back to medium for a job with no equipment attached', function () {
    // The control that keeps this change additive: a work order naming no machine behaves exactly
    // as it did before.
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $tenant = makeTenant();
    makeLease($unit, $tenant);

    $request = makeTenantRequest([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'title' => 'Corridor light', 'description' => 'Flickering', 'category' => 'electrical',
    ]);

    $order = app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($request, [
        'execution_type' => 'internal',
    ]);

    expect($order->priority)->toBe('medium');
});

it('carries criticality into the preventive round too', function () {
    // A routine round on a critical machine is still a critical machine. The generator set no
    // priority at all, so every plan produced `medium` whatever it was servicing.
    $equipment = criticalEquipment(Equipment::CRITICAL);

    ServicePlan::create([
        'asset_id' => $equipment->asset_id,
        'equipment_id' => $equipment->id,
        'title' => 'Quarterly service',
        'category' => 'hvac',
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => now()->subDay()->toDateString(),
        'is_active' => true,
    ]);

    app(GeneratePreventiveWorkOrdersService::class)->run();

    expect(FacilityWorkOrder::where('equipment_id', $equipment->id)->value('priority'))->toBe('urgent');
});

it('falls back to routine rather than guessing critical', function () {
    // An unknown or blank value lands on the SAFE end of the scale. Guessing `critical` would page
    // someone at 2am for a hand dryer, and that is how an alert channel stops being read.
    $asset = makeAsset();

    $equipment = Equipment::create([
        'asset_id' => $asset->id,
        'code' => 'EQ-'.uniqid(),
        'name_en' => 'Hand dryer',
        'name_ar' => 'مجفف أيدٍ',
        'category' => 'other',
        'criticality' => 'nonsense',
    ]);

    expect($equipment->fresh()->criticality)->toBe(Equipment::ROUTINE)
        ->and($equipment->defaultWorkOrderPriority())->toBe('medium');
});
