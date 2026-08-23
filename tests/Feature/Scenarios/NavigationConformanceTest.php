<?php

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Settings\ModulesSettings;
use App\Support\Modules;
use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Support\Facades\Lang;

/**
 * The sidebar is a registry now, so the failure it can have is OMISSION.
 *
 * `App\Support\Navigation` replaced Filament's auto-assembly with an explicit builder. That fixed a
 * group thirteen pages referenced and the panel never declared, three screens in no group at all,
 * and fifteen colliding sort integers — and it introduced one hazard those never had: a screen the
 * registry does not mention is not merely mis-sorted, it is GONE, with no error anywhere and no way
 * for the person who added it to notice. Filament would have placed it somewhere wrong; this places
 * it nowhere.
 *
 * So the gate discovers every resource and page from the PANEL rather than from the registry — the
 * same independence rule the catalogue-widening gate follows, because a check that reads only the
 * registry it guards cannot see what the registry omits.
 *
 * Then it RENDERS. Reading `GROUPS` proves what the file says; only building the navigation proves
 * what an operator gets, and the two came apart once already: `getNavigationItems()` builds an item
 * unconditionally, and the permission/module refusals live in `registerNavigationItems()`, which a
 * custom builder never calls. Spliced in naively, every screen rendered for every role.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Every screen the admin panel registers, discovered from the panel and not from the registry. */
function allAdminScreens(): array
{
    $panel = Filament::getPanel('admin');

    return array_values(array_merge(
        array_values($panel->getResources()),
        array_values($panel->getPages()),
    ));
}

/**
 * The sidebar as an operator of this role sees it: group label => item labels, in order.
 *
 * @return array<string, array<int, string>>
 */
function renderedSidebarFor(string $role): array
{
    $asset = makeAsset();
    $user = makeUser($role, [$asset->id]);
    test()->actingAs($user);
    Filament::setTenant($asset, isQuiet: true);

    $sidebar = [];

    foreach (Filament::getPanel('admin')->getNavigation() as $group) {
        /** @var NavigationGroup $group */
        $sidebar[$group->getLabel() ?? '(top level)'] = array_values(array_map(
            fn ($item): string => $item->getLabel(),
            (array) $group->getItems(),
        ));
    }

    return $sidebar;
}

it('places every admin screen, and places none that does not exist', function () {
    $screens = allAdminScreens();
    $placed = Navigation::placed();

    $unplaced = array_values(array_diff($screens, $placed, array_keys(Navigation::EXEMPT)));
    $stale = array_values(array_diff($placed, $screens));

    expect($screens)->not->toBeEmpty(); // the sweep must have found something before it reports

    expect($unplaced)->toBe([], 'Not in App\Support\Navigation::GROUPS or ::TOP_LEVEL, so INVISIBLE '
        ."in the sidebar:\n  ".implode("\n  ", $unplaced)
        ."\nAdd each to a group (or to EXEMPT with the reason it is reachable another way).");

    expect($stale)->toBe([], "Registered in App\Support\Navigation but no longer a panel screen:\n  "
        .implode("\n  ", $stale));
});

it('places every screen exactly once', function () {
    $duplicated = array_keys(array_filter(
        array_count_values(Navigation::placed()),
        fn (int $times): bool => $times > 1,
    ));

    expect($duplicated)->toBe([], "Placed in more than one group — the sidebar would list it twice:\n  "
        .implode("\n  ", $duplicated));
});

it('carries no stale exemption', function () {
    $screens = allAdminScreens();

    // Empty today, and asserted rather than assumed: every admin screen is in the sidebar. The loop
    // below has nothing to iterate in that state, and a test whose body never runs reports green
    // for the wrong reason — the shape this project has been bitten by three times.
    expect(Navigation::EXEMPT)->toBeArray();

    foreach (Navigation::EXEMPT as $screen => $reason) {
        expect($screens)->toContain($screen, "{$screen} is exempted from the sidebar but is not a panel screen.");
        expect(strlen($reason))->toBeGreaterThan(30, "The exemption for {$screen} does not say why. "
            .'"Not needed" is the shape of a reason nobody can review — say what reaches this screen instead.');
    }
});

