<?php

/*
|--------------------------------------------------------------------------
| A setting the screen writes and nothing reads (CFG-06)
|--------------------------------------------------------------------------
| The failure is invisible from the screen: the operator changes a number, the page says "Saved ✓",
| the value really is in the database — and nothing consults it. No error, no red, nothing to
| notice. It has happened three times here and was found by hand every time.
|
|   1. `TaxSettings` was written by the page and read as `config()` from env — the screen did nothing.
|   2. `levy_rate_percent`, `auto_apply_tenant_credit` and `holdover_default_rate_pct` were live
|      settings with NO screen at all (CFG-01 found those).
|   3. `default_payment_terms_days` had a screen AND a reader and was STILL inert: the reader sat on
|      the dead branch of a `??` over a NOT NULL column (CFG-03 found that one).
|
| Case 3 is why this file has two halves — a grep for the setting's name passes it happily.
|
| Registry: `App\Support\SettingsReach`.
*/

use App\Support\SettingsReach;
use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/** Every line of first-party PHP that could read a setting — the settings classes and page excluded. */
function readerSource(): string
{
    static $source = null;

    if ($source !== null) {
        return $source;
    }

    $source = '';

    foreach (['app', 'routes'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            $path = $file->getPathname();

            // The settings CLASS is the declaration, not a read. The settings PAGE is the write
            // side — counting it would make every setting look consulted by the very screen whose
            // inertness is the thing under test.
            if (str_contains($path, '/app/Settings/')) {
                continue;
            }

            if (str_ends_with($path, 'Admin/Pages/Settings.php')) {
                continue;
            }

            $source .= file_get_contents($path)."\n";
        }
    }

    return $source;
}

it('has something reading every setting', function () {
    $source = readerSource();
    $unread = [];

    foreach (SettingsRegistry::classes() as $class) {
        foreach (SettingsRegistry::propertiesOf($class) as $property) {
            $name = is_string($property) ? $property : $property->getName();
            $key = $class::group().'.'.$name;

            if (str_contains($source, $name)) {
                continue;
            }

            if (array_key_exists($key, SettingsReach::EXEMPT_NO_READER)
                || array_key_exists($key, SettingsReach::KNOWN_INERT)) {
                continue;
            }

            $unread[] = $key;
        }
    }

    expect($unread)->toBe([], implode("\n", [
        'These settings are written by the settings page and read by nothing:',
        '  '.implode("\n  ", $unread),
        '',
        'The operator will change one, see "Saved", and nothing will happen.',
        'Either wire it, or declare it in App\Support\SettingsReach with a reason.',
    ]));
});

it('does not let a known-inert setting be quietly declared fine', function () {
    // Two lists, because "this is correct" and "this is broken and here is why we have not fixed
    // it" are different claims. Collapsing them is how a real gap becomes invisible again — which
    // is the exact failure this whole gate exists to stop.
    foreach (SettingsReach::KNOWN_INERT as $key => $reason) {
        expect(SettingsReach::EXEMPT_NO_READER)->not->toHaveKey($key, "{$key} cannot be both a defect and by design");
        expect(strlen($reason))->toBeGreaterThan(30, "{$key} needs a real reason, not a label");
    }
});

it('names a setting that actually exists in every exemption', function () {
    // A stale exemption is worse than none: it silently covers a setting that has since been
    // renamed, so the real one goes ungated while the list still looks complete.
    $keys = SettingsReach::keys();

    foreach (array_merge(SettingsReach::EXEMPT_NO_READER, SettingsReach::KNOWN_INERT) as $key => $reason) {
        expect(in_array($key, $keys, true))
            ->toBeTrue("{$key} is exempted but is not a registered setting any more");
    }
});

/*
|--------------------------------------------------------------------------
| Half two — the reader has to be REACHABLE
|--------------------------------------------------------------------------
*/

it('refuses a settings fallback that can never fire', function () {
    // `$lease->payment_terms_days ?? BillingSettings::defaultPaymentTermsDays()` reads like a
    // working fallback and is dead code when the column is NOT NULL with a database default — which
    // that one is. Eight billing call sites carried it, so the setting could never apply: an
    // operator could set 30 and watch every lease keep billing at 7. A name-grep passes this.
    $notNull = notNullColumns();
    $undeclared = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $lines = file($file->getPathname());

        foreach ($lines as $i => $line) {
            if (! str_contains($line, '??')) {
                continue;
            }

            // Only the part to the RIGHT of the ?? counts as the fallback.
            [$left, $right] = array_pad(explode('??', $line, 2), 2, '');

            $readsSettings = collect(SettingsReach::SETTINGS_READ_MARKERS)
                ->contains(fn (string $marker) => str_contains($right, $marker));

            if (! $readsSettings) {
                continue;
            }

            // The column being defaulted is the last property access on the left.
            if (! preg_match('/->(\w+)\s*$/', rtrim($left), $m)) {
                continue;
            }

            $column = $m[1];

            if (! in_array($column, $notNull, true)) {
                continue;
            }

            if (array_key_exists("{$relative}:{$column}", SettingsReach::NOT_NULL_FALLBACKS)) {
                continue;
            }

            $undeclared[] = "{$relative}:".($i + 1)." — `{$column}` is NOT NULL, so this fallback is unreachable";
        }
    }

    expect($undeclared)->toBe([], implode("\n", [
        'These settings fallbacks can never fire:',
        '  '.implode("\n  ", $undeclared),
        '',
        'A NOT NULL column always has a value, so the `?? <setting>` is dead code and the setting',
        'silently applies to nothing. Move the default to origination, or declare the site in',
        'App\Support\SettingsReach::NOT_NULL_FALLBACKS with a reason.',
    ]));
});

it('names a column that is really NOT NULL in every declared fallback', function () {
    // The mirror of the stale-exemption check. A declaration whose column has since been made
    // nullable is covering nothing, and would hide a genuine dead fallback later.
    $notNull = notNullColumns();

    foreach (SettingsReach::NOT_NULL_FALLBACKS as $site => $reason) {
        [$path, $column] = explode(':', $site, 2);

        expect(file_exists(base_path($path)))->toBeTrue("{$site} points at a file that no longer exists");
        expect(in_array($column, $notNull, true))
            ->toBeTrue("{$site} declares a NOT NULL fallback but `{$column}` is nullable now");
        expect(strlen($reason))->toBeGreaterThan(30, "{$site} needs a real reason, not a label");
    }
});

/** Column names that are NOT NULL everywhere they appear. */
function notNullColumns(): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $nullableSomewhere = [];
    $seen = [];

    foreach (Schema::getTableListing() as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $seen[$column['name']] = true;

            if ($column['nullable']) {
                $nullableSomewhere[$column['name']] = true;
            }
        }
    }

    // "NOT NULL" only counts when the name is NOT NULL in EVERY table carrying it. A column that is
    // nullable somewhere makes the fallback genuinely reachable for that model, and flagging it
    // would be a false positive on the one gate that must not cry wolf.
    $columns = array_values(array_diff(array_keys($seen), array_keys($nullableSomewhere)));

    return $columns;
}
