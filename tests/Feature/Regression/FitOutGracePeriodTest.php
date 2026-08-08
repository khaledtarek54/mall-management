<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Fit-out / rent-free grace period (operator decision 2026-07-19, OPEN-QUESTIONS C1.5 = FULL grace):
 * for `fit_out_months` whole months from the commencement month, NOTHING bills — not rent, service,
 * CAM, or marketing levy. Default 0 preserves today's behaviour. The grace does not carry on renewal.
 */

/** A lease with the full standard charge stack, so we can prove ALL charges are suppressed. */
function fitOutLease(int $months, string $commencement = '2026-01-01'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => $commencement, 'expiry_date' => '2027-12-31',
        'payment_terms_days' => 7, 'fit_out_months' => $months,
        // GROSS grace — the whole invoice is suppressed. Stated explicitly because new leases
        // now default to the industry-standard NET abatement (rent free, service charge still
        // payable); this file is specifically about the all-or-nothing behaviour the operator
        // chose in 2026-07-19, which existing leases keep. Net abatement has its own file:
        // tests/Feature/Regression/FitOutAbatementScopeTest.php.
        'fit_out_scope' => \App\Models\Lease::FIT_OUT_GROSS,
    ]);
    Charge::create(['lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => $commencement, 'is_active' => true]);
    Charge::create(['lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => 2000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => $commencement, 'is_active' => true]);
    Charge::create(['lease_id' => $lease->id, 'name' => 'Marketing Levy', 'type' => 'marketing', 'amount' => 500,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => $commencement, 'is_active' => true]);

    return $lease->fresh();
}

it('bills nothing during the fit-out grace, then the full stack after it ends', function () {
    $lease = fitOutLease(months: 2); // commences Jan 1 → Jan + Feb free, first bill Mar
    $svc = app(MonthlyBillingService::class);

    // Jan + Feb: the WHOLE invoice is suppressed (not just rent) — no invoice row at all.
    expect($svc->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['reason'])->toBe('fit_out')
        ->and($svc->generateForLease($lease, CarbonImmutable::parse('2026-02-01'))['reason'])->toBe('fit_out')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);

    // Mar: the first billable month — all three charges bill.
    $mar = $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];
    expect($mar)->not->toBeNull()
        ->and($mar->items()->count())->toBe(3); // rent + service + marketing
});

it('preserves today\'s behaviour when fit_out_months is 0', function () {
    $lease = fitOutLease(months: 0);

    $jan = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];
    expect($jan)->not->toBeNull()
        ->and($jan->items()->count())->toBe(3);
});

it('suppresses fit-out months in the bulk run too', function () {
    $lease = fitOutLease(months: 1); // Jan free, Feb bills
    $svc = app(MonthlyBillingService::class);

    $jan = $svc->runForPeriod(CarbonImmutable::parse('2026-01-01'));
    expect($jan['created'])->toBe(0)                                   // nothing billed in the grace month
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(0);

    $feb = $svc->runForPeriod(CarbonImmutable::parse('2026-02-01'));
    expect($feb['created'])->toBe(1)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('counts the grace from the commencement month even for a mid-month start', function () {
    $lease = fitOutLease(months: 2, commencement: '2026-01-15'); // Jan(partial) + Feb free, Mar bills

    expect($lease->firstBillableMonth()->toDateString())->toBe('2026-03-01')
        ->and($lease->periodInFitOut(CarbonImmutable::parse('2026-02-28')))->toBeTrue()
        ->and($lease->periodInFitOut(CarbonImmutable::parse('2026-03-31')))->toBeFalse();
});

it('does not carry the fit-out grace onto a renewal', function () {
    // ->fresh() so DB defaults are populated before the renewal copies the lease.
    $lease = fitOutLease(months: 3)->fresh();
    $renewal = app(LeaseRenewalService::class)->renew($lease, ['new_term_months' => 12, 'new_rent' => 10000]);

    expect($renewal->fit_out_months)->toBe(0); // a renewal has no new build-out → bills from day one
});
