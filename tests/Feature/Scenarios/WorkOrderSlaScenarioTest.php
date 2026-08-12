<?php

use App\Models\MaintenanceWorkOrder;
use App\Models\SlaPolicy;
use App\Notifications\WorkOrderSlaBreachedNotification;
use App\Services\MaintenanceWorkOrderService;
use App\Settings\MaintenanceSettings;
use App\Support\SlaResolver;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Per-property SLA for corrective maintenance (FR-CM-05/06/07) + breach detection (FR-CM-08).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->svc = app(MaintenanceWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'SLA']);
});

function cm(array $attrs = []): MaintenanceWorkOrder
{
    return MaintenanceWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'description' => 'Compressor fault.',
        'title' => 'Fix compressor',
        'category' => 'hvac',
        'scheduled_for' => '2026-07-01',
    ], $attrs));
}

/* ---- FR-CM-05: SLA set per mall ---------------------------------------- */

it('falls back to the operator-wide default when a property has no policy', function () {
    // A row is an override, not a requirement — an operator records only the malls that
    // genuinely differ instead of restating four numbers per property.
    $settings = app(MaintenanceSettings::class);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe($settings->sla_urgent_hours);
});

it('uses a property override when one exists', function () {
    // The point of FR-CM-05: a mall with a 24/7 engineering team and a small strip centre
    // cannot share one number.
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(2);
});

it('keeps each property on its own clock', function () {
    $other = makeAsset(['code' => 'SLB']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);
    SlaPolicy::create(['asset_id' => $other->id, 'priority' => 'urgent', 'resolve_hours' => 48]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(2);
    expect(SlaResolver::hoursFor($other->id, 'urgent'))->toBe(48);
});

it('only overrides the priority it names', function () {
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(2);
    expect(SlaResolver::hoursFor($this->asset->id, 'medium'))->toBe(app(MaintenanceSettings::class)->sla_medium_hours);
});

it('allows one policy per property and priority', function () {
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 2]);

    expect(fn () => SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 4]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses a zero-hour SLA', function () {
    // Would mark every job breached the instant it is accepted.
    expect(fn () => SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'low', 'resolve_hours' => 0]))
        ->toThrow(InvalidArgumentException::class);
});

/* ---- FR-CM-06: tiers ---------------------------------------------------- */

it('gives each priority its own duration', function () {
    foreach (['low' => 100, 'medium' => 50, 'high' => 20, 'urgent' => 5] as $priority => $hours) {
        SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => $priority, 'resolve_hours' => $hours]);
    }

    expect(SlaResolver::hoursFor($this->asset->id, 'urgent'))->toBe(5);
    expect(SlaResolver::hoursFor($this->asset->id, 'low'))->toBe(100);
});

it('defaults a new job to Normal priority', function () {
    expect(cm()->priority)->toBe('medium');
});

/* ---- FR-CM-07: the clock starts on ACCEPTANCE -------------------------- */

it('does not treat a freshly raised job as accepted', function () {
    // Module 11 stamps the RESOLUTION target at create-time, so a request nobody picks up for
    // three days has already burned its whole SLA before an engineer sees it — the breach then
    // says more about the queue than about the work. FR-CM-07 keeps that clock on acceptance.
    //
    // Rewritten 2026-08-12. This used to assert `target_resolution_at === null` on a new order,
    // which pinned the trapdoor as if it were the rule: `open → done` is a legal hop, so a job
    // that never passed through acceptance had NO deadline and escaped the scan, the penalty and
    // every filter, permanently. The deadline now exists from the start, measured from when the
    // job SHOULD have been accepted; what stays true is that nobody has accepted it yet.
    $order = cm(['priority' => 'urgent']);

    expect($order->acknowledged_at)->toBeNull();
    expect($order->target_resolution_at)->not->toBeNull();
    expect($order->target_response_at)->not->toBeNull();
    expect($order->isOverdue())->toBeFalse();
});

it('starts the clock when the job is accepted', function () {
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 4]);
    $order = cm(['priority' => 'urgent']);

    $accepted = $this->svc->transition($order, 'in_progress');

    expect($accepted->acknowledged_at)->not->toBeNull();
    expect(abs($accepted->acknowledged_at->diffInHours($accepted->target_resolution_at)))->toEqualWithDelta(4, 0.01);
});

