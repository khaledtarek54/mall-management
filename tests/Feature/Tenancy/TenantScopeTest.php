<?php

use App\Models\Asset;
use App\Support\TenantScope;

beforeEach(function () {
    ensureAllPropertiesAsset();
});

it('returns null when no tenant is set', function () {
    expect(TenantScope::currentAssetId())->toBeNull();
});

it('returns the asset id when a real property tenant is active', function () {
    $asset = makeAsset(['code' => 'TST']);

    asTenant($asset, function () use ($asset) {
        expect(TenantScope::currentAssetId())->toBe($asset->id);
    });
});

it('returns null when the All Properties pseudo-tenant is active', function () {
    $all = Asset::where('code', 'ALL')->first();

    asTenant($all, function () {
        expect(TenantScope::currentAssetId())->toBeNull();
    });
});

it('visibleAssetIds returns null for super_admin in any context', function () {
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    expect(TenantScope::visibleAssetIds())->toBeNull();
});

it('visibleAssetIds returns the user assigned set when on All Properties', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);
    makeAsset(['code' => 'CCC']); // not assigned

    $user = makeUser('manager', [$a->id, $b->id]);
    $this->actingAs($user);

    $all = Asset::where('code', 'ALL')->first();
    asTenant($all, function () use ($a, $b) {
        expect(TenantScope::visibleAssetIds())
            ->toBeArray()
            ->toEqualCanonicalizing([$a->id, $b->id]);
    });
});

it('visibleAssetIds returns the single tenant id on a specific property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $user = makeUser('manager', [$a->id]);
    $this->actingAs($user);

    asTenant($a, function () use ($a) {
        expect(TenantScope::visibleAssetIds())->toEqual([$a->id]);
    });
});
