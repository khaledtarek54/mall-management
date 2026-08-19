<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Unit;
use App\Services\RentEscalationService;
use Carbon\CarbonImmutable;

/**
 * A lease whose term has run out is expired, its unit freed, and its rent stops escalating.
 *
 * **F-04 / F-05, pre-staging QA 2026-08-19.** There was a `vendors:expire-contracts` sweep for
 * vendor contracts and no equivalent for leases, so an `active` lease stayed active for ever unless
 * a person renewed, terminated or held it over. Measured on a lease that expired 2026-01-31, with
 * today at 2026-08-19:
 *
 *   - it still read `active` seven months later, and its unit still read `occupied` — so every
 *     occupancy figure, the occupancy map and the rent roll overstated;
 *   - **the unit could not be re-let**: creation refused with "this unit already has an active
 *     lease" on a shop that was physically empty;
 *   - `RentEscalationService` filtered on status alone and stepped its rent anyway, writing a
 *     schedule row starting 2026-08-01 on a tenancy that ended in January.
 *
 * Invoices were never at risk — `MonthlyBillingService` refuses an ended lease with `lease_ended`.
 * What was wrong was the STATE and everything that reads it.
 *
 * Two guards, kept separate on purpose: the sweep fixes the state, and the escalation query refuses
 * to act on a lease the sweep has not reached yet. A sweep that fails, or that has not run since the
 * expiry, must not leave rent escalating.
 */
beforeEach(function () {
    seedRoles();
    $this->asset = makeAsset();

    // A closure on the test case, NOT a file-scope function: two test files declaring the same
    // helper is a fatal redeclaration during collection, which `--parallel` hides and which has
    // already cost this project three debugging sessions (see CLAUDE.md).
    $this->endedLease = function (int $n = 1): Lease {
        $unit = makeUnit($this->asset, ['code' => "E-{$n}"]);

        return makeLease($unit, null, [
            'status' => 'active',
            'commencement_date' => '2023-01-01',
            'expiry_date' => '2026-01-31',
            'term_months' => 37,
        ]);
    };
});

it('expires a lease past its term and frees the unit', function () {
    $lease = ($this->endedLease)();
    $unit = Unit::find($lease->unit_id);

    expect($unit->fresh()->status)->toBe('occupied');

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($lease->fresh()->status)->toBe('expired')
        ->and($unit->fresh()->status)->toBe('vacant')
        ->and($unit->fresh()->isActivelyLeased())->toBeFalse();
});

it('leaves a lease still inside its term alone', function () {
    $unit = makeUnit($this->asset, ['code' => 'L-1']);
    $live = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ]);

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($live->fresh()->status)->toBe('active');
});

it('never expires a converted holdover', function () {
    // Its expiry is in the past BY DESIGN — `holdover_from` is what makes it billable at all, so
    // expiring it would end a tenancy the operator explicitly chose to continue.
    $unit = makeUnit($this->asset, ['code' => 'H-1']);
    $holdover = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => '2024-01-01',
        'expiry_date' => '2026-07-31',
        'holdover_from' => '2026-08-01',
    ]);

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($holdover->fresh()->status)->toBe('active')
        ->and(Unit::find($unit->id)->status)->toBe('occupied');
});

it('does not escalate the rent of a lease whose term has ended', function () {
    $lease = ($this->endedLease)(2);
    $lease->forceFill([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
        'next_escalation_date' => '2026-08-01',
    ])->save();

    $before = (float) $lease->base_rent_monthly;
    $rowsBefore = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->count();

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2026-08-19'));

    expect((float) $lease->fresh()->base_rent_monthly)->toBe($before)
        ->and(Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->count())->toBe($rowsBefore);
});

it('still escalates a holdover, whose expiry is past by design', function () {
    // The control. Excluding "expiry in the past" without this exception would silently stop
    // escalating every holdover — the population the rule is least entitled to freeze.
    $unit = makeUnit($this->asset, ['code' => 'H-2']);
    $holdover = makeLease($unit, null, [
        'status' => 'active',
        'commencement_date' => '2023-01-01',
        'expiry_date' => '2026-02-28',
        'holdover_from' => '2026-03-01',
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
    ]);
    $holdover->forceFill(['next_escalation_date' => '2026-08-01'])->save();

    $before = (float) $holdover->base_rent_monthly;

    app(RentEscalationService::class)->runForToday(CarbonImmutable::parse('2026-08-19'));

    expect((float) $holdover->fresh()->base_rent_monthly)->toBeGreaterThan($before);
});

it('re-projects a unit whose stored occupancy has gone stale', function () {
    // F-05. `Unit::recomputeStatus()` is correctly date-aware but only ever runs on a lease event,
    // the unit pages, or a space change — nothing ran on a schedule, so a future-dated give-back
    // left the column wrong from the day its date passed.
    $unit = makeUnit($this->asset, ['code' => 'S-1']);
    makeLease($unit, null, ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31']);

    $unit->forceFill(['status' => 'vacant'])->saveQuietly();

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($unit->fresh()->status)->toBe('occupied');
});

it('never overwrites a maintenance override', function () {
    $unit = makeUnit($this->asset, ['code' => 'M-1', 'status' => 'maintenance']);

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($unit->fresh()->status)->toBe('maintenance');
});
