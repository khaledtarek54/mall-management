<?php

use App\Support\SavedViews;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * "Save this view" is offered by a RULE, not by whoever remembered to mount the trait.
 *
 * It shipped on seven lists of sixty-six, chosen by hand, and nothing said whether the other
 * fifty-nine were a decision or an oversight. The stale direction is the one nobody notices: a
 * resource that grows its fourth filter next month does not grow a way to save the combination,
 * and the person adding the filter has no reason to think about it.
 *
 * `App\Support\SavedViews` derives the answer from the number of filters a list actually carries
 * (see the class for why three) and this gate fails when a list is on the wrong side of it. So
 * adding a third filter now tells you to mount the trait, and removing filters down to two tells
 * you the menu no longer earns its place.
 *
 * The filter count is taken by BUILDING each resource's real table, not by reading its source: a
 * filter can be added conditionally, and counting them statically counts ones that are never
 * offered.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('offers saved views on exactly the lists the rule names', function () {
    $missing = [];
    $extra = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (($resource::getPages()['index'] ?? null) === null) {
            continue;
        }

        $should = SavedViews::offeredBy($resource);
        $does = SavedViews::mountedBy($resource);

        if ($should && ! $does) {
            $missing[] = class_basename($resource).' ('.SavedViews::filterCount($resource).' filters)';
        }

        if ((! $should) && $does) {
            $extra[] = class_basename($resource).' ('.SavedViews::filterCount($resource).' filters)';
        }
    }

    expect($missing)->toBe([], 'These lists carry at least '.SavedViews::THRESHOLD
        ." filters and do not offer saved views. Add `use SavesTableViews;` to the List page and\n"
        ."spread `...\$this->savedViewActions()` into getHeaderActions(), or register the resource\n"
        ."in SavedViews::NEVER with a reason:\n  ".implode("\n  ", $missing));

    expect($extra)->toBe([], "These lists offer saved views but no longer carry enough filters to\n"
        ."earn the menu. Remove the trait, or register the resource in SavedViews::ALWAYS with the\n"
        ."reason its value is in the search, sort, tab or column layout instead:\n  ".implode("\n  ", $extra));
});

it('proves its own premise — the sweep found lists on both sides of the rule', function () {
    // A gate that counts must assert it counted something. If `filterCount()` started throwing for
    // every resource (a panel that will not build, a Filament change to `Table::make()`), every
    // count would be zero, every list would read as "should not offer", and the assertions above
    // would pass while checking nothing.
    $offered = 0;
    $notOffered = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (($resource::getPages()['index'] ?? null) === null) {
            continue;
        }

        SavedViews::offeredBy($resource) ? $offered++ : $notOffered++;
    }

    expect($offered)->toBeGreaterThan(20);
    expect($notOffered)->toBeGreaterThan(10);
});

it('carries no exemption for a resource that does not exist, and none without a reason', function () {
    $resources = Filament::getPanel('admin')->getResources();

    foreach ([SavedViews::ALWAYS, SavedViews::NEVER] as $registry) {
        foreach ($registry as $resource => $reason) {
            expect($resources)->toContain($resource);
            expect(strlen($reason))->toBeGreaterThan(30,
                "The exemption for {$resource} does not say why. A one-word reason is not reviewable.");
        }
    }

    // Both empty today, and asserted rather than assumed — the loops above have nothing to iterate
    // in that state, and a test whose body never runs reports green for the wrong reason.
    expect(SavedViews::ALWAYS)->toBeArray();
    expect(SavedViews::NEVER)->toBeArray();
});
