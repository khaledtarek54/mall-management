<?php

/**
 * Property scoping on the occupancy map.
 *
 * The map used to be a hand-built Blade grid and these assertions read its
 * `getViewData()`. It is now a native Filament table, so they assert the same
 * security property through the surface that actually renders: the resolved
 * property, the picker's options, and — most importantly — the UNITS the table
 * returns. That last one is the real guarantee; a page could resolve the right
 * property and still query the wrong one.
 */

use App\Filament\Admin\Pages\OccupancyMap;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);

    $this->hwUnit = makeUnit($this->hw, ['code' => 'HW-101', 'floor' => '1']);
    $this->paUnit = makeUnit($this->pa, ['code' => 'PA-101', 'floor' => '1']);
});

/**
 * The rows a table actually returned. The occupancy map paginates, so
 * getTableRecords() hands back a LengthAwarePaginator — and collect()ing a
 * paginator wraps the paginator OBJECT, not its items, which silently yields
 * junk rather than failing.
 */
function occupancyRows(OccupancyMap $page): Collection
{
    $records = $page->getTableRecords();

    return method_exists($records, 'getCollection') ? $records->getCollection() : collect($records);
}

/** The property options the picker offers. */
function occupancyAssetOptions(OccupancyMap $page): array
{
    $ref = new ReflectionMethod($page, 'visibleAssets');
    $ref->setAccessible(true);

    return $ref->invoke($page)->pluck('id')->all();
}

it('restricts the property list + selection to the staff member\'s assigned assets', function () {
    // Operations manager assigned to Haya Walk only.
    $this->actingAs(makeUser('operations', [$this->hw->id]));

    $page = new OccupancyMap;
    $page->assetId = $this->pa->id; // attempt to view an unassigned property

    expect(occupancyAssetOptions($page))->toEqual([$this->hw->id]);

    // The unassigned PA selection is ignored and falls back to HW.
    expect($page->resolvedAssetId())->toBe($this->hw->id);
});

it('never returns another property\'s units, even with a tampered selection', function () {
    $this->actingAs(makeUser('operations', [$this->hw->id]));

    // Setting assetId to an unassigned property is the attack: the clamp has to
    // hold at the QUERY, not just in a label.
    $component = Livewire::test(OccupancyMap::class)->set('assetId', $this->pa->id);

    $units = occupancyRows($component->instance());

    expect($units->pluck('id')->all())->toEqual([$this->hwUnit->id])
        ->and($units->pluck('id')->all())->not->toContain($this->paUnit->id);
});

it('returns no units at all when no property resolves', function () {
    // When nothing resolves, the map must come back EMPTY rather than querying
    // units unscoped — an unscoped fallback here would render every unit in the
    // portfolio. Both properties are removed so the visible set is genuinely
    // empty for a super_admin, isolating the page's own guard rather than
    // AssignedAssets' scoping rules.
    $this->actingAs(makeUser('super_admin'));
    trashBypassingDeletionPolicy($this->hw);
    trashBypassingDeletionPolicy($this->pa);

    $component = Livewire::test(OccupancyMap::class);

    expect($component->instance()->resolvedAssetId())->toBeNull();

    expect(occupancyRows($component->instance()))->toBeEmpty()
        // …and there ARE units that a missing guard would have leaked, so this
        // is not passing merely because the table is empty.
        ->and(Unit::count())->toBeGreaterThan(0);
});

it('links each unit card to that unit, in its own property', function () {
    $this->actingAs(makeUser('super_admin'));

    $component = Livewire::test(OccupancyMap::class)->set('assetId', $this->hw->id);
    $url = $component->instance()->getTable()->getRecordUrl($this->hwUnit);

    // Tenant segment comes from the UNIT's own property, not ambient context —
    // the map is reachable from outside a single property's chrome.
    expect($url)->toContain((string) $this->hw->code)
        ->and($url)->toContain((string) $this->hwUnit->id);
});

it('lets a super_admin see every property in the map', function () {
    $this->actingAs(makeUser('super_admin'));

    expect(occupancyAssetOptions(new OccupancyMap))
        ->toContain($this->hw->id)
        ->toContain($this->pa->id);
});
