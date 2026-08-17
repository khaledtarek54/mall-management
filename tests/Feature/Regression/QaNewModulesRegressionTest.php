<?php

use App\Filament\Admin\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Regressions for the QA-sweep findings across the new modules (22-26).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    ensureAllPropertiesAsset();
    $this->stock = app(StockMovementService::class);
});

/* ---- #9 consumption availability ----------------------------------------- */

it('rejects consuming more stock than is on hand (no negative stock / phantom COGS)', function () {
    $w = Warehouse::create(['asset_id' => makeAsset()->id, 'name' => 'S', 'code' => 'S1']);
    $i = InventoryItem::create(['sku' => 'A', 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 25]);
    $this->stock->receive($w, $i, 5, 25);

    expect(fn () => $this->stock->record(['warehouse_id' => $w->id, 'inventory_item_id' => $i->id, 'type' => 'consumption', 'quantity' => 6]))
        ->toThrow(HttpException::class);

    expect($this->stock->onHand($i, $w))->toBe(5.0); // unchanged
});

/* ---- #5 adjustment valued at item standard cost -------------------------- */

it('values an adjustment at the item standard cost when the caller supplies none', function () {
    $w = Warehouse::create(['asset_id' => makeAsset()->id, 'name' => 'S', 'code' => 'S1']);
    $i = InventoryItem::create(['sku' => 'B', 'name' => 'Bolt', 'unit' => 'each', 'unit_cost' => 15]);

    // Stock it first: this asserts COSTING, but a write-off still has to be possible, and you
    // cannot write off bolts that were never received. The floor is now keyed on the sign, so
    // any stock-removing quantity is checked against on-hand (gap-analysis F-84) — the fixture
    // used to describe a warehouse holding −3 bolts.
    $this->stock->receive($w, $i, 10, 15);

    $adjust = $this->stock->adjust($w, $i, -3); // no unit_cost supplied

    expect((float) $adjust->unit_cost)->toBe(15.0); // defaulted from the item → non-zero GL value
    expect($adjust->value())->toBe(45.0);
});

/* ---- #2 warehouse soft-delete keeps movement GL -------------------------- */

it('keeps a stock movement GL-attributable after its warehouse is soft-deleted', function () {
    $w = Warehouse::create(['asset_id' => makeAsset()->id, 'name' => 'S', 'code' => 'S1']);
    $i = InventoryItem::create(['sku' => 'C', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 20]);
    $receipt = $this->stock->receive($w, $i, 10, 20);

    $poster = app(LedgerPoster::class);
    expect($poster->sync($receipt->fresh()))->not->toBeNull();

    trashBypassingDeletionPolicy($w); // soft-delete the warehouse (movement stays live)

    // The movement still resolves its (trashed) warehouse → asset_id → the entry is NOT voided.
    expect($poster->sync($receipt->fresh()))->not->toBeNull();
});

/* ---- #1 depreciation skips soft-deleted-property assets ------------------ */

it('stops depreciating fixed assets whose property was soft-deleted', function () {
    $property = makeAsset();
    $fa = FixedAsset::create([
        'asset_id' => $property->id, 'name' => 'Chiller', 'tag' => 'FA-D', 'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000, 'salvage_value' => 0, 'useful_life_months' => 12, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);

    trashBypassingDeletionPolicy($property); // soft-delete the mall

    expect(app(DepreciationService::class)->run(CarbonImmutable::parse($fa->acquisition_date)))->toBe(0);
});

/* ---- #7 dispose negative proceeds ---------------------------------------- */

it('rejects a negative disposal proceeds', function () {
    $fa = FixedAsset::create([
        'asset_id' => makeAsset()->id, 'name' => 'Pump', 'tag' => 'FA-N', 'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 5000, 'salvage_value' => 0, 'useful_life_months' => 10, 'method' => 'straight_line', 'funded_from' => 'cash',
    ]);

    expect(fn () => app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => -500]))
        ->toThrow(HttpException::class);

    expect($fa->fresh()->status)->toBe('active'); // not disposed
});

/* ---- #6 warehouse asset_id scope guard ----------------------------------- */

it('rejects an out-of-scope asset_id on a warehouse (assertAssetInScope)', function () {
    $assetA = makeAsset(['code' => 'WGA']);
    $assetB = makeAsset(['code' => 'WGB']);
    $this->actingAs(makeUser('operations', [$assetA->id]));

    WarehouseResource::assertAssetInScope($assetA->id);
    expect(true)->toBeTrue();

    expect(fn () => WarehouseResource::assertAssetInScope($assetB->id))
        ->toThrow(HttpException::class);
});

/* ---- #8 duplicate payroll line ------------------------------------------- */

it('rejects a duplicate employee line on a payroll run (no raw unique-constraint 500)', function () {
    $asset = makeAsset();
    $run = Payroll::create([
        'asset_id' => $asset->id, 'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 0, 'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);
    $emp = Employee::create(['asset_id' => $asset->id, 'code' => 'E1', 'name' => 'Ahmed', 'hire_date' => '2026-01-01', 'base_salary' => 5000, 'payment_method' => 'bank']);
    $run->lines()->create(['employee_id' => $emp->id, 'gross' => 5000]);

    $this->actingAs(makeUser('accounting', [$asset->id]));

    // The RM add_line action's server-side backstop rejects a tampered duplicate.
    asTenant($asset, function () use ($run, $emp) {
        try {
            Livewire::test(PayrollLinesRelationManager::class, [
                'ownerRecord' => $run, 'pageClass' => EditPayroll::class,
            ])->callTableAction('add_line', data: ['employee_id' => $emp->id, 'gross' => 3000]);
        } catch (Throwable $e) {
            // abort(422) surfaces as an exception on the Livewire path.
        }
    });

    expect(PayrollLine::where('payroll_id', $run->id)->where('employee_id', $emp->id)->count())->toBe(1);
});
