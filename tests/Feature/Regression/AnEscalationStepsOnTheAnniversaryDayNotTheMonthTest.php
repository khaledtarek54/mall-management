<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Services\ChargeScheduleService;
use App\Services\CreditUnearnedBillingService;
use App\Services\LeaseRentChangeService;
use App\Services\MonthlyBillingService;
use App\Services\RentEscalationService;
use App\Services\StraightLineRentService;
use App\Support\ChargeEscalation;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LeaseLadder;

/**
 * An escalation steps ON the anniversary, and the month it falls in is billed at two figures —
 * the days before at the outgoing rent, the days from it at the new (Trello gzwI17R0, High,
 * 2026-09-13).
 *
 * Every schedule write snapped its date to the 1st (`ChargeScheduleService::billingBoundary()`,
 * applied inside `setAmount()`), because the planner billed one amount per charge type per month
 * and a row starting mid-month would have left the month covered by two rows. So a lease
 * commencing on the 10th stepped its rent from the 1st of the anniversary month — nine days early,
 * at every anniversary, and a third rung was minted for a 36-month term whose third anniversary
 * fell the day AFTER expiry. The market's charge schedule is date-ranged and the posting run
 * prorates the partial months of a row (benchmark 01 §3.2–3.3), which is what the tester asked for.
 *
 * Three seams. `setAmount()` takes the day it is given; the anniversary walk and the sweep pass
 * the anniversary, and a TYPED act (Change Rent, Add charge, an import, a CAM estimate) snaps at
 * its own door, because its screen says "the month". `MonthlyBillingService::lineWindow()` bills
 * each row for the days it is in force inside the window, and two contiguous rows of one type
 * are a split, not a clash. And the move-out credit apportions each line on the window it
 * recorded (`invoice_items.covered_start/end`), so a split month is credited on the right rung.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'ANN']);
    $this->travelTo(CarbonImmutable::parse('2026-09-10'));
});

/** One month's invoice for the lease, as `type amount (covered_start..covered_end)` lines in schedule order. */
function anniversaryMonthLines(\App\Models\Lease $lease, string $month): array
{
    $result = app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse($month), prorate: true);

    expect($result['status'])->toBe('created', 'reason: '.($result['reason'] ?? 'none'));

    return $result['invoice']->items()->orderBy('type')->orderBy('covered_start')->get()
        ->map(fn ($i) => sprintf('%s %s (%s..%s)%s', $i->type, number_format((float) $i->amount, 2, '.', ''), $i->covered_start->toDateString(), $i->covered_end->toDateString(), str_contains($i->description, 'pro-rated') ? ' pro-rated' : ''))
        ->all();
}

it('dates every rung on the anniversary, and mints no step the term never reaches', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);

        // The tester's lease: 36 months from the 10th. Two anniversaries fall inside the term;
        // the third (2029-09-10) is the day after the 2029-09-09 expiry — no rung, where the snap
        // minted one for the whole of that final month.
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..2028-09-09 1210@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toBe('50@2026-09-10..2027-09-09 55@2027-09-10..2028-09-09 61@2028-09-10..open');

        // An anniversary INSIDE the final month is reached and steps: expiry on the 30th.
        $longer = LeaseLadder::testersLease($this->asset, expiry: '2029-09-30');
        expect(LeaseLadder::rungsByDay($longer, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..2028-09-09 1210@2028-09-10..2029-09-09 1331@2029-09-10..open');
    });
});

it('bills the anniversary month at two figures, each for its own days, and does not call the split a clash', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-09-01'));

        // The forecast is the same planner: the month before and after are one line each, at the
        // outgoing and the new rent, and the anniversary month is two.
        $plan = fn (string $m) => collect(app(MonthlyBillingService::class)->planInvoiceForLease($lease->fresh(), CarbonImmutable::parse($m), CarbonImmutable::parse($m)->endOfMonth(), true)['items'])
            ->where('type', 'base_rent')->sortBy('covered_start')->pluck('amount')->values()->all();
        expect($plan('2027-08-01'))->toBe([1000.0])
            ->and($plan('2027-09-01'))->toBe([300.0, 770.0])
            ->and($plan('2027-10-01'))->toBe([1100.0]);

        // 1,000 × 9/30 + 1,100 × 21/30 = 300 + 770; the levy at 5 % of each; the service charge,
        // stepping nothing, one line for the month.
        expect(anniversaryMonthLines($lease, '2027-09-01'))->toBe([
            'base_rent 300.00 (2027-09-01..2027-09-09) pro-rated',
            'base_rent 770.00 (2027-09-10..2027-09-30) pro-rated',
            'marketing 15.00 (2027-09-01..2027-09-09) pro-rated',
            'marketing 38.50 (2027-09-10..2027-09-30) pro-rated',
            'service_charge 250.00 (2027-09-01..2027-09-30)',
        ]);
    });
});

