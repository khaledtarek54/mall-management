<?php

/*
|--------------------------------------------------------------------------
| Dashboard layout — the self-enforcing gate
|--------------------------------------------------------------------------
| `App\Support\DashboardLayout` is the single registry of what each role's dashboard is. These
| tests are what make it a registry rather than a suggestion, and each one exists because the
| thing it forbids actually happened:
|
|   A. A ROLE WITH NO DASHBOARD. Six of the fifteen roles — owner, marketing, hr, technician,
|      vendor and mall_admin — logged in to a completely blank page, because visibility was
|      declared widget-by-widget and nobody ever read it back by role.
|
|   B. A WIDGET NOBODY DECIDED ABOUT. Filament's `discoverWidgets()` registers everything in the
|      widget directory. `MonthlyCloseStats` shipped with no gate at all, so the property's
|      invoices, collections rate, outstanding AR and every ageing bucket were published to every
|      role on the panel — a marketing user's dashboard opened on the whole AR book. A new widget
|      must now be placed in a layout or named in NOT_ON_DASHBOARD; there is no third state.
|
|   C. MONEY SHOWN TO ROLES THAT DON'T HANDLE MONEY.
|
|   D. A LAYOUT THAT IS CORRECT ON PAPER AND 500s IN A BROWSER. Every test above reads the
|      registry; none of them ever rendered a dashboard. So a widget that throws for one role,
|      or a Blade view that stops compiling, was invisible here — the registry would still be
|      perfectly well-formed. The render smoke at the bottom closes that: it walks every role in
|      LAYOUTS and asks for the page.
*/

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\MonthlyCloseStats;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Support\DashboardLayout;
use Database\Seeders\RolesPermissionsSeeder;

/** Every widget class that exists in the admin widgets directory. */
function allWidgetClasses(): array
{
    return collect(glob(app_path('Filament/Admin/Widgets/*.php')))
        ->map(fn (string $f): string => 'App\\Filament\\Admin\\Widgets\\'.basename($f, '.php'))
        ->filter(fn (string $c): bool => class_exists($c))
        ->values()
        ->all();
}

it('gives every seeded role a dashboard', function () {
    $rolesWithout = [];

    foreach (array_keys(RolesPermissionsSeeder::ROLES) as $role) {
        if (empty(DashboardLayout::LAYOUTS[$role] ?? [])) {
            $rolesWithout[] = $role;
        }
    }

    expect($rolesWithout)->toBe([], implode('', [
        'These roles land on an empty dashboard: '.implode(', ', $rolesWithout).'. ',
        'Add a layout to App\Support\DashboardLayout::LAYOUTS — a role with nothing to look at ',
        'has no reason to open the app.',
    ]));
});

it('has a layout for every role and no layout for a role that does not exist', function () {
    $seeded = array_keys(RolesPermissionsSeeder::ROLES);
    $laidOut = array_keys(DashboardLayout::LAYOUTS);

    expect(array_diff($laidOut, $seeded))->toBe([],
        'DashboardLayout names a role that RolesPermissionsSeeder does not create.');
});

it('accounts for every widget: in a layout, or explicitly kept off the dashboard', function () {
    $placed = DashboardLayout::allWidgets();
    $excluded = array_keys(DashboardLayout::NOT_ON_DASHBOARD);

    $unaccounted = array_values(array_diff(allWidgetClasses(), $placed, $excluded));

    expect($unaccounted)->toBe([], implode('', [
        'These widgets are in neither a role layout nor NOT_ON_DASHBOARD: ',
        implode(', ', array_map('class_basename', $unaccounted)).'. ',
        'Filament auto-discovers every widget in that directory, so an undecided widget is ',
        'published to the whole panel — which is how the monthly-close receivables reached HR.',
    ]));
});

it('never composes a widget that is marked as not-on-dashboard', function () {
    foreach (array_keys(DashboardLayout::NOT_ON_DASHBOARD) as $widget) {
        expect(DashboardLayout::allWidgets())->not->toContain($widget);
    }
});

