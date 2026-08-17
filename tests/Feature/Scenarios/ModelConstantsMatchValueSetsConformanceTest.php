<?php

use App\Support\ValueSets;
use Illuminate\Support\Facades\File;

/**
 * A model constant that restates a `ValueSets` entry must agree with it.
 *
 * `ValueSets` is the single source of truth for what a string column accepts — it replaced 62
 * DB-level enums precisely so widening a set is a one-line change. But several models also carry a
 * hand-written `TYPES` / `STATUSES` constant listing the same values, read by forms, filters and
 * factories. **A second copy that agrees on the day it is written is the only kind anyone writes.**
 *
 * That is not hypothetical. Adding `hours` to `utility_meters.type` on 2026-08-17 left
 * `UtilityMeter::TYPES` saying three where the column accepted four, and **nothing failed**: the
 * meter form reads the translation group, so the new type appeared there, while every reader of the
 * constant — two screens and two factories — kept getting the old answer. No gate covered it,
 * because every gate was pointed at the registry rather than at its copies.
 *
 * The fix for that model was to derive (`UtilityMeter::types()`), which is what this gate pushes
 * toward. It does NOT demand derivation, because a constant is legitimately useful for `match`
 * arms and named single values — it demands only that a copy which exists tells the truth.
 */

/** @return array<string, array{const: list<string>, set: list<string>}> */
function shadowedModelConstants(): array
{
    $drift = [];

    foreach (File::allFiles(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $model = new $class;
        $table = $model->getTable();

        foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
            // Only plural list constants that could plausibly restate a column's set.
            if (! is_array($value) || $value === [] || ! in_array($name, ['TYPES', 'STATUSES'], true)) {
                continue;
            }

            // A list of non-strings is not a value set (weights, ids, nested config).
            if (array_filter($value, fn ($v) => ! is_string($v)) !== []) {
                continue;
            }

            $column = $name === 'TYPES' ? 'type' : 'status';
            $set = ValueSets::allowed($table, $column);

            if ($set === null) {
                continue;
            }

            sort($value);
            $sorted = $set;
            sort($sorted);

            if ($value !== $sorted) {
                $drift[$class.'::'.$name] = ['const' => $value, 'set' => $sorted];
            }
        }
    }

    return $drift;
}

it('finds constants that shadow a value set, so the sweep is not vacuously green', function () {
    // The guard this file needs most: a discovery that silently matches nothing passes the
    // assertion below forever. This codebase has shipped exactly that gate before.
    $shadowed = 0;

    foreach (File::allFiles(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $constants = (new ReflectionClass($class))->getConstants();
        $table = (new $class)->getTable();

        foreach (['TYPES' => 'type', 'STATUSES' => 'status'] as $name => $column) {
            if (isset($constants[$name]) && is_array($constants[$name]) && ValueSets::allowed($table, $column) !== null) {
                $shadowed++;
            }
        }
    }

    expect($shadowed)->toBeGreaterThan(3);
});

it('keeps every shadowing constant in step with the registry', function () {
    $drift = shadowedModelConstants();

    $report = implode("\n", array_map(
        fn (string $k, array $v) => sprintf(
            '  %s: constant has [%s], ValueSets has [%s]',
            $k,
            implode(', ', $v['const']),
            implode(', ', $v['set']),
        ),
        array_keys($drift),
        $drift,
    ));

    expect($drift)->toBe([], "A model constant disagrees with ValueSets. The registry is the source \n"
        ."of truth; either update the constant or derive it (see UtilityMeter::types()).\n".$report);
});
