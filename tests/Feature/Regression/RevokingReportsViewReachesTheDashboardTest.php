<?php

/**
 * Revoking `reports.view` closed the Reports pages and left the dashboard publishing the same
 * receivables.
 *
 * `Pages\ArAging::canAccess()` has always required `reports.view`. The dashboard did not: the
 * `ArAging` chart gated on the widget registry alone, and `MallStats` hid its collections and
 * outstanding-AR stats behind `DashboardLayout::seesMoney()`, which asked only for a MONEY_ROLES
 * role. Both draw from the same `ReportService::arAgingBuckets()` call as the page.
 *
 * So the permission an operator actually reaches for silently did not work on the one screen
 * everybody looks at first. It was LATENT rather than live — all six roles carrying these widgets
 * hold `reports.view`, so the two gates agreed by coincidence, which is precisely what makes a
 * later revocation surprising.
 *
 * Every refusal below is paired with a control: a gate that hid the widget from everyone would
 * satisfy the refusals alone and read as a pass.
 */

use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\MallStats;
use App\Support\DashboardLayout;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Sign in as a role and scope the panel to the fixture property. */
function dashUser(string $role): void
{
    test()->actingAs(makeUser($role, [test()->asset->id]));
    Filament::setTenant(test()->asset);
}

/* ---- the control: it works for someone who holds the permission ---------- */

it('shows the AR chart and the money stats to accounting', function () {
    dashUser('accounting');

    expect(auth()->user()->can('reports.view'))->toBeTrue()
        ->and(DashboardLayout::seesMoney())->toBeTrue()
        ->and(ArAging::canView())->toBeTrue();
});

/* ---- the refusal: revoking the permission now reaches the dashboard ------ */

it('hides the AR chart once reports.view is revoked', function () {
    dashUser('accounting');

    Role::findByName('accounting')->revokePermissionTo('reports.view');
    auth()->user()->unsetRelation('roles')->forgetCachedPermissions();

    expect(auth()->user()->can('reports.view'))->toBeFalse()
        // Still a MONEY_ROLES role and still on the dashboard registry — the ONLY thing that
        // changed is the permission, which is the point.
        ->and(DashboardLayout::allows(ArAging::class, auth()->user()))->toBeTrue()
        ->and(ArAging::canView())->toBeFalse();
});

it('hides the collections and outstanding-AR stats once reports.view is revoked', function () {
    // MallStats stays visible — occupancy and contractual rent are every operational role's
    // business. It is the two MONEY stats inside it that must go, and they hang off seesMoney().
    dashUser('manager');

    expect(DashboardLayout::seesMoney())->toBeTrue();

    Role::findByName('manager')->revokePermissionTo('reports.view');
    auth()->user()->unsetRelation('roles')->forgetCachedPermissions();

    expect(DashboardLayout::seesMoney())->toBeFalse()
        // The widget itself is NOT money-gated, and must not become so.
        ->and(MallStats::canView())->toBeTrue();
});

/* ---- the two halves are independent ------------------------------------- */

it('still refuses a role that holds reports.view but does not handle money', function () {
    // The other half of the AND. `leasing` needs occupancy and contractual rent, not what the
    // tenants owe — so the permission alone must not open the receivables.
    dashUser('leasing');

    expect(auth()->user()->hasAnyRole(DashboardLayout::MONEY_ROLES))->toBeFalse()
        ->and(DashboardLayout::seesMoney())->toBeFalse();
});

it('agrees with the page it mirrors', function () {
    // The property that was actually broken: the dashboard and the Reports page must answer the
    // same question the same way, before AND after a revocation.
    dashUser('viewer');

    $page = fn () => \App\Filament\Admin\Pages\ArAging::canAccess();

    expect($page())->toBeTrue()->and(ArAging::canView())->toBeTrue();

    Role::findByName('viewer')->revokePermissionTo('reports.view');
    auth()->user()->unsetRelation('roles')->forgetCachedPermissions();

    expect($page())->toBeFalse()->and(ArAging::canView())->toBeFalse();
});

/* ---- the chart is a doorway, not a dead end ------------------------------ */

it('offers a way from the chart to the drill-down that already existed', function () {
    // `ReportService::arAgingDrilldown()` — who owes this and how late — has existed all along, and
    // only Pages\ArAging consumed it. So the dashboard showed a bucket worth millions and offered
    // no way to find out whose it was; the reader had to know the Reports page existed.
    dashUser('accounting');

    $description = (string) (new ArAging)->getDescription();

    expect($description)
        ->toContain(\App\Filament\Admin\Pages\ArAging::getUrl())
        ->toContain(__('admin.widgets.ar_aging.drilldown'))
        // The original wording survives — the link is added, not substituted for the explanation.
        ->toContain(__('admin.widgets.ar_aging.description'));
});

it('does not render the link as escaped markup', function () {
    // getDescription() is typed `string|Htmlable`; returning a plain string would print the anchor
    // tag as visible text. Htmlable is what makes Blade emit it as HTML.
    dashUser('accounting');

    expect((new ArAging)->getDescription())->toBeInstanceOf(\Illuminate\Contracts\Support\Htmlable::class);
});
