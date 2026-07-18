<?php

use App\Models\InventoryItem;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\StockMovementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * End-to-end inventory lifecycle: receive → consume on a ticket → adjust, with derived
 * on-hand and perpetual-inventory GL that keeps the AP tie-out intact (receipts credit
 * GRNI, never the AP control).
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->stock = app(StockMovementService::class);
    $this->poster = app(LedgerPoster::class);
    $this->report = app(LedgerReportService::class);
});

function invClosing(string $code): float
{
    $a = LedgerAccount::where('code', $code)->first();

    return round((float) app(LedgerReportService::class)->accountLedger($a)['closing'], 2);
}

it('runs receive → consume → adjust with derived on-hand and perpetual-inventory GL', function () {
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Main Store', 'code' => 'MS1']);
    $item = InventoryItem::create(['sku' => 'SEAL-1', 'name' => 'Pump Seal', 'unit' => 'each', 'unit_cost' => 25]);
    $ticket = makeTenantRequest(['unit_id' => makeUnit($asset)->id]);

    // Receive 100 @ 25 = 2500.
    $receipt = $this->stock->receive($warehouse, $item, 100, 25);
    expect($this->stock->onHand($item, $warehouse))->toBe(100.0);

    // Consume 10 on the ticket = 250.
    $consume = $this->stock->record([
        'warehouse_id' => $warehouse->id, 'inventory_item_id' => $item->id, 'type' => 'consumption',
        'quantity' => 10, 'unit_cost' => 25,
        'source_type' => $ticket->getMorphClass(), 'source_id' => $ticket->getKey(),
    ]);
    expect($this->stock->onHand($item, $warehouse))->toBe(90.0);

    // Shrinkage adjustment −5 = 125.
    $adjust = $this->stock->adjust($warehouse, $item, -5, ['unit_cost' => 25]);
    expect($this->stock->onHand($item, $warehouse))->toBe(85.0);

    // Post every movement.
    foreach ([$receipt, $consume, $adjust] as $m) {
        $this->poster->sync($m->fresh());
    }

    // Inventory asset = 2500 received − 250 consumed − 125 shrinkage = 2125.
    expect(invClosing('11301001'))->toBe(2125.0);
    expect(invClosing('21701001'))->toBe(2500.0);   // GRNI (liability, credit-normal) = the received value
    expect(invClosing('51102001'))->toBe(250.0);    // Maintenance expense (consumption)
    expect(invClosing('51108001'))->toBe(125.0);    // Inventory adjustment (shrinkage)

    $tb = $this->report->trialBalance();
    expect($tb['balanced'])->toBeTrue();
});

it('keeps the GL↔AP tie-out intact — a receipt credits GRNI, never the AP control', function () {
    // A real vendor bill sets the AP baseline.
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'balance' => 3000,
    ]);
    $this->poster->sync($bill->fresh());

    // A large inventory receipt must NOT inflate AP.
    $asset = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Store', 'code' => 'S1']);
    $item = InventoryItem::create(['sku' => 'BOLT-1', 'name' => 'Bolt', 'unit' => 'each', 'unit_cost' => 10]);
    $this->poster->sync($this->stock->receive($warehouse, $item, 500, 10)->fresh()); // 5000 → GRNI

    $tie = app(BooksReconciliationService::class)->glTieOut();
    expect($tie['ap']['delta'])->toBe(0.0);
    expect($tie['ap']['gl'])->toBe(3000.0); // AP is still just the vendor bill
    expect(collect(app(BooksReconciliationService::class)->run()['checks'])->firstWhere('key', 'gl_tie_out')['passed'])->toBeTrue();
});
