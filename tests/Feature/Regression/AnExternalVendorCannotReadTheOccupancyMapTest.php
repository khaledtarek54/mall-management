<?php

/**
 * The occupancy map is leasing data, and it had no lock on it.
 *
 * `OccupancyMap` declares **no `canAccess()`** at all. A Filament page with no gate is reachable by
 * every authenticated panel user, and this one renders, per unit: the unit code, the **name of the
 * tenant trading in it** (`activeLease.tenant.name`), its occupancy status, and a headline vacancy
 * rate for the mall.
 *
 * That is the same data as the rent roll, and its two neighbours in `Navigation::GROUPS['leasing']`
 * — `RentRoll` and `ExpirationSchedule` — both gate on `reports.view`. All three are registered
 * side by side in `ReportCatalogue` as LEASING reports. One of the three was open.
 *
 * Who could read it: `vendor` — an EXTERNAL maintenance contractor, whose grant in
 * `RolesPermissionsSeeder` is five keys wide (`requests.view`, `requests.view_all`, `facility.view`,
 * `facility.view_all`, `notes.view`) under a docblock that says in writing *"NO tenants/leases/
 * financials/HR/GL — it must not read another party's commercial data"*. Also `technician`,
 * `customer_service`, `marketing` and `hr`, none of which hold `tenants.view` either.
 *
 * Found by sweeping all 14 roles against all 99 screens and noticing that this one screen appeared
 * in every role's reachable set. It is invisible from the file: a missing method looks like nothing
 * at all, and the page's own docblock and query are careful about PROPERTY scoping — which is what
 * makes it read as a screen that had been thought about.
 */

use App\Filament\Admin\Pages\OccupancyMap;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/**
 * Every role holding NEITHER of the two rights this screen now asks for.
 *
 * `customer_service` is in the list and holds `tenants.view` — deliberately, to identify a caller
 * by name on the tenant register. That is a different claim from reading the whole mall's occupancy
 * and vacancy rate, and it is the distinction the gate draws.
 */
const OUTSIDERS = ['vendor', 'technician', 'coordinator', 'customer_service', 'marketing', 'hr'];

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'OM']);
    $this->tenant = makeTenant(['name' => 'Zara Home']);
    $this->unit = makeUnit($this->asset, ['code' => 'A-101']);
    makeLease($this->unit, $this->tenant, ['status' => 'active']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses the occupancy map to a role that may not read tenants or units', function (string $role) {
    $this->flushSession();
    $this->actingAs(makeUser($role, [$this->asset->id]));

    // The premise, asserted rather than assumed: this role really holds neither claim on the data.
    // Without it the refusal below could be testing a role that is allowed to see all of it anyway.
    expect(Auth::user()->canAny(['reports.view', 'units.view']))
        ->toBeFalse("The {$role} role holds a right that would make this test vacuous.");

    asTenant($this->asset, function () {
        expect(OccupancyMap::canAccess())->toBeFalse();
    });

    $this->get(OccupancyMap::getUrl(tenant: $this->asset))->assertForbidden();
})->with(OUTSIDERS);

it('still opens for the roles whose job it is', function (string $role) {
    // The control. A gate that refused everybody would satisfy every assertion above, and this
    // screen is one of the three a leasing manager opens every morning — its own comment in
    // `Navigation` says so.
    $this->flushSession();
    $this->actingAs(makeUser($role, [$this->asset->id]));

    asTenant($this->asset, function () {
        expect(OccupancyMap::canAccess())->toBeTrue();
    });

    $this->get(OccupancyMap::getUrl(tenant: $this->asset))->assertOk();
})->with([
    'super_admin', 'manager', 'mall_admin', 'viewer', 'owner',
    // Leasing reaches it through `reports.view`, operations through `units.view` — the two honest
    // claims the gate accepts, and the reason it is a union rather than the siblings' single right.
    'leasing', 'operations', 'accounting',
]);

it('keeps the tenant name it renders behind that gate', function () {
    // What is actually at stake: the page eager-loads `activeLease.tenant` and prints the retailer's
    // name on every occupied tile. Naming it here is what stops the gate being "tidied away" later
    // as a screen that only shows unit codes.
    $this->flushSession();
    $this->actingAs(makeUser('leasing', [$this->asset->id]));

    $this->get(OccupancyMap::getUrl(tenant: $this->asset))
        ->assertOk()
        ->assertSee('Zara Home');

    $this->flushSession();
    $this->actingAs(makeUser('vendor', [$this->asset->id]));

    $this->get(OccupancyMap::getUrl(tenant: $this->asset))
        ->assertForbidden()
        ->assertDontSee('Zara Home');
});
