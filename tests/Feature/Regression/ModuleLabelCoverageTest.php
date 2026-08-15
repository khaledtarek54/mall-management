<?php

/*
|--------------------------------------------------------------------------
| Regression — module & category labels resolve (no raw i18n keys in the UI)
|--------------------------------------------------------------------------
| The Playwright smoke caught raw translation keys leaking on real pages the
| suite had never visited before (2026-07-11):
|
|  - Settings + Roles edit render a permission toggle per Modules::KEYS, but the
|    newer modules (inventory, fixed_assets, employees, custodies,
|    facility) had no admin.permission_modules.* label →
|    "admin.permission_modules.employees" showed as visible text.
|  - Maintenance Plans/Work Orders label a row by its category, but the seeded
|    categories (elevator, fire-safety, generator) weren't in
|    admin.facility.categories → raw keys shown.
|
| These guards keep every module/category label present in BOTH locales so the
| next module added can't silently reintroduce the leak.
*/

use App\Support\Modules;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;

it('has a permission-module label in en + ar for every permission group and feature module', function () {
    // Two surfaces render admin.permission_modules.{key}: the Roles edit matrix
    // (one section per RolesPermissionsSeeder::PERMISSIONS module) and the
    // Settings modules toggles (Modules::KEYS). Cover the union so neither can
    // leak a raw key — this is what the Playwright smoke caught on Roles/Settings.
    $keys = array_unique(array_merge(
        array_keys(RolesPermissionsSeeder::PERMISSIONS),
        Modules::KEYS,
    ));

    $missing = [];
    foreach ($keys as $key) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.permission_modules.{$key}", $locale)) {
                $missing[] = "{$key} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([], 'Permission modules without a label: '.implode(', ', $missing));
})->group('i18n');

it('has a label in en + ar for every maintenance category the app uses', function () {
    // The Work-Order/Plan forms offer categories from this same translation
    // array, so any category a record can hold must have a label. Includes the
    // seeded set (elevator/fire-safety/generator) that originally leaked.
    $categories = ['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other', 'elevator', 'fire-safety', 'generator'];

    $missing = [];
    foreach ($categories as $cat) {
        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.facility.categories.{$cat}", $locale)) {
                $missing[] = "{$cat} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([], 'Maintenance categories without a label: '.implode(', ', $missing));
})->group('i18n');
