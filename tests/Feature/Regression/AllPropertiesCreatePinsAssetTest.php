<?php

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use App\Filament\Admin\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\CreateFacilityWorkOrder;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\ServicePlans\Pages\CreateServicePlan;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
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
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\FacilityWorkOrder;
use App\Models\FixedAsset;
use App\Models\PurchaseRequest;
use App\Models\ServicePlan;
use App\Models\SlaPolicy;
use App\Models\Unit;
use App\Models\UtilityMeter;
use App\Models\Violation;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression — the "Announcements tenancy trap" (systemic). Every property-owned resource whose form
 * lets the operator PICK asset_id, with that Select enabled in All-Properties mode, must store the
 * CHOSEN mall — not the ALL pseudo-asset.
 *
 * While such a resource used Filament auto-tenancy (`$tenantOwnershipRelationshipName='asset'` +
 * BypassesScopingOnAll, which does NOT turn `isScopedToTenant()` off), Panel::boot() registered a
 * model `creating` hook that force-associated asset_id with the CURRENT tenant — and in All-mode the
 * tenant is the ALL pseudo-asset. So a record created in All-mode picking a real mall was silently
 * stored against the pseudo-asset and vanished from every real mall's scoped list. Fixed by switching
 * each to BypassesFilamentTenantAutoScope + an explicit getEloquentQuery (the AnnouncementResource
 * pattern), so the picked asset_id is kept and re-validated by assertAssetInScope.
 *
 * IMPORTANT — this test REGISTERS the clobber hook itself (`observeTenancyModelCreation`, exactly as
 * Panel::boot() does; see AdminCreateFlowsTest). Livewire::test() does NOT run the SetUpPanel
 * middleware that boots the panel, so without this the hook never fires and the bug can't reproduce —
 * the assertion would pass on the UNFIXED code too (a false guard). With the hook registered, a
 * fixed resource (isScopedToTenant()===false) makes observeTenancyModelCreation a no-op, while a
 * revert to auto-tenancy re-registers the hook and this test goes red.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mall = makeAsset(['code' => 'REAL']);
    $this->actingAs(makeUser('super_admin'));

    $this->panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($this->panel);
    // Put the panel in All-Properties mode — the exact condition that triggered the clobber.
    Filament::setTenant(Asset::where('code', Asset::ALL_PROPERTIES_CODE)->firstOrFail());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

dataset('all_mode_pickable_asset_resources', [
    // resource, create page, model, fn(int $mallId, self $test): array $formData
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
    'ServicePlan' => [ServicePlanResource::class, CreateServicePlan::class, ServicePlan::class,
        fn (int $mall) => ['asset_id' => $mall, 'title' => 'Monthly HVAC service', 'trade_id' => tradeId('hvac')]],
    'SlaPolicy' => [SlaPolicyResource::class, CreateSlaPolicy::class, SlaPolicy::class,
        fn (int $mall) => ['asset_id' => $mall, 'priority' => 'high', 'resolve_hours' => 8]],
    'UtilityMeter' => [UtilityMeterResource::class, CreateUtilityMeter::class, UtilityMeter::class,
        fn (int $mall) => ['asset_id' => $mall, 'meter_number' => 'MTR-01', 'type' => 'electric', 'status' => 'active']],
    'CamExpensePool' => [CamExpensePoolResource::class, CreateCamExpensePool::class, CamExpensePool::class,
        fn (int $mall) => ['asset_id' => $mall, 'period_year' => 2026, 'status' => 'draft', 'total_actual_expense' => 500, 'total_estimated_collected' => 500]],
    'PurchaseRequest' => [PurchaseRequestResource::class, CreatePurchaseRequest::class, PurchaseRequest::class,
        fn (int $mall) => ['asset_id' => $mall, 'justification' => 'Restock cleaning supplies']],
    'FacilityWorkOrder' => [FacilityWorkOrderResource::class, CreateFacilityWorkOrder::class, FacilityWorkOrder::class,
        fn (int $mall) => ['asset_id' => $mall, 'title' => 'Fix escalator', 'trade_id' => tradeId('hvac'), 'priority' => 'medium', 'scheduled_for' => now()->toDateString()]],
    // Already-fixed reference resources — kept here so the (now hook-registering) guard covers them too.
    'Violation' => [ViolationResource::class, CreateViolation::class, Violation::class,
        fn (int $mall) => ['asset_id' => $mall, 'tenant_id' => makeTenant()->id, 'description' => 'Blocked fire exit', 'violation_date' => now()->toDateString()]],
    'Area' => [AreaResource::class, CreateArea::class, Area::class,
        fn (int $mall) => ['asset_id' => $mall, 'name' => 'Food Court', 'code' => 'FC']],
]);

it('pins a record to the CHOSEN mall when created in All-Properties mode', function (
    string $resource,
    string $createPage,
    string $model,
    Closure $formData,
) {
    // Register the exact clobber hook Panel::boot() would — a no-op for a fixed resource
    // (isScopedToTenant()===false), but it re-registers (and reproduces the bug) if a resource is
    // reverted to Filament auto-tenancy.
    $resource::observeTenancyModelCreation($this->panel);

    Livewire::test($createPage)
        ->fillForm($formData($this->mall->id))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($model::latest('id')->first()->asset_id)
        ->toBe($this->mall->id); // the real mall, NOT the ALL pseudo-asset
})->with('all_mode_pickable_asset_resources');
