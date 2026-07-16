<?php

use App\Models\Equipment;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Services\GeneratePreventiveWorkOrdersService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2c — preventive maintenance anchored to the machine (FR-PPM-01/02/03):
 * Routine vs Fixed, yearly scheduling, and equipment carried from plan to work order.
 */
beforeEach(function () {
    $this->gen = app(GeneratePreventiveWorkOrdersService::class);
    $this->asset = makeAsset(['code' => 'PEQ']);
    $this->chiller = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'CH-01',
        'name_en' => 'Chiller', 'name_ar' => 'مبرد', 'category' => 'hvac',
    ]);
});

/** next_due_date is date-cast, so compare its string form rather than a Carbon. */
function dueDate(MaintenancePlan $plan): string
{
    return $plan->next_due_date instanceof \DateTimeInterface
        ? $plan->next_due_date->format('Y-m-d')
        : (string) $plan->next_due_date;
}

function planFor(array $attrs = []): MaintenancePlan
{
    return MaintenancePlan::create(array_merge([
        'asset_id' => test()->asset->id,
        'title' => 'Chiller service',
        'category' => 'hvac',
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => '2026-07-01',
        'is_active' => true,
    ], $attrs));
}

/* ---- FR-PPM-02: yearly, and the trap it closed ------------------------- */

it('advances a yearly plan by a year, not a month', function () {
    // The bug this closes: advanceDue() ended in `default => addMonths()`, so an
    // unrecognised 'years' silently fired MONTHLY — twelve inspections a year that nobody
    // ordered, on a plan whose form said "every 1 year".
    $plan = planFor(['frequency_unit' => 'years', 'frequency_value' => 1, 'next_due_date' => '2026-07-01']);

    $plan->advanceDue();

    // A month would have given 2026-08-01 — the exact wrong answer the old default arm gave.
    expect(dueDate($plan))->toBe('2027-07-01');
});

it('still advances days, weeks and months correctly', function () {
    $advanced = function (string $unit, int $value): string {
        $plan = planFor(['frequency_unit' => $unit, 'frequency_value' => $value]);
        $plan->advanceDue();

        return dueDate($plan);
    };

    expect($advanced('days', 10))->toBe('2026-07-11');
    expect($advanced('weeks', 2))->toBe('2026-07-15');
    expect($advanced('months', 3))->toBe('2026-10-01');
});

it('refuses to save an unknown frequency unit', function () {
    expect(fn () => planFor(['frequency_unit' => 'fortnights']))
        ->toThrow(InvalidArgumentException::class, 'Unknown frequency_unit');
});

it('throws rather than silently guessing when the stored unit is corrupt', function () {
    // The model guard makes this unreachable from the app, so this is the backstop for a
    // direct DB edit or an import. Loud beats a plausible wrong answer.
    $plan = planFor(['frequency_unit' => 'years']);
    DB::table('maintenance_plans')->where('id', $plan->id)->update(['frequency_unit' => 'decades']);

    expect(fn () => $plan->fresh()->advanceDue())
        ->toThrow(InvalidArgumentException::class, 'unknown frequency_unit');
});

it('does not let one corrupt plan stop every other property getting work orders', function () {
    // advanceDue() throwing is only safe because the scan contains failures per plan —
    // the SLA scan's convention. Without it, one bad row halts the nightly run silently.
    $good = planFor(['title' => 'Good plan']);
    $bad = planFor(['title' => 'Corrupt plan']);
    DB::table('maintenance_plans')->where('id', $bad->id)->update(['frequency_unit' => 'decades']);

    $raised = $this->gen->run('2026-07-04');

    expect($raised)->toBe(1);
    expect($this->gen->failures)->toHaveKey($bad->id);
    expect(MaintenanceWorkOrder::where('maintenance_plan_id', $good->id)->count())->toBe(1);
    expect(MaintenanceWorkOrder::where('maintenance_plan_id', $bad->id)->count())->toBe(0);
});

/* ---- FR-PPM-01: Routine vs Fixed --------------------------------------- */

it('defaults a plan to routine maintenance', function () {
    expect(planFor()->maintenance_type)->toBe(MaintenancePlan::MAINTENANCE_TYPE_ROUTINE);
});

it('requires fixed maintenance to name its machine', function () {
    // The FRD defines Fixed as "per asset" — a fixed plan with no equipment is meaningless.
    expect(fn () => planFor(['maintenance_type' => 'fixed', 'equipment_id' => null]))
        ->toThrow(InvalidArgumentException::class, 'must target a specific piece of equipment');
});

it('accepts fixed maintenance against a machine', function () {
    $plan = planFor(['maintenance_type' => 'fixed', 'equipment_id' => $this->chiller->id]);

    expect($plan->maintenance_type)->toBe('fixed');
    expect($plan->equipment->code)->toBe('CH-01');
});

