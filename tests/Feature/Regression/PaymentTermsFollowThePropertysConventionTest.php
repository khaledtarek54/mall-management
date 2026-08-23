<?php

use App\Models\Lease;
use App\Services\LeaseCreationService;
use App\Settings\BillingSettings;
use App\Support\PropertySettings;

/*
| The configured payment terms reached the lease FORM and nothing else.
|
| `payment_terms_days` is NOT NULL with a database default of 7, so the `?? setting` fallbacks that
| used to sit at eight billing call sites could never fire — CFG-03. The fix put the default at
| ORIGINATION, which is right and is what Yardi does: a new lease starts from its mall's convention
| and then carries its own number, so changing a property's default never re-dates receivables that
| have already been raised.
|
| It was applied to `LeaseForm` only. `LeaseCreationService` — the one service every non-form path
| goes through — still wrote `?? 7`, one line below the deposit default that EG-35 had just made
| property-aware. `LeaseImporter` has no `payment_terms_days` column at all, so a migrating operator
| whose terms are 30 days imported every lease at 7 and nothing on any screen said so.
|
| Found 2026-08-23 while re-verifying which open questions the system genuinely answers: a document
| claiming payment terms are configuration is false if the value cannot reach an imported lease.
*/

function leaseFrom(int $unitId, array $lease = []): Lease
{
    return app(LeaseCreationService::class)->create([
        'tenant_mode' => 'existing',
        'tenant_id' => makeTenant()->id,
        'lease' => array_merge([
            'unit_id' => $unitId,
            'commencement_date' => '2026-01-01',
            'expiry_date' => '2026-12-31',
            'term_months' => 12,
            'base_rent_monthly' => 30000,
            'service_charge_monthly' => 5000,
        ], $lease),
    ]);
}

it('starts a new lease on the portfolio convention rather than a literal seven', function () {
    app(BillingSettings::class)->default_payment_terms_days = 30;

    $lease = leaseFrom(makeUnit(makeAsset())->id);

    expect($lease->payment_terms_days)->toBe(30)
        ->and($lease->paymentTermsDays())->toBe(30);
});

it('lets one mall keep its own terms', function () {
    // The property tier is the point: 30 days at the flagship, 7 at the outlet.
    app(BillingSettings::class)->default_payment_terms_days = 30;

    $outlet = makeAsset(['code' => 'OUTLET']);
    PropertySettings::set('billing.default_payment_terms_days', $outlet->id, 7);

    expect(leaseFrom(makeUnit($outlet)->id)->payment_terms_days)->toBe(7)
        ->and(leaseFrom(makeUnit(makeAsset(['code' => 'FLAG']))->id)->payment_terms_days)->toBe(30);
});

it('still lets a negotiated figure win', function () {
    // The convention is a default, never a rule: a lease that states its own terms keeps them.
    app(BillingSettings::class)->default_payment_terms_days = 30;

    expect(leaseFrom(makeUnit(makeAsset())->id, ['payment_terms_days' => 45])->payment_terms_days)->toBe(45);
});

it('behaves exactly as before on an install that changed nothing', function () {
    // The deploy safety case — the shipped default is 7, which is what the literal was.
    expect(leaseFrom(makeUnit(makeAsset())->id)->payment_terms_days)->toBe(7);
});
