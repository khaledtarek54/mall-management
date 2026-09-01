<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\Support\LockSpy;

/**
 * The nightly sweep must not make the holdover decision unreachable.
 *
 * `leases:expire` runs at 05:15 and moves every active lease past its term with no `holdover_from`
 * to `expired`. That candidate set is **exactly** the holdover-conversion candidate set, and four
 * doors then shut on the same lease at once: the service refused anything but `active`, the button's
 * `visible()` asked `isHoldover()` (which requires `active`), the ActionRequired card's scope did
 * too, and the terminal-immutability hook refused every commercial write because `expired` is one of
 * `TERMINAL_STATUSES`. The whole LE-04 workflow was reachable from midnight to 05:15 on the single
 * morning after a term ended, and never again.
 *
 * The root cause is that `expired` is BOTH things at once. It is a PROJECTION
 * (`ProjectedState::PROJECTIONS['lease.term']`) — a machine's guess about today — and a member of
 * `TERMINAL_STATUSES`, a decision that closed the record. The registry's other two projections each
 * carve out a human's statement (`units.status = 'maintenance'`,
 * `rentable_items.status = 'out_of_service'`); this one had no carve-out and no way back. Whether the
 * tenant is still trading is the one fact only a person holds, which the conversion service's own
 * docblock says in writing — and the sweep was making that assertion on the operator's behalf,
 * irreversibly, before anyone was at their desk.
 *
 * What must NOT change: a tenancy somebody really closed stays closed. `terminated`, `cancelled` and
 * `renewed` are each a person's act with a successor document, and each is still absolutely
 * immutable.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
});

/** A lease whose term ran out months ago and which nobody has dealt with. */
function pastTermLease(array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(test()->asset), null, array_merge([
        'status' => 'active',
        'start_date' => '2024-01-01',
        // Month END, deliberately: holdover defaults to starting the day after the term ends, and
        // the service refuses a start inside a month the term already covered. A lease expiring
        // mid-month is a legitimate case that needs an explicit effective_from — not what this file
        // is about.
        'expiry_date' => CarbonImmutable::now()->subMonths(3)->endOfMonth()->toDateString(),
        'base_rent_monthly' => 100000,
        'holdover_rate_pct' => 150,
    ], $attrs));

    // The service holds over from the base-rent SCHEDULE, not from the lease column, so a lease
    // with no charge row has nothing to price the uplift against.
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => $lease->base_rent_monthly, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => $lease->start_date, 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('still offers the decision after the sweep has projected the lease as expired', function () {
    $lease = pastTermLease();

    // The control: before the sweep, the decision is outstanding.
    expect($lease->awaitsHoldoverDecision())->toBeTrue();

    $this->artisan('leases:expire')->assertSuccessful();

    $swept = $lease->fresh();

    expect($swept->status)->toBe('expired')                      // the sweep did its job
        ->and($swept->awaitsHoldoverDecision())->toBeTrue()      // …and the decision survives it
        ->and(Lease::query()->holdoverNeedingAction()->pluck('id'))->toContain($lease->id);
});

it('converts an expired lease to holdover, and brings it back to active', function () {
    $lease = pastTermLease();
    $this->artisan('leases:expire')->assertSuccessful();

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'reason' => 'The tenant is still trading while terms are renegotiated.',
    ]);

    $held = $lease->fresh();

    expect($held->holdover_from)->not->toBeNull()
        // Back to active, or the conversion would succeed and still bill nothing:
        // isBillableForPeriod() requires it.
        ->and($held->status)->toBe('active')
        // Priced at the uplift: 150% of the contracted rent.
        ->and((float) $held->base_rent_monthly)->toEqual(150000.0);
});

it('is stable under the sweep once converted — no oscillation', function () {
    $lease = pastTermLease();
    $this->artisan('leases:expire')->assertSuccessful();
    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['reason' => 'Still trading.']);

    // The sweep's candidate query excludes a lease with `holdover_from` set, so a converted holdover
    // must not be swept back to `expired` the next morning — which would undo the decision nightly.
    $this->artisan('leases:expire')->assertSuccessful();

    expect($lease->fresh()->status)->toBe('active')
        ->and($lease->fresh()->awaitsHoldoverDecision())->toBeFalse();
});

