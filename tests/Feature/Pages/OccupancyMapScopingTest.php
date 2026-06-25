<?php

use App\Filament\Admin\Pages\OccupancyMap;
use App\Models\Asset;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->hw = makeAsset(['code' => 'HW']);
    $this->pa = makeAsset(['code' => 'PA']);
});

function occupancyViewData(OccupancyMap $page): array
{
    $ref = new ReflectionMethod($page, 'getViewData');
    $ref->setAccessible(true);

    return $ref->invoke($page);
}

it('restricts the property list + selection to the staff member\'s assigned assets', function () {
    // Maintenance manager assigned to Haya Walk only.
    $user = makeUser('operations', [$this->hw->id]);
    $this->actingAs($user);

    $page = new OccupancyMap;
    $page->assetId = $this->pa->id; // attempt to view an unassigned property

    $data = occupancyViewData($page);

    expect($data['assets']->pluck('id')->all())->toEqual([$this->hw->id]);
    // The unassigned PA selection is ignored and falls back to HW.
    expect($data['asset']->id)->toBe($this->hw->id);
});

it('lets a super_admin see every property in the map', function () {
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    $page = new OccupancyMap;
    $data = occupancyViewData($page);

    expect($data['assets']->pluck('id')->all())
        ->toContain($this->hw->id)
        ->toContain($this->pa->id);
});
