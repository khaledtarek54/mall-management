<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\FieldWidths;

/**
 * **No door accepts more than the column holds — checked where the column has a width.**
 *
 * The other half of `ADoorNeverRefusesWhatAnotherDoorAcceptedConformanceTest`, here because sqlite
 * cannot see it: Laravel's sqlite grammar emits a bare `varchar` with no length, so a 32-character
 * national ID validated into a `varchar(20)` is perfectly green in the ordinary suite and fatal on
 * the real database. That is the same reason `DriverBehaviourTest` checks `ValueSets` by WIDTH
 * rather than by inserting, and this sits beside it deliberately.
 *
 * The failure it catches: the row passes validation and the INSERT refuses it, so an operator
 * importing their own file reads a raw *"Data too long for column"* in `failed_import_rows` where
 * a field-level message belongs — or, on a connection that is not strict, gets a silently
 * truncated national ID or email address that nobody notices until a document bounces. Four
 * shipped that way and none was visible from inside the file that had it:
 *
 *   - `ChargeImporter.type`            max:64  into a varchar(32)
 *   - `EmployeeImporter.national_id`   max:32  into a varchar(20)
 *   - `EmployeeImporter.phone`         max:32  into a varchar(30)
 *   - `VendorImporter.email`           max:255 into a varchar(200)
 *
 * The first three were narrowed to the column; the fourth was the door that was RIGHT — 255 is
 * this application's own convention for an email everywhere else — so the column widened instead.
 *
 * Reads the shape of the database `composer qa:baseline` already built, like the rest of this tier.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The MySQL tier needs a MySQL connection — see tests/Mysql/README.md.');
    }
});

it('never lets a form field accept more than its column holds', function () {
    $over = [];
    $checked = 0;

    foreach (FieldWidths::formLimits() as $model => $columns) {
        $widths = FieldWidths::columnWidths($model);

        foreach ($columns as $column => $fields) {
            $width = $widths[$column] ?? null;

            if ($width === null) {
                continue;   // not a bounded string column — a TEXT column has no width to exceed
            }

            foreach ($fields as [$max, $at]) {
                $checked++;

                if ($max > $width) {
                    $over[] = "{$at}  {$column}  maxLength({$max}) > varchar({$width})";
                }
            }
        }
    }

    // The premise, and it is load-bearing on this tier above all others: on sqlite every width is
    // null, so without this the whole file would pass vacuously the moment somebody ran it against
    // the wrong connection.
    expect($checked)->toBeGreaterThan(100, 'The form sweep examined almost nothing — either the '
        .'model resolver stopped resolving, or this database reports no column widths.');

    expect($over)->toBe([], 'A form accepts more than the column holds, so the save fails at the '
        ."database rather than in the field:\n  ".implode("\n  ", $over));
});

it('never lets an importer accept more than its column holds', function () {
    $over = [];
    $checked = 0;

    foreach (FieldWidths::importers() as $path => $model) {
        $widths = FieldWidths::columnWidths($model);

        foreach (FieldWidths::chains(file_get_contents($path), ['ImportColumn']) as $column) {
            $width = $widths[$column['name']] ?? null;
            $max = FieldWidths::maxRuleOf($column['chain']);

            if ($column['name'] === null || $width === null || $max === null) {
                continue;
            }

            $checked++;

            if ($max > $width) {
                $over[] = basename($path).":{$column['line']}  {$column['name']}  max:{$max} > varchar({$width})";
            }
        }
    }

    expect($checked)->toBeGreaterThan(20, 'The importer sweep examined almost nothing.');

    expect($over)->toBe([], 'An importer validates a row the INSERT then refuses — the operator '
        ."reads a database error, or the value is silently truncated:\n  ".implode("\n  ", $over));
});
