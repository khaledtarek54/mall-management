<?php

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\ValueSets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **The gate on "no DB-level enum column, anywhere."**
 *
 * CLAUDE.md's rule is `string` + Laravel validation, never `$table->enum(...)`. On 2026-08-12 the
 * last 62 enum columns were converted and their sets moved to {@see ValueSets}, so this file no
 * longer keeps a grandfathered list — the allowed count is zero, and the burn-down list is gone
 * because there is nothing left to burn down.
 *
 * **The previous version of this gate passed while being wrong, and the mechanism is worth keeping
 * written down.** It compared the live schema against a 38-entry grandfathered list and went green.
 * MySQL had 62. Laravel renders `enum()` on SQLite as `varchar check ("col" in (…))`, which is why
 * the old docblock concluded the suite enforced the identical sets — but SQLite has no
 * `ALTER COLUMN`, so any `->change()` makes Laravel rebuild the table from the *introspected* schema,
 * which knows the column is a `varchar` and nothing about the check. Every check constraint on that
 * table is dropped, silently. 24 columns had been freed that way on SQLite while remaining enums on
 * MySQL, so the gate read 38 in the environment it ran in and passed, and the 24 were enforced
 * NOWHERE in tests: a value the model allowed but MySQL refused would have been green here and
 * fatal on the first real save. That is the `escalation_type` bug this project has already paid for,
 * queued up 24 more times.
 *
 * So the schema half of this file still reads BOTH driver shapes even though neither should now
 * match anything — because "we found nothing" has to mean "there is nothing", not "we looked in the
 * wrong place". The enforcement half then proves the replacement actually runs, since a registry
 * nothing consults is decoration.
 */

/**
 * Every `table.column` in the LIVE schema that is a DB-level enum, on either driver.
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

it('has no DB-level enum column at all', function () {
    $enums = liveEnumColumns();

    expect($enums)->toBe(
        [],
        'DB-level enum column(s): '.implode(', ', $enums)."\n"
        .'Use a string column and add the set to App\Support\ValueSets (CLAUDE.md — "no DB-level '
        .'enums"). Widening an enum costs an ALTER TABLE on a live table, and an operator cannot add '
        .'a value at all.'
    );
});

it('registers a value set for a column that still exists', function () {
    // The lesson from the PHPStan baseline, which rotted until a fifth of it described errors that
    // no longer existed: a list nobody can tell is out of date reports coverage it does not have.
    // A renamed table or dropped column must take its entry with it.
    $stale = [];

    foreach (array_keys(ValueSets::SETS) as $key) {
        [$table, $column] = explode('.', $key, 2);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'App\Support\ValueSets names column(s) that no longer exist: '.implode(', ', $stale));
});

it('holds every freed column as a string column, not an integer or a date', function () {
    // The conversion is only honoured if the column can actually store the set. A `status` column
    // left as an int would pass the enum check above and store 0 for every value in the registry.
    $wrongType = [];

    foreach (array_keys(ValueSets::SETS) as $key) {
        [$table, $column] = explode('.', $key, 2);
        $type = strtolower((string) Schema::getColumnType($table, $column));

        if (! str_contains($type, 'char') && ! str_contains($type, 'text')) {
            $wrongType[] = $key.' is '.$type;
        }
    }

    expect($wrongType)->toBe([], 'Not string columns: '.implode(', ', $wrongType));
});

it('states a non-empty set of values for every registered column', function () {
    // `resolved()`, not the raw `SETS`: a set may be declared as the backed-enum class the model
    // also casts to, and what must be non-empty is what the guard actually compares against.
    $empty = array_keys(array_filter(ValueSets::resolved(), fn (array $values): bool => $values === []));

    expect($empty)->toBe([], 'Empty value set(s) — a column that accepts nothing cannot be saved: '.implode(', ', $empty));
});

it('lists each value once per column', function () {
    // A duplicate is harmless to the check and a sign the set was edited by hand from two places.
    $dupes = [];

    foreach (ValueSets::resolved() as $key => $values) {
        if (count($values) !== count(array_unique($values))) {
            $dupes[] = $key;
        }
    }

    expect($dupes)->toBe([], 'Duplicated value(s) in: '.implode(', ', $dupes));
});

/**
 * The enforcement half. The registry is not the guard — `AppServiceProvider`'s wildcard
 * `eloquent.saving: *` listener is — and these prove it is wired, because a registry that nothing
 * consults would satisfy every assertion above.
 *
 * Each refusal is PAIRED with a control that must succeed. A refusal test passes just as happily
 * when the save is a no-op for some unrelated reason, which is how a guard gets credited for work
 * it is not doing.
 */
it('refuses a value the column does not accept', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);

    expect(fn () => $tenant->update(['status' => 'suspended']))
        ->toThrow(DomainException::class);

    // The control: the guard refuses the unknown value, not the update.
    $tenant->update(['status' => 'blacklisted']);

    expect($tenant->fresh()->status)->toBe('blacklisted');
});

it('guards a model with no line of its own in it, because the listener is global', function () {
    // Unit is not mentioned anywhere in ValueSets' wiring — no trait, no observer, no boot method.
    // If this passes, the fortieth model added to the project is covered before anyone remembers.
    $unit = Unit::factory()->create(['category' => 'retail']);

    expect(fn () => $unit->update(['category' => 'cinema']))->toThrow(DomainException::class);

    $unit->update(['category' => 'kiosk']);

    expect($unit->fresh()->category)->toBe('kiosk');
});

it('refuses an unknown value on create, not only on update', function () {
    expect(fn () => Tenant::factory()->create(['type' => 'foreign']))->toThrow(DomainException::class);

    expect(Tenant::factory()->create(['type' => 'individual']))->toBeInstanceOf(Tenant::class);
});

it('leaves a row holding a retired value editable through a different field', function () {
    // Narrowing a set must not make history unsaveable. The guard is dirty-only, so a row whose
    // stored value is no longer listed can still be corrected elsewhere — otherwise an operator
    // fixing a phone number would meet a refusal about a field they never touched.
    $tenant = Tenant::factory()->create();

    // Write a value the registry does not list, bypassing model events the way a legacy row got there.
    DB::table('tenants')->where('id', $tenant->id)->update(['status' => 'archived']);

    $tenant->refresh()->update(['phone' => '01000000001']);

    expect($tenant->fresh()->phone)->toBe('01000000001')
        ->and($tenant->fresh()->status)->toBe('archived');
});

it('treats an absent value as the schema\'s business, not an unknown value', function () {
    // Whether the column may be empty is what NOT NULL and the default are for, and CLAUDE.md's own
    // invariant is that a blank optional field is coerced in the model. A nullable select submitting
    // '' has not named a value, so it must not raise "'' is not allowed".
    $invoice = Invoice::factory()->create(['eta_status' => null]);

    $invoice->update(['eta_status' => '']);

    expect($invoice->fresh()->eta_status)->toBeIn([null, '']);
});
