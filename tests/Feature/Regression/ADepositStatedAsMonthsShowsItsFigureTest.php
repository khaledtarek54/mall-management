<?php

/*
|--------------------------------------------------------------------------
| A deposit stated as "three months' rent" shows what three months comes to
|--------------------------------------------------------------------------
| Found on screen while rehearsing the client demo (2026-09-01): a lease priced
| at 6,600/m²/yr on 70 m² derived its rent live — 38,500 appeared as the rate
| was typed — and the Security Deposit box beside it sat at 0.00, greyed out,
| under helper text reading "Derived from the rent and the months above", with
| "Deposit as months of rent" set to 3.
|
| It was never wrong in the DATABASE. `Lease::saving` multiplies rent by months
| on the way in, so the stored figure was always 115,500, and that is exactly
| why it survived: every test asserted the SAVED row. It was wrong on the only
| surface a human reads, on the field where a landlord's security is agreed —
| and its sibling derived field filled itself in, so the screen said one number
| derives and the other does not.
|
| Two things are pinned here, and the second is the one that lasts:
|
|   1. the form SHOWS the figure while it is being typed, on both pricing bases
|      and on the cascade (change the rate, the rent moves, the deposit follows);
|   2. what the form shows is what the model SAVES.
|
| The rule lives in `Lease::saving` because the importer, the API, the escalation
| sweep and a renewal all write leases and none of them is a form. The form holds
| a preview of that rule, so the two can drift — this compares them instead of
| assuming. Blank months means a FLAT deposit and must stay untouched: a sum
| unrelated to rent is a real deal, and deriving one there invents a term.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Lease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'DP']);
    $this->unit = makeUnit($this->asset, ['code' => 'DP-01', 'area_sqm' => 70]);
    $this->tenant = makeTenant(['name' => 'Deposit Tenant']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('shows the deposit as the rate is typed', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'rent_pricing_basis' => Lease::RENT_RATE,
            'security_deposit_months' => 3,
            'base_rent_rate_per_sqm_year' => 6600,
        ])
        // 6,600 x 70 / 12 = 38,500 -> x 3 months = 115,500.
        // The ARRAY form, never a closure: assertFormSet(fn ($state) => ...) ignores
        // what the closure returns and passes against any value at all.
        ->assertFormSet([
            'base_rent_monthly' => 38500.0,
            'security_deposit' => 115500.0,
        ]);
});

it('shows the deposit on a flat-priced lease too', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'rent_pricing_basis' => Lease::RENT_FLAT,
            'security_deposit_months' => 2.5,
            'base_rent_monthly' => 40000,
        ])
        ->assertFormSet(['security_deposit' => 100000.0]);
});

it('leaves a flat deposit alone when no multiple is stated', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'rent_pricing_basis' => Lease::RENT_FLAT,
            'security_deposit_months' => null,
            'security_deposit' => 25000,
            'base_rent_monthly' => 40000,
        ])
        ->assertFormSet(['security_deposit' => 25000]);
});

/*
| THE ONE THAT LASTS — the preview must equal what is stored.
|
| Read the figure off the form, save the same form, and compare. If somebody
| changes the rule in `Lease::saving` (or here) and not the other, this fails
| naming both numbers, rather than the screen quietly disagreeing with the row.
*/
it('shows exactly what the model saves', function () {
    $page = Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'commencement_date' => '2026-09-16',
            'expiry_date' => '2029-09-15',
            'term_months' => 36,
            'rent_pricing_basis' => Lease::RENT_RATE,
            'base_rent_rate_per_sqm_year' => 6600,
            'security_deposit_months' => 3,
            'service_charge_monthly' => 5775,
        ]);

    $shownOnScreen = (float) $page->instance()->form->getRawState()['security_deposit'];

    $page->call('create')->assertHasNoFormErrors();

    $saved = Lease::where('tenant_id', $this->tenant->id)->firstOrFail();

    expect($shownOnScreen)->toBe((float) $saved->security_deposit)
        ->and((float) $saved->security_deposit)->toBe(115500.0);
});
