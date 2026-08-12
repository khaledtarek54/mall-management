<?php

use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\ListMaintenanceWorkOrders;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Optional grouping on the registers where a second axis is the actual question.
 *
 * Grouping is OFFERED, never applied: no table declares `->defaultGroup()`, so a list arrives
 * ungrouped and the operator picks an axis from the toolbar. A list that silently arrives grouped
 * reads as broken to whoever did not choose it.
 *
 * Worth a test rather than trusting the declaration, because the failure is quiet in a specific
 * way: `Group::make('tenant.name')` names a RELATION path, and a renamed relation does not throw —
 * Filament renders the group control and every row falls into one nameless bucket. So each case
 * asserts the group is registered AND that selecting it still returns rows.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->user = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
    $this->actingAs($this->user);

    $this->asset = Asset::where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();
});

it('offers the grouping and still returns rows when it is selected', function (string $page, string $group) {
    asTenant($this->asset, function () use ($page, $group) {
        $test = Livewire::test($page)->assertOk();

        // assertContains, not expect()->toContain(): Pest's toContain is VARIADIC, so a second
        // argument is read as another needle rather than as a failure message — the assertion
        // then passes or fails for a reason that has nothing to do with the message.
        $this->assertContains(
            $group,
            collect($test->instance()->getTable()->getGroups())->keys()->all(),
            "the {$group} grouping is not registered on this table",
        );

        $test->set('tableGrouping', $group)->assertOk();

        $this->assertGreaterThan(
            0,
            $test->instance()->getTableRecords()->count(),
            "grouping by {$group} returned no rows — the relation path is probably wrong",
        );
    });
})->with([
    // Collections: what one tenant owes in total, and the ageing split.
    [ListInvoices::class, 'tenant.name'],
    // Reconciliation: cash, transfers and cheques land in different places.
    [ListPayments::class, 'method'],
    // How a leasing manager physically walks the mall.
    [ListUnits::class, 'floor.name'],
    // The dispatcher's board.
    [ListMaintenanceWorkOrders::class, 'status'],
    // The axis the owner's cost report is built on.
    [ListExpenses::class, 'category'],
    // The AP conversation: what we owe this vendor across all its bills.
    [ListVendorBills::class, 'vendor.name'],
]);

it('never applies a grouping the operator did not choose', function () {
    // The whole basis for adding this: it is additive. If a table ever declares a default group,
    // every operator's list changes shape overnight with no action of theirs.
    asTenant($this->asset, function () {
        foreach ([
            ListInvoices::class,
            ListPayments::class,
            ListUnits::class,
            ListMaintenanceWorkOrders::class,
            ListExpenses::class,
            ListVendorBills::class,
        ] as $page) {
            $this->assertNull(
                Livewire::test($page)->instance()->getTable()->getDefaultGroup(),
                class_basename($page).' arrives pre-grouped',
            );
        }
    });
});
