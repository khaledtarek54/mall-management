<?php

use App\Support\BadgeColors;
use Tests\Support\BadgeColorMaps;

/*
|--------------------------------------------------------------------------
| A classification value has ONE colour, whatever screen renders it (2026-09-11)
|--------------------------------------------------------------------------
| Measured before the registry existed: 87 inline `match ($state)` colour maps over 41
| vocabularies across the panel, 15 vocabularies coloured in more than one file, and 7 of those
| disagreeing with themselves —
| `leases.status` five ways, a unit's `vacant` red on the register and amber on the property's
| Units tab, a bay's `available` amber on the register and GREEN on the property's Parking tab.
| None of it reported, because every file is right on its own.
|
| `App\Support\BadgeColors` is the one map. This gate keeps it the only one:
|
|   1. every registered set is a real `ValueSets` key and names every value of it, no more;
|   2. no inline map colours a registered set, and no UNREGISTERED set is coloured inline in more
|      than one file — the second site is the moment to register it (the repo's extract-on-the-
|      second-call-site rule, made a gate);
|   3. a registry read names the set the column actually holds — `BadgeColors::of('units.status')`
|      on a lease column would be green on this sweep and wrong on the screen;
|   4. every registry entry is read by something, so a colour nobody renders cannot sit there
|      looking like a decision.
|
| Each counts what it examined. A sweep that stopped collecting would report a clean panel over a
| set it never looked at — the failure recorded against three gates in this project already.
*/

it('names every value of every registered set, and only real sets', function () {
    $sets = BadgeColorMaps::sets();
    $problems = [];

    foreach (BadgeColors::MAP as $set => $colours) {
        if (! isset($sets[$set])) {
            $problems[] = "`{$set}` is not a ValueSets key";

            continue;
        }

        foreach (array_diff($sets[$set], array_keys($colours)) as $missing) {
            $problems[] = "`{$set}` has no colour for `{$missing}` — every value is decided explicitly, so a new status cannot fall to grey on one screen and be coloured on another";
        }

        foreach (array_diff(array_keys($colours), $sets[$set]) as $extra) {
            $problems[] = "`{$set}` colours `{$extra}`, which the column cannot hold";
        }
    }

    expect(BadgeColors::MAP)->not->toBeEmpty();
    expect($problems)->toBe([], implode("\n  ", $problems));
});

it('colours a registered set through the registry everywhere, and registers any set coloured twice', function () {
    $decisions = BadgeColorMaps::scan();
    $inline = array_filter($decisions, fn (array $d) => $d['kind'] === 'inline');
    $registry = array_filter($decisions, fn (array $d) => $d['kind'] === 'registry');

    // Premises. Measured at 37 inline maps left (single-screen vocabularies) and 50 registry
    // reads on the day this was written; either at zero means the tokenizer stopped reading.
    expect(count($inline))->toBeGreaterThan(20, 'The sweep found almost no inline colour maps — it has stopped reading chains.');
    expect(count($registry))->toBeGreaterThan(40, 'The sweep found almost no registry reads — it has stopped reading chains.');

    $offenders = [];
    $byUnregisteredSet = [];

    foreach ($inline as $d) {
        if ($d['set'] !== null && isset(BadgeColors::MAP[$d['set']])) {
            $offenders[] = "{$d['file']}:{$d['line']} colours `{$d['set']}` inline — read BadgeColors::of('{$d['set']}')";

            continue;
        }

        if ($d['set'] !== null) {
            $byUnregisteredSet[$d['set']][] = "{$d['file']}:{$d['line']}";
        }
    }

    foreach ($byUnregisteredSet as $set => $files) {
        if (count(array_unique($files)) > 1) {
            $offenders[] = "`{$set}` is coloured inline in more than one file — register it in BadgeColors::MAP and read it from both:\n      ".implode("\n      ", $files);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

it('reads the set the column actually holds', function () {
    $wrong = [];
    $checked = 0;

    foreach (BadgeColorMaps::scan() as $d) {
        if ($d['kind'] !== 'registry') {
            continue;
        }

        $checked++;
        $column = str_contains($d['column'], '.') ? substr((string) strrchr($d['column'], '.'), 1) : $d['column'];

        if (! str_ends_with((string) $d['set'], '.'.$column)) {
            $wrong[] = "{$d['file']}:{$d['line']} colours `{$d['column']}` with BadgeColors::of('{$d['set']}')";
        }

        if ($d['table'] !== null && ! str_starts_with((string) $d['set'], $d['table'].'.')) {
            $wrong[] = "{$d['file']}:{$d['line']} lists `{$d['table']}` rows and colours them with BadgeColors::of('{$d['set']}')";
        }
    }

    expect($checked)->toBeGreaterThan(40);
    expect($wrong)->toBe([], implode("\n  ", $wrong));
});

it('renders every registered set somewhere', function () {
    $read = [];

    foreach (BadgeColorMaps::scan() as $d) {
        if ($d['kind'] === 'registry') {
            $read[$d['set']] = true;
        }
    }

    // `FacilityVocabulary` reads two entries by `for()` on behalf of the board and the contractor
    // portal, which the chain sweep (a `->color(BadgeColors::of(…))` reader) cannot see.
    $source = (string) file_get_contents(app_path('Support/FacilityVocabulary.php'));

    if (preg_match_all("/BadgeColors::for\('([a-z_.]+)'/", $source, $m)) {
        foreach ($m[1] as $set) {
            $read[$set] = true;
        }
    }

    $stale = array_values(array_diff(array_keys(BadgeColors::MAP), array_keys($read)));

    expect($stale)->toBe([], 'Registered and rendered by nothing: '.implode(', ', $stale));
});

it('falls to neutral on a value the column happens to hold and the map does not', function () {
    // A legacy or imported row must render, not take the list down — completeness is the gate
    // above, never the render.
    expect(BadgeColors::for('invoices.status', 'legacy_value'))->toBe(BadgeColors::FALLBACK)
        ->and(BadgeColors::for('invoices.status', null))->toBe(BadgeColors::FALLBACK)
        ->and(BadgeColors::for('no.such.set', 'paid'))->toBe(BadgeColors::FALLBACK)
        ->and(BadgeColors::for('invoices.status', 'paid'))->toBe('success')
        ->and((BadgeColors::of('leases.status'))('future'))->toBe('primary');
});
