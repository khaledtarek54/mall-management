<?php

/*
|--------------------------------------------------------------------------
| App\Support\TenantScope — property-scoping RBAC infrastructure
|--------------------------------------------------------------------------
| Consolidated unit coverage for the helper that answers "which properties
| may this user see/select?". Three concerns:
|   (a) visibleAssetIds()  — null (unrestricted) for super_admin, the assigned
|       asset id set for a scoped role.
|   (b) selectable* helpers — only surface rows inside the visible assets, and
|       always exclude the synthetic "All Properties" pseudo-asset.
|   (c) the "All Properties" pseudo-asset itself never scopes for super_admin
|       but still pins a restricted user to their assigned set.
*/

use App\Models\Asset;
use App\Support\TenantScope;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->all = ensureAllPropertiesAsset();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/* =========================================================================
 | (a) visibleAssetIds()
 ========================================================================= */

it('visibleAssetIds is null (unrestricted = all properties) for super_admin', function () {
    $this->actingAs(makeUser('super_admin'));

    // No tenant context: defers to AssignedAssets, still null for platform admin.
    expect(TenantScope::visibleAssetIds())->toBeNull();

    // Even inside All-Properties mode, super_admin remains unrestricted.
    asTenant($this->all, function () {
        expect(TenantScope::visibleAssetIds())->toBeNull();
    });
});

it('visibleAssetIds returns exactly the assigned asset ids for a scoped role', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);
    $c = makeAsset(['code' => 'CCC']); // assigned to nobody

    $this->actingAs(makeUser('manager', [$a->id, $b->id]));

    asTenant($this->all, function () use ($a, $b, $c) {
        expect(TenantScope::visibleAssetIds())
            ->toBeArray()
            ->toEqualCanonicalizing([$a->id, $b->id])
            ->not->toContain($c->id);
    });
});

it('visibleAssetIds collapses to the single property id when pinned to one asset', function () {
    $a = makeAsset(['code' => 'AAA']);
    makeAsset(['code' => 'BBB']);

    // A manager assigned to TWO properties, but viewing one specific property,
    // is scoped to just that property — not their whole assigned set.
    $b2 = makeAsset(['code' => 'B2']);
    $this->actingAs(makeUser('manager', [$a->id, $b2->id]));

    asTenant($a, function () use ($a) {
        expect(TenantScope::visibleAssetIds())->toEqual([$a->id]);
    });
});

it('currentAssetId is null on All-Properties but the id on a real property', function () {
    $a = makeAsset(['code' => 'AAA']);
    $this->actingAs(makeUser('super_admin'));

    asTenant($a, fn () => expect(TenantScope::currentAssetId())->toBe($a->id));
    asTenant($this->all, fn () => expect(TenantScope::currentAssetId())->toBeNull());
});

/* =========================================================================
 | (b) selectableAssetOptions()
 ========================================================================= */

it('selectableAssetOptions returns every real property (never ALL) for super_admin', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);
    $this->actingAs(makeUser('super_admin'));

    asTenant($this->all, function () use ($a, $b) {
        $options = TenantScope::selectableAssetOptions();

        expect($options)->toHaveKey($a->id)
            ->and($options)->toHaveKey($b->id)
            ->and($options)->not->toHaveKey($this->all->id); // pseudo-asset excluded
    });
});

it('selectableAssetOptions caps a restricted user to their assigned set and drops ALL', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']); // not assigned

    $this->actingAs(makeUser('manager', [$a->id]));

    // Even in All-Properties mode the restricted user only sees A, and the
    // synthetic ALL row is never offered as a real selection.
    asTenant($this->all, function () use ($a, $b) {
        $options = TenantScope::selectableAssetOptions();

        expect($options)->toHaveKey($a->id)
            ->and($options)->not->toHaveKey($b->id)
            ->and($options)->not->toHaveKey($this->all->id);
    });
});

/* =========================================================================
 | (c) selectableTenantOptions()
 ========================================================================= */

it('selectableTenantOptions surfaces only tenants leased within the visible assets', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $aTenant = makeTenant(['name' => 'A Retailer']);
    makeLease(makeUnit($a, ['code' => 'A-1']), $aTenant);

    $bTenant = makeTenant(['name' => 'B Retailer']);
    makeLease(makeUnit($b, ['code' => 'B-1']), $bTenant);

    $leaseless = makeTenant(['name' => 'Prospect (no lease yet)']);

    // Restricted manager pinned to A only.
    $this->actingAs(makeUser('manager', [$a->id]));

    asTenant($a, function () use ($aTenant, $bTenant, $leaseless) {
        $options = TenantScope::selectableTenantOptions();

        expect($options)->toHaveKey($aTenant->id)          // leased in A → shown
            ->and($options)->toHaveKey($leaseless->id)      // no lease → safe everywhere
            ->and($options)->not->toHaveKey($bTenant->id);  // leased only in B → hidden (no leak)
    });
});

it('selectableTenantOptions is unconstrained for super_admin in All-Properties mode', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $aTenant = makeTenant(['name' => 'A Retailer']);
    makeLease(makeUnit($a, ['code' => 'A-1']), $aTenant);

    $bTenant = makeTenant(['name' => 'B Retailer']);
    makeLease(makeUnit($b, ['code' => 'B-1']), $bTenant);

    $this->actingAs(makeUser('super_admin'));

    asTenant($this->all, function () use ($aTenant, $bTenant) {
        $options = TenantScope::selectableTenantOptions();

        expect($options)->toHaveKey($aTenant->id)
            ->and($options)->toHaveKey($bTenant->id);
    });
});

it('selectableTenantOptions on a real property hides tenants leased only elsewhere', function () {
    $a = makeAsset(['code' => 'AAA']);
    $b = makeAsset(['code' => 'BBB']);

    $aTenant = makeTenant(['name' => 'A Retailer']);
    makeLease(makeUnit($a, ['code' => 'A-1']), $aTenant);

    $bTenant = makeTenant(['name' => 'B Retailer']);
    makeLease(makeUnit($b, ['code' => 'B-1']), $bTenant);

    // super_admin, but pinned to a SPECIFIC property → scoping still applies
    // via currentAssetId/visibleAssetIds returning [A].
    $this->actingAs(makeUser('super_admin'));

    asTenant($a, function () use ($aTenant, $bTenant) {
        $options = TenantScope::selectableTenantOptions();

        expect($options)->toHaveKey($aTenant->id)
            ->and($options)->not->toHaveKey($bTenant->id);
    });
});
