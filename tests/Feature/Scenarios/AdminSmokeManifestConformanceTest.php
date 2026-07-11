<?php

/*
|--------------------------------------------------------------------------
| Conformance gate — the e2e smoke manifest covers every admin resource/page
|--------------------------------------------------------------------------
| The Playwright system-smoke spec (tests/e2e/99-system-smoke.spec.js) walks
| every URL in tests/e2e/filament-admin-manifest.json. This gate asserts that
| committed manifest still matches what the admin panel actually registers, so
| a newly-added resource or page can NEVER silently escape smoke coverage —
| the same self-enforcing philosophy as PropertyIsolationConformanceTest.
|
| If this fails: run `php artisan atriom:dump-admin-manifest` and commit the
| updated JSON (the smoke spec will then cover the new module automatically).
*/

use App\Console\Commands\DumpAdminManifest;

it('keeps the committed e2e smoke manifest in sync with the live admin panel', function () {
    $path = base_path(DumpAdminManifest::PATH);
    expect(is_file($path))->toBeTrue(DumpAdminManifest::PATH.' is missing — run php artisan atriom:dump-admin-manifest');

    $committed = json_decode(file_get_contents($path), true);
    $live = DumpAdminManifest::manifest();

    $liveSlugs = collect($live['resources'])->pluck('slug')->sort()->values()->all();
    $committedSlugs = collect($committed['resources'])->pluck('slug')->sort()->values()->all();

    $missing = array_values(array_diff($liveSlugs, $committedSlugs));
    $stale = array_values(array_diff($committedSlugs, $liveSlugs));

    expect($missing)->toBe([], 'Resources registered but NOT in the smoke manifest (uncovered): '.implode(', ', $missing).' — run php artisan atriom:dump-admin-manifest');
    expect($stale)->toBe([], 'Resources in the smoke manifest but no longer registered: '.implode(', ', $stale).' — run php artisan atriom:dump-admin-manifest');

    // Full structural equality (slugs, create/edit flags, pages) — catches a
    // resource that gained/lost its Create page without the manifest updating.
    expect($committed)->toEqual($live, 'Smoke manifest is stale — run php artisan atriom:dump-admin-manifest and commit the result.');
});

it('every admin resource in the manifest has a non-empty slug', function () {
    $committed = json_decode(file_get_contents(base_path(DumpAdminManifest::PATH)), true);

    foreach ($committed['resources'] as $r) {
        expect($r['slug'])->not->toBeEmpty("Resource {$r['class']} has an empty slug");
    }
});
