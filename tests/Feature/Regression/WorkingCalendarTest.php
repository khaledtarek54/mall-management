<?php

/*
|--------------------------------------------------------------------------
| Egypt's working week, and the SLA clock that has to know about it
|--------------------------------------------------------------------------
| Egypt's weekend is FRIDAY and SATURDAY, and until EG-08 no part of this system knew: every SLA
| deadline was `now()->addHours($n)` on a bare calendar. A 24-hour urgent job raised Thursday 17:00
| fell due Friday 17:00 with nobody on site — and vendor SLA penalties, which post to the general
| ledger, were computed off that clock. This is the case the feature exists for and it is the first
| test below.
|
| ## Two things about these tests that are load-bearing
|
| **The timezone is pinned to Africa/Cairo, not merely frozen.** Production runs Cairo
| (`config/app.php`) and the suite pins UTC (`phpunit.xml`) so its determinism is a stated choice.
| A job raised Friday 00:30 in Cairo is Thursday 22:30 in UTC — a working day in one and the weekend
| in the other. The boundary cases this feature exists for are exactly the ones a UTC-only test gets
| wrong, so every case here sets the clock in Cairo explicitly.
|
| Precisely: `config(['app.timezone' => …])` pins what `WorkingCalendar` reads, because that class
| asks `config()` directly. It does NOT call `date_default_timezone_set()`, so Eloquent's own
| datetime round-trip stays on the suite's UTC — a Cairo-only PERSISTENCE bug would not be caught
| here. What is covered is the calendar's day-and-window arithmetic, which is where the risk is.
|
| **The working clock ships OFF.** `SlaSettings::sla_working_clock_priorities` is empty by default,
| which is the behaviour that predates the calendar. Whether a given priority is office work or a
| round-the-clock promise is the operator's ruling — so these tests switch it on for the priority
| under test and, crucially, prove the default path is untouched.
*/

use App\Enums\TenantRequestType;
use App\Models\FacilityWorkOrder;
use App\Models\Holiday;
use App\Models\TenantRequest;
use App\Services\FacilityWorkOrderService;
use App\Services\TenantRequestService;
use App\Settings\CalendarSettings;
use App\Settings\SlaSettings;
use App\Support\SlaResolver;
use App\Support\WorkingCalendar;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Africa/Cairo, explicitly. The suite pins UTC for determinism (phpunit.xml) and production
    // runs Cairo (config/app.php) — and a working day is 09:00 in the mall, not 09:00 UTC. Without
    // this the calendar measures Cairo 00:30 Friday as Thursday 22:30 and calls it a working day,
    // which is the precise boundary the feature exists to get right.
    config(['app.timezone' => 'Africa/Cairo']);

    $this->asset = makeAsset();

    // Egypt's week, explicitly rather than by trusting the shipped default: a test that would pass
    // on a Monday–Friday calendar is not testing this feature.
    tap(app(CalendarSettings::class), function (CalendarSettings $c) {
        $c->working_days = CalendarSettings::EGYPTIAN_WEEK;
        $c->day_opens_at = '09:00';
        $c->day_closes_at = '17:00';
    });
});

// Captured before anything changes it, so the restore below puts back what was actually there
// rather than a hard-coded guess. 'UTC' happens to be right today only because `phpunit.xml` pins
// `APP_TIMEZONE=UTC`; a test file should not silently depend on that.
$processTimezone = date_default_timezone_get();

afterEach(function () use ($processTimezone) {
    CarbonImmutable::setTestNow();
    // One case below pins the PROCESS timezone (not just the config) because it compares a stored
    // deadline against a live `now()`. Restoring here rather than in that test means an assertion
    // failure cannot leak Cairo into the rest of the file — or, under `--parallel`, into whatever
    // else this worker runs next.
    date_default_timezone_set($processTimezone);
});

/** Cairo, not UTC — see the header. */
function cairo(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when, 'Africa/Cairo');
}

it('does not fall due on a Friday, which is the whole point', function () {
    // Thursday 17 September 2026 is a working day; Friday and Saturday are the weekend.
    $raised = cairo('2026-09-17 15:00'); // Thursday, two hours before close

    $due = WorkingCalendar::addWorkingHours($raised, 4, $this->asset->id);

    // Two hours left on Thursday, so the remaining two land on SUNDAY morning — not Friday.
    expect($due->setTimezone('Africa/Cairo')->format('D Y-m-d H:i'))->toBe('Sun 2026-09-20 11:00');
});