it('is applied by the nightly sweep on the anniversary day — a projected rung stands, an unprojected one is opened on the day', function () {
    asTenant($this->asset, function () {
        // The rent's step — and a service charge FOLLOWING the clause — ride through
        // `LeaseRentChangeService`; a charge on its OWN rule is the sweep's own write, on the
        // anniversary it resolved. One of each, so both doors are on the day.
        $lease = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setEscalation($lease, 'service_charge', ChargeEscalation::PERCENT, 10);
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();

        expect((float) $lease->fresh()->base_rent_monthly)->toBe(1100.0)
            ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2028-09-10')
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-09-10..2027-09-09 1100@2027-09-10..2028-09-09')
            ->and(LeaseLadder::rungsByDay($lease, 'service_charge'))->toStartWith('250@2026-09-10..2027-09-09 275@2027-09-10..2028-09-09');

        // A lease with NO projected ladder (a single open row per type, the pre-projection
        // shape): the sweep closes each on the eve and opens the step on the day.
        $legacy = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setEscalation($legacy, 'service_charge', ChargeEscalation::PERCENT, 10);
        $legacy->charges()->where('origin', '!=', Charge::ORIGIN_SEED)->update(['is_active' => false]);
        $legacy->charges()->where('origin', Charge::ORIGIN_SEED)->update(['end_date' => null]);
        app(RentEscalationService::class)->runForToday();

        expect(LeaseLadder::rungsByDay($legacy, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..open')
            ->and(LeaseLadder::rungsByDay($legacy, 'service_charge'))->toBe('250@2026-09-10..2027-09-09 275@2027-09-10..open');
    });
});

it('splits a QUARTERLY cycle the same way — whole months at each rung, the split month by days', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $lease->forceFill(['billing_frequency' => 'quarterly'])->save();
        $this->travelTo(CarbonImmutable::parse('2027-09-01'));

        // Sep–Nov 2027 in advance: 9 days of September at 1,000, then 21 days + October + November
        // at 1,100 (2.7 months).
        $lines = collect(anniversaryMonthLines($lease, '2027-09-01'))->filter(fn ($l) => str_starts_with($l, 'base_rent'))->values()->all();
        expect($lines)->toBe([
            'base_rent 300.00 (2027-09-01..2027-09-09) pro-rated',
            'base_rent 2970.00 (2027-09-10..2027-11-30) pro-rated',
        ]);
    });
});

it('lets a charge that does not prorate yield the split month whole to its new figure', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        // A flat signage licence, payable in full for any month it runs into, stepping 10 % by
        // its own rule.
        app(ChargeScheduleService::class)->setAmount($lease, 'other', 600, CarbonImmutable::parse('2026-09-10'), [
            'name' => 'Signage licence', 'frequency' => 'monthly', 'prorate' => false,
            'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 10,
        ]);
        app(ChargeScheduleService::class)->retrueProjectedLadder($lease->fresh());
        expect(LeaseLadder::rungsByDay($lease, 'other'))->toStartWith('600@2026-09-10..2027-09-09 660@2027-09-10');

        $this->travelTo(CarbonImmutable::parse('2027-09-01'));
        $lines = collect(anniversaryMonthLines($lease, '2027-09-01'))->filter(fn ($l) => str_starts_with($l, 'other'))->values()->all();

        // ONE line, the new figure, the whole month — never 600 + 660 for one September.
        expect($lines)->toBe(['other 660.00 (2027-09-10..2027-09-30)']);
    });
});

it('credits a move-out inside the split month against the rung that was in force, on its own days', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-09-01'));
        anniversaryMonthLines($lease, '2027-09-01');
        $invoice = Invoice::query()->where('lease_id', $lease->id)->whereDate('period_start', '2027-09-01')->sole();

        // Leaving on the 20th: the 1–9 line is fully earned; the 10–30 line gives back 10 of its
        // 21 days — 770 × 10/21 = 366.67 rent, 38.50 × 10/21 = 18.33 levy, 250 × 10/30 = 83.33
        // service charge.
        $notes = app(CreditUnearnedBillingService::class)->forTermination($lease->fresh(), CarbonImmutable::parse('2027-09-20'));

        expect($notes)->toHaveCount(1)
            ->and($notes[0]->items->mapWithKeys(fn ($i) => [$i->type => round((float) $i->amount, 2)])->sortKeys()->all())
            ->toBe(['base_rent' => 366.67, 'marketing' => 18.33, 'service_charge' => 83.33]);
    });
});

