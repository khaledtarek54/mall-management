<?php

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\CreateMaintenancePlan;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\MaintenanceWorkOrderResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\CreateMaintenanceWorkOrder;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\SlaPolicies\Pages\CreateSlaPolicy;
use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\Pages\CreateUtilityMeter;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Filament\Admin\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Area;
use App\Models\CamExpensePool;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\PurchaseRequest;
use App\Models\SlaPolicy;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Models\Violation;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Property-first isolation lock-in (docs/plans/03-remove-all-properties-mode.md, step 3).
 *
 * With "All Properties" removed from the switcher, a direct-`asset_id` create ALWAYS happens inside
 * ONE real mall. That collapses `TenantScope::visibleAssetIds()` to `[currentMall]` (it returns the
 * whole assigned set only in the now-removed All-Properties mode), so BOTH isolation layers confine
 * the create to the mall the operator is in — this file exercises each explicitly:
 *   1. FORM layer — the property `Select`, scoped via `selectableAssetOptions()`, rejects an
 *      out-of-mall pick at validation (first test).
 *   2. SERVER-GUARD layer — `assertAssetInScope()`, the write guard in each create page's mutate
 *      hook, rejects any mall other than the current one (second test). This is the layer a CRAFTED
 *      request that bypasses the disabled/scoped Select would hit; the first test cannot reach it
 *      because `fillForm()` is stopped at validation.
 *
 * The actor is **super_admin** so this is not merely re-asserting role permissions — it proves the
 * confinement holds for the MOST-privileged user, whose guard is a no-op only when no real mall is
 * active. `AssetInScopeWriteGuardTest` covers the tenant-less restricted-user case; this file covers
 * the property-first tenant-active case (super_admin, `visibleAssetIds()` === `[currentMall]`).
 *
 * Sibling of AllPropertiesCreatePinsAssetTest, which covers the (now test-only) forced All-mode path.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->currentMall = makeAsset(['code' => 'REAL']);
    $this->otherMall = makeAsset(['code' => 'OTHER']); // a real, existing mall the crafted value points at
    $this->actingAs(makeUser('super_admin'));

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // Operational reality post-All-Properties: the panel is pinned to ONE real mall.
    Filament::setTenant($this->currentMall);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

dataset('direct_fk_pickable_asset_resources', [
    // resource, create page, model, fn(int $craftedMallId): array $formData
    'Warehouse' => [WarehouseResource::class, CreateWarehouse::class, Warehouse::class,
        fn (int $mall) => ['asset_id' => $mall, 'name' => 'Central Store', 'code' => 'WH1']],
    'Unit' => [UnitResource::class, CreateUnit::class, Unit::class,
        fn (int $mall) => ['asset_id' => $mall, 'code' => 'U-01', 'category' => 'retail', 'area_sqm' => 55, 'status' => 'vacant']],
    'Employee' => [EmployeeResource::class, CreateEmployee::class, Employee::class,
        fn (int $mall) => ['asset_id' => $mall, 'code' => 'E-01', 'name' => 'Sara', 'payment_method' => 'bank']],
    'Equipment' => [EquipmentResource::class, CreateEquipment::class, Equipment::class,
        fn (int $mall) => ['asset_id' => $mall, 'code' => 'EQ-01', 'name_en' => 'Chiller', 'name_ar' => 'مبرد']],
    'FixedAsset' => [FixedAssetResource::class, CreateFixedAsset::class, FixedAsset::class,
        fn (int $mall) => ['asset_id' => $mall, 'name' => 'Generator', 'tag' => 'FA-01', 'acquisition_cost' => 100000, 'useful_life_months' => 60, 'funded_from' => 'cash']],
    'MaintenancePlan' => [MaintenancePlanResource::class, CreateMaintenancePlan::class, MaintenancePlan::class,
        fn (int $mall) => ['asset_id' => $mall, 'title' => 'Monthly HVAC service']],
    'SlaPolicy' => [SlaPolicyResource::class, CreateSlaPolicy::class, SlaPolicy::class,
        fn (int $mall) => ['asset_id' => $mall, 'priority' => 'high', 'resolve_hours' => 8]],
    'UtilityMeter' => [UtilityMeterResource::class, CreateUtilityMeter::class, UtilityMeter::class,
        fn (int $mall) => ['asset_id' => $mall, 'meter_number' => 'MTR-01', 'type' => 'electric', 'status' => 'active']],
    'CamExpensePool' => [CamExpensePoolResource::class, CreateCamExpensePool::class, CamExpensePool::class,
        fn (int $mall) => ['asset_id' => $mall, 'period_year' => 2026, 'status' => 'draft', 'total_actual_expense' => 500, 'total_estimated_collected' => 500]],
    'PurchaseRequest' => [PurchaseRequestResource::class, CreatePurchaseRequest::class, PurchaseRequest::class,
        fn (int $mall) => ['asset_id' => $mall, 'justification' => 'Restock cleaning supplies']],
    'MaintenanceWorkOrder' => [MaintenanceWorkOrderResource::class, CreateMaintenanceWorkOrder::class, MaintenanceWorkOrder::class,
        fn (int $mall) => ['asset_id' => $mall, 'title' => 'Fix escalator', 'category' => 'hvac', 'priority' => 'medium', 'scheduled_for' => now()->toDateString()]],
    'Violation' => [ViolationResource::class, CreateViolation::class, Violation::class,
        fn (int $mall) => ['asset_id' => $mall, 'tenant_id' => makeTenant()->id, 'description' => 'Blocked fire exit', 'violation_date' => now()->toDateString()]],
    'Area' => [AreaResource::class, CreateArea::class, Area::class,
        fn (int $mall) => ['asset_id' => $mall, 'name' => 'Food Court', 'code' => 'FC']],
]);

it('confines a direct-FK create to the current mall — a crafted other-mall asset_id is rejected', function (
    string $resource,
    string $createPage,
    string $model,
    Closure $formData,
) {
    // Craft asset_id = the OTHER (real, existing) mall while the panel is pinned to REAL.
    Livewire::test($createPage)
        ->fillForm($formData($this->otherMall->id))
        ->call('create')
        // The property Select is scoped to the current mall, so an out-of-mall pick is invalid.
        ->assertHasFormErrors(['asset_id']);

    // Nothing was filed into the other mall.
    expect($model::where('asset_id', $this->otherMall->id)->exists())->toBeFalse();
})->with('direct_fk_pickable_asset_resources');

it('server-side write guard confines even super_admin to the current mall (independent of the form)', function (
    string $resource,
    string $createPage,
    string $model,
    Closure $formData,
) {
    // The layer a crafted request that bypasses the disabled/scoped Select would hit. With a real
    // mall active, visibleAssetIds() === [currentMall], so assertAssetInScope() 403s any other mall
    // even for super_admin — assertAssetInScope() otherwise accepts any *visible* mall.
    $resource::assertAssetInScope($this->currentMall->id); // in-mall → allowed (no throw)

    expect(fn () => $resource::assertAssetInScope($this->otherMall->id))
        ->toThrow(HttpException::class); // out-of-mall → 403
})->with('direct_fk_pickable_asset_resources');
