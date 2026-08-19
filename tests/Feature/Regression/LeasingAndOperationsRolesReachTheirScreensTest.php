<?php

use App\Filament\Admin\Pages\Budget;
use App\Filament\Admin\Pages\ExpirationSchedule;
use App\Filament\Admin\Pages\OccupancyCost;
use App\Filament\Admin\Pages\RentRoll;
use App\Filament\Admin\Resources\Units\UnitResource;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The roles that do the work can open the screens the work needs.
 *
 * **F-06 / F-07, pre-staging QA 2026-08-19**, measured against the running panel as each of the
 * seven demo roles. Every report page gates on the single permission `reports.view`, held by
 * `manager`, `accounting` and **`viewer`** — and not by `leasing`. So the role that creates, renews
 * and terminates leases could not open the **rent roll**, the **expiration schedule** or the
 * **occupancy-cost** report, while a read-only viewer could open all three. A leasing manager's two
 * most-used screens were invisible to them.
 *
 * `operations` held `facility.*`, `areas.*`, `requests.*` and `procurement.*` but not `units.view`,
 * so the role that routes work orders to units could not open the unit register.
 *
 * And `Budget` gated on `settings.manage`, held only by super_admin — the finance lead could not set
 * a budget without one.
 *
 * None of these was a security hole; all three fail closed. They are role-design defects an operator
 * meets on day one, which is exactly the class a permission matrix in a seeder makes easy to miss.
 */
beforeEach(function () {
    // The REAL grants, not `seedRoles()` — that only creates empty role rows, and a role with no
    // permissions would make every assertion below pass or fail for the wrong reason.
    $this->seed(RolesPermissionsSeeder::class);
});

it('lets the leasing role open the leasing reports', function () {
    $leasing = makeUser('leasing');

    $this->actingAs($leasing);

    expect(RentRoll::canAccess())->toBeTrue()
        ->and(ExpirationSchedule::canAccess())->toBeTrue()
        ->and(OccupancyCost::canAccess())->toBeTrue();
});

it('keeps the leasing role out of what is not theirs', function () {
    // The control. Granting `reports.view` must not have widened the role generally — a test that
    // only asserts new access cannot tell a correct grant from a blanket one.
    $leasing = makeUser('leasing');

    $this->actingAs($leasing);

    expect($leasing->can('invoices.create'))->toBeFalse()
        ->and($leasing->can('vendor_bills.approve'))->toBeFalse()
        ->and($leasing->can('units.delete'))->toBeFalse();
});

it('lets operations open the unit register, read-only', function () {
    $operations = makeUser('operations');

    $this->actingAs($operations);

    expect(UnitResource::canViewAny())->toBeTrue()
        // What a unit IS, and how big it is, stays a leasing and valuation fact.
        ->and($operations->can('units.create'))->toBeFalse()
        ->and($operations->can('units.edit'))->toBeFalse();
});

it('lets the finance roles set the budget', function () {
    foreach (['manager', 'accounting'] as $role) {
        $this->actingAs(makeUser($role));

        expect(Budget::canAccess())->toBeTrue("{$role} should be able to set the budget");
    }
});

it('still keeps the budget away from roles with no financial authority', function () {
    foreach (['leasing', 'operations', 'viewer'] as $role) {
        $this->actingAs(makeUser($role));

        expect(Budget::canAccess())->toBeFalse("{$role} should not set the budget");
    }
});
