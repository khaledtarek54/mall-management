<?php

/**
 * Table footer totals + the filters added alongside them.
 *
 * These summarizers are not decorative: three of them (inventory stock value,
 * custody outstanding, fixed-asset NBV) are computed in SQL against the DERIVED
 * table Filament hands `Summarizer::using()` — the resource query wrapped as a
 * subquery — not against real columns. That is exactly the kind of thing that
 * renders fine and returns a wrong number, so every one is asserted against a
 * hand-computed figure rather than merely "the page loads".
 *
 * Helpers are prefixed `tsf` because Pest hoists these into the global
 * namespace: a bare makeEmployee()/makeFixedAsset() here would fatally
 * redeclare the ones in EmployeeResourceTest / FixedAssetResourceTest, and
 * --parallel hides that until the two land in the same worker.
 */

use App\Filament\Admin\Resources\Custodies\Pages\ListCustodies;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Admin\Resources\InventoryItems\Pages\ListInventoryItems;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepreciationEntry;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/**
 * Stock on hand is NOT a column — it is the resource's property-scoped
 * withSum('movements') alias. Writing `on_hand` on the item persists nothing and
 * leaves every quantity at 0, so stock has to arrive the way it does in the app:
 * a warehouse in this property, and receipt movements into it.
 */
function tsfStockedItem(int $assetId, string $sku, float $unitCost, float $onHand, float $reorderLevel): InventoryItem
{
    $warehouse = Warehouse::create([
        'asset_id' => $assetId,
        'name' => "Store {$sku}",
        'code' => 'WH-'.uniqid(),
        'is_active' => true,
    ]);

    $item = InventoryItem::create([
        'sku' => $sku,
        'name' => "Item {$sku}",
        'unit' => 'pc',
        'unit_cost' => $unitCost,
        'reorder_level' => $reorderLevel,
        'is_active' => true,
    ]);

    StockMovement::create([
        'warehouse_id' => $warehouse->id,
        'inventory_item_id' => $item->id,
        'type' => 'receipt',
        'quantity' => $onHand,
        'unit_cost' => $unitCost,
        'moved_on' => '2026-01-05',
    ]);

    return $item;
}

function tsfEmployee(int $assetId, array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'asset_id' => $assetId,
        'code' => 'E-'.uniqid(),
        'name' => 'Staffer',
        'hire_date' => '2026-01-01',
        'base_salary' => 5000,
        'payment_method' => 'bank',
        'status' => 'active',
    ], $attrs));
}

/* ---- Inventory: stock value ---------------------------------------------- */

it('totals inventory stock value as Σ(on_hand × unit_cost), not Σ(unit_cost)', function () {
    $asset = makeAsset();

    // 10 × 25.00 = 250.00 and 4 × 12.50 = 50.00 → 300.00.
    // A naive sum of unit_cost would give 37.50, so this pins the multiplication.
    tsfStockedItem($asset->id, 'F-1', unitCost: 25.00, onHand: 10, reorderLevel: 2);
    tsfStockedItem($asset->id, 'L-1', unitCost: 12.50, onHand: 4, reorderLevel: 8);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListInventoryItems::class)
            ->assertTableColumnSummarySet('stock_value', 'total', 300.0);
    });
});

it('narrows the inventory list — and its stock-value total — to items at or below reorder level', function () {
    $asset = makeAsset();

    // on_hand 10 > reorder 2 → healthy. on_hand 4 <= reorder 8 → low.
    tsfStockedItem($asset->id, 'F-1', unitCost: 25.00, onHand: 10, reorderLevel: 2);
    $low = tsfStockedItem($asset->id, 'L-1', unitCost: 12.50, onHand: 4, reorderLevel: 8);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($low) {
        Livewire::test(ListInventoryItems::class)
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$low])
            ->assertCountTableRecords(1)
            // The footer must follow the filter, not report the whole catalogue.
            ->assertTableColumnSummarySet('stock_value', 'total', 50.0);
    });
});

/* ---- Employees: base payroll --------------------------------------------- */

it('totals base salary across the filtered roster', function () {
    $asset = makeAsset();
    tsfEmployee($asset->id, ['base_salary' => 5000]);
    tsfEmployee($asset->id, ['base_salary' => 7500]);
    tsfEmployee($asset->id, ['base_salary' => 9000, 'status' => 'terminated', 'terminated_on' => '2026-06-30']);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        // Unfiltered: the whole roster, terminated included — the status filter is
        // deliberately NOT defaulted, so nothing is hidden on first load.
        Livewire::test(ListEmployees::class)
            ->assertCountTableRecords(3)
            ->assertTableColumnSummarySet('base_salary', 'total', 21500.0)
            // Filtered to active: the total must drop with the rows.
            ->filterTable('status', 'active')
            ->assertCountTableRecords(2)
            ->assertTableColumnSummarySet('base_salary', 'total', 12500.0);
    });
});

/* ---- Custody: outstanding ------------------------------------------------ */

