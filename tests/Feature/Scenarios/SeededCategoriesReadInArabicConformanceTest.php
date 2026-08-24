<?php

use App\Models\ExpenseCategory;
use App\Models\RetailCategory;
use App\Models\ViolationCategory;
use App\Support\CategorySuggestions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * **Every category value the system SHIPS reads in Arabic.**
 *
 * Reported from the running panel, 2026-08-24: *"things still not translated in categorized
 * tables"*. Neither of the two obvious checks could see it — the lang files have **perfect** key
 * parity (12,962 keys both sides, none missing) and no Arabic value is left as English. The
 * untranslated text was not a lang string at all. It was DATA:
 *
 *  - `warehouses.category` was seeded as `'spare parts'` while the suggestion key is `'spare_parts'`
 *    — a space against an underscore, so it matched nothing and fell through to the raw value;
 *  - `fixed_assets.category` was seeded as `'generator'` and `'elevator'`, which were not in the
 *    fixed-asset suggestions at all, though a mall plainly has both.
 *
 * `CategorySuggestions::label()` returning the raw value is CORRECT for a free-text field — an
 * operator may type anything and their word is the best label there is. What is not correct is the
 * system shipping values it has no word for: the demo is what a buyer is shown, and an Arabic
 * screen reading `spare parts` reads as unfinished.
 *
 * So this checks the values SEEDED, not the values possible. An operator-typed category stays their
 * own business.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('gives every seeded category value an Arabic label', function () {
    app()->setLocale('ar');

    /** @var array<int, array{0: string, 1: string, 2: callable}> $columns */
    $columns = [
        ['expenses', 'category', fn (string $v): ?string => ExpenseCategory::labelFor($v)],
        ['vendor_bills', 'category', fn (string $v): ?string => ExpenseCategory::labelFor($v)],
        ['recurring_expenses', 'category', fn (string $v): ?string => ExpenseCategory::labelFor($v)],
        ['violations', 'category', fn (string $v): ?string => ViolationCategory::labelFor($v)],
        ['tenants', 'retail_category', fn (string $v): ?string => RetailCategory::labelFor($v)],
        ['warehouses', 'category', fn (string $v): ?string => CategorySuggestions::label('warehouse', $v)],
        ['fixed_assets', 'category', fn (string $v): ?string => CategorySuggestions::label('fixed_asset', $v)],
    ];

    $untranslated = [];
    $checked = 0;

    foreach ($columns as [$table, $column, $label]) {
        foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $checked++;
            $rendered = $label($value);

            // No Arabic script in the rendered label means the operator is reading English — either
            // the raw code fell through, or the label itself was never translated.
            if ($rendered === null || ! preg_match('/\p{Arabic}/u', $rendered)) {
                $untranslated[] = "{$table}.{$column} = '{$value}' → ".($rendered ?? 'NULL');
            }
        }
    }

    // The sweep must have found values before reporting none untranslated — a seeder that stops
    // producing categories would otherwise turn this green by emptying it.
    expect($checked)->toBeGreaterThan(15);

    expect($untranslated)->toBe([], implode("\n  ", array_merge(
        ['These category values ship with the system and have no Arabic label, so an Arabic',
            'operator reads English (or a raw code) in the table:'],
        $untranslated,
    )));
});
