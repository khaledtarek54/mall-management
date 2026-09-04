<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * ENDING AN ARREARS CHARGE STILL BILLS THE MONTH IT CONSUMED. — SW-047
 *
 * EG-30's arrears rows bill one cycle BEHIND the window they cover: the September invoice settles
 * August's service charge, because until August has run nobody knows what the common area cost.
 *
 * `ChargeScheduleService::close()` writes `end_date = $from - 1 day` AND, for a stop that has
 * already arrived, `is_active = false`. `MonthlyBillingService` drops an inactive row at the TOP of
 * its planner, before the covered window is even computed — so ending a service charge from
 * 1 September, on 2 September, on a mall whose billing day has not come round yet, forfeited the
 * last month the tenant actually consumed. Silently, and it is the operator's own revenue.
 *
 * `end_date` was always the real bound: the planner tests it against the window the row COVERS
 * (August), not against the invoice's own period, so the row stops itself after August whether or
 * not the flag is set. All the flag has to do is wait one billing cycle past the stop — which is
 * the reasoning `close()` already carried for a stop dated in the FUTURE, applied to the half
 * nobody had done.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function leaseWithAnArrearsServiceCharge(bool $arrears = true): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 20000,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 100000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => $arrears ? Charge::TIMING_ARREARS : null,
    ]);

    return $lease->fresh();
}

/** @return array<int, array<string, mixed>> */
function arrearsPlanItems(Lease $lease, string $month): array
{
    $start = CarbonImmutable::parse($month)->startOfMonth();

    return app(MonthlyBillingService::class)
        ->planInvoiceForLease($lease->fresh(), $start, $start->endOfMonth())['items'] ?? [];
}

it('still bills August when an arrears service charge is stopped from 1 September', function () {
    CarbonImmutable::setTestNow('2026-09-02 10:00:00');   // this month's run has not fired yet

    $lease = leaseWithAnArrearsServiceCharge();

    app(ChargeScheduleService::class)
        ->close($lease, 'service_charge', CarbonImmutable::parse('2026-09-01'));

    $row = $lease->charges()->where('type', 'service_charge')->sole();

    expect($row->end_date->toDateString())->toBe('2026-08-31')
        // The row the September invoice still needs. `end_date` is what stops it, not the flag.
        ->and($row->is_active)->toBeTrue();

    $line = collect(arrearsPlanItems($lease, '2026-09-01'))
        ->first(fn (array $i): bool => $i['type'] === 'service_charge');

    expect($line)->not->toBeNull()
        ->and($line['description'])->toContain('August 2026')
        ->and((float) $line['amount'])->toBe(20000.0);
});

it('stops after that — the end date is what bounds it, not the flag', function () {
    CarbonImmutable::setTestNow('2026-09-02 10:00:00');

    $lease = leaseWithAnArrearsServiceCharge();

    app(ChargeScheduleService::class)
        ->close($lease, 'service_charge', CarbonImmutable::parse('2026-09-01'));

    // October's invoice covers SEPTEMBER, which the tenant did not consume.
    expect(collect(arrearsPlanItems($lease, '2026-10-01'))->pluck('type'))
        ->not->toContain('service_charge');
});

it('deactivates an ADVANCE row on the spot, exactly as it always did', function () {
    CarbonImmutable::setTestNow('2026-09-02 10:00:00');

    $lease = leaseWithAnArrearsServiceCharge(arrears: false);

    app(ChargeScheduleService::class)
        ->close($lease, 'service_charge', CarbonImmutable::parse('2026-09-01'));

    $row = $lease->charges()->where('type', 'service_charge')->sole();

    expect($row->is_active)->toBeFalse()
        ->and($row->end_date->toDateString())->toBe('2026-08-31');

    // An advance row bills the month it names, so September owes nothing and never did.
    expect(collect(arrearsPlanItems($lease, '2026-09-01'))->pluck('type'))
        ->not->toContain('service_charge');
});

it('deactivates an arrears row once the invoice that settles it has been and gone', function () {
    CarbonImmutable::setTestNow('2026-09-02 10:00:00');

    $lease = leaseWithAnArrearsServiceCharge();

    // Stopped from 1 June: June's window was settled on the July invoice, two months ago. The wait
    // is ONE billing cycle, not for ever.
    app(ChargeScheduleService::class)
        ->close($lease, 'service_charge', CarbonImmutable::parse('2026-06-01'));

    expect($lease->charges()->where('type', 'service_charge')->sole()->is_active)->toBeFalse();
});

it('leaves the rest of the schedule alone', function () {
    CarbonImmutable::setTestNow('2026-09-02 10:00:00');

    $lease = leaseWithAnArrearsServiceCharge();

    app(ChargeScheduleService::class)
        ->close($lease, 'service_charge', CarbonImmutable::parse('2026-09-01'));

    // Rent is billed in advance and was never in question — a stop on one charge code must not
    // reach another, and September's rent is still owed.
    $rent = collect(arrearsPlanItems($lease, '2026-09-01'))
        ->first(fn (array $i): bool => $i['type'] === 'base_rent');

    expect($rent)->not->toBeNull()
        ->and((float) $rent['amount'])->toBe(100000.0);
});