it('breaches BOTH clocks on a job nobody ever accepted', function () {
    // The inverse of what this used to assert. It read "sits on an unaccepted job indefinitely
    // without ever breaching — nothing is late until someone took it on", which described the
    // defect rather than the rule: an order left alone for a month was invisible to every SLA
    // surface, and declining to click Start was a silent way to waive a vendor's penalty.
    //
    // Both clocks now speak, and they say different things: nobody ANSWERED (the queue's problem)
    // and nobody FIXED it (the job's). FR-CM-07 is untouched — an engineer who accepts inside the
    // response window still gets their full resolution window from that moment.
    $order = cm(['priority' => 'urgent']);
    $this->travel(30)->days();

    expect($order->fresh()->isResponseBreached())->toBeTrue()
        ->and($order->fresh()->isOverdue())->toBeTrue();
});

it('uses the property override, not the global default, when starting the clock', function () {
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'medium', 'resolve_hours' => 1]);
    $order = cm(['priority' => 'medium']);

    $accepted = $this->svc->transition($order, 'in_progress');

    expect(abs($accepted->acknowledged_at->diffInHours($accepted->target_resolution_at)))->toEqualWithDelta(1, 0.01);
});

it('does not put a preventive order on an SLA clock', function () {
    // PPM is scheduled work — its date is the plan's, not a response deadline.
    $ppm = MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Scheduled visit', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01',
    ]);

    $started = $this->svc->transition($ppm, 'in_progress');

    expect($started->acknowledged_at)->toBeNull();
    expect($started->target_resolution_at)->toBeNull();
});

/* ---- FR-CM-08 (detection half): breach scan ---------------------------- */

it('alerts operators once when an accepted job runs past its SLA', function () {
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    $order = cm(['priority' => 'urgent']);
    $this->svc->transition($order, 'in_progress');

    $this->travel(3)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches')->assertSuccessful();

    Notification::assertSentTimes(WorkOrderSlaBreachedNotification::class, 1);
    expect($order->fresh()->sla_breach_notified_at)->not->toBeNull();
    expect($order->fresh()->isOverdue())->toBeTrue();
    expect($order->fresh()->hoursOverSla())->toBe(2);
});

it('alerts owner Jawad on a late facility job too (FR MNT-5 / NOT-2), like the tenant-request scan', function () {
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    $owner = makeUser('owner');
    $owner->ownedAssets()->attach($this->asset->id, ['ownership_percentage' => 100]);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    $order = cm(['priority' => 'urgent']);
    $this->svc->transition($order, 'in_progress');

    $this->travel(3)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($owner, WorkOrderSlaBreachedNotification::class); // was silently omitted
});

it('does not alert twice for the same breach', function () {
    // The stamp is the idempotency key; the scan runs hourly.
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    $this->svc->transition(cm(['priority' => 'urgent']), 'in_progress');

    $this->travel(3)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches');
    $this->artisan('maintenance:scan-wo-sla-breaches');

    Notification::assertSentTimes(WorkOrderSlaBreachedNotification::class, 1);
});

it('does not alert on a job that is still within its SLA', function () {
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 8]);
    $this->svc->transition(cm(['priority' => 'urgent']), 'in_progress');

    $this->travel(2)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches');

    Notification::assertNothingSent();
});

it('does not alert on a job that was finished before its deadline passed', function () {
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    $order = cm(['priority' => 'urgent']);
    $this->svc->transition($order, 'in_progress');
    $this->svc->transition($order->fresh(), 'done');

    $this->travel(5)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches');

    Notification::assertNothingSent();
});

it('stamps a breach even when the property has no staff to alert', function () {
    // Otherwise a mall with nobody assigned re-alerts on every hourly run forever.
    Notification::fake();
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    $order = cm(['priority' => 'urgent']);
    $this->svc->transition($order, 'in_progress');

    $this->travel(3)->hours();
    $this->artisan('maintenance:scan-wo-sla-breaches');

    Notification::assertNothingSent();
    expect($order->fresh()->sla_breach_notified_at)->not->toBeNull();
});

it('never alerts on a preventive order', function () {
    Notification::fake();
    makeUser('operations', [$this->asset->id]);
    // Hand-set a target on a PPM order — the scan must still ignore it, because SLA is a
    // corrective concept and a scheduled visit is not "late".
    $ppm = MaintenanceWorkOrder::create([
        'asset_id' => $this->asset->id, 'title' => 'Visit', 'category' => 'hvac',
        'scheduled_for' => '2026-07-01', 'target_resolution_at' => now()->subDay(),
    ]);

    $this->artisan('maintenance:scan-wo-sla-breaches');

    Notification::assertNothingSent();
    expect($ppm->fresh()->sla_breach_notified_at)->toBeNull();
});
