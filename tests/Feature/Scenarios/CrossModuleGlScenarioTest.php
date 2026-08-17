<?php

use App\Models\Custody;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SettleCustodyService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The integration test: every new GL-posting module (fixed assets, inventory, employee
 * advances, custodies) plus AR (invoice) and AP (vendor bill) baselines are posted
 * together through the real `accounting:sync-ledger --all` sweep — and the AR/AP tie-out
 * that gates monthly close, plus the trial balance, still hold. This is where a
 * cross-module leak (e.g. anything wrongly touching AR/AP) would surface.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

it('posts every module together and keeps the AR/AP tie-out + trial balance intact', function () {
    // --- AR baseline: an issued invoice.
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    // --- AP baseline: an approved vendor bill.
    VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 4000, 'vat_amount' => 0, 'total' => 4000, 'balance' => 4000,
    ]);

    // --- Fixed asset: acquire → depreciate a month → dispose (loss).
    $fa = FixedAsset::create([
        'asset_id' => makeAsset()->id, 'name' => 'HVAC', 'tag' => 'FA-X', 'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000, 'salvage_value' => 0, 'useful_life_months' => 12, 'method' => 'straight_line', 'funded_from' => 'bank',
    ]);
    app(DepreciationService::class)->run(CarbonImmutable::parse($fa->acquisition_date));
    app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    // --- Inventory: receipt + consumption.
    $invAsset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $invAsset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'X1', 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 20]);
    app(StockMovementService::class)->receive($warehouse, $item, 50, 20);
    app(StockMovementService::class)->adjust($warehouse, $item, -3, ['unit_cost' => 20]);

    // --- Employee advance + repayment.
    $emp = Employee::create([
        'asset_id' => makeAsset()->id, 'code' => 'E9', 'name' => 'Staff', 'hire_date' => now()->startOfYear()->toDateString(),
        'base_salary' => 8000, 'payment_method' => 'bank',
    ]);
    $adv = app(GrantEmployeeAdvanceService::class)->grant($emp, ['amount' => 2000, 'advance_date' => now()->toDateString(), 'paid_from' => 'cash']);
    app(RecordAdvanceRepaymentService::class)->record($adv, ['amount' => 500, 'repaid_on' => now()->toDateString(), 'method' => 'cash']);

    // --- Custody grant + expense settlement.
    $custody = app(GrantCustodyService::class)->grant($emp, ['amount' => 1500, 'custody_date' => now()->toDateString(), 'paid_from' => 'bank']);
    app(SettleCustodyService::class)->settle($custody, ['type' => 'expense', 'amount' => 900, 'transaction_date' => now()->toDateString(), 'category' => 'cleaning_security']);

    // --- Post EVERYTHING through the real sweep.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    // The AR/AP tie-out that gates monthly close holds — nothing new leaked into AR/AP.
    $recon = app(BooksReconciliationService::class)->run();
    $gl = collect($recon['checks'])->firstWhere('key', 'gl_tie_out');
    expect($gl['passed'])->toBeTrue();

    $tie = app(BooksReconciliationService::class)->glTieOut();
    expect($tie['ar']['delta'])->toBe(0.0);
    expect($tie['ap']['delta'])->toBe(0.0);
    expect($tie['ap']['gl'])->toBe(4000.0); // AP = just the vendor bill (inventory/custody/advance never touched AP)

    // The whole trial balance ties out.
    $tb = app(LedgerReportService::class)->trialBalance();
    expect($tb['balanced'])->toBeTrue();
    expect($tb['total_debit'])->toEqualWithDelta($tb['total_credit'], 0.001);
});
