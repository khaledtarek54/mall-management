<?php

use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // No active Filament tenant → visibleAssetIds() falls back to the user's
    // assigned set (or null for an unrestricted user).
    Filament::setTenant(null, isQuiet: true);
});

it('clamps a property-restricted user to their assigned properties', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('accounting', [$a->id])); // assigned to A only

    // Tampering the picked property to B is ignored — clamped to the allowed set.
    expect(TenantScope::reportAssetIds($b->id))->toBe([$a->id]);
    // Picking their own property is honored.
    expect(TenantScope::reportAssetIds($a->id))->toBe([$a->id]);
    // "Consolidated" for a restricted user means consolidated across THEIR set.
    expect(TenantScope::reportAssetIds(null))->toBe([$a->id]);
});

it('lets an unrestricted user pick any property or truly consolidate', function () {
    $a = makeAsset();
    $b = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    expect(TenantScope::reportAssetIds($b->id))->toBe([$b->id]);
    expect(TenantScope::reportAssetIds(null))->toBeNull(); // null = all properties
});
