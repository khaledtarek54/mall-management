<?php

/*
|--------------------------------------------------------------------------
| The VAT rate is configuration, not a constant
|--------------------------------------------------------------------------
| 14% was a literal repeated across eight origination points: BillMeterReadingService::VAT_RATE,
| the service charge seeded onto a new lease, the CAM admin fee (a bare `* 0.14`, plus the charge
| and invoice-line labels), the invoice-line form's default and its type-switch, and two DB column
| defaults. Egypt moved this rate once already — 10% → 14% in 2017 — and the next move meant
| finding all eight, with nowhere stating what the rate actually is.
|
| It became `TaxSettings::vat_standard_rate`, and since 2026-08-12 it is a dated rung on the
| `VAT_STD` tax code — because the one property a rate has that a settings field cannot carry is
| the day it came into force.
|
| Two properties are asserted here, and they pull in opposite directions:
|   1. Changing the rate changes what is billed NEXT.
|   2. It never changes what was already billed. An invoice issued at 14% is a 14% document
|      forever — otherwise a rate change silently rewrites history and de-ties the books from the
|      returns already filed.
*/

use App\Models\Charge;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use App\Services\BillMeterReadingService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Tests\Support\TaxCatalogue;

/** Point the standard rate somewhere unmistakable, so a stray literal 14 can't produce a pass. */
function setVatRate(float $rate): void
{
    TaxCatalogue::setStandardRate($rate);
}

it('reads the rate from the tax catalogue', function () {
    setVatRate(17.5);

    expect(Vat::standardRate())->toBe(17.5)
        ->and(Vat::on(1000))->toBe(175.0)
        ->and(Vat::EXEMPT)->toBe(0.0);
});

it('refuses a negative rate rather than billing a negative tax line', function () {
    // A mistyped rate must not turn a VAT line into a hidden credit. Clamped, not thrown:
    // billing must not stop because someone fat-fingered a rung.
    setVatRate(-5);

    expect(Vat::standardRate())->toBe(0.0)
        ->and(Vat::on(1000))->toBe(0.0);
});

it('bills a new lease service charge at the configured rate', function () {
    setVatRate(20);

    $lease = app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => [
            'unit_id' => makeUnit(makeAsset())->id,
            'commencement_date' => '2026-01-01',
            'term_months' => 12,
            'base_rent_monthly' => 30000,
            'service_charge_monthly' => 5000,
        ],
    ]);

    $service = $lease->charges()->where('type', 'service_charge')->first();
    $rent = $lease->charges()->where('type', 'base_rent')->first();

    expect((float) $service->vat_rate)->toBe(20.0)
        // Base rent is outside the scope of VAT — it must NOT follow the standard rate.
        ->and((float) $rent->vat_rate)->toBe(0.0);
});

it('bills a utility recharge at the configured rate', function () {
    setVatRate(25);

    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $meter = UtilityMeter::create([
        'asset_id' => $asset->id,
        'unit_id' => $unit->id,
        'meter_number' => 'VAT-E-'.uniqid(),
        'type' => 'electric',
        'status' => 'active',
        'unit_of_measurement' => 'kWh',
    ]);
    $reading = MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_date' => '2026-03-31',
        'reading_value' => 100,
        'consumption' => 100,
        'cost' => 1000,
    ]);

    $invoice = app(BillMeterReadingService::class)->bill($reading);
    $item = $invoice->items()->first();

    expect((float) $item->vat_rate)->toBe(25.0)
        ->and((float) $item->vat_amount)->toBe(250.0);
});

it('leaves an already-issued invoice at the rate it was billed at', function () {
    // The property that protects the books. Bill at 14, then move the rate — the issued document
    // must not move with it.
    setVatRate(14);

    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);
    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 1000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'vat_rate' => Vat::standardRate(),
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    $result = app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-03-01'));

    expect($result['status'])->toBe('created');
    $invoice = $result['invoice'];
    $before = (float) $invoice->vat_amount;

    setVatRate(30);
    $invoice->refresh();

    expect($before)->toBe(140.0)
        ->and((float) $invoice->vat_amount)->toBe(140.0, 'an issued invoice must not re-rate when the rate changes')
        ->and((float) $invoice->items()->first()->vat_rate)->toBe(14.0);
});

it('has no hardcoded VAT rate left in the app', function () {
    // The gate. A literal rate anywhere outside Vat and the tax-catalogue seeder is the bug this
    // change removed; it would silently disagree with the rate the accountant just entered.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(base_path().'/', '', $file->getPathname());

        // The one place in app/ that legitimately states the rate: the FLOOR an unseeded database
        // bills at. (The seeded figure lives in database/seeders/TaxCodeSeeder.php, outside this
        // sweep, and `TaxCatalogueConformanceTest` asserts the two agree.)
        if (str_contains($path, 'Support/Vat.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $no => $line) {
            // Ignore comments — the docblocks explain the rate, they don't apply it.
            $code = trim($line);
            if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                continue;
            }

            // A VAT rate being assigned as a literal: `'vat_rate' => 14`, `* 0.14`, `VAT_RATE = 14`.
            if (preg_match('/vat_rate[\'"]?\s*(=>|=)\s*1[0-9](\.\d+)?\b/i', $code)
                || preg_match('/\*\s*0\.1[0-9]\b/', $code)) {
                $offenders[] = $path.':'.($no + 1).' — '.trim($code);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['A VAT rate is hardcoded here instead of read from App\Support\Vat:'],
        $offenders,
        ['', 'Use Vat::rateForType($code, $date) to originate a supply, or Vat::EXEMPT for one outside the scope.']
    )));
});
