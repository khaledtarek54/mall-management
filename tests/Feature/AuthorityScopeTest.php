<?php

use App\Filament\Admin\Pages\ActivityLog;
use App\Models\Tenant;
use App\Support\Search\OptionDisplay;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('scopes tenant options to the current property (no cross-property leak)', function () {
    ensureAllPropertiesAsset();
    $hw = makeAsset(['code' => 'HW']);
    $pa = makeAsset(['code' => 'PA']);

    $hwTenant = makeTenant(['name' => 'HW Tenant']);
    makeLease(makeUnit($hw, ['code' => 'HW-1']), $hwTenant);

    $paTenant = makeTenant(['name' => 'PA Tenant']);
    makeLease(makeUnit($pa, ['code' => 'PA-1']), $paTenant);

    $leaseless = makeTenant(['name' => 'Unaffiliated']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($hw);

    $options = OptionDisplay::options(Tenant::class);

    expect($options)->toHaveKey($hwTenant->id)            // leased in HW → shown
        ->and($options)->toHaveKey($leaseless->id)        // unaffiliated → safe to show
        ->and($options)->not->toHaveKey($paTenant->id);   // leased only in PA → hidden
});

it('excludes the All-Properties pseudo-asset from asset options', function () {
    $all = ensureAllPropertiesAsset();
    $hw = makeAsset(['code' => 'HW']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant($all);

    $options = TenantScope::selectableAssetOptions();

    expect($options)->toHaveKey($hw->id)
        ->and($options)->not->toHaveKey($all->id);
});

it('restricts the activity log to full-portfolio roles', function () {
    // Seeded 2026-08-19. `ActivityLog::canAccess()` moved from an inline role list to the
    // `activity_log.view` PERMISSION, and this test had never seeded the catalogue — so with no
    // permissions in the database `can()` was false for everyone and the refusal half passed for
    // the wrong reason while the control half failed. A refusal test with no working control is
    // the trap this codebase names in three other places.
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();

    $this->actingAs(makeUser('owner'));
    expect(ActivityLog::canAccess())->toBeFalse();         // property-restricted → blocked

    $this->actingAs(makeUser('super_admin'));
    expect(ActivityLog::canAccess())->toBeTrue();          // full portfolio → allowed
});
