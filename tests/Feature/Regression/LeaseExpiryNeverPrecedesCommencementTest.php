<?php

use App\Models\Charge;
use App\Services\LeaseTerminationService;

/**
 * A lease's expiry can never fall before its commencement — on ANY writer.
 *
 * THE GAP (validation sweep — leasing, 2026-08-11). The rule was a single `->after()` on the
 * standard lease form's `expiry_date` DatePicker. `LeaseTerminationService::terminate()` writes the
 * SAME column from an operator-supplied `termination_date`, and neither it nor its DatePicker
 * constrained the date at all — no `minDate`, no rule, nothing. Terminating a lease with a date
 * before it commenced produced:
 *
 *   - an inverted lease (expiry < commencement) that reads as expired while its status is active,
 *     and that `activeInPeriod()` can never match, so it bills nothing ever again;
 *   - recurring charges stamped `end_date` BEFORE their own `start_date`, which is a shape
 *     `atriom:audit-charge-schedules` exists to catch on import but nothing produced in-app;
 *   - a move-out statement and a final account frozen around a date the lease never reached.
 *
 * The fix is at the model, because the column has three writers (the form, termination, renewal)
 * and a guard per writer is the arrangement where one gets forgotten — the same reasoning as the
 * escalation collar directly above it in `Lease::booted()`.
 *
 * EQUAL IS ALLOWED, deliberately. A lease terminated on its own commencement date — a deal that
 * collapses on day one — is legitimate and must stay recordable. The form keeps its stricter
 * `->after()` for NEW leases, where a zero-day term is nonsense: layer 1 enforces the invariant
 * every writer must obey, layer 3 adds the stricter product rule for the create path.
 */
beforeEach(function () {
    $this->lease = makeLease(makeUnit(makeAsset()), null, [
        'commencement_date' => '2026-03-01',
        'expiry_date' => '2028-02-29',
    ]);
});

it('refuses a termination dated before the lease commenced', function () {
    expect(fn () => app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2026-01-15',   // six weeks BEFORE it commenced
        'reason' => 'mis-keyed year',
    ]))->toThrow(DomainException::class);

    // Nothing moved: the lease is still active on its original dates, and no charge was
    // stamped with a backwards end_date on the way out.
    $this->lease->refresh();
    expect($this->lease->status)->toBe('active')
        ->and($this->lease->expiry_date->toDateString())->toBe('2028-02-29')
        ->and(Charge::where('lease_id', $this->lease->id)->whereNotNull('end_date')->count())->toBe(0);
});

it('allows a termination ON the commencement date (a deal that collapses on day one)', function () {
    // The control, and a real case: equal is not inverted.
    $terminated = app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2026-03-01',
        'reason' => 'tenant withdrew at handover',
    ]);

    expect($terminated->status)->toBe('terminated')
        ->and($terminated->expiry_date->toDateString())->toBe('2026-03-01');
});

it('allows an ordinary early termination', function () {
    // The other control — without it the refusal above would pass just as happily if
    // terminate() were refusing everything.
    $terminated = app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2027-06-30',
        'reason' => 'break option exercised',
    ]);

    expect($terminated->status)->toBe('terminated')
        ->and($terminated->expiry_date->toDateString())->toBe('2027-06-30');
});

it('refuses a direct model write that inverts the dates (API / import / console)', function () {
    expect(fn () => $this->lease->update(['expiry_date' => '2026-02-01']))
        ->toThrow(DomainException::class);

    expect(fn () => $this->lease->update(['expiry_date' => '2029-02-28']))
        ->not->toThrow(DomainException::class);
});

it('refuses moving COMMENCEMENT past a fixed expiry — the same inversion from the other side', function () {
    // Guarding only the expiry column would leave the identical broken state reachable by
    // editing the other end of the range.
    expect(fn () => $this->lease->update(['commencement_date' => '2029-01-01']))
        ->toThrow(DomainException::class);
});

it('refuses creating an inverted lease outright', function () {
    expect(fn () => makeLease(makeUnit(makeAsset()), null, [
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2026-01-01',
    ]))->toThrow(DomainException::class);
});

it('has no open-ended leases to worry about — expiry_date is NOT NULL at layer 0', function () {
    // Worth pinning rather than assuming: the guard skips a null expiry (a holdover row could
    // plausibly have one), and that branch is only unreachable because the column forbids null.
    // If a migration ever relaxes it, this fails and the guard's null branch becomes live.
    expect(fn () => makeLease(makeUnit(makeAsset()), null, ['expiry_date' => null]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
