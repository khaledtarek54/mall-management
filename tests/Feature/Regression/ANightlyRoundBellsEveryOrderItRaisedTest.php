<?php

use App\Models\FacilityWorkOrder;
use App\Models\ServicePlan;
use App\Notifications\WorkOrderRaisedNotification;
use App\Services\GeneratePreventiveWorkOrdersService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Regression — SW-247. The preventive round bells EVERY order it raised, and names the ones it
 * could not.
 *
 * `GeneratePreventiveWorkOrdersService::notifyRaised()` wrapped its whole `->each()` in one
 * `try/catch`, so the first order whose transport failed silenced every order after it — nightly,
 * on `facility:generate-preventive` — and `Log::warning` named only the first error, so nothing
 * recorded WHICH orders went unannounced. The orders themselves are durable (the finding is the
 * row; the bell is how it travels), so the fix is SW-244's: best effort PER ORDER through
 * `BestEffortNotification`, with the order's id in the ops-log record.
 *
 * Found by the adversarial review of SW-244, 2026-09-10, which is what a review is for: the same
 * shape one file along.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PMB']);
    makeUser('operations', [$this->asset->id]);
    $this->svc = app(GeneratePreventiveWorkOrdersService::class);
});

function sw247DuePlan(int $assetId, string $title): ServicePlan
{
    return ServicePlan::create([
        'asset_id' => $assetId,
        'title' => $title,
        'trade_id' => tradeId('hvac'),
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'checklist' => ['Check'],
        'next_due_date' => now()->subDay()->toDateString(),
        'is_active' => true,
    ]);
}

it('still bells the second order when the first one’s transport fails', function () {
    sw247DuePlan($this->asset->id, 'Lift inspection');
    sw247DuePlan($this->asset->id, 'Generator test');

    // Mockery verifies the count on teardown: the round must ATTEMPT both orders. With one catch
    // around the loop the first throw ended the loop and this was called once.
    Notification::shouldReceive('send')->twice()->andThrow(new RuntimeException('[status code] 403 Forbidden'));

    $raised = null;
    $ops = captureOpsLog(function () use (&$raised) {
        $raised = $this->svc->run();
    });

    // Both orders exist — the finding was never at risk — and both misses are on the record, each
    // naming ITS order, which is the half the old `Log::warning` could not say.
    expect($raised)->toBe(2);

    $misses = collect($ops)->where('message', 'notification.delivery_failed');
    $named = $misses->pluck('context.facility_work_order_id')->sort()->values()->all();

    expect($misses)->toHaveCount(2)
        ->and($named)->toBe(FacilityWorkOrder::query()->orderBy('id')->pluck('id')->all())
        ->and($misses->pluck('level')->unique()->all())->toBe(['warning']);
});

it('still bells at all — the control', function () {
    // A "fix" that stopped notifying would satisfy the refusal above, so the delivering path is
    // asserted through the real dispatcher.
    sw247DuePlan($this->asset->id, 'Lift inspection');
    Notification::fake();

    expect($this->svc->run())->toBe(1);

    Notification::assertSentTimes(WorkOrderRaisedNotification::class, 1);
});

it('exits SUCCESS rather than failing the scheduled run', function () {
    sw247DuePlan($this->asset->id, 'Lift inspection');
    Notification::shouldReceive('send')->andThrow(new RuntimeException('transport down'));

    captureOpsLog(function () {
        $this->artisan('facility:generate-preventive')->assertSuccessful();
    });
});
