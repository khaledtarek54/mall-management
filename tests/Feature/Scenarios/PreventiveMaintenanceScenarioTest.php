<?php

use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Services\FacilityWorkLogPdfService;
use App\Services\GeneratePreventiveWorkOrdersService;

/**
 * End-to-end preventive maintenance: a recurring plan raises work orders (catching up
 * missed cycles one per run), an engineer completes the checklist, and the facility
 * work-log report reflects the work.
 */
beforeEach(function () {
    $this->gen = app(GeneratePreventiveWorkOrdersService::class);
});

it('catches up missed cycles one work order per run, each with the checklist', function () {
    $asset = makeAsset();
    $plan = MaintenancePlan::create([
        'asset_id' => $asset->id, 'title' => 'Generator monthly run', 'category' => 'safety',
        'frequency_unit' => 'months', 'frequency_value' => 1,
        'checklist' => ['Oil level', 'Load test', 'Battery'],
        'next_due_date' => '2026-05-01', 'is_active' => true,
    ]);

    // Three cycles are due as of 2026-07-04 (May, Jun, Jul); each run raises one.
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(0); // caught up — next due is 2026-08-01

    $orders = MaintenanceWorkOrder::where('maintenance_plan_id', $plan->id)->orderBy('scheduled_for')->get();
    expect($orders)->toHaveCount(3);
    expect($orders->pluck('scheduled_for')->map->toDateString()->all())->toBe(['2026-05-01', '2026-06-01', '2026-07-01']);
    expect($orders->first()->items()->count())->toBe(3); // checklist copied
    expect($plan->fresh()->next_due_date->toDateString())->toBe('2026-08-01');
});

it('an engineer completes the checklist + work order, and the work log reflects it', function () {
    $asset = makeAsset();
    MaintenancePlan::create([
        'asset_id' => $asset->id, 'title' => 'AC filter', 'category' => 'hvac',
        'frequency_unit' => 'months', 'frequency_value' => 1, 'checklist' => ['Replace filter', 'Clean coils'],
        'next_due_date' => '2026-07-01', 'is_active' => true,
    ]);
    $this->gen->run('2026-07-04');

    $order = MaintenanceWorkOrder::first();
    // Engineer ticks every item, then marks the order done.
    $order->items()->update(['is_done' => true, 'done_at' => now()]);
    $order->update(['status' => 'done', 'completed_at' => now()]);

    expect($order->fresh()->items()->where('is_done', true)->count())->toBe(2);

    // The facility work-log report includes the completed order for the property + range.
    $log = app(FacilityWorkLogPdfService::class)->orders('2026-07-01', '2026-07-31', [$asset->id]);
    expect($log)->toHaveCount(1);
    expect($log->first()->status)->toBe('done');

    // Rendering the PDF works too.
    expect(substr(app(FacilityWorkLogPdfService::class)->build('2026-07-01', '2026-07-31', [$asset->id], 'Mall'), 0, 4))->toBe('%PDF');
});

it('does not regenerate for a plan that has been deactivated mid-cycle', function () {
    $asset = makeAsset();
    $plan = MaintenancePlan::create([
        'asset_id' => $asset->id, 'title' => 'Lift', 'category' => 'safety',
        'frequency_unit' => 'weeks', 'frequency_value' => 2, 'checklist' => ['Inspect'],
        'next_due_date' => '2026-06-01', 'is_active' => true,
    ]);
    $this->gen->run('2026-07-04'); // raises one, advances
    $plan->update(['is_active' => false]);

    // Even though still "behind", an inactive plan raises nothing further.
    expect($this->gen->run('2026-07-04'))->toBe(0);
});
