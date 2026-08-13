<?php

use App\Filament\Admin\Pages\Dashboard;
use App\Support\ReportCatalogue;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **Every admin page must actually mount.**
 *
 * WHY THIS EXISTS. A pre-staging sweep found that six admin pages — `ArAgingByType`,
 * `ExpirationSchedule`, `OccupancyCost`, `SalesAnalytics`, `WeeklySpend` and `Workflows`, all of
 * them REPORTS — were referenced by no Pest test at all. Their only coverage was the Playwright
 * suite, which is advisory, is not wired into CI (paused by owner decision), and is not run in the
 * ordinary push loop. So a page that threw on mount would have reached staging with nothing
 * reporting it, and the failure mode of a report page is silent: it is not on the path an operator
 * walks daily, so the first person to find it is the person who needed the number.
 *
 * The other gates around these pages check *declaration* — `ReportCatalogueConformanceTest` proves
 * a page is catalogued, `AdminSmokeManifestConformanceTest` proves the E2E manifest lists it,
 * `ScreenGuideConformanceTest` proves it has a guide. None of them proves the page RENDERS. This
 * one does, and it is deliberately the cheapest possible assertion — mount it, expect 200 — because
 * the bug it catches is a fatal on mount, not a subtle one.
 *
 * **Discovery, not a list.** Pages come from `ReportCatalogue::allAdminPages()`, the same source the
 * catalogue gate uses, so a page added tomorrow is covered without anyone remembering this file —
 * which is the whole reason the six were uncovered in the first place.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // The chart + posting map, because `atriom:install` seeds both on the first deploy — this is
    // the shape of a real environment.
    //
    // Worth knowing rather than hiding: WITHOUT them the VAT return page does not degrade, it
    // throws — `No account mapping for role 'vat_payable'`, straight to a 500. An incomplete
    // posting map is a state the system explicitly anticipates (`ConfigurationHealth` has a check
    // for exactly it), and Jawad's real Egyptian chart is still to be loaded, so that is reachable
    // rather than theoretical. Seeding here keeps this test answering "does the page mount"; the
    // fragility is recorded in the sweep notes as its own question.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('mounts every admin page without erroring', function () {
    $pages = collect(ReportCatalogue::allAdminPages())
        // The dashboard is the panel's landing component and is mounted by Filament itself rather
        // than as a standalone Livewire page; every other page under app/Filament/Admin/Pages is
        // a plain Page and mounts the same way.
        ->reject(fn (string $page) => $page === Dashboard::class)
        ->values();

    // The discovery must find something. A sweep that silently matched zero pages would pass
    // for ever while covering nothing — the exact failure this project has hit before.
    expect($pages->count())->toBeGreaterThan(20);

    $failed = [];

    foreach ($pages as $page) {
        try {
            Livewire::test($page)->assertOk();
        } catch (Throwable $e) {
            $failed[] = class_basename($page).' — '.str($e->getMessage())->limit(160);
        }
    }

    expect($failed)->toBe([], implode("\n", array_merge(
        ['These admin pages did not mount:'],
        $failed,
    )));
});
