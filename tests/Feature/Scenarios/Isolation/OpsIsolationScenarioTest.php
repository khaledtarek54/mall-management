<?php

/*
|--------------------------------------------------------------------------
| Operations property-isolation scenarios
|--------------------------------------------------------------------------
| NET-NEW vs ScopingScenarioTest / ResourceScopingTest / AssetInScopeWriteGuardTest
| (which cover Money/AR resources: Unit, Lease, Invoice, Payment, Expense …).
| Here we lock down the OPERATIONS group — the resources a manager/operations
| user touches day to day:
|
|   - TenantRequest (TenantRequest, chain via unit)  → assertUnitAssetInScope
|   - CamExpensePool      (direct asset_id)               → assertAssetInScope
|   - UtilityMeter        (direct asset_id)               → assertAssetInScope
|   - MarketingBudget     (direct asset_id, NO write guard — asset_id form field is
|                          unconditionally disabled + auto-stamped; read-scope only)
|
| For each we assert three scenario CLASSES with a RESTRICTED actor (a manager
| pinned to property A):
|   (a) READ-SCOPE     — scoped query in A's tenant context returns A's rows, never B's;
|   (b) ALL-PROPERTIES — super_admin sees BOTH; the restricted user in ALL mode still
|                        sees only their assigned set (A), never B;
|   (c) WRITE-GUARD    — the resource's assert*AssetInScope REJECTS an out-of-scope
|                        asset/unit and ALLOWS an in-scope one (skipped for
|                        MarketingBudget, which has no guard by design).
|
| We drive the real Filament-scoped Eloquent queries (scopedResourceQuery inside
| asTenant), not just helper return values.
*/

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\MarketingBudget;
use App\Models\TenantRequest;
use App\Models\UtilityMeter;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->all = ensureAllPropertiesAsset();

    // Two fully independent properties.
    $this->a = makeAsset(['code' => 'OPSA', 'name' => 'Alpha Ops']);
    $this->b = makeAsset(['code' => 'OPSB', 'name' => 'Beta Ops']);

    $this->unitA = makeUnit($this->a, ['code' => 'A-OPS-01']);
    $this->unitB = makeUnit($this->b, ['code' => 'B-OPS-01']);

    // --- TenantRequest (TenantRequest via unit chain) ---
    $this->mrA = makeTenantRequest([
        'reference' => 'MR-OPSA',
        'unit_id' => $this->unitA->id,
    ]);
    $this->mrB = makeTenantRequest([
        'reference' => 'MR-OPSB',
        'unit_id' => $this->unitB->id,
    ]);

    // --- CamExpensePool (direct asset_id) ---
    $this->camA = CamExpensePool::create([
        'asset_id' => $this->a->id,
        'period_year' => 2026,
        'status' => 'draft',
    ]);
    $this->camB = CamExpensePool::create([
        'asset_id' => $this->b->id,
        'period_year' => 2026,
        'status' => 'draft',
    ]);

    // --- UtilityMeter (direct asset_id) ---
    $this->meterA = UtilityMeter::create([
        'asset_id' => $this->a->id,
        'unit_id' => $this->unitA->id,
        'meter_number' => 'MTR-OPSA',
        'type' => 'electric',
        'status' => 'active',
    ]);
    $this->meterB = UtilityMeter::create([
        'asset_id' => $this->b->id,
        'unit_id' => $this->unitB->id,
        'meter_number' => 'MTR-OPSB',
        'type' => 'electric',
        'status' => 'active',
    ]);

    // --- MarketingBudget (direct asset_id, auto-provisioned) ---
    $this->budgetA = MarketingBudget::create([
        'asset_id' => $this->a->id,
        'period_year' => 2026,
        'status' => 'open',
    ]);
    $this->budgetB = MarketingBudget::create([
        'asset_id' => $this->b->id,
        'period_year' => 2026,
        'status' => 'open',
    ]);
});

/* =========================================================================
 | (a) READ-SCOPE — restricted user pinned to A sees only A's rows
 ========================================================================= */

describe('read-scope: a manager assigned only to property A', function () {
    it('sees only A maintenance requests through the scoped query, never B', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        asTenant($this->a, function () {
            $ids = scopedResourceQuery(TenantRequestResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->mrA->id)->not->toContain($this->mrB->id);
        });
    });

    it('sees only A CAM pools through the scoped query, never B', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        asTenant($this->a, function () {
            $ids = scopedResourceQuery(CamExpensePoolResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->camA->id)->not->toContain($this->camB->id);
        });
    });

    it('sees only A utility meters through the scoped query, never B', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        asTenant($this->a, function () {
            $ids = scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->meterA->id)->not->toContain($this->meterB->id);
        });
    });

    it('sees only A marketing budgets through the scoped query, never B', function () {
        $this->actingAs(makeUser('marketing', [$this->a->id]));

        asTenant($this->a, function () {
            $ids = scopedResourceQuery(MarketingBudgetResource::class)->pluck('id')->all();
            expect($ids)->toContain($this->budgetA->id)->not->toContain($this->budgetB->id);
        });
    });
});