it('keeps the monthly-close stats behind the same permission as the page it belongs to', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // The bug: no gate at all, so this returned true for everyone.
    $this->actingAs(makeUser('marketing'));
    expect(MonthlyCloseStats::canView())->toBeFalse();

    $this->actingAs(makeUser('hr'));
    expect(MonthlyCloseStats::canView())->toBeFalse();

    $this->actingAs(makeUser('accounting'));
    expect(MonthlyCloseStats::canView())->toBeTrue();
});

it('only lets money-handling roles see the receivables figures', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // Roles whose layout contains an explicitly money-shaped widget must be MONEY_ROLES,
    // otherwise the layout and the MallStats filter would disagree about who handles money.
    $moneyWidgets = [
        ArAging::class,
        RecentPayments::class,
    ];

    $contradictions = [];

    foreach (DashboardLayout::LAYOUTS as $role => $widgets) {
        foreach ($moneyWidgets as $widget) {
            if (in_array($widget, $widgets, true) && ! in_array($role, DashboardLayout::MONEY_ROLES, true)) {
                $contradictions[] = $role.' → '.class_basename($widget);
            }
        }
    }

    expect($contradictions)->toBe([], implode('', [
        'These roles are shown a money widget but are not MONEY_ROLES: ',
        implode(', ', $contradictions).'. ',
        'MallStats would hide its AR figures for them while a chart beside it shows the same money.',
    ]));
});

it('resolves a real user to the widgets their role is laid out with', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $marketing = makeUser('marketing');
    expect(DashboardLayout::widgetsFor($marketing))
        ->toBe(DashboardLayout::LAYOUTS['marketing'])
        ->and(DashboardLayout::seesMoney($marketing))->toBeFalse();

    $accounting = makeUser('accounting');
    expect(DashboardLayout::seesMoney($accounting))->toBeTrue();
});

it('gives a multi-role user the union, ordered by the registry', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $user = makeUser('manager');
    $user->assignRole('accounting');

    $widgets = DashboardLayout::widgetsFor($user);

    // Everything from both layouts...
    foreach ([...DashboardLayout::LAYOUTS['manager'], ...DashboardLayout::LAYOUTS['accounting']] as $w) {
        expect($widgets)->toContain($w);
    }

    // ...each exactly once, and still reading as a manager's dashboard (ActionRequired first).
    expect($widgets)->toBe(array_values(array_unique($widgets)))
        ->and($widgets[0])->toBe(DashboardLayout::LAYOUTS['manager'][0]);
});

it('shows nothing to a guest', function () {
    expect(DashboardLayout::widgetsFor(null))->toBe([]);
});

/**
 * Every role's dashboard actually renders.
 *
 * The registry tests above prove the COMPOSITION is sound — that each role has widgets, that no
 * widget is unclassified, that money stays with money roles. None of them prove the page comes
 * back. A widget whose query blows up for one role, or a custom Blade view that stops compiling,
 * passes all of them and 500s on login.
 *
 * Cheap on purpose: one GET per role against a small shared fixture, no per-role seeding.
 */
it('renders a working dashboard for every role in the registry', function (string $role) {
    // The full catalogue: tests/Pest.php's seedRoles() only creates six roles, and the registry
    // covers fourteen. (Bulk-written, ~11ms — see CLAUDE.md.)
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset();
    $unit = makeUnit($asset, ['status' => 'vacant']);
    $lease = makeLease($unit, makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYear(),
        'expiry_date' => now()->addDays(15),
        'has_percentage_rent' => true,
    ]);
    // Enough money on the books that the AR/collections widgets take their populated path
    // rather than their empty one — an empty dashboard renders far more things than a full one.
    makeInvoice($lease, ['balance' => 1000, 'status' => 'overdue', 'due_date' => now()->subDays(10)]);

    $this->actingAs(makeUser($role, [$asset->id]));

    asTenant($asset, function () use ($asset) {
        $this->get(Dashboard::getUrl(panel: 'admin', tenant: $asset))
            ->assertSuccessful();
    });
})->with(array_keys(DashboardLayout::LAYOUTS));
