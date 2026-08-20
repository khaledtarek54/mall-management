<?php

/*
|--------------------------------------------------------------------------
| 42 extinguishers, and no way to say which three failed (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 7, the last in the order. Maximo §6 (routes) and §3 (job-plan estimates).
|
| Scenario S5: a quarterly round over 42 fire extinguishers, three of them fail. A `ServicePlan`
| targeted ONE machine with a free-text checklist, so an operator either created 42 plans or one
| plan whose checklist had 42 lines — and "Extinguisher 2-17 — fail" is a STRING, so no report could
| say which devices were overdue and 2-17's own history stayed empty however often it failed.
|
| And §3: without a planned cost on the plan, every job the preventive programme raises is
| un-estimated for ever, so step 2's `costVariance()` is null across the whole programme.
*/

use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Models\ServicePlan;
use App\Models\ServicePlanStop;
use App\Models\Trade;
use App\Services\GeneratePreventiveWorkOrdersService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->trade = Trade::where('code', 'fire-safety')->firstOrFail();
    $this->trade->update(['standard_hourly_rate' => 250]);

    $this->plan = ServicePlan::create([
        'asset_id' => $this->asset->id, 'title' => 'Quarterly extinguisher round',
        'trade_id' => $this->trade->id, 'frequency_unit' => 'months', 'frequency_value' => 3,
        'next_due_date' => '2026-03-01', 'is_active' => true,
    ]);
});

function extinguisher($ctx, string $code): Equipment
{
    return Equipment::create([
        'asset_id' => $ctx->asset->id, 'code' => $code, 'name_en' => 'Extinguisher',
        'name_ar' => 'طفاية حريق', 'trade_id' => $ctx->trade->id, 'is_active' => true,
    ]);
}

function walkTheRound($ctx, array $codes): FacilityWorkOrder
{
    foreach ($codes as $i => $code) {
        ServicePlanStop::create([
            'service_plan_id' => $ctx->plan->id,
            'equipment_id' => extinguisher($ctx, $code)->id,
            'sort_order' => ($i + 1) * 10,
        ]);
    }

    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');

    return $ctx->plan->workOrders()->latest('id')->firstOrFail();
}

/* ---- routes ------------------------------------------------------------- */

it('raises ONE job for the whole round, not one per machine', function () {
    walkTheRound($this, ['EXT-2-01', 'EXT-2-02', 'EXT-2-03']);

    // The failure a route exists to prevent is 42 work orders for one walk.
    expect($this->plan->workOrders()->count())->toBe(1);
});

/**
 * **Scenario S5.** A failed line names the DEVICE. Before this it was a string, so 2-17's own
 * history stayed empty however often it failed.
 */
it('attributes a failure to the machine, not to a line of text', function () {
    $order = walkTheRound($this, ['EXT-2-01', 'EXT-2-02', 'EXT-2-03']);

    $failed = $order->items()->whereHas('equipment', fn ($q) => $q->where('code', 'EXT-2-02'))->sole();
    $failed->update(['result' => FacilityWorkOrderItem::RESULT_FAIL, 'marked_at' => now()]);

    $failures = $order->items()->where('result', FacilityWorkOrderItem::RESULT_FAIL)->with('equipment')->get();

    expect($failures)->toHaveCount(1)
        ->and($failures->first()->equipment->code)->toBe('EXT-2-02')
        // …and it is queryable AS a device, which is the whole point.
        ->and(FacilityWorkOrderItem::query()
            ->where('result', FacilityWorkOrderItem::RESULT_FAIL)
            ->whereNotNull('equipment_id')
            ->count())->toBe(1);
});

it('gives every stop its own line, in the order the round is walked', function () {
    $order = walkTheRound($this, ['EXT-2-01', 'EXT-2-02', 'EXT-2-03']);

    expect($order->items()->whereNotNull('equipment_id')->count())->toBe(3)
        ->and($order->items()->with('equipment')->get()->pluck('equipment.code')->all())
        ->toBe(['EXT-2-01', 'EXT-2-02', 'EXT-2-03']);
});

/** The line reads as the machine, once. `Equipment::label()` is already "CODE — Name". */
it('names the machine on the line without repeating its code', function () {
    $order = walkTheRound($this, ['EXT-2-01']);

    expect($order->items()->sole()->label)->toBe('EXT-2-01 — Extinguisher');
});

/** A plan with no stops is an ordinary plan and behaves exactly as it always did. */
it('leaves an ordinary single-target plan alone', function () {
    $this->plan->update(['checklist' => ['Check the gauge', 'Check the seal']]);

    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');
    $order = $this->plan->workOrders()->sole();

    expect($this->plan->isRoute())->toBeFalse()
        ->and($order->items()->count())->toBe(2)
        ->and($order->items()->whereNotNull('equipment_id')->count())->toBe(0);
});

