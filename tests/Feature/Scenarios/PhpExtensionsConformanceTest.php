<?php

/*
|--------------------------------------------------------------------------
| The extensions this app needs are declared, and checked in the right SAPI
|--------------------------------------------------------------------------
| `composer.json` carried no `ext-*` entry at all while 260 money columns resolved through
| `Number::currency()`, which throws without `ext-intl`. In practice the dependency tree saved us —
| `filament/support` hard-requires intl, so `composer install --no-dev` already refuses on a box
| without it — and the report that raised this said "a deploy box without intl 500s every money
| column" without saying so.
|
| The part composer structurally cannot see is the SAPI split: composer runs under `php-cli`, the
| money columns render under `php-fpm`. One missing `.ini` symlink and the install succeeds, the
| scheduler runs, the console health check passes, and the panel throws on every list. That is what
| `Health::checkPhpExtensions()` answers, over HTTP, in the SAPI that serves the request.
|
| Both halves are pinned here, each with a control — a registry nobody has seen fail is decoration.
*/

use App\Support\Health;
use App\Support\PhpExtensions;

it('declares in composer.json exactly the extensions the app itself calls', function () {
    $declared = collect(json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR)['require'])
        ->keys()
        ->filter(fn (string $package): bool => str_starts_with($package, 'ext-'))
        ->map(fn (string $package): string => substr($package, 4))
        ->sort()
        ->values()
        ->all();

    // Both directions. A new `ext-` line with no entry here is an undocumented requirement; an
    // entry with no `ext-` line is a claim composer will not enforce on the next deploy.
    expect($declared)->toBe(collect(PhpExtensions::SELF_DECLARED)->sort()->values()->all());
});

it('never claims to own a requirement that belongs to a dependency', function () {
    // SELF_DECLARED is what THIS app calls; REQUIRED is everything the request path needs. The
    // first must be a subset of the second, or composer.json is asserting something the runtime
    // registry does not even consider necessary.
    expect(array_diff(PhpExtensions::SELF_DECLARED, array_keys(PhpExtensions::REQUIRED)))->toBe([]);
});

it('names only extensions that really exist, and says what each one costs', function () {
    foreach (PhpExtensions::REQUIRED as $extension => $breaks) {
        // A registry naming a fictional extension would report a permanent, unfixable failure.
        expect(extension_loaded($extension))->toBeTrue(
            "PhpExtensions names '{$extension}', which is not loaded even on a development machine — "
            .'either the name is wrong or this box cannot run the app.'
        );

        // The sentence is the feature: "missing intl" is a row an operator closes.
        expect(strlen($breaks))->toBeGreaterThan(30, "The '{$extension}' entry does not say what breaks.");
    }
});

it('reports a missing extension as a health failure, and says what it costs', function () {
    // The control first: nothing missing is a passing row.
    expect(Health::phpExtensionState([])['ok'])->toBeTrue();

    // The failure path, driven rather than assumed — this is the state a box with intl in the CLI
    // and not in FPM is actually in, and it cannot be reproduced by unloading an extension.
    $degraded = Health::phpExtensionState(['intl' => PhpExtensions::REQUIRED['intl']]);

    expect($degraded['ok'])->toBeFalse()
        ->and($degraded['detail'])->toContain('intl')
        ->and($degraded['detail'])->toContain('Number::currency()');
});

it('finds nothing missing on a machine that can run the suite', function () {
    // If this ever fails, the box running it is genuinely short an extension — which is the whole
    // point of the check, and better learned here than from a blank invoice.
    expect(PhpExtensions::missing())->toBe([]);

    // The control: the detector is capable of finding something, so the assertion above is evidence
    // rather than a broken sweep.
    expect(PhpExtensions::missing(['json']))->not->toBe([]);
});
