<?php

use App\Models\ServicePlan;
use App\Models\FacilityWorkOrder;
use App\Services\GeneratePreventiveWorkOrdersService;

function makePlan(array $attrs = []): ServicePlan
{
    return ServicePlan::create(array_merge([
        'asset_id' => makeAsset()->id,
        'title' => 'HVAC filter check',
        'category' => 'hvac',
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'checklist' => ['Check filter', 'Check coolant'],
        'next_due_date' => now()->subDay()->toDateString(), // due
        'is_active' => true,
    ], $attrs));
}

beforeEach(fn () => $this->svc = app(GeneratePreventiveWorkOrdersService::class));

it('raises a work order with the checklist for a due plan and advances next_due', function () {
    $plan = makePlan(['next_due_date' => '2026-06-01', 'frequency_unit' => 'months', 'frequency_value' => 1]);

    expect($this->svc->run('2026-07-04'))->toBe(1);

    $order = FacilityWorkOrder::where('service_plan_id', $plan->id)->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('open');
    expect((int) $order->asset_id)->toBe($plan->asset_id);
    expect($order->items()->pluck('label')->all())->toBe(['Check filter', 'Check coolant']);
    expect($order->scheduled_for->toDateString())->toBe('2026-06-01');

    // next_due advanced one month.
    expect($plan->fresh()->next_due_date->toDateString())->toBe('2026-07-01');
});

it('notifies operations when a scheduled work order is raised (FRD MNT-2)', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    \Illuminate\Support\Facades\Notification::fake();
    $asset = makeAsset();
    $ops = makeUser('operations', [$asset->id]);
    makePlan(['asset_id' => $asset->id, 'next_due_date' => now()->subDay()->toDateString()]);

    expect($this->svc->run())->toBe(1);

    // Was raised completely silently before — the scheduled service now pings the doers.
    \Illuminate\Support\Facades\Notification::assertSentTo($ops, \App\Notifications\WorkOrderRaisedNotification::class);
});

it('is idempotent — a second run does not double-generate', function () {
    $plan = makePlan();
    $this->svc->run();
    $this->svc->run();

    expect(FacilityWorkOrder::where('service_plan_id', $plan->id)->count())->toBe(1);
});

it('skips a plan that is not yet due', function () {
    makePlan(['next_due_date' => now()->addMonth()->toDateString()]);

    expect($this->svc->run())->toBe(0);
    expect(FacilityWorkOrder::count())->toBe(0);
});

it('skips an inactive plan', function () {
    makePlan(['is_active' => false, 'next_due_date' => now()->subMonth()->toDateString()]);

    expect($this->svc->run())->toBe(0);
});

it('advances by the plan frequency (weeks)', function () {
    $plan = makePlan(['next_due_date' => '2026-07-01', 'frequency_unit' => 'weeks', 'frequency_value' => 2]);
    $this->svc->run('2026-07-04');

    expect($plan->fresh()->next_due_date->toDateString())->toBe('2026-07-15'); // +2 weeks
});

it('does not copy blank checklist entries', function () {
    $plan = makePlan(['checklist' => ['Real item', '', '  ']]);
    $this->svc->run();

    $order = FacilityWorkOrder::where('service_plan_id', $plan->id)->first();
    expect($order->items()->count())->toBe(1);
});

it('runs the scheduled command', function () {
    makePlan();
    $this->artisan('facility:generate-preventive')->assertExitCode(0);
    expect(FacilityWorkOrder::count())->toBe(1);
});

it('mints distinct references for work orders in the same asset/month', function () {
    $asset = makeAsset();
    $a = FacilityWorkOrder::create(['asset_id' => $asset->id, 'title' => 'A', 'scheduled_for' => now()->toDateString()]);
    $b = FacilityWorkOrder::create(['asset_id' => $asset->id, 'title' => 'B', 'scheduled_for' => now()->toDateString()]);

    expect($a->reference)->not->toBeNull();
    expect($a->reference)->not->toBe($b->reference);
});