it('counts the calendar the old way when nothing has been switched on', function () {
    // The control for every case here: with the feature shipped OFF, the deadline must be exactly
    // what it was before the working calendar existed — including being wrong for Egypt.
    CarbonImmutable::setTestNow(cairo('2026-09-17 15:00')); // Thursday afternoon

    expect(app(SlaSettings::class)->sla_working_clock_priorities)->toBe([]);

    $order = correctiveOrder(['priority' => 'urgent'])->fresh();

    // The job records the clock it was promised on — `calendar`, explicitly, rather than null.
    // Null belongs to orders that predate the feature and were never stamped; a new one says which
    // rule it is measured by, which is what makes the freeze auditable.
    expect($order->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_CALENDAR);

    // Bare calendar arithmetic, to the hour: respond 1h then resolve 4h from Thursday 15:00 lands
    // Thursday 20:00 — after the mall has closed, which is the defect the operator may now opt out
    // of. Under the working clock the same job would fall due Sunday morning.
    $response = $order->target_response_at->setTimezone('Africa/Cairo');
    $resolution = $order->target_resolution_at->setTimezone('Africa/Cairo');

    expect($response->format('D H:i'))->toBe('Thu 16:00')
        ->and($resolution->format('D H:i'))->toBe('Thu 20:00');
});

it('skips a holiday the operator entered, and only for the property it names', function () {
    // Sunday 20 September is a working day…
    expect(WorkingCalendar::isWorkingDay(cairo('2026-09-20'), $this->asset->id))->toBeTrue();

    $other = makeAsset();

    Holiday::create([
        'asset_id' => $this->asset->id,
        'date' => '2026-09-20',
        'kind' => Holiday::KIND_CLOSURE,
        'name_en' => 'Fit-out shutdown',
        'name_ar' => 'إغلاق للتجهيز',
    ]);

    // …and now it is not, at this mall only. The control is the neighbour: a property-scoped row
    // that silently closed the whole portfolio would pass a refusal-only test.
    expect(WorkingCalendar::isWorkingDay(cairo('2026-09-20'), $this->asset->id))->toBeFalse()
        ->and(WorkingCalendar::isWorkingDay(cairo('2026-09-20'), $other->id))->toBeTrue();
});

it('lets one mall trade through a national holiday', function () {
    Holiday::create([
        'asset_id' => null, // the whole portfolio
        'date' => '2026-09-20',
        'kind' => Holiday::KIND_CLOSURE,
        'name_en' => 'National holiday',
        'name_ar' => 'عطلة رسمية',
    ]);

    expect(WorkingCalendar::isWorkingDay(cairo('2026-09-20'), $this->asset->id))->toBeFalse();

    // The property's own row wins — which is how "we trade through Eid" is expressed, without a
    // third kind of row for it.
    Holiday::create([
        'asset_id' => $this->asset->id,
        'date' => '2026-09-20',
        'kind' => Holiday::KIND_SHORT_DAY,
        'opens_at' => '10:00',
        'closes_at' => '14:00',
        'name_en' => 'Trading through',
        'name_ar' => 'العمل مستمر',
    ]);

    expect(WorkingCalendar::isWorkingDay(cairo('2026-09-20'), $this->asset->id))->toBeTrue();
});

it('honours Ramadan hours, so a job raised in Ramadan gets the days it really needs', function () {
    // A six-hour day, which is what Egyptian law gives during Ramadan — at full pay.
    foreach (['2026-09-20', '2026-09-21', '2026-09-22'] as $date) {
        Holiday::create([
            'asset_id' => null,
            'date' => $date,
            'kind' => Holiday::KIND_SHORT_DAY,
            'opens_at' => '09:00',
            'closes_at' => '15:00',
            'name_en' => 'Ramadan hours',
            'name_ar' => 'ساعات رمضان',
        ]);
    }

    // 14 working hours from Sunday 09:00: 6 on Sunday, 6 on Monday, 2 on Tuesday.
    $due = WorkingCalendar::addWorkingHours(cairo('2026-09-20 09:00'), 14, $this->asset->id);

    expect($due->setTimezone('Africa/Cairo')->format('D Y-m-d H:i'))->toBe('Tue 2026-09-22 11:00');
});

