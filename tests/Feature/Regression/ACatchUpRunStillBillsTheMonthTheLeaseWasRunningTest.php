<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * A CATCH-UP RUN STILL BILLS THE MONTH THE LEASE WAS ACTUALLY RUNNING. — SW-046
 *
 * `leases:expire` (05:15 nightly) projects every active lease past its term to `expired`. Both
 * halves of the billing-eligibility pair — `Lease::isBillableForPeriod()` and
 * `scopeBillableForPeriod()` — then tested `status === 'active'`, which is a fact about TODAY
 * asked inside a query about a PERIOD. So the morning after a term ended the lease vanished from
 * the run for every month, including the months it had been trading through.
 *
 * Traced on HEAD by reading the pair, not by running it — this file is what measures it. A lease
 * commencing 2025-01-01 and expiring 2026-08-31, swept to `expired` on 1 September, answers false
 * for AUGUST, so `billing:run-monthly --period=2026-08` — the documented recovery from a failed
 * billing night — puts it in the ordinary `skipped` counter. The final month of every tenancy that
 * ended between the failed run and the re-run is never invoiced, silently.
 *
 * `expired` is a PROJECTION of the dates: the sweep writes it and nothing else does, and only for a
 * term that has run out. `terminated`, `cancelled` and `renewed` are DECISIONS with their own
 * settlement behind them and stay refused — which is the control this file carries, because a rule
 * that admitted them would re-bill a lease whose final account has already been settled.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function leaseThatRanTo(string $expiry, string $status = 'active', float $rent = 30000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => $expiry,
        'base_rent_monthly' => $rent,
        'service_charge_monthly' => 0,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => $rent, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2025-01-01', 'is_active' => true,
    ]);

    // Set AFTER creation on purpose: `leases:expire` is what writes `expired`, and the transition
    // INTO a terminal state is exactly what the immutability hook allows.
    if ($status !== 'active') {
        $lease->update(['status' => $status]);
    }

    return $lease->fresh();
}

it('bills the final month of a lease the nightly sweep has already expired', function () {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');   // the catch-up, four days late

    $lease = leaseThatRanTo('2026-08-31', 'expired');
    $august = CarbonImmutable::parse('2026-08-01');

    expect($lease->isBillableForPeriod($august, $august->endOfMonth()))->toBeTrue();

    $result = app(MonthlyBillingService::class)->generateForLease($lease, $august);

    expect($result['status'])->toBe('created')
        ->and((float) $lease->invoices()->sole()->total)->toBe(30000.0);
});

it('picks it up in the catch-up BATCH, not only from the manual button', function () {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = leaseThatRanTo('2026-08-31', 'expired');

    $stats = app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-08-01'));

    expect($stats['created'])->toBe(1)
        ->and($lease->invoices()->count())->toBe(1);
});

it('agrees with itself — the predicate and the scope are one rule', function (string $case, string $status, string $expiry, bool $expected) {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = leaseThatRanTo($expiry, $status);
    $august = CarbonImmutable::parse('2026-08-01');
    $end = $august->endOfMonth();

    expect($lease->isBillableForPeriod($august, $end))->toBe($expected)
        ->and(Lease::query()->billableForPeriod($august, $end)->whereKey($lease->id)->exists())
        ->toBe($expected);
})->with([
    ['expired by the nightly sweep', 'expired', '2026-08-31', true],
    ['terminated — already settled', 'terminated', '2026-08-31', false],
    ['cancelled', 'cancelled', '2026-08-31', false],
    ['renewed — the successor bills', 'renewed', '2026-08-31', false],
    ['draft', 'draft', '2026-12-31', false],
    ['still active', 'active', '2026-12-31', true],
    ['expired before the period', 'expired', '2026-07-10', false],
]);

it('refuses a settled lease outright, however it was settled', function (string $status) {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = leaseThatRanTo('2026-08-31', $status);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease, CarbonImmutable::parse('2026-08-01'));

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('lease_not_billable')
        ->and($lease->invoices()->count())->toBe(0);
})->with(['terminated', 'cancelled', 'renewed']);

it('bills only the days the term actually ran, never the whole final month', function () {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    // Expired on the 15th, swept on the 16th, and this mall's billing day had not come round — so
    // the half-month was never invoiced at all. Admitting the lease must not widen what it bills.
    $lease = leaseThatRanTo('2026-08-15', 'expired');

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-08-01'));

    // 15 of August's 31 days under the `actual` method — the trailing-edge clip the planner has
    // always applied. `monthsCovered()` keeps the ratio at full precision and rounds the AMOUNT.
    expect((float) $lease->invoices()->sole()->total)
        ->toBe(round(30000 * 15 / 31, 2));
});

it('still refuses a month the term never reached', function () {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = leaseThatRanTo('2026-08-31', 'expired');
    $september = CarbonImmutable::parse('2026-09-01');

    expect($lease->isBillableForPeriod($september, $september->endOfMonth()))->toBeFalse();

    expect(app(MonthlyBillingService::class)->generateForLease($lease, $september)['reason'])
        ->toBe('lease_not_billable');
});
