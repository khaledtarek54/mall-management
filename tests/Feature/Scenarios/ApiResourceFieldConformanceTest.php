<?php

use Illuminate\Support\Facades\Schema;

/**
 * Every field an API Resource emits must still exist on the model it claims to describe.
 *
 * **The failure this exists for.** `units.floor` was a string column; replacing it with the `Floor`
 * register turned `$unit->floor` into a RELATION, and three endpoints silently began serialising a
 * whole Floor object where the Dart client expects a scalar. Nothing caught it: the relation
 * resolves, so PHPStan was satisfied; the API suite asserted presence and values but never types;
 * and `ApiSpecContractTest` only checks that a route is documented, not that its shape is right.
 *
 * Two rules, both derived from that:
 *
 * 1. **Every `$this->x` resolves** to a column, a relation, an accessor or a method. A dropped
 *    column whose name is NOT also a relation emits `null` — quieter still than an object, and
 *    indistinguishable from "the tenant has not set that".
 * 2. **No relation is emitted raw.** `'floor' => $this->unit->floor` is the shape that broke; a
 *    relation belongs behind `whenLoaded()` with its own scalar fields named, never dropped whole
 *    onto the wire.
 *
 * Reflection over the Resources rather than a hand-maintained list: a new Resource is covered the
 * day it is written, which a registry would not manage.
 */
function apiResourceFiles(): array
{
    $dir = app_path('Http/Resources/Api/V1');
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if (! $file->isDir() && $file->getExtension() === 'php') {
            $out[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $out;
}

/** The model a Resource declares it decorates, or null when it names none. */
function apiResourceModel(string $source): ?object
{
    if (! preg_match('/@mixin\s+\\\\?([A-Za-z0-9_\\\\]+)/', $source, $m)) {
        return null;
    }

    $named = ltrim($m[1], '\\');

    // Resolve the SHORT name the way PHP would, which this did not until 2026-08-24.
    //
    // Every one of the 17 resources writes `@mixin Invoice`, not `@mixin \App\Models\Invoice` —
    // so `class_exists('\Invoice')` was false for all of them, every file was skipped at the
    // `continue` below, and this gate swept ZERO resources and passed. The vacuity failure this
    // codebase has now shipped four times, in the gate guarding the mobile API contract.
    $candidates = [$named, 'App\\Models\\'.$named];

    // …and the file's own imports, which is what PHP actually consults for a short name.
    if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($named, '/').');/m', $source, $u)) {
        array_unshift($candidates, $u[1]);
    }

    foreach ($candidates as $candidate) {
        if (class_exists($candidate)) {
            return new $candidate;
        }
    }

    return null;
}

it('emits only fields that still exist on the model', function () {
    $problems = [];
    $swept = 0;

    foreach (apiResourceFiles() as $path => $source) {
        $model = apiResourceModel($source);

        if (! $model || ! Schema::hasTable($model->getTable())) {
            continue;
        }

        $swept++;

        $columns = Schema::getColumnListing($model->getTable());

        preg_match_all('/\$this->([a-z][a-zA-Z0-9_]*)\b(?!\()/', $source, $hits);

        foreach (array_unique($hits[1]) as $prop) {
            if (in_array($prop, $columns, true) || in_array($prop, ['resource', 'id'], true)) {
                continue;
            }
            if (method_exists($model, $prop)) {
                continue;
            }

            $accessor = 'get'.str_replace(' ', '', ucwords(str_replace('_', ' ', $prop))).'Attribute';

            if (method_exists($model, $accessor)) {
                continue;
            }

            $problems[] = basename($path).": \$this->{$prop} is not a column, relation or accessor on "
                .class_basename($model::class);
        }
    }

    // The sweep must have RESOLVED resources before reporting none broken. Without this the gate
    // passed while skipping all 17 — see the note in `apiResourceModel()`.
    expect($swept)->toBeGreaterThan(12);

    expect($problems)->toBe([], "API resources reading something that no longer exists:\n  ".implode("\n  ", $problems));
})->group('api');

it('never drops a whole relation onto the wire', function () {
    $problems = [];

    foreach (apiResourceFiles() as $path => $source) {
        $model = apiResourceModel($source);

        if (! $model) {
            continue;
        }

        // `'key' => $this->rel,` and `'key' => $this->rel->sub,` — a value emitted with no further
        // navigation. Only a flag when the final segment is a RELATION method.
        preg_match_all('/=>\s*\$this->([a-z][a-zA-Z0-9_]*)(?:->([a-z][a-zA-Z0-9_]*))?,/', $source, $hits, PREG_SET_ORDER);

        foreach ($hits as $hit) {
            [$whole, $first] = $hit;
            $second = $hit[2] ?? null;

            // Nested: the relation is being navigated INTO, so check the leaf against the related
            // model instead of the root.
            $target = $model;
            $leaf = $first;

            if ($second !== null) {
                if (! method_exists($model, $first)) {
                    continue;
                }

                try {
                    $related = $model->{$first}()->getRelated();
                } catch (Throwable $e) {
                    continue;
                }

                $target = $related;
                $leaf = $second;
            }

            if (! method_exists($target, $leaf)) {
                continue;
            }

            try {
                $type = (new ReflectionMethod($target, $leaf))->getReturnType();
            } catch (Throwable $e) {
                continue;
            }

            if ($type && str_contains((string) $type, 'Relations\\')) {
                $problems[] = basename($path).": {$whole} emits a relation ("
                    .class_basename((string) $type).') — name its scalar fields instead';
            }
        }
    }

    expect($problems)->toBe([], "API resources putting an object where a scalar belongs:\n  ".implode("\n  ", $problems));
})->group('api');
