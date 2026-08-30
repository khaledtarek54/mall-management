<?php

use App\Support\RowActionPolicy;

/**
 * Self-enforcing gate for {@see RowActionPolicy} — **the list FINDS, the record ACTS.**
 *
 * A row carried up to eight things you could DO to a record while the record's own page carried
 * Delete and nothing else, so an operator who opened a work order had to go back to the list to
 * act on it. `LeaseActions` and `SalesDeclarationActions` fixed two modules that way; this is what
 * stops the twenty-first table from being written the old way.
 *
 * What counts as a verb is DERIVED — a row action whose chain calls `->action(` — so nobody
 * maintains a vocabulary of act names, and a `ViewAction`, a PDF download or an `open` link is
 * never counted. Relation managers are exempt by DERIVATION too: one is already on a record page.
 */
function rowActionFiles(): array
{
    $files = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[RowActionPolicy::relative($file->getPathname())] = file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

it('keeps write verbs off the list and on the record', function () {
    $offenders = [];
    $tablesSeen = 0;

    foreach (rowActionFiles() as $path => $source) {
        if (RowActionPolicy::segments($source) !== []) {
            $tablesSeen++;
        }

        $verbs = RowActionPolicy::rowActionsIn($source)['verbs'];

        if ($verbs === [] || RowActionPolicy::isRelationManager($path) || RowActionPolicy::keepsVerbsInRow($path)) {
            continue;
        }

        $offenders[] = "{$path} → ".implode(', ', $verbs);
    }

    // The premise, and it has already earned its place: an earlier version of the segmenter
    // pointed its ActionGroup recursion at a nested `__()` replacement list, so every action
    // inside a group vanished and the sweep reported a tidy, wrong result.
    //
    // It counts TABLES, not verbs. A verb count is the thing this gate is driving to zero, so a
    // floor under it fails the moment the work succeeds — which it did, at 88 of a floor of 95.
    // The number of tables carrying row actions at all does not move when a verb relocates.
    expect($tablesSeen)->toBeGreaterThan(100);

    expect($offenders)->toBe([], "These verbs hang off a LIST row. Move them to the record's own page —\n"
        ."define each once in App\Filament\Admin\Actions\{Module}Actions and compose it from the\n"
        ."Edit (or View) page's getHeaderActions(), the way LeaseActions and SalesDeclarationActions\n"
        ."already do. If a screen genuinely has nowhere else to put them, register it in\n"
        ."App\Support\RowActionPolicy::IN_ROW_EXCEPTIONS with the reason:\n  ".implode("\n  ", $offenders));
});

it('holds no stale in-row exception', function () {
    $files = rowActionFiles();
    $stale = [];

    foreach (RowActionPolicy::IN_ROW_EXCEPTIONS as $path => $why) {
        if (! isset($files[$path])) {
            $stale[] = "{$path} → no such file";

            continue;
        }

        // A table that SPREADS a shared Actions class declares no verbs of its own, so asking only
        // about inline ones reports a live exception as stale — which is how TenantSalesDeclarations
        // looked the moment it was registered.
        $composesShared = (bool) preg_match('/\.\.\.\s*[A-Za-z]+Actions::/', $files[$path]);

        if (RowActionPolicy::rowActionsIn($files[$path])['verbs'] === [] && ! $composesShared) {
            $stale[] = "{$path} → no write verbs in the row any more, so the exception grants nothing";
        }

        expect(strlen($why))->toBeGreaterThan(60, "{$path}: an exception nobody can review is not an exception");
    }

    expect($stale)->toBe([], "App\Support\RowActionPolicy::IN_ROW_EXCEPTIONS is out of date:\n  ".implode("\n  ", $stale));
});

it('never composes a shared Actions class back into a list row', function () {
    // The intermediate state this rule exists to end: the acts were extracted into one class —
    // good — and then spread into BOTH the record page and the list, which puts the eight verbs
    // straight back on the row they were taken off.
    $offenders = [];

    foreach (rowActionFiles() as $path => $source) {
        if (RowActionPolicy::isRelationManager($path) || RowActionPolicy::keepsVerbsInRow($path)) {
            continue;
        }

        foreach (RowActionPolicy::segments($source) as $segment) {
            if (preg_match('/\.\.\.\s*([A-Za-z]+Actions)::/', $segment, $m)) {
                $offenders[] = "{$path} → spreads {$m[1]} into recordActions()";
            }
        }
    }

    expect($offenders)->toBe([], "A shared Actions class belongs on the RECORD page, not back in the list row:\n  "
        .implode("\n  ", $offenders));
});

it('leaves no Actions class that no record page composes', function () {
    // The other direction, and the one `ServiceReachability` exists for: a registry every act was
    // carefully moved into, reachable from nothing, looks maintained and is dead.
    $classes = glob(app_path('Filament/Admin/Actions/*Actions.php'));

    expect($classes)->not->toBeEmpty();

    $pages = [];

    foreach (rowActionFiles() as $path => $source) {
        if (str_contains($path, '/Pages/')) {
            $pages[] = $source;
        }
    }

    $orphans = [];

    foreach ($classes as $file) {
        $class = basename($file, '.php');
        $composed = false;

        foreach ($pages as $source) {
            if (str_contains($source, $class.'::')) {
                $composed = true;
                break;
            }
        }

        if (! $composed) {
            $orphans[] = $class;
        }
    }

    expect($orphans)->toBe([], "Defined and reachable from no record page — the acts inside it cannot be\n"
        ."started by anyone:\n  ".implode("\n  ", $orphans));
});
