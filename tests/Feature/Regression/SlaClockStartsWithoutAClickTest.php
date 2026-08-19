<?php

use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Notifications\WorkOrderResponseSlaBreachedNotification;
use App\Services\FacilityWorkOrderService;
use App\Settings\SlaSettings;
use App\Support\SlaResolver;
use Illuminate\Support\Facades\Notification;

/**
 * The SLA moat had a trapdoor: the clock only started if somebody clicked Start.
 *
 * `target_resolution_at` was written in exactly ONE place — the manual `open → in_progress`
 * transition — and `open → done` is a legal hop. So an external corrective order could be created,
 * worked for three weeks and closed with the target still **null**. `isSlaBreached()` requires a
 * non-null target, so the hourly scan, the penalty gate, the table filter and the dashboard card
 * all skipped it, permanently. Not clicking Start was a silent way to waive a vendor's penalty,
 * with nothing recording that it happened.
 *
 * FR-CM-07's rule is untouched: the RESOLUTION clock runs from acceptance, so an engineer is never
 * charged for the time a job spent in a queue. What was missing is the other side of that trade —
 * if queue time is not the engineer's problem, it has to be somebody's. Hence a **response** clock
 * from creation, which every FM specialist runs and this system had zero trace of
 * (`respond_by`, `first_response`, `target_response` — no hits anywhere).
 *
 * One rule states both: **a job has `resolve_hours` from the moment it was accepted, or from the
 * moment it should have been — whichever came first.**
 */
beforeEach(function () {
    $this->svc = app(FacilityWorkOrderService::class);
    $this->asset = makeAsset(['code' => 'MALL']);

    $settings = app(SlaSettings::class);
    $settings->sla_high_hours = 24;
    $settings->sla_high_respond_hours = 4;
    $settings->save();
});

function correctiveOrder(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => 'internal',
        'title' => 'Chiller down',
        'description' => 'No cooling on level 2.',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'high',
        'scheduled_for' => now()->toDateString(),
    ], $attrs));
}

it('gives every corrective order a deadline the moment it is raised', function () {
    // The headline. Before, both columns were null until somebody clicked Start.
    $order = correctiveOrder();

    expect($order->target_response_at)->not->toBeNull()
        ->and($order->target_resolution_at)->not->toBeNull()
        // 4h to accept, then 24h to fix: the deadline a job has if nobody touches it.
        ->and($order->target_response_at->diffInHours($order->created_at, true))->toBe(4.0)
        ->and($order->target_resolution_at->diffInHours($order->created_at, true))->toBe(28.0);
});

it('catches the job that goes open → done without ever being accepted', function () {
    // The exact escape. `open → done` is legal, so the whole job used to complete with no target
    // and `isSlaBreached()` false — the vendor took three weeks and owed nothing.
    $order = correctiveOrder(['execution_type' => 'external']);

    $this->travel(40)->hours();
    $this->svc->transition($order->fresh(), 'done');

    expect($order->fresh()->isSlaBreached())->toBeTrue()
        ->and($order->fresh()->hoursOverSla())->toBeGreaterThan(0);
});

it('still gives an engineer their full window from acceptance — FR-CM-07 intact', function () {
    // The rule this must not break. Accepting inside the response window pulls the deadline IN to
    // acceptance + resolve_hours, so queue time is not deducted from the engineer's window.
    $order = correctiveOrder();

    $this->travel(2)->hours();
    $accepted = $this->svc->transition($order->fresh(), 'in_progress');

    expect($accepted->acknowledged_at)->not->toBeNull()
        // 2h queued + 24h to fix = 26h from creation, tighter than the 28h floor.
        ->and(round($accepted->target_resolution_at->diffInHours($order->created_at, true)))->toBe(26.0);
});

it('does not let a late acceptance buy extra time to finish', function () {
    // The trapdoor in its milder form. If acceptance simply ASSIGNED the deadline, sitting on a job
    // for a week and then clicking Start would hand the contractor a fresh full window — an
    // extension bought by ignoring the work.
    $order = correctiveOrder();
    $floor = $order->target_resolution_at;

    $this->travel(30)->hours();     // long past the 4h response target
    $accepted = $this->svc->transition($order->fresh(), 'in_progress');

    expect($accepted->target_resolution_at->equalTo($floor))->toBeTrue();
});

it('breaches the response clock when nobody takes the job on', function () {
    $order = correctiveOrder();

    $this->travel(3)->hours();
    expect($order->fresh()->isResponseBreached())->toBeFalse();

    $this->travel(2)->hours();      // 5h total, past the 4h target
    expect($order->fresh()->isResponseBreached())->toBeTrue()
        ->and($order->fresh()->hoursOverResponseSla())->toBe(1)
        ->and(FacilityWorkOrder::responseBreached()->pluck('id')->all())->toContain($order->id);
});

it('stops the response clock at acceptance, not at now — the paired control', function () {
    // Answered on time. Nothing here may drift into a breach a week later just because the job is
    // still open, which is what measuring to `now()` unconditionally would do.
    $order = correctiveOrder();

    $this->travel(1)->hour();
    $this->svc->transition($order->fresh(), 'in_progress');

    $this->travel(10)->days();

    expect($order->fresh()->isResponseBreached())->toBeFalse()
        ->and(FacilityWorkOrder::responseBreached()->count())->toBe(0);
});

