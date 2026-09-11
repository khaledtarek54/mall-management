<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseReliefService;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Editing an escalation clause re-trues the projected ladder — every term of it.
 *
 * Two Critical cards, one lease, one defect (Trello RV4DrGHA + jF09XB3n, 2026-09-11). The billing
 * engine reads the LADDER — the date-ranged `charges` rows `projectTermEscalations()` writes up
 * front — and never the clause. The `Lease::updated` hook re-projected that ladder for exactly one
 * clause term, the service-charge toggle, and projected with whatever the lease carried at that
 * moment. Every other term fell through it.
 *
 * Reproduced from the staging ladder rung for rung: the operator typed 1 into *Steps every*
 * (nothing re-projected), flipped the toggle (which re-projected MONTHLY — 1,000 → 1,100 at the
 * first anniversary, then 1,210, 1,331 … 10,835 two years later), cleared the interval (nothing
 * re-projected, the monthly rungs stayed), and the next day set the rate 10 → 100 (nothing
 * re-projected, the 10% rungs stayed). The lease read *100%, yearly* and billed *10%, monthly*.
 *
 * Yardi regenerates the rent steps from the escalation setup whenever the setup changes, and MRI
 * does the same. `ChargeScheduleService::retrueProjectedLadder()` is that in one place: prune the
 * not-yet-started projected rungs, then project from the clause as it now reads. A stated, manual
 * rung survives, the collar is deliberately not a trigger, and the sweep's pointer follows an
 * interval change from the sweep's OWN state (the pointer it carries is one old interval past the
 * last step it applied) — walked forward to the first anniversary on or after today.
 *
 * The adversarial review of the first cut found three things, all confirmed by driving the code,
 * and each has its case below: a clause edit on a lease under a rent RELIEF halved the rent for
 * the rest of the term (the relief row read as a stated step and its resumption was pruned);
 * re-arming the pointer from the last projected rung that had STARTED read a rung as applied in
 * the window between the 1st and the anniversary sweep, and every later sweep then amended the
 * ladder down a step; and the levy toggled on through the real page lost its first future step,
 * because the page re-synced the base levy AFTER the hook had projected the rungs.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'ESC']);
    $this->travelTo(CarbonImmutable::parse('2026-09-10'));
});

/** The rent ladder as `amount@Y-m`, so a whole shape is one string. */
function activeRentRungs(Lease $lease): string
{
    return $lease->charges()->where('type', 'base_rent')->where('is_active', true)->orderBy('start_date')->get()
        ->map(fn (Charge $c) => number_format((float) $c->amount, 0, '.', '').'@'.$c->start_date->format('Y-m'))
        ->implode(' ');
}

/** The levy ladder in the same shape. */
function activeLevyRungs(Lease $lease): string
{
    return $lease->charges()->where('type', 'marketing')->where('is_active', true)->orderBy('start_date')->get()
        ->map(fn (Charge $c) => number_format((float) $c->amount, 0, '.', '').'@'.$c->start_date->format('Y-m'))
        ->implode(' ');
}

/** The tester's lease, through the real create form: 1,000 rent, 10 % yearly, levy 5 %. */
function testersLease(TestCase $test, int $rate = 10): Lease
{
    $unit = makeUnit($test->asset, ['code' => 'E-'.uniqid(), 'status' => 'vacant']);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)->fillForm([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => 'active',
        'commencement_date' => '2026-09-10', 'term_months' => 36, 'expiry_date' => '2029-09-09',
        'base_rent_monthly' => 1000, 'service_charge_monthly' => 250,
        'has_marketing_levy' => true, 'marketing_levy_rate' => 5,
        'escalation_type' => 'fixed_percent', 'escalation_rate' => $rate,
        'escalation_interval_months' => null, 'security_deposit_months' => 3,
    ])->call('create')->assertHasNoFormErrors();

    return Lease::where('tenant_id', $tenant->id)->sole();
}

function editLease(Lease $lease, array $data): void
{
    Livewire::test(EditLease::class, ['record' => $lease->getKey()])
        ->fillForm($data)->call('save')->assertHasNoFormErrors();
}

it('re-trues the ladder when the rate changes', function () {
    // RV4DrGHA — configured 100 %, ladder stepping 10 %.
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        expect(activeRentRungs($lease))->toBe('1000@2026-09 1100@2027-09 1210@2028-09 1331@2029-09');

        editLease($lease, ['escalation_rate' => 100]);

        expect(activeRentRungs($lease))->toBe('1000@2026-09 2000@2027-09 4000@2028-09 8000@2029-09');
    });
});

