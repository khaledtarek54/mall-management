<?php

use App\Filament\Admin\Actions\CamExpensePoolActions;
use App\Filament\Admin\RelationManagers\CamAllocationsRelationManager;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CAM write actions (generateAllocations / markReconciled / bill) gated their permission + status
 * ONLY in visible(); the fix added `abort_unless(...)` inside action(). The seeded `viewer` and
 * `owner` roles both hold `cam.view`, so the pool list renders for them.
 *
 * REWRITTEN 2026-07-31, because these tests did not test the fix. They dispatched via
 * mountAction — but `mountAction()` refuses DISABLED actions, and `CanBeDisabled::isDisabled()`
 * returns true when `isHidden()` does, so `visible()` short-circuits the dispatch long before
 * `action()` runs. Proven by mutation: deleting the abort_unless left this file 5/5 green. The
 * gate the tests were named after was never reached. See FilamentActionDispatchContractTest.
 *
 * So there are now two layers, tested separately:
 *
 *   1. `visible()` — the UI, and (on Filament v4.11.8) what actually refuses the dispatch. The
 *      mountAction tests below cover this and are honest about covering only this.
 *   2. `abort_unless()` inside action() — the layer that does not depend on an upstream
 *      implementation detail. Reached via `Action::call()`, which evaluates the action closure
 *      directly instead of going through the disabled check.
 *
 * Delete either gate and something here goes red. That is the whole point.
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

/**
 * Invoke a table action's own closure, bypassing the visibility/disabled short-circuit.
 *
 * `Action::call()` evaluates the action function directly (Action.php:666), which is the only way
 * to reach an `abort_unless` inside `action()` — mountAction refuses the action as disabled before
 * ever getting there, so a mountAction-based test can never exercise that gate.
 */
function callCamAction(string $name, CamExpensePool $pool): void
{
    // The acts moved to the pool's own page on 2026-08-30 — the list FINDS, the record ACTS —
    // so the gate is asserted against the registry both surfaces compose, not against a screen.
    $action = collect(CamExpensePoolActions::all())->first(fn ($a) => $a->getName() === $name);
    expect($action)->not->toBeNull("action [{$name}] not found on the CAM pools table");

    $action->record($pool)->call();
}

/** Authenticate as a role, THEN scope the panel to the asset (setTenant needs a user). */
function camActAs(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

it('refuses a read-only VIEWER generating allocations, even dispatched directly', function () {
    // viewer holds cam.view (list renders) but NOT cam.generate_allocations.
    camActAs('viewer');
    expect(CamExpensePoolActions::canGenerate($this->pool))->toBeFalse();

    // The acts live on the pool's own page now, and a viewer holds cam.view without cam.edit —
    // so the refusal lands one layer EARLIER than it used to: the page itself is refused, and the
    // act has no surface to be dispatched from at all.
    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getRouteKey()])
        ->assertForbidden();

    expect($this->pool->fresh()->allocations()->count())->toBe(0)
        ->and($this->pool->fresh()->status)->toBe('draft'); // never bumped to reconciling
});

it('refuses a read-only OWNER marking a pool reconciled, even dispatched directly', function () {
    $this->pool->update(['status' => 'reconciling']);
    camActAs('owner');
    expect(CamExpensePoolActions::canMarkReconciled($this->pool))->toBeFalse();

    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getRouteKey()])
        ->assertForbidden();

    expect($this->pool->fresh()->status)->toBe('reconciling')  // not flipped to reconciled
        ->and($this->pool->fresh()->reconciled_at)->toBeNull();
});

it('refuses re-opening a RECONCILED pool via generateAllocations — even for an authorized user', function () {
    // The status guard lived only in visible(); generateAllocations' action() then unconditionally
    // set status=reconciling, so dispatching it on a reconciled pool re-opened it.
    $this->pool->update(['status' => 'reconciled', 'reconciled_at' => now()]);
    camActAs('accounting'); // holds every cam.* perm — only the STATUS blocks it
    expect(CamExpensePoolActions::canGenerate($this->pool))->toBeFalse();

    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getRouteKey()])
        ->mountAction(TestAction::make('generateAllocations'))
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

    Role::findByName('accounting', 'web')->revokePermissionTo('cam.bill_allocation');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

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

    Role::findByName('accounting', 'web')->revokePermissionTo('cam.bill_allocation');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

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

/* ---- layer 2: the abort_unless inside action() ---------------------------- */

it('refuses a viewer generating allocations when the action closure is reached directly', function () {
    // The test the mountAction version was always meant to be. visible() cannot help here: the
    // closure is invoked directly, so only the abort_unless stands between a read-only auditor
    // and a full CAM allocation run.
    camActAs('viewer');

    expect(fn () => callCamAction('generateAllocations', $this->pool))
        ->toThrow(HttpException::class);

    expect($this->pool->fresh()->allocations()->count())->toBe(0)
        ->and($this->pool->fresh()->status)->toBe('draft');
});

it('refuses a viewer marking a pool reconciled when the action closure is reached directly', function () {
    $this->pool->update(['status' => 'reconciling']);
    camActAs('viewer');

    expect(fn () => callCamAction('markReconciled', $this->pool))
        ->toThrow(HttpException::class);

    expect($this->pool->fresh()->status)->toBe('reconciling')
        ->and($this->pool->fresh()->reconciled_at)->toBeNull();
});

it('control: an authorised user CAN generate allocations through the same path', function () {
    // Without this the two refusals above would pass just as happily if call() never ran the
    // closure at all — the failure mode that made the ORIGINAL tests meaningless.
    camActAs('accounting');

    callCamAction('generateAllocations', $this->pool);

    expect($this->pool->fresh()->allocations()->count())->toBeGreaterThan(0);
});