it('leaves preventive rounds out of both clocks', function () {
    // A PPM round is scheduled work with a `scheduled_for`, not a response-and-repair obligation,
    // and every SLA surface in the module filters ->corrective(). Stamping it would put routine
    // filter changes on the breach dashboard.
    $ppm = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => FacilityWorkOrder::TYPE_PPM,
        'title' => 'Quarterly filter change',
        'trade_id' => tradeId('hvac'),
        'status' => 'open',
        'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
    ]);

    expect($ppm->target_response_at)->toBeNull()
        ->and($ppm->target_resolution_at)->toBeNull()
        ->and($ppm->isResponseBreached())->toBeFalse();
});

it('resolves the response target through the same three tiers as the resolution one', function () {
    // One chain, so a property that overrides its SLA overrides both halves in the same place. A
    // second resolution path is how the picker and the guard start disagreeing.
    SlaPolicy::create([
        'asset_id' => $this->asset->id, 'priority' => 'high',
        'resolve_hours' => 12, 'respond_hours' => 2, 'is_active' => true,
    ]);

    expect(SlaResolver::respondHoursFor($this->asset->id, 'high'))->toBe(2)
        ->and(SlaResolver::hoursFor($this->asset->id, 'high'))->toBe(12)
        // A policy may override resolution ONLY — null response falls through to the operator-wide
        // target rather than needing a sentinel value.
        ->and(SlaResolver::respondHoursFor($this->asset->id, 'medium'))
        ->toBe(app(SlaSettings::class)->sla_medium_respond_hours);
});

it('alerts once on an unanswered job, off its own stamp', function () {
    $manager = makeUser('manager');
    $manager->assignedAssets()->attach($this->asset->id);

    $order = correctiveOrder();
    $this->travel(6)->hours();

    Notification::fake();
    $this->artisan('facility:scan-sla-breaches')->assertSuccessful();

    Notification::assertSentTo($manager, WorkOrderResponseSlaBreachedNotification::class);
    expect($order->fresh()->response_breach_notified_at)->not->toBeNull();

    // Once. The hourly scan must not re-nag about a job somebody is already chasing.
    Notification::fake();
    $this->artisan('facility:scan-sla-breaches')->assertSuccessful();
    Notification::assertNothingSent();

    // NOT covered, and stated rather than faked: `alertResponseBreach()` re-checks the stamp a
    // second time INSIDE the row lock. Deleting that check leaves this test green, because the
    // outer query already excludes stamped rows — it only matters when a slow scan is still going
    // as the next one fires, which a single-process test cannot produce. Its sibling
    // `alertBreach()` carries the identical untestable guard for the identical reason.
});

it('keeps the two stamps apart, so one breach cannot silence the other', function () {
    // A job answered late but fixed on time is a different conversation from one answered on time
    // and fixed late. Sharing a stamp would mean whichever clock breached first hid the other.
    //
    // A recipient has to exist: `AssetStaffRecipients` resolves through spatie roles, and with no
    // roles at all the send throws, the scan contains it, and NOTHING is stamped — which would make
    // this pass or fail for a reason that has nothing to do with the stamps.
    makeUser('manager')->assignedAssets()->attach($this->asset->id);

    $order = correctiveOrder();
    $this->travel(6)->hours();
    $this->artisan('facility:scan-sla-breaches')->assertSuccessful();

    expect($order->fresh()->response_breach_notified_at)->not->toBeNull()
        ->and($order->fresh()->sla_breach_notified_at)->toBeNull();

    $this->travel(40)->hours();
    $this->artisan('facility:scan-sla-breaches')->assertSuccessful();

    expect($order->fresh()->sla_breach_notified_at)->not->toBeNull();
});

it('stamps the clocks on orders that predate the response clock', function () {
    // Every job that slipped through the original defect is sitting in the database with two null
    // columns. Without the heal they stay invisible forever — which is the bug, not the fix.
    $order = correctiveOrder();
    FacilityWorkOrder::whereKey($order->id)
        ->update(['target_response_at' => null, 'target_resolution_at' => null]);

    $this->artisan('facility:scan-sla-breaches')->assertSuccessful();

    expect($order->fresh()->target_response_at)->not->toBeNull()
        ->and($order->fresh()->target_resolution_at)->not->toBeNull();
});

it('writes nothing on --dry-run, backfill included', function () {
    // The option is documented as "print what would be alerted WITHOUT writing", and stamping a
    // deadline is a write. Same reasoning that already keeps penalty assessment out of the preview.
    $order = correctiveOrder();
    FacilityWorkOrder::whereKey($order->id)
        ->update(['target_response_at' => null, 'target_resolution_at' => null]);

    $this->travel(6)->hours();

    Notification::fake();
    $this->artisan('facility:scan-sla-breaches', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect($order->fresh()->target_response_at)->toBeNull()
        ->and($order->fresh()->response_breach_notified_at)->toBeNull();
});