it('freezes the clock on the job, so changing the policy never re-prices work in flight', function () {
    // The correction that matters most: a PENDING penalty is recomputed on every hourly scan and
    // `SlaPenalty.amount` is DERIVED, so resolving the clock at read time would have moved the
    // books under a job already running.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = ['medium']);

    $order = correctiveOrder(['priority' => 'medium', 'created_at' => cairo('2026-09-17 15:00')]);

    $stamped = $order->fresh();
    expect($stamped->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_WORKING);

    $deadline = $stamped->target_resolution_at;

    // The operator changes their mind. The job in flight must not move.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = []);

    $stamped->stampSlaClocks();

    expect($stamped->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_WORKING)
        ->and($stamped->target_resolution_at->equalTo($deadline))->toBeTrue();

    // …and again with the deadlines CLEARED, which is the only shape that reaches the
    // `$this->sla_clock ?? SlaResolver::clockFor(...)` this case is named for. With both targets
    // already set, `stampSlaClocks()` skips both branches and the assertions above hold whether or
    // not the freeze exists — the whole case passed with the `??` deleted.
    $stamped->forceFill(['target_response_at' => null, 'target_resolution_at' => null]);
    $stamped->stampSlaClocks();

    expect($stamped->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_WORKING,
        'A re-stamp must keep the clock the job was PROMISED, not resolve the setting as it is now.')
        // And the re-derived deadline is on that promised clock, not the current one.
        ->and(WorkingCalendar::isWorkingDay($stamped->target_resolution_at, $this->asset->id))->toBeTrue();
});

it('never re-promises the legacy backlog when the operator switches the clock on', function () {
    // The one path where the freeze is load-bearing, and it had no test at all. The hourly scan
    // heals orders raised BEFORE the feature — both deadlines and `sla_clock` null. If it resolved
    // the CURRENT setting, the day an operator ticked a priority the whole backlog would silently
    // acquire a working-clock deadline and a different penalty BASIS, via `saveQuietly()`, so not
    // even the activity log would show it.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = ['medium']);
    CarbonImmutable::setTestNow(cairo('2026-09-21 09:00')); // Monday

    // A legacy row: raised Thursday, never stamped.
    $legacy = correctiveOrder(['priority' => 'medium', 'created_at' => cairo('2026-09-17 15:00')]);
    $legacy->forceFill([
        'sla_clock' => null,
        'target_response_at' => null,
        'target_resolution_at' => null,
    ])->saveQuietly();

    $this->artisan('facility:scan-sla-breaches')->assertExitCode(0);

    expect($legacy->fresh()->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_CALENDAR,
        'A job raised before the feature was promised the calendar and must keep it.');

    // The control: a job raised NOW, with the same setting, IS on the working clock — so the
    // assertion above is the heal being careful, not the feature failing to apply at all.
    $fresh = correctiveOrder(['priority' => 'medium', 'created_at' => cairo('2026-09-21 09:00')]);

    expect($fresh->fresh()->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_WORKING);
});

it('measures a work order\'s overrun on the clock it was promised on', function () {
    // The module-26 twin of the tenant-request fix, and it was left on bare calendar hours while
    // `daysOverSla()` — the MONEY — was converted. One `sla_penalties` row therefore carried an
    // overrun measured two different ways: "66 hours over" beside an amount priced at one working
    // day, with the breach bell and its email quoting the 66.
    //
    // Process timezone pinned for the same reason the tenant-request case pins it: the deadline is
    // STORED and Laravel's datetime cast reads a stored wall clock in the process timezone, which
    // the suite holds at UTC. A pure duration diff is unaffected (both ends shift together), but
    // the working branch resolves each end against Cairo opening hours, so a three-hour offset
    // moves Thursday 16:00 past closing. Production runs Cairo end to end; `afterEach` restores.
    date_default_timezone_set('Africa/Cairo');

    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = ['medium']);

    $order = correctiveOrder([
        'priority' => 'medium',
        'status' => 'done',
        'target_resolution_at' => cairo('2026-09-17 16:00'), // Thursday, an hour before close
        'completed_at' => cairo('2026-09-20 10:00'),         // Sunday morning
    ]);
    $order->sla_clock = FacilityWorkOrder::SLA_CLOCK_WORKING;
    $order->save();

    // Thursday 16:00→17:00 is one working hour; Friday and Saturday are the weekend; Sunday
    // 09:00→10:00 is one. Two, not the 66 a calendar subtraction reports.
    expect($order->hoursOverSla())->toBe(2)
        // …and it now agrees with the money, which prices the same breach at one working day.
        ->and($order->daysOverSla())->toBe(1);

    // The control: the same order on the calendar clock still reports the full elapsed time, so
    // nothing changes for an operator who never switched the feature on.
    $order->sla_clock = FacilityWorkOrder::SLA_CLOCK_CALENDAR;

    expect($order->hoursOverSla())->toBe(66);
});

