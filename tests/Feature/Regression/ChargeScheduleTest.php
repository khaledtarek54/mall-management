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

    // Inserted through the QUERY BUILDER, deliberately: Charge::saving() now refuses an
    // overlapping row, so the only way this state arises is the way the billing guard exists for —
    // a direct SQL write, a bad import, or a migration. The write guard is the first line; this is
    // the backstop for data that arrived another way.
    \Illuminate\Support\Facades\DB::table('charges')->insert([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_MANUAL, 'amount' => 12000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-01-01', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
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

/* ---- LS-01: the whole term, written at signing ----------------------------- */

it('writes the whole term\'s contracted rent steps when the lease is created', function () {
    CarbonImmutable::setTestNow('2026-01-05');

    $lease = app(\App\Services\LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 60,
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 0,
            'escalation_type' => 'fixed_percent',
            'escalation_rate' => 7,
            'has_marketing_levy' => false,
        ],
    ])->fresh();

    // Five-year term, 7% a year: the ladder exists the day it is signed — nobody waits for a
    // nightly job to reveal 2030's rent.
    expect(rentSchedule($lease)->map(fn ($c) => (float) $c->amount)->all())
        ->toBe([100000.0, 107000.0, 114490.0, 122504.3, 131079.6]);

    $rows = rentSchedule($lease);
    expect($rows[1]->start_date->toDateString())->toBe('2027-01-01')
        ->and($rows[0]->end_date->toDateString())->toBe('2026-12-31')
        ->and($rows[1]->origin)->toBe(Charge::ORIGIN_ESCALATION)
        // The last step still starts inside the term, and the term-end row stays open.
        ->and($rows[4]->start_date->toDateString())->toBe('2030-01-01');
});

it('bills each year of a projected term at its own contracted rent, with no sweep run at all', function () {
    CarbonImmutable::setTestNow('2026-01-05');

    $lease = app(\App\Services\LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 36,
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 0,
            'escalation_type' => 'fixed_percent',
            'escalation_rate' => 7,
            'has_marketing_levy' => false,
        ],
    ])->fresh();

    $billing = app(MonthlyBillingService::class);

    expect((float) $billing->generateForLease($lease, CarbonImmutable::parse('2026-06-01'))['invoice']
        ->items()->where('type', 'base_rent')->sole()->amount)->toBe(100000.0)
        ->and((float) $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2027-06-01'))['invoice']
            ->items()->where('type', 'base_rent')->sole()->amount)->toBe(107000.0)
        ->and((float) $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2028-06-01'))['invoice']
            ->items()->where('type', 'base_rent')->sole()->amount)->toBe(114490.0);
});

it('does not fight the escalation sweep — a projected lease and a swept one converge', function () {
    // THE interaction risk of writing the ladder up front: the sweep still runs every anniversary.
    // It recomputes the same amount, setAmount() finds it already in force, and no row is added.
    // What the sweep keeps doing is advancing base_rent_monthly and next_escalation_date.
    CarbonImmutable::setTestNow('2026-01-05');

    $lease = app(\App\Services\LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 36,
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 0,
            'escalation_type' => 'fixed_percent',
            'escalation_rate' => 7,
            'has_marketing_levy' => false,
        ],
    ])->fresh();

    $before = rentSchedule($lease)->count();

    CarbonImmutable::setTestNow('2027-01-02');
    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2027-01-02'));

    expect(rentSchedule($lease->fresh())->count())->toBe($before)          // no duplicate row
        ->and((float) $lease->fresh()->base_rent_monthly)->toBe(107000.0)  // …but the rent in force moved
        ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2028-01-01');
});

it('projects the marketing levy in lock-step so a future month is internally consistent', function () {
    CarbonImmutable::setTestNow('2026-01-05');

    $lease = app(\App\Services\LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 24,
            'base_rent_monthly' => 100000,
            'service_charge_monthly' => 0,
            'escalation_type' => 'fixed_percent',
            'escalation_rate' => 10,
            'has_marketing_levy' => true,
            'marketing_levy_rate' => 5,
        ],
    ])->fresh();

    // Billing 2027 must charge 2027's rent beside 2027's levy — not year-one's levy.
    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2027-06-01'))['invoice'];

    expect((float) $invoice->items()->where('type', 'base_rent')->sole()->amount)->toBe(110000.0)
        ->and((float) $invoice->items()->where('type', 'marketing')->sole()->amount)->toBe(5500.0);
});

it('projects nothing for CPI, because there is no index to project from', function () {
    CarbonImmutable::setTestNow('2026-01-05');

    // Built directly rather than through LeaseCreationService, which HARD-CODES
    // escalation_type => 'fixed_percent' (LeaseCreationService.php:70) and ignores whatever the
    // caller passes — so a CPI lease can only exist by editing one after creation. Worth knowing;
    // it is not this change's to fix.
    $lease = scheduledLease([
        'escalation_type' => 'cpi',
        'escalation_rate' => 7,
        'expiry_date' => '2030-12-31',
    ], 100000);

    // Inventing a CPI step here would be inventing data — the same reason the sweep skips CPI.
    expect(app(ChargeScheduleService::class)->projectTermEscalations($lease))->toBe(0)
        ->and(rentSchedule($lease->fresh()))->toHaveCount(1);
});

it('projects nothing when the lease has no escalation configured', function () {
    CarbonImmutable::setTestNow('2026-01-05');
    $lease = scheduledLease(['escalation_type' => 'none', 'escalation_rate' => 0]);

    expect(app(ChargeScheduleService::class)->projectTermEscalations($lease))->toBe(0)
        ->and(rentSchedule($lease->fresh()))->toHaveCount(1);
});

/* ---- the write-time guard (LS-01's actual acceptance criterion) ------------- */

it('refuses an overlapping schedule row at the keystroke, not at bill time', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();   // base_rent 2026-01-01 → open-ended

    // Billing already refused a month covered by two rows, but that is the last possible moment to
    // find out and it fails a whole lease's invoice run. LS-01 asked for the refusal at write time.
    expect(fn () => Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_MANUAL, 'amount' => 12000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
        'start_date' => '2026-03-01', 'is_active' => true,
    ]))->toThrow(DomainException::class);

    expect(rentSchedule($lease->fresh()))->toHaveCount(1);
});

it('allows adjacent rows, which is exactly what a schedule is', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    // The service's own close-then-open must not trip its own guard.
    app(LeaseRentChangeService::class)->apply($lease, ['base_rent_monthly' => 12000]);

    expect(rentSchedule($lease->fresh()))->toHaveCount(2);
});

it('still lets several one-off charges share a month', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();

    // A CAM true-up, a percentage-rent overage and a utility recharge genuinely coincide — they
    // are not a schedule, so the overlap guard must not touch them.
    foreach ([500, 700] as $amount) {
        Charge::create([
            'lease_id' => $lease->id, 'name' => 'One-off', 'type' => 'other',
            'origin' => Charge::ORIGIN_MANUAL, 'amount' => $amount, 'currency' => 'EGP',
            'frequency' => 'one_time', 'vat_applicable' => false, 'vat_rate' => Vat::EXEMPT,
            'start_date' => '2026-06-05', 'is_active' => true,
        ]);
    }

    expect(Charge::where('lease_id', $lease->id)->where('type', 'other')->count())->toBe(2);
});

it('does not block re-saving a row against itself', function () {
    CarbonImmutable::setTestNow('2026-06-10');
    $lease = scheduledLease();
    $row = rentSchedule($lease)->first();

    $row->update(['name' => 'Base Rent (renamed)']);

    expect($row->fresh()->name)->toBe('Base Rent (renamed)');
});
