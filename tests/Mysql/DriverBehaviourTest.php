<?php

use App\Models\FacilityWorkOrder;
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
 *  4. **An identifier may not exceed 64 characters.** SQLite has no such limit, so a migration
 *     whose auto-generated index name is too long passes the entire suite and fails on the FIRST
 *     real deploy — after some of its statements have already run, because MySQL does not roll DDL
 *     back. `facility_work_order_labour` shipped with exactly that (2026-08-20): Laravel derived
 *     `facility_work_order_labour_facility_work_order_id_worked_on_index`, 65 characters.
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

/**
 * No identifier exceeds MySQL's 64-character limit.
 *
 * Laravel derives index and constraint names from the table and every column in them, so a long
 * table name plus a two-column index overflows silently — silently, because SQLite has no limit
 * and the whole suite is green. The failure lands on the first `migrate --force` against a real
 * database, halfway through a migration MySQL will not roll back.
 *
 * Reads `information_schema` rather than the migration files: what matters is the name that
 * actually exists, whoever generated it.
 */
it('keeps every index and constraint name within MySQL\'s 64-character limit', function () {
    $database = DB::connection()->getDatabaseName();

    $long = collect(DB::select('
        select table_name, index_name as name, length(index_name) as len
          from information_schema.statistics
         where table_schema = ? and length(index_name) > 64
        union
        select table_name, constraint_name as name, length(constraint_name) as len
          from information_schema.table_constraints
         where table_schema = ? and length(constraint_name) > 64
    ', [$database, $database]))
        ->map(fn ($r): string => "{$r->table_name}.{$r->name} ({$r->len} chars)")
        ->all();

    expect($long)->toBe([], implode('', [
        'These identifiers exceed MySQL\'s 64-character limit: '.implode(', ', $long).'. ',
        'Name the index explicitly in the migration — Laravel derives the name from the table plus ',
        'every column, and SQLite will not tell you.',
    ]));

    // The sweep must prove it looked at something: an empty `information_schema` read would
    // satisfy the assertion above and report coverage it does not have.
    $total = DB::selectOne('select count(*) as c from information_schema.statistics where table_schema = ?', [$database]);
    expect((int) $total->c)->toBeGreaterThan(100);
});

/**
 * The repeat-visit subquery runs on MySQL.
 *
 * Date arithmetic relative to each row has no portable spelling — SQLite wants
 * `datetime(col, '-30 days')`, MySQL wants `date_sub(col, interval 30 day)` — so
 * `scopeWithPriorVisitCount()` branches on the driver. **The suite only ever exercises the SQLite
 * half**, which is precisely the asymmetry this tier exists for: the MySQL branch would otherwise
 * be discovered by an operator opening the work-order list.
 *
 * It also proves the aliased self-subquery compiles at all. A correlated subquery over the same
 * table needs the inner copy aliased, and the soft-delete global scope qualifies its column with
 * the REAL table name — a combination that is easy to get wrong and impossible to see here.
 */
it('compiles and runs the repeat-visit subquery on the real driver', function () {
    $rows = FacilityWorkOrder::query()
        ->withPriorVisitCount()
        ->limit(25)
        ->get();

    // Executed, not merely compiled — the point of the tier.
    expect($rows)->not->toBeNull();

    foreach ($rows as $row) {
        expect((int) $row->prior_visit_count)->toBeGreaterThanOrEqual(0);

        // …and the one-query answer matches the per-row definition on THIS driver too, which is
        // the property a SQLite run cannot establish.
        expect((int) $row->prior_visit_count)
            ->toBe(FacilityWorkOrder::query()->repeatsOf($row)->count(), "work order {$row->id}");
    }
});