it('never charges nothing for a breach that fell entirely inside the weekend', function () {
    // The money bug the design review caught before it shipped, now pinned on a case where the
    // floor actually FIRES. The first version of this used a Thursday-evening deadline — and
    // `workingDaysBetween` counts Thursday as a working day touched, so it returned 1 on its own
    // and the floor was never exercised. Right number, wrong reason.
    expect(WorkingCalendar::workingDaysBetween(cairo('2026-09-18 10:00'), cairo('2026-09-19 10:00')))
        ->toBe(0, 'Friday to Saturday is entirely weekend — this is the input the floor exists for.');

    $order = correctiveOrder([
        'priority' => 'medium',
        'status' => 'done',
        'target_resolution_at' => cairo('2026-09-18 10:00'), // Friday
        'completed_at' => cairo('2026-09-19 10:00'),         // Saturday
    ]);

    // Stamped directly: `sla_clock` is deliberately not fillable — an operator changes the policy,
    // never one job's clock.
    $order->sla_clock = FacilityWorkOrder::SLA_CLOCK_WORKING;
    $order->save();

    // A real breach with no working time in it. Unfloored this computes 0, `BASIS_PER_DAY` charges
    // `0 × rate`, and the row reads "assessed and owed nothing" — while a FLAT-basis penalty would
    // charge in full for the very same breach.
    expect($order->isSlaBreached())->toBeTrue()
        ->and($order->daysOverSla())->toBe(1);
});

it('counts a long overrun in full rather than quietly truncating it', function () {
    // A walk cap on `workingDaysBetween` under-counted a two-year overrun as 287 working days
    // instead of ~520 — and that number MULTIPLIES a per-day SLA penalty, so a long-open breach was
    // silently under-charged. The loop is bounded by its own range; it never needed a cap.
    $days = WorkingCalendar::workingDaysBetween(cairo('2024-01-01 09:00'), cairo('2026-01-01 09:00'));

    // Two years of Sun–Thu is ~520 working days. The assertion is deliberately a range, not a
    // magic number: what matters is that it is not silently clipped at the old 400-iteration bound.
    expect($days)->toBeGreaterThan(500)
        ->and($days)->toBeLessThan(540);
});

it('measures an overrun in the same unit the calendar branch does', function () {
    // The money bug the FIRST review of this feature caught. The working branch counted working
    // days TOUCHED while the calendar branch measures elapsed DURATION — different quantities,
    // quoted against the same `sla_penalties.rate`. An overrun from Sunday 17:00 to Monday 09:00
    // has no working time in it at all but touches two working days, so the option sold as
    // "don't charge a contractor for the weekend" charged an EXTRA day on the ordinary
    // Sunday-to-Thursday overrun.
    $cases = [
        // [from, to, working days, why]
        ['2026-09-20 17:00', '2026-09-21 09:00', 0, 'overnight between two working days is no working time'],
        ['2026-09-20 09:00', '2026-09-23 09:00', 3, 'three full working days'],
        ['2026-09-20 09:00', '2026-09-20 10:00', 1, 'one hour is a part-day, which counts as a day'],
        ['2026-09-17 16:59', '2026-09-20 09:01', 1, 'a minute either side of the weekend is one part-day'],
    ];

    foreach ($cases as [$from, $to, $expected, $why]) {
        expect(WorkingCalendar::workingDaysBetween(cairo($from), cairo($to)))
            ->toBe($expected, "{$from} → {$to}: {$why}");
    }
});

