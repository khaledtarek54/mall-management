<?php

use App\Models\Charge;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;

/**
 * **A service charge is settled AFTER the month it covers.** — EG-30 (M-2)
 *
 * Everything billed in advance, always. Rent in advance is right and is what a lease says; a
 * service charge or a utility recharge is not, because until the month has run nobody knows what
 * the common area cost or what the meter read. The gap analysis put it plainly — *"a service charge
 * or utility recharge billed in arrears has no home"* — and the case that matters is MIXED: rent
 * ahead, service behind, on one lease.
 *
 * `charges.billing_timing` is therefore per ROW, not per lease, and both ride the SAME invoice with
 * each arrears line naming the month it covers. A second invoice per lease per month was rejected
 * on evidence: `alreadyBilledForMonth()` has silently suppressed a lease's base rent five times
 * over a second invoice dated into a billed month, and all five were one-offs — a recurring one
 * would fire every month for every arrears lease.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function arrearsLease(array $chargeOverrides = []): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 100000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    Charge::create(array_merge([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 12000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ], $chargeOverrides));

    return $lease->fresh();
}

/** The plan for one month, which is what the run and the preview both read. */
function planFor(Lease $lease, string $month): array
{
    $start = CarbonImmutable::parse($month)->startOfMonth();

    return app(MonthlyBillingService::class)
        ->planInvoiceForLease($lease, $start, $start->endOfMonth());
}

it('bills rent for this month and the service charge for last, on one invoice', function () {
    $lease = arrearsLease();

    $plan = planFor($lease, '2026-09-01');
    $descriptions = collect($plan['items'])->pluck('description');

    expect($plan['billable'])->toBeTrue()
        // One document, one header period — the whole reason this shape was chosen.
        ->and($plan['period_start']->format('Y-m'))->toBe('2026-09')
        ->and($descriptions)->toHaveCount(2);

    expect($descriptions->first(fn ($d) => str_contains($d, 'Base Rent')))
        ->toContain('September 2026');

    // …and the arrears line names AUGUST and says why, or a tenant reads it as a duplicate.
    $service = $descriptions->first(fn ($d) => str_contains($d, 'Service Charge'));

    expect($service)->toContain('August 2026')
        ->and($service)->toContain('in arrears');
});

it('leaves an advance charge exactly where it was', function () {
    // The deployment argument: null is the state every charge written before this is in.
    $lease = arrearsLease(['billing_timing' => null]);

    $plan = planFor($lease, '2026-09-01');

    foreach ($plan['items'] as $item) {
        expect($item['description'])->toContain('September 2026')
            ->and($item['description'])->not->toContain('in arrears');
    }
});

it('does not bill an arrears line on the lease\'s very first month', function () {
    // August 2026 lease, billed in August: the month the service charge would cover is July, before
    // the lease existed. It bills nothing now and bills normally next month — the honest answer
    // rather than a zero line the tenant has to ask about.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-08-01',
        'expiry_date' => '2030-12-31', 'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 100000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-08-01', 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 12000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-08-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $first = planFor($lease->fresh(), '2026-08-01');

    expect(collect($first['items'])->pluck('description'))->toHaveCount(1)
        ->and(collect($first['items'])->first()['description'])->toContain('Base Rent');

    // …and September carries August's service charge, so nothing was LOST — only deferred.
    $second = planFor($lease->fresh(), '2026-09-01');

    expect(collect($second['items'])->pluck('description')
        ->first(fn ($d) => str_contains($d, 'Service Charge')))->toContain('August 2026');
});

it('prorates an arrears line against the month it COVERS, not the month it is billed in', function () {
    // A lease commencing 15 August owes HALF of August's service charge, and that half appears on
    // the September invoice. Prorating against September instead would bill a full month for a half
    // month of service — plausible on the page, and wrong.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-08-15',
        'expiry_date' => '2030-12-31', 'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 31000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0, 'start_date' => '2026-08-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $plan = planFor($lease->fresh(), '2026-09-01');
    $line = collect($plan['items'])->first();

    // 15–31 August is 17 of 31 days. 31,000 × 17/31 = 17,000 exactly.
    expect($line['description'])->toContain('August 2026')
        ->and((float) $line['amount'])->toBe(17000.0);
});

it('refuses a timing the column does not accept', function () {
    // Registered in `ValueSets`, so the wildcard save listener refuses a third reading rather than
    // the column quietly acquiring one an importer invented.
    $lease = arrearsLease();

    expect(fn () => Charge::create([
        'lease_id' => $lease->id, 'name' => 'Odd', 'type' => 'service_charge',
        'amount' => 100, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => 'quarterly_in_hand',
    ]))->toThrow(DomainException::class);
});
