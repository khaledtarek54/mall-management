<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Support\ScreenGuides;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * In-app guidance: complete, in BOTH languages, on EVERY screen, and actually reachable.
 *
 * The first version of this rendered `docs/business-model/*.md` into a modal, and was wrong three
 * ways: the docs are English only, so an Arabic operator got English help in an RTL panel; it styled
 * itself with `prose` classes this build does not ship, so it rendered as unspaced raw text; and a
 * whole reference document in a dialogue is not guidance — someone who opens help is stuck on one
 * thing, not looking for a chapter.
 *
 * **Coverage is now asserted, which reverses this file's own previous decision.** It used to say
 * coverage across all ~45 resources was "deliberately not asserted — a registry padded with
 * exemption reasons would be noise". That was right while 13 screens had guides and 68 did not:
 * a gate then would have been 68 exemptions describing an absence. With content written for all 81,
 * an unregistered screen is an omission, so the gate discovers every screen class on disk and fails
 * on one that is neither registered nor EXEMPT — the shape `DeletionPolicy` and `PropertyIsolation`
 * already use.
 *
 * Four things are worth gating, and they fail differently:
 *   A — every screen is classified (the new one forces a decision rather than inheriting silence)
 *   B — every registered screen has all four fields, in both locales
 *   C — the panel is actually MOUNTED (a perfect guide nobody can open helps nobody)
 *   D — the guide really renders
 */
it('A: classifies every screen in both panels', function () {
    $screens = ScreenGuides::discoverScreens();

    // Vacuity guard: a discovery that silently matched nothing would pass everything below it.
    expect(count($screens))->toBeGreaterThan(70);

    $unclassified = array_values(array_filter(
        $screens,
        fn (string $screen): bool => ! ScreenGuides::has($screen) && ! ScreenGuides::isExempt($screen),
    ));

    expect($unclassified)->toBe([], "These screens have no guide and no exemption. Add a guide to\n"
        ."`App\\Support\\ScreenGuides::SCREENS` (+ content in lang/{en,ar}/guides.php), or register\n"
        ."them in EXEMPT with a reason:\n  ".implode("\n  ", $unclassified));
})->group('conformance');

it('A2: keeps the exemption list honest', function () {
    // An EXEMPT entry naming something that is not a screen classifies nothing — it just looks like
    // a decision. Two such entries were written and deleted during this build: the login form and
    // Filament's tenancy registration screen both extend `SimplePage`, not `Page`, so discovery
    // never offers them and exempting them was documenting a filter that already existed.
    $screens = ScreenGuides::discoverScreens();

    // EXEMPT is empty today, so assert the state rather than looping over nothing — a test whose
    // every assertion sits inside a foreach over an empty array passes without checking anything.
    expect(ScreenGuides::EXEMPT)->toBeArray();

    foreach (array_keys(ScreenGuides::EXEMPT) as $exempt) {
        // `assertContains`, not `expect()->toContain($x, $message)` — toContain is VARIADIC, so the
        // message would become a second NEEDLE and the assertion would demand the array contain it
        // too. Inert while EXEMPT is empty, and confusingly wrong the day it is not.
        $this->assertContains(
            $exempt,
            $screens,
            "{$exempt} is exempt from guidance but is not a screen `discoverScreens()` finds — "
            .'so the exemption classifies nothing. Remove it.'
        );
    }

    foreach (ScreenGuides::EXEMPT as $exempt => $reason) {
        expect(trim($reason))->not->toBe('', "{$exempt} is exempt without a stated reason.");
    }
})->group('conformance');

it('B: gives every registered screen a complete guide, in English and Arabic', function () {
    $missing = [];

    foreach (ScreenGuides::SCREENS as $screen => $key) {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $purpose = ScreenGuides::purpose($key);

            // A missing key returns the key path itself — which is how untranslated help reaches
            // production reading "guides.leases.purpose".
            if ($purpose === "guides.{$key}.purpose" || trim($purpose) === '') {
                $missing[] = "{$key}.purpose [{$locale}]";
            }

            foreach (['steps', 'affects', 'rules'] as $field) {
                if (ScreenGuides::{$field}($key) === []) {
                    $missing[] = "{$key}.{$field} [{$locale}]";
                }
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], "Incomplete guides:\n  ".implode("\n  ", $missing));
})->group('conformance');

it('B2: says what changes elsewhere, which is the question nothing else answers', function () {
    // `affects` is the field that earns the panel. A guide that only restates the screen's title is
    // decoration; this asserts every one of them tells the operator what moves downstream.
    foreach (ScreenGuides::SCREENS as $key) {
        expect(ScreenGuides::affects($key))->not->toBeEmpty("'{$key}' does not say what it affects");
    }
})->group('conformance');

it('B3: uses each guide exactly once', function () {
    // Two screens sharing a key means one of them is being described by the other's guide — which
    // reads as a plausible guide rather than as a missing one, so nothing else would catch it.
    $counts = array_count_values(ScreenGuides::SCREENS);
    $shared = array_keys(array_filter($counts, fn (int $n): bool => $n > 1));

    expect($shared)->toBe([], 'These guide keys are claimed by more than one screen: '.implode(', ', $shared));
})->group('conformance');

it('C: mounts the guide on every screen that has one', function () {
    // A STATIC check on purpose. Proving this by rendering would mean booting 81 Livewire
    // components in one file, and Pest parallelises per FILE — that single case would set the floor
    // under the whole suite the way `AllFiltersSweepTest` once did. Test D renders one, to prove the
    // mounting expression this scans for is the one that works.
    $unmounted = [];

    foreach (array_keys(ScreenGuides::SCREENS) as $screen) {
        $file = guideHostFileFor($screen);

        if ($file === null) {
            $unmounted[] = "{$screen} (no page file found)";

            continue;
        }

        if (! str_contains((string) file_get_contents($file), 'GuideAction::for(')) {
            $unmounted[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($unmounted)->toBe([], "These screens have a written guide that no operator can open —\n"
        ."add `GuideAction::for(static::getResource())` (resources) or `GuideAction::for(static::class)`\n"
        ."(pages) to getHeaderActions():\n  ".implode("\n  ", $unmounted));
})->group('conformance');

it('D: renders the guide on both kinds of screen', function () {
    // BOTH kinds, because there are two mounting expressions and test C only greps for them.
    // `static::getResource()` on a resource's list page and `static::class` on a standalone page
    // are different lookups into the registry, and a grep is equally happy with either — so a
    // page-form typo would have shipped green with only the resource case rendered here.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(ListLeases::class)
        ->assertOk()
        ->assertActionVisible('guide');

    Livewire::test(TrialBalance::class)
        ->assertOk()
        ->assertActionVisible('guide');

    Filament::setTenant(null, isQuiet: true);
});

/**
 * Where a screen's header actions live: a resource mounts its guide on its List page, a page on
 * itself.
 */
function guideHostFileFor(string $screen): ?string
{
    $path = app_path(str_replace('\\', '/', substr($screen, strlen('App\\'))));

    if (! str_contains($screen, '\\Resources\\')) {
        return is_file("{$path}.php") ? "{$path}.php" : null;
    }

    $pages = glob(dirname($path).'/Pages/List*.php') ?: [];

    return $pages[0] ?? null;
}