it('keeps the promised clock when a job is accepted', function () {
    // FR-CM-07 re-derives the resolution deadline from the moment of acceptance. Doing that in bare
    // calendar hours discarded the working deadline — and because the working one is always later
    // in wall-clock, the `min()` that follows picked the calendar figure every time, leaving the
    // job stamped `working` while its deadline said otherwise. Two clocks, one penalty.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = ['medium']);
    CarbonImmutable::setTestNow(cairo('2026-09-17 15:00')); // Thursday afternoon

    $order = correctiveOrder(['priority' => 'medium']);

    app(FacilityWorkOrderService::class)->transition($order->fresh(), 'in_progress');

    $accepted = $order->fresh();

    // Asserted against the WORKING computation, not merely "lands on a working day" — the first
    // version of this case checked the latter and passed with the fix reverted, because a short
    // calendar window from Thursday afternoon lands on Thursday either way.
    $expected = WorkingCalendar::addWorkingHours(
        $accepted->acknowledged_at,
        SlaResolver::hoursFor($accepted->asset_id, 'medium'),
        $accepted->asset_id,
    );

    expect($accepted->sla_clock)->toBe(FacilityWorkOrder::SLA_CLOCK_WORKING)
        ->and($accepted->target_resolution_at->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'));
});

it('honours the same setting in module 11, not just module 26', function () {
    // EG-38. `SlaSettings` is shared — its own docblock says so — and the first cut of the working
    // calendar wired module 26 only. An operator ticking `medium` then got two different SLA
    // semantics for the same priority depending on whether the fault arrived as a tenant request or
    // as a work order: the split-brain the maintenance rename was done to end.
    tap(app(SlaSettings::class), function (SlaSettings $s) {
        $s->sla_working_clock_priorities = ['medium'];
        // Pinned, not inherited: the shipped default is 72h, which from a Thursday afternoon lands
        // on SUNDAY — a working day in Egypt either way — so a test written on the default asserts
        // nothing. 24h is the interval that separates the two clocks.
        $s->sla_medium_hours = 24;
    });

    CarbonImmutable::setTestNow(cairo('2026-09-17 15:00')); // Thursday, two hours before close

    // The premise, asserted rather than assumed: on the bare calendar this request falls due inside
    // the weekend, with nobody on site. If a future settings change made that untrue, this test
    // would be measuring nothing and should say so here rather than pass quietly.
    $onTheCalendar = now()->addHours(24);
    expect(WorkingCalendar::isWorkingDay($onTheCalendar, $this->asset->id))->toBeFalse();

    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    // `create()` clamps the unit to the tenant's OWN lease, deliberately, so a crafted payload
    // cannot file against another retailer's shop.
    makeLease($unit, $tenant, ['status' => 'active']);

    $request = app(TenantRequestService::class)->create([
        'unit_id' => $unit->id,
        'request_type' => TenantRequestType::Maintenance->value,
        'priority' => 'medium',
        'title' => 'Air conditioning is dead',
        'description' => 'No cooling in the unit.',
    ], $tenant)->fresh();

    expect($request->sla_clock)->toBe(TenantRequest::SLA_CLOCK_WORKING)
        // The deadline moved off the weekend…
        ->and(WorkingCalendar::isWorkingDay($request->target_resolution_at, $this->asset->id))->toBeTrue()
        // …and moved LATER, which is the half a working-day check alone cannot see: Friday 15:00
        // and the following Tuesday 15:00 are both answers, and only one of them was promised.
        ->and($request->target_resolution_at->greaterThan($onTheCalendar))->toBeTrue();

    // The control. With the setting off — the shipped state — module 11 is byte-identical to the
    // `now()->addHours()` this replaced, so the feature costs nothing until an operator asks for it.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = []);

    expect(app(TenantRequestService::class)->defaultTargetResolution('medium', $this->asset->id)
        ->equalTo(now()->addHours(24)))->toBeTrue();
});

it('will not let the party raising a request choose its own clock', function () {
    // `sla_clock` is fillable on TenantRequest (it cannot be guarded — the admin road is a Filament
    // CreateRecord, which would silently drop a non-fillable key). What keeps it safe is that both
    // writers set it themselves. This pins the portal/API road: the service builds an explicit
    // whitelist and never spreads the client payload, so a crafted submit asking for the working
    // clock — a materially later deadline — is ignored rather than honoured.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = []);

    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    $unit = makeUnit($this->asset);
    makeLease($unit, $tenant, ['status' => 'active']);

    $request = app(TenantRequestService::class)->create([
        'unit_id' => $unit->id,
        'request_type' => TenantRequestType::Maintenance->value,
        'priority' => 'medium',
        'title' => 'Air conditioning is dead',
        'description' => 'No cooling in the unit.',
        'sla_clock' => TenantRequest::SLA_CLOCK_WORKING, // the crafted field
    ], $tenant)->fresh();

    expect($request->sla_clock)->toBe(TenantRequest::SLA_CLOCK_CALENDAR);
});

it('measures a tenant request\'s overrun on the clock it was promised on', function () {
    // The bell entry quotes this number. A request promised on the working clock, breached Thursday
    // evening and read on Sunday morning is not "62 hours late" — the mall was shut for two of
    // them. That figure tells the operator the failure is an order of magnitude worse than it is.
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    $unit = makeUnit($this->asset);

    // Both sides of the subtraction must agree on what "16:00" means. The suite pins the process to
    // UTC, and Laravel's `datetime` cast reads a stored wall clock in the PROCESS timezone — so a
    // Cairo 16:00 comes back out of the model as 16:00 UTC, three hours adrift, while `now()` stays
    // honest. That is a property of the test harness, not of the code: production runs Cairo end to
    // end (`config/app.php`) and the two agree. Pinning the process here reproduces production;
    // `afterEach` puts UTC back.
    date_default_timezone_set('Africa/Cairo');

    $due = cairo('2026-09-17 16:00'); // Thursday, an hour before close

    $request = TenantRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => $due,
        'sla_clock' => TenantRequest::SLA_CLOCK_WORKING,
    ]);

    CarbonImmutable::setTestNow(cairo('2026-09-20 11:00')); // Sunday morning

    // Thursday 16:00→17:00 is one working hour; Friday and Saturday are the weekend; Sunday
    // 09:00→11:00 is two. Three, not the 67 a calendar subtraction reports.
    expect($request->hoursOverSla())->toBe(3);

    // The control: the same request on the calendar clock — the shipped default — still reports the
    // full elapsed time, so this changes nothing for an operator who never turned the feature on.
    $request->sla_clock = TenantRequest::SLA_CLOCK_CALENDAR;
    expect($request->hoursOverSla())->toBe(67);

    // And a request inside its window reports nothing rather than a negative.
    CarbonImmutable::setTestNow(cairo('2026-09-17 10:00'));
    expect($request->hoursOverSla())->toBe(0);
});

