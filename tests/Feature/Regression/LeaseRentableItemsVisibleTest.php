<?php

use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A lease must SHOW the space it rents beyond its premises.
 *
 * Assign and Release worked, and the money followed correctly — but only from the leases *list*
 * overflow menu, and the lease's own page had seven relation managers and none for rentable items.
 * So an operator could let a parking bay and then had no way to see they had: the assignment existed
 * only as a line on an invoice. Reported as "I can't see it", which is the right description —
 * working business logic with no surface is indistinguishable from a missing feature.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function heldLease(): array
{
    $asset = makeAsset();
    Filament::setTenant($asset);

    $lease = makeLease(makeUnit($asset, ['code' => 'S-01']), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 30000,
    ])->fresh();

    $item = RentableItem::create([
        'asset_id' => $asset->id,
        'code' => 'P-001',
        'type' => RentableItem::TYPE_PARKING,
        'monthly_rate' => 900,
    ]);

    return [$lease, $item];
}

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('lists the bays a lease holds, on the lease', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    [$lease, $item] = heldLease();

    app(AssignRentableItemService::class)->assign($lease, $item, ['effective_from' => '2026-03-01']);

    Livewire::test(LeaseRentableItemsRelationManager::class, [
        'ownerRecord' => $lease->fresh(),
        'pageClass' => EditLease::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$item]);
});

it('assigns a bay from the lease page, and the charge follows', function () {
    // The claim the whole design rests on, exercised through the SCREEN rather than the service:
    // one click, and the lease's parking charge is there.
    CarbonImmutable::setTestNow('2026-03-05');
    [$lease, $item] = heldLease();

    Livewire::test(LeaseRentableItemsRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])
        // `assignRentableItem`, not `assign`: the action was renamed on 2026-08-17 by
        // "refactor(leases): the list finds, the record acts", and this test — written on the 10th —
        // kept the old name and has been red ever since. The product was never broken; the relation
        // manager and LeaseActions agree on the new name throughout.
        ->callTableAction('assignRentableItem', data: [
            'rentable_item_id' => $item->id,
            'effective_from' => '2026-03-01',
            'monthly_rate' => 650,
        ]);

    expect($lease->fresh()->rentableItems()->count())->toBe(1)
        // The negotiated rate, and the money in the same click.
        ->and((float) $lease->fresh()->charges()->where('type', 'parking')->sole()->amount)->toBe(650.0);
});

it('releases a bay from the lease page and closes the charge', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    [$lease, $item] = heldLease();

    app(AssignRentableItemService::class)->assign($lease, $item, ['effective_from' => '2026-03-01']);

    Livewire::test(LeaseRentableItemsRelationManager::class, [
        'ownerRecord' => $lease->fresh(),
        'pageClass' => EditLease::class,
    ])
        ->callTableAction('release', $item, data: ['effective_to' => '2026-03-31']);

    $row = $lease->fresh()->charges()->where('type', 'parking')->sole();

    expect($row->end_date->toDateString())->toBe('2026-03-31')
        ->and((bool) $row->is_active)->toBeFalse()
        ->and($item->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);
});

it('offers only bays that are actually lettable', function () {
    // The picker must not offer a bay somebody already holds, nor one out of service — the service
    // refuses both, and offering them turns a rule into an error message.
    CarbonImmutable::setTestNow('2026-03-05');
    [$lease, $free] = heldLease();
    $asset = $lease->unit->asset;

    $taken = RentableItem::create(['asset_id' => $asset->id, 'code' => 'P-002', 'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 900]);
    $broken = RentableItem::create(['asset_id' => $asset->id, 'code' => 'P-003', 'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 900, 'status' => RentableItem::STATUS_OUT_OF_SERVICE]);
    $elsewhere = RentableItem::create(['asset_id' => makeAsset()->id, 'code' => 'P-900', 'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 900]);

    $other = makeLease(makeUnit($asset, ['code' => 'S-02']), null, ['status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2029-12-31'])->fresh();
    app(AssignRentableItemService::class)->assign($other, $taken, ['effective_from' => '2026-03-01']);

    $rm = new LeaseRentableItemsRelationManager;
    $rm->ownerRecord = $lease->fresh();

    $options = (fn () => $this->lettableOptions())->call($rm);

    expect($options)->toHaveKey($free->id)
        ->and($options)->not->toHaveKey($taken->id)
        ->and($options)->not->toHaveKey($broken->id)
        ->and($options)->not->toHaveKey($elsewhere->id);
});

it('hides the write actions from a role without the permission', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    [$lease, $item] = heldLease();
    app(AssignRentableItemService::class)->assign($lease, $item, ['effective_from' => '2026-03-01']);

    $this->actingAs(makeUser('viewer'));

    Livewire::test(LeaseRentableItemsRelationManager::class, [
        'ownerRecord' => $lease->fresh(),
        'pageClass' => EditLease::class,
    ])
        ->assertOk()
        // Still READ it — a viewer should see what the tenant holds…
        ->assertCanSeeTableRecords([$item])
        // …but not change it.
        ->assertTableActionHidden('assignRentableItem');
});
