<?php

/**
 * The parking & space map — the floor plan for everything let that is not a shop.
 *
 * Three properties are asserted here, and they are the three a map can get wrong:
 *
 *  1. **It is gated.** The map names the HOLDER on every let tile, which is the commercial data
 *     `OccupancyMap` was left open on until 2026-08-26 (see
 *     AnExternalVendorCannotReadTheOccupancyMapTest). A new map is a new door to the same class of
 *     data, so it is locked from the first commit rather than after somebody notices.
 *  2. **It is scoped, at the QUERY.** A page can resolve the right property and still query the
 *     wrong one, so the assertions read the ROWS the table returns, not the resolved id.
 *  3. **It agrees with itself.** The colour comes from `status` and the holder name from
 *     `currentHolderLabel()`; if those two ever disagree the card says a bay is free and names the
 *     tenant in it.
 */

use App\Filament\Admin\Pages\RentableItemMap;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->mine = makeAsset(['code' => 'MM']);
    $this->theirs = makeAsset(['code' => 'TT']);

    $this->bay = RentableItem::create([
        'asset_id' => $this->mine->id, 'code' => 'P-01',
        'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 500,
        'status' => RentableItem::STATUS_AVAILABLE,
    ]);

    $this->theirBay = RentableItem::create([
        'asset_id' => $this->theirs->id, 'code' => 'X-99',
        'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 500,
        'status' => RentableItem::STATUS_AVAILABLE,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The rows the map actually returned — it does not paginate, so this is the whole grid. */
function mapRows($component): Collection
{
    $records = $component->instance()->getTableRecords();

    return method_exists($records, 'getCollection') ? $records->getCollection() : collect($records);
}

it('refuses a role that may read neither the register nor the reports', function (string $role) {
    $this->flushSession();
    $this->actingAs(makeUser($role, [$this->mine->id]));

    expect(auth()->user()->canAny(['rentable_items.view', 'reports.view']))
        ->toBeFalse("The {$role} role holds a right that would make this vacuous.");

    asTenant($this->mine, fn () => expect(RentableItemMap::canAccess())->toBeFalse());

    $this->get(RentableItemMap::getUrl(tenant: $this->mine))->assertForbidden();
})->with(['vendor', 'technician', 'coordinator', 'customer_service', 'marketing', 'hr']);

it('opens for the roles whose job it is', function (string $role) {
    // The control. `leasing` maintains the register, `operations` works the estate, and the
    // portfolio roles read everything — a gate that refused everybody would satisfy the refusals
    // above while making the page useless.
    $this->flushSession();
    $this->actingAs(makeUser($role, [$this->mine->id]));

    asTenant($this->mine, fn () => expect(RentableItemMap::canAccess())->toBeTrue());

    $this->get(RentableItemMap::getUrl(tenant: $this->mine))->assertOk();
})->with(['super_admin', 'manager', 'mall_admin', 'leasing', 'viewer']);

it('never returns another property\'s items, even with a tampered selection', function () {
    $this->flushSession();
    $this->actingAs(makeUser('leasing', [$this->mine->id]));

    // Setting assetId to an unassigned property is the attack: the clamp has to hold at the QUERY,
    // not just in a label.
    $component = Livewire::test(RentableItemMap::class)->set('assetId', $this->theirs->id);

    $codes = mapRows($component)->pluck('code')->all();

    expect($codes)->toBe(['P-01'])
        ->and($codes)->not->toContain('X-99');
});

it('returns nothing at all when no property resolves', function () {
    // An unscoped fallback here would render every bay in the portfolio. Both properties are
    // removed so the visible set is genuinely empty even for a super_admin, isolating the page's
    // own guard rather than the assignment rules.
    $this->flushSession();
    $this->actingAs(makeUser('super_admin'));

    trashBypassingDeletionPolicy($this->mine);
    trashBypassingDeletionPolicy($this->theirs);

    $component = Livewire::test(RentableItemMap::class);

    expect($component->instance()->resolvedAssetId())->toBeNull()
        ->and(mapRows($component))->toBeEmpty()
        // …and there ARE items a missing guard would have leaked.
        ->and(RentableItem::count())->toBeGreaterThan(0);
});

it('names the holder on a let bay, and nobody on a free one', function () {
    $lease = makeLease(makeUnit($this->mine), makeTenant(['name' => 'Zara Home']), ['status' => 'active']);

    app(AssignRentableItemService::class)->assign($lease, $this->bay, [
        'monthly_rate' => 500,
        'effective_from' => CarbonImmutable::now()->subMonth()->toDateString(),
    ]);

    $free = RentableItem::create([
        'asset_id' => $this->mine->id, 'code' => 'P-02',
        'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 500,
        'status' => RentableItem::STATUS_AVAILABLE,
    ]);

    expect($this->bay->fresh()->currentHolderLabel())->toBe('Zara Home')
        ->and($free->fresh()->currentHolderLabel())->toBeNull();

    // The card and the colour must agree: a tile that names a tenant is never coloured as free.
    expect($this->bay->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED)
        ->and($free->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE);

    $this->flushSession();
    $this->actingAs(makeUser('leasing', [$this->mine->id]));

    $this->get(RentableItemMap::getUrl(tenant: $this->mine))
        ->assertOk()
        ->assertSee('Zara Home');
});

it('leaves an out-of-service bay out of the utilisation figure rather than calling it free', function () {
    // A bay closed for resurfacing is not lost letting. Counting it as vacant makes a mall look
    // worse the more diligently it maintains its car park.
    $this->bay->update(['status' => RentableItem::STATUS_OUT_OF_SERVICE]);

    RentableItem::create([
        'asset_id' => $this->mine->id, 'code' => 'P-03',
        'type' => RentableItem::TYPE_PARKING, 'monthly_rate' => 500,
        'status' => RentableItem::STATUS_ASSIGNED,
    ]);

    $this->flushSession();
    $this->actingAs(makeUser('leasing', [$this->mine->id]));

    $subheading = asTenant($this->mine, function () {
        $page = Livewire::test(RentableItemMap::class)->instance();

        return $page->getSubheading();
    });

    // One let of one lettable = 100%, with the closed bay reported separately rather than dragging
    // the figure to 50%.
    expect($subheading)->toContain('100%')
        ->and($subheading)->toContain('1/1');
});
