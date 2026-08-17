<?php

use App\Models\MeterReading;
use App\Models\ServicePlan;
use App\Models\UtilityMeter;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Support\ActivityVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * A preventive plan can fire on running hours, not only on the calendar.
 *
 * Every plan in module 26 was time-driven, which is right for a statutory round ("extinguishers,
 * annually") and wrong for the machines a mall actually runs. A chiller, a lift and a generator are
 * serviced on **running hours**: a genset idle for six months needs nothing, one running double
 * shifts needs servicing twice as often. A calendar plan gets both wrong in opposite directions —
 * it over-services the idle machine and under-services the hard-worked one, which is precisely the
 * failure the interval exists to prevent.
 *
 * Usage is read from a meter, because `meter_readings.reading_value` is already a cumulative
 * counter with a per-property register, a reading workflow and an import path around it.
 */
function usagePlanFixture(array $planAttrs = [], float $baselineReading = 1000.0): array
{
    $asset = makeAsset();

    $meter = UtilityMeter::create([
        'asset_id' => $asset->id,
        'unit_id' => null,
        'meter_number' => 'CHILLER-HRS',
        'type' => 'hours',
        'status' => 'active',
        'unit_of_measurement' => 'h',
    ]);

    // The machine has been counting for years before anyone wrote a plan for it.
    MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => now()->subMonths(2)->toDateString(),
        'reading_value' => $baselineReading,
        'consumption' => 0,
        'cost' => 0,
    ]);

    $plan = ServicePlan::create(array_merge([
        'asset_id' => $asset->id,
        'title' => 'Chiller service',
        'category' => 'hvac',
        'trigger_type' => ServicePlan::TRIGGER_USAGE,
        'utility_meter_id' => $meter->id,
        'usage_threshold' => 500,
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => now()->toDateString(),
        'checklist' => ['Check refrigerant', 'Clean coils'],
        'is_active' => true,
    ], $planAttrs));

    return [$asset, $meter, $plan->fresh()];
}

function readMeter(UtilityMeter $meter, float $value, ?string $on = null): MeterReading
{
    return MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => $on ?? now()->toDateString(),
        'reading_value' => $value,
        'consumption' => 0,
        'cost' => 0,
    ]);
}

it('baselines a new usage plan against the counter it starts watching', function () {
    // The trap this closes: a meter installed years ago reads 1,000 hours, and measuring the first
    // delta from ZERO would make the plan instantly overdue by two full thresholds and raise a
    // backlog of services that were never actually missed.
    [, , $plan] = usagePlanFixture();

    expect((float) $plan->usage_at_last_generation)->toBe(1000.0)
        ->and($plan->usageSinceLastGeneration())->toBe(0.0)
        ->and($plan->isDueByUsage())->toBeFalse();
});

it('does not fire before the counter has moved a full threshold', function () {
    [, $meter, $plan] = usagePlanFixture();

    readMeter($meter, 1499);

    expect($plan->fresh()->isDueByUsage())->toBeFalse()
        ->and(app(GeneratePreventiveWorkOrdersService::class)->run())->toBe(0);
});

it('raises a work order once the counter passes the threshold', function () {
    [, $meter, $plan] = usagePlanFixture();

    readMeter($meter, 1500);

    expect($plan->fresh()->isDueByUsage())->toBeTrue();

    $raised = app(GeneratePreventiveWorkOrdersService::class)->run();

    expect($raised)->toBe(1)
        ->and($plan->workOrders()->count())->toBe(1);

    // The checklist is copied exactly as it is on the time round — a job raised on hours is the
    // same job in every respect except what made it due.
    expect($plan->workOrders()->first()->items()->count())->toBe(2);
});

it('says on the order that the counter is what raised it', function () {
    // A technician holding the job needs to know it came from running hours, not the calendar —
    // and after the plan is edited or deleted the order is the only thing that still says so.
    [, $meter, $plan] = usagePlanFixture();
    readMeter($meter, 1600);

    app(GeneratePreventiveWorkOrdersService::class)->run();

    expect($plan->workOrders()->first()->notes)->toContain('CHILLER-HRS');
});

