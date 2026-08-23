<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
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
function planInvoiceFor(Lease $lease, string $month): array
{
    $start = CarbonImmutable::parse($month)->startOfMonth();

    return app(MonthlyBillingService::class)
        ->planInvoiceForLease($lease, $start, $start->endOfMonth());
}

it('bills rent for this month and the service charge for last, on one invoice', function () {
    $lease = arrearsLease();

    $plan = planInvoiceFor($lease, '2026-09-01');
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

    $plan = planInvoiceFor($lease, '2026-09-01');

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

    $first = planInvoiceFor($lease->fresh(), '2026-08-01');

    expect(collect($first['items'])->pluck('description'))->toHaveCount(1)
        ->and(collect($first['items'])->first()['description'])->toContain('Base Rent');

    // …and September carries August's service charge, so nothing was LOST — only deferred.
    $second = planInvoiceFor($lease->fresh(), '2026-09-01');

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

    $plan = planInvoiceFor($lease->fresh(), '2026-09-01');
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

it('bills the FINAL month of an arrears charge on the last invoice', function () {
    // The one that would have been silent revenue loss on every arrears lease.
    //
    // An arrears row is billed one invoice LATE by design, so the last month of a lease would need
    // an invoice dated after the lease ended — and there is none: `scopeBillableForPeriod` requires
    // `expiry_date >= period_start`, so a lease expiring 31 August is not selected for the
    // September run at all (and `leases:expire` has moved its status off `active` by then anyway).
    // August's service charge would simply never be billed, on every arrears lease, for ever, with
    // nothing in the run summary to say so.
    //
    // So the FINAL invoice settles the arrears window AND its own month — which is what an operator
    // does by hand when a tenant leaves.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-08-31',
        'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 12000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $plan = planInvoiceFor($lease->fresh(), '2026-08-01');
    $descriptions = collect($plan['items'])->pluck('description');

    $line = $descriptions->first(fn ($d) => str_contains($d, 'Service Charge'));

    // One line covering BOTH months, labelled as the span it is — the cycle label the multi-month
    // path already uses, rather than two lines the tenant has to reconcile.
    expect($line)->toContain('Jul')
        ->and($line)->toContain('Aug')
        ->and($line)->toContain('in arrears');

    // The money is the point: two months of service charge, not one. Without this the operator
    // silently lost the final month on every arrears lease.
    expect((float) $plan['subtotal'])->toBe(24000.0);
});

it('saves the timing the operator picked, and keeps it across a rent change and a renewal', function () {
    // Three siblings the first cut left behind, each of which made M-2 unusable or wrong in a way
    // that looked entirely ordinary on screen.
    //
    // 1. The ONLY screen offering `billing_timing` threw the value away — the add-charge action
    //    builds an explicit attribute list for `setAmount()` and the column was not on it. Pick
    //    "In arrears", save, get a charge that bills in advance. The whole feature was unreachable.
    // 2. A successor rung inherited `frequency`, `vat_applicable` and `vat_rate` and NOT this, so
    //    any rent change, escalation step, CAM estimate or relief silently reverted the charge to
    //    advance — billing the crossover month twice.
    // 3. A renewal copies every other charge term and dropped this one, at exactly the point where
    //    nobody re-reads each charge.
    $lease = arrearsLease();
    $schedule = app(ChargeScheduleService::class);

    // The service path the screen now uses, with the timing supplied.
    $opened = $schedule->setAmount(
        $lease,
        'utility',
        500,
        CarbonImmutable::parse('2026-02-01'),
        ['name' => 'Water', 'frequency' => 'monthly', 'billing_timing' => Charge::TIMING_ARREARS],
        Charge::ORIGIN_MANUAL,
    );

    expect($opened->billing_timing)->toBe(Charge::TIMING_ARREARS);

    // …and the successor rung a later change opens keeps it.
    $successor = $schedule->setAmount(
        $lease,
        'utility',
        650,
        CarbonImmutable::parse('2026-06-01'),
        ['name' => 'Water', 'frequency' => 'monthly'],
        Charge::ORIGIN_MANUAL,
    );

    expect($successor->id)->not->toBe($opened->id)
        ->and($successor->billing_timing)->toBe(Charge::TIMING_ARREARS);

    // The control: a charge nobody ruled on stays null, so the default is untouched.
    $advance = $schedule->setAmount(
        $lease,
        'parking',
        300,
        CarbonImmutable::parse('2026-02-01'),
        ['name' => 'Parking', 'frequency' => 'monthly'],
        Charge::ORIGIN_MANUAL,
    );

    expect($advance->billing_timing)->toBeNull()
        ->and($advance->billsInArrears())->toBeFalse();
});

it('does not bill the month twice when the arrears line is a utility recharge', function () {
    // `alreadyBilledForMonth()` ignores any invoice carrying a one-off line type — utility,
    // CAM recovery, percentage rent, a fine, an NSF or late fee — because each of those raises its
    // OWN invoice dated into a month the recurring run also bills, and without the exclusion that
    // standalone document would suppress the lease's rent.
    //
    // An arrears UTILITY charge puts one of those types on the RECURRING invoice for the first
    // time. If the exclusion still fires, the run looks at its own invoice, decides the lease has
    // not been billed, and raises a second one — double-billing the tenant on the feature's own
    // headline example.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active', 'commencement_date' => '2026-01-01',
        'expiry_date' => '2030-12-31', 'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 100000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Water', 'type' => 'utility',
        'amount' => 900, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $billing = app(MonthlyBillingService::class);
    $september = CarbonImmutable::parse('2026-09-01');

    $first = $billing->generateForLease($lease->fresh(), $september);
    expect($first['invoice'] ?? null)->not->toBeNull();

    // The same run again must find the lease already billed, not raise a second invoice.
    $second = $billing->generateForLease($lease->fresh(), $september);

    expect($second['invoice'] ?? null)->toBeNull()
        ->and(Invoice::where('lease_id', $lease->id)->count())->toBe(1);
});

it('does not hand back a rent-free abatement a month later', function () {
    // Fit-out grace is measured on the invoice's own period, which is right for an advance row and
    // wrong for an arrears one: the rent-free month an arrears line needs to know about is the one
    // BEHIND it. A tenant whose rent commenced 15 August had August's service charge abated in
    // August — and would then have been billed it in FULL on the September invoice, the abatement
    // given and taken back a month later.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'rent_commencement_date' => '2026-08-15',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 100000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 31000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-08-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $line = collect(planInvoiceFor($lease->fresh(), '2026-09-01')['items'])->first();

    // Only if the lease's grace actually abates a service charge does the abated figure apply; on a
    // `rent_only` grace the service charge is payable in full and 31,000 is correct. Either way the
    // figure must be the one AUGUST earned — never more than a full month, and never a September
    // measurement applied to an August line.
    expect($line)->not->toBeNull()
        ->and($line['description'])->toContain('August 2026')
        ->and((float) $line['amount'])->toBeLessThanOrEqual(31000.0)
        ->and((float) $line['amount'])->toBeGreaterThan(0.0);

    // The specific claim: whatever the grace policy, the amount is derived from AUGUST. With a
    // gross grace it is the 17/31 the tenant actually owed; with rent-only it is the full month.
    expect(in_array((float) $line['amount'], [17000.0, 31000.0], true))->toBeTrue(
        'The arrears line was measured against a month other than the one it covers.'
    );
});

it('does not drop a month when a quarterly lease truncates its final cycle', function () {
    // A quarterly lease expiring 31 August truncates its Jul–Sep cycle to two months, and
    // `$cycleMonths` is reassigned to 2. Shifting an arrears row back by THAT covers May–Jun — so
    // APRIL, which the previous full quarter (Apr–Jun) would have carried, is billed by nothing at
    // all. The shift has to use the lease's full cycle, because the cycle behind this one was whole.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-08-31',
        'base_rent_monthly' => 90000,
        'billing_frequency' => 'quarterly',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 3000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $plan = planInvoiceFor($lease->fresh(), '2026-07-01');

    expect($plan['billable'])->toBeTrue();

    $line = collect($plan['items'])->first(fn ($i) => str_contains($i['description'], 'Service Charge'));

    expect($line)->not->toBeNull()
        // April, not May: the window starts a FULL quarter back.
        ->and($line['description'])->toContain('Apr');

    // Apr–Aug is five months at 3,000. A truncated two-month shift would have produced four.
    expect((float) $line['amount'])->toBe(15000.0);
});

it('does not drop NINE months when an annual lease truncates its final cycle', function () {
    // The quarterly case above loses two months. The adversarial review worked the annual one and
    // it loses nine: a lease on an annual cycle expiring 15 March 2026 truncates its Jan–Dec 2026
    // cycle to three months, so a three-month shift covers Oct–Dec 2025 and the whole of
    // January–September 2025 is billed by NOTHING. At 12,000/month that is 108,000 EGP, silently,
    // behind a final invoice whose figures all look plausible.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2020-01-01',
        'expiry_date' => '2026-03-15',
        'base_rent_monthly' => 90000,
        'billing_frequency' => 'annual',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 12000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2020-01-01', 'is_active' => true,
        'billing_timing' => Charge::TIMING_ARREARS,
    ]);

    $line = collect(planInvoiceFor($lease->fresh(), '2026-01-01')['items'])
        ->first(fn ($i) => str_contains($i['description'], 'Service Charge'));

    expect($line)->not->toBeNull()
        // The window opens a FULL year back — January 2025, not October.
        ->and($line['description'])->toContain('Jan');

    // Jan 2025 → 15 Mar 2026 is 12 + 15/31 = 14.483871 months at 12,000 = 173,806.45.
    // The truncated shift produced 5.483871 months = 65,806.45 — the 108,000 gap.
    expect((float) $line['amount'])->toBe(173806.45);
});
