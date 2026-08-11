<?php

use App\Models\Area;
use App\Models\Floor;
use App\Models\Unit;

/**
 * A unit's floor and zone belong to the unit's own property.
 *
 * THE GAP (validation sweep — spacing, 2026-08-11), and the correction that found it. The first
 * pass reported `floor_id` as unguarded because `UnitResource` guards its sibling `area_id` on
 * create AND edit while `floor_id` — the newer relation, from the 2026-08-10 floors register — had
 * nothing. That asymmetry is a real signal, but the conclusion drawn from it was wrong: Filament's
 * own relationship-Select validation already refuses an out-of-scope pick **from the form**, so a
 * page-level guard changes nothing there. Tests driven through the Create/Edit pages passed with
 * the new guard deleted — proving the guard, not the rule.
 *
 * The hole is one layer down and was found by writing the raw call:
 *
 *     Unit::create(['asset_id' => A, 'floor_id' => <a floor of B>, ...]);   // went straight through
 *
 * Any import, console command, factory or future screen is that path — which is the whole thesis of
 * the sweep, arriving in the one area that looked like it had the least to find.
 *
 * A unit on another mall's floor puts the shop in the wrong building on the stacking plan and merges
 * two properties wherever anything groups by floor. A unit tagged with another mall's ZONE is worse:
 * area routing fans its tenant requests out to that zone's supervisors, so mall-A request data
 * reaches mall-B staff (module 30 → 11). So the rule is now `Unit::saving` and covers both columns;
 * the page guards stay as the fast 403 and the mirror of intent.
 */
beforeEach(function () {
    $this->a = makeAsset(['code' => 'FLRA']);
    $this->b = makeAsset(['code' => 'FLRB']);

    $this->floorA = Floor::create(['asset_id' => $this->a->id, 'code' => 'G', 'name' => 'Ground', 'level' => 0]);
    $this->floorB = Floor::create(['asset_id' => $this->b->id, 'code' => 'G', 'name' => 'Ground', 'level' => 0]);
});

it('refuses a raw create that puts a unit on another property\'s floor', function () {
    expect(fn () => makeUnit($this->a, ['floor_id' => $this->floorB->id]))
        ->toThrow(DomainException::class);

    expect(Unit::where('floor_id', $this->floorB->id)->exists())->toBeFalse();
});

it('accepts the same create on the unit\'s OWN floor', function () {
    // The control. Without it the refusal above passes just as happily if every create threw.
    expect(makeUnit($this->a, ['floor_id' => $this->floorA->id])->exists)->toBeTrue();
});

it('refuses moving a unit onto another property\'s floor', function () {
    $unit = makeUnit($this->a, ['floor_id' => $this->floorA->id]);

    expect(fn () => $unit->update(['floor_id' => $this->floorB->id]))
        ->toThrow(DomainException::class);

    expect((int) $unit->fresh()->floor_id)->toBe($this->floorA->id);
});

it('refuses RE-HOMING a unit to another property while it keeps the old floor', function () {
    // The same broken state from the other direction: move the unit, leave the floor. Guarding
    // only the floor column would miss it entirely.
    $unit = makeUnit($this->a, ['floor_id' => $this->floorA->id]);

    expect(fn () => $unit->update(['asset_id' => $this->b->id]))
        ->toThrow(DomainException::class);

    // And the legitimate version of that move — re-home AND re-floor together — still works.
    expect(fn () => $unit->fresh()->update([
        'asset_id' => $this->b->id,
        'floor_id' => $this->floorB->id,
    ]))->not->toThrow(DomainException::class);
});

it('refuses a zone from another property, which routes requests to the wrong staff', function () {
    $zoneB = Area::create([
        'asset_id' => $this->b->id, 'code' => 'Z1', 'name' => 'Zone 1', 'is_active' => true,
    ]);

    expect(fn () => makeUnit($this->a, ['area_id' => $zoneB->id]))
        ->toThrow(DomainException::class);
});

it('accepts a zone from the unit\'s own property', function () {
    $zoneA = Area::create([
        'asset_id' => $this->a->id, 'code' => 'Z1', 'name' => 'Zone 1', 'is_active' => true,
    ]);

    expect(makeUnit($this->a, ['area_id' => $zoneA->id])->exists)->toBeTrue();
});

it('leaves a unit with no floor and no zone alone', function () {
    // Both columns are nullable — a mall that has not set up its floor register yet must still be
    // able to record units.
    $unit = makeUnit($this->a);

    expect(fn () => $unit->update(['description' => 'no floor, no zone']))
        ->not->toThrow(DomainException::class);
});
