<?php

/*
|--------------------------------------------------------------------------
| The manual "Generate Invoice" action must refuse what the batch run refuses
|--------------------------------------------------------------------------
| `runForPeriod()` filters eligibility in its QUERY — active status, commenced, not expired. The
| manual path is handed a lease the operator already picked, so it had no query to filter and
| applied NONE of those rules. Measured before the fix:
|
|   lease state          manual            scheduled batch
|   expired 2026-07-10   invoice CREATED   refused
|   terminated           invoice CREATED   refused
|   draft                invoice CREATED   refused
|
| An invoice is a real AR document that posts to the general ledger, so this let an operator bill a
| dead lease into the books with one click.
|
| The rule now lives once — `Lease::isBillableForPeriod()` with `scopeBillableForPeriod()` as its
| query form — because two copies of "which leases bill" is exactly how the two paths drifted.
*/

use App\Models\Charge;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

function billableFixture(array $attrs): App\Models\Lease
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), $attrs + ['base_rent_monthly' => 30000]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base rent', 'type' => 'base_rent',
        'amount' => 30000, 'frequency' => 'monthly', 'vat_applicable' => false,
        'vat_rate' => 0, 'is_active' => true, 'start_date' => '2024-01-01',
    ]);

    return $lease;
}

it('refuses to bill a lease the scheduled run would skip', function (string $case, array $attrs) {
    $lease = billableFixture($attrs);
    $period = CarbonImmutable::parse('2026-08-01');

    $manual = app(MonthlyBillingService::class)->generateForLease($lease, $period);

    expect($manual['status'])->toBe('skipped', "[{$case}] must not mint an invoice")
        ->and($manual['reason'])->toBe('lease_not_billable')
        ->and($lease->invoices()->count())->toBe(0);

    // …and the batch run agrees, which is the property that was broken.
    $batch = app(MonthlyBillingService::class)->runForPeriod($period);
    expect($batch['created'])->toBe(0, "[{$case}] the batch run must agree with the manual path");
})->with([
    ['terminated', ['status' => 'terminated', 'commencement_date' => '2025-01-01', 'expiry_date' => '2027-01-01']],
    ['draft', ['status' => 'draft', 'commencement_date' => '2025-01-01', 'expiry_date' => '2027-01-01']],
    ['expired before the period', ['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2026-07-10']],
    ['not yet commenced', ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2028-01-01']],
]);

it('still bills a healthy lease', function () {
    // The guard must refuse the dead cases without breaking the live one.
    $lease = billableFixture(['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2027-01-01']);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-08-01'));

    expect($result['status'])->toBe('created')
        ->and((float) $lease->invoices()->sole()->total)->toBe(30000.0);
});

it('bills the final month of a lease that expires mid-period', function () {
    // A lease ending on the 10th is still billable for that month — the period overlaps its term.
    // Note this is a FULL month, not pro-rata: proration keys on commencement only. That is the
    // system's rule (module 05 §2), and this pins it so a change is a decision, not a surprise.
    $lease = billableFixture(['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2026-08-10']);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-08-01'));

    expect($result['status'])->toBe('created')
        ->and((float) $lease->invoices()->sole()->total)->toBe(30000.0);
});

it('keeps one definition of billability — the predicate and the scope agree', function () {
    // The whole point of the fix. If these ever disagree the two paths drift apart again.
    $period = CarbonImmutable::parse('2026-08-01');
    $end = $period->endOfMonth();

    $cases = [
        ['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2027-01-01'],
        ['status' => 'terminated', 'commencement_date' => '2025-01-01', 'expiry_date' => '2027-01-01'],
        ['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2026-07-10'],
        ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2028-01-01'],
        // No open-ended case: `leases.expiry_date` is NOT NULL, so the null-expiry branch both
        // the predicate and the scope carry is unreachable today. Kept there as defence in case
        // the column is ever relaxed — but not asserted here, because a test over a state the
        // schema forbids proves nothing.
    ];

    foreach ($cases as $attrs) {
        $lease = billableFixture($attrs);

        $predicate = $lease->isBillableForPeriod($period, $end);
        $viaScope = App\Models\Lease::query()->billableForPeriod($period, $end)->whereKey($lease->id)->exists();

        expect($predicate)->toBe($viaScope,
            'isBillableForPeriod() and scopeBillableForPeriod() disagree for '.json_encode($attrs));
    }
});