it('lands a typed act on the day typed — a rent change on the 15th bills the 15th onward, and the month in two parts', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-03-01'));

        app(LeaseRentChangeService::class)->apply($lease->fresh(), [
            'base_rent_monthly' => 1500, 'effective_from' => '2027-03-15', 'reason' => 'Renegotiated.',
        ]);

        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-09-10..2027-03-14 1500@2027-03-15..2027-09-09 1650@2027-09-10')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toStartWith('50@2026-09-10..2027-03-14 75@2027-03-15..2027-09-09');

        $lines = collect(anniversaryMonthLines($lease, '2027-03-01'))->filter(fn ($l) => str_starts_with($l, 'base_rent'))->values()->all();
        expect($lines)->toBe(['base_rent 451.61 (2027-03-01..2027-03-14) pro-rated', 'base_rent 822.58 (2027-03-15..2027-03-31) pro-rated']);
    });
});

it('lets a stated figure supersede the projected rungs that started after its date — typed after the step, or back-dated across it', function () {
    // REVIEW FINDING: with the rung on the 10th and a typed act on the 1st, a Change Rent typed on
    // the 15th landed BEFORE the started rung and the rung billed the rest of the year (the
    // column said 1,500, the schedule 1,100). A stated figure takes the place of every projected
    // rung derived from the figure it restates.
    asTenant($this->asset, function () {
        // Typed after the anniversary, effective today: the started rung is closed on the eve.
        $lease = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setEscalation($lease, 'service_charge', ChargeEscalation::FOLLOWS_LEASE);
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();
        $this->travelTo(CarbonImmutable::parse('2027-09-15'));
        app(LeaseRentChangeService::class)->apply($lease->fresh(), ['base_rent_monthly' => 1500, 'effective_from' => '2027-09-15', 'reason' => 'Renegotiated after the step.']);

        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..2027-09-14 1500@2027-09-15..2028-09-09 1650@2028-09-10..open')
            ->and((float) $lease->fresh()->base_rent_monthly)->toBe(1500.0);

        // Back-dated across the step: the projected rung that started since is superseded, the
        // levy's with it, and the ladder compounds from the stated figure.
        $backdated = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();
        $this->travelTo(CarbonImmutable::parse('2027-09-15'));
        app(LeaseRentChangeService::class)->apply($backdated->fresh(), ['base_rent_monthly' => 1500, 'effective_from' => '2027-09-05', 'reason' => 'Signed on the 5th, keyed late.']);

        expect(LeaseLadder::rungsByDay($backdated, 'base_rent'))->toBe('1000@2026-09-10..2027-09-04 1500@2027-09-05..2028-09-09 1650@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($backdated, 'marketing'))->toBe('50@2026-09-10..2027-09-04 75@2027-09-05..2028-09-09 83@2028-09-10..open')
            ->and($backdated->charges()->where('type', 'base_rent')->where('is_active', false)->whereDate('start_date', '2027-09-10')->count())->toBe(1);

        // A levy re-rate typed through the page after the step lands on the day too.
        $levy = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();
        $this->travelTo(CarbonImmutable::parse('2027-09-15'));
        LeaseLadder::edit($levy, ['marketing_levy_rate' => 6]);
        expect(LeaseLadder::rungsByDay($levy, 'marketing'))->toBe('50@2026-09-10..2027-09-09 55@2027-09-10..2027-09-14 66@2027-09-15..2028-09-09 73@2028-09-10..open');
    });
});

it('never bills a quarterly- or annually-frequency charge twice in the month its own rule steps it', function () {
    // REVIEW FINDING: a non-monthly row bills whole in its month and bypasses the day window, so
    // the outgoing rung (to the 9th) and the incoming one (from the 10th) both applied to
    // September — 900 AND 990 on one invoice. The outgoing rung yields the month.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setAmount($lease, 'other', 900, CarbonImmutable::parse('2026-09-10'), [
            'name' => 'Signage licence', 'frequency' => 'quarterly',
            'escalation_mode' => ChargeEscalation::PERCENT, 'escalation_rate' => 10,
        ]);
        app(ChargeScheduleService::class)->retrueProjectedLadder($lease->fresh());
        expect(LeaseLadder::rungsByDay($lease, 'other'))->toStartWith('900@2026-09-10..2027-09-09 990@2027-09-10');

        $this->travelTo(CarbonImmutable::parse('2027-09-01'));
        $lines = collect(anniversaryMonthLines($lease, '2027-09-01'))->filter(fn ($l) => str_starts_with($l, 'other'))->values()->all();
        expect($lines)->toBe(['other 990.00 (2027-09-01..2027-09-30)']);
    });
});