it('totals custody outstanding per row before summing, so an over-settled عهدة cannot mask another', function () {
    $asset = makeAsset();
    $employee = tsfEmployee($asset->id);

    // A: advanced 1000, settled 400 → 600 outstanding.
    $a = Custody::create(['employee_id' => $employee->id, 'asset_id' => $asset->id, 'reference' => 'C-A', 'amount' => 1000, 'custody_date' => '2026-01-05', 'paid_from' => 'cash']);
    CustodyTransaction::create(['custody_id' => $a->id, 'asset_id' => $asset->id, 'type' => 'expense', 'amount' => 400, 'transaction_date' => '2026-01-10']);

    // B: advanced 500, settled 800 (over-settled) → clamps to 0, NOT −300.
    $b = Custody::create(['employee_id' => $employee->id, 'asset_id' => $asset->id, 'reference' => 'C-B', 'amount' => 500, 'custody_date' => '2026-01-06', 'paid_from' => 'cash']);
    CustodyTransaction::create(['custody_id' => $b->id, 'asset_id' => $asset->id, 'type' => 'expense', 'amount' => 800, 'transaction_date' => '2026-01-11']);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListCustodies::class)
            ->assertTableColumnSummarySet('amount', 'total', 1500.0)
            ->assertTableColumnSummarySet('settled_sum', 'total', 1200.0)
            // 600 + 0. A naive Σ(amount) − Σ(settled) would report 300 and
            // understate what is actually still out with staff.
            ->assertTableColumnSummarySet('outstanding', 'total', 600.0);
    });
});

it('excludes soft-deleted settlements from settled + outstanding, and from the outstanding-only filter', function () {
    $asset = makeAsset();
    $employee = tsfEmployee($asset->id);

    $custody = Custody::create(['employee_id' => $employee->id, 'asset_id' => $asset->id, 'reference' => 'C-1', 'amount' => 1000, 'custody_date' => '2026-01-05', 'paid_from' => 'cash']);
    $txn = CustodyTransaction::create(['custody_id' => $custody->id, 'asset_id' => $asset->id, 'type' => 'expense', 'amount' => 1000, 'transaction_date' => '2026-01-10']);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    // Fully settled → nothing outstanding, and off the chase list.
    asTenant($asset, function () {
        Livewire::test(ListCustodies::class)
            ->assertTableColumnSummarySet('outstanding', 'total', 0.0)
            ->filterTable('outstanding_only')
            ->assertCountTableRecords(0);
    });

    // Reversing the settlement (soft delete) must put the money back on the books.
    $txn->delete();

    asTenant($asset, function () use ($custody) {
        Livewire::test(ListCustodies::class)
            ->assertTableColumnSummarySet('settled_sum', 'total', 0.0)
            ->assertTableColumnSummarySet('outstanding', 'total', 1000.0)
            ->filterTable('outstanding_only')
            ->assertCanSeeTableRecords([$custody]);
    });
});

/* ---- Fixed assets: cost / accumulated / NBV ------------------------------ */

it('totals fixed-asset cost, accumulated depreciation and net book value consistently', function () {
    $asset = makeAsset();

    $a = FixedAsset::create(['asset_id' => $asset->id, 'name' => 'Chiller', 'tag' => 'FA-A', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 12000, 'salvage_value' => 0, 'useful_life_months' => 12, 'method' => 'straight_line', 'funded_from' => 'cash']);
    $b = FixedAsset::create(['asset_id' => $asset->id, 'name' => 'Generator', 'tag' => 'FA-B', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 8000, 'salvage_value' => 0, 'useful_life_months' => 8, 'method' => 'straight_line', 'funded_from' => 'cash']);

    DepreciationEntry::create(['fixed_asset_id' => $a->id, 'period_month' => '2026-01-01', 'amount' => 1000]);
    DepreciationEntry::create(['fixed_asset_id' => $a->id, 'period_month' => '2026-02-01', 'amount' => 1000]);
    DepreciationEntry::create(['fixed_asset_id' => $b->id, 'period_month' => '2026-01-01', 'amount' => 1000]);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        Livewire::test(ListFixedAssets::class)
            ->assertTableColumnSummarySet('acquisition_cost', 'total', 20000.0)
            ->assertTableColumnSummarySet('accumulated', 'total', 3000.0)
            // NBV must tie out: 20,000 − 3,000. This is the balance-sheet figure.
            ->assertTableColumnSummarySet('net_book_value', 'total', 17000.0);
    });
});

it('surfaces fully-depreciated assets as a write-off worklist', function () {
    $asset = makeAsset();

    $done = FixedAsset::create(['asset_id' => $asset->id, 'name' => 'Old lift', 'tag' => 'FA-OLD', 'acquisition_date' => '2025-01-01', 'acquisition_cost' => 5000, 'salvage_value' => 0, 'useful_life_months' => 10, 'method' => 'straight_line', 'funded_from' => 'cash']);
    $running = FixedAsset::create(['asset_id' => $asset->id, 'name' => 'New lift', 'tag' => 'FA-NEW', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 5000, 'salvage_value' => 0, 'useful_life_months' => 10, 'method' => 'straight_line', 'funded_from' => 'cash']);

    DepreciationEntry::create(['fixed_asset_id' => $done->id, 'period_month' => '2025-01-01', 'amount' => 5000]);
    DepreciationEntry::create(['fixed_asset_id' => $running->id, 'period_month' => '2026-01-01', 'amount' => 500]);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($done) {
        Livewire::test(ListFixedAssets::class)
            ->filterTable('fully_depreciated')
            ->assertCanSeeTableRecords([$done])
            ->assertCountTableRecords(1);
    });
});
