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
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

function billableFixture(array $attrs): Lease
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

it('prorates the final month of a lease that expires mid-period', function () {
    // A lease ending on the 10th is still billable for that month — the period overlaps its term —
    // and since MF-02 it bills only the 10 days it runs.
    //
    // **This reverses what this test pinned before 2026-08-09**, which was a full month, because
    // proration keyed on commencement only. That was a real rule, deliberately pinned here so a
    // change would be a decision rather than a surprise; the Yardi benchmark (scenario S8) is that
    // decision. Billing 21 days the tenant does not owe and unwinding it with a manual credit note
    // is not a commercial policy, it is an error with a workaround.
    //
    // Note the caller passes NO prorate flag: trailing proration is unconditional, unlike the
    // commencement-month kind, which stays an operator choice per run.
    $lease = billableFixture(['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2026-08-10']);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-08-01'));

    // 30,000 × 10/31
    expect($result['status'])->toBe('created')
        ->and((float) $lease->invoices()->sole()->total)->toBe(9677.42)
        // …and the invoice says what it actually covers, rather than claiming a whole month.
        ->and($lease->invoices()->sole()->period_end->toDateString())->toBe('2026-08-10');
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
        $viaScope = Lease::query()->billableForPeriod($period, $end)->whereKey($lease->id)->exists();

        expect($predicate)->toBe($viaScope,
            'isBillableForPeriod() and scopeBillableForPeriod() disagree for '.json_encode($attrs));
    }
});
