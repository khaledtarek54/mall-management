<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseRentChangeService;
use App\Services\MonthlyBillingService;
use App\Services\RentEscalationService;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * The rent is a SCHEDULE, not a mutable amount (phase 1, stories LS-01/02/03 —
 * docs/benchmarks/yardi/07-phase-plan.md).
 *
 * A rent change now closes the row in force and opens the next one, so:
 *   - what the rent WAS stays readable and re-billable,
 *   - what it WILL BE can be written before it bills,
 *   - the escalation sweep stops destroying the row it increases.
 *
 * The two things that can go catastrophically wrong here are both about **billing the same rent
 * twice**: a renewal copying every historical row onto the new lease, and two rows covering one
 * month. Both are pinned below, because the failure is silent money.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function scheduledLease(array $attrs = [], float $rent = 10000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => $rent,
        'has_marketing_levy' => false,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $rent, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    return $lease->fresh();
}

function rentSchedule(Lease $lease)
{
    return Charge::where('lease_id', $lease->id)->where('type', 'base_rent')
        ->orderBy('start_date')->orderBy('id')->get();
}

/* ---- the core behaviour ---------------------------------------------------- */

it('closes the row in force and opens the next instead of overwriting the amount', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]);

    $schedule = rentSchedule($lease->fresh());

    expect($schedule)->toHaveCount(2)
        ->and((float) $schedule[0]->amount)->toBe(10000.0)
        ->and($schedule[0]->start_date->toDateString())->toBe('2026-01-01')
        ->and($schedule[0]->end_date->toDateString())->toBe('2026-05-31')
        ->and((float) $schedule[1]->amount)->toBe(12000.0)
        ->and($schedule[1]->start_date->toDateString())->toBe('2026-06-01')
        ->and($schedule[1]->end_date)->toBeNull()
        // Both stay ACTIVE: the closed row is history, not a deactivated charge. Billing filters
        // it out by DATE, which is what lets a past month still bill it.
        ->and($schedule[0]->is_active)->toBeTrue();
});

it('bills each month at the rent that was in force in that month', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();
    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]);

    $billing = app(MonthlyBillingService::class);
    $lease = $lease->fresh();

    $may = $billing->generateForLease($lease, CarbonImmutable::parse('2026-05-01'));
    $june = $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-06-01'));

    expect((float) $may['invoice']->items()->where('type', 'base_rent')->sole()->amount)->toBe(10000.0)
        ->and((float) $june['invoice']->items()->where('type', 'base_rent')->sole()->amount)->toBe(12000.0);
});

it('does not litter the schedule when the amount has not actually changed', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 10000]);
    app(LeaseRentChangeService::class)->apply($lease->fresh(), ['base_rent_monthly' => 10000]);

    expect(rentSchedule($lease->fresh()))->toHaveCount(1);
});

it('amends a not-yet-started row in place rather than leaving a stub behind', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    // Schedule a change for August, then correct it before it starts.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 12000, 'effective_from' => '2026-08-01',
    ]);
    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 12500, 'effective_from' => '2026-08-01',
    ]);

    $schedule = rentSchedule($lease->fresh());
    expect($schedule)->toHaveCount(2)
        ->and((float) $schedule[1]->amount)->toBe(12500.0)
        ->and($schedule[1]->start_date->toDateString())->toBe('2026-08-01');
});

it('snaps an effective date to the billing month so no month is ever split', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    // Mid-month effective date: the engine bills one amount per type per month, so the row must
    // start on the 1st. This also reproduces the old overwrite behaviour exactly — a mid-month
    // change always billed that whole month at the new rent.
    app(LeaseRentChangeService::class)->apply($lease, [
        'base_rent_monthly' => 12000, 'effective_from' => '2026-07-17',
    ]);

    $schedule = rentSchedule($lease->fresh());
    expect($schedule[1]->start_date->toDateString())->toBe('2026-07-01')
        ->and($schedule[0]->end_date->toDateString())->toBe('2026-06-30');
});

/* ---- escalation ------------------------------------------------------------ */

it('escalation appends the next row and leaves last year readable', function () {
    CarbonImmutable::setTestNow('2027-01-05');
    $lease = scheduledLease([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'next_escalation_date' => '2027-01-01',
    ]);

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-05'));

    $schedule = rentSchedule($lease->fresh());

    expect($schedule)->toHaveCount(2)
        ->and((float) $schedule[0]->amount)->toBe(10000.0)   // 2026 is still there
        ->and((float) $schedule[1]->amount)->toBe(10700.0)
        ->and($schedule[1]->origin)->toBe(Charge::ORIGIN_ESCALATION)
        // Dated to the contractual anniversary, NOT the night the sweep happened to run.
        ->and($schedule[1]->start_date->toDateString())->toBe('2027-01-01')
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(10700.0);
});

