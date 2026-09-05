<?php

use App\Support\TableSortPolicy;

/**
 * Self-enforcing gate for {@see TableSortPolicy} — what order a table opens in.
 *
 * The defects this exists to catch were all found by reading 144 tables side by side, and none of
 * them looks wrong in its own file:
 *
 *  - `order by sla_policies.asset_id` — a list ordered by a raw foreign key, i.e. arbitrarily.
 *  - a people register sorted by signup order (`users.id`), so the operator cannot find a name.
 *  - a payslip history sorted by insertion, while a payroll run for March may be generated in May.
 *
 * Discovery is FROM DISK, never from the registry: a gate that reads only the list it guards
 * cannot see what that list omits. {@see TableSortPolicy::owns()} states the derivation — a file
 * owns a row order unless it delegates to a shared table class or is fed from an array.
 */
function tableSortOwningFiles(): array
{
    $files = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (TableSortPolicy::owns($source)) {
            $files[TableSortPolicy::relative($file->getPathname())] = $source;
        }
    }

    ksort($files);

    return $files;
}

/**
 * The `->defaultSort(...)` this file declares, as `[column, direction]`.
 *
 * `null` means the file declares none — legitimate only where the order is set inside the
 * table's own `->query()` closure, which is how the dashboard widgets do it.
 */
function tableSortDeclaration(string $source): ?array
{
    if (! preg_match('/->defaultSort\(\s*(.+)$/m', $source, $m)) {
        return null;
    }

    $args = $m[1];

    if (! preg_match("/^'([^']+)'/", $args, $col)) {
        return ['__closure__', 'asc'];
    }

    return [$col[1], str_contains($args, "'desc'") ? 'desc' : 'asc'];
}

it('classifies every table that owns its row order', function () {
    $files = tableSortOwningFiles();

    // The premise. A sweep whose discovery silently stopped collecting would report no offenders
    // and pass — the failure this codebase has been bitten by more than any other.
    expect($files)->toHaveCount(147);

    $unclassified = [];

    foreach ($files as $path => $source) {
        if (TableSortPolicy::kindOf($path) === null && ! array_key_exists($path, TableSortPolicy::EXEMPT)) {
            $unclassified[] = $path;
        }
    }

    expect($unclassified)->toBe([], "These tables decide a row order that nothing says is deliberate.\n"
        ."Classify each in App\Support\TableSortPolicy::TABLES (LEDGER · REGISTER · WORKLIST ·\n"
        ."SEQUENCE · RANKED · CUSTOM), or exempt it with a reason:\n  ".implode("\n  ", $unclassified));
});

it('holds no entry for a table that no longer owns its order', function () {
    $files = tableSortOwningFiles();

    $stale = array_values(array_diff(
        [...array_keys(TableSortPolicy::TABLES), ...array_keys(TableSortPolicy::EXEMPT)],
        array_keys($files),
    ));

    expect($stale)->toBe([], "Registered in App\Support\TableSortPolicy but no longer owns a row order —\n"
        ."the file was deleted, renamed, or now delegates to a shared table class:\n  ".implode("\n  ", $stale));
});

it('never orders a list by a raw foreign key', function () {
    // An `*_id` is a surrogate. Ordering by one produces whatever sequence the rows happened to be
    // inserted in on the OTHER table, which is not an order any reader can predict or use. Both
    // instances shipped: `sla_policies.asset_id` and the portal's `cam_expense_pool_id`.
    $offenders = [];

    foreach (tableSortOwningFiles() as $path => $source) {
        $sort = tableSortDeclaration($source);

        if ($sort === null) {
            continue;
        }

        [$column] = $sort;
        $leaf = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;

        if ($leaf !== 'id' && str_ends_with($leaf, '_id')) {
            $offenders[] = "{$path} → {$column}";
        }
    }

    expect($offenders)->toBe([], "Ordered by a raw foreign key, which is no order at all. Sort on the\n"
        ."related record's own name, code or date instead — the column the table already shows:\n  "
        .implode("\n  ", $offenders));
});