it('ends the tester s exact editing session on a yearly ladder', function () {
    // jF09XB3n — reproduced from staging rung for rung. The three edits in the order the
    // timestamps say, and the ladder is right after EACH of them.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        editLease($lease, ['escalation_interval_months' => 1]);
        // A monthly clause projects monthly — correctly, for as long as that is what it says…
        expect(activeRentRungs($lease))->toStartWith('1000@2026-09 1100@2026-10 1210@2026-11');

        editLease($lease, ['escalation_applies_to_service_charge' => true]);
        expect(activeRentRungs($lease))->toStartWith('1000@2026-09 1100@2026-10 1210@2026-11');

        editLease($lease, ['escalation_interval_months' => null]);
        // …and the moment it says yearly again, so does the ladder. Before the fix every monthly
        // rung survived this edit and went on billing.
        expect(activeRentRungs($lease))->toBe('1000@2026-09 1100@2027-09 1210@2028-09 1331@2029-09');
    });
});

it('carries the levy along with the re-trued rent', function () {
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        editLease($lease, ['escalation_rate' => 100]);

        // 5 % of each rung, and no orphaned rung from the 10 % ladder left billing beside it.
        expect(activeLevyRungs($lease))->toBe('50@2026-09 100@2027-09 200@2028-09 400@2029-09');
    });
});

it('re-rates the levy from today without touching the final rung, and touches no rent rung at all', function () {
    // THE TAIL CLOBBER, isolated. A levy-rate edit is the one save that legitimately re-syncs the
    // base levy "from today" — and in the commencement month "today" snaps to the 1st, a date no
    // row covers, which `pickInForce()` used to answer with the LAST active row. The final
    // projected rung was then overwritten in place with the base levy: 400 → 50 in the last year
    // of the term, exactly the tail staging's ladder carried. Mutating `pickInForce` goes red on
    // this case alone.
    //
    // And it is the LEVY's half of the hook: the rent ladder is walked, not re-written, so every
    // rent rung keeps its id — a levy edit that deactivated and re-minted three years of rent
    // rungs to change no rent figure would be audit-trail churn for nothing.
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        $rentIds = $lease->charges()->where('type', 'base_rent')->where('is_active', true)->orderBy('start_date')->pluck('id')->all();

        editLease($lease, ['marketing_levy_rate' => 6]);

        // Six percent of 1,000 from the lease's own first day, then six percent of every rung.
        expect(activeLevyRungs($lease))->toBe('60@2026-09 66@2027-09 73@2028-09 80@2029-09')
            ->and($lease->charges()->where('type', 'base_rent')->where('is_active', true)->orderBy('start_date')->pluck('id')->all())->toBe($rentIds);
    });
});

it('leaves a rung the operator STATED exactly where it is', function () {
    // A future-dated Change Rent amends a rung in place and marks it manual — a negotiated term.
    // Re-truing must adopt it, never overwrite it with arithmetic; `pruneProjectedLadder` never
    // touches an `ORIGIN_MANUAL` row and the projection adopts one as a stated step.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        $rung = $lease->charges()->where('type', 'base_rent')->where('amount', 1100)->sole();
        $rung->update(['amount' => 1500, 'origin' => Charge::ORIGIN_MANUAL]);

        editLease($lease, ['escalation_rate' => 100]);

        // Stated 1,500 stands; the steps AFTER it compound from it at the new rate.
        expect(activeRentRungs($lease))->toBe('1000@2026-09 1500@2027-09 3000@2028-09 6000@2029-09');
    });
});

it('does not churn the ladder when only the collar moves', function () {
    // The control on the trigger list. The projection states the raw rate and the sweep collars
    // it, so a collar change moves no rung — re-truing on it would deactivate and recreate every
    // rung identically, which is audit noise and nothing else.
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        $ids = $lease->charges()->where('type', 'base_rent')->pluck('id')->sort()->values()->all();

        editLease($lease, ['escalation_floor_rate' => 2, 'escalation_ceiling_rate' => 50]);

        expect($lease->charges()->where('type', 'base_rent')->where('is_active', true)->pluck('id')->sort()->values()->all())
            ->toBe($ids);
    });
});

it('still prunes the projected future when the clause is cleared', function () {
    // The old first branch, now a case of the one rule: a cleared clause projects nothing.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        editLease($lease, ['escalation_type' => 'none']);

        expect(activeRentRungs($lease))->toBe('1000@2026-09');
    });
});