it('leaves a tenancy somebody actually closed alone', function () {
    // A FUTURE-dated termination, deliberately. A past-dated one sets `status = 'terminated'`, and
    // then the status clause alone satisfies this test — the event exclusion could be deleted and it
    // would stay green. Notice served for a future date leaves the lease `active`, which is exactly
    // the shape the exclusion exists for: the date has since passed, the 05:15 sweep has not run,
    // and the decision is closed even though the status does not say so yet.
    $terminated = pastTermLease();
    app(LeaseTerminationService::class)->terminate($terminated, [
        'termination_date' => CarbonImmutable::now()->addDays(3)->toDateString(),
        'reason' => 'Tenant vacated.',
    ]);

    $closed = $terminated->fresh();

    // Derived from the immutable termination EVENT, not from a new column.
    expect($closed->events()->where('type', LeaseEvent::TYPE_TERMINATION)->exists())->toBeTrue()
        ->and($closed->awaitsHoldoverDecision())->toBeFalse()
        ->and(Lease::query()->holdoverNeedingAction()->pluck('id'))->not->toContain($terminated->id);

    expect(fn () => app(ConvertLeaseToHoldoverService::class)->convert($closed, ['reason' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('converts a lease carrying a security deposit — the column that broke the first attempt', function () {
    // `Lease::saving` recomputes `security_deposit` whenever `base_rent_monthly` moves, and the
    // uplift moves it. The first carve-out was an ALLOWLIST of the columns the service writes, read
    // AFTER that hook had run — so it saw a column it did not list and refused, for every lease
    // carrying `security_deposit_months`, which the lease form defaults from the property setting.
    // The original fixture omitted it and passed; this is the case that matters.
    $lease = pastTermLease(['security_deposit_months' => 3]);
    $this->artisan('leases:expire')->assertSuccessful();

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['reason' => 'Still trading.']);

    expect($lease->fresh()->status)->toBe('active')
        ->and((float) $lease->fresh()->base_rent_monthly)->toEqual(150000.0)
        // The derived column moved with it, which is the whole point of letting it.
        ->and((float) $lease->fresh()->security_deposit)->toEqual(450000.0);
});

it('refuses to hold over a shop that has been re-let since it expired', function () {
    // Sequential, not a race: the sweep vacates the unit, leasing signs a new tenant, and weeks
    // later somebody works the ActionRequired card. Without the unit lock both leases end up
    // `active` on one shop, both billing, with `Unit::recomputeStatus()` reporting `occupied`
    // either way.
    $lease = pastTermLease();
    $unit = $lease->unit;
    $this->artisan('leases:expire')->assertSuccessful();

    $relet = makeLease($unit, null, [
        'status' => 'active',
        'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
    ]);

    // The card must not offer it either — a card that offers work the service declines is worse
    // than one that shows nothing.
    expect($lease->fresh()->awaitsHoldoverDecision())->toBeFalse()
        ->and(Lease::query()->holdoverNeedingAction()->pluck('id'))->not->toContain($lease->id);

    expect(fn () => app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['reason' => 'x']))
        ->toThrow(InvalidArgumentException::class);

    expect($relet->fresh()->status)->toBe('active');   // the new tenant is untouched
});

it('does not let the carve-out re-open a terminated lease', function () {
    // The carve-out is a SHAPE — expired → active with holdover_from moving from null — and no other
    // write has it. `terminated` fails the first clause, so the immutability hook still refuses.
    $lease = pastTermLease();
    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => CarbonImmutable::now()->subMonth()->toDateString(),
        'reason' => 'Tenant vacated.',
    ]);

    $closed = $lease->fresh();

    expect(fn () => $closed->update([
        'status' => 'active',
        'holdover_from' => CarbonImmutable::now()->toDateString(),
        'holdover_rate_pct' => 150,
    ]))->toThrow(DomainException::class);
});

it('does not let a crafted payload borrow the carve-out to change the deal', function () {
    $lease = pastTermLease();
    $this->artisan('leases:expire')->assertSuccessful();

    // The right two columns AND a commercial one. Not a resumption — refused.
    expect(fn () => $lease->fresh()->update([
        'status' => 'active',
        'holdover_from' => CarbonImmutable::now()->toDateString(),
        'expiry_date' => CarbonImmutable::now()->addYears(5)->toDateString(),
    ]))->toThrow(DomainException::class);
});

it('locks the unit before it makes the shop live again', function () {
    // `ConcurrencyPolicy::PROVEN` claims this service is driven through a LockSpy test, and for a
    // week it was not: the entry arrived with the holdover fix and the gate that checks the claim
    // was a hardcoded list of six in another file, so it went red over correct code and — CI being
    // paused — stayed red silently. The claim is now backed here.
    //
    // The lock is the whole of what stops the SEQUENTIAL double-booking: the 05:15 sweep vacates the
    // unit, leasing signs a new tenant, and weeks later somebody works the ActionRequired card. Both
    // leases end up `active` on one shop, both billing, and `Unit::recomputeStatus()` reports
    // `occupied` either way, so nothing on any screen says anything is wrong.
    //
    // `leases` is asserted as well as `units`, because the guard behind the lock has to be a LOCKING
    // READ or it answers from the snapshot taken before it waited — the F-09 finding, and the reason
    // `Unit::isActivelyLeasedForUpdate()` exists beside its plain twin.
    $lease = pastTermLease();
    $this->artisan('leases:expire')->assertSuccessful();

    $spy = LockSpy::watch(fn () => app(ConvertLeaseToHoldoverService::class)
        ->convert($lease->fresh(), ['reason' => 'Still trading.']));

    expect($spy->locked('units'))->toBeTrue(
        'ConvertLeaseToHoldoverService took no lock on `units`. Locked: '.implode(', ', $spy->lockedTables()))
        ->and($spy->locked('leases'))->toBeTrue(
            'the double-let guard read `leases` without a lock, so it decides from a stale snapshot');
});
