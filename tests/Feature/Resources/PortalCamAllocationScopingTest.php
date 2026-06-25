<?php

use App\Filament\Portal\Resources\CamAllocations\Pages\ListCamAllocations as PortalListCamAllocations;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->tenantA = makeTenant(['name' => 'Cafe Crema', 'password' => bcrypt('password')]);
    $this->tenantB = makeTenant(['name' => 'Optix Eyewear', 'password' => bcrypt('password')]);
    $this->unitA = makeUnit($this->asset);
    $this->unitB = makeUnit($this->asset);
    $this->leaseA = makeLease($this->unitA, $this->tenantA, ['status' => 'active']);
    $this->leaseB = makeLease($this->unitB, $this->tenantB, ['status' => 'active']);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2025,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 90000,
        'status' => 'reconciled',
    ]);

    $this->allocA = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $this->leaseA->id,
        'pro_rata_share_pct' => 60,
        'allocated_amount' => 60000,
        'estimated_paid' => 54000,
        'true_up_amount' => 6000,
        'status' => 'billed',
    ]);
    $this->allocB = CamAllocation::create([
        'cam_expense_pool_id' => $this->pool->id,
        'lease_id' => $this->leaseB->id,
        'pro_rata_share_pct' => 40,
        'allocated_amount' => 40000,
        'estimated_paid' => 36000,
        'true_up_amount' => 4000,
        'status' => 'billed',
    ]);
});

it('portal tenant A sees only their own CAM allocation, not Tenant B\'s', function () {
    $this->actingAs(makeTenantUser($this->tenantA), 'portal');

    Livewire::test(PortalListCamAllocations::class)
        ->assertCanSeeTableRecords([$this->allocA])
        ->assertCanNotSeeTableRecords([$this->allocB]);
});

it('portal tenant B sees only their own CAM allocation', function () {
    $this->actingAs(makeTenantUser($this->tenantB), 'portal');

    Livewire::test(PortalListCamAllocations::class)
        ->assertCanSeeTableRecords([$this->allocB])
        ->assertCanNotSeeTableRecords([$this->allocA]);
});
