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
 * Every chain attached to `Action::make(...)`, read by counting parentheses.
 *
 * A regex cannot do this: a chained `->using(function () { ... })` contains its own parens, quotes
 * and semicolons, so any bounded pattern either stops inside the closure or runs past the end of the
 * array element. The first version used `\([^;]*?\)` and swept over three actions entirely — a
 * mutation proved an ungated `CreateAction` passed green behind one. Scanning forward and tracking
 * depth is the only honest reading.
 *
 * @return array<int, string>
 */
function rmActionChains(string $code, string $action): array
{
    $chains = [];
    $needle = $action.'::make(';
    $offset = 0;
    $len = strlen($code);

    while (($start = strpos($code, $needle, $offset)) !== false) {
        $i = $start + strlen($needle);
        $depth = 1;

        while ($i < $len && $depth > 0) {
            $c = $code[$i];

            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;

                if ($depth === 0) {
                    // Chained call? Skip whitespace and look for `->`.
                    $j = $i + 1;
                    while ($j < $len && ctype_space($code[$j])) {
                        $j++;
                    }

                    if (substr($code, $j, 2) === '->') {
                        $depth = 1;
                        $i = $j + 2;

                        while ($i < $len && $code[$i] !== '(') {
                            $i++;
                        }
                    }
                }
            }

            $i++;
        }

        $chains[] = substr($code, $start, $i - $start);
        $offset = $i;
    }

    return $chains;
}

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
            // Everything chained onto the action, read by BALANCING PARENTHESES rather than by a
            // regex. The first version used `\([^;]*?\)` per call, which cannot see past a closure
            // body — three actions with a `->using(function () { … })` were swept over entirely, and
            // a mutation proved an ungated `CreateAction` passed green behind one.
            foreach (rmActionChains($code, $action) as $chain) {
                $checked++;

                // `->authorize()`, not merely `->visible()`.
                //
                // NOT because a hidden action is dispatchable — on the Filament we ship it is not
                // (`CanBeDisabled::isDisabled()` returns true when `isHidden()` does, and both
                // `mountAction()` and `callMountedAction()` refuse a disabled action). CLAUDE.md
                // records that correction, and `FilamentActionDispatchContractTest` pins it.
                //
                // The reason is that hidden-implies-disabled is an UPSTREAM implementation detail.
                // A `visible()`-only action states a UI preference and inherits its refusal from a
                // line in a vendor trait; a Filament release that separates the two would reopen
                // every one of them at once, silently. `->authorize()` states the intent here.
                //
                // The cost of stating it is finding 3 of this feature's review, and it is why the
                // seam exists: `->authorize()` writes a SINGLE slot and REPLACES whatever was
                // there, including Filament's own default response — which is what denies a write
                // action on a read-only View page. `AnnouncesRecordChange::defaultAuthorizationAllows()`
                // re-supplies it, so declaring authorization here adds a layer instead of swapping one.
                if (str_contains($chain, '->authorize(')) {
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
        'These relation-manager CRUD actions have no `->authorize()`. A relation manager has no',
        'resource for the authorization seam to ask, so the call site IS the gate — and `visible()`',
        'alone is not one: it hides the button while the hard check on the call path still says yes.',
        '  '.implode("\n  ", array_unique($offenders)),
    ]));
});
