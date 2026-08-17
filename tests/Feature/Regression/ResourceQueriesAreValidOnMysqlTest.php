<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use App\Support\SearchPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * **Every resource query must be valid SQL on the database we actually ship on.**
 *
 * THE BUG. `FixedAssetResource::getEloquentQuery()` built its derived accumulated-depreciation
 * column as `->withSum(…)->addSelect(['*', DB::raw(…)])`. `withSum()` has already selected
 * `fixed_assets.*`, so the second bare `*` produced:
 *
 *     select `fixed_assets`.*, (select sum(…)) as `depreciation_charged`, *, COALESCE(…) AS accumulated
 *
 * A bare `*` after a qualified column list is a **syntax error in MySQL** and is accepted by
 * SQLite. The suite runs on sqlite `:memory:`, so 5,172 tests passed over it while the real
 * database refused the fixed-asset list, the register CSV export, and — because global search fans
 * out to EVERY searchable resource — every single query typed into the search bar. It shipped in
 * `ad665318` and was found by a browser, not by a test.
 *
 * This is the same shape as the CHECK-constraint trap already recorded in CLAUDE.md: two drivers
 * express something differently and only one of them is in the test run. The lesson is not "test on
 * MySQL" — it is that a query which never executes in the suite is not covered by the suite.
 *
 * WHAT THIS TEST DOES. It compiles every searchable resource's query to SQL and asserts the shape
 * that MySQL refuses, without needing MySQL: a `*` may appear only as the first selected column.
 * Compiling is enough because the fault is in the SQL string, and it means the guard runs in the
 * ordinary sqlite suite where it will actually be seen.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Does this SELECT list put a bare `*` anywhere but first?
 *
 * Deliberately crude — it reads the compiled string rather than the builder, because the builder is
 * exactly where the two `*`s look reasonable side by side.
 */
function selectsBareStarAfterAnotherColumn(string $sql): bool
{
    if (! str_starts_with(strtolower(ltrim($sql)), 'select ')) {
        return false;
    }

    // Walk the select list tracking parenthesis depth, because BOTH delimiters that matter here —
    // the commas between columns and the `from` that ends the list — also occur inside a correlated
    // subquery. The first version of this used `/^select\s+(.*?)\s+from\s/`, which stopped at the
    // `from` inside `(select sum(amount) from depreciation_entries …)` and truncated the list
    // before the offending `*`. It reported the buggy query as clean, which is the one thing a
    // regression test must not do — caught by deleting the fix and watching this stay green.
    $list = substr(ltrim($sql), strlen('select '));
    $columns = [];
    $current = '';
    $depth = 0;
    $length = strlen($list);

    for ($i = 0; $i < $length; $i++) {
        $character = $list[$i];

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($depth === 0 && strtolower(substr($list, $i, 6)) === ' from ') {
            break;
        }

        if ($character === ',' && $depth === 0) {
            $columns[] = trim($current);
            $current = '';

            continue;
        }

        $current .= $character;
    }

    $columns[] = trim($current);

    foreach ($columns as $index => $column) {
        if ($index > 0 && $column === '*') {
            return true;
        }
    }

    return false;
}

it('compiles every searchable resource query to SQL MySQL would accept', function () {
    $broken = [];

    foreach (Filament::getResources() as $resource) {
        if (SearchPolicy::isGlobalSearchExempt($resource)) {
            continue;
        }

        try {
            $query = $resource::getGlobalSearchEloquentQuery();
        } catch (Throwable $e) {
            $broken[] = class_basename($resource).' — query could not be built: '.$e->getMessage();

            continue;
        }

        if (! $query instanceof Builder) {
            continue;
        }

        if (selectsBareStarAfterAnotherColumn($query->toSql())) {
            $broken[] = class_basename($resource).' — bare `*` after another selected column (MySQL syntax error)';
        }
    }

    expect($broken)->toBe([], implode("\n  ", [
        'These resource queries are invalid on MySQL and silently fine on sqlite:',
        ...$broken,
    ]));
});

it('still derives accumulated depreciation after dropping the stray star', function () {
    // The control. Removing `'*'` from a select list is exactly the kind of fix that can quietly
    // drop the column it was there to keep, and `accumulated` is read by the table sort AND the
    // register CSV total.
    $fixedAsset = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'Chiller',
        'tag' => 'FA-STAR-1',
        'acquisition_date' => '2025-01-01',
        'acquisition_cost' => 120000,
        'useful_life_months' => 60,
        'opening_accumulated_depreciation' => 5000,
    ]);

    $row = FixedAssetResource::getEloquentQuery()
        ->whereKey($fixedAsset->id)
        ->first();

    // The row's own columns survive the narrowed select…
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Chiller')
        ->and($row->tag)->toBe('FA-STAR-1')
        // …and so does the derived one, which is the whole reason the addSelect exists.
        ->and(round((float) $row->accumulated, 2))->toBe(5000.0);
});
