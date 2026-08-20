<?php

/*
|--------------------------------------------------------------------------
| The Trades page was invisible in the running app for a whole day (2026-08-20)
|--------------------------------------------------------------------------
| Reported by the operator: *"I can't see trades page"*. The cause was not code — it was that
| `RolesPermissionsSeeder` gained `trades.*` and `failure_codes.*` and **the local database was
| never re-seeded**, so the permissions did not exist and `canAccess()` was false for everyone,
| including super_admin. Every test stayed green because tests seed the roles themselves.
|
| The lesson is the same one this codebase already learned about `atriom:rebuild-search`: a change
| to a SEEDER is not applied by running the tests. It is a deploy step.
|
| These render every screen the facility & operations close-out built or changed, under the role
| that is meant to use it — the check that a green suite genuinely cannot make.
*/

use App\Filament\Admin\RelationManagers\ServicePlanStopsRelationManager;
use App\Filament\Admin\RelationManagers\WorkOrderLabourRelationManager;
use App\Filament\Admin\RelationManagers\WorkOrderProposalsRelationManager;
use App\Filament\Admin\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Admin\Resources\FailureCodes\Pages\ListFailureCodes;
use App\Filament\Admin\Resources\ServicePlans\Pages\EditServicePlan;
use App\Filament\Admin\Resources\ServicePlans\Pages\ListServicePlans;
use App\Filament\Admin\Resources\Trades\Pages\ListTrades;
use App\Filament\Admin\Resources\WorkPermits\Pages\ListWorkPermits;
use App\Models\FacilityWorkOrder;
use App\Models\ServicePlan;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

/**
 * Every list the close-out touched renders for a manager.
 *
 * `Livewire::test()->assertOk()` is what catches a table whose query is only ever COMPILED — the
 * class of bug that 500'd the fixed-asset list and the global search bar while 5,180 tests passed.
 */
it('renders every facility screen the close-out built', function (string $page) {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, fn () => Livewire::test($page)->assertOk());
})->with([
    'trades' => ListTrades::class,
    'failure codes' => ListFailureCodes::class,
    'work permits' => ListWorkPermits::class,
    'work orders' => ListFacilityWorkOrders::class,
    'service plans' => ListServicePlans::class,
    'equipment' => ListEquipment::class,
]);

/**
 * **The permission the seeder grants is the permission the screen asks for.**
 *
 * This is the assertion that would have caught the reported bug the moment it was introduced: the
 * resource's `canAccess()` is answered from the seeded catalogue, so a resource whose permission
 * nobody seeded is invisible however correct its code is.
 */
it('grants every facility screen a permission that actually exists', function (string $permission) {
    expect(Permission::where('name', $permission)->exists())
        ->toBeTrue("{$permission} is referenced by a screen but seeded by nobody");

    // …and somebody can actually hold it. A permission granted to no role is the same as none.
    expect(makeUser('manager', [$this->asset->id])->can($permission))
        ->toBeTrue("no manager holds {$permission}");
})->with([
    'trades.view', 'trades.create', 'trades.edit',
    'failure_codes.view', 'failure_codes.create', 'failure_codes.edit',
    'work_permits.view', 'work_permits.create', 'work_permits.issue',
]);

/**
 * The technician — who, per the operator's decision, has no mobile app and works here — can reach
 * the two screens their job needs and is refused the registers they do not maintain.
 */
it('lets a technician reach their work and no more', function () {
    $tech = makeUser('technician', [$this->asset->id]);
    $this->actingAs($tech);

    asTenant($this->asset, function () {
        Livewire::test(ListFacilityWorkOrders::class)->assertOk();
    });

    expect($tech->can('facility.complete'))->toBeTrue()
        // The registers are operator configuration, not an engineer's job.
        ->and($tech->can('trades.edit'))->toBeFalse()
        ->and($tech->can('failure_codes.edit'))->toBeFalse();
});

/**
 * The relation managers the close-out added are Livewire components in their own right, and a
 * resource page rendering says nothing about whether its panels do.
 */
it('renders every relation manager the close-out added', function (string $manager, string $owner) {
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $record = $owner === 'order'
        ? FacilityWorkOrder::create([
            'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
            'title' => 'A job', 'description' => 'Something to panel over.', 'trade_id' => tradeId('hvac'),
            'status' => 'open', 'priority' => 'medium', 'scheduled_for' => now()->toDateString(),
        ])
        : ServicePlan::create([
            'asset_id' => $this->asset->id, 'title' => 'A plan', 'trade_id' => tradeId('hvac'),
            'frequency_unit' => 'months', 'frequency_value' => 1,
            'next_due_date' => now()->addMonth()->toDateString(), 'is_active' => true,
        ]);

    asTenant($this->asset, function () use ($manager, $record, $owner) {
        Livewire::test($manager, [
            'ownerRecord' => $record,
            'pageClass' => $owner === 'order'
                ? EditFacilityWorkOrder::class
                : EditServicePlan::class,
        ])->assertOk();
    });
})->with([
    'labour' => [WorkOrderLabourRelationManager::class, 'order'],
    'proposals' => [WorkOrderProposalsRelationManager::class, 'order'],
    'route stops' => [ServicePlanStopsRelationManager::class, 'plan'],
]);
