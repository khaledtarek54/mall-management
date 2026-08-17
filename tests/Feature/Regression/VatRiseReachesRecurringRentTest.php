<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;

/**
 * A VAT rise entered in advance never reached rent or service charge.
 *
 * The dated catalogue's headline promise is that a rate is a rung with a start date, so a rise can
 * be recorded before it takes effect and applies by itself on the day. That held for every one-off
 * charge — late fees, fines, meter recharges, CAM recoveries, percentage rent — because each
 * resolves through `Vat::rateForType()` at origination.
 *
 * It did **not** hold for the recurring schedule, which is the bulk of the money.
 * `ChargeScheduleService` stamped `charges.vat_rate` once when the row was written and
 * `MonthlyBillingService` billed that number for the life of the lease. Measured before the fix:
 * with a rise to 20% effective 1 September, the resolver answered **20** for a September document
 * while the September invoice billed **14**. The operator enters the rise, the screen confirms it,
 * and the output VAT they still owe ETA is quietly under-collected. Amending the lease did not help
 * either — the amendment carried the old rate onto the new row.
 *
 * The fix makes `charges.vat_rate` an OVERRIDE with null as the normal state, and
 * `Charge::resolvedVatRate($on)` the one place that answers what a charge bills on a date. Yardi is
 * the standard being followed: the charge record holds the amount, the rate comes from a tax table
 * resolved at billing.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    $this->lease = makeLease(makeUnit(makeAsset(['code' => 'MALL'])), makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);
});

/** A recurring service charge with no rate of its own — the normal state. */
function recurringServiceCharge(?float $override = null): Charge
{
    return Charge::create([
        'lease_id' => test()->lease->id, 'type' => 'service_charge', 'name' => 'Service charge',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => $override,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
}

/** Record a rise against the standard VAT code, effective from $from. */
function raiseStandardVatTo(float $rate, string $from): void
{
    TaxRate::create([
        'tax_code_id' => TaxCode::where('code', Vat::STANDARD_TAX_CODE)->value('id'),
        'rate' => $rate,
        'effective_from' => $from,
    ]);
}

function serviceChargeLineFor(string $period): ?InvoiceItem
{
    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse($period));

    return Invoice::where('lease_id', test()->lease->id)
        ->whereDate('period_start', CarbonImmutable::parse($period)->startOfMonth())
        ->latest('id')->first()
        ?->items()->where('type', 'service_charge')->first();
}

it('bills the new rate from the month it takes effect', function () {
    // The headline. This billed 14 before the fix, on an invoice the catalogue said was 20.
    recurringServiceCharge();
    raiseStandardVatTo(20, '2026-09-01');

    $line = serviceChargeLineFor('2026-09-01');

    expect((float) $line->vat_rate)->toBe(20.0)
        ->and((float) $line->vat_amount)->toBe(2000.0);
});

it('bills the OLD rate for a month before the rise — the paired control', function () {
    // Resolving at billing time must not re-rate history. The rate follows the DOCUMENT's date, so
    // an August invoice keeps August's rate however many rises are on file.
    recurringServiceCharge();
    raiseStandardVatTo(20, '2026-09-01');

    $line = serviceChargeLineFor('2026-08-01');

    expect((float) $line->vat_rate)->toBe(14.0)
        ->and((float) $line->vat_amount)->toBe(1400.0);
});

it('keeps a rate the operator deliberately fixed', function () {
    // The override is the reason the column survives. A contract that fixed a rate must not be
    // silently re-rated by a catalogue change — that would be the same bug pointing the other way.
    recurringServiceCharge(override: 5);
    raiseStandardVatTo(20, '2026-09-01');

    $line = serviceChargeLineFor('2026-09-01');

    expect((float) $line->vat_rate)->toBe(5.0)
        ->and((float) $line->vat_amount)->toBe(500.0);
});

it('keeps an untaxed charge untaxed on the strength of the flag alone', function () {
    // `vat_applicable = false` with a NULL rate is the post-migration state of every row somebody
    // had marked untaxed — the migration nulled the column, so the flag is now the only thing
    // holding them. If the resolver skipped it they would silently start carrying the catalogue's
    // rate, which is the original bug pointing the other way.
    //
    // Written with a null rate on purpose: with an explicit 0 this passes whether or not the flag
    // is honoured, and it did — the first version of this test survived deleting the guard.
    $charge = Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'service_charge', 'name' => 'Untaxed recovery',
        'amount' => 10000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => null,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    raiseStandardVatTo(20, '2026-09-01');

    expect($charge->resolvedVatRate(CarbonImmutable::parse('2026-09-15')))->toBe(0.0)
        // And through the real billing path, not just the accessor.
        ->and((float) serviceChargeLineFor('2026-09-01')->vat_amount)->toBe(0.0);
});

it('leaves an exempt charge exempt without anyone storing a zero', function () {
    // Base rent is exempt in the catalogue. With no stored rate it must resolve to 0 on its own —
    // if it did not, making the column nullable would start taxing rent.
    Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'base_rent', 'name' => 'Base rent',
        'amount' => 50000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => null,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);
    raiseStandardVatTo(20, '2026-09-01');

    app(MonthlyBillingService::class)->runForPeriod(CarbonImmutable::parse('2026-09-01'));
    $rent = Invoice::where('lease_id', $this->lease->id)->latest('id')->first()
        ->items()->where('type', 'base_rent')->first();

    expect((float) $rent->vat_rate)->toBe(0.0)
        ->and((float) $rent->vat_amount)->toBe(0.0);
});

it('does not bake a rate into a charge row when the schedule is written', function () {
    // The root cause. Every creation path used to default the column from the catalogue, which is
    // what made the stored snapshot look like a considered value.
    app(ChargeScheduleService::class)->setAmount(
        $this->lease, 'service_charge', 7000, CarbonImmutable::parse('2026-02-01'),
        ['name' => 'Service charge', 'frequency' => 'monthly', 'first_row_from_effective' => true],
    );

    $row = Charge::where('lease_id', $this->lease->id)->where('type', 'service_charge')->latest('id')->first();

    expect($row->vat_rate)->toBeNull()
        ->and($row->hasVatRateOverride())->toBeFalse()
        ->and($row->resolvedVatRate(CarbonImmutable::parse('2026-02-15')))->toBe(14.0);
});
