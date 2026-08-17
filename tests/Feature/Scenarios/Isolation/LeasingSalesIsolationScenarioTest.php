<?php

/*
|--------------------------------------------------------------------------
| Leasing + Sales property-isolation scenarios
|--------------------------------------------------------------------------
| Group: Lease (chain unit), Unit (direct asset_id), TenantSalesDeclaration
| (chain lease.unit). NET-NEW vs ScopingScenarioTest / ResourceScopingTest /
| PropertyIsolation*Test / AssetInScopeWriteGuardTest — those cover the shared
| helpers and the write-guard in general; here we exercise, for each of these
| three resources specifically:
|   (a) READ-SCOPE  — a manager pinned to A sees only A's rows via the fully
|                     tenant-scoped resource query (scopedResourceQuery),
|                     never B's;
|   (b) ALL-PROPERTIES — a super_admin sees BOTH A and B; a restricted manager
|                     in ALL mode is still pinned to their assigned set;
|   (c) WRITE-GUARD — the resource's assert*AssetInScope(...) rejects an
|                     out-of-scope asset / unit / lease (403) and allows an
|                     in-scope one, for a restricted user; no-op for portfolio;
|   (d) group-specific — a multi-unit lease's master AND every additional unit
|                     must be in the same visible property:
|                     assertUnitsAssetInScope([A, B_in_other_property]) throws,
|                     [A1, A2] (both in A) passes.
|
| We drive the REAL scoped Eloquent queries the List page builds, not helper
| return values, and hit the actual guard entry points wired from the
| Create/Edit pages (assertUnitAssetInScope / assertUnitsAssetInScope /
| assertAssetInScope / assertLeaseAssetInScope).
*/

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
})->group('isolation');

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Two fully-populated properties (A and B), each with a unit, tenant, lease
 * and a submitted sales declaration. Plus a spare in-scope unit in A for the
 * multi-unit path. Returns a bag keyed by name.
 */
function leasingSalesFixtures(): array
{
    $all = ensureAllPropertiesAsset();

    $a = makeAsset(['code' => 'AAA', 'name' => 'Alpha Mall']);
    $b = makeAsset(['code' => 'BBB', 'name' => 'Beta Mall']);

    $aUnit = makeUnit($a, ['code' => 'A-01']);
    $aUnit2 = makeUnit($a, ['code' => 'A-02']); // second in-scope unit in A
    $bUnit = makeUnit($b, ['code' => 'B-01']);

    $aTenant = makeTenant(['name' => 'Alpha Tenant']);
    $bTenant = makeTenant(['name' => 'Beta Tenant']);

    $aLease = makeLease($aUnit, $aTenant, ['reference' => 'L-A']);
    $bLease = makeLease($bUnit, $bTenant, ['reference' => 'L-B']);

    $aDecl = makeSalesDeclaration($aLease);
    $bDecl = makeSalesDeclaration($bLease);

    return compact(
        'all', 'a', 'b', 'aUnit', 'aUnit2', 'bUnit',
        'aTenant', 'bTenant', 'aLease', 'bLease', 'aDecl', 'bDecl',
    );
}

/** A submitted sales declaration on a lease (period Jan 2026). */
function makeSalesDeclaration(Lease $lease, array $attrs = []): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create(array_merge([
        'lease_id' => $lease->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'declared_sales' => 500000,
        'status' => 'submitted',
        'declared_at' => now(),
    ], $attrs));
}

/*
|--------------------------------------------------------------------------
| (a) READ-SCOPE — a manager pinned to A sees only A's rows
|--------------------------------------------------------------------------
*/

it('scopes Leases to the restricted manager\'s property', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        $ids = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();

        expect($ids)->toContain($f['aLease']->id)
            ->and($ids)->not->toContain($f['bLease']->id);
    });
});

it('scopes Units to the restricted manager\'s property', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        $ids = scopedResourceQuery(UnitResource::class)->pluck('id')->all();

        expect($ids)->toContain($f['aUnit']->id, $f['aUnit2']->id)
            ->and($ids)->not->toContain($f['bUnit']->id);
    });
});

it('scopes TenantSalesDeclarations to the restricted manager\'s property', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        $ids = scopedResourceQuery(TenantSalesDeclarationResource::class)->pluck('id')->all();

        expect($ids)->toContain($f['aDecl']->id)
            ->and($ids)->not->toContain($f['bDecl']->id);
    });
});

/*
|--------------------------------------------------------------------------
| (b) ALL-PROPERTIES — super_admin sees both; restricted stays pinned
|--------------------------------------------------------------------------
*/

it('shows both properties\' Leases/Units/Declarations to a super_admin in All-Properties mode', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('super_admin'));

    asTenant($f['all'], function () use ($f) {
        $leases = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();
        $units = scopedResourceQuery(UnitResource::class)->pluck('id')->all();
        $decls = scopedResourceQuery(TenantSalesDeclarationResource::class)->pluck('id')->all();

        expect($leases)->toContain($f['aLease']->id, $f['bLease']->id)
            ->and($units)->toContain($f['aUnit']->id, $f['bUnit']->id)
            ->and($decls)->toContain($f['aDecl']->id, $f['bDecl']->id);
    });
});

