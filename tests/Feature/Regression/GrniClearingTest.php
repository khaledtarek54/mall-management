<?php

use App\Models\InventoryItem;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\PurchaseRequestService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\StockMovementService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — a purchase must cost the company its money exactly ONCE.
 *
 * THE BUG (fixed 2026-07-17). A goods receipt posts Dr Inventory / Cr GRNI. A vendor bill posted
 * Dr Expense / Cr AP. Nothing connected them, so buying 500 EGP of stock once left:
 *
 *   Inventory +500, Expense +500, GRNI −500, AP −500
 *
 * The cost recognised twice — once as an asset, once in the P&L — and the liability recorded
 * twice. Every stock purchase whose supplier bill was entered overstated both the P&L and the
 * balance sheet by its full value, and GRNI could never be cleared because nothing knew which
 * bill paid for which receipt. The demo books carried 166,120 EGP of GRNI credits against zero
 * debits. `InventoryMovementJournalizer`'s docblock had called the fix "a future enhancement"
 * since the module shipped.
 *
 * These tests never touch LedgerPoster — they drive the services and the real
 * `accounting:sync-ledger` sweep, the trap that let the SLA penalty ship posting nothing.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->svc = app(PurchaseRequestService::class);
    $this->asset = makeAsset(['code' => 'GRN']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Filter', 'unit' => 'each', 'unit_cost' => 50]);
    $this->vendor = Vendor::create(['name' => 'PartsCo', 'category' => 'hvac', 'status' => 'active']);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->manager = makeUser('manager', [$this->asset->id]);
});

/** Receive $qty × $cost of stock through the real purchase flow. */
function purchased(float $qty = 10, float $cost = 50, array $extraLines = []): PurchaseRequest
{
    $lines = array_merge([['inventory_item_id' => test()->item->id, 'quantity' => $qty, 'unit_cost' => $cost]], $extraLines);

    $r = test()->svc->request([
        'asset_id' => test()->asset->id, 'justification' => 'Stock out.',
        'warehouse_id' => test()->warehouse->id, 'lines' => $lines,
    ], test()->buyer);

    test()->svc->approve($r, null, test()->manager);
    test()->svc->order($r->fresh(), null, 'PO-1', test()->manager);

    return test()->svc->receive($r->fresh(), test()->buyer);
}

/** The supplier's invoice for it. */
function billFor(?PurchaseRequest $pr, float $subtotal, float $vat = 0): VendorBill
{
    return VendorBill::create([
        'vendor_id' => test()->vendor->id, 'asset_id' => test()->asset->id,
        'number' => 'SUP-'.fake()->unique()->numberBetween(1000, 9999),
        'category' => 'maintenance', 'purchase_request_id' => $pr?->id,
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => $subtotal, 'vat_amount' => $vat, 'total' => $subtotal + $vat,
        'status' => 'approved',
    ]);
}

/** Signed balance of an account: debits − credits. */
function bal(string $code): float
{
    $account = LedgerAccount::where('code', $code)->first();

    if (! $account) {
        return 0.0;
    }

    $lines = JournalLine::where('ledger_account_id', $account->id)
        ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->get();

    return round($lines->sum(fn ($l) => (float) $l->debit) - $lines->sum(fn ($l) => (float) $l->credit), 2);
}

const GRNI = '21701001';
const INVENTORY = '11301001';
const RM_EXPENSE = '51102001';

it('costs the company its money exactly once', function () {
    // The headline. Before the fix: Inventory 500 AND Expense 500 — the same 500, twice.
    $pr = purchased(10, 50);
    billFor($pr, 500);

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(INVENTORY))->toBe(500.0);   // we own the stock…
    expect(bal(RM_EXPENSE))->toBe(0.0);    // …and have NOT expensed it. It isn't used yet.
    expect(bal(GRNI))->toBe(0.0);          // the clearing account did its job and closed
    expect(bal('21101001'))->toBe(-500.0); // we owe the supplier 500, once
});

