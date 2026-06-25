<?php

/*
|--------------------------------------------------------------------------
| Cross-property data isolation scenarios
|--------------------------------------------------------------------------
| NET-NEW vs tests/Feature/Tenancy/ResourceScopingTest.php (which drives the
| per-property Filament tenant chain AS super_admin) and AuthorityScopeTest /
| AssignedAssetsTest. Here the actor is a RESTRICTED user (a manager pinned to
| one property, or a Jawad owner) and we assert that:
|   (a) a manager assigned only to property A sees A's units/tenants/
|       leases/invoices but never B's — through the resource scoped query,
|       AssignedAssets, and TenantScope::visibleAssetIds() in "All Properties";
|   (b) an owner (asset_owner) is scoped to their owned property only;
|   (c) the selectable* picker helpers hide out-of-scope rows for a restricted
|       user, and Department::selectableOptions() hides another property's
|       departments while keeping global ones.
| We drive real scoped Eloquent queries, not only the helper return values.
*/

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Asset;
use App\Models\Department;
use App\Support\AssignedAssets;
use App\Support\TenantScope;
use Filament\Facades\Filament;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Two fully-populated properties plus a lease-less ("orphan") tenant. Returns
 * a tidy bag so each test reads its fixtures by name.
 */
function scopingFixtures(): array
{
    $all = ensureAllPropertiesAsset();

    $a = makeAsset(['code' => 'AAA', 'name' => 'Alpha Mall']);
    $b = makeAsset(['code' => 'BBB', 'name' => 'Beta Mall']);

    $aUnit = makeUnit($a, ['code' => 'A-01']);
    $bUnit = makeUnit($b, ['code' => 'B-01']);

    $aTenant = makeTenant(['name' => 'Alpha Tenant']);
    $bTenant = makeTenant(['name' => 'Beta Tenant']);

    $aLease = makeLease($aUnit, $aTenant, ['reference' => 'L-A']);
    $bLease = makeLease($bUnit, $bTenant, ['reference' => 'L-B']);

    $aInvoice = makeInvoice($aLease);
    $bInvoice = makeInvoice($bLease);

    $orphanTenant = makeTenant(['name' => 'Unaffiliated Co']); // no lease anywhere

    return compact(
        'all', 'a', 'b', 'aUnit', 'bUnit', 'aTenant', 'bTenant',
        'aLease', 'bLease', 'aInvoice', 'bInvoice', 'orphanTenant',
    );
}

/* =========================================================================
 | (a) Manager assigned ONLY to property A
 ========================================================================= */

describe('manager assigned only to property A', function () {
    it('cannot access property B as a Filament tenant, only A and the ALL view', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);

        // A → yes, B → no, even though both are real properties.
        expect($user->canAccessTenant($f['a']))->toBeTrue()
            ->and($user->canAccessTenant($f['b']))->toBeFalse();

        // accessibleAssets() is the single source of truth for the property
        // switcher — it must contain A and exclude B and the ALL pseudo-asset.
        $accessibleIds = $user->accessibleAssets()->pluck('id')->all();
        expect($accessibleIds)->toContain($f['a']->id)
            ->not->toContain($f['b']->id)
            ->not->toContain($f['all']->id);
    });

    it('restricts visibleAssetIds to A in the All-Properties view', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        // In "All Properties" mode a restricted user must still be pinned to
        // their assigned set — not granted the whole portfolio.
        asTenant($f['all'], function () use ($f) {
            expect(TenantScope::visibleAssetIds())->toEqualCanonicalizing([$f['a']->id]);
        });
    });

    it('sees only A units / leases / invoices when the scoped query is pinned to A', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        // Drive the real resource queries inside property A's tenant context.
        asTenant($f['a'], function () use ($f) {
            $unitCodes = scopedResourceQuery(UnitResource::class)->pluck('code')->all();
            expect($unitCodes)->toContain('A-01')->not->toContain('B-01');

            $leaseIds = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();
            expect($leaseIds)->toContain($f['aLease']->id)->not->toContain($f['bLease']->id);

            $invoiceIds = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
            expect($invoiceIds)->toContain($f['aInvoice']->id)->not->toContain($f['bInvoice']->id);

            $tenantIds = scopedResourceQuery(TenantResource::class)->pluck('id')->all();
            expect($tenantIds)->toContain($f['aTenant']->id)->not->toContain($f['bTenant']->id);
        });
    });

    it('constrains a restricted user to A even in the All-Properties view via visibleAssetIds', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        // The "All Properties" pseudo-tenant bypasses per-resource scoping, so
        // a restricted user is held back by visibleAssetIds(). Apply it the way
        // the All-Properties widgets/lists do and confirm B never surfaces.
        asTenant($f['all'], function () use ($f) {
            $ids = TenantScope::visibleAssetIds();

            $unitCodes = \App\Models\Unit::query()->whereIn('asset_id', $ids)->pluck('code')->all();
            expect($unitCodes)->toContain('A-01')->not->toContain('B-01');

            $leaseIds = \App\Models\Lease::query()
                ->whereHas('unit', fn ($q) => $q->whereIn('asset_id', $ids))
                ->pluck('id')->all();
            expect($leaseIds)->toContain($f['aLease']->id)->not->toContain($f['bLease']->id);
        });
    });
});

/* =========================================================================
 | (b) Jawad owner scoped via asset_owner
 ========================================================================= */

