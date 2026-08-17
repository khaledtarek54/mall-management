<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * The SCHEDULED billing run must prorate a lease's commencement month.
 *
 * Until 2026-08-08 `runForPeriod()` called `generateInvoiceForLease()` without the `$prorate`
 * argument, so it took the default `false`. The only path that ever prorated was the manual
 * per-lease "Generate Invoice" action — which means a mid-month move-in was billed a FULL month
 * unless a human happened to bill that lease by hand before the nightly run reached it. The
 * overcharge therefore landed on precisely the leases nobody was watching.
 *
 * The proration ARITHMETIC was always correct and is covered by BillingMathTest; what these tests
 * pin is that the BULK path reaches it at all, and that nothing else moved.
 *
 * Found by the Yardi benchmark — docs/benchmarks/yardi/04-scenarios.md S2.
 */
function proratedLease(array $leaseAttrs = [], float $rent = 30000): Lease
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), null, array_merge([
        'commencement_date' => '2026-06-15',
        'expiry_date' => '2028-06-14',
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => 0,
    ], $leaseAttrs));

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => $rent,
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => true,
    ]);

    return $lease;
}

it('prorates the commencement month on the scheduled bulk run', function () {
    $lease = proratedLease(); // commences 15 June, 30-day month → 16/30 of the rent

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-06-01'));

    expect($stats['created'])->toBe(1);

    $invoice = Invoice::where('lease_id', $lease->id)->sole();

    // 30,000 x 16/30 = 16,000. Pre-fix the bulk run billed the full 30,000 — the tenant was
    // charged for 14 days before they had the keys.
    expect((float) $invoice->subtotal)->toBe(16000.0)
        // period_start is the COMMENCEMENT, not the 1st — that is what keeps the overlap-based
        // idempotency guard recognising June as billed.
        ->and($invoice->period_start->toDateString())->toBe('2026-06-15');
});

it('still bills a full month for a lease that commenced on the first', function () {
    $lease = proratedLease(['commencement_date' => '2026-06-01']);

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-06-01'));

    expect((float) Invoice::where('lease_id', $lease->id)->sole()->subtotal)->toBe(30000.0);
});

it('still bills a full month for every period after the commencement month', function () {
    $lease = proratedLease(); // commences 15 June

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-07-01'));

    $invoice = Invoice::where('lease_id', $lease->id)->sole();
    expect((float) $invoice->subtotal)->toBe(30000.0)
        ->and($invoice->period_start->toDateString())->toBe('2026-07-01');
});

it('does not double-bill the prorated month on a re-run', function () {
    $lease = proratedLease();
    $service = app(MonthlyBillingService::class);
    $june = CarbonImmutable::parse('2026-06-01');

    $service->runForPeriod($june);
    $second = $service->runForPeriod($june);

    expect($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});