it('clamps a working week somebody emptied back to Egypt rather than stopping the clock', function () {
    // An empty week would mean no work is ever done, and every deadline would walk to the fallback.
    tap(app(CalendarSettings::class), fn (CalendarSettings $c) => $c->working_days = []);

    expect(WorkingCalendar::workingDays())->toBe(CalendarSettings::EGYPTIAN_WEEK);

    // And the string round-trip a CheckboxList produces resolves to the same ints — `in_array`
    // with strict comparison against '7' would otherwise match nothing and close the mall.
    tap(app(CalendarSettings::class), fn (CalendarSettings $c) => $c->working_days = ['7', '1', '2', '3', '4']);

    expect(WorkingCalendar::workingDays())->toBe([7, 1, 2, 3, 4])
        ->and(WorkingCalendar::isWorkingDay(cairo('2026-09-20')))->toBeTrue();
});

it('still raises a request on a box whose settings store cannot be read', function () {
    // `TenantRequestService::defaultTargetResolution()` has wrapped its settings read in a
    // try/catch since audit M09 F-36, so a deploy without settings rows still produces a sensible
    // deadline from `config/sla.php`. EG-38 routed `SlaResolver::clockFor()` — an UNGUARDED
    // `app(SlaSettings::class)` — in front of it, and re-stated that guarantee in a comment while
    // breaking it. Tenant-request creation then 500'd on exactly the boxes the guard exists for:
    // a fresh install before `atriom:install`, and the `reset.sh` restore-without-migrating path
    // this work's own deployment note describes. It fired with the feature switched OFF.
    app()->bind(SlaSettings::class, function () {
        throw new RuntimeException('settings unavailable');
    });

    $clock = SlaResolver::clockFor($this->asset->id, 'medium');

    // The calendar: it is what predates the setting, so an unreadable store behaves as an empty one.
    expect($clock)->toBe(SlaResolver::CLOCK_CALENDAR);

    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    makeLease($unit, $tenant, ['status' => 'active']);

    $request = app(TenantRequestService::class)->create([
        'unit_id' => $unit->id,
        'request_type' => TenantRequestType::Maintenance->value,
        'priority' => 'medium',
        'title' => 'Air conditioning is dead',
        'description' => 'No cooling in the unit.',
    ], $tenant);

    // It exists, and it carries the config fallback deadline rather than nothing.
    expect($request->exists)->toBeTrue()
        ->and($request->target_resolution_at)->not->toBeNull();
});

