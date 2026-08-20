<?php

/*
|--------------------------------------------------------------------------
| A preventive programme nobody measures is a list of intentions (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 3, and the cheapest real gap in the module: `scheduled_for` (which the generator
| copies from the plan's `next_due_date`, so it IS the due date) and `completed_at` have both been
| stored since module 26 shipped, and **nothing ever compared them**. The quarterly generator
| load-bank test could generate in March, sit open, and be closed in July with a tick — complete,
| and four months late, with no measure able to say so. Maximo §6.
|
| Measured STRICTLY, with no tolerance window — a stated deviation from Maximo, which allows one. A
| single global tolerance would be wrong in both directions here (three days is most of a weekly
| cleaning round and nothing at all on an annual overhaul), and a percentage of the cycle would be
| a policy nobody has agreed to. Strict never OVERSTATES compliance, which is the safe direction.
*/

use App\Models\FacilityWorkOrder;
use App\Models\ServicePlan;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->plan = ServicePlan::create([
        'asset_id' => $this->asset->id, 'title' => 'Quarterly load-bank test',
        'trade_id' => tradeId('generator'), 'frequency_unit' => 'months', 'frequency_value' => 3,
        'next_due_date' => '2026-06-01', 'is_active' => true,
    ]);
});

function cycle($ctx, string $due, ?string $completedAt = null, array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'service_plan_id' => $ctx->plan->id,
        'asset_id' => $ctx->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_PPM,
        'title' => 'Load-bank test',
        'trade_id' => $ctx->plan->trade_id,
        'status' => $completedAt === null ? 'open' : 'done',
        'priority' => 'medium',
        'scheduled_for' => $due,
        'completed_at' => $completedAt,
    ], $attrs));
}

/* ---- the four states --------------------------------------------------- */

/**
 * **Completing at 16:00 on the day it was due is ON TIME.** A datetime compared against a date
 * column calls every afternoon completion late, which would report a compliant programme as
 * failing and destroy trust in the measure on day one.
 */
it('counts work finished on the due day as on time, whatever the hour', function () {
    $wo = cycle($this, '2026-03-01', '2026-03-01 16:00');

    expect($wo->pmComplianceState())->toBe(FacilityWorkOrder::PM_ON_TIME)
        ->and(FacilityWorkOrder::query()->pmOnTime()->pluck('id')->all())->toBe([$wo->id]);
});

it('counts work finished the next morning as late', function () {
    $wo = cycle($this, '2026-03-01', '2026-03-02 09:00');

    expect($wo->pmComplianceState())->toBe(FacilityWorkOrder::PM_LATE)
        ->and(FacilityWorkOrder::query()->pmLate()->pluck('id')->all())->toBe([$wo->id]);
});

/** The finding: planned work whose day has passed and which nobody has done. */
it('flags planned work nobody did once its day has passed', function () {
    $wo = cycle($this, '2026-03-01');

    expect($wo->pmComplianceState(CarbonImmutable::parse('2026-03-05')))->toBe(FacilityWorkOrder::PM_OVERDUE)
        ->and(FacilityWorkOrder::query()->pmOverdue(CarbonImmutable::parse('2026-03-05'))->pluck('id')->all())
        ->toBe([$wo->id]);
});

/** A cycle still inside its window is not yet anything — and must not read as a failure. */
it('does not treat work still inside its window as a failure', function () {
    $wo = cycle($this, '2026-03-01');

    expect($wo->pmComplianceState(CarbonImmutable::parse('2026-02-20')))->toBe(FacilityWorkOrder::PM_DUE)
        ->and(FacilityWorkOrder::query()->pmOverdue(CarbonImmutable::parse('2026-02-20'))->count())->toBe(0);
});

/* ---- where the question does not apply ---------------------------------- */

it('asks nothing of a corrective job, which answers to its SLA instead', function () {
    $wo = cycle($this, '2026-03-01', null, [
        'work_order_type' => FacilityWorkOrder::TYPE_CM, 'execution_type' => 'internal',
        'description' => 'Reported fault', 'service_plan_id' => null,
    ]);

    expect($wo->pmComplianceState(CarbonImmutable::parse('2026-06-01')))->toBeNull()
        ->and(FacilityWorkOrder::query()->pmOverdue(CarbonImmutable::parse('2026-06-01'))->count())->toBe(0);
});

it('asks nothing of a cancelled cycle, which was never going to happen', function () {
    $wo = cycle($this, '2026-03-01', null, ['status' => 'cancelled']);

    expect($wo->pmComplianceState(CarbonImmutable::parse('2026-06-01')))->toBeNull()
        ->and(FacilityWorkOrder::query()->pmOverdue(CarbonImmutable::parse('2026-06-01'))->count())->toBe(0);
});

/* ---- the plan's own figure ---------------------------------------------- */

it('rates a plan by the share of its settled cycles done on time', function () {
    cycle($this, '2025-06-01', '2025-06-01 10:00');   // on time
    cycle($this, '2025-09-01', '2025-09-01 10:00');   // on time
    cycle($this, '2025-12-01', '2025-12-04 10:00');   // late
    cycle($this, '2026-03-01');                       // never done → overdue

    expect($this->plan->complianceRate())->toBe(50.0);   // 2 of 4 settled
});

/**
 * A cycle still inside its window is EXCLUDED. Counting it as a failure would make every plan look
 * bad the day after it generated; counting it as a success would be a claim nobody can make yet.
 */
it('excludes a cycle that has not settled from the rate', function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-01'));

    cycle($this, '2025-12-01', '2025-12-01 10:00');   // on time — settled
    cycle($this, '2026-03-01');                        // still in its window

    expect($this->plan->complianceRate())->toBe(100.0);

    $this->travelBack();
});

/** A new plan has no compliance. 0% and 100% would both be inventions. */
it('reports no rate at all for a plan whose cycles have not settled', function () {
    expect($this->plan->complianceRate())->toBeNull();
});

/**
 * **The drift guard.** The per-plan method and the list's count-based one must always agree, or a
 * table would report a different compliance from the record it links to.
 */
it('gives a list the same figure as the record', function () {
    cycle($this, '2025-06-01', '2025-06-01 10:00');
    cycle($this, '2025-12-01', '2025-12-04 10:00');
    cycle($this, '2026-03-01');

    $listed = ServicePlan::withComplianceCounts()->findOrFail($this->plan->id);

    expect($listed->complianceRateFromCounts())->toBe($this->plan->complianceRate())
        ->and($listed->complianceRateFromCounts())->toBe(33.3);
});
