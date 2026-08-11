<?php

use App\Models\Invoice;
use App\Models\Lease;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;

/**
 * Late-fee terms are per-lease (phase 4, story MF-08).
 *
 * One global rate, minimum and grace period were applied to every invoice in the portfolio. Real
 * leases do not agree on any of the three — an anchor negotiates 30 days' grace, a kiosk gets 5 —
 * so the system billed what the config said rather than what each contract said.
 *
 * Two traps are pinned here:
 *
 *   1. **The batch query must not filter on a global grace period.** It would exclude exactly the
 *      leases with the LONGEST negotiated grace from ever being considered — the ones whose terms
 *      most needed honouring — and it would do it silently.
 *   2. **The default is the SETTING, not `config('billing.*')`.** Until MF-08 the admin Settings
 *      page wrote `BillingSettings` while the service read the config file (populated from env), so
 *      every late-fee value an operator saved on that screen was ignored.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 2;
    $settings->late_fee_grace_days = 7;
    $settings->late_fee_minimum = 50;
});

function overdueInvoiceOn(Lease $lease, string $dueDate, float $balance = 10000): Invoice
{
    return makeInvoice($lease, [
        'due_date' => $dueDate,
        'status' => 'overdue',
        'balance' => $balance,
    ]);
}

it('honours a lease that negotiated its own rate and minimum', function () {
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 10,
        'late_fee_minimum' => 500,
    ]);
    $invoice = overdueInvoiceOn($lease, '2028-01-01', 10000);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();

    // 10% of 10,000, not the portfolio's 2%.
    expect((float) lateFeeItems($invoice)->sole()->amount)->toBe(1000.0);
});

it('applies the lease minimum when the percentage falls below it', function () {
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_percent' => 1, 'late_fee_minimum' => 500]);
    $invoice = overdueInvoiceOn($lease, '2028-01-01', 10000);

    app(LateFeeService::class)->applyTo($invoice);

    // 1% of 10,000 = 100, floored at the negotiated 500.
    expect((float) lateFeeItems($invoice)->sole()->amount)->toBe(500.0);
});

it('waits out a longer negotiated grace period, then charges', function () {
    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_grace_days' => 30]);
    $invoice = overdueInvoiceOn($lease, '2028-01-01');

    // Day 10: the portfolio default (7 days) would have charged by now. This lease bought 30.
    CarbonImmutable::setTestNow('2028-01-11');
    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and(lateFeeItems($invoice)->count())->toBe(0);

    // Day 31: grace has run out.
    CarbonImmutable::setTestNow('2028-02-01');
    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue()
        ->and(lateFeeItems($invoice)->count())->toBe(1);
});

it('still reaches a long-grace lease through the BATCH run, not just the direct call', function () {
    // Trap 1. A batch query filtering on the global grace period would never even look at this
    // invoice — the sweep would quietly never charge the leases with the longest terms.
    $lease = makeLease(makeUnit(makeAsset()), null, ['late_fee_grace_days' => 30]);
    $invoice = overdueInvoiceOn($lease, '2028-01-01');

    CarbonImmutable::setTestNow('2028-01-11');
    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2028-01-11'));
    expect(lateFeeItems($invoice)->count())->toBe(0);

    CarbonImmutable::setTestNow('2028-02-05');
    app(LateFeeService::class)->runForToday(CarbonImmutable::parse('2028-02-05'));
    expect(lateFeeItems($invoice)->count())->toBe(1);
});

it('falls back to the portfolio setting when the lease states no terms', function () {
    CarbonImmutable::setTestNow('2028-02-01');

    $lease = makeLease(makeUnit(makeAsset()));   // all three columns null
    $invoice = overdueInvoiceOn($lease, '2028-01-01', 10000);

    app(LateFeeService::class)->applyTo($invoice);

    // The SETTING's 2%, which is the value the admin Settings page writes.
    expect((float) lateFeeItems($invoice)->sole()->amount)->toBe(200.0);
});

it('reads the operator-editable setting rather than the config file', function () {
    // Trap 2, and the live bug MF-08 uncovered. Before this, changing the rate on the Settings
    // screen had no effect at all: the screen wrote BillingSettings, the sweep read
    // config('billing.late_fee_percent') from env. Setting the config here must NOT change the fee.
    CarbonImmutable::setTestNow('2028-02-01');

    config(['billing.late_fee_percent' => 99]);
    app(BillingSettings::class)->late_fee_percent = 3;

    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = overdueInvoiceOn($lease, '2028-01-01', 10000);

    app(LateFeeService::class)->applyTo($invoice);

    expect((float) lateFeeItems($invoice)->sole()->amount)->toBe(300.0);
});

it('never charges before the due date has even passed', function () {
    CarbonImmutable::setTestNow('2027-12-20');

    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = overdueInvoiceOn($lease, '2028-01-01');

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse();
});
