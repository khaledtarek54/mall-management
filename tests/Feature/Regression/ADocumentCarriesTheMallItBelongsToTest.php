<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Models\Lease;
use App\Models\TenantRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A DOCUMENT CARRIES THE INITIALS OF THE MALL IT BELONGS TO.
 *
 * Reported from the Val Plaza demo box: leases on Val Plaza units were numbered `LSE-AW-2026-0001`
 * — Atriom Walk's initials. It was NOT the documented "renamed after seeding" hazard (the asset was
 * created once and never updated, and the leases were created days later); it was live, and it
 * would have branded every mall's leases with the first demo mall's code for ever.
 *
 * `Lease::creating()` resolves the code from the lease's own UNIT and allocates under the
 * document-number lock — and it returns early when a reference is already filled. `LeaseForm` filled
 * one, from `Lease::generateReference('AW')`, a hardcoded literal. So the correct answer was
 * computed and then silently overridden by a wrong one, which is why a direct model create looked
 * fine and only the PANEL was wrong. Pre-allocating at render time was a second fault: two operators
 * opening the form both got the same number and the second save hit the unique index.
 *
 * The two models differ and the tests below differ with them: a lease is numbered by the MODEL (so
 * the form must not pre-fill), while nothing in `TenantRequest` allocates a reference at all (so its
 * form stays the source and simply has to name the property the operator is working in).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    // Deliberately NOT 'AW': a fixture on the old literal passes whatever the code does.
    $this->asset = makeAsset(['code' => 'ZZ', 'name' => 'Zed Mall']);
    $this->unit = makeUnit($this->asset, ['code' => 'Z-01', 'status' => 'vacant']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('numbers a lease created through the panel with its own mall code', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $this->unit->id,
            'tenant_id' => makeTenant()->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'expiry_date' => '2027-05-31',
            'term_months' => 12,
            'base_rent_monthly' => 5000,
            'service_charge_monthly' => 1000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::latest('id')->first();

    expect($lease->reference)->toStartWith('LSE-ZZ-')
        ->and($lease->reference)->not->toContain('-AW-');
});

it('numbers a tenant request with the property being worked in', function () {
    // The form is this model's only source of a reference, so the assertion has to be on what the
    // REAL page offers. Reading the mounted state, not calling the generator — calling it would
    // pass just as happily with the hardcoded literal still in the form.
    $reference = Livewire::test(CreateTenantRequest::class)->get('data.reference');

    expect($reference)->toContain('-ZZ-')
        ->and($reference)->not->toContain('-AW-');
});
