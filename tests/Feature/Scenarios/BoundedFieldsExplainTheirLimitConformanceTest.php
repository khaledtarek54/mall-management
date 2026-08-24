<?php

use App\Support\FieldHelp;

/**
 * If the form will REFUSE a number, the screen has to say so before the operator types it.
 *
 * ## The gap this closes
 *
 * `FieldHelpConformanceTest` polices the guidance that EXISTS — its length, whether a hint icon
 * carries a tooltip, whether an exemption is stale. Nothing asked about the guidance that is
 * MISSING, which is the same shape as every "a gate can report on a set it has silently stopped
 * collecting" note in CLAUDE.md, pointing the other way.
 *
 * "Every required field needs help" would be the wrong bar and would produce a few hundred filler
 * sentences — exactly what {@see FieldHelp::WORD_BUDGET} exists to prevent. A field called Name
 * explains itself. The bar that is right is the one `FieldHelp`'s own docblock names: **a
 * first-time operator cannot see the rule that would have stopped them.** A `minValue()` or
 * `maxValue()` is precisely such a rule — the form knows it, enforces it, and until the operator
 * trips it, says nothing.
 *
 * So: a field carrying a bound must carry help, unless its bound explains itself and is registered
 * in {@see FieldHelp::SELF_EVIDENT_BOUNDS} with the reason (a percentage capped at 100 is
 * arithmetic; a lease term capped at 120 months is a policy nobody can guess).
 *
 * ## Why this is a STATIC sweep
 *
 * The first version of this measurement built all sixty-six create forms and asked each field for
 * its helper text. `Field::getHelperText()` **throws for every field outside a mounted Livewire
 * container** — all 673 of them — so the run reported "11% of fields carry help" while actually
 * measuring nothing at all, and would have gone on reporting it. Reading the source cannot lie in
 * that direction: a `->helperText(` in a field's chain is there whether or not anything can
 * evaluate it.
 */

/** Every `X::make('field')` chain in a Filament schema file, as (file, field, chain source). */
function fieldChains(): array
{
    $chains = [];

    foreach (filamentSources() as $file) {
        if (! str_contains($file, '/Schemas/')) {
            continue;
        }

        $body = (string) file_get_contents($file);

        // Split on the start of each `Something::make('name')`. Everything up to the NEXT one is
        // that field's chain — crude, and exactly right for the question being asked, which is
        // whether two calls appear between the same pair of boundaries.
        $parts = preg_split(
            "/(?=[A-Za-z]+::make\(\s*'[a-z0-9_.]+'\s*\))/",
            $body,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        foreach ($parts as $part) {
            if (! preg_match("/^[A-Za-z]+::make\(\s*'([a-z0-9_.]+)'\s*\)/", $part, $m)) {
                continue;
            }

            $chains[] = ['file' => $file, 'field' => $m[1], 'source' => $part];
        }
    }

    return $chains;
}

it('states every bound the operator cannot infer', function () {
    $chains = fieldChains();

    // The premise, before anything is reported on it. A regex that stopped matching would sweep
    // zero chains and pass while checking nothing.
    expect(count($chains))->toBeGreaterThan(400);

    $bounded = 0;
    $silent = [];

    foreach ($chains as $chain) {
        $hasBound = preg_match('/->(minValue|maxValue)\(/', $chain['source']) === 1;

        if (! $hasBound) {
            continue;
        }

        // "Not negative" is not a rule anyone needs told. Only a ceiling, or a floor past zero.
        $surprising = preg_match('/->maxValue\(/', $chain['source']) === 1
            || preg_match('/->minValue\(\s*(?!0\s*\)|0\.01\s*\)|0\.0+\s*\))/', $chain['source']) === 1;

        if (! $surprising) {
            continue;
        }

        $bounded++;

        $explains = preg_match('/->(helperText|hintIcon|hint)\(/', $chain['source']) === 1;
        $resource = basename(dirname(dirname($chain['file'])));

        if ($explains || FieldHelp::boundIsSelfEvident($resource, $chain['field'])) {
            continue;
        }

        $silent[] = $resource.'.'.$chain['field'].'  ('.str_replace(base_path().'/', '', $chain['file']).')';
    }

    expect($bounded)->toBeGreaterThan(10);

    expect($silent)->toBe([], "These fields carry a limit the form enforces and the screen never states.\n"
        ."Add a `->helperText()` saying what the limit is, or register the field in\n"
        ."FieldHelp::SELF_EVIDENT_BOUNDS with the reason its bound explains itself:\n  "
        .implode("\n  ", $silent));
});

it('carries no self-evident exemption for a field that has since gained help', function () {
    // The stale direction. Somebody writes the helper text and leaves the exemption behind; the
    // registry then claims a bound is self-evident when the screen already explains it, and the
    // next reader takes that as a ruling rather than as a leftover.
    $explained = [];

    foreach (fieldChains() as $chain) {
        if (preg_match('/->(helperText|hintIcon|hint)\(/', $chain['source']) !== 1) {
            continue;
        }

        $resource = basename(dirname(dirname($chain['file'])));

        if (FieldHelp::boundIsSelfEvident($resource, $chain['field'])) {
            $explained[] = $resource.'.'.$chain['field'];
        }
    }

    expect($explained)->toBe([], "Registered as self-evident, but the field now carries help anyway —\n"
        ."remove the exemption or the explanation, not both:\n  ".implode("\n  ", $explained));
});

it('gives every exemption a reason worth reading', function () {
    expect(FieldHelp::SELF_EVIDENT_BOUNDS)->not->toBeEmpty();

    foreach (FieldHelp::SELF_EVIDENT_BOUNDS as $field => $reason) {
        expect($field)->toContain('.');
        expect(strlen($reason))->toBeGreaterThan(30,
            "The exemption for {$field} does not say why. \"Obvious\" is not a reviewable reason.");
    }
});
