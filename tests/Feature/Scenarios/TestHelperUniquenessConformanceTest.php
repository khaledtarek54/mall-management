<?php

/**
 * Self-enforcing gate — no two test files may declare the same file-scope function.
 *
 * PHP has one global function table. Two test files each declaring `function optionOn()` is a
 * FATAL redeclaration the moment a single process loads both, which is every non-parallel run and
 * every `--filter` run (a filter still LOADS every file, it only skips executing them).
 *
 * **Why this needs a gate rather than care.** The failure is invisible. `pest --parallel` gives
 * each worker only the files it owns, so a collision between two files that land on different
 * workers never fires — the suite is green. When it does fire, PHP dies during collection: the
 * run produces **no output at all** and exits 255. No test name, no file, no message. Nothing in
 * the failure points at the cause, and the natural next move — "run just that directory" — is
 * itself a full load, so it fails the same way.
 *
 * It has now cost this project three times: a cross-file `makeViolation()` during the inventory
 * pass (recorded in CLAUDE.md), `optionOn()` during the 2026-08-11 validation sweep, and
 * `annualPctLease()` during the final sweep the same day. Three times is a pattern, and this is a
 * five-line check.
 *
 * ---
 *
 * **THIS GATE CANNOT CATCH THE COLLISION THE NORMAL WAY, AND THAT IS NOT A DEFECT IN IT.**
 *
 * It reads the files as TEXT (`token_get_all`), so it needs nothing loaded — but it is still a
 * test, and any run that would execute it also loads every other test file first. The fatal
 * therefore happens during collection, before this ever runs. Measured with a live collision:
 *
 * | how you run it | result |
 * |---|---|
 * | `pest --parallel` | **exit 255, zero bytes of output** |
 * | `pest --filter="TestHelperUniqueness"` | **exit 255, zero bytes of output** |
 * | `pest tests/Feature/Scenarios/TestHelperUniquenessConformanceTest.php` | fails properly, naming both files |
 *
 * **So when a run dies with exit 255 and NO output, run exactly this:**
 *
 * ```
 * vendor/bin/pest tests/Feature/Scenarios/TestHelperUniquenessConformanceTest.php
 * ```
 *
 * A path-scoped run loads only that path, which is the one way to get the diagnostic out. Every
 * instinctive alternative — re-run, run the directory, add a `--filter` — loads everything and
 * fails identically, which is what makes this cost an hour instead of a minute.
 *
 * **The fix when it fails** is usually not to rename: it is to notice the helper already exists and
 * use it. Two files needing the same fixture is a signal they belong together, which is exactly
 * what the second incident turned out to be.
 *
 * Genuinely shared helpers belong in `tests/Pest.php` (loaded once) or a class under
 * `Tests\Support` — a class, not file-scope functions, for the same reason: a parallel worker only
 * loads the test files it owns, so shared file-scope helpers re-declared per shard are a fatal.
 */
it('declares no test helper function in two different files', function () {
    $declarations = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all(file_get_contents($file->getPathname()));
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                // Track brace depth so a method inside a class, or a closure body, is not
                // mistaken for a file-scope declaration.
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }

            if ($token[0] !== T_FUNCTION || $depth !== 0) {
                continue;
            }

            // The next meaningful token is the name — unless this is a closure or arrow fn,
            // where it is `(` or `use`.
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $next = $tokens[$j];

                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    $declarations[$next[1]][] = str_replace(base_path().'/', '', $file->getPathname());
                }

                break;
            }
        }
    }

    $collisions = collect($declarations)
        ->map(fn (array $files) => array_values(array_unique($files)))
        ->filter(fn (array $files) => count($files) > 1);

    expect($collisions->all())->toBe([], $collisions->isEmpty() ? '' : sprintf(
        'These helper names are declared in more than one test file, which is a FATAL redeclaration '
        ."on any single-process run (and invisible under --parallel):\n%s\n"
        .'Reuse the existing helper, move it to tests/Pest.php, or give yours a distinct name.',
        $collisions->map(fn (array $files, string $name) => "  {$name}: ".implode(', ', $files))
            ->implode("\n"),
    ));
});
