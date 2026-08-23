<?php

use App\Filament\Imports\ChargeImporter;
use App\Models\Charge;
use App\Services\ChargeScheduleService;
use App\Services\CreditUnearnedBillingService;
use App\Services\MonthlyBillingService;
use App\Support\ProrationMethod;
use Carbon\CarbonImmutable;

/**
 * **A charge says whether it prorates at all** — Yardi's per-charge prorate flag.
 *
 * EG-29 made the proration METHOD a lease term. It did not answer the prior question: whether a
 * given charge prorates in the first place. Every monthly row prorated together, so a mid-month
 * move-in cut a flat fixed parking fee, a fixed parking fee and a fixed management fee by the same
 * fraction it cut the rent. Those are not time-priced — hanging a sign from the 15th does not make
 * it half a sign — and Yardi's lease charge row carries the flag for exactly that reason
 * (docs/benchmarks/yardi/01-yardi-lease-administration.md §3.2).
 *
 * Every refusal here is paired with a CONTROL on the same lease and the same invoice: a test that
 * only asserted "the parking fee billed 5,000" would pass just as happily if nothing prorated at all.
 */
it('bills a flat charge whole while the rent beside it prorates', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'asset_id' => $asset->id,
        'status' => 'active',
        'commencement_date' => '2026-08-16',   // 16 of August's 31 days
        'expiry_date' => '2029-08-15',
        'base_rent_monthly' => 31000,
    ]);

    $schedule = app(ChargeScheduleService::class);

    $schedule->setAmount($lease, 'base_rent', 31000.0, CarbonImmutable::parse('2026-08-16'), [
        'name' => 'Base Rent', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    // The fixed parking fee: payable in full for any month the lease runs into.
    $schedule->setAmount($lease, 'parking', 5000.0, CarbonImmutable::parse('2026-08-16'), [
        'name' => 'Fixed Parking Fee', 'frequency' => 'monthly', 'prorate' => false,
        'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $lease->fresh(), CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), prorate: true,
    );

    $items = collect($plan['items']);
    $rent = $items->firstWhere('type', 'base_rent');
    $parking = $items->firstWhere('type', 'parking');

    // THE CONTROL — the lease really is prorating. Without this the assertion below passes on an
    // install where proration is switched off entirely, which is the wrong reason.
    expect($rent)->not->toBeNull()
        ->and(round((float) $rent['amount'], 2))->toBe(16000.0)   // 16/31 of 31,000
        // …and the flat charge beside it, on the same invoice, is untouched.
        ->and($parking)->not->toBeNull()
        ->and(round((float) $parking['amount'], 2))->toBe(5000.0);

    // The line does not claim to be pro-rated either — the label is what the tenant reads.
    expect($parking['description'])->not->toContain('pro-rated')
        ->and($rent['description'])->toContain('pro-rated');
});

it('still bills nothing for a month the lease never reached', function () {
    // Whether a PART month is worth a whole month is a different question from whether the lease
    // ran in that month at all. A flag that answered both would bill a flat charge for months
    // before the tenant existed.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'asset_id' => $asset->id,
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
        'base_rent_monthly' => 30000,
    ]);

    app(ChargeScheduleService::class)->setAmount($lease, 'parking', 5000.0, CarbonImmutable::parse('2026-09-01'), [
        'name' => 'Fixed Parking Fee', 'frequency' => 'monthly', 'prorate' => false,
        'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $lease->fresh(), CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'), prorate: true,
    );

    expect(collect($plan['items'] ?? [])->firstWhere('type', 'parking'))->toBeNull();
});

