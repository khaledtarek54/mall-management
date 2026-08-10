<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * Lease-level billing frequency (operator decision 2026-07-19): a quarterly/semiannual/annual lease
 * pays IN ADVANCE — one invoice per cycle covering the whole cycle (rent + service + levy × months),
 * on cycle-start months only. Cycles are anchored to the lease's first billable month; every cycle
 * is a full N months. Default 'monthly' is unchanged. The multi-month invoice period forced a switch
 * of the anti-double-bill probe to item-type exclusion (below) — regression-covered here.
 */
function freqLease(string $frequency, array $extra = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), array_merge([
        'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
        'payment_terms_days' => 7, 'billing_frequency' => $frequency,
    ], $extra));
    Charge::create(['lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent', 'amount' => 10000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true]);
    Charge::create(['lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge', 'amount' => 2000,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => 14, 'start_date' => '2026-01-01', 'is_active' => true]);
    Charge::create(['lease_id' => $lease->id, 'name' => 'Marketing Levy', 'type' => 'marketing', 'amount' => 500,
        'currency' => 'EGP', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-01-01', 'is_active' => true]);

    return $lease->fresh();
}

function rentLine(Invoice $inv): InvoiceItem
{
    return $inv->items()->where('type', 'base_rent')->first();
}

it('bills a quarterly lease once per quarter, for the whole quarter', function () {
    $inv = app(MonthlyBillingService::class)
        ->generateForLease(freqLease('quarterly'), CarbonImmutable::parse('2026-01-01'))['invoice'];

    expect($inv->period_start->toDateString())->toBe('2026-01-01')
        ->and($inv->period_end->toDateString())->toBe('2026-03-31')   // whole quarter
        ->and((float) rentLine($inv)->amount)->toBe(30000.0)          // 10,000 × 3
        ->and((float) $inv->subtotal)->toBe(37500.0)                  // 30,000 + 6,000 + 1,500
        ->and((float) $inv->vat_amount)->toBe(840.0)                  // 14% of 6,000 service only
        ->and((float) $inv->total)->toBe(38340.0);
});

it('does not bill a quarterly lease on mid-cycle months, and starts the next cycle on time', function () {
    $lease = freqLease('quarterly');
    $svc = app(MonthlyBillingService::class);
    $svc->generateForLease($lease, CarbonImmutable::parse('2026-01-01')); // Q1 Jan–Mar

    expect($svc->generateForLease($lease, CarbonImmutable::parse('2026-02-01'))['reason'])->toBe('already_billed')
        ->and($svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['reason'])->toBe('already_billed');

    $q2 = $svc->generateForLease($lease, CarbonImmutable::parse('2026-04-01'))['invoice'];
    expect($q2->period_start->toDateString())->toBe('2026-04-01')
        ->and($q2->period_end->toDateString())->toBe('2026-06-30')
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(2);
});

it('reports off_cycle when a mid-cycle month is generated with no prior cycle invoice', function () {
    expect(app(MonthlyBillingService::class)
        ->generateForLease(freqLease('quarterly'), CarbonImmutable::parse('2026-02-01'))['reason'])->toBe('off_cycle');
});

it('the bulk run bills a quarterly lease only on cycle-start months', function () {
    $lease = freqLease('quarterly');
    $svc = app(MonthlyBillingService::class);

    expect($svc->runForPeriod(CarbonImmutable::parse('2026-01-01'))['created'])->toBe(1)
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-02-01'))['created'])->toBe(0)
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-03-01'))['created'])->toBe(0)
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-04-01'))['created'])->toBe(1)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(2);
});

it('is idempotent — re-running a quarterly cycle-start month creates no duplicate', function () {
    $lease = freqLease('quarterly');
    $svc = app(MonthlyBillingService::class);
    $svc->runForPeriod(CarbonImmutable::parse('2026-01-01'));
    $svc->runForPeriod(CarbonImmutable::parse('2026-01-01'));

    expect(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('bills an annual lease once for the whole year', function () {
    $lease = freqLease('annual');
    $svc = app(MonthlyBillingService::class);
    $inv = $svc->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];

    expect($inv->period_start->toDateString())->toBe('2026-01-01')
        ->and($inv->period_end->toDateString())->toBe('2026-12-31')
        ->and((float) rentLine($inv)->amount)->toBe(120000.0)                       // 10,000 × 12
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-06-01'))['created'])->toBe(0); // no mid-year bill
});

it('anchors cycles to the commencement month, not the calendar', function () {
    $lease = freqLease('quarterly', ['commencement_date' => '2026-02-01']); // Feb, May, Aug, Nov
    $svc = app(MonthlyBillingService::class);

    expect($svc->runForPeriod(CarbonImmutable::parse('2026-02-01'))['created'])->toBe(1)
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-04-01'))['created'])->toBe(0) // calendar Q2, not this lease's
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-05-01'))['created'])->toBe(1);
});

it('leaves a monthly lease unchanged', function () {
    $inv = app(MonthlyBillingService::class)
        ->generateForLease(freqLease('monthly'), CarbonImmutable::parse('2026-01-01'))['invoice'];

    expect($inv->period_start->toDateString())->toBe('2026-01-01')
        ->and($inv->period_end->toDateString())->toBe('2026-01-31')
        ->and((float) rentLine($inv)->amount)->toBe(10000.0);
});

it('shifts the first cycle to start after the fit-out grace', function () {
    // Gross grace: nothing bills for Jan+Feb, so the first CYCLE starts in March. Under net
    // abatement the lease bills its service charge from January, so the cycle anchors at
    // commencement instead — a different (and correct) answer, not a regression.
    $lease = freqLease('quarterly', ['rent_commencement_date' => '2026-03-01', 'fit_out_scope' => \App\Models\Lease::FIT_OUT_GROSS]); // free Jan+Feb → first cycle Mar–May
    $svc = app(MonthlyBillingService::class);

    expect($svc->runForPeriod(CarbonImmutable::parse('2026-01-01'))['created'])->toBe(0)
        ->and($svc->runForPeriod(CarbonImmutable::parse('2026-02-01'))['created'])->toBe(0);

    $q1 = $svc->generateForLease($lease, CarbonImmutable::parse('2026-03-01'))['invoice'];
    expect($q1->period_start->toDateString())->toBe('2026-03-01')
        ->and($q1->period_end->toDateString())->toBe('2026-05-31');
});

it('prorates only the first month of a quarterly cycle when commencing mid-month', function () {
    $lease = freqLease('quarterly', ['commencement_date' => '2026-01-15']);
    $inv = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-01-01'), prorate: true)['invoice'];

    // Jan prorated 17/31 + Feb + Mar full = (17/31 + 2) × 10,000
    $expected = round((17 / 31 + 2) * 10000, 2);
    expect((float) rentLine($inv)->amount)->toBe($expected)
        ->and($inv->period_start->toDateString())->toBe('2026-01-15')
        ->and($inv->period_end->toDateString())->toBe('2026-03-31');
});

it('carries billing_frequency onto a renewal', function () {
    $renewal = app(LeaseRenewalService::class)
        ->renew(freqLease('quarterly')->fresh(), ['new_term_months' => 12, 'new_rent' => 10000]);

    expect($renewal->billing_frequency)->toBe('quarterly');
});

/*
|--------------------------------------------------------------------------
| Expiry cap — a lease whose term isn't a whole number of cycles must NOT be
| billed past its expiry_date (caught by the pre-push adversarial review).
|--------------------------------------------------------------------------
*/
it('caps the final cycle at the lease expiry month — no billing past expiry', function () {
    // Quarterly lease Jan–Nov 2026. The Oct cycle-start would naively bill Oct–Dec (×3);
    // December is entirely after the lease ends, so the cycle must truncate to Oct–Nov.
    $lease = freqLease('quarterly', ['commencement_date' => '2026-01-01', 'expiry_date' => '2026-11-30']);
    $inv = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-10-01'))['invoice'];

    expect($inv->period_start->toDateString())->toBe('2026-10-01')
        ->and($inv->period_end->toDateString())->toBe('2026-11-30')   // capped at the expiry month, not Dec 31
        ->and((float) rentLine($inv)->amount)->toBe(20000.0);         // Oct + Nov only (×2, not ×3)
});

it('does not mint a full second-year invoice for a mid-month annual lease near expiry', function () {
    // Annual lease 15 Jan 2026 – 14 Jan 2027. Year 1 bills the full 2026; the Jan-2027 cycle-start
    // must bill only the final stub month, not another year — and since MF-02 that stub is itself
    // prorated to the 14 days the lease actually runs, the same rule a monthly lease now follows.
    $lease = freqLease('annual', ['commencement_date' => '2026-01-15', 'expiry_date' => '2027-01-14']);
    $svc = app(MonthlyBillingService::class);

    $y1 = $svc->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];
    expect($y1->period_end->toDateString())->toBe('2026-12-31')
        ->and((float) rentLine($y1)->amount)->toBe(120000.0);        // full 2026 (×12)

    $y2 = $svc->generateForLease($lease, CarbonImmutable::parse('2027-01-01'))['invoice'];
    expect($y2->period_start->toDateString())->toBe('2027-01-01')
        ->and($y2->period_end->toDateString())->toBe('2027-01-14')    // the day the lease ends
        ->and((float) rentLine($y2)->amount)->toBe(4516.13);         // 10,000 × 14/31, not another full year
});

/*
|--------------------------------------------------------------------------
| Hardened idempotency probe — the multi-month period forced a switch from a
| period-shape heuristic to item-type exclusion. These prove the special
| invoices (CAM recovery, %-overage) still don't suppress the regular rent,
| EVEN when their period overlaps the month being billed.
|--------------------------------------------------------------------------
*/
it('a period-overlapping CAM recovery invoice does not suppress the regular rent', function () {
    $lease = freqLease('monthly');
    $recovery = makeInvoice($lease, ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']); // overlaps Jan
    InvoiceItem::create(['invoice_id' => $recovery->id, 'description' => 'CAM Recovery 2026',
        'type' => 'cam_recovery', 'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000]);

    $inv = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];
    expect($inv)->not->toBeNull()
        ->and((float) rentLine($inv)->amount)->toBe(10000.0); // rent still billed despite the overlap
});

it('a %-overage invoice does not suppress the regular rent', function () {
    $lease = freqLease('monthly');
    $overage = makeInvoice($lease, ['period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
    InvoiceItem::create(['invoice_id' => $overage->id, 'description' => 'Percentage Rent Jan 2026',
        'type' => 'percentage_rent', 'amount' => 3000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 3000]);

    $inv = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-01-01'))['invoice'];
    expect($inv)->not->toBeNull()
        ->and((float) rentLine($inv)->amount)->toBe(10000.0);
});
