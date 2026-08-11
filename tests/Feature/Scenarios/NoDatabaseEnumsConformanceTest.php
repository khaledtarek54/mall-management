<?php

use App\Support\DatabaseEnums;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-enforcing gate — no NEW DB-level enum column.
 *
 * `string` + Laravel/Filament validation is a documented convention in CLAUDE.md, and it is the one
 * long-standing convention with no gate. That is not a coincidence: **62 enum columns survive while
 * only four have ever been freed.** The project's own defining strength is that a convention with a
 * gate does not drift; this is the counter-example that proves it.
 *
 * **The cost is deploy friction, not breakage** — and saying so precisely matters, because the
 * original framing was wrong. Laravel renders `enum()` on SQLite as `varchar check (…)`, so the
 * suite enforces the identical set, and a diff of every model's `STATUS_*`/`TYPE_*`/`METHOD_*`
 * constant against all 62 DB sets found zero mismatches. There is no false-green hole. What there
 * is: adding one value means `ALTER TABLE … MODIFY`, twice in three days already, once on
 * `payments.status` — and an operator who cannot add a payment rail without a deploy, in a market
 * where the rails keep moving.
 *
 * Reads the LIVE SCHEMA rather than the migrations, because the schema is the truth however a
 * column got there — an `ALTER` in a later migration is just as much an enum as the `create` was.
 */

/**
 * Every `table.column` in the LIVE schema that is a DB-level enum, on either driver.
 *
 * **Two drivers, one question, and the difference is the whole reason this needs writing out.**
 * MySQL stores an enum as a column TYPE. SQLite — which the test suite runs on — has no enum type,
 * so Laravel renders it as `varchar check ("col" in (…))`. A gate that only checked the column type
 * would therefore find ZERO enums in the environment it actually runs in, pass forever, and gate
 * nothing. That is not hypothetical: the first version of this file did exactly that.
 *
 * Reading the schema rather than the migrations is deliberate too. Static parsing has two blind
 * spots that both apply here: a column freed by raw `DB::statement('ALTER TABLE …')` still reads as
 * an enum in its original `create` migration, and `maintenance_requests` was renamed to
 * `tenant_requests`, so its four columns would be counted under a table that no longer exists.
 *
 * @return array<int, string>
 */
function liveEnumColumns(): array
{
    $found = [];

    if (DB::connection()->getDriverName() === 'sqlite') {
        foreach (DB::table('sqlite_master')->where('type', 'table')->get() as $table) {
            // `"status" varchar check ("status" in ('draft', 'issued'))`
            preg_match_all('/"([a-z0-9_]+)"\s+varchar\s+check\s*\(\s*"\1"\s+in\s*\(/i', (string) $table->sql, $matches);

            foreach ($matches[1] as $column) {
                $found[] = $table->name.'.'.$column;
            }
        }

        return array_values(array_unique($found));
    }

    foreach (Schema::getTables() as $table) {
        $name = is_array($table) ? ($table['name'] ?? '') : $table;

        if ($name === '') {
            continue;
        }

        foreach (Schema::getColumns($name) as $column) {
            if (str_starts_with(strtolower((string) $column['type']), 'enum')) {
                $found[] = $name.'.'.$column['name'];
            }
        }
    }

    return array_values(array_unique($found));
}

it('adds no DB-level enum column beyond the grandfathered set', function () {
    $new = array_diff(liveEnumColumns(), DatabaseEnums::GRANDFATHERED);

    expect(array_values($new))->toBe(
        [],
        'New DB-level enum column(s): '.implode(', ', $new)."\n"
        .'Use a string column plus validation (CLAUDE.md — "no DB-level enums"). Widening an enum '
        .'later costs an ALTER TABLE on a live table, and an operator cannot add a value at all.'
    );
});

it('carries no grandfathered entry for a column that is no longer an enum', function () {
    // Freeing a column MUST remove its line, or this list rots the way the PHPStan baseline did —
    // a fifth of that file described errors that no longer existed, and nothing said so. A list
    // nobody can tell is out of date reports coverage it does not have.
    $stale = array_diff(DatabaseEnums::GRANDFATHERED, liveEnumColumns());

    expect(array_values($stale))->toBe(
        [],
        'Freed (or renamed) column(s) still listed in App\Support\DatabaseEnums::GRANDFATHERED: '
        .implode(', ', $stale).'. Delete the line — that is how the list shrinks.'
    );
});

it('keeps the burn-down list honest about what it still refers to', function () {
    // Every column marked "free this one" must still be an enum. Once freed, it leaves both lists.
    $gone = array_diff(array_keys(DatabaseEnums::FREE_THESE), liveEnumColumns());

    expect(array_values($gone))->toBe([], 'Already freed, remove from FREE_THESE: '.implode(', ', $gone));
});

it('states a reason for every column it recommends freeing', function () {
    $blank = array_keys(array_filter(
        DatabaseEnums::FREE_THESE,
        fn (string $reason): bool => trim($reason) === '',
    ));

    expect($blank)->toBe([]);
});

it('recommends freeing only columns that are actually grandfathered', function () {
    // The two lists must not disagree about what exists — a FREE_THESE entry outside
    // GRANDFATHERED would be advice about a column the gate is not watching.
    $orphan = array_diff(array_keys(DatabaseEnums::FREE_THESE), DatabaseEnums::GRANDFATHERED);

    expect(array_values($orphan))->toBe([]);
});
