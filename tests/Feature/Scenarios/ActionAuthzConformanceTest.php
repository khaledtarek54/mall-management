<?php

/*
|--------------------------------------------------------------------------
| Every Filament write action is gated, not merely hidden
|--------------------------------------------------------------------------
| The project invariant: a write action must be gated in BOTH `visible()` (the UI) and
| `authorize()`/`abort_unless` (the actual gate). `visible()` alone is a UI decision — a hidden
| action can still be reachable by a crafted Livewire call, and a reader cannot distinguish "safe"
| from "someone forgot" without opening every closure.
|
| Before this gate the invariant was enforced by memory. It shipped broken twice — CAM and Sales
| both had write actions (generateAllocations / markReconciled / lock / dispute / void) gated only
| in visible(), each found module-by-module during its close-out, each fixed in isolation. Nothing
| stopped the next module repeating it, and the per-module authz tests only cover the modules
| someone already thought to check (`FirstEightActionAuthzTest` is, literally, the first eight).
|
| This scans EVERY `Action::make(...)` chain under app/Filament instead. New module, new action —
| it is covered the moment it is written.
|
| Deliberate exemptions (read-only renders, a toast, a gate the scanner cannot follow into a
| method) live in App\Support\ActionAuthz::EXEMPT with a stated reason.
*/

use App\Support\ActionAuthz;

/**
 * Strip comments (and normalise whitespace) using PHP's own tokenizer.
 *
 * Not cosmetic — the first version of this gate parsed raw source and a `// ...` line INSIDE a
 * fluent chain terminated the walk. Chains were silently truncated before their `->action(`, so
 * they were skipped as "not a write" and the gate passed on code it had never really read.
 * Caught by mutation-testing the gate (remove a real `->authorize()`, expect red, got green).
 * The tokenizer also means a `//` or paren inside a STRING can't derail the scan.
 */
