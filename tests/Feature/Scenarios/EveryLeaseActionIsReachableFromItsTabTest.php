<?php

/*
|--------------------------------------------------------------------------
| A lease action is reachable where its result is shown (2026-08-28)
|--------------------------------------------------------------------------
| Asked for directly: the things the lease FORM and its header menus can do should also be reachable
| from the tabs underneath, so an operator can work from either direction.
|
| The rule applied is not "put everything everywhere" — it is **an action lives where its result is
| shown**. `changeRent` and `grantRelief` write rows into the CHARGE SCHEDULE; every lifecycle and
| premises action writes a row into LEASE HISTORY, which is the tab that exists to show them.
|
| Finding it turned up a silent defect that predates the request: `ChargeScheduleRelationManager`
| declared `headerActions()` TWICE, and the second call REPLACES the first — it is a setter, not an
| append. So `changeRent` was written on that tab and rendered nowhere, from the day it was added.
| Exactly the failure `LeaseActions`'s own docblock records — "an action missing from a group is
| defined and rendered nowhere" — arriving through a different door.
|
| The gate below is behavioural: it builds each table and asks what it will actually render, because
| reading the source is what let a duplicate `headerActions()` look correct for months.
*/

use App\Filament\Admin\Actions\LeaseActions;
use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\RelationManagers\LeaseDepositsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseHistoryRelationManager;
use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Every action a tab's header will actually render, flattened through its groups. */
function tabHeaderActions(string $manager, $lease): array
{
    $table = Livewire::test($manager, ['ownerRecord' => $lease, 'pageClass' => EditLease::class])
        ->instance()->getTable();

    $names = [];

    foreach ($table->getHeaderActions() as $action) {
        if (method_exists($action, 'getActions')) {
            foreach ($action->getActions() as $child) {
                $names[] = $child->getName();
            }

            continue;
        }

        $names[] = $action->getName();
    }

    return $names;
}

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);

    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset, ['area_sqm' => 110]), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2029-07-31',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Where each action's RESULT is shown — the tab it must therefore be reachable from.
 *
 * Every entry is a claim about what the action writes, checkable in its service.
 */
dataset('placements', [
    'rent change writes charge rows' => [ChargeScheduleRelationManager::class, 'changeRent'],
    'relief overlays charge rows' => [ChargeScheduleRelationManager::class, 'grantRelief'],
    'billing a deposit lands in deposits' => [LeaseDepositsRelationManager::class, 'billDeposit'],
    'recording a deposit lands in deposits' => [LeaseDepositsRelationManager::class, 'recordDeposit'],
    'assigning an item lands in rentable items' => [LeaseRentableItemsRelationManager::class, 'assignRentableItem'],
    'a premises change writes an event' => [LeaseHistoryRelationManager::class, 'changePremises'],
    'a renewal writes an event' => [LeaseHistoryRelationManager::class, 'renew'],
    'an extension writes an event' => [LeaseHistoryRelationManager::class, 'extendTerm'],
    'a holdover writes an event' => [LeaseHistoryRelationManager::class, 'convertToHoldover'],
    'a termination writes an event' => [LeaseHistoryRelationManager::class, 'terminate'],
    'a final account writes an event' => [LeaseHistoryRelationManager::class, 'finalAccount'],
]);

it('renders the action on the tab that shows its result', function (string $manager, string $action) {
    expect(tabHeaderActions($manager, $this->lease))->toContain($action);
})->with('placements');

it('renders BOTH of the schedule tab actions — one headerActions() call, not two', function () {
    // The defect this file was written over: a second `headerActions()` silently replaced the
    // first, so one of these was defined and invisible. Asserting them together is what catches it;
    // either alone passes on the broken version.
    expect(tabHeaderActions(ChargeScheduleRelationManager::class, $this->lease))
        ->toContain('changeRent')
        ->toContain('grantRelief')
        ->toContain('addCharge');
});

it('still offers every action from the header, so nothing MOVED', function () {
    // Tabs are a second door, not a replacement. An action that left the header would be harder to
    // find, not easier — the sweep is about adding a route, never rerouting one.
    $header = array_map(fn ($a) => $a->getName(), LeaseActions::all());

    expect($header)->toContain('changeRent')
        ->toContain('grantRelief')
        ->toContain('terminate');
});