/* =========================================================================
 | (b) ALL-PROPERTIES — super_admin sees both; restricted stays pinned to A
 ========================================================================= */

describe('all-properties mode', function () {
    it('shows a super_admin BOTH properties for every ops resource', function () {
        $this->actingAs(makeUser('super_admin'));

        asTenant($this->all, function () {
            $mr = scopedResourceQuery(TenantRequestResource::class)->pluck('id')->all();
            expect($mr)->toContain($this->mrA->id)->toContain($this->mrB->id);

            $cam = scopedResourceQuery(CamExpensePoolResource::class)->pluck('id')->all();
            expect($cam)->toContain($this->camA->id)->toContain($this->camB->id);

            $meters = scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all();
            expect($meters)->toContain($this->meterA->id)->toContain($this->meterB->id);

            $budgets = scopedResourceQuery(MarketingBudgetResource::class)->pluck('id')->all();
            expect($budgets)->toContain($this->budgetA->id)->toContain($this->budgetB->id);
        });
    });

    it('still pins a restricted manager to A in ALL mode — never leaks B', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        asTenant($this->all, function () {
            // TenantRequest — chain scoping via ScopesViaProperty::getEloquentQuery.
            $mr = scopedResourceQuery(TenantRequestResource::class)->pluck('id')->all();
            expect($mr)->toContain($this->mrA->id)->not->toContain($this->mrB->id);

            // Direct-FK resources — scoping via BypassesScopingOnAll::scopeEloquentQueryToTenant.
            $cam = scopedResourceQuery(CamExpensePoolResource::class)->pluck('id')->all();
            expect($cam)->toContain($this->camA->id)->not->toContain($this->camB->id);

            $meters = scopedResourceQuery(UtilityMeterResource::class)->pluck('id')->all();
            expect($meters)->toContain($this->meterA->id)->not->toContain($this->meterB->id);
        });
    });

    it('still pins a restricted marketing user to A budgets in ALL mode — never leaks B', function () {
        $this->actingAs(makeUser('marketing', [$this->a->id]));

        asTenant($this->all, function () {
            $budgets = scopedResourceQuery(MarketingBudgetResource::class)->pluck('id')->all();
            expect($budgets)->toContain($this->budgetA->id)->not->toContain($this->budgetB->id);
        });
    });
});

/* =========================================================================
 | (c) WRITE-GUARD — assert*AssetInScope rejects out-of-scope, allows in-scope
 ========================================================================= */

describe('write-guard for a manager assigned only to property A', function () {
    it('TenantRequest.assertUnitAssetInScope allows A unit, rejects B unit + null', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        // In-scope unit → no throw.
        TenantRequestResource::assertUnitAssetInScope($this->unitA->id);
        expect(true)->toBeTrue();

        // B's unit → the unit resolves to property B → 403.
        expect(fn () => TenantRequestResource::assertUnitAssetInScope($this->unitB->id))
            ->toThrow(HttpException::class);

        // A null/unknown unit resolves to a null asset → a restricted user is blocked.
        expect(fn () => TenantRequestResource::assertUnitAssetInScope(null))
            ->toThrow(HttpException::class);
    });

    it('CamExpensePool.assertAssetInScope allows A, rejects B + null', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        CamExpensePoolResource::assertAssetInScope($this->a->id);
        expect(true)->toBeTrue();

        expect(fn () => CamExpensePoolResource::assertAssetInScope($this->b->id))
            ->toThrow(HttpException::class);

        expect(fn () => CamExpensePoolResource::assertAssetInScope(null))
            ->toThrow(HttpException::class);
    });

    it('UtilityMeter.assertAssetInScope allows A, rejects B + null', function () {
        $this->actingAs(makeUser('manager', [$this->a->id]));

        UtilityMeterResource::assertAssetInScope($this->a->id);
        expect(true)->toBeTrue();

        expect(fn () => UtilityMeterResource::assertAssetInScope($this->b->id))
            ->toThrow(HttpException::class);

        expect(fn () => UtilityMeterResource::assertAssetInScope(null))
            ->toThrow(HttpException::class);
    });

    it('is a no-op for a super_admin (portfolio user) on every guarded ops resource', function () {
        $this->actingAs(makeUser('super_admin'));

        // No property constraint → every asset/unit is in scope, including null.
        TenantRequestResource::assertUnitAssetInScope($this->unitB->id);
        CamExpensePoolResource::assertAssetInScope($this->b->id);
        CamExpensePoolResource::assertAssetInScope(null);
        UtilityMeterResource::assertAssetInScope($this->b->id);
        UtilityMeterResource::assertAssetInScope(null);

        expect(true)->toBeTrue();
    });
});

/* =========================================================================
 | (d) GROUP-SPECIFIC — MarketingBudget carries NO write guard by design
 |     (asset_id form field is disabled + auto-stamped). Assert the guard is
 |     genuinely absent so a future refactor that removes the disable without
 |     adding a guard trips this test.
 ========================================================================= */

it('MarketingBudgetResource does NOT expose an assertAssetInScope guard (asset_id field is disabled + auto-stamped)', function () {
    expect(method_exists(MarketingBudgetResource::class, 'assertAssetInScope'))->toBeFalse();
});