it('does not claw a flat charge back when the lease terminates mid-month', function () {
    // The mirror of the billing rule, and the reason `prorate = false` resolves to WHOLE_MONTH
    // rather than short-circuiting the multiplier. Billing whole and crediting part-month would
    // refund the tenant for a licence they held for the month.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'asset_id' => $asset->id,
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 30000,
    ]);

    $schedule = app(ChargeScheduleService::class);
    $schedule->setAmount($lease, 'base_rent', 30000.0, CarbonImmutable::parse('2026-01-01'), [
        'name' => 'Base Rent', 'frequency' => 'monthly', 'vat_applicable' => false, 'vat_rate' => 0,
    ]);
    $schedule->setAmount($lease, 'parking', 5000.0, CarbonImmutable::parse('2026-01-01'), [
        'name' => 'Fixed Parking Fee', 'frequency' => 'monthly', 'prorate' => false,
        'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    $invoice = app(MonthlyBillingService::class)->generateForLease(
        $lease->fresh(), CarbonImmutable::parse('2026-09-01'),
    )['invoice'] ?? null;

    expect($invoice)->not->toBeNull();

    $notes = app(CreditUnearnedBillingService::class)->forTermination(
        $lease->fresh(), CarbonImmutable::parse('2026-09-15'),
    );

    $note = collect($notes)->first();

    expect($note)->not->toBeNull();

    // Credit-note lines are grouped by VAT RATE, not by charge — so the subtotal is what says which
    // lines were clawed back. Half of September's 30,000 rent is unearned; the 5,000 licence is not
    // unearned at all. Were it apportioned too the note would read 17,500, and that figure is
    // plausible enough that nobody would query it — which is the whole reason to pin it here.
    expect(round((float) $note->subtotal, 2))->toBe(15000.0);
});

it('bills a charge that settles in arrears', function () {
    // EG-30's arrears row, driven through the PLANNER rather than the arithmetic. The closure that
    // prices it lost its `$proration` capture in EG-29 — an undefined variable reaching a
    // non-nullable `string` parameter, which is a TypeError, so every lease with an arrears charge
    // fatalled the whole billing run. Same shape as the `use ($get)` bug that 500'd the invoice
    // form for five days: every test of the behaviour called the arithmetic directly.
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'asset_id' => $asset->id,
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 30000,
    ]);

    app(ChargeScheduleService::class)->setAmount($lease, 'service_charge', 4000.0, CarbonImmutable::parse('2026-01-01'), [
        'name' => 'Service Charge', 'frequency' => 'monthly',
        'billing_timing' => Charge::TIMING_ARREARS, 'vat_applicable' => false, 'vat_rate' => 0,
    ]);

    $plan = app(MonthlyBillingService::class)->planInvoiceForLease(
        $lease->fresh(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), prorate: true,
    );

    $line = collect($plan['items'])->firstWhere('type', 'service_charge');

    expect($line)->not->toBeNull()
        ->and(round((float) $line['amount'], 2))->toBe(4000.0)
        // It names the month it covers — August, on a September invoice.
        ->and($line['description'])->toContain('August 2026')
        ->and($line['description'])->toContain('in arrears');
});

it('resolves the row’s method from the agreement’s, and only an explicit false departs', function () {
    $charge = new Charge;

    // Null is the normal state: the operator has ruled on nothing, so the lease's clause stands.
    // Tested `=== false` rather than falsy — the trap `charges.vat_applicable` fell into (EG-01).
    expect($charge->prorationMethodWithin(ProrationMethod::THIRTY_DAY))->toBe(ProrationMethod::THIRTY_DAY);

    $charge->prorate = true;
    expect($charge->prorationMethodWithin(ProrationMethod::THIRTY_DAY))->toBe(ProrationMethod::THIRTY_DAY);

    $charge->prorate = false;
    expect($charge->prorationMethodWithin(ProrationMethod::THIRTY_DAY))->toBe(ProrationMethod::WHOLE_MONTH);
});

it('lets a migrating operator import the flag with the charge', function () {
    // Settable in the UI and not in the importer is the gap a migrating operator falls into: they
    // arrive with a spreadsheet of charges, some of which their previous system never prorated.
    $names = array_map(fn ($c) => $c->getName(), ChargeImporter::getColumns());

    expect($names)->toContain('prorate');
});