it('does not raise a second job until another full threshold is consumed', function () {
    [, $meter, $plan] = usagePlanFixture();

    readMeter($meter, 1500);
    app(GeneratePreventiveWorkOrdersService::class)->run();

    // Baseline moved to the triggering reading, so the counter must climb another 500.
    expect((float) $plan->fresh()->usage_at_last_generation)->toBe(1500.0);

    readMeter($meter, 1999, now()->addDay()->toDateString());
    expect(app(GeneratePreventiveWorkOrdersService::class)->run(now()->addDay()->toDateString()))->toBe(0);

    readMeter($meter, 2000, now()->addDays(2)->toDateString());
    expect(app(GeneratePreventiveWorkOrdersService::class)->run(now()->addDays(2)->toDateString()))->toBe(1)
        ->and($plan->workOrders()->count())->toBe(2);
});

it('credits the FULL overshoot, so a hard-worked machine does not queue a second job', function () {
    // A machine that ran 700 hours between reads has had 700 hours of wear. Advancing the baseline
    // by the THRESHOLD (500) instead of to the reading would leave 200 already banked, and the very
    // next 300 hours would raise a second service for work just scheduled.
    [, $meter, $plan] = usagePlanFixture();

    readMeter($meter, 1700);
    app(GeneratePreventiveWorkOrdersService::class)->run();

    expect((float) $plan->fresh()->usage_at_last_generation)->toBe(1700.0)
        ->and($plan->fresh()->usageSinceLastGeneration())->toBe(0.0);
});

it('is idempotent within a day, however the counter moves', function () {
    [, $meter, $plan] = usagePlanFixture();
    readMeter($meter, 1500);

    app(GeneratePreventiveWorkOrdersService::class)->run();
    app(GeneratePreventiveWorkOrdersService::class)->run();

    expect($plan->workOrders()->count())->toBe(1);
});

it('never lets a usage plan also fire on its calendar', function () {
    // The load-bearing line of the whole change. `next_due_date` is NOT NULL, so a usage plan
    // carries one like every other row — without the `trigger_type` filter on `scopeDue()` it would
    // match the time round as well and raise TWO work orders for one service.
    [, $meter, $plan] = usagePlanFixture(['next_due_date' => now()->subYear()->toDateString()]);

    readMeter($meter, 1500);

    expect(ServicePlan::due(now()->toDateString())->pluck('id'))->not->toContain($plan->id)
        ->and(app(GeneratePreventiveWorkOrdersService::class)->run())->toBe(1);
});

it('leaves time-driven plans exactly as they were', function () {
    // The control. Every existing plan defaults to `time`, and this change must be invisible to it.
    $asset = makeAsset();
    $timePlan = ServicePlan::create([
        'asset_id' => $asset->id,
        'title' => 'Fire extinguishers',
        'category' => 'safety',
        'frequency_unit' => 'months',
        'frequency_value' => 12,
        'next_due_date' => now()->toDateString(),
        'checklist' => ['Check pressure'],
        'is_active' => true,
    ]);

    expect($timePlan->trigger_type)->toBe(ServicePlan::TRIGGER_TIME)
        ->and(app(GeneratePreventiveWorkOrdersService::class)->run())->toBe(1)
        ->and($timePlan->fresh()->next_due_date->toDateString())
        ->toBe(now()->addMonths(12)->toDateString());
});

