<?php

/*
|--------------------------------------------------------------------------
| The producer had no trigger (2026-08-18)
|--------------------------------------------------------------------------
| `facility:generate-preventive` raised preventive work orders nightly, and nothing in the panel
| could run it. The service-plans screen already told an operator that a plan was OVERDUE, and even
| showed `last_generation_error` when generation was FAILING — and offered nothing to do about
| either. The remedies were waiting for cron or opening a shell.
|
| Found by sweeping the module for reachability: every other facility service was reachable from a
| screen, this one was reachable only from a command. CAM's pool and a lease's billing both put the
| same act behind a button.
|
| `runFor()` routes through the same private path the sweep uses — the trigger type decides which —
| so a manual generation cannot take a different route from the automatic one and raise a different
| work order.
*/

use App\Models\FacilityWorkOrder;
use App\Models\ServicePlan;
use App\Services\GeneratePreventiveWorkOrdersService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->svc = app(GeneratePreventiveWorkOrdersService::class);
    CarbonImmutable::setTestNow('2026-08-18');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function duePlan($ctx, array $overrides = []): ServicePlan
{
    return ServicePlan::create(array_merge([
        'asset_id' => $ctx->asset->id,
        'title' => 'Quarterly chiller service',
        'plan_type' => 'routine',
        'trigger_type' => ServicePlan::TRIGGER_TIME,
        'frequency_unit' => 'months',
        'frequency_value' => 3,
        'next_due_date' => '2026-08-01',
        'is_active' => true,
    ], $overrides));
}

it('raises the work order for ONE plan on demand', function () {
    $plan = duePlan($this);

    expect($this->svc->runFor($plan))->toBe(1)
        ->and(FacilityWorkOrder::count())->toBe(1);
});

it('rolls the plan forward, so pressing it twice does not raise two', function () {
    $plan = duePlan($this);

    $this->svc->runFor($plan);
    $second = $this->svc->runFor($plan->fresh());

    // The manual path must be as idempotent as the nightly one: an operator who clicks twice, or
    // clicks after cron has already run, must not double the tenant's disruption.
    expect($second)->toBe(0)
        ->and(FacilityWorkOrder::count())->toBe(1);
});

it('leaves a plan that is not due alone', function () {
    $plan = duePlan($this, ['next_due_date' => '2026-12-01']);

    expect($this->svc->runFor($plan))->toBe(0)
        ->and(FacilityWorkOrder::count())->toBe(0);
});

it('never generates for an inactive plan', function () {
    $plan = duePlan($this, ['is_active' => false]);

    expect($this->svc->runFor($plan))->toBe(0);
});

it('takes the same route as the nightly sweep', function () {
    $manual = duePlan($this);
    $swept = duePlan($this, ['title' => 'Monthly lift service']);

    $this->svc->runFor($manual);
    $this->svc->run();

    // Two plans, two orders — one raised by hand and one by the sweep, and the manual one is not
    // raised twice. If the paths differed, this is where it would show.
    expect(FacilityWorkOrder::count())->toBe(2);
});