it('never tells an operator a breached request is 0 hours late', function () {
    // A request promised on the working clock, breached Thursday evening and read on Saturday, has
    // no working time in the overrun at all — so the honest subtraction is 0 and the bell read
    // "is 0 h past its target resolution" on a request that IS late. `daysOverSla()` floors at 1
    // for exactly this input, with a comment saying why; module 11 took the opposite decision
    // silently. A breach is a breach.
    date_default_timezone_set('Africa/Cairo');

    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    $unit = makeUnit($this->asset);

    $request = TenantRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'in_progress',
        'target_resolution_at' => cairo('2026-09-17 17:00'), // Thursday, at close
        'sla_clock' => TenantRequest::SLA_CLOCK_WORKING,
    ]);

    CarbonImmutable::setTestNow(cairo('2026-09-19 12:00')); // Saturday — still the weekend

    expect($request->isOverdue())->toBeTrue('The premise: this request really is late.')
        ->and($request->hoursOverSla())->toBe(1);

    // The control: a request INSIDE its window still reports nothing, so the floor did not turn
    // every request into a breach.
    CarbonImmutable::setTestNow(cairo('2026-09-17 10:00'));
    expect($request->isOverdue())->toBeFalse()
        ->and($request->hoursOverSla())->toBe(0);
});

it('stamps no clock on a request type that carries no SLA', function () {
    // A clock is what a deadline is measured against. `inquiry` and `billing` have no deadline at
    // all — `targetResolutionFor()` returns null — so a clock on those rows is a claim about
    // nothing. Module 26 leaves it null on a preventive order for the same reason.
    tap(app(SlaSettings::class), fn (SlaSettings $s) => $s->sla_working_clock_priorities = ['medium']);

    $unit = makeUnit($this->asset);
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    makeLease($unit, $tenant, ['status' => 'active']);

    $inquiry = app(TenantRequestService::class)->create([
        'unit_id' => $unit->id,
        'request_type' => TenantRequestType::Inquiry->value,
        'priority' => 'medium',
        'title' => 'When does the mall open on Eid?',
        'description' => 'Asking about holiday hours.',
    ], $tenant)->fresh();

    expect($inquiry->target_resolution_at)->toBeNull()
        ->and($inquiry->sla_clock)->toBeNull();

    // The control: a type that DOES carry an SLA gets both, under the same settings.
    $maintenance = app(TenantRequestService::class)->create([
        'unit_id' => $unit->id,
        'request_type' => TenantRequestType::Maintenance->value,
        'priority' => 'medium',
        'title' => 'Air conditioning is dead',
        'description' => 'No cooling in the unit.',
    ], $tenant)->fresh();

    expect($maintenance->target_resolution_at)->not->toBeNull()
        ->and($maintenance->sla_clock)->toBe(TenantRequest::SLA_CLOCK_WORKING);
});

it('freezes the clock on a closed request, alongside the deadline it measures', function () {
    // `target_resolution_at` was already frozen on a terminal request; `sla_clock` was not — so
    // what the deadline IS could not be changed while what it is measured AGAINST could. Half a
    // rule.
    $tenant = makeTenant(['asset_id' => $this->asset->id]);
    $unit = makeUnit($this->asset);

    $request = TenantRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'unit_id' => $unit->id,
        'status' => 'closed',
        'target_resolution_at' => cairo('2026-09-17 17:00'),
        'sla_clock' => TenantRequest::SLA_CLOCK_CALENDAR,
    ]);

    $request->sla_clock = TenantRequest::SLA_CLOCK_WORKING;

    // Refused outright rather than silently reverted — the same treatment the frozen deadline gets.
    expect(fn () => $request->save())->toThrow(DomainException::class);
    expect($request->fresh()->sla_clock)->toBe(TenantRequest::SLA_CLOCK_CALENDAR);

    // The control: a field that is NOT frozen still saves on a closed request, so the refusal above
    // is about this column and not about the record being closed to everything.
    $request->refresh();
    $request->csat_comment = 'Handled well in the end.';
    $request->save();

    expect($request->fresh()->csat_comment)->toBe('Handled well in the end.');
});
