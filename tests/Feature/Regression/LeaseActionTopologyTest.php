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