it('labels every group in both languages', function () {
    foreach (array_keys(Navigation::GROUPS) as $key) {
        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` is load-bearing: Lang::has() falls back to English by default, so
            // the obvious spelling of this check only ever catches a key missing from BOTH.
            expect(Lang::has("admin.groups.{$key}", $locale, fallback: false))
                ->toBeTrue("admin.groups.{$key} is missing from lang/{$locale}.");
        }
    }

    foreach (array_keys(Navigation::GROUPS) as $key) {
        expect(array_key_exists($key, Navigation::ICONS))->toBeTrue("Group '{$key}' has no icon. A "
            .'collapsed sidebar renders the group icon and nothing else, so a group without one is '
            .'an unlabelled blank the operator has to expand to identify.');
    }
});

it('renders every screen a super_admin may open', function () {
    $sidebar = renderedSidebarFor('super_admin');

    $rendered = array_merge(...array_values($sidebar));

    expect(count($rendered))->toBe(
        count(Navigation::placed()),
        'A super_admin should see every placed screen. Rendered '.count($rendered).' of '
            .count(Navigation::placed()).".\n".json_encode($sidebar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    // Group ORDER is the registry's order, minus any group this role has nothing in.
    $expectedOrder = array_values(array_map(
        fn (string $key): string => __("admin.groups.{$key}"),
        array_keys(Navigation::GROUPS),
    ));

    expect(array_values(array_slice(array_keys($sidebar), 1)))->toBe($expectedOrder);
    expect(array_key_first($sidebar))->toBe('(top level)');
});

it('still refuses a screen the role has no permission for', function () {
    $rendered = array_merge(...array_values(renderedSidebarFor('marketing')));

    // The control FIRST. A refusal test passes just as happily when the sidebar rendered NOTHING,
    // which is precisely the failure a registry-driven builder can have.
    expect($rendered)->toContain(MarketingBudgetResource::getNavigationLabel());
    expect(count($rendered))->toBeGreaterThan(2);

    // The marketing role holds no users/roles rights at all.
    expect($rendered)->not->toContain(UserResource::getNavigationLabel());
    expect($rendered)->not->toContain(RoleResource::getNavigationLabel());
});

it('still refuses a screen whose module is switched off', function () {
    $with = array_merge(...array_values(renderedSidebarFor('super_admin')));
    expect($with)->toContain(CamExpensePoolResource::getNavigationLabel());

    app(ModulesSettings::class)->fill(['cam' => false])->save();
    app()->forgetInstance(ModulesSettings::class);

    $without = array_merge(...array_values(renderedSidebarFor('super_admin')));

    expect(Modules::enabled('cam'))->toBeFalse();
    expect($without)->not->toContain(CamExpensePoolResource::getNavigationLabel());
    expect(count($without))->toBeLessThan(count($with));
});

it('leaves no screen declaring its own group or sort', function () {
    // The registry is the ONE place navigation order is decided. A `getNavigationGroup()` left
    // behind on a screen is inert under the builder — which is exactly what makes it dangerous:
    // it reads as the truth, and the sidebar says otherwise.
    $offenders = [];

    foreach (filamentSources() as $file) {
        if (! str_contains($file, '/Filament/Admin/')) {
            continue; // the PORTAL panel still uses Filament's own auto-assembly
        }

        $source = file_get_contents($file);

        if (str_contains($source, 'getNavigationGroup') || str_contains($source, '$navigationSort')
            || str_contains($source, '$navigationGroup')) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($offenders)->toBe([], "These declare a navigation group or sort that App\Support\Navigation "
        ."now decides, so the declaration is inert and contradicts the sidebar:\n  ".implode("\n  ", $offenders));
});