it('two escalations leave three rows, not one overwritten amount', function () {
    CarbonImmutable::setTestNow('2027-01-05');
    $lease = scheduledLease([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
        'next_escalation_date' => '2027-01-01',
    ]);

    $sweep = app(RentEscalationService::class);
    $sweep->runForToday(CarbonImmutable::parse('2027-01-05'));
    CarbonImmutable::setTestNow('2028-01-05');
    $sweep->runForToday(CarbonImmutable::parse('2028-01-05'));

    expect(rentSchedule($lease->fresh())->pluck('amount')->map(fn ($a) => (float) $a)->all())
        ->toBe([10000.0, 11000.0, 12100.0]);
});

/* ---- the double-billing traps ---------------------------------------------- */

it('carries only the row in force onto a renewal, never the whole history', function () {
    // THE trap: LeaseRenewalService iterated every active recurring charge. With a schedule that
    // is three rent rows, all re-dated to the renewal commencement and all open-ended — three
    // overlapping rent charges billing the tenant three times a month.
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease(['expiry_date' => '2026-12-31']);

    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 11000]);
    app(LeaseRentChangeService::class)->apply($lease->fresh(), [
        'base_rent_monthly' => 12000, 'effective_from' => '2026-09-01',
    ]);
    expect(rentSchedule($lease->fresh()))->toHaveCount(3);

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), [
        'new_term_months' => 12,
        'new_rent' => 13000,
    ]);

    $renewalRent = rentSchedule($renewal);
    expect($renewalRent)->toHaveCount(1)
        ->and((float) $renewalRent[0]->amount)->toBe(13000.0);

    // And it actually bills once.
    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($renewal->fresh(), CarbonImmutable::parse('2027-02-01'))['invoice'];
    expect($invoice->items()->where('type', 'base_rent')->count())->toBe(1)
        ->and((float) $invoice->items()->where('type', 'base_rent')->sole()->amount)->toBe(13000.0);
});

it('refuses to bill a month covered by two rows instead of silently double-charging', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    // A hand-edited date, a bad import, or a bug in something that writes the schedule: two rows
    // both cover June. The old single-row world could not express this; this one can, and left
    // unguarded it puts TWO rent lines on one invoice.
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_MANUAL, 'amount' => 12000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-06-01'));

    expect($result['status'])->toBe('failed')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);
});

it('lets several one-off charges land in the same month — they are not a schedule', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    foreach ([500, 700] as $amount) {
        Charge::create([
            'lease_id' => $lease->id, 'name' => 'CAM true-up', 'type' => 'other',
            'origin' => Charge::ORIGIN_MANUAL, 'amount' => $amount, 'currency' => 'EGP',
            'frequency' => 'one_time', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
            'start_date' => '2026-06-05', 'is_active' => true,
        ]);
    }

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-06-01'));

    expect($result['status'])->toBe('created')
        ->and($result['invoice']->items()->where('type', 'other')->count())->toBe(2);
});

/* ---- the levy follows the rent --------------------------------------------- */

it('moves the marketing levy on the same effective date as the rent it derives from', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease(['has_marketing_levy' => true, 'marketing_levy_rate' => 5]);
    app(\App\Services\MarketingLevyService::class)->createLevyCharge($lease->fresh());

    app(LeaseRentChangeService::class)->apply($lease->fresh(), ['base_rent_monthly' => 20000]);

    $levy = Charge::where('lease_id', $lease->id)->where('type', 'marketing')
        ->orderBy('start_date')->orderBy('id')->get();

    // A past month must bill the levy that was in force then, beside the rent that was in force
    // then — otherwise the schedule fixes the rent and leaves the levy inconsistent with it.
    expect($levy->last()->start_date->toDateString())->toBe('2026-06-01')
        ->and((float) $levy->last()->amount)->toBe(1000.0)
        ->and((float) $levy->first()->amount)->toBe(500.0);
});

/* ---- provenance ------------------------------------------------------------ */

it('records where every row came from', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();
    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 11000]);

    expect(rentSchedule($lease->fresh())->pluck('origin')->all())
        ->toBe([Charge::ORIGIN_SEED, Charge::ORIGIN_MANUAL]);
});

it('reads the row in force on a date', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();
    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]);

    $schedule = app(ChargeScheduleService::class);
    $lease = $lease->fresh();

    expect((float) $schedule->rowInForce($lease, 'base_rent', CarbonImmutable::parse('2026-03-15'))->amount)->toBe(10000.0)
        ->and((float) $schedule->rowInForce($lease, 'base_rent', CarbonImmutable::parse('2026-06-15'))->amount)->toBe(12000.0)
        ->and((float) $schedule->rowInForce($lease, 'base_rent', CarbonImmutable::parse('2030-01-01'))->amount)->toBe(12000.0);
});
