<?php

use App\Models\Charge;
use App\Models\ChargeCode;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;

/**
 * EG-01 — taxability was still frozen onto the recurring charge row.
 *
 * 2026-08-12 made `charges.vat_rate` an override with null as the normal state, and
 * `VatRiseReachesRecurringRentTest` pins that a rise entered in advance reaches the billing run.
 * That fix was applied to `seedStandardCharges()`'s SERVICE-CHARGE block and not to the BASE-RENT
 * block two lines above it — which still wrote both `vat_rate` AND `vat_applicable` from the
 * catalogue at row-creation time, under a comment reading *"Taxability comes from the charge code,
 * not from here."*
 *
 * **`vat_applicable` is the half nobody fixed, and it short-circuits the whole resolver.**
 * `Charge::resolvedVatRate()` returns 0.0 the moment it is false, before the catalogue is ever
 * consulted — so a `base_rent` row, born false because rent is in `Vat::EXEMPT_TYPES`, can never
 * become taxable again no matter what the accountant rules. It is not a stale rate; it is a
 * permanent exemption written by a service, on the largest money line in the system.
 *
 * That matters NOW rather than hypothetically: Law 157/2025 pulled property rental into the tax net
 * (§3.1), so "point base rent at VAT_14" is the exact change this operator is expecting to make. It
 * would appear to work — `/admin/charge-codes` saves, `Vat::rateForType('base_rent')` answers 14 —
 * and every lease already on the books would go on billing rent untaxed, with the operator owing
 * ETA the output VAT they never collected.
 *
 * No screen has ever offered a `vat_applicable` tick: all three write sites DERIVE it. So it was
 * never an operator's statement about a supply, it was the catalogue's answer copied onto a row —
 * which is the definition of the freeze EG-01 asks to remove.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    $this->lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);

    // The rent + service pair the way a lease really gets them — through the service the wizard and
    // the Filament form both call, not by hand-writing the columns a form never offers.
    LeaseCreationService::seedStandardCharges($this->lease, rent: 100000, service: 10000);
});

/** The accountant's ruling: this supply is taxable at the standard rate from now on. */
function ruleTaxable(string $chargeCode): void
{
    ChargeCode::where('code', $chargeCode)->firstOrFail()->update(['tax_code' => Vat::STANDARD_TAX_CODE]);
    ChargeCode::flushLookupCaches();
}

it('leaves rent exempt while the catalogue says exempt', function () {
    // The control, and it is the whole safety case for the change: nothing moves on deploy. A fix
    // that started taxing rent the day it shipped would be a worse bug than the one it repairs.
    $rent = Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->sole();

    expect(Vat::rateForType('base_rent'))->toBe(0.0)
        ->and($rent->resolvedVatRate())->toBe(0.0);
});

it('bills rent at the rate the accountant rules, on a lease that already existed', function () {
    $rent = Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->sole();

    ruleTaxable('base_rent');

    // The ruling took — so anything still answering 0.0 below is the charge row, not the catalogue.
    expect(Vat::rateForType('base_rent'))->toBe(14.0);

    expect($rent->fresh()->resolvedVatRate())->toBe(14.0);
});

it('carries that rate into the money the billing run actually raises', function () {
    // The resolver agreeing is not the same claim as the invoice being right — this project has
    // shipped a correct resolver behind a billing run that ignored it. Drive the real run.
    ruleTaxable('base_rent');

    CarbonImmutable::setTestNow('2026-03-05');

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $line = $this->lease->invoices()->latest('id')->sole()
        ->items()->where('type', 'base_rent')->sole();

    expect((float) $line->vat_rate)->toBe(14.0)
        ->and((float) $line->vat_amount)->toBe(14000.0);
});

it('still lets a typed rate win over the catalogue', function () {
    // `vat_rate` is an OVERRIDE and must stay one: an operator who typed 8% for a grandfathered
    // agreement keeps 8% when the standard rate moves. Removing the freeze must not remove this.
    $rent = Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->sole();
    $rent->update(['vat_rate' => 8]);

    ruleTaxable('base_rent');

    expect($rent->fresh()->resolvedVatRate())->toBe(8.0);
});

it('still lets a typed zero hold a supply untaxed', function () {
    // The other override, and the one that carries what `vat_applicable = false` used to say. A
    // charge the operator has deliberately zero-rated must not start being taxed by a ruling.
    $rent = Charge::where('lease_id', $this->lease->id)->where('type', 'base_rent')->sole();
    $rent->update(['vat_rate' => 0]);

    ruleTaxable('base_rent');

    expect($rent->fresh()->resolvedVatRate())->toBe(0.0);
});

it('does not freeze the rate onto a newly created rent row either', function () {
    // The forward half. Fixing only the backfill leaves every lease signed tomorrow frozen at
    // whatever the catalogue said the day it was signed — the same bug with a later start date.
    ruleTaxable('base_rent');

    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL2'])), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);
    LeaseCreationService::seedStandardCharges($lease, rent: 50000, service: 5000);

    $rent = Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->sole();

    expect($rent->vat_rate)->toBeNull()
        ->and($rent->vat_applicable)->toBeNull();
});

it('never re-rates an invoice that has already been issued', function () {
    // The other half of the contract, and the one that stops this fix over-correcting. Unfreezing
    // the SCHEDULE ROW must not unfreeze HISTORY: a recurring row resolves at each billing, and the
    // line it produced keeps what it billed. `RentableItemAssignmentTest` used to assert this by
    // checking the frozen column, which conflated the two — an issued document and a standing
    // instruction are different things and only one of them is evidence.
    CarbonImmutable::setTestNow('2026-03-05');
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $march = $this->lease->invoices()->latest('id')->firstOrFail()
        ->items()->where('type', 'base_rent')->sole();

    expect((float) $march->vat_rate)->toBe(0.0);

    ruleTaxable('base_rent');

    CarbonImmutable::setTestNow('2026-04-05');
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-04-01'));

    // March, already raised, is untouched…
    expect((float) $march->fresh()->vat_rate)->toBe(0.0)
        ->and((float) $march->fresh()->vat_amount)->toBe(0.0);

    // …and April, billed after the ruling, carries it.
    // `firstOrFail`, not `sole` — there are two invoices on the lease by now, which is the point.
    $april = $this->lease->invoices()->latest('id')->firstOrFail()
        ->items()->where('type', 'base_rent')->sole();

    expect((float) $april->vat_rate)->toBe(14.0)
        ->and((float) $april->vat_amount)->toBe(14000.0);
});