it('stays quiet when the counter has never been read', function () {
    // "Unknown" must not read as "zero": a plan pointed at a meter nobody has read is
    // misconfigured, not perpetually not-due-yet. It raises nothing, and the delta says null rather
    // than 0 so the distinction survives to anything that reports on it.
    $asset = makeAsset();
    $meter = UtilityMeter::create([
        'asset_id' => $asset->id, 'meter_number' => 'NEW-HRS', 'type' => 'hours', 'status' => 'active',
    ]);
    $plan = ServicePlan::create([
        'asset_id' => $asset->id,
        'title' => 'Genset service',
        'category' => 'hvac',
        'trigger_type' => ServicePlan::TRIGGER_USAGE,
        'utility_meter_id' => $meter->id,
        'usage_threshold' => 500,
        'frequency_unit' => 'months',
        'frequency_value' => 1,
        'next_due_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    expect($plan->fresh()->isDueByUsage())->toBeFalse()
        ->and(app(GeneratePreventiveWorkOrdersService::class)->run())->toBe(0);
});

it('treats a rolled-over or replaced counter as not-due rather than instantly due', function () {
    // A meter swapped for a new unit reads LOWER than the baseline. Reported negative, the
    // arithmetic would go true again the moment the counter passed the OLD baseline — months of
    // wear later than it should. Clamped at 0: not due, and the operator re-baselines by saving.
    [, $meter, $plan] = usagePlanFixture();

    readMeter($meter, 5, now()->addDay()->toDateString());

    expect($plan->fresh()->usageSinceLastGeneration())->toBe(0.0)
        ->and($plan->fresh()->isDueByUsage())->toBeFalse();
});

it('refuses a trigger_type outside the value set', function () {
    expect(fn () => usagePlanFixture(['trigger_type' => 'whenever']))
        ->toThrow(DomainException::class);
});

it('records a switch between the calendar and the counter in the audit trail', function () {
    // Changing what makes a machine due for service is exactly the change an auditor asks about,
    // and none of the three columns that decide it were logged when the feature shipped —
    // `logOnly()` is an allowlist, so a new column is invisible until it is named.
    // Starts as a plain calendar plan with no counter, so all three columns are genuinely dirty on
    // the switch. `logOnlyDirty()` would otherwise (correctly) omit a value that did not move.
    [, $meter, $plan] = usagePlanFixture([
        'trigger_type' => ServicePlan::TRIGGER_TIME,
        'utility_meter_id' => null,
        'usage_threshold' => null,
    ]);

    $plan->update([
        'trigger_type' => ServicePlan::TRIGGER_USAGE,
        'utility_meter_id' => $meter->id,
        'usage_threshold' => 500,
    ]);

    // `attribute_changes`, NOT `properties` — spatie stores the before/after diff in its own
    // column and leaves `properties` empty for an ordinary model change. Reading `properties` shows
    // `[]` for every model in the system and reads exactly like a broken audit trail; it isn't one.
    $changes = json_decode(
        (string) DB::table('activity_log')->where('log_name', 'service_plan')->latest('id')->value('attribute_changes'),
        true,
    );

    expect($changes['attributes'] ?? [])->toHaveKeys(['trigger_type', 'utility_meter_id', 'usage_threshold'])
        ->and($changes['old']['trigger_type'] ?? null)->toBe(ServicePlan::TRIGGER_TIME)
        ->and($changes['attributes']['trigger_type'] ?? null)->toBe(ServicePlan::TRIGGER_USAGE);
});

it('reads the trigger and the counter as words, not as raw data', function () {
    // The lesson from the tariff sweep: a field LABEL satisfies the conformance gate while the
    // VALUE still renders raw. Both halves are asserted, in both languages, because `Lang::has()`
    // falls back to English and a parity check written the obvious way passes on a missing key.
    [, $meter] = usagePlanFixture();
    $vocab = app(ActivityVocabulary::class);

    expect($vocab->value('service_plan', 'service_plan', 'trigger_type', 'usage'))
        ->toBe('A counter (running hours, consumption)')
        // The FK must NAME the meter — `meter_number` is not in the fallback chain, so this only
        // works because `UtilityMeter::label()` exists.
        ->and($vocab->value('service_plan', 'service_plan', 'utility_meter_id', $meter->id))
        ->toBe('CHILLER-HRS');

    app()->setLocale('ar');

    try {
        expect($vocab->field('service_plan', 'trigger_type'))->toBe('يُستدعى بواسطة');
    } finally {
        app()->setLocale('en');
    }
});
