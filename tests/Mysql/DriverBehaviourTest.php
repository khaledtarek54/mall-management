<?php

use App\Support\ValueSets;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three properties of this system that sqlite cannot tell the truth about.
 *
 * Each has already cost something, which is why the tier exists rather than a note in a doc:
 *
 *  1. **Locks are real.** `SQLiteGrammar::compileLock()` returns `''`, so all ~120 lock
 *     acquisitions are inert in the default suite and deleting one turns nothing red. `LockSpy`
 *     makes them *observable* there by compiling to a comment; only MySQL makes them *work*.
 *  2. **A CHECK survives a `->change()`.** SQLite rebuilds the table from its introspected schema
 *     and silently drops the constraint — which is how 24 columns came to be enums in production
 *     and unconstrained in tests, with a gate reading the test schema passing throughout.
 *  3. **`select tbl.*, x, *` is a syntax error.** SQLite accepts it. `FixedAssetResource` built
 *     exactly that shape, and the fixed-asset list, the register CSV and **every query typed into
 *     the global search bar** 500'd on the real database while 5,180 tests passed.
 *
 * These do not use `RefreshDatabase`: they read the shape of a database that already exists
 * (`composer qa:baseline` builds it) rather than migrating a fresh one, because what is under test
 * is the DRIVER's behaviour against the real schema — including whatever a `->change()` migration
 * left behind, which a fresh migrate would hide.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The MySQL tier needs a MySQL connection — see tests/Mysql/README.md.');
    }
});

it('compiles a row lock into real SQL', function () {
    // The one-line difference that made two guards decide nothing (F-09). If this ever returns an
    // empty string again, every `lockForUpdate()` in the codebase is decoration.
    $sql = DB::table('leases')->where('id', 1)->lockForUpdate()->toSql();

    expect($sql)->toContain('for update');

    $shared = DB::table('leases')->where('id', 1)->sharedLock()->toSql();
    expect($shared)->toContain('lock in share mode');
});

it('has no DB-level enum column left, on the real driver', function () {
    // `NoDatabaseEnumsConformanceTest` reads BOTH shapes because the two drivers express this
    // differently; here the question is the direct one, against the schema that actually ships.
    $enums = DB::select(
        'select table_name as `table`, column_name as `column`, column_type as `type`
           from information_schema.columns
          where table_schema = ? and data_type = ?',
        [DB::connection()->getDatabaseName(), 'enum']
    );

    expect($enums)->toBe([], 'A DB-level enum means widening a value set needs an ALTER TABLE: '
        .collect($enums)->map(fn ($e) => "{$e->table}.{$e->column}")->join(', '));
});

it('accepts every value the application believes a column allows', function () {
    // The failure this catches is the one that is green on sqlite and fatal on the first real save:
    // a value `ValueSets` permits that the COLUMN refuses. Checked by width here rather than by
    // inserting — a string column too narrow for its longest allowed value fails identically.
    $tooNarrow = [];

    foreach (ValueSets::SETS as $path => $set) {
        [$table, $column] = explode('.', $path);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            continue;   // a set for a table this database does not have is another gate's problem
        }

        $allowed = ValueSets::allowed($table, $column) ?? [];

        if ($allowed === []) {
            continue;
        }

        $length = DB::selectOne(
            'select character_maximum_length as len from information_schema.columns
              where table_schema = ? and table_name = ? and column_name = ?',
            [DB::connection()->getDatabaseName(), $table, $column]
        )?->len;

        if ($length === null) {
            continue;   // not a string column
        }

        foreach ($allowed as $value) {
            if (mb_strlen((string) $value) > (int) $length) {
                $tooNarrow[] = "{$path} allows '{$value}' (".mb_strlen((string) $value)
                    ." chars) into a {$length}-char column";
            }
        }
    }

    expect($tooNarrow)->toBe([], "The column refuses a value the application allows:\n  "
        .implode("\n  ", $tooNarrow));
});

it('executes every globally-searchable resource query', function () {
    // Compiled is not executed. `FixedAssetResource::getEloquentQuery()` built `select tbl.*, x, *`
    // via `->withSum(…)->addSelect(['*', …])`, which sqlite accepts and MySQL rejects — so the
    // whole search bar 500'd in production with the suite green. Running them is the only check
    // that would have caught it.
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The test proves its own premise first. If MySQL ever stopped rejecting this shape, every
    // assertion below would pass vacuously and nobody would know the check had stopped checking —
    // the failure mode this whole tier exists to avoid.
    $rejectsTheShape = false;

    try {
        DB::select('select units.*, units.id as probe, * from units limit 1');
    } catch (Throwable) {
        $rejectsTheShape = true;
    }

    expect($rejectsTheShape)->toBeTrue(
        'MySQL accepted `select tbl.*, x, *` — the shape this test exists to catch is no longer invalid, '
        .'so passing below means nothing.'
    );

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! $resource::canGloballySearch()) {
            continue;
        }

        try {
            $resource::getEloquentQuery()->limit(1)->get();
        } catch (Throwable $e) {
            $failures[] = class_basename($resource).': '.mb_substr($e->getMessage(), 0, 140);
        }
    }

    expect($failures)->toBe([], "These resource queries are invalid on MySQL:\n  ".implode("\n  ", $failures));
});