/* ---- planned cost ------------------------------------------------------- */

/**
 * **Hours on the plan, money at generation.** Storing a labour COST on the plan would freeze a rate
 * for its whole life — exactly what `charges.vat_rate` did wrong before 2026-08-12.
 */
it('prices the plan\'s hours at the trade rate on the day the job is raised', function () {
    $this->plan->update(['est_labour_hours' => 4, 'est_material_cost' => 300]);

    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');
    $order = $this->plan->workOrders()->sole();

    expect((float) $order->est_labour_hours)->toBe(4.0)
        ->and((float) $order->est_labour_cost)->toBe(1000.0)      // 4 × 250
        ->and((float) $order->est_material_cost)->toBe(300.0)
        ->and((float) $order->est_total_cost)->toBe(1300.0);
});

it('re-prices later jobs when the trade rate changes, and never re-prices earlier ones', function () {
    $this->plan->update(['est_labour_hours' => 4]);
    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');
    $first = $this->plan->workOrders()->sole();

    $this->trade->update(['standard_hourly_rate' => 400]);
    $this->plan->update(['next_due_date' => '2026-06-01']);
    app(GeneratePreventiveWorkOrdersService::class)->run('2026-06-01');

    $second = $this->plan->workOrders()->latest('id')->first();

    expect((float) $first->fresh()->est_labour_cost)->toBe(1000.0)   // untouched
        ->and((float) $second->est_labour_cost)->toBe(1600.0);       // the new rate
});

/** A trade with no rate produces hours and no labour cost — visibly missing, never invented. */
it('leaves the labour cost at zero when the trade has no rate', function () {
    $this->trade->update(['standard_hourly_rate' => null]);
    $this->plan->update(['est_labour_hours' => 4]);

    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');
    $order = $this->plan->workOrders()->sole();

    expect((float) $order->est_labour_hours)->toBe(4.0)
        ->and((float) $order->est_labour_cost)->toBe(0.0);
});

/**
 * An un-estimated plan must leave its jobs un-estimated — not estimated at zero. "Nobody planned
 * this" and "this was expected to be free" are different claims, and the variance depends on it.
 */
it('leaves a job un-estimated when its plan carries no estimate', function () {
    app(GeneratePreventiveWorkOrdersService::class)->run('2026-03-01');
    $order = $this->plan->workOrders()->sole();

    expect($order->est_labour_hours)->toBeNull()
        ->and($order->est_total_cost)->toBeNull()
        ->and($order->costVariance())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Review pass (2026-08-20)
|--------------------------------------------------------------------------
*/

/**
 * **A decommissioned machine drops off the round, and the round still runs.**
 *
 * Found by review: a retired extinguisher kept appearing on the sheet, so an engineer was sent to
 * inspect a device that is not there — and a `fail` recorded against it would be a fact about
 * nothing. Skipped rather than refused, because one dead stop out of 42 must not stop the other 41
 * being inspected.
 */
it('drops a retired machine from the round without stopping the round', function () {
    $order = walkTheRound($this, ['EXT-2-01', 'EXT-2-02', 'EXT-2-03']);
    expect($order->items()->whereNotNull('equipment_id')->count())->toBe(3);

    Equipment::where('code', 'EXT-2-02')->update(['is_active' => false]);

    $this->plan->update(['next_due_date' => '2026-06-01']);
    app(GeneratePreventiveWorkOrdersService::class)->run('2026-06-01');
    $next = $this->plan->workOrders()->latest('id')->first();

    expect($next->items()->whereNotNull('equipment_id')->count())->toBe(2)
        ->and($next->items()->with('equipment')->get()->pluck('equipment.code')->all())
        ->toBe(['EXT-2-01', 'EXT-2-03']);
});

/**
 * The control: a round whose machines are ALL retired still raises its job. The plan is the thing
 * that should be retired at that point, and producing an empty round is the visible prompt — where
 * silently generating nothing would look like the scan had failed.
 */
it('still raises the job when every machine on the round has been retired', function () {
    walkTheRound($this, ['EXT-2-01', 'EXT-2-02']);
    Equipment::where('asset_id', $this->asset->id)->update(['is_active' => false]);

    $this->plan->update(['next_due_date' => '2026-06-01']);
    app(GeneratePreventiveWorkOrdersService::class)->run('2026-06-01');

    expect($this->plan->workOrders()->count())->toBe(2)
        ->and($this->plan->workOrders()->latest('id')->first()->items()->count())->toBe(0);
});
