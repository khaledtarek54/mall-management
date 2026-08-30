<?php

use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\ListTenantSalesDeclarations;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\CamExpensePool;
use App\Models\Department;
use App\Models\TenantSalesDeclaration;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression guards for the app-wide authorization audit. Two proven bug classes:
 *  (1) property-scoping leaked every property to a restricted user in
 *      "All Properties" mode (scoping only checked the single-property id);
 *  (2) custom Filament Action::make() row/header/relation-manager actions don't
 *      inherit RoleGatedActions auto-authorization, so they defaulted to allowed,
 *      letting read-only / under-privileged roles run privileged mutations.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/*
| ---- Property scoping: no cross-property leak in All-Properties mode ----- |
*/

it('pins a restricted user to their assigned properties in All-mode (direct-FK resource)', function () {
    $a = makeAsset(['code' => 'AA']);
    $b = makeAsset(['code' => 'BB']);
    $unitA = makeUnit($a);
    $unitB = makeUnit($b);

    $this->actingAs(makeUser('operations', [$a->id])); // assigned to A only
    $all = ensureAllPropertiesAsset();
    Filament::setTenant($all);                          // "All Properties"

    // UnitResource opts out of Filament auto-tenancy (BypassesFilamentTenantAutoScope) and applies
    // the per-property scope itself in getEloquentQuery() — the query Filament runs for the table.
    $ids = UnitResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($unitA->id)->not->toContain($unitB->id);
});

it('pins a restricted user to their assigned properties in All-mode (indirect resource)', function () {
    $a = makeAsset(['code' => 'AA']);
    $b = makeAsset(['code' => 'BB']);
    $invA = makeInvoice(makeLease(makeUnit($a)));
    $invB = makeInvoice(makeLease(makeUnit($b)));

    $this->actingAs(makeUser('accounting', [$a->id]));
    Filament::setTenant(ensureAllPropertiesAsset());

    $ids = InvoiceResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($invA->id)->not->toContain($invB->id);
});

it('still shows a super_admin every property in All-mode', function () {
    $a = makeAsset(['code' => 'AA']);
    $b = makeAsset(['code' => 'BB']);
    $unitA = makeUnit($a);
    $unitB = makeUnit($b);

    $this->actingAs(makeUser('super_admin'));
    $all = ensureAllPropertiesAsset();
    Filament::setTenant($all);

    $ids = UnitResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($unitA->id)->toContain($unitB->id);
});

/*
| ---- Custom action gating: read-only roles can't run privileged actions -- |
*/

it('hides sales-declaration lock/dispute from a role without the permission', function () {
    $asset = makeAsset();
    $decl = TenantSalesDeclaration::create([
        'lease_id' => makeLease(makeUnit($asset))->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 250000, 'declared_at' => '2026-02-01', 'status' => 'submitted',
    ]);

    $this->actingAs(makeUser('viewer', [$asset->id])); // tenant_sales.view, NOT .lock/.dispute
    Filament::setTenant($asset);

    Livewire::test(ListTenantSalesDeclarations::class)
        ->assertTableActionHidden('lock', $decl)
        ->assertTableActionHidden('dispute', $decl);
});

it('shows the sales-declaration lock action to a role that holds tenant_sales.lock', function () {
    $asset = makeAsset();
    $decl = TenantSalesDeclaration::create([
        'lease_id' => makeLease(makeUnit($asset))->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        'declared_sales' => 250000, 'declared_at' => '2026-02-01', 'status' => 'submitted',
    ]);

    $this->actingAs(makeUser('leasing', [$asset->id])); // holds tenant_sales.lock
    Filament::setTenant($asset);

    Livewire::test(ListTenantSalesDeclarations::class)
        ->assertTableActionVisible('lock', $decl);
});

it('hides the CAM generate-allocations action from a role without cam.generate_allocations', function () {
    $asset = makeAsset();
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 100000, 'total_estimated_collected' => 80000, 'status' => 'draft',
    ]);

    $this->actingAs(makeUser('viewer', [$asset->id])); // cam.view, NOT cam.generate_allocations
    Filament::setTenant($asset);

    // The act moved to the pool's own page on 2026-08-30 — the list FINDS, the record ACTS —
    // and a viewer holds cam.view without cam.edit, so the refusal is now the PAGE itself.
    Livewire::test(EditCamExpensePool::class, ['record' => $pool->getRouteKey()])
        ->assertForbidden();
});

it('shows the CAM generate-allocations action to a role that holds cam.generate_allocations', function () {
    $asset = makeAsset();
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 100000, 'total_estimated_collected' => 80000, 'status' => 'draft',
    ]);

    $this->actingAs(makeUser('accounting', [$asset->id])); // holds cam.generate_allocations
    Filament::setTenant($asset);

    Livewire::test(EditCamExpensePool::class, ['record' => $pool->getRouteKey()])
        ->assertActionVisible('generateAllocations');
});

/*
| ---- Relation-manager action gating: dept membership = role grant -------- |
*/

it('hides department-member attach from a role without roles.edit (manager)', function () {
    $this->actingAs(makeUser('manager')); // departments.edit but NOT roles.edit
    Filament::setTenant(makeAsset(['code' => 'HW']));
    $dept = Department::create(['name' => 'Operations']);

    Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ])->assertTableActionHidden('attach');
});

it('shows department-member attach to super_admin', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset(['code' => 'HW']));
    $dept = Department::create(['name' => 'Operations']);

    Livewire::test(DepartmentMembersRelationManager::class, [
        'ownerRecord' => $dept,
        'pageClass' => EditDepartment::class,
    ])->assertTableActionVisible('attach');
});
