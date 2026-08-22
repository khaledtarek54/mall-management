<?php

use App\Support\Filament\AnnouncesRecordChange;
use App\Support\Filament\AnnouncingAttachAction;
use Filament\Actions\AttachAction;
use Illuminate\Support\Facades\File;

/**
 * **Every relation-manager action type this app USES must see Filament's read-only deny.**
 *
 * `RelationManager::getDefaultActionAuthorizationResponse()` answers
 * `$this->isReadOnly() ? Response::deny() : …` for sixteen action types — Associate, Attach, Detach,
 * Dissociate, Import, Replicate, Create, Edit, Delete, ForceDelete, Restore and five bulk variants.
 * `CanBeAuthorized::isAuthorized()` consults that default **only when nothing was declared**, so a
 * call-site `->authorize()` REPLACES it. `App\Support\Filament\AnnouncesRecordChange` re-supplies it
 * — but only for the classes bound to an `Announcing*` subclass, and `Action::make()` resolves
 * `app(static::class)`, so an unbound type never sees the binding at all.
 *
 * Five were bound and eleven were not, which nobody noticed because the eleven are rarer. Three are
 * in use here (Attach, Detach, DeleteBulk), and Attach/Detach grant and revoke ACCESS to a property
 * or a department. The bug is latent rather than live only because no `ViewRecord` page currently
 * renders a relation manager that uses them — and adding one is an ordinary UX change (`ViewTenant`
 * is exactly that change, already made once).
 *
 * So this gate keeps no list. It reads the vendor method, extracts the action types the deny really
 * covers, narrows them to the ones this codebase uses **with a call-site `->authorize()`** — which
 * is precisely when the deny gets replaced rather than narrowed — and requires each of those to
 * resolve to a class that composes the seam. A Filament upgrade that adds a seventeenth type, or an
 * `->authorize()` added to a bulk action that has none today, turns it red on that commit instead of
 * quietly reopening the same hole.
 */
it('binds every read-only-sensitive action type this codebase actually uses', function () {
    $vendor = base_path('vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php');

    expect(File::exists($vendor))->toBeTrue();

    $source = File::get($vendor);

    // The body of `getDefaultActionAuthorizationResponse()` only — not the whole file, or
    // `getDefaultActionSchemaResolver()` below it would contribute types the deny never touches.
    $start = strpos($source, 'public function getDefaultActionAuthorizationResponse');

    expect($start)->not->toBeFalse('Filament renamed the method this gate reads.');

    $body = substr($source, $start, strpos($source, 'public function getDefaultActionIndividualRecord', $start) - $start);

    preg_match_all('/\$action instanceof (\w+Action)/', $body, $matches);

    $denyCovered = array_values(array_unique($matches[1]));

    // Prove the premise before reporting on it: a regex that matched nothing would make every
    // assertion below vacuous, which is the exact failure this project has been bitten by.
    expect(count($denyCovered))->toBeGreaterThan(10)
        ->and($denyCovered)->toContain('CreateAction', 'AttachAction', 'DetachAction');

    // What this codebase calls inside a relation manager AND declares `->authorize()` on — which is
    // precisely when the hole opens. An action that declares nothing still receives the default
    // through vendor's own `isAuthorized()`, so it needs no binding; the defect is specifically that
    // declaring authorization REPLACES the deny. Requiring a binding for every used type would flag
    // `AssetUnitsRelationManager`'s bare `DeleteBulkAction::make()`, which is not a hole — and a
    // gate that reports things that are not defects is one people learn to override.
    //
    // Evaluated from disk on every run, so adding `->authorize()` to that bulk action later turns
    // this red on the commit that opens the hole rather than years afterwards.
    $used = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $code = $file->getContents();

        if (! str_contains($code, 'extends RelationManager')) {
            continue;
        }

        // Comments discuss these actions at length; strip them or a docblock naming a type counts
        // as a call site.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code) ?? $code;

        foreach ($denyCovered as $type) {
            foreach (rmActionChains($code, $type) as $chain) {
                if (str_contains($chain, '->authorize(')) {
                    $used[$type] = true;
                }
            }
        }
    }

    $used = array_keys($used);

    expect($used)->not->toBeEmpty('Found no authorized relation-manager actions at all — the sweep is broken.');

    // A type is covered when `Action::make()` resolves to something carrying the seam. Asked of the
    // CONTAINER, not of a list of file names: the binding is what actually decides, and a class that
    // exists but was never bound protects nothing.
    $uncovered = [];

    foreach ($used as $type) {
        $class = 'Filament\\Actions\\'.$type;

        if (! class_exists($class)) {
            continue;
        }

        $resolved = app($class);

        $composes = in_array(
            AnnouncesRecordChange::class,
            class_uses_recursive($resolved),
            true,
        );

        if (! $composes) {
            $uncovered[] = $type;
        }
    }

    expect($uncovered)->toBe([], implode("\n", [
        'These relation-manager action types are covered by Filament\'s read-only-on-a-View-page deny,',
        'are used in this codebase, and resolve to a class that does NOT re-supply it — so a call-site',
        '->authorize() on them replaces the deny instead of narrowing it:',
        '  '.implode(', ', $uncovered),
        'Add an App\\Support\\Filament\\Announcing{Type}Action and bind it in AppServiceProvider.',
    ]));
});

it('actually refuses an attach on a read-only relation manager', function () {
    // The gate above proves the WIRING; this proves the BEHAVIOUR, because a binding that resolved
    // to a class whose `isAuthorized()` had been emptied would satisfy the wiring check exactly.
    $action = app(AttachAction::class);

    expect($action)->toBeInstanceOf(AnnouncingAttachAction::class);

    // With no Livewire owner there is no default to consult, and the seam must ALLOW rather than
    // refuse — a guard that answers "no" when it cannot know would break every action outside a
    // relation manager. `AnnouncesRecordChange::defaultAuthorizationAllows()` returns true there.
    expect($action->isAuthorized())->toBeTrue();
});
