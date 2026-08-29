<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Services\LeaseTerminationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * A TERMINATION DATED IN THE FUTURE IS NOTICE, AND A LEASE UNDER NOTICE STILL BILLS.
 *
 * The field offers any date from commencement onward and has no upper bound, which is right — a
 * tenant gives notice months before they go, and recording it the day they hand it in is the point.
 * What was wrong is what happened next: the status went to `terminated` and every charge row was
 * deactivated immediately, so two independent blockers stopped the billing at once —
 * `isBillableForPeriod()` refuses a lease that is not `active`, and the planner skips an inactive
 * charge row.
 *
 * Measured: a lease terminated on 30 August effective 30 November stopped billing in September.
 * Three months of rent the tenant genuinely owes, never invoiced, with nothing on any screen to say
 * a lease had gone quiet — a lease that bills nothing looks exactly like a lease with nothing due.
 *
 * Under notice the lease stays ACTIVE and the charge rows stay live with an `end_date`; the
 * schedule is date-ranged, so they stop themselves on the day. `leases:expire` then closes it and
 * frees the unit, reading the termination EVENT to close it as `terminated` rather than `expired` —
 * derived from the lease's own history, never a second column.
 */
function leaseBillingMonthly(): Lease
{
    $lease = Lease::factory()->create([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::today()->subYear()->startOfMonth(),
        'expiry_date' => CarbonImmutable::today()->addYears(2)->endOfMonth(),
        'base_rent_monthly' => 40_000,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
        'billing_frequency' => 'monthly',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 40_000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'start_date' => $lease->commencement_date, 'is_active' => true,
    ]);

    return $lease->fresh();
}

it('keeps a lease under notice active, occupied and billing', function (): void {
    $lease = leaseBillingMonthly();
    $leaving = CarbonImmutable::today()->addMonths(3)->endOfMonth();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => $leaving,
        'reason' => 'notice given',
    ]);

    $lease = $lease->fresh();

    expect($lease->status)->toBe('active')
        ->and($lease->expiry_date->toDateString())->toBe($leaving->toDateString())
        ->and($lease->charges()->where('is_active', true)->count())->toBeGreaterThan(0)
        // The tenant is still in the shop until the day they leave.
        ->and($lease->unit->fresh()->status)->toBe('occupied');

    // Every month up to the leaving date still bills.
    foreach ([1, 2, 3] as $ahead) {
        $period = CarbonImmutable::today()->addMonths($ahead)->startOfMonth();

        expect($lease->isBillableForPeriod($period, $period->endOfMonth()))
            ->toBeTrue("month +{$ahead} should still bill");
    }

    // And the month after it does not.
    $after = $leaving->addMonth()->startOfMonth();
    expect($lease->isBillableForPeriod($after, $after->endOfMonth()))->toBeFalse();
});

it('raises a real invoice for a month inside the notice period', function (): void {
    $lease = leaseBillingMonthly();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => CarbonImmutable::today()->addMonths(3)->endOfMonth(),
        'reason' => 'notice given',
    ]);

    $period = CarbonImmutable::today()->addMonth()->startOfMonth();
    $result = app(MonthlyBillingService::class)->generateForLease($lease->fresh(), $period);

    // The predicate is not the claim — the invoice is.
    expect($result['invoice'])->not->toBeNull()
        ->and(round((float) $result['invoice']->subtotal, 2))->toBe(40_000.0);
});

it('ends immediately when the termination is today or in the past', function (): void {
    $lease = leaseBillingMonthly();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => CarbonImmutable::today(),
        'reason' => 'left today',
    ]);

    $lease = $lease->fresh();

    expect($lease->status)->toBe('terminated')
        ->and($lease->charges()->where('is_active', true)->count())->toBe(0);
});

it('closes a lease that was under notice as terminated, not expired', function (): void {
    $lease = leaseBillingMonthly();
    $leaving = CarbonImmutable::today()->addMonths(2)->endOfMonth();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => $leaving,
        'reason' => 'notice given',
    ]);

    expect($lease->fresh()->status)->toBe('active');

    CarbonImmutable::setTestNow($leaving->addDays(2));
    Carbon\Carbon::setTestNow($leaving->addDays(2));

    $this->artisan('leases:expire')->assertExitCode(0);

    // Both reach the sweep the same way; they are different facts, and a report of early exits
    // must not read as a term that simply ran its course.
    expect($lease->fresh()->status)->toBe('terminated');

    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

it('still expires a lease whose term merely ran out', function (): void {
    $lease = leaseBillingMonthly();
    $lease->forceFill(['expiry_date' => CarbonImmutable::today()->subDay()])->saveQuietly();

    expect(LeaseEvent::where('lease_id', $lease->id)->where('type', 'termination')->exists())->toBeFalse();

    $this->artisan('leases:expire')->assertExitCode(0);

    expect($lease->fresh()->status)->toBe('expired');
});

it('leaves an already-closed rung of the rent ladder closed', function (): void {
    $lease = leaseBillingMonthly();

    // The state every escalating lease is in: a closed rung and the one that succeeded it.
    $closed = $lease->charges()->where('type', 'base_rent')->first();
    $closed->update(['end_date' => CarbonImmutable::today()->subMonths(2)->endOfMonth()]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 44_000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'start_date' => CarbonImmutable::today()->subMonth()->startOfMonth(), 'is_active' => true,
    ]);

    $leaving = CarbonImmutable::today()->addMonths(3)->endOfMonth();

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => $leaving,
        'reason' => 'notice given',
    ]);

    // A blanket `update()` writes the termination date over EVERY row, which is invisible while
    // they are all being deactivated in the same statement — and the moment they stay live it
    // RE-OPENS the closed rung, so two rows cover the same month and the billing run throws.
    expect($closed->fresh()->end_date->toDateString())
        ->toBe(CarbonImmutable::today()->subMonths(2)->endOfMonth()->toDateString());

    // The claim is the billing, not the column.
    $period = CarbonImmutable::today()->addMonth()->startOfMonth();
    $result = app(MonthlyBillingService::class)->generateForLease($lease->fresh(), $period);

    expect($result['invoice'])->not->toBeNull()
        ->and(round((float) $result['invoice']->subtotal, 2))->toBe(44_000.0);
});

it('deactivates the whole schedule once the tenancy is actually over', function (): void {
    $lease = leaseBillingMonthly();
    $lease->charges()->first()->update(['end_date' => CarbonImmutable::today()->subMonths(2)->endOfMonth()]);

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => CarbonImmutable::today(),
        'reason' => 'left today',
    ]);

    // Closed rungs included: once the tenancy is over nothing on it is live.
    expect($lease->fresh()->charges()->where('is_active', true)->count())->toBe(0);
});