it('moves the sweep s pointer when the interval changes on a lease that has not stepped yet', function () {
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        expect($lease->next_escalation_date->toDateString())->toBe('2027-09-10');

        editLease($lease, ['escalation_interval_months' => 6]);

        // The FIRST step is six months out now, not twelve — and the ladder agrees.
        expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-03-10')
            ->and(activeRentRungs($lease))->toStartWith('1000@2026-09 1100@2027-03 1210@2027-09');
    });
});

it('measures a mid-term interval change from the sweep s own pointer', function () {
    // Two annual steps have run (the sweep applied them, bumped the rent and rolled the pointer to
    // year three). Switching to six-monthly must put the next step six months after the SECOND
    // anniversary — never re-open a past one, never anchor on commencement.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        $this->travelTo(CarbonImmutable::parse('2028-09-15'));
        // What two sweeps would have left: the pointer on year three AND the rent at the second
        // rung (the first cut of this fixture moved only the pointer, which is how it stayed
        // green while the re-arm was reading rung dates instead of the sweep's own state).
        $lease->update(['next_escalation_date' => '2029-09-10', 'base_rent_monthly' => 1210]);

        editLease($lease, ['escalation_interval_months' => 6]);

        expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2029-03-10')
            ->and((float) $lease->fresh()->base_rent_monthly)->toBe(1210.0);
    });
});

it('anchors a second interval change on the last step actually applied, not on commencement', function () {
    // The pointer the sweep carries is one interval past the last step it applied. A lease already
    // on a six-monthly cadence (last step 10 March, pointer 10 September) moved to four-monthly
    // steps next on 10 JULY — four months after the last step. Counting from commencement on the
    // new cadence would say 10 May, two months after a step the tenant just took.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        $this->travelTo(CarbonImmutable::parse('2028-04-01'));
        DB::table('leases')->where('id', $lease->id)
            ->update(['escalation_interval_months' => 6, 'next_escalation_date' => '2028-09-10']);

        editLease($lease->fresh(), ['escalation_interval_months' => 4]);

        expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2028-07-10');
    });
});

it('never arms the pointer in the past when a shortened interval is set mid-cycle', function () {
    // REVIEW FINDING. Rungs start on the 1st; the sweep applies on the anniversary day. An
    // interval edit in between — the 1100 rung has STARTED, the sweep has not run, the rent is
    // still 1000 — must leave the sweep exactly where it was going: the anniversary on the 10th
    // applies 1000 × 1.1 onto a rung already carrying 1100 (a no-op), and the NEXT step is six
    // months on. The first cut read the started rung as "applied", armed the pointer six months
    // past it, and the sweep then took 1000 × 1.1 and amended the 1210 rung DOWN to 1100 — one
    // step behind for the rest of the term, silently.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        $this->travelTo(CarbonImmutable::parse('2027-09-05'));
        editLease($lease, ['escalation_interval_months' => 6]);

        // Six-monthly from commencement is 2027-03-10 — in the past — so it walks on to the
        // anniversary the sweep was already going to keep.
        expect($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-09-10')
            ->and(activeRentRungs($lease))->toBe('1000@2026-09 1100@2027-09 1210@2028-03 1331@2028-09 1464@2029-03 1611@2029-09');

        // The sweep on the anniversary agrees with the ladder instead of fighting it.
        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();

        expect((float) $lease->fresh()->base_rent_monthly)->toBe(1100.0)
            ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2028-03-10')
            ->and(activeRentRungs($lease))->toBe('1000@2026-09 1100@2027-09 1210@2028-03 1331@2028-09 1464@2029-03 1611@2029-09');
    });
});

it('keeps a step the sweep has already applied and re-trues only what lies ahead', function () {
    // REVIEW: the first cut had no case where the sweep had actually bumped the rent. One annual
    // step applied (rent 1,100, pointer on year two), then the rate moves to 20 %: the started
    // rung is history and keeps its row, and every rung beyond it compounds at 20 % from it.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        $this->travelTo(CarbonImmutable::parse('2027-09-10'));
        app(RentEscalationService::class)->runForToday();
        $started = $lease->charges()->where('type', 'base_rent')->where('is_active', true)->whereDate('start_date', '2027-09-01')->sole();
        expect((float) $lease->fresh()->base_rent_monthly)->toBe(1100.0);

        $this->travelTo(CarbonImmutable::parse('2027-09-15'));
        editLease($lease, ['escalation_rate' => 20]);

        expect(activeRentRungs($lease))->toBe('1000@2026-09 1100@2027-09 1320@2028-09 1584@2029-09')
            ->and($started->fresh()->is_active)->toBeTrue()
            ->and((float) $started->fresh()->amount)->toBe(1100.0)
            ->and((float) $lease->fresh()->base_rent_monthly)->toBe(1100.0);
    });
});

