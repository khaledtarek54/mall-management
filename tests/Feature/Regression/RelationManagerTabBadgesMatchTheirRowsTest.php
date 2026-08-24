<?php

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\Asset;
use App\Models\Lease;
use App\Models\Tenant;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * A tab badge that disagrees with its tab is worse than no badge.
 *
 * A lease record carries thirteen relation-manager tabs, a tenant nine, a property six, and until
 * 2026-08-24 not one of the forty-nine relation managers in this panel said anything about its
 * contents — so finding out whether a lease had any options, clauses or percentage-rent tiers meant
 * clicking each tab, and clicking a tab that turns out to be empty is the most repeated wasted
 * action on a record page.
 *
 * `CountsItsRows` counts the plain relationship the manager declares. That is right for a manager
 * whose table IS that relationship and WRONG for one that narrows its own query — a tab showing two
 * rows under a badge reading five leaves the operator with a number they cannot reconcile and no
 * way to tell which half is lying. The trait's docblock says so; this test is what makes it true.
 *
 * It compares, for a real seeded record, each badged manager's badge against the number of rows
 * that manager's OWN table returns. Reading the source would only tell us which managers call
 * `modifyQueryUsing`; running them tells us whether the number is right.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset, isQuiet: true);
});

/** Every relation manager in the panel that opted into a row-count badge. */
function badgedRelationManagers(): array
{
    $managers = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getRelations() as $manager) {
            if (! is_string($manager)) {
                continue; // a RelationGroup, not a manager
            }

            if (in_array(CountsItsRows::class, class_uses_recursive($manager), true)) {
                $managers[$manager] = $resource;
            }
        }
    }

    return $managers;
}

it('badges a relation manager only where the count matches what the tab shows', function () {
    $managers = badgedRelationManagers();

    // The premise, asserted before anything is reported on it. If `getRelations()` started
    // returning nothing (an ungated panel, a missing tenant) this test would sweep zero managers
    // and pass while checking nothing — the failure shape this codebase has hit three times.
    expect(count($managers))->toBeGreaterThan(10);

    $mismatched = [];

    foreach ($managers as $manager => $resource) {
        $owner = ownerRecordFor($resource);

        if ($owner === null) {
            continue;
        }

        $badge = $manager::getBadge($owner, $resource::getPages()['edit']?->getPage() ?? '');
        $relationship = $manager::getRelationshipName();

        if (! method_exists($owner, $relationship)) {
            $mismatched[] = class_basename($manager).' declares relationship `'.$relationship
                .'` which does not exist on '.class_basename($owner);

            continue;
        }

        $rows = $owner->{$relationship}()->count();
        $expected = $rows > 0 ? (string) $rows : null;

        if ($badge !== $expected) {
            $mismatched[] = class_basename($manager)." badge={$badge} rows={$rows}";
        }
    }

    expect($mismatched)->toBe([], "A badge must equal the rows its tab shows. Override `badgeCount()`\n"
        ."on the manager to apply the same narrowing its table does, or take the trait off:\n  "
        .implode("\n  ", $mismatched));
});

it('shows nothing rather than a zero', function () {
    $managers = badgedRelationManagers();
    $manager = array_key_first($managers);
    $owner = ownerRecordFor($managers[$manager]);

    expect($owner)->not->toBeNull();

    // An empty relation renders NO badge. A row of thirteen grey zeroes carries the same
    // information as no badges while costing far more attention — the operator wants to see the
    // three tabs that have something in them.
    expect($manager::getBadge($owner, ''))->toBeNull();
});

it('defers every badge, so thirteen counts never delay a record page', function () {
    foreach (badgedRelationManagers() as $manager => $resource) {
        $owner = ownerRecordFor($resource);

        if ($owner === null) {
            continue;
        }

        // Filament passes a deferred badge as a CLOSURE and resolves it after the page renders.
        // Without this the thirteen counts would all block first paint, which is a worse screen
        // than no counts at all — the same lesson the fifty redundant navigation-badge COUNTs
        // taught: a count is cheap exactly once, and never on the critical path.
        expect($manager::isBadgeDeferred($owner, ''))->toBeTrue(
            class_basename($manager).' resolves its badge during the page render.');
    }
});

/** A saved, empty instance of a resource's model — enough to ask a relationship for its count. */
function ownerRecordFor(string $resource): ?Model
{
    $model = $resource::getModel();

    return match ($model) {
        Lease::class => makeLease(makeUnit(test()->asset)),
        Tenant::class => makeTenant(),
        Asset::class => test()->asset,
        default => null,
    };
}