describe('owner scoped to their owned property', function () {
    it('AssignedAssets folds legal ownership into the visible set and excludes other malls', function () {
        $f = scopingFixtures();

        $owner = makeUser('owner'); // no staff assignment...
        $owner->ownedAssets()->attach($f['a']->id, [
            'ownership_percentage' => 100,
            'started_at' => now(),
        ]); // ...only legal ownership of A.

        $ids = AssignedAssets::idsFor($owner);
        expect($ids)->toEqualCanonicalizing([$f['a']->id])
            ->and($ids)->not->toContain($f['b']->id);
        expect(AssignedAssets::isRestricted($owner))->toBeTrue();

        // Owner may switch into A but not into B.
        expect($owner->canAccessTenant($f['a']))->toBeTrue()
            ->and($owner->canAccessTenant($f['b']))->toBeFalse();
    });

    it('shows the owner only their property data when the query is pinned to the owned property', function () {
        $f = scopingFixtures();

        $owner = makeUser('owner');
        $owner->ownedAssets()->attach($f['a']->id, [
            'ownership_percentage' => 100,
            'started_at' => now(),
        ]);
        $this->actingAs($owner);

        asTenant($f['a'], function () use ($f) {
            $invoiceIds = scopedResourceQuery(InvoiceResource::class)->pluck('id')->all();
            expect($invoiceIds)->toContain($f['aInvoice']->id)->not->toContain($f['bInvoice']->id);

            $unitCodes = scopedResourceQuery(UnitResource::class)->pluck('code')->all();
            expect($unitCodes)->toContain('A-01')->not->toContain('B-01');
        });
    });

    it('keeps two co-owners independent — each sees only their own mall', function () {
        $f = scopingFixtures();

        $ownerA = makeUser('owner');
        $ownerA->ownedAssets()->attach($f['a']->id, ['ownership_percentage' => 100, 'started_at' => now()]);

        $ownerB = makeUser('owner');
        $ownerB->ownedAssets()->attach($f['b']->id, ['ownership_percentage' => 100, 'started_at' => now()]);

        expect(AssignedAssets::idsFor($ownerA))->toEqualCanonicalizing([$f['a']->id]);
        expect(AssignedAssets::idsFor($ownerB))->toEqualCanonicalizing([$f['b']->id]);
        expect($ownerA->canAccessTenant($f['b']))->toBeFalse();
        expect($ownerB->canAccessTenant($f['a']))->toBeFalse();
    });
});

/* =========================================================================
 | (c) Picker-option helpers for a RESTRICTED user
 ========================================================================= */

describe('selectable picker options for a restricted user', function () {
    it('selectableAssetOptions excludes the ALL pseudo-asset and out-of-scope properties', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        // On A: only A is selectable; B and ALL are excluded.
        asTenant($f['a'], function () use ($f) {
            $options = TenantScope::selectableAssetOptions();
            expect($options)->toHaveKey($f['a']->id)
                ->and($options)->not->toHaveKey($f['b']->id)
                ->and($options)->not->toHaveKey($f['all']->id);
        });

        // In All-Properties mode the restricted user is still capped at A,
        // and the synthetic ALL row is never offered as a real selection.
        asTenant($f['all'], function () use ($f) {
            $options = TenantScope::selectableAssetOptions();
            expect($options)->toHaveKey($f['a']->id)
                ->and($options)->not->toHaveKey($f['b']->id)
                ->and($options)->not->toHaveKey($f['all']->id);
        });
    });

    it('selectableTenantOptions hides B-leased tenants but keeps lease-less ones', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        asTenant($f['a'], function () use ($f) {
            $options = TenantScope::selectableTenantOptions();
            expect($options)->toHaveKey($f['aTenant']->id)        // leased in A → shown
                ->and($options)->toHaveKey($f['orphanTenant']->id) // no lease → safe everywhere
                ->and($options)->not->toHaveKey($f['bTenant']->id); // leased only in B → hidden
        });
    });

    it('Department::selectableOptions hides another property departments but keeps global ones', function () {
        $f = scopingFixtures();
        $user = makeUser('manager', [$f['a']->id]);
        $this->actingAs($user);

        $global = Department::create([
            'name' => 'Operations', 'code' => 'OPS-G', 'asset_id' => null,
            'is_active' => true, 'sort_order' => 1,
        ]);
        $deptA = Department::create([
            'name' => 'Alpha Leasing', 'code' => 'LEAS-A', 'asset_id' => $f['a']->id,
            'is_active' => true, 'sort_order' => 2,
        ]);
        $deptB = Department::create([
            'name' => 'Beta Leasing', 'code' => 'LEAS-B', 'asset_id' => $f['b']->id,
            'is_active' => true, 'sort_order' => 3,
        ]);
        $inactiveA = Department::create([
            'name' => 'Alpha Archived', 'code' => 'ARCH-A', 'asset_id' => $f['a']->id,
            'is_active' => false, 'sort_order' => 4,
        ]);

        asTenant($f['a'], function () use ($global, $deptA, $deptB, $inactiveA) {
            $options = Department::selectableOptions();
            expect($options)->toHaveKey($global->id)        // global → always shown
                ->and($options)->toHaveKey($deptA->id)      // A's own department → shown
                ->and($options)->not->toHaveKey($deptB->id) // B's department → hidden
                ->and($options)->not->toHaveKey($inactiveA->id); // inactive → hidden
        });
    });
});