it('rejects an unknown maintenance type', function () {
    expect(fn () => planFor(['maintenance_type' => 'occasional']))
        ->toThrow(InvalidArgumentException::class, 'Unknown maintenance_type');
});

/* ---- property isolation of the machine link ---------------------------- */

it('refuses a plan targeting equipment in another property', function () {
    // Else the plan would raise work orders against another mall's machine.
    $other = makeAsset(['code' => 'PEQ2']);
    $foreign = Equipment::create([
        'asset_id' => $other->id, 'code' => 'CH-99', 'name_en' => 'Foreign', 'name_ar' => 'أجنبي',
    ]);

    expect(fn () => planFor(['equipment_id' => $foreign->id]))
        ->toThrow(InvalidArgumentException::class, 'another property');
});

it('refuses a plan targeting equipment that does not exist', function () {
    expect(fn () => planFor(['equipment_id' => 99999]))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

/* ---- a plan must never silently stop generating ------------------------ */

it('keeps generating after its machine is hard-deleted', function () {
    // nullOnDelete fires at the DB, behind Eloquent's back, so a `fixed` plan is left with
    // no machine. When the guards ran on EVERY save, the scan's own $plan->save() then
    // threw and the plan raised ZERO work orders from then on — a fire pump that silently
    // stops being inspected. Guards are write-time validation, so they only run on change.
    $plan = planFor(['maintenance_type' => 'fixed', 'equipment_id' => $this->chiller->id, 'checklist' => ['Check']]);
    $this->chiller->forceDelete();

    expect($plan->fresh()->equipment_id)->toBeNull();

    $raised = $this->gen->run('2026-07-04');

    expect($raised)->toBe(1);
    expect($this->gen->failures)->toBe([]);
});

it('keeps generating for a routine plan whose linked machine was deleted', function () {
    $plan = planFor(['equipment_id' => $this->chiller->id, 'checklist' => ['Check']]);
    $this->chiller->forceDelete();

    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->failures)->toBe([]);
});

it('refuses to move a machine that plans or work orders reference', function () {
    // The other half of the same problem: a plan's machine must live in the plan's own
    // property, so letting the machine walk away would leave the plan permanently invalid
    // and make the work-order history claim a machine that was never in that mall.
    $other = makeAsset(['code' => 'PEQ3']);
    planFor(['equipment_id' => $this->chiller->id]);

    expect(fn () => $this->chiller->update(['asset_id' => $other->id]))
        ->toThrow(InvalidArgumentException::class, 'referenced by maintenance plans or work orders');

    expect((int) $this->chiller->fresh()->asset_id)->toBe($this->asset->id);
});

it('refuses to move a machine that has work-order history', function () {
    $other = makeAsset(['code' => 'PEQ4']);
    planFor(['equipment_id' => $this->chiller->id, 'checklist' => ['Check']]);
    $this->gen->run('2026-07-04');
    MaintenancePlan::query()->forceDelete(); // history outlives the plan

    expect(fn () => $this->chiller->fresh()->update(['asset_id' => $other->id]))
        ->toThrow(InvalidArgumentException::class, 'referenced by maintenance plans or work orders');
});

it('still allows moving an unreferenced machine', function () {
    // The guard must bite only on references — an unused machine is free to be re-filed.
    $other = makeAsset(['code' => 'PEQ5']);
    $spare = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'SPARE-1', 'name_en' => 'Spare', 'name_ar' => 'احتياطي',
    ]);

    $spare->update(['asset_id' => $other->id]);

    expect((int) $spare->fresh()->asset_id)->toBe($other->id);
});

/* ---- FR-PPM-03: the machine reaches the work order --------------------- */

it('carries the plan\'s equipment onto every work order it raises', function () {
    planFor(['maintenance_type' => 'fixed', 'equipment_id' => $this->chiller->id, 'checklist' => ['Check pressure']]);

    $this->gen->run('2026-07-04');
    $order = MaintenanceWorkOrder::first();

    expect($order->equipment_id)->toBe($this->chiller->id);
    expect($order->equipment->code)->toBe('CH-01');
});

it('keeps saying which machine the job was against after the plan is gone', function () {
    // equipment_id lives on the order, not read through the plan: maintenance_plan_id is
    // nullOnDelete, and ad-hoc orders have no plan at all.
    $plan = planFor(['equipment_id' => $this->chiller->id]);
    $this->gen->run('2026-07-04');
    $order = MaintenanceWorkOrder::first();

    $plan->forceDelete();

    expect($order->fresh()->maintenance_plan_id)->toBeNull();
    expect($order->fresh()->equipment->code)->toBe('CH-01');
});

it('leaves equipment null on a property-wide plan', function () {
    // Routine plans stay valid without a machine — the register is additive.
    planFor(['checklist' => ['Sweep']]);
    $this->gen->run('2026-07-04');

    expect(MaintenanceWorkOrder::first()->equipment_id)->toBeNull();
});
