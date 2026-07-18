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

/*
 * Property-first: the Properties resource sits ABOVE the per-property context
 * (it manages the tenants themselves). With "All Properties" removed from the
 * switcher, it lists the user's whole portfolio — their assigned set, every
 * real property for super_admin — regardless of which mall is currently active,
 * and creating a new property follows the `assets.create` permission alone
 * (it no longer requires the removed All-Properties view). See
 * docs/plans/03-remove-all-properties-mode.md.
 */

it('lists the whole portfolio for super_admin, regardless of the active mall', function () {
    $this->actingAs(makeUser('super_admin'));

    // Even while operating inside one specific mall, a super_admin sees every
    // real property in the Properties list (so they can manage/edit any of them).
    asTenant($this->hw, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqualCanonicalizing(['HW', 'PA']);
    });
});

it('always hides the All Properties pseudo-asset from the list', function () {
    $this->actingAs(makeUser('super_admin'));

    $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
    expect($codes)->not->toContain(Asset::ALL_PROPERTIES_CODE);
});

it('scopes a restricted user to their assigned properties, even inside a mall', function () {
    $hwOnly = makeUser('manager', [$this->hw->id]);
    $this->actingAs($hwOnly);

    asTenant($this->hw, function () {
        $codes = AssetResource::getEloquentQuery()->pluck('code')->all();
        expect($codes)->toEqual(['HW']); // never leaks PA
    });
});

it('allows creating a new property while inside a specific mall (property-first)', function () {
    // Regression guard: canCreate() used to require the removed All-Properties
    // view (currentAssetId() === null), which made onboarding a new mall
    // impossible once the operator always works inside one property.
    $this->actingAs(makeUser('super_admin'));

    asTenant($this->hw, function () {
        expect(AssetResource::canCreate())->toBeTrue();
    });
});

it('still rejects creation when the user lacks the assets.create permission', function () {
    $this->actingAs(makeUser('viewer'));

    asTenant($this->hw, function () {
        expect(AssetResource::canCreate())->toBeFalse();
    });
});
