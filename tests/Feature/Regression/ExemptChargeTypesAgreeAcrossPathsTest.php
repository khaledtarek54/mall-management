<?php

use App\Support\Vat;

/**
 * A charge type is taxed the same however the line was raised.
 *
 * THE DRIFT (validation sweep — receivables, 2026-08-11). `App\Support\Vat`'s docblock named five
 * out-of-scope supplies and every service that raises one wrote 0, but the invoice FORM's
 * type-switch read `in_array($state, ['base_rent', 'percentage_rent'])`. So a Late Fee, Marketing
 * Levy, Violation Fine or Returned-Cheque Fee added BY HAND got the standard rate defaulted onto an
 * out-of-scope supply: the same charge taxed differently depending on whether a person or a service
 * raised it — over-charging the tenant and over-stating VAT payable on the return.
 *
 * The fix names the policy ONCE and has every path read it. Taxability now lives on
 * `charge_codes.vat_treatment` (the accountant's row) with `Vat::EXEMPT_TYPES` as the floor for an
 * unseeded catalogue, and `Vat::rateForType()` is the single resolver — see
 * `ChargeCodeVatTreatmentTest` for the rulings taking effect and
 * `ChargeCodeVatTreatmentConformanceTest` for the floor and the catalogue agreeing.
 *
 * What is left here is the property that started it: no path may decide taxability for itself.
 */
it('names every VAT-exempt charge type in one place', function () {
    expect(Vat::EXEMPT_TYPES)->toEqualCanonicalizing([
        'base_rent',
        'percentage_rent',
        'late_fee',
        'marketing',
        'violation_fine',
        'nsf_fee',
        'parking',
    ]);
});

it('exempts the same types the invoice form does', function () {
    // The form's default is derived from the resolver, not a second list — assert the derivation
    // rather than re-listing it, or this test would be the third copy of the same policy.
    foreach (Vat::EXEMPT_TYPES as $type) {
        expect(Vat::rateForType($type))->toBe(Vat::EXEMPT);
    }

    // The control: a taxable supply still gets the standard rate, so the assertion above is not
    // passing because rateForType() returns 0 for everything.
    expect(Vat::rateForType('service_charge'))->toBe(Vat::standardRate())
        ->and(Vat::rateForType('cam_recovery'))->toBe(Vat::standardRate())
        ->and(Vat::standardRate())->toBeGreaterThan(0.0);
});

it('lets no origination service decide a rate for itself', function () {
    // Every service that raises a charge or an invoice line resolves through `Vat::rateForType()`.
    // A direct `standardRate()` / `Vat::on()` call is the drift returning: it cannot see the
    // accountant's ruling, so that service would keep taxing a supply the catalogue exempted.
    //
    // Grepped rather than exercised because the point is that NO path opts out — a behavioural test
    // can only cover the paths someone remembered to write one for.
    $offenders = [];

    foreach (glob(app_path('Services/*.php')) as $path) {
        foreach (file($path) as $no => $line) {
            $code = trim($line);

            if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
                continue;
            }

            if (preg_match('/Vat::(standardRate\(\)|on\()/', $code)) {
                $offenders[] = basename($path).':'.($no + 1).' — '.$code;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['A service resolves VAT without consulting the charge code:'],
        $offenders,
        ['', 'Use Vat::rateForType($code) (or Vat::onType($amount, $code)) so the accountant\'s ruling reaches it.']
    )));
});