it('walks through a rent relief instead of over it', function () {
    // REVIEW FINDING, the worst of the three. A six-month 50 % relief over the first step: the
    // relief rows read as STATED steps to the projection (they were `manual`), the rung that
    // resumed the contract after the window was pruned as "projected", and the relief row ending
    // the day before it was re-linked past its own end. Measured: a rate edit left
    // `550@2027-09..2028-08 | 660@2028-09 | 792@2029-09` — rent halved to the end of the term,
    // and the ladder looked ordinary.
    //
    // Now: the window's own rows are `relief` and stay exactly as granted, the rung that resumes
    // after it stays and is re-priced to the step the clause now says (the levy is derived from
    // that same figure, so the two agree), and the step after compounds from it.
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        app(LeaseReliefService::class)->grant($lease, [
            'percent_off' => 50, 'from' => '2027-07-01', 'to' => '2027-12-31',
            'reason' => 'Six-month concession while the anchor unit is re-let.',
        ]);
        expect(activeRentRungs($lease))->toBe('1000@2026-09 500@2027-07 550@2027-09 1100@2028-01 1210@2028-09 1331@2029-09');

        editLease($lease, ['escalation_rate' => 20]);

        expect(activeRentRungs($lease))->toBe('1000@2026-09 500@2027-07 550@2027-09 1200@2028-01 1440@2028-09 1728@2029-09')
            ->and(activeLevyRungs($lease))->toBe('50@2026-09 60@2027-09 72@2028-09 86@2029-09')
            // The relief's own rows are the operator's, untouched to the day.
            ->and($lease->charges()->where('type', 'base_rent')->where('origin', Charge::ORIGIN_RELIEF)->where('is_active', true)->orderBy('start_date')->get()
                ->map(fn (Charge $c) => $c->amount.' '.$c->start_date->toDateString().'..'.$c->end_date->toDateString())->implode(' | '))
            ->toBe('500.00 2027-07-01..2027-08-31 | 550.00 2027-09-01..2027-12-31');
    });
});

it('gives a levy toggled on through the page its full ladder, from the base row up', function () {
    // REVIEW FINDING. `has_marketing_levy` is a ladder term, so the hook projected the levy's
    // rungs — and with no base levy row in force, `setAmount` opened the levy from COMMENCEMENT at
    // the first STEP's amount; the page's own re-sync then ran AFTER the hook and overwrote it
    // with the base levy. Through the real page: the 2027-09 step was gone, and mid-term a row
    // asserted a stepped levy in force for six months that billed nothing. The base row is synced
    // FIRST now, in the hook, and the page re-syncs nothing.
    asTenant($this->asset, function () {
        $lease = testersLease($this);
        editLease($lease, ['has_marketing_levy' => false]);
        expect($lease->charges()->where('type', 'marketing')->where('is_active', true)->count())->toBe(0);

        // Commencement month.
        editLease($lease, ['has_marketing_levy' => true, 'marketing_levy_rate' => 5]);
        expect(activeLevyRungs($lease))->toBe('50@2026-09 55@2027-09 61@2028-09 67@2029-09');

        // Mid-term, off again and on again: the same shape, and no back-dated stepped row.
        editLease($lease, ['has_marketing_levy' => false]);
        $this->travelTo(CarbonImmutable::parse('2027-03-15'));
        editLease($lease, ['has_marketing_levy' => true, 'marketing_levy_rate' => 5]);
        expect(activeLevyRungs($lease))->toBe('50@2026-09 55@2027-09 61@2028-09 67@2029-09');
    });
});

it('repairs a ladder that has already drifted, on demand', function () {
    // The tester's lease on staging is already wrong, and the hook only fires on a CHANGE — so the
    // operation has to be callable on its own, which is why it is a method and not the hook's body.
    asTenant($this->asset, function () {
        $lease = testersLease($this);

        // Drift it the way staging was drifted: monthly rungs under a yearly clause.
        editLease($lease, ['escalation_interval_months' => 1]);
        // Straight to the columns, so no hook fires — the exact shape staging was left in: a
        // yearly clause, the pointer still on the first anniversary, monthly rungs underneath.
        DB::table('leases')->where('id', $lease->id)
            ->update(['escalation_interval_months' => null, 'next_escalation_date' => '2027-09-10']);
        expect(activeRentRungs($lease->fresh()))->toStartWith('1000@2026-09 1100@2026-10');

        app(ChargeScheduleService::class)->retrueProjectedLadder($lease->fresh());

        expect(activeRentRungs($lease->fresh()))->toBe('1000@2026-09 1100@2027-09 1210@2028-09 1331@2029-09');
    });
});
