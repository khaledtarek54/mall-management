<?php

use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\ListCamExpensePools;
use App\Filament\Admin\Resources\CamExpensePools\Tables\CamExpensePoolsTable;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * CAM write actions (generateAllocations / markReconciled / bill) gated their permission + status
 * ONLY in visible(). But Filament's mountAction() never checks isVisible(), and the seeded `viewer`
 * and `owner` roles both hold `cam.view` (so the pool list renders) — so a crafted dispatch let a
 * read-only auditor or owner Jawad generate allocations, re-open a reconciled pool, or bill. These
 * pin the abort_unless gate in action() via mountAction (NOT callAction, which asserts-visible-first
 * and would false-pass). See PortalReadOnlyDispatchGuardTest for the pattern.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 100]), null, ['status' => 'active']);
    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2026,
        'total_actual_expense' => 100000, 'total_estimated_collected' => 80000, 'status' => 'draft',
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Authenticate as a role, THEN scope the panel to the asset (setTenant needs a user). */
function camActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

it('refuses a read-only VIEWER generating allocations, even dispatched directly', function () {
    // viewer holds cam.view (list renders) but NOT cam.generate_allocations.
    camActAs('viewer');
    expect(CamExpensePoolsTable::canGenerate($this->pool))->toBeFalse();

    Livewire::test(ListCamExpensePools::class)
        ->mountAction(TestAction::make('generateAllocations')->table($this->pool))
        ->callMountedAction();

    expect($this->pool->fresh()->allocations()->count())->toBe(0)
        ->and($this->pool->fresh()->status)->toBe('draft'); // never bumped to reconciling
});

it('refuses a read-only OWNER marking a pool reconciled, even dispatched directly', function () {
    $this->pool->update(['status' => 'reconciling']);
    camActAs('owner');
    expect(CamExpensePoolsTable::canMarkReconciled($this->pool))->toBeFalse();

    Livewire::test(ListCamExpensePools::class)
        ->mountAction(TestAction::make('markReconciled')->table($this->pool))
        ->callMountedAction();

    expect($this->pool->fresh()->status)->toBe('reconciling')  // not flipped to reconciled
        ->and($this->pool->fresh()->reconciled_at)->toBeNull();
});

it('refuses re-opening a RECONCILED pool via generateAllocations — even for an authorized user', function () {
    // The status guard lived only in visible(); generateAllocations' action() then unconditionally
    // set status=reconciling, so dispatching it on a reconciled pool re-opened it.
    $this->pool->update(['status' => 'reconciled', 'reconciled_at' => now()]);
    camActAs('accounting'); // holds every cam.* perm — only the STATUS blocks it
    expect(CamExpensePoolsTable::canGenerate($this->pool))->toBeFalse();

    Livewire::test(ListCamExpensePools::class)
        ->mountAction(TestAction::make('generateAllocations')->table($this->pool))
        ->callMountedAction();

    expect($this->pool->fresh()->status)->toBe('reconciled'); // stayed sealed
});

it('refuses billing an allocation without cam.bill_allocation, even dispatched directly', function () {
    // Generate one allocation as an authorized user, then attempt to bill it as a role that has
    // cam.edit (can open the pool + its relation manager) but NOT cam.bill_allocation — the exact
    // custom-role gap the double-gate exists for. Revoke from the ROLE (a direct user-revoke leaves
    // the role grant), then reset the permission cache.
    app(CamReconciliationService::class)->generateAllocations($this->pool);
    $allocation = CamAllocation::where('cam_expense_pool_id', $this->pool->id)->first();

    \Spatie\Permission\Models\Role::findByName('accounting', 'web')->revokePermissionTo('cam.bill_allocation');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);
    expect(CamAllocationsRelationManager::canBill($allocation->fresh()))->toBeFalse();

    Livewire::test(CamAllocationsRelationManager::class, [
        'ownerRecord' => $this->pool,
        'pageClass' => EditCamExpensePool::class,
    ])
        ->mountAction(TestAction::make('bill')->table($allocation))
        ->callMountedAction();

    expect($allocation->fresh()->status)->toBe('pending'); // never billed
});

it('refuses VOIDING an allocation without cam.bill_allocation, even dispatched directly', function () {
    // void reverses money (invoices + credit notes) — same permission domain as bill.
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($this->pool);
    $billed = $svc->bill($this->pool->allocations()->first());

    \Spatie\Permission\Models\Role::findByName('accounting', 'web')->revokePermissionTo('cam.bill_allocation');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);
    expect(CamAllocationsRelationManager::canVoid($billed->fresh()))->toBeFalse();

    Livewire::test(CamAllocationsRelationManager::class, [
        'ownerRecord' => $this->pool,
        'pageClass' => EditCamExpensePool::class,
    ])
        ->mountAction(TestAction::make('void')->table($billed))
        ->callMountedAction();

    expect($billed->fresh()->status)->toBe('billed'); // never un-billed
});
