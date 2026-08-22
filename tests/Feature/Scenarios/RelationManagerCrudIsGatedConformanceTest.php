<?php

/*
|--------------------------------------------------------------------------
| A relation manager's CRUD buttons must state their own rule
|--------------------------------------------------------------------------
| `App\Support\Filament\ResourceAbility` answers by asking the SCREEN which resource it is. A
| relation manager has none — verified over all of this app's relation managers, not one defines
| `getResource()` — so for a child row the seam can only enforce what it knows about the MODEL
| (`#[NeverDeletable]`), and the call site's own gate is the whole of the rest.
|
| That is the honest division of labour, and it leaves exactly one hole: a bare
| `CreateAction::make()` / `EditAction::make()` / `DeleteAction::make()` in a relation manager is
| open to anyone who can open the parent record. It is not hypothetical — `PortalUsersRelationManager`
| shipped that way, and a `manager` (or `leasing`, which holds `tenants.edit`) could reset an
| existing portal ADMIN's password to a value they chose and sign in to /portal as that tenant.
|
| A seam cannot invent a permission for a screen that never named one. So the screen has to name it,
| and this fails the build when it does not.
*/

use Illuminate\Support\Facades\File;

/**
 * Relation managers that legitimately need no gate of their own, each with the reason.
 *
 * READ-ONLY managers are not listed — they carry no CRUD action, so the sweep never sees them.
 */
const RM_CRUD_UNGATED = [
    // Nothing yet. An entry here is a claim that a child row's create/edit/delete is safe for
    // anyone who can open its parent, which is a claim worth writing down.
];

it('gives every relation-manager CRUD action a gate of its own', function () {
    $offenders = [];
    $checked = 0;

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $body = $file->getContents();

        if (! str_contains($body, 'extends RelationManager')) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (array_key_exists($relative, RM_CRUD_UNGATED)) {
            continue;
        }

        // Comments explain these actions in several files; strip them before matching.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $body) ?? $body;

        foreach (['CreateAction', 'EditAction', 'DeleteAction', 'AttachAction', 'DetachAction', 'AssociateAction', 'DissociateAction'] as $action) {
            // Every occurrence of the action, with whatever is chained onto it up to the next
            // element of the array literal. A bare `Action::make(),` has nothing between the
            // closing paren and the comma.
            preg_match_all('~'.$action.'::make\((?:[^()]*)\)((?:\s*->\s*\w+\([^;]*?\))*)\s*,~s', $code, $matches);

            foreach ($matches[1] as $chain) {
                $checked++;

                if (str_contains($chain, '->visible(') || str_contains($chain, '->authorize(')
                    || str_contains($chain, '->hidden(') || str_contains($chain, 'abort_unless')) {
                    continue;
                }

                // `->url()` NAVIGATES. It opens no modal and writes nothing — the destination is a
                // resource page, and `CreateRecord`/`EditRecord::authorizeAccess()` abort on mount.
                // A rule rather than a per-file waiver, because it is true of every such action.
                if (str_contains($chain, '->url(')) {
                    continue;
                }

                $offenders[] = "{$relative} — {$action} with no visible()/authorize()";
            }
        }
    }

    // The premise. A regex that stopped matching would report a clean sweep, which is the failure
    // mode CLAUDE.md names three times over.
    expect($checked)->toBeGreaterThan(30, 'The sweep matched almost nothing — the pattern has stopped seeing relation-manager actions.');

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", [
        'These relation-manager CRUD actions are open to anyone who can open the parent record.',
        'A relation manager has no resource for the authorization seam to ask, so its buttons must',
        'state their own rule:',
        '  '.implode("\n  ", array_unique($offenders)),
    ]));
});
