<?php

use App\Models\Floor;
use App\Models\Unit;

/**
 * GAP ANALYSIS, module 34 — renaming a floor stranded every unit blob on it.
 *
 * `Unit::searchTextSources()` quotes the floor's CODE, so "ground A-1" narrows the way an operator
 * says it. That is the one relation hop the search policy allows, and it is documented as such —
 * with the remedy stated in the docblock: run `atriom:rebuild-search` after renaming a floor.
 *
 * **Nobody was ever going to run it.** The floor code is editable through `EditAction` on the
 * property's floors relation manager — an ordinary operator act, on a screen with no mention of
 * search — and `Floor` had no hook. So renaming a floor silently froze the OLD code into every unit
 * blob standing on it: searching the new name found nothing, searching the retired one still worked,
 * and there is no error anywhere to connect the two.
 *
 * That is the same shape as the rest of round 3 — a rule the code knew and nothing enforced. A
 * documented manual remedy for a UI-triggerable action is a remedy that does not exist.
 *
 * The blob stays a pure function of the row's own attributes everywhere else; this closes the one
 * exception by making the OWNER of the borrowed value push the change down, rather than expecting
 * the borrower to notice.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'FL']);

    $this->floor = Floor::create([
        'asset_id' => $this->asset->id,
        'code' => 'G',
        'name' => 'Ground',
        'level' => 0,
    ]);

    $this->unit = makeUnit($this->asset, ['code' => 'A-1', 'floor_id' => $this->floor->id]);
});

it('folds the floor code into the unit blob to begin with — the control', function () {
    expect(Unit::query()->search('G A-1')->pluck('id')->all())->toBe([$this->unit->id]);
});

it('finds the unit by the floor\'s NEW code after a rename', function () {
    $this->floor->update(['code' => 'GF']);

    expect(Unit::query()->search('GF A-1')->pluck('id')->all())->toBe([$this->unit->id]);
});

it('stops finding the unit by the floor\'s retired code', function () {
    // Renamed to a code that does not CONTAIN the old one: search matches on substring, so
    // G → GF would still match on "G" and prove nothing about the refold.
    $this->floor->update(['code' => 'M2']);

    // The other half: a stale blob is not just missing the new name, it is still answering to a
    // code the property no longer has.
    expect(Unit::query()->search('G A-1')->count())->toBe(0)
        ->and(Unit::query()->search('M2 A-1')->pluck('id')->all())->toBe([$this->unit->id]);
});

it('leaves units on other floors alone', function () {
    $other = Floor::create([
        'asset_id' => $this->asset->id,
        'code' => 'F1',
        'name' => 'First',
        'level' => 1,
    ]);
    $upstairs = makeUnit($this->asset, ['code' => 'B-2', 'floor_id' => $other->id]);

    $before = $upstairs->fresh()->search_text;
    $this->floor->update(['code' => 'GF']);

    // The refold is scoped to the renamed floor's own units — renaming one floor must not rewrite
    // the whole property's blobs.
    expect($upstairs->fresh()->search_text)->toBe($before);
});

it('does not refold when something other than the code changes', function () {
    $before = $this->unit->fresh()->search_text;

    $this->floor->update(['name' => 'Ground Level']);

    // `name` is not in the unit's blob, so touching it must not cascade. Guards against the fix
    // becoming "rewrite every unit whenever a floor is saved at all".
    expect($this->unit->fresh()->search_text)->toBe($before);
});
