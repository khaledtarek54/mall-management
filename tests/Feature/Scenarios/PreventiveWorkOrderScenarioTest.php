<?php

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Models\ServicePlan;
use App\Services\FacilityWorkLogPdfService;
use App\Services\FacilityWorkOrderService;
use App\Services\GeneratePreventiveWorkOrdersService;

/**
 * End-to-end preventive maintenance: a recurring plan raises work orders (catching up
 * missed cycles one per run), an engineer works the checklist, and the facility
 * work-log report reflects the work.
 */
beforeEach(function () {
    $this->gen = app(GeneratePreventiveWorkOrdersService::class);
    $this->wos = app(FacilityWorkOrderService::class);
});

it('catches up missed cycles one work order per run, each with the checklist', function () {
    $asset = makeAsset();
    $plan = ServicePlan::create([
        'asset_id' => $asset->id, 'title' => 'Generator monthly run', 'trade_id' => tradeId('safety'),
        'frequency_unit' => 'months', 'frequency_value' => 1,
        'checklist' => ['Oil level', 'Load test', 'Battery'],
        'next_due_date' => '2026-05-01', 'is_active' => true,
    ]);

    // Three cycles are due as of 2026-07-04 (May, Jun, Jul); each run raises one.
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(1);
    expect($this->gen->run('2026-07-04'))->toBe(0); // caught up — next due is 2026-08-01

    $orders = FacilityWorkOrder::where('service_plan_id', $plan->id)->orderBy('scheduled_for')->get();
    expect($orders)->toHaveCount(3);
    expect($orders->pluck('scheduled_for')->map->toDateString()->all())->toBe(['2026-05-01', '2026-06-01', '2026-07-01']);
    expect($orders->first()->items()->count())->toBe(3); // checklist copied
    expect($plan->fresh()->next_due_date->toDateString())->toBe('2026-08-01');
});

it('an engineer completes the checklist + work order, and the work log reflects it', function () {
    $asset = makeAsset();
    ServicePlan::create([
        'asset_id' => $asset->id, 'title' => 'AC filter', 'trade_id' => tradeId('hvac'),
        'frequency_unit' => 'months', 'frequency_value' => 1, 'checklist' => ['Replace filter', 'Clean coils'],
        'next_due_date' => '2026-07-01', 'is_active' => true,
    ]);
    $this->gen->run('2026-07-04');

    $engineer = makeUser('operations');
    $order = FacilityWorkOrder::first();

    // Drive the real path — the service, not raw model writes — so this exercises the
    // FR-PPM-07 gate rather than a shortcut around it.
    foreach ($order->items as $item) {
        $this->wos->markItem($item, FacilityWorkOrderItem::RESULT_PASS, $engineer->id);
    }
    $this->wos->transition($order, 'done', $engineer->id);

    expect($order->fresh()->items()->marked()->count())->toBe(2);
    expect($order->fresh()->completed_by_user_id)->toBe($engineer->id);

    // The facility work-log report includes the completed order for the property + range.
    $log = app(FacilityWorkLogPdfService::class)->orders('2026-07-01', '2026-07-31', [$asset->id]);
    expect($log)->toHaveCount(1);
    expect($log->first()->status)->toBe('done');

    // Rendering the PDF works too.
    expect(substr(app(FacilityWorkLogPdfService::class)->build('2026-07-01', '2026-07-31', [$asset->id], 'Mall'), 0, 4))->toBe('%PDF');
});

it('does not regenerate for a plan that has been deactivated mid-cycle', function () {
    $asset = makeAsset();
    $plan = ServicePlan::create([
        'asset_id' => $asset->id, 'title' => 'Lift', 'trade_id' => tradeId('safety'),
        'frequency_unit' => 'weeks', 'frequency_value' => 2, 'checklist' => ['Inspect'],
        'next_due_date' => '2026-06-01', 'is_active' => true,
    ]);
    $this->gen->run('2026-07-04'); // raises one, advances
    $plan->update(['is_active' => false]);

    // Even though still "behind", an inactive plan raises nothing further.
    expect($this->gen->run('2026-07-04'))->toBe(0);
});