it('opens a document register newest first and a master register alphabetically', function () {
    $wrongWay = [];

    foreach (tableSortOwningFiles() as $path => $source) {
        $kind = TableSortPolicy::kindOf($path);
        $sort = tableSortDeclaration($source);

        if ($sort === null || $kind === null) {
            continue;
        }

        [$column, $direction] = $sort;

        if ($column === '__closure__') {
            // A closure order is only legible at the call site, so it must say it is deliberate.
            if ($kind !== TableSortPolicy::CUSTOM) {
                $wrongWay[] = "{$path} → a closure order on a {$kind} table (classify it CUSTOM)";
            }

            continue;
        }

        if ($kind === TableSortPolicy::LEDGER && $direction !== 'desc') {
            $wrongWay[] = "{$path} → LEDGER sorted {$direction}; a document register opens newest first";
        }

        if (in_array($kind, [TableSortPolicy::REGISTER, TableSortPolicy::WORKLIST], true) && $direction !== 'asc') {
            $wrongWay[] = "{$path} → {$kind} sorted desc; a register reads A→Z and a worklist soonest first";
        }

        // A CUSTOM table claims a domain order. If it declares a plain column and no query
        // ordering it has no domain order at all — it is an unclassified table wearing the
        // escape hatch, which is how the portal's marketing feed came to open in insertion
        // order while the operator's copy of the same list opened in feed order.
        if ($kind === TableSortPolicy::CUSTOM && ! str_contains($source, '->modifyQueryUsing(')) {
            $wrongWay[] = "{$path} → CUSTOM but orders by a plain column; express the domain order as a "
                .'defaultSort closure or a query scope, or classify it as what it really is';
        }
    }

    expect($wrongWay)->toBe([], "A table opens in the opposite order to the kind it is:\n  ".implode("\n  ", $wrongWay));
});

it('never sorts a register or a dated document by its primary key', function () {
    // Two different failures, one cause. A REGISTER sorted by `id` is in signup order, so a name
    // cannot be found by eye. A LEDGER sorted by `id` claims insertion order IS the document
    // chronology — true for an append-only record, false the moment a document can be dated
    // independently of when it was keyed, which is most of them.
    $offenders = [];

    foreach (tableSortOwningFiles() as $path => $source) {
        $kind = TableSortPolicy::kindOf($path);
        $sort = tableSortDeclaration($source);

        if ($sort === null || $sort[0] !== 'id') {
            continue;
        }

        if ($kind === TableSortPolicy::REGISTER) {
            $offenders[] = "{$path} → REGISTER sorted by id; sort on the name or code the list shows";
        }

        if ($kind === TableSortPolicy::LEDGER && ! TableSortPolicy::sortsByInsertion($path)) {
            $offenders[] = "{$path} → LEDGER sorted by id; sort on the document's own date, or record in "
                .'TableSortPolicy::LEDGER_BY_INSERTION why this model has none';
        }
    }

    expect($offenders)->toBe([], "Ordered by a surrogate key where a business column exists:\n  ".implode("\n  ", $offenders));
});

it('holds no stale insertion-order exception', function () {
    $files = tableSortOwningFiles();
    $stale = [];

    foreach (TableSortPolicy::LEDGER_BY_INSERTION as $path => $why) {
        if (! isset($files[$path])) {
            $stale[] = "{$path} → no longer owns a row order";

            continue;
        }

        if ((tableSortDeclaration($files[$path])[0] ?? null) !== 'id') {
            $stale[] = "{$path} → no longer sorts by id, so the exception grants nothing";
        }

        expect(strlen($why))->toBeGreaterThan(40, "{$path}: an exception nobody can review is not an exception");
    }

    expect($stale)->toBe([], "App\Support\TableSortPolicy::LEDGER_BY_INSERTION is out of date:\n  ".implode("\n  ", $stale));
});

it('orders a table that declares no default sort inside its own query', function () {
    // The dashboard widgets build their query by hand and order it there. That is fine — what is
    // not fine is a table with neither, which falls through to Filament's `id asc` and opens on
    // the oldest row in the system.
    $unordered = [];

    foreach (tableSortOwningFiles() as $path => $source) {
        if (tableSortDeclaration($source) !== null) {
            continue;
        }

        if (! preg_match('/->(orderBy|orderByDesc|orderByRaw|latest|oldest)\(|->modifyQueryUsing\(/', $source)) {
            $unordered[] = $path;
        }
    }

    expect($unordered)->toBe([], "No default sort and no ordering in the query — this list opens on the\n"
        ."oldest row in the table, which is never what anyone wants:\n  ".implode("\n  ", $unordered));
});
