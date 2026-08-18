<?php

/*
|--------------------------------------------------------------------------
| The list finds; the record acts (2026-08-17)
|--------------------------------------------------------------------------
| Nine commercial actions hung off every row of the leases LIST while the lease's own page carried
| one — so an operator who opened a lease had to go back to the list to do anything to it. That is
| backwards from the record-hub information architecture this project took from Yardi
| (docs/benchmarks/yardi/08), and a row of nine equally-weighted verbs reads as noise rather than as
| choices.
|
| Worse, with the definitions living in one surface only the two could never be kept in step: an
| action added in one place silently left the other behind, which is exactly what had happened.
|
| `App\Filament\Admin\Actions\LeaseActions` is now the single definition and both surfaces compose
| from it. These tests hold that shape: the table stays small, the record carries the acts, and
| neither can grow a private copy.
*/

use App\Filament\Admin\Actions\LeaseActions;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Tables\LeasesTable;
use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

it('defines every commercial act exactly once', function () {
    $names = LeaseActions::names();

    expect($names)->toBe(array_unique($names))
        ->and($names)->toContain('renew', 'extendTerm', 'changeRent', 'grantRelief', 'changePremises', 'terminate');
});

it('groups the record page into a handful of dropdowns, not a wall of verbs', function () {
    $grouped = LeaseActions::grouped();

    expect($grouped)->toHaveCount(3)
        ->and($grouped)->each->toBeInstanceOf(ActionGroup::class);

    // Every grouped action is one the registry owns — a group cannot smuggle in a private copy.
    $inGroups = collect($grouped)
        ->flatMap(fn (ActionGroup $g) => collect($g->getActions())->map(fn ($a) => $a->getName()))
        ->all();

    expect($inGroups)->each->toBeIn(LeaseActions::names());
});

it('leaves the leases table with only the actions that OPEN a record', function () {
    // Read the source rather than booting a table: what matters is that no future edit reintroduces
    // a bespoke `Action::make()` on a row, and that is a statement about the file.
    $source = file_get_contents((new ReflectionClass(LeasesTable::class))->getFileName());

    // The row-action block: everything between recordActions([ and the next `])`.
    $start = strpos($source, '->recordActions([');
    $end = strpos($source, '])', $start);
    $rowActions = substr($source, $start, $end - $start);

    expect($rowActions)->not->toContain("Action::make('")
        ->and($rowActions)->toContain('ViewAction::make()')
        ->and($rowActions)->toContain('EditAction::make()');
});

it('puts the acts on the lease page, where the record is', function () {
    $source = file_get_contents((new ReflectionClass(EditLease::class))->getFileName());

    expect($source)->toContain('LeaseActions::grouped()');
});

it('keeps every registry entry a real, named action', function () {
    foreach (LeaseActions::all() as $action) {
        expect($action)->toBeInstanceOf(Action::class)
            ->and($action->getName())->not->toBeEmpty();
    }
});

/*
|--------------------------------------------------------------------------
| Opening the modal (2026-08-17)
|--------------------------------------------------------------------------
| Every test above reads the SHAPE of the registry — names, groups, which file mentions what. None
| of them ever ran an action's closures, and that is where the bug was: `renew` called
| `app(ExerciseLeaseOptionService::class)` with no `use` statement, so the name resolved against
| this file's own namespace and the container was asked for
| `App\Filament\Admin\Actions\ExerciseLeaseOptionService`. Clicking Renew on any lease 500'd while
| this file stayed green, because `fillForm()` only runs when an operator opens the modal.
|
| `mount()` is the seam Filament actually calls on open (Concerns\CanBeMounted::mount), so driving
| it here is driving the operator's path. `UnresolvedClassReferenceConformanceTest` gates the
| general property — that no file names a class it never imported — and this pins the specific one:
| a lease's modals open.
*/
it('opens every action modal on a real lease', function () {
    $lease = Lease::factory()->create();

    foreach (LeaseActions::all() as $action) {
        $action->record($lease);

        // `fillForm()` is stored as the mount callback, so this evaluates it — the exact frame that
        // threw (CanBeMounted.php:34 → LeaseActions.php:87). A null schema is what an action with no
        // form gets, and `$schema?->fill()` handles it, so the closure still runs either way.
        $action->mount(['schema' => null]);

        // The other closure an operator triggers on open, and the other site that named the missing
        // service (LeaseActions.php:70).
        $action->getModalDescription();
        $action->getModalHeading();
    }
})->throwsNoExceptions();

it('renders every lease action somewhere — a group, or nowhere at all', function () {
    // **An action missing from a group is defined and rendered NOWHERE.** It passes every visibility
    // and authorisation check and simply never appears on the page, which is indistinguishable from
    // a feature that was never built. Both deposit actions shipped that way for one commit
    // (2026-08-18): `isVisible()` true on the record, absent from the screen.
    $defined = collect(LeaseActions::names());
    $grouped = collect(LeaseActions::GROUPS)->flatten();

    $ungrouped = $defined->diff($grouped)->values()->all();
    $phantom = $grouped->diff($defined)->values()->all();

    expect($ungrouped)->toBe([], 'Defined but in no group, so rendered nowhere: '.implode(', ', $ungrouped));

    // The reverse: a group naming an action that no longer exists renders nothing and says nothing.
    expect($phantom)->toBe([], 'Grouped but not defined: '.implode(', ', $phantom));

    // Vacuity guard — a rename that emptied both sides would satisfy the two assertions above.
    expect($defined->count())->toBeGreaterThan(8);
});

it('puts each action in exactly ONE group', function () {
    $grouped = collect(LeaseActions::GROUPS)->flatten();

    // Twice on the page is a different bug from missing, and just as confusing to an operator.
    expect($grouped->count())->toBe($grouped->unique()->count());
});

it('defines each lease act ONCE — a tab composes it, never re-declares it', function () {
    // `LeaseRentableItemsRelationManager` carried its own `assign`, with its own form, beside the
    // registry's. The two had already drifted: the copy picked the item with a plain `Select` where
    // the registry uses an `EntitySelect`, so the same act searched one raw column on one surface
    // and the folded blob on the other (2026-08-18). That is precisely what LeaseActions exists to
    // prevent, and the old topology gate could not see it — it compared the list against the page
    // header and never looked at a tab.
    $services = [
        'AssignRentableItemService', 'LeaseRentChangeService', 'LeaseReliefService',
        'LeaseSpaceChangeService', 'LeaseTerminationService', 'LeaseRenewalService',
        'LeaseExtensionService', 'ConvertLeaseToHoldoverService', 'SettleMoveOutService',
        'BillSecurityDepositService',
    ];

    $offenders = [];

    foreach (glob(app_path('Filament/Admin/RelationManagers/*.php')) as $file) {
        $body = (string) file_get_contents($file);

        foreach ($services as $service) {
            // Calling a lease service from a tab means that tab is DOING the act rather than
            // composing it. `LeaseActions::forOwner()` is how a tab carries one.
            if (str_contains($body, $service.'::class') && ! str_contains($body, 'LeaseActions::forOwner')) {
                $offenders[] = basename($file).' → '.$service;
            }
        }
    }

    expect($offenders)->toBe([], "These re-implement a lease act instead of composing it:\n  "
        .implode("\n  ", $offenders));
});
