<?php

use App\Filament\Admin\Pages\ActivityLog;
use App\Models\Tenant;
use App\Support\Search\OptionDisplay;
use App\Support\TenantScope;
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
    ensureAllPropertiesAsset();

    $this->actingAs(makeUser('owner'));
    expect(ActivityLog::canAccess())->toBeFalse();         // property-restricted → blocked

    $this->actingAs(makeUser('super_admin'));
    expect(ActivityLog::canAccess())->toBeTrue();          // full portfolio → allowed
});
