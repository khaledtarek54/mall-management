<?php

/*
|--------------------------------------------------------------------------
| A supplier's withholding is a tax code, not a percentage someone typed
|--------------------------------------------------------------------------
| `vendors.withholding_tax_rate` was a free percentage box and `TaxSettings::wht_default_rate` was
| one more as the portfolio fallback. Both carried the flaw `TaxSettings` itself named — the
| statutory figure depends on the nature of the payment, so a guessed constant looks official and is
| wrong — while being exactly the shape that invites the guess, one supplier at a time.
|
| The operator's own sheet lists withholding at four rates (0.5 · 1 · 3 · 5%). A supplier is now
| POINTED at whichever the accountant rules applies, and the rate that code carries lives in the
| catalogue with every other rate.
|
| Two subtleties are worth pinning, because both are silent if they break:
|   - **The catalogue stores withholding NEGATIVE** ("WH -1%", the sheet's own notation for
|     deducted-not-added). Everything downstream wants a magnitude; a leaked sign would pay the
|     supplier MORE, not less.
|   - **Exempt is not "no code".** "This supplier is outside withholding" and "nothing has been
|     ruled for this supplier" are different statements, and the default must reach only the second.
*/

use App\Models\TaxCode;
use App\Models\Vendor;
use App\Settings\TaxSettings;
use App\Support\WithholdingTax;
use Database\Seeders\TaxCodeSeeder;
use Tests\Support\TaxCatalogue;

beforeEach(function () {
    $this->seed(TaxCodeSeeder::class);

    $settings = app(TaxSettings::class);
    $settings->wht_enabled = true;
    $settings->wht_default_tax_code = 'WH_1_P';
    $settings->save();
});

function whtVendor(array $attributes = []): Vendor
{
    return Vendor::create($attributes + [
        'name' => 'Supplier '.uniqid(),
        'status' => Vendor::STATUS_ACTIVE,
    ]);
}

it('takes the rate from the supplier\'s own code', function () {
    expect(WithholdingTax::rateFor(whtVendor(['withholding_tax_code' => 'WH_5_P'])))->toBe(5.0)
        // …and the portfolio default reaches a supplier who has none.
        ->and(WithholdingTax::rateFor(whtVendor()))->toBe(1.0);
});

it('drops the sign the catalogue stores withholding with', function () {
    // The sheet writes "WH -5%" because the tax comes OFF what is paid. Everything here works in
    // magnitudes: a leaked negative would make `on()` return a negative deduction, and the vendor
    // would be paid MORE than the bill — a bug that looks like a rounding artefact in a total.
    expect(TaxCode::rateOn('WH_5_P'))->toBeLessThan(0.0)
        ->and(WithholdingTax::rateFor(whtVendor(['withholding_tax_code' => 'WH_5_P'])))->toBe(5.0)
        ->and(WithholdingTax::on(1000, whtVendor(['withholding_tax_code' => 'WH_5_P'])))->toBe(50.0);
});

it('keeps "exempt" apart from "not ruled yet"', function () {
    // The reason these are two columns. As one percentage, exempt was a magic 0 that every reader
    // had to remember was special — and the portfolio default was one careless `?:` away from
    // withholding from a supplier who is outside the tax altogether.
    $exempt = whtVendor(['withholding_exempt' => true]);
    $unruled = whtVendor();

    expect(WithholdingTax::rateFor($exempt))->toBe(0.0)
        ->and(WithholdingTax::taxCodeFor($exempt))->toBeNull()
        // The control: the default DOES reach a supplier nobody has ruled on.
        ->and(WithholdingTax::rateFor($unruled))->toBe(1.0);

    // …and it stays exempt when the default moves, which is the whole point of recording it.
    $settings = app(TaxSettings::class);
    $settings->wht_default_tax_code = 'WH_5_P';
    $settings->save();

    expect(WithholdingTax::rateFor($exempt))->toBe(0.0)
        ->and(WithholdingTax::rateFor($unruled))->toBe(5.0);
});

it('resolves the rate in force on the payment date', function () {
    // A withholding rate has a validity period like every other rate in the catalogue, so a
    // back-dated payment withholds what was due when it was made — not what is due today.
    TaxCatalogue::setOnlyRate('WH_3_P', -3.0, '2017-07-01');
    TaxCatalogue::setRate('WH_3_P', -7.0, '2027-01-01');

    $vendor = whtVendor(['withholding_tax_code' => 'WH_3_P']);

    expect(WithholdingTax::rateFor($vendor, '2026-12-31'))->toBe(3.0)
        ->and(WithholdingTax::rateFor($vendor, '2027-06-01'))->toBe(7.0);
});

it('withholds nothing at all while the feature is off', function () {
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = false;
    $settings->save();

    expect(WithholdingTax::rateFor(whtVendor(['withholding_tax_code' => 'WH_5_P'])))->toBe(0.0)
        ->and(WithholdingTax::on(1000, whtVendor(['withholding_tax_code' => 'WH_5_P'])))->toBe(0.0);
});

it('withholds nothing when nothing has been configured', function () {
    // The safe direction, and the reason the default ships empty: there is no defensible assumption
    // about the nature of a payment, so an unconfigured install deducts nothing rather than
    // deducting something invented.
    $settings = app(TaxSettings::class);
    $settings->wht_default_tax_code = '';
    $settings->save();

    expect(WithholdingTax::rateFor(whtVendor()))->toBe(0.0)
        ->and(WithholdingTax::defaultRate())->toBe(0.0);
});

it('withholds nothing for a code the catalogue no longer holds', function () {
    // A supplier pointing at a deleted or renamed code must not fall through to some other rate.
    expect(WithholdingTax::rateFor(whtVendor(['withholding_tax_code' => 'WH_GONE'])))->toBe(0.0);
});