it('keeps a restricted manager pinned to their set in All-Properties mode', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['all'], function () use ($f) {
        $leases = scopedResourceQuery(LeaseResource::class)->pluck('id')->all();
        $units = scopedResourceQuery(UnitResource::class)->pluck('id')->all();
        $decls = scopedResourceQuery(TenantSalesDeclarationResource::class)->pluck('id')->all();

        // Assigned to A only — B must never leak even in the portfolio view.
        expect($leases)->toContain($f['aLease']->id)->and($leases)->not->toContain($f['bLease']->id)
            ->and($units)->toContain($f['aUnit']->id)->and($units)->not->toContain($f['bUnit']->id)
            ->and($decls)->toContain($f['aDecl']->id)->and($decls)->not->toContain($f['bDecl']->id);
    });
});

/*
|--------------------------------------------------------------------------
| (c) WRITE-GUARD — assert*AssetInScope rejects out-of-scope, allows in-scope
|--------------------------------------------------------------------------
| A restricted user (manager pinned to A) tampering the client-supplied FK to
| point at B's property must be rejected 403 on BOTH create and edit paths.
*/

it('UnitResource::assertAssetInScope rejects B\'s asset for a manager pinned to A', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        expect(fn () => UnitResource::assertAssetInScope($f['b']->id))
            ->toThrow(HttpException::class);

        // In-scope asset passes — no exception.
        expect(fn () => UnitResource::assertAssetInScope($f['a']->id))
            ->not->toThrow(HttpException::class);
    });
});

it('LeaseResource::assertUnitAssetInScope rejects a unit in B for a manager pinned to A', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        expect(fn () => LeaseResource::assertUnitAssetInScope($f['bUnit']->id))
            ->toThrow(HttpException::class);

        // In-scope master unit passes — no exception.
        expect(fn () => LeaseResource::assertUnitAssetInScope($f['aUnit']->id))
            ->not->toThrow(HttpException::class);
    });
});

it('LeaseResource::assertUnitAssetInScope rejects a null/unknown unit for a restricted user', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () {
        // null resolves to a null asset → out of a restricted user's set → 403.
        expect(fn () => LeaseResource::assertUnitAssetInScope(null))
            ->toThrow(HttpException::class);
    });
});

it('TenantSalesDeclarationResource::assertLeaseAssetInScope rejects B\'s lease for a manager pinned to A', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        expect(fn () => TenantSalesDeclarationResource::assertLeaseAssetInScope($f['bLease']->id))
            ->toThrow(HttpException::class);

        // In-scope lease passes — no exception.
        expect(fn () => TenantSalesDeclarationResource::assertLeaseAssetInScope($f['aLease']->id))
            ->not->toThrow(HttpException::class);
    });
});

it('is a no-op for a super_admin (portfolio) submitting any property\'s FK', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('super_admin'));

    asTenant($f['all'], function () use ($f) {
        // visibleAssetIds() is null for portfolio → every assert passes for any property.
        expect(fn () => UnitResource::assertAssetInScope($f['b']->id))->not->toThrow(HttpException::class);
        expect(fn () => LeaseResource::assertUnitAssetInScope($f['bUnit']->id))->not->toThrow(HttpException::class);
        expect(fn () => TenantSalesDeclarationResource::assertLeaseAssetInScope($f['bLease']->id))->not->toThrow(HttpException::class);
    });
});

/*
|--------------------------------------------------------------------------
| (d) GROUP-SPECIFIC — multi-unit lease: master AND every additional unit
|     must share the same visible property.
|--------------------------------------------------------------------------
*/

it('rejects a multi-unit set that mixes in a unit from another property', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        // [A-unit, B-unit] — one additional unit belongs to another property → 403.
        expect(fn () => LeaseResource::assertUnitsAssetInScope([$f['aUnit']->id, $f['bUnit']->id]))
            ->toThrow(HttpException::class);
    });
});

it('allows a multi-unit set where every unit is in the same visible property', function () {
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        // Both units in A — passes with no exception.
        expect(fn () => LeaseResource::assertUnitsAssetInScope([$f['aUnit']->id, $f['aUnit2']->id]))
            ->not->toThrow(HttpException::class);
    });
});

it('rejects the additional-units set even when the master unit is in scope', function () {
    // Simulates CreateLease: master (unit_id) in A passes, but an additional
    // unit in B must still trip the guard — proving both entry points are wired.
    $f = leasingSalesFixtures();
    $this->actingAs(makeUser('manager', [$f['a']->id]));

    asTenant($f['a'], function () use ($f) {
        // master ok
        expect(fn () => LeaseResource::assertUnitAssetInScope($f['aUnit']->id))
            ->not->toThrow(HttpException::class);
        // additional set contains B → throws
        expect(fn () => LeaseResource::assertUnitsAssetInScope([$f['bUnit']->id]))
            ->toThrow(HttpException::class);
    });
});
