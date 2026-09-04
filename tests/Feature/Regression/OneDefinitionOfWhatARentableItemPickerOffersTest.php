<?php

/*
|--------------------------------------------------------------------------
| One definition of what a rentable-item picker offers (SW-054)
|--------------------------------------------------------------------------
| `App\Support\RentableItemOptions` was created because these two lists — "what could this
| agreement take" and "what does it hold" — existed twice and had drifted. A THIRD copy survived the
| consolidation as dead `private static` methods on `LeasesTable`, called from nowhere, and it still
| carried the pre-2026-08-28 self-holding exemption that the shared version was fixed to remove:
|
|     ->reject(fn (RentableItem $i) => $i->isHeldOn(null, ignore: ['type' => 'lease', 'id' => ...]))
|
| That exemption offered a lease the bays it already held, and `AssignRentableItemService::assign()`
| then refused the pick on submit — a picker whose value the write guard rejects, which is the worst
| kind, because the operator has already decided by the time they are told no.
|
| Dead code cannot misbehave; what it does is wait to be copied. So both halves are pinned here: the
| list is built in exactly ONE file, and the MECHANISM for the exemption is gone from the model
| rather than merely unused. `APickerNeverOffersWhatTheGuardRefusesTest` continues to pin the
| BEHAVIOUR of the surviving definition — this file pins that there is only one of it.
*/

use Illuminate\Support\Facades\File;

/** @return array<int, string> every app/ file that maps RentableItems into picker labels */
$pickerBuilders = function (): array {
    $found = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // The shape both copies had, and the shape a fourth would have: a collection of
        // RentableItems turned into `id => label`. Deliberately narrow — `RentableItem::query()`
        // alone matches the nightly re-projection sweep and the floor-plan map, neither of which
        // is a picker.
        if (preg_match('/mapWithKeys\(\s*fn \(RentableItem /', $source) === 1) {
            $found[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($found);

    return $found;
};

it('builds the rentable-item pickers in exactly one file', function () use ($pickerBuilders) {
    $found = $pickerBuilders();

    // The premise, before anything is reported on it: a regex that stopped matching would sweep
    // zero files and pass while checking nothing — the shape CLAUDE.md records three times.
    expect($found)->toContain('app/Support/RentableItemOptions.php');

    expect($found)->toBe(['app/Support/RentableItemOptions.php'], implode("\n", [
        'A second copy of the rentable-item option list has appeared:',
        '  '.implode("\n  ", $found),
        '',
        'Both lists are holder-agnostic and live in App\\Support\\RentableItemOptions.',
        'Call lettable()/held() rather than rebuilding the query — the copies drifted once already.',
    ]));
});

it('leaves no way to exempt an agreement’s own holdings from the double-let clash test', function () {
    // Half one: the MECHANISM is gone, not merely unused. `isHeldOn()` took an `$ignore` holding
    // until 2026-09-03 and nothing had passed one since the dead copy above was its last caller.
    $model = (string) file_get_contents(app_path('Models/RentableItem.php'));

    expect($model)->toContain('public function isHeldOn(?CarbonImmutable $on = null): bool');

    // Half two: nothing anywhere passes an exemption. Kept as a separate assertion because a future
    // re-introduction could come either as a parameter or as a hand-rolled clause.
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/isHeldOn\([^()]*ignore:/', $source) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These files exempt a holder from its own double-let check:',
        '  '.implode("\n  ", $offenders),
        '',
        'AssignRentableItemService::assign() refuses re-assignment to the current holder, so an',
        'exemption here only produces a picker whose value the write guard rejects (2026-08-28).',
    ]));

    // …and the surviving definition really does ask the unexempted question, so the sweep above is
    // not passing because nobody calls `isHeldOn()` at all any more.
    expect((string) file_get_contents(app_path('Support/RentableItemOptions.php')))
        ->toContain('isHeldOn(null)');
});
