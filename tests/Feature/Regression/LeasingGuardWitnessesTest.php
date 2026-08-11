<?php

use App\Models\Charge;
use App\Support\Vat;

/**
 * Witnesses for two leasing guards that were correct and untested.
 *
 * The 2026-08-11 validation sweep traced every leasing rule to its layer. These two were already
 * where they belong — model hooks, so every writer obeys them — but nothing had ever watched them
 * refuse anything. A guard nobody has seen fail is a guard nobody knows works: it can be deleted,
 * narrowed by a well-meant early return, or quietly disabled by a `saveQuietly()` somewhere
 * upstream, and the suite stays green either way.
 *
 * These add nothing to the code. They add the failing observation the guards never had.
 */
beforeEach(function () {
    $this->lease = makeLease(makeUnit(makeAsset()), null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ]);
});

/* ---- the charge schedule cannot cover one month twice (Charge::assertNoScheduleOverlap) ------- */

function rentRow(int $leaseId, ?string $start, ?string $end, array $attrs = []): Charge
{
    return Charge::create(array_merge([
        'lease_id' => $leaseId,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 10000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => Vat::EXEMPT,
        'start_date' => $start,
        'end_date' => $end,
        'is_active' => true,
    ], $attrs));
}

it('refuses a second rent row covering a month the first already covers', function () {
    rentRow($this->lease->id, '2026-01-01', '2026-12-31');

    // Overlaps June–December. Left unguarded, MonthlyBillingService refuses to bill the
    // ambiguous months — so the lease silently stops invoicing rather than double-billing,
    // which is the harder failure to notice.
    expect(fn () => rentRow($this->lease->id, '2026-06-01', '2027-06-30'))
        ->toThrow(DomainException::class);
});

it('allows an ADJACENT row — the shape a rent ladder actually has', function () {
    // The control. `ChargeScheduleService` closes one row the day before the next begins, so if
    // the guard rejected touching ranges every escalation step would be refused.
    rentRow($this->lease->id, '2026-01-01', '2026-12-31');

    expect(fn () => rentRow($this->lease->id, '2027-01-01', '2027-12-31'))
        ->not->toThrow(DomainException::class);
});

it('refuses an unbounded row that swallows an existing one', function () {
    rentRow($this->lease->id, '2027-01-01', '2027-12-31');

    // A null end date is unbounded, so this covers 2027 too.
    expect(fn () => rentRow($this->lease->id, '2026-01-01', null))
        ->toThrow(DomainException::class);
});

it('does not police one-off charges, which legitimately share a month', function () {
    // A CAM true-up, a percentage-rent overage and a utility recharge all land in the same month
    // and are not a schedule. Narrowing this would break real billing.
    $oneOff = ['frequency' => 'one_time', 'type' => 'utility', 'name' => 'Water recharge'];

    rentRow($this->lease->id, '2026-06-01', '2026-06-30', $oneOff);

    expect(fn () => rentRow($this->lease->id, '2026-06-01', '2026-06-30', $oneOff))
        ->not->toThrow(DomainException::class);
});

it('separates the guard by charge TYPE — rent and service charge cover the same months', function () {
    rentRow($this->lease->id, '2026-01-01', '2026-12-31');

    expect(fn () => rentRow($this->lease->id, '2026-01-01', '2026-12-31', [
        'type' => 'service_charge', 'name' => 'Service Charge', 'vat_applicable' => true,
        'vat_rate' => Vat::standardRate(),
    ]))->not->toThrow(DomainException::class);
});

/* ---- a terminal lease is immutable (Lease::booted) -------------------------------------------- */

it('refuses a commercial edit to a terminated lease', function () {
    $this->lease->update(['status' => 'terminated']);

    expect(fn () => $this->lease->fresh()->update(['base_rent_monthly' => 999]))
        ->toThrow(DomainException::class);
});

it('refuses re-opening a terminated lease back to active', function () {
    // The exploit the guard was written for: flip it back to active, then edit it freely.
    $this->lease->update(['status' => 'terminated']);

    expect(fn () => $this->lease->fresh()->update(['status' => 'active']))
        ->toThrow(DomainException::class);
});

it('still allows the annotation and soft-delete housekeeping it deliberately carves out', function () {
    // The control, and a real need: an operator must be able to record WHY after the fact, and
    // the record must stay soft-deletable.
    $this->lease->update(['status' => 'terminated']);

    expect(fn () => $this->lease->fresh()->update(['notes' => 'Settled at move-out on 2026-08-11.']))
        ->not->toThrow(DomainException::class);

    expect(fn () => $this->lease->fresh()->delete())->not->toThrow(DomainException::class);
});

it('allows the transition INTO a terminal state (the guard reads the ORIGINAL status)', function () {
    // Without this the guard would block the very act of terminating.
    expect(fn () => $this->lease->update(['status' => 'terminated']))
        ->not->toThrow(DomainException::class);

    expect($this->lease->fresh()->status)->toBe('terminated');
});
