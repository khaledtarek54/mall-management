<?php

use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * **The final cycle settles its own month; then the lease continues, and settles it again.**
 *
 * An arrears row is billed one invoice late by design, so on the LAST cycle `coveredWindow()` runs
 * the window through the end of the period — otherwise the final month of every arrears charge is
 * silently never billed, which is the defect that carve-out was added for.
 *
 * But `isFinalCycle` is a PREDICTION — *"no later invoice will exist"* — and the lease can falsify
 * it. Convert to holdover, or simply extend the term, and the next run computes `isFinalCycle =
 * false`, so the arrears window shifts back a cycle onto the month the final invoice already
 * settled. Measured on a lease expiring 31 August with a 20,000/month arrears service charge:
 * August's invoice covers Jul–Aug (40,000), September's covers August AGAIN.
 *
 * **Nothing caught it.** `alreadyBilledForMonth()` compares the INVOICES' own periods — Aug 1–31
 * against Sep 1–30, no overlap — and the covered window was stored nowhere at all: it survived only
 * as English inside `description`. Both documents read plausibly alone ("Jul-Aug 2026", then "Aug
 * 2026"), so the tenant's accountant finds it or nobody does.
 *
 * The window is a COLUMN now, and the planner clamps against it. Null means *not recorded* — every
 * row written before the migration — so no historical invoice is reinterpreted.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function endingArrearsLease(string $expiry = '2026-08-31'): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => $expiry,
        'base_rent_monthly' => 50000,
    ]);

    // `ConvertLeaseToHoldoverService` prices the uplift off the base-rent SCHEDULE, so a lease
    // with no rent row cannot be held over at all.
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 50000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 20000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    return $lease->fresh();
}

/** Every service month covered by the lease's raised lines, as `start..end` strings. */
function coveredWindows(Lease $lease): array
{
    return InvoiceItem::query()
        ->whereIn('invoice_id', $lease->invoices()->pluck('id'))
        ->where('type', 'service_charge')
        ->whereNotNull('covered_start')
        ->orderBy('covered_start')
        ->get()
        ->map(fn (InvoiceItem $i) => $i->covered_start->toDateString().'..'.$i->covered_end->toDateString())
        ->all();
}

it('records the window each line covers', function () {
    // The enabling fact. Before this the planner computed it, priced from it, labelled from it —
    // and threw it away.
    $lease = endingArrearsLease();
    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-07-01'));

    expect(coveredWindows($lease->fresh()))->toBe(['2026-06-01..2026-06-30']);
});

it('does not bill August twice when the lease is held over', function () {
    $lease = endingArrearsLease();
    $billing = app(MonthlyBillingService::class);

    // The final cycle: July's service AND August's, because no later invoice was going to exist.
    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));
    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-08-31']);

    // …and then one does. The operator converts the lease to holdover.
    CarbonImmutable::setTestNow('2026-09-02');
    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), ['reason' => 'Still trading.']);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-09-01'));

    // September's invoice bills NOTHING for this row — August is settled, and September's own
    // service is not knowable until October. That is the arrears design, not a missed month…
    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-08-31']);

    // …and October picks it up exactly on schedule, which is what proves the clamp trims rather
    // than silently stops the charge.
    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-10-01'));

    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-08-31', '2026-09-01..2026-09-30']);
});

it('does not bill the month twice when the term is simply EXTENDED', function () {
    // The likelier path of the two, and the one the sweep row did not mention: no holdover, no
    // expired lease, no card — the operator just moves `expiry_date` forward.
    $lease = endingArrearsLease();
    $billing = app(MonthlyBillingService::class);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));
    $lease->fresh()->forceFill(['expiry_date' => '2027-08-31'])->saveQuietly();

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-09-01'));
    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-08-31']);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-10-01'));
    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-08-31', '2026-09-01..2026-09-30']);
});

it('still bills an ordinary arrears month behind its period', function () {
    // The control: the clamp must not eat a month that was never billed. Two consecutive runs on a
    // lease that is going nowhere.
    $lease = endingArrearsLease('2030-12-31');
    $billing = app(MonthlyBillingService::class);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-07-01'));
    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect(coveredWindows($lease->fresh()))->toBe(['2026-06-01..2026-06-30', '2026-07-01..2026-07-31']);
});

it('still settles the final month, which is what the carve-out is for', function () {
    // The other control, and the one that matters most: removing the double-bill must not
    // reintroduce the defect `isFinalCycle` was added to fix — an arrears charge whose last month
    // is never billed at all.
    $lease = endingArrearsLease();
    $billing = app(MonthlyBillingService::class);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-07-01'));
    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect(coveredWindows($lease->fresh()))->toBe(['2026-06-01..2026-06-30', '2026-07-01..2026-08-31']);
});

it('leaves a line written before the migration alone', function () {
    // Null `covered_end` means NOT RECORDED, not covers-nothing. A backfill was refused because the
    // honest source for a legacy row is prose: guessing the period from the invoice would be right
    // for advance rows and wrong for exactly the arrears rows this protects, which could SUPPRESS a
    // real bill. Losing a month of revenue is worse than the duplicate this fixes.
    $lease = endingArrearsLease('2030-12-31');
    $billing = app(MonthlyBillingService::class);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-07-01'));
    InvoiceItem::query()->update(['covered_start' => null, 'covered_end' => null]);

    $billing->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));

    // The July run's line is invisible to the clamp, so August bills exactly as it always did.
    expect(coveredWindows($lease->fresh()))->toBe(['2026-07-01..2026-07-31']);
});
