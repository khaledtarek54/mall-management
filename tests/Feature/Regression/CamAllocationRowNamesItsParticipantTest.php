<?php

use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression: AN ALLOCATION ROW MUST NAME WHO IT IS AGAINST, AND MUST NOT CONTRADICT ITSELF.
 *
 * Two defects on one table, both reported from the panel as "the numbers are wrong".
 *
 * (1) A pool apportions to LEASES and to the OWNERS of sold units (module 37), and both the Tenant
 *     and the Unit column read `lease.*` — so every ownership row rendered with no name and no unit.
 *     Six of the 39 rows on the demo's 2026 pool: money against nobody, with nothing on screen to
 *     say the blanks are owners. `tenantName()` was written to fix exactly this for the modal title
 *     and read `unitOwnership->tenant`; the relation is `owner`, and an undefined relation resolves
 *     to NULL rather than throwing, so the fix answered '—' for every owner and looked correct.
 *
 * (2) The cap columns were added "so the true-up reconciles when a cap bites" and hidden by default,
 *     which is the one state in which it does not. `allocated 52,983.90` and `estimated 50,213.50`
 *     beside a true-up of `−30,368.50` are three numbers that cannot all be right. They now show
 *     exactly when a cap actually refused cost — the rule the tenant's own statement already uses.
 *
 * Every assertion is paired with its opposite: a table that showed the columns always would satisfy
 * the first half, and one that named everybody 'X' would satisfy the naming half.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CAM-ROW']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->span = ['commencement_date' => '2024-01-01', 'expiry_date' => '2029-12-31'];

    $this->pool = fn (array $extra = []) => CamExpensePool::create(array_merge([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'draft',
        'total_actual_expense' => 100_000,
        'total_estimated_collected' => 0,
        'expense_basis' => 'stated',
        'estimate_basis' => 'stated',
        'admin_fee_pct' => 0,
    ], $extra));

    $this->manager = fn ($pool) => Livewire::test(CamAllocationsRelationManager::class, [
        'ownerRecord' => $pool,
        'pageClass' => EditCamExpensePool::class,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('names the OWNER of a sold unit, not a blank cell', function () {
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(['name' => 'Trading Tenant']), $this->span);

    $ownedUnit = makeUnit($this->asset, ['area_sqm' => 100, 'code' => 'OWNED-1']);
    // Through the shared helper: `Tenant` has no `asset_id` and no `contact_email`, and Eloquent
    // DROPS an unknown key silently — so the fixture set up a different state than it read as, which
    // is the shape `FixtureColumnsExistConformanceTest` exists to catch. It caught this.
    $owner = makeTenant(['name' => 'Hoda The Unit Owner']);
    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $ownedUnit->id,
        'tenant_id' => $owner->id,
        'status' => 'handed_over',
        'handover_date' => '2024-06-01',
    ]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    $ownerRow = CamAllocation::where('cam_expense_pool_id', $pool->id)->whereNull('lease_id')->sole();

    expect(CamAllocationsRelationManager::participantName($ownerRow))->toBe('Hoda The Unit Owner')
        ->and(CamAllocationsRelationManager::participantUnit($ownerRow))->toBe('OWNED-1');

    // And on the rendered table, beside the lease row — the control, because a table naming
    // everybody the same thing would pass the assertion above on its own.
    ($this->manager)($pool)
        ->assertSuccessful()
        ->assertSee('Hoda The Unit Owner')
        ->assertSee('OWNED-1')
        ->assertSee('Trading Tenant');

    unset($lease);
});

it('finds an owner by name in the table search, not just a tenant', function () {
    // A computed column has no column to search. Left on Filament's default the search reaches the
    // lease only, and typing an owner's name empties the table — which reads as "no such record".
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(['name' => 'Zebra Retail']), $this->span);

    $ownedUnit = makeUnit($this->asset, ['area_sqm' => 100, 'code' => 'OWNED-2']);
    // Through the shared helper: `Tenant` has no `asset_id` and no `contact_email`, and Eloquent
    // DROPS an unknown key silently — so the fixture set up a different state than it read as, which
    // is the shape `FixtureColumnsExistConformanceTest` exists to catch. It caught this.
    $owner = makeTenant(['name' => 'Quokka Holdings']);
    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $ownedUnit->id,
        'tenant_id' => $owner->id,
        'status' => 'handed_over',
        'handover_date' => '2024-06-01',
    ]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    $ownerRow = CamAllocation::where('cam_expense_pool_id', $pool->id)->whereNull('lease_id')->sole();
    $leaseRow = CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease->id)->sole();

    ($this->manager)($pool)
        ->searchTable('Quokka')
        ->assertCanSeeTableRecords([$ownerRow])
        ->assertCanNotSeeTableRecords([$leaseRow]);

    // The control: the lease side of the same search still works.
    ($this->manager)($pool)
        ->searchTable('Zebra')
        ->assertCanSeeTableRecords([$leaseRow])
        ->assertCanNotSeeTableRecords([$ownerRow]);
});

it('hides the cap columns on a pool where no cap refused anything', function () {
    makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $this->span);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    ($this->manager)($pool)
        ->assertTableColumnHidden('capped_cost_amount')
        ->assertTableColumnHidden('cap_absorbed_amount');
});

it('shows the cap columns as soon as a cap actually refuses cost, so the row adds up', function () {
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $this->span);
    $lease->camTerms()->create([
        'effective_year' => 2025,
        'cap_type' => 'absolute',
        'cap_absolute_amount' => 40_000,
    ]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    $row = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    // The cap really bit: the whole pool is this lease's, and the ceiling is below it.
    expect((float) $row->allocated_amount)->toBe(100_000.0)
        ->and((float) $row->capped_cost_amount)->toBe(40_000.0)
        ->and((float) $row->cap_absorbed_amount)->toBe(60_000.0);

    ($this->manager)($pool)
        ->assertTableColumnVisible('capped_cost_amount')
        ->assertTableColumnVisible('cap_absorbed_amount');
});

it('keeps the columns hidden when a cap is set ABOVE the share and refuses nothing', function () {
    // The case that separates "has a cap" from "a cap explained something". A ceiling nobody reaches
    // leaves the plain columns adding up perfectly, so two more of them explain nothing.
    $lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), makeTenant(), $this->span);
    $lease->camTerms()->create([
        'effective_year' => 2025,
        'cap_type' => 'absolute',
        'cap_absolute_amount' => 500_000,
    ]);

    $pool = ($this->pool)();
    app(CamReconciliationService::class)->generateAllocations($pool);

    $row = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    expect($row->cap_amount)->not->toBeNull()
        ->and((float) $row->cap_absorbed_amount)->toBe(0.0);

    ($this->manager)($pool)
        ->assertTableColumnHidden('capped_cost_amount')
        ->assertTableColumnHidden('cap_absorbed_amount');
});
