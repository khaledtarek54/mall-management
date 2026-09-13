<?php

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Services\ChargeScheduleService;
use App\Services\LeaseTerminationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LeaseLadder;

/**
 * Editing a lease's TERM re-dates its charge schedule — the commencement it is anchored on and the
 * expiry it is bounded by.
 *
 * Trello 7IgLPLGl (Critical, 2026-09-11), one door over from the two escalation cards of the same
 * day: create a lease commencing 10/09/2026, save, move the commencement to 12/09/2026 on the Term
 * tab, save — and the charge schedule went on starting on the 10th. The seeded rows start ON the
 * commencement, the anniversaries are counted FROM it and the walk stops AT the expiry, so the
 * schedule is as much a function of the term as of the clause; `LeaseForm` had said so since
 * 2026-08-12 in the comment that LOCKS both dates once the lease is invoiced ("the commencement
 * anchors ... every charge row's start date"), and nothing re-derived any of it on an edit.
 *
 * Now `Lease::LADDER_BOUNDS` joins the re-true trigger: a commencement move re-dates the rows
 * creation anchored on the old date (`seed` · `levy` · `renewal` — a row that merely shares the
 * date is not about it) and re-arms the first anniversary from the new one; an expiry move
 * prunes past the new end, projects up to a lengthened LIVE term, and is bounded at the
 * contracted end when the tenancy is being closed out (a step the term never reached must not
 * be minted for the final bill). And the form's lock is a GATE: an invoiced lease — or one whose
 * rent has already stepped — refuses the commencement move at the model, so a service that
 * renders no field is refused for the reason the form shows; under the importer the row fails
 * (Filament records a failed row and not the sentence — a pre-existing shape for every model
 * refusal there). Voyager treats a start-date change once charges have posted as an amendment
 * rather than an edit, which is the same line drawn in the same place.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'TRM']);
    $this->travelTo(CarbonImmutable::parse('2026-09-10'));
});

it('re-dates the schedule when the commencement moves — the tester s exact steps', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-09-10..2027-09-09 1100@2027-09-10');

        LeaseLadder::edit($lease, ['commencement_date' => '2026-09-12']);

        // Every row anchored on the 10th now starts on the 12th, and so do the anniversaries —
        // counted from the commencement and, since 2026-09-13, landing ON their day (Trello
        // gzwI17R0) rather than on the 1st of their month. The third anniversary (2029-09-12)
        // falls past the 2029-09-09 expiry, so the term steps twice.
        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))
            ->toBe('1000@2026-09-12..2027-09-11 1100@2027-09-12..2028-09-11 1210@2028-09-12..open')
            ->and(LeaseLadder::rungsByDay($lease, 'service_charge'))->toBe('250@2026-09-12..open')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toStartWith('50@2026-09-12..2027-09-11 55@2027-09-12')
            ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-09-12');
    });
});

it('moves the anniversaries with a commencement that moves to another month', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);

        // What the Term tab does: the expiry is re-derived from the new commencement + 36 months.
        LeaseLadder::edit($lease, ['commencement_date' => '2026-11-20', 'expiry_date' => '2029-11-19']);

        expect(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-11 1100@2027-11 1210@2028-11')
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-11-20..2027-11-19 1100@2027-11-20')
            ->and(LeaseLadder::rungs($lease, 'marketing'))->toBe('50@2026-11 55@2027-11 61@2028-11')
            ->and($lease->fresh()->next_escalation_date->toDateString())->toBe('2027-11-20')
            // No rung from the September cadence left billing beside the new ones.
            ->and($lease->charges()->where('type', 'base_rent')->where('is_active', true)->whereMonth('start_date', 9)->count())->toBe(0);
    });
});

it('leaves a row that has its own date exactly where it is', function () {
    // A bay assigned from 1 December, a manual charge from a date the operator picked, an
    // imported row — none of them is anchored on the commencement, so none of them moves. (The
    // sharper case — a row that SHARES the commencement date without being about it — is below.)
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        app(ChargeScheduleService::class)->setAmount($lease, 'parking', 500, CarbonImmutable::parse('2026-12-01'), [
            'name' => 'Parking', 'first_row_from_effective' => true,
        ]);

        LeaseLadder::edit($lease, ['commencement_date' => '2026-09-12']);

        expect(LeaseLadder::rungsByDay($lease, 'parking'))->toBe('500@2026-12-01..open')
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-09-12');
    });
});

it('prunes the rungs past a shortened expiry and projects up to a lengthened one', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);

        // Term first, then the expiry it derives to: `fillForm` fires each field's own
        // `afterStateUpdated` in array order, and the other order lets `deriveExpiry` overwrite
        // the typed date (the review caught the first cut saving 2028-07-09 while claiming 06-30).
        LeaseLadder::edit($lease, ['term_months' => 22, 'expiry_date' => '2028-07-09']);
        expect($lease->fresh()->expiry_date->toDateString())->toBe('2028-07-09')
            ->and(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-09 1100@2027-09')
            ->and(LeaseLadder::rungs($lease, 'marketing'))->toBe('50@2026-09 55@2027-09');

        LeaseLadder::edit($lease, ['term_months' => 60, 'expiry_date' => '2031-09-09']);
        expect($lease->fresh()->expiry_date->toDateString())->toBe('2031-09-09')
            ->and(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-09 1100@2027-09 1210@2028-09 1331@2029-09 1464@2030-09');
    });
});

it('refuses to move the commencement of an invoiced lease, through every door, and still moves its expiry', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2026-10-01'));
        expect(app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-10-01'))['status'])->toBe('created');
        $before = LeaseLadder::rungsByDay($lease, 'base_rent');

        // The form locks the field ...
        Livewire::test(EditLease::class, ['record' => $lease->getKey()])->assertFormFieldDisabled('commencement_date');

        // ... and the MODEL refuses the move for every service that never renders one, in the
        // reader's words, with the way out (an importer row fails without the sentence — Filament's
        // own `ImportCsv` logs the row and not the message).
        expect(fn () => $lease->fresh()->update(['commencement_date' => '2026-09-12']))
            ->toThrow(DomainException::class, __('admin.refusals.lease_commencement_locked_after_invoicing', [
                'reference' => $lease->reference, 'from' => '2026-09-10',
            ]))
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe($before);

        // CONTROL: the expiry is an act's to move on an invoiced lease (an extension, a
        // termination), and the move re-trues the ladder through the same hook.
        $lease->fresh()->update(['expiry_date' => '2030-09-09']);
        expect(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-09 1100@2027-09 1210@2028-09 1331@2029-09');
    });
});

it('refuses to move the commencement once a contracted step has been reached', function () {
    // REVIEW FINDING. The anniversaries are counted from the commencement, so once one has been
    // reached — a projected rung has started, or the sweep has applied one — moving the
    // commencement re-derives a step that already happened: measured, a year-old draft moved one
    // month later kept its started 1,100 rung AND projected 1,210 from the new anniversary, one
    // extra step for the rest of the term. Same gate as the invoiced one, its own reason, one
    // predicate for the form and the model.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-09-15'));
        $before = LeaseLadder::rungsByDay($lease, 'base_rent');

        expect($lease->fresh()->commencementLockedBecause())->toBe('stepped');
        Livewire::test(EditLease::class, ['record' => $lease->getKey()])
            ->assertFormFieldDisabled('commencement_date')
            ->assertSee(__('admin.helpers.locked_after_stepping'));

        expect(fn () => $lease->fresh()->update(['commencement_date' => '2026-10-10']))
            ->toThrow(DomainException::class, __('admin.refusals.lease_commencement_locked_after_stepping', [
                'reference' => $lease->reference, 'from' => '2026-09-10', 'stepped' => '2027-09-10',
            ]))
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe($before);

        // CONTROL: a lease whose every step is still ahead is free — the same predicate answers null.
        $fresh = LeaseLadder::testersLease($this->asset, commencement: '2027-10-10', expiry: '2030-10-09');
        expect($fresh->commencementLockedBecause())->toBeNull();
    });
});

it('never mints the step a term never reached when the tenancy is closed out past its expiry', function () {
    // REVIEW FINDING. `LeaseTerminationService` writes the termination date onto `expiry_date`,
    // so the hook saw an expiry moved LATER and projected up to it — the anniversary on the 1st of
    // the expiry month, which the contracted term had ended before, was minted (1,331 over 1,210)
    // and the final bill read it. `ConvertLeaseToHoldoverService` states the rule: a projected
    // escalation the lease never reached must not become the basis. A lengthened LIVE term is an
    // extension; an ended term "lengthened" is a close-out, bounded at the contracted expiry.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset, expiry: '2029-08-31');
        expect(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-09 1100@2027-09 1210@2028-09');

        $this->travelTo(CarbonImmutable::parse('2029-09-20'));
        app(LeaseTerminationService::class)->terminate($lease->fresh(), [
            'termination_date' => '2029-10-15', 'reason' => 'Closed out six weeks after the term ran out.',
        ]);

        expect(LeaseLadder::rungs($lease, 'base_rent'))->toBe('1000@2026-09 1100@2027-09 1210@2028-09')
            ->and($lease->fresh()->expiry_date->toDateString())->toBe('2029-10-15');
    });
});

it('keeps the step that still falls before an early termination date', function () {
    // The other direction: terminating under notice six months out, with an anniversary in
    // between, must keep that step and drop only the rungs past the termination date.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2027-06-01'));

        app(LeaseTerminationService::class)->terminate($lease->fresh(), [
            'termination_date' => '2027-12-31', 'reason' => 'Tenant served notice.',
        ]);

        expect(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('1000@2026-09-10..2027-09-09 1100@2027-09-10..2027-12-31')
            ->and($lease->fresh()->status)->toBe('active');
    });
});

it('does not move a row that merely shares the commencement date on a lease commencing on the 1st', function () {
    // REVIEW FINDING. Every writer snaps to the 1st, so on a lease commencing on the 1st a bay
    // assigned that month, a CAM estimate or a manual charge starts on the commencement date
    // without being about it — and a bay's register row does not move with the lease. Only the
    // rows CREATION anchors there (`seed`, `levy`, `renewal`) follow the commencement.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset, commencement: '2026-10-01', expiry: '2029-09-30');
        app(ChargeScheduleService::class)->setAmount($lease, 'parking', 500, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Parking', 'first_row_from_effective' => true,
        ]);

        LeaseLadder::edit($lease, ['commencement_date' => '2026-11-01', 'expiry_date' => '2029-10-31']);

        expect(LeaseLadder::rungsByDay($lease, 'parking'))->toBe('500@2026-10-01..open')
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-11-01..2027-10-31 1100@2027-11-01')
            ->and(LeaseLadder::rungsByDay($lease, 'marketing'))->toStartWith('50@2026-11-01');
    });
});

it('retires a moved row that would now end before it starts instead of refusing in a charge s words', function () {
    // REVIEW FINDING. A levy re-rate closes the base levy row on the eve of the edit and opens
    // the new rate from that day (the day, since 2026-09-13); move the commencement past that
    // eve and the old base row would "end before it starts" — refused by `Charge::saving`, in a
    // charge's vocabulary, on a lease-term field. Under the new term that row covers nothing, so
    // it is retired.
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);
        $this->travelTo(CarbonImmutable::parse('2026-11-05'));
        LeaseLadder::edit($lease, ['marketing_levy_rate' => 6]);
        expect(LeaseLadder::rungsByDay($lease, 'marketing'))->toStartWith('50@2026-09-10..2026-11-04 60@2026-11-05');

        LeaseLadder::edit($lease, ['commencement_date' => '2026-12-01', 'expiry_date' => '2029-11-30']);

        expect(LeaseLadder::rungsByDay($lease, 'marketing'))->toStartWith('60@2026-11-05..2027-11-30 66@2027-12-01')
            ->and($lease->charges()->where('type', 'marketing')->where('is_active', false)->whereDate('start_date', '2026-09-10')->count())->toBe(1)
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toStartWith('1000@2026-12-01..2027-11-30 1100@2027-12-01');
    });
});

it('tells the operator what a move does before they make one', function () {
    asTenant($this->asset, function () {
        $lease = LeaseLadder::testersLease($this->asset);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $text = __('admin.helpers.commencement_redates_schedule');

            expect($text)->not->toBe('admin.helpers.commencement_redates_schedule')
                ->and(str_word_count($text) <= 18 || $locale === 'ar')->toBeTrue();

            Livewire::test(EditLease::class, ['record' => $lease->getKey()])
                ->assertSee($text);
        }
    });
});