function stripPhpComments(string $src): string
{
    // token_get_all() needs an opening tag — without one it returns the whole string as a single
    // T_INLINE_HTML token, comments and all, i.e. a silent no-op. Real files have `<?php`; the
    // self-test's snippet does not, and that discrepancy would leave the self-test exercising a
    // different code path from the scan it is meant to certify.
    $tagged = str_contains(substr($src, 0, 16), '<?php');
    $out = '';

    foreach (token_get_all($tagged ? $src : '<?php '.$src) as $token) {
        if (is_array($token)) {
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $out .= $token;
    }

    return $tagged ? $out : substr($out, strlen('<?php '));
}

/**
 * Extract each `SomeAction::make('name')` fluent chain from PHP source.
 *
 * Walks the fluent chain by paren depth rather than regex-to-end-of-line: an action's `->action()`
 * closure spans many lines and contains its own parens/braces, so a line-based scan either stops
 * early (missing the gate) or runs into the NEXT action (inheriting its gate). Both failure modes
 * make the gate lie.
 *
 * @return array<int, array{name: string, body: string}>
 */
function filamentActionChains(string $src): array
{
    $src = stripPhpComments($src);
    $chains = [];

    if (! preg_match_all('/\b\w*Action::make\(\s*[\'"]([\w.-]+)[\'"]/', $src, $matches, PREG_OFFSET_CAPTURE)) {
        return $chains;
    }

    foreach ($matches[0] as $i => [$match, $start]) {
        $name = $matches[1][$i][0];

        // Consume `::make( ... )`, then each following `->method( ... )`, by paren depth.
        $pos = strpos($src, '(', $start);
        $end = closeParen($src, $pos);

        while ($end !== null && $end < strlen($src)) {
            if (! preg_match('/\A\s*\??->\s*\w+\s*\(/', substr($src, $end + 1, 120), $m)) {
                break;
            }
            $open = $end + 1 + strlen($m[0]) - 1;
            $next = closeParen($src, $open);
            if ($next === null) {
                break;
            }
            $end = $next;
        }

        $chains[] = ['name' => $name, 'body' => substr($src, $start, ($end ?? $start) - $start + 1)];
    }

    return $chains;
}

/** Index of the paren matching the one at $open, or null. */
function closeParen(string $src, int $open): ?int
{
    $depth = 0;
    $len = strlen($src);

    for ($i = $open; $i < $len; $i++) {
        $c = $src[$i];
        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

it('gates every Filament action that performs a write', function () {
    $ungated = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $src = file_get_contents($file->getPathname());
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        foreach (filamentActionChains($src) as $chain) {
            // Only custom actions with their own body. Filament's standard actions
            // (Edit/Delete/Create) authorize through the resource policy.
            if (! str_contains($chain['body'], '->action(')) {
                continue;
            }

            $gated = str_contains($chain['body'], '->authorize(')
                || str_contains($chain['body'], 'abort_unless')
                || str_contains($chain['body'], 'abort_if');

            if ($gated || ActionAuthz::isExempt($relative, $chain['name'])) {
                continue;
            }

            $ungated[] = $relative.' :: '.$chain['name'];
        }
    }

    expect($ungated)->toBe([], implode("\n", array_merge(
        ['These Filament actions perform a write with no authorization gate:'],
        array_map(fn (string $v): string => '  - '.$v, $ungated),
        [
            '',
            'Gate them in BOTH visible() (the UI) and authorize()/abort_unless (the gate) —',
            'visible() alone is a UI decision, not an authorization decision.',
            'If the action genuinely needs no gate, add it to App\Support\ActionAuthz::EXEMPT',
            'with the reason.',
        ],
    )));
});

it('does not carry exemptions for actions that no longer exist', function () {
    // An exemption that outlives its action is a hole waiting to be inherited by the next action
    // that happens to take the same name.
    $present = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (filamentActionChains(file_get_contents($file->getPathname())) as $chain) {
            $present[] = $file->getBasename().'::'.$chain['name'];
        }
    }

    $stale = [];

    foreach (array_keys(ActionAuthz::EXEMPT) as $key) {
        // `.portal` suffix disambiguates two same-named actions in same-named files.
        $lookup = str_ends_with($key, '.portal') ? substr($key, 0, -7) : $key;

        if (! in_array($lookup, $present, true)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'ActionAuthz::EXEMPT lists actions that no longer exist: '.implode(', ', $stale));
});

it('actually detects an ungated write action', function () {
    // A conformance gate that cannot fail is decoration. Prove the parser sees the shape it exists
    // to catch — and, just as importantly, that it is not fooled by a gate belonging to the NEXT
    // action in the file.
    $source = <<<'PHP'
    Action::make('safeOne')
        ->label('Safe')
        ->action(function ($record) {
            $record->update(['x' => 1]);
        })
        ->authorize(fn () => true),
    Action::make('leakyOne')
        ->label('Leaky')
        ->visible(fn () => auth()->user()->can('thing.edit'))
        ->action(function ($record) {
            $record->update(['y' => 2]);
        }),
    Action::make('gatedAfter')
        ->authorize(fn () => true)
        ->action(fn ($r) => $r->delete()),
    Action::make('commented')
        ->visible(fn () => true)
        // A comment in the middle of a chain used to TERMINATE the walk, so everything
        // below here was invisible to the gate — including the ->action() that makes it a
        // write. This case exists because that bug shipped in the first version.
        ->action(function ($record) {
            $record->update(['z' => 3]);
        }),
    PHP;

    $chains = collect(filamentActionChains($source))
        ->filter(fn (array $c): bool => str_contains($c['body'], '->action('))
        ->mapWithKeys(fn (array $c): array => [
            $c['name'] => str_contains($c['body'], '->authorize(')
                || str_contains($c['body'], 'abort_unless'),
        ]);

    expect($chains['safeOne'])->toBeTrue('a gated action must read as gated')
        ->and($chains['leakyOne'])->toBeFalse('a visible()-only write action must read as UNGATED')
        ->and($chains['gatedAfter'])->toBeTrue('the following action\'s gate must not leak backwards')
        ->and($chains->has('commented'))->toBeTrue('a comment mid-chain must not hide the ->action() from the scan')
        ->and($chains['commented'])->toBeFalse('a commented, visible()-only write must still read as UNGATED');
});
