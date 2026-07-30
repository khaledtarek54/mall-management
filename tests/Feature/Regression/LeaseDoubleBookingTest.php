<?php

/*
|--------------------------------------------------------------------------
| A unit cannot end up carrying two active leases
|--------------------------------------------------------------------------
| The one invariant this module exists to protect. Two active leases on one shop means the shop is
| billed twice every month, two tenants hold a claim on it, and the occupancy figures the owner is
| shown are wrong.
|
| The hole was in LeaseRenewalService. Its `status === 'active'` guard sat OUTSIDE the transaction
| with no lock, so two requests that each loaded the lease before either committed — a double-click
| on "Renew", two admins, a retried POST — both passed it and both created an `active` renewal.
| Reproduced below: it left the unit with two active leases and the original in `renewed`.
|
| LeaseCreationService had the same shape in weaker form: its isActivelyLeased() guard is inside the
| transaction, but read the unit WITHOUT a row lock, and a snapshot read cannot see another
| transaction's uncommitted lease. Both now lock the unit row, so every path that can put an active
| lease on a unit contends on the same row.
*/

use App\Models\Lease;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Services\LeaseRenewalService;
use Illuminate\Validation\ValidationException;

/** Every active lease on the unit — via the pivot, so additional units count, not just the master. */
function activeLeasesOn(Unit $unit): int
{
    return $unit->allLeases()->where('leases.status', 'active')->count();
}

it('refuses a second renewal raced against the first', function () {
    $unit = makeUnit(makeAsset());
    $lease = makeLease($unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    // Two requests, each holding the lease as they read it — precisely what two concurrent
    // renewals hold, and what a double-click produces.
    $requestA = Lease::find($lease->id);
    $requestB = Lease::find($lease->id);
    $terms = ['new_term_months' => 12, 'new_rent' => 32000];

    app(LeaseRenewalService::class)->renew($requestA, $terms);

    expect(fn () => app(LeaseRenewalService::class)->renew($requestB, $terms))
        ->toThrow(InvalidArgumentException::class);

    expect(activeLeasesOn($unit))->toBe(1, 'the unit must carry exactly one active lease after a raced double-renewal')
        ->and($lease->fresh()->status)->toBe('renewed');
});

it('still renews normally', function () {
    // The guard must refuse the race without breaking the ordinary path.
    $unit = makeUnit(makeAsset());
    $lease = makeLease($unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2026-12-31',
        'base_rent_monthly' => 28000,
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, ['new_term_months' => 12, 'new_rent' => 32000]);

    expect($renewal->status)->toBe('active')
        ->and((float) $renewal->base_rent_monthly)->toBe(32000.0)
        ->and($renewal->previous_lease_id)->toBe($lease->id)
        ->and($lease->fresh()->status)->toBe('renewed')
        ->and(activeLeasesOn($unit))->toBe(1);
});

it('refuses a new lease on a unit that already has one', function () {
    $unit = makeUnit(makeAsset());
    makeLease($unit, makeTenant(), ['status' => 'active']);

    expect(fn () => app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => $unit->id,
            'commencement_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
            'term_months' => 12,
            'base_rent_monthly' => 30000,
            'service_charge_monthly' => 5000,
        ],
    ]))->toThrow(ValidationException::class);

    expect(activeLeasesOn($unit))->toBe(1);
});

it('takes a row lock on the unit in every path that can activate a lease', function () {
    // The guards above are check-then-act. Without a lock on the contended row they are advisory:
    // each concurrent transaction reads a snapshot in which the other's lease does not yet exist,
    // so both pass. A sequential test cannot reproduce that — it can only hold the line that the
    // lock is still there, which is what actually makes the guards authoritative.
    $unlocked = [];

    foreach ([LeaseCreationService::class, LeaseRenewalService::class] as $service) {
        $source = file_get_contents((new ReflectionClass($service))->getFileName());

        if (! str_contains($source, 'lockForUpdate')) {
            $unlocked[] = class_basename($service);
        }
    }

    expect($unlocked)->toBe([], implode('', [
        'These can put an active lease on a unit without locking the contended row: ',
        implode(', ', $unlocked).'. Two concurrent requests will both pass the occupancy check ',
        'and double-book the unit.',
    ]));
});