it('does not let a holdover\'s first arrears line reach back into the days between a mid-month expiry and the holdover', function () {
    // REVIEW FINDING: the final invoice records the arrears line's true window (to the expiry,
    // the 20th); a holdover begins the month after, and its October invoice then billed the
    // service charge for the 21st–30th while the rent for those days, by the holdover's own
    // rule, is nobody's. The holdover's window starts where the holdover does.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset, expiry: '2027-09-20');
        $lease->charges()->where('type', 'service_charge')->update(['billing_timing' => 'arrears']);

        $this->travelTo(CarbonImmutable::parse('2027-09-01'));
        $final = anniversaryMonthLines($lease, '2027-09-01');
        // The final cycle settles the arrears window AND its own days, to the expiry.
        expect(collect($final)->filter(fn ($l) => str_starts_with($l, 'service_charge'))->values()->all())
            ->toBe(['service_charge 416.67 (2027-08-01..2027-09-20)']);

        $this->travelTo(CarbonImmutable::parse('2027-09-25'));
        $lease->fresh()->update(['status' => 'expired']);
        app(\App\Services\ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['effective_from' => '2027-10-01', 'reason' => 'Stayed on while the renewal is negotiated.']);

        $this->travelTo(CarbonImmutable::parse('2027-10-01'));
        $october = anniversaryMonthLines($lease, '2027-10-01');
        expect(collect($october)->filter(fn ($l) => str_starts_with($l, 'service_charge'))->values()->all())->toBe([]);
    });
});

it('reads a whole-month rent row the way the invoice bills it when straight-lining', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $lease->charges()->where('type', 'base_rent')->update(['prorate' => false]);

        // A `prorate = false` rent yields the split month whole to the new rung: the invoice
        // carries 1,100 for September and so does the straight-line read.
        expect(app(StraightLineRentService::class)->billedFor($lease->fresh(), CarbonImmutable::parse('2027-09-01')))->toBe(1100.0);
    });
});

it('re-dates an already-laddered lease onto its anniversaries through the console repair', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);

        // The pre-2026-09-13 shape, as every laddered lease on an install stands: rungs on the 1st.
        foreach ([['2027-09-10', '2027-09-01'], ['2028-09-10', '2028-09-01']] as [$day, $first]) {
            $lease->charges()->whereDate('start_date', $day)->update(['start_date' => $first]);
            $lease->charges()->whereDate('end_date', CarbonImmutable::parse($day)->subDay()->toDateString())->update(['end_date' => CarbonImmutable::parse($first)->subDay()->toDateString()]);
        }
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-08-31 1100@2027-09-01..2028-08-31 1210@2028-09-01..open');

        $this->artisan('atriom:project-lease-schedules', ['--retrue' => true, '--commit' => true])->assertSuccessful();

        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..2028-09-09 1210@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toBe('50@2026-09-10..2027-09-09 55@2027-09-10..2028-09-09 61@2028-09-10..open');
    });
});

