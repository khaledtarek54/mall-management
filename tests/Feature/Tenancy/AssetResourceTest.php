<?php

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);
});

it('lists only the current property when scoped to one', function () {
    $this->actingAs(makeUser('super_admin'));

    asTenant($this->hw, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqual(['HW']);
    });

    asTenant($this->pa, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqual(['PA']);
    });
});

it('lists every assigned property under All Properties', function () {
    $this->actingAs(makeUser('super_admin'));
    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();

    asTenant($all, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqualCanonicalizing(['HW', 'PA']);
    });
});

it('always hides the All Properties pseudo-asset from the list', function () {
    $this->actingAs(makeUser('super_admin'));

    $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
    expect($codes)->not->toContain(Asset::ALL_PROPERTIES_CODE);
});

it('blocks creating a new property while inside a specific tenant', function () {
    $admin = makeUser('super_admin');
    $admin->syncRoles(['super_admin']);
    $this->actingAs($admin);

    asTenant($this->hw, function () {
        expect(AssetResource::canCreate())->toBeFalse();
    });
});

it('allows creating a new property from the All Properties view', function () {
    $admin = makeUser('super_admin');
    $admin->syncRoles(['super_admin']);
    $this->actingAs($admin);

    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
    asTenant($all, function () {
        expect(AssetResource::canCreate())->toBeTrue();
    });
});

it('still rejects creation when the user lacks the assets.create permission', function () {
    $viewer = makeUser('viewer');
    $viewer->syncRoles(['viewer']);
    $this->actingAs($viewer);

    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
    asTenant($all, function () {
        expect(AssetResource::canCreate())->toBeFalse();
    });
});

it('does not leak unassigned properties when the user is restricted', function () {
    $hwOnly = makeUser('manager', [$this->hw->id]);
    $this->actingAs($hwOnly);

    $all = Asset::where('code', Asset::ALL_PROPERTIES_CODE)->first();
    asTenant($all, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqual(['HW']);
    });
});
