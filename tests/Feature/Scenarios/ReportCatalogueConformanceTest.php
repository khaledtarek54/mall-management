<?php

use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Pages\ReportHub;
use App\Filament\Admin\Pages\VatReturn;
use App\Support\ReportCatalogue;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A report nobody can find is a report nobody has.
 *
 * Nineteen reports were scattered across five sidebar groups with nothing listing them. The hub is
 * the index; this gate is what keeps it complete, because the person adding the twentieth report
 * will not know `App\Support\ReportCatalogue` exists and their screen will work perfectly while
 * being reachable only by URL.
 *
 * Same arrangement `SearchPolicy` puts on resources and `PropertyIsolation` on models: classify or
 * exempt, and an unclassified one fails the build.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('classifies every admin page as a report or not', function () {
    $unclassified = collect(ReportCatalogue::allAdminPages())
        ->reject(fn (string $page) => array_key_exists($page, ReportCatalogue::REPORTS)
            || array_key_exists($page, ReportCatalogue::EXEMPT)
            || $page === ReportHub::class)
        ->values()
        ->all();

    expect($unclassified)->toBe([], implode("\n", array_merge(
        ['These admin pages are neither catalogued as reports nor exempted:'],
        $unclassified,
        ['', 'Add them to App\Support\ReportCatalogue::REPORTS so operators can find them,'],
        ['or to ::EXEMPT with a reason why the page is not a report.'],
    )));
});

it('registers only pages that exist, and only known categories', function () {
    $stale = [];

    foreach (ReportCatalogue::REPORTS as $page => $meta) {
        if (! class_exists($page)) {
            $stale[] = "{$page} no longer exists";
        }

        if (! in_array($meta['category'], ReportCatalogue::CATEGORIES, true)) {
            $stale[] = "{$page} is in unknown category '{$meta['category']}'";
        }
    }

    foreach (array_keys(ReportCatalogue::EXEMPT) as $page) {
        if (! class_exists($page)) {
            $stale[] = "{$page} is exempted but no longer exists";
        }
    }

    expect($stale)->toBe([], implode("\n", $stale));
});

it('describes every report in English and Arabic', function () {
    // The description is what earns the hub — a list of nineteen titles is a menu, and "AR ageing"
    // versus "AR ageing by charge type" are indistinguishable as titles and obvious as sentences.
    // An untranslated key reaches production reading "admin.report_hub.descriptions.rent_roll".
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach (ReportCatalogue::REPORTS as $page => $meta) {
            $key = "admin.report_hub.descriptions.{$meta['key']}";

            if (__($key) === $key || trim(__($key)) === '') {
                $missing[] = "{$meta['key']} [{$locale}]";
            }
        }

        foreach (ReportCatalogue::CATEGORIES as $category) {
            $key = "admin.report_hub.categories.{$category}";

            if (__($key) === $key) {
                $missing[] = "category {$category} [{$locale}]";
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], 'Undescribed reports: '.implode(', ', $missing));
})->group('i18n');

it('gives every exemption a reason', function () {
    $unreasoned = collect(ReportCatalogue::EXEMPT)
        ->filter(fn (string $reason) => strlen(trim($reason)) < 40)
        ->keys()
        ->all();

    expect($unreasoned)->toBe([], 'These exemptions need a real reason: '.implode(', ', $unreasoned));
});

it('lists a report exactly when the operator can open it', function () {
    // Access is the page's own answer, asked rather than duplicated. A permission copied into the
    // catalogue would drift, and the drift shows up as either a link that refuses or a report the
    // operator never learns exists.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    $all = collect(ReportCatalogue::visibleTo())->flatten(1)->pluck('page')->all();

    expect($all)->toContain(VatReturn::class)
        ->and($all)->toContain(RentRoll::class);

    // A role with no accounting rights must not be shown the VAT return.
    $this->actingAs(makeUser('marketing'));
    $marketing = collect(ReportCatalogue::visibleTo())->flatten(1)->pluck('page')->all();

    expect($marketing)->not->toContain(VatReturn::class);

    Filament::setTenant(null, isQuiet: true);
});

it('renders the hub', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(ReportHub::class)
        ->assertOk()
        // The catalogue reached the screen — a hub that renders an empty table is the failure this
        // whole feature exists to prevent, and it looks identical to a working one.
        ->assertSee(__('admin.report_hub.descriptions.rent_roll'));

    Filament::setTenant(null, isQuiet: true);
});
