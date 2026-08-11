<?php

use App\Models\Lease;
use App\Services\LeaseCreationService;

/**
 * Deleting a lease must not break lease creation for the rest of the year.
 *
 * `Lease::generateReference()` was `count() + 1` against a UNIQUE column on a soft-deleting model.
 * The soft-delete scope hides trashed rows from `count()`, so the counter falls behind the numbers
 * actually issued: create five leases, delete one, and the next create computes `…-0005` — which
 * already exists. The insert throws a duplicate key, and it throws again on every subsequent
 * attempt, because the count never recovers. **New leases become impossible until the calendar
 * year rolls over.**
 *
 * It was reachable by design, not by misuse: `DeletionPolicy` classifies Lease as WHEN_UNUSED and
 * `EditLease` offers Delete/ForceDelete, so removing a draft lease that was never used is a
 * supported operator action.
 *
 * `Invoice` has had the correct shape — MAX over `withTrashed()`, a collision loop, and a lock
 * spanning the insert — the whole time, four files away. This is that shape, applied.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
});

function makeLeaseOn(object $asset): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'new',
        'tenant' => [
            'name' => 'Tenant '.uniqid(),
            'email' => uniqid().'@brand.test',
            'type' => 'company',
        ],
        'lease' => [
            'unit_id' => makeUnit($asset, ['status' => 'vacant'])->id,
            'commencement_date' => '2026-03-01',
            'term_months' => 12,
            'base_rent_monthly' => 10000,
        ],
    ]);
}

it('still allocates a fresh reference after a lease is soft-deleted', function () {
    $first = makeLeaseOn($this->asset);
    $second = makeLeaseOn($this->asset);

    expect($second->reference)->not->toBe($first->reference);

    // Charges block deletion (RefusesDeletionWhenReferenced), so the reachable shape is a lease
    // nothing references — which is precisely what DeletionPolicy's WHEN_UNUSED tier permits, and
    // precisely what LeaseImporter produces today, since it never seeds a charge schedule.
    $second->charges()->delete();
    $second->delete();

    $third = makeLeaseOn($this->asset);

    expect($third->reference)
        ->not->toBe($first->reference)
        ->and($third->reference)->not->toBe($second->reference);
});

it('keeps allocating after a lease is force-deleted', function () {
    $first = makeLeaseOn($this->asset);
    $second = makeLeaseOn($this->asset);
    $second->charges()->delete();
    $second->forceDelete();

    // A force-delete genuinely frees the number, so reuse is correct here — what must not happen
    // is a throw.
    $third = makeLeaseOn($this->asset);

    expect($third->reference)->toStartWith('LSE-MALL-');
});

it('never reuses a soft-deleted reference', function () {
    $first = makeLeaseOn($this->asset);
    $taken = $first->reference;
    $first->charges()->delete();
    $first->delete();

    $next = makeLeaseOn($this->asset);

    // The trashed row still holds the UNIQUE value, so handing it out again would be the same
    // duplicate-key crash wearing a different hat.
    expect($next->reference)->not->toBe($taken);
});

it('numbers monotonically within the property-year', function () {
    $a = makeLeaseOn($this->asset);
    $b = makeLeaseOn($this->asset);

    $prefix = Lease::referencePrefix('MALL');
    $seqA = (int) substr($a->reference, strlen($prefix));
    $seqB = (int) substr($b->reference, strlen($prefix));

    expect($seqB)->toBeGreaterThan($seqA);
});

it('scopes the sequence per property', function () {
    $other = makeAsset(['code' => 'POINT']);

    $a = makeLeaseOn($this->asset);
    $b = makeLeaseOn($other);

    // Two malls must not share a counter — one property's leasing should never renumber another's.
    expect($a->reference)->toStartWith('LSE-MALL-')
        ->and($b->reference)->toStartWith('LSE-POINT-');
});

it('honours a reference the caller supplied', function () {
    // Deliberately NOT Invoice's "always re-generate" rule. Importing an operator's existing
    // leases means importing the contract references they already use — those must survive the
    // insert, so allocation only fills a BLANK reference. A supplied duplicate is refused by the
    // UNIQUE index rather than silently renumbered, which is the right answer for someone else's
    // data.
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $tenant = makeTenant();

    $lease = makeLease($unit, $tenant, ['reference' => 'CONTRACT-2019-0042']);

    expect($lease->fresh()->reference)->toBe('CONTRACT-2019-0042');
});

it('allocates when the reference is blank', function () {
    $unit = makeUnit($this->asset, ['status' => 'vacant']);
    $tenant = makeTenant();

    $lease = makeLease($unit, $tenant, ['reference' => null]);

    expect($lease->fresh()->reference)->toStartWith(Lease::referencePrefix('MALL'));
});