it('clears GRNI to exactly zero for the goods it received', function () {
    // The 166,120 EGP problem, in miniature: a GRNI credit with no way back to a debit.
    $pr = purchased(10, 50);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);
    expect(bal(GRNI))->toBe(-500.0); // receipt only: we hold the goods, awaiting the invoice

    billFor($pr, 500);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(GRNI))->toBe(0.0);
});

it('recognises the expense only when the stock is actually used', function () {
    // The point of perpetual inventory: cost follows consumption, not purchase.
    $pr = purchased(10, 50);
    billFor($pr, 500);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);
    expect(bal(RM_EXPENSE))->toBe(0.0);

    app(StockMovementService::class)->record([
        'warehouse_id' => $this->warehouse->id, 'inventory_item_id' => $this->item->id,
        'type' => 'consumption', 'quantity' => 4, 'unit_cost' => 50,
    ]);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(RM_EXPENSE))->toBe(200.0); // 4 × 50, when used
    expect(bal(INVENTORY))->toBe(300.0);  // and out of stock
    expect(bal(GRNI))->toBe(0.0);         // still closed
});

it('leaves an ordinary service bill entirely as expense', function () {
    // Most bills have no purchase behind them. Nothing about them may change.
    billFor(null, 4000);

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(RM_EXPENSE))->toBe(4000.0);
    expect(bal(GRNI))->toBe(0.0);
    expect(bal('21101001'))->toBe(-4000.0);
});

it('splits a bill that covers goods and labour', function () {
    // 500 of filters (already in stock) + 300 callout on one invoice: the goods clear GRNI, the
    // labour is a real expense now.
    $pr = purchased(10, 50, [['description' => 'Callout fee', 'quantity' => 1, 'unit_cost' => 300]]);
    billFor($pr, 800);

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(GRNI))->toBe(0.0);
    expect(bal(RM_EXPENSE))->toBe(300.0); // the labour only
    expect(bal(INVENTORY))->toBe(500.0);
});

it('keeps VAT recoverable and the payable whole', function () {
    $pr = purchased(10, 50);
    billFor($pr, 500, 70); // 14% on 500

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(GRNI))->toBe(0.0);
    expect(bal('11401001'))->toBe(70.0);   // VAT recoverable untouched by the GRNI split
    expect(bal('21101001'))->toBe(-570.0); // AP is the gross total
});

it('never clears more than the receipt actually credited', function () {
    // A bill larger than the goods (extra freight, a price rise) must not manufacture a GRNI
    // debit that no receipt ever credited — that would swing GRNI positive and hide a real
    // discrepancy. The excess is an expense.
    $pr = purchased(10, 50);
    billFor($pr, 620); // 120 more than the goods

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(GRNI))->toBe(0.0);
    expect(bal(RM_EXPENSE))->toBe(120.0);
});

it('clears nothing for goods that have not arrived', function () {
    // Billed before delivery: the receipt has posted no GRNI credit yet, so there is nothing to
    // clear and the bill is an ordinary expense until the goods land.
    $r = $this->svc->request([
        'asset_id' => $this->asset->id, 'justification' => 'Ordered, not delivered.',
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['inventory_item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 50]],
    ], $this->buyer);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-9', $this->manager); // NOT received

    billFor($r->fresh(), 500);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(bal(GRNI))->toBe(0.0);
    expect(bal(RM_EXPENSE))->toBe(500.0);
});

it('keeps the books tying out, and does not double-post on a re-sweep', function () {
    $pr = purchased(10, 50);
    billFor($pr, 500);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $result = app(BooksReconciliationService::class)->run(deep: true);
    expect($result['ok'])->toBeTrue(json_encode($result['checks'] ?? []));
    expect(app(BooksReconciliationService::class)->glDriftDiscrepancies())->toBeEmpty();

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);
    expect(bal(GRNI))->toBe(0.0);
    expect(bal(INVENTORY))->toBe(500.0);
});
