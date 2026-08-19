<?php

use App\Models\Area;
use App\Models\ServicePlan;
use App\Services\GeneratePreventiveWorkOrdersService;

/**
 * Planned work generalised from "maintenance of equipment" to ANY recurring facility service.
 *
 * FM splits work into HARD services (equipment-centric PPM) and SOFT services (cleaning, landscaping,
 * pest, waste, security — LOCATION-centric rounds). Both run through the same work-order engine and
 * differ only in target + cadence. Plans/work orders could not target an AREA, so soft services — which
 * this operator schedules in-house — could not be planned at all. These pin the two new capabilities.
 */
function plannedWorkPlan(array $overrides = []): ServicePlan
{
    $asset = makeAsset();

    return ServicePlan::create(array_merge([
        'asset_id' => $asset->id,
        'title' => 'Food court cleaning round',
        'trade_id' => tradeId('cleaning'),
        'frequency_unit' => 'days',
        'frequency_value' => 1,
        'next_due_date' => '2026-03-02', // a Monday
        'is_active' => true,
    ], $overrides));
}

it('generates a work order for an AREA-based round and carries the location onto it', function () {
    $plan = plannedWorkPlan();
    $area = Area::create(['asset_id' => $plan->asset_id, 'name' => 'Food Court', 'code' => 'FC', 'is_active' => true]);
    $plan->update(['area_id' => $area->id]);

    $created = app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-02');

    $order = $plan->workOrders()->sole();
    expect($created)->toBe(1)
        ->and($order->area_id)->toBe($area->id)      // the round knows WHERE it happens
        ->and($order->trade_id)->toBe(tradeId('cleaning'))
        ->and($order->equipment_id)->toBeNull();     // a soft-service round has no machine
});

it('restricts a round to its permitted weekdays (every Mon/Wed/Fri)', function () {
    // Mon=1, Wed=3, Fri=5. Daily cadence + a weekday filter = the classic cleaning round.
    $plan = plannedWorkPlan(['next_due_date' => '2026-03-02', 'days_of_week' => [1, 3, 5]]);

    $plan->advanceDue();   // from Mon 02 → +1 day = Tue 03 → rolls to Wed 04
    expect($plan->next_due_date->toDateString())->toBe('2026-03-04');

    $plan->advanceDue();   // Wed 04 → +1 = Thu 05 → rolls to Fri 06
    expect($plan->next_due_date->toDateString())->toBe('2026-03-06');

    $plan->advanceDue();   // Fri 06 → +1 = Sat 07 → rolls to Mon 09
    expect($plan->next_due_date->toDateString())->toBe('2026-03-09');
});

it('leaves cadence unchanged when no weekdays are set (existing plans behave exactly as before)', function () {
    $plan = plannedWorkPlan(['next_due_date' => '2026-03-02', 'frequency_unit' => 'months', 'frequency_value' => 1]);

    $plan->advanceDue();

    expect($plan->next_due_date->toDateString())->toBe('2026-04-02')
        ->and($plan->days_of_week)->toBeNull();
});

it('offers the soft-service disciplines, not just maintenance trades', function () {
    $categories = array_keys(__('admin.facility.categories'));

    expect($categories)->toContain('cleaning')->toContain('landscaping')
        ->toContain('pest_control')->toContain('waste')->toContain('security');
});
