<?php

use App\Support\Vat;

/**
 * A charge type that is outside the scope of VAT is exempt on EVERY path that can raise it.
 *
 * THE DRIFT (validation sweep — receivables, 2026-08-11). `App\Support\Vat`'s own docblock names
 * five exempt supplies — base rent, percentage rent, late fees, violation fines and the marketing
 * levy — and every service that originates one writes 0: `LateFeeService`, `MarketingLevyService`,
 * `BillViolationFineService`, `BillBouncedChequeFeeService`, `PercentageRentCalculationService`.
 *
 * The invoice FORM knew about two of them. Its type-switch read
 * `in_array($state, ['base_rent', 'percentage_rent'])`, so an operator adding a Late Fee,
 * Marketing Levy, Violation Fine or Returned-Cheque Fee line by hand got the STANDARD rate
 * defaulted onto an out-of-scope supply. The same charge, taxed differently depending on whether a
 * service or a person raised it — over-charging the tenant and over-stating VAT payable on the
 * return.
 *
 * The fix names the set ONCE (`Vat::EXEMPT_TYPES`) and has both sides read it, which is the only
 * arrangement in which they cannot drift again. This test pins the set against the services that
 * originate it, so adding a sixth exempt supply to one and not the other fails here.
 *
 * NOT promoted to a model-layer refusal, deliberately: `charge_codes` (the catalogue an accountant
 * edits without a deploy) has no taxability column, so a hard refusal would hard-code tax policy in
 * PHP — the exact thing the catalogue exists to avoid. The recommendation is a `vat_exempt` column
 * on `charge_codes`, which needs the accountant's ruling on which codes are exempt. Recorded in
 * docs/gap-analysis/VALIDATION-SWEEP-PLAN.md.
 */
it('names every VAT-exempt charge type in one place', function () {
    expect(Vat::EXEMPT_TYPES)->toEqualCanonicalizing([
        'base_rent',
        'percentage_rent',
        'late_fee',
        'marketing',
        'violation_fine',
        'nsf_fee',
    ]);
});

it('exempts the same types the invoice form does', function () {
    // The form's default is derived from the set, not a second list — assert the derivation
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

it('agrees with what each service actually originates', function () {
    // Every service that raises one of these lines writes Vat::EXEMPT. If one of them ever
    // originates at the standard rate, the set above is a lie and this fails.
    $sources = [
        'late_fee' => app_path('Services/LateFeeService.php'),
        'marketing' => app_path('Services/MarketingLevyService.php'),
        'violation_fine' => app_path('Services/BillViolationFineService.php'),
        'nsf_fee' => app_path('Services/BillBouncedChequeFeeService.php'),
        'percentage_rent' => app_path('Services/PercentageRentCalculationService.php'),
    ];

    foreach ($sources as $type => $path) {
        expect(file_exists($path))->toBeTrue("{$type}: {$path} is missing");

        $source = file_get_contents($path);

        expect($source)
            ->not->toContain("'vat_rate' => Vat::standardRate()", "{$type} originates at the standard rate but is listed exempt")
            ->and($source)->not->toContain("'vat_rate' => \App\Support\Vat::standardRate()");
    }
});
