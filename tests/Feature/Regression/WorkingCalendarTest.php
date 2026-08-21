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
| **The working clock ships OFF.** `SlaSettings::sla_working_clock_priorities` is empty by default,
| which is the behaviour that predates the calendar. Whether a given priority is office work or a
| round-the-clock promise is the operator's ruling — so these tests switch it on for the priority
| under test and, crucially, prove the default path is untouched.
*/

use App\Models\FacilityWorkOrder;
use App\Models\Holiday;
use App\Settings\CalendarSettings;
use App\Settings\SlaSettings;
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

afterEach(fn () => CarbonImmutable::setTestNow());

/** A corrective order at this property, which is the only kind that carries an SLA. */
function correctiveOrder(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Fix compressor',
        'description' => 'Compressor fault.',
        'trade_id' => tradeId('hvac'),
        'scheduled_for' => '2026-09-17',
    ], $attrs));
}

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
});

it('never charges nothing for a breach that fell across the weekend', function () {
    // The money bug the design review caught before it shipped. Deadline Thursday 23:00, finished
    // Saturday 10:00: a real breach with no working time in it. Measured naively that is 0 working
    // days, `BASIS_PER_DAY` computes `0 × rate = 0`, and the row reads "assessed and owed nothing"
    // — while BASIS_FLAT would still charge in full for the same breach.
    $order = correctiveOrder([
        'priority' => 'medium',
        'status' => 'completed',
        'target_resolution_at' => cairo('2026-09-17 23:00'), // Thursday, after close
        'completed_at' => cairo('2026-09-19 10:00'),         // Saturday
    ]);

    // Stamped directly: `sla_clock` is deliberately not fillable — an operator changes the policy,
    // never one job's clock.
    $order->sla_clock = FacilityWorkOrder::SLA_CLOCK_WORKING;
    $order->save();

    expect($order->isSlaBreached())->toBeTrue()
        ->and($order->daysOverSla())->toBe(1);
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