it('never steps twice a ladder written under the old snap whose current rung started before its anniversary', function () {
    // THE MIGRATION WINDOW. On every install the ladders stand as the snap wrote them: the rung
    // for the 10 September anniversary starts on 1 September. Between that 1st and the 10th the
    // rung has STARTED and the pointer still sits on the 10th — the soak box's anchor lease on
    // the day this shipped — so the eve of the anniversary is covered by the rung that IS the
    // step. Read as a base it would be stepped again, by the re-true and by the sweep.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setEscalation($lease, 'service_charge', ChargeEscalation::PERCENT, 10);

        // The pre-2026-09-13 shape, by hand.
        foreach (['base_rent', 'marketing', 'service_charge'] as $type) {
            $lease->charges()->where('type', $type)->whereDate('start_date', '2027-09-10')->update(['start_date' => '2027-09-01']);
            $lease->charges()->where('type', $type)->whereDate('end_date', '2027-09-09')->update(['end_date' => '2027-08-31']);
        }
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-09-10..2027-08-31 1100@2027-09-01..2028-09-09');

        $this->travelTo(CarbonImmutable::parse('2027-09-05'));
        app(ChargeScheduleService::class)->retrueProjectedLadder($lease->fresh());

        // The started rung is adopted as the step it is; only what lies ahead is re-dated.
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-08-31 1100@2027-09-01..2028-09-09 1210@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($lease, 'service_charge'))->toBe('250@2026-09-10..2027-08-31 275@2027-09-01..2028-09-09 303@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toBe('50@2026-09-10..2027-08-31 55@2027-09-01..2028-09-09 61@2028-09-10..open');

        // And the sweep on the anniversary moves the columns and mints nothing on top.
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();

        expect((float) $lease->fresh()->base_rent_monthly)->toBe(1100.0)
            ->and((float) $lease->fresh()->service_charge_monthly)->toBe(275.0)
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-08-31 1100@2027-09-01..2028-09-09 1210@2028-09-10..open')
            ->and(LeaseLadder::rungsByDay($lease, 'service_charge'))->toBe('250@2026-09-10..2027-08-31 275@2027-09-01..2028-09-09 303@2028-09-10..open');
    });
});

it('still steps on the anniversary after a relief that ends on the eve of the anniversary month', function () {
    // A resumption rung after a relief window is `escalation`-origin and starts on the 1st —
    // the same shape as a legacy snapped step. It is NOT one: it carries the contracted figure
    // the relief was granted against, so the step on the 10th is still owed.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        app(\App\Services\LeaseReliefService::class)->grant($lease, [
            'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-08-31',
            'reason' => 'Two-month concession over the summer works.',
        ]);

        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))
            ->toBe('1000@2026-09-10..2027-06-30 500@2027-07-01..2027-08-31 1000@2027-09-01..2027-09-09 1100@2027-09-10..2028-09-09 1210@2028-09-10..open');

        // And re-truing inside September — the resumption rung has started — keeps the step.
        $this->travelTo(CarbonImmutable::parse('2027-09-05'));
        app(ChargeScheduleService::class)->retrueProjectedLadder($lease->fresh());
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))
            ->toBe('1000@2026-09-10..2027-06-30 500@2027-07-01..2027-08-31 1000@2027-09-01..2027-09-09 1100@2027-09-10..2028-09-09 1210@2028-09-10..open');

        // A window SPANNING an anniversary resumes on the projected rung's own continuation —
        // `escalation`-origin, starting on the 1st of the next anniversary's month, before it:
        // the legacy shape exactly, except that it carries the PREVIOUS step, not this one.
        $this->travelTo(CarbonImmutable::parse('2026-09-10'));
        $spanning = LeaseLadder::testersLease($this->asset);
        app(\App\Services\LeaseReliefService::class)->grant($spanning, [
            'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2028-08-31',
            'reason' => 'Fourteen months at half rent through the refit.',
        ]);
        expect(LeaseLadder::rungsByDay($spanning, 'base_rent'))
            ->toBe('1000@2026-09-10..2027-06-30 500@2027-07-01..2027-09-09 550@2027-09-10..2028-08-31 1100@2028-09-01..2028-09-09 1210@2028-09-10..open');

        $this->travelTo(CarbonImmutable::parse('2028-09-05'));
        app(ChargeScheduleService::class)->retrueProjectedLadder($spanning->fresh());
        expect(LeaseLadder::rungsByDay($spanning, 'base_rent'))
            ->toBe('1000@2026-09-10..2027-06-30 500@2027-07-01..2027-09-09 550@2027-09-10..2028-08-31 1100@2028-09-01..2028-09-09 1210@2028-09-10..open');
    });
});

it('reads the split month as the blend the invoice bills when straight-lining rent', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $service = app(StraightLineRentService::class);

        expect($service->billedFor($lease->fresh(), CarbonImmutable::parse('2027-08-01')))->toBe(1000.0)
            ->and($service->billedFor($lease->fresh(), CarbonImmutable::parse('2027-09-01')))->toBe(1070.0)
            ->and($service->billedFor($lease->fresh(), CarbonImmutable::parse('2027-10-01')))->toBe(1100.0);
    });
});
