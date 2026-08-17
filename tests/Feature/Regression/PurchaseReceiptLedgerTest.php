<?php

use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\PurchaseRequestService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The money half of procurement — does a goods receipt reach the ledger through the paths
 * PRODUCTION uses, and can the GRNI it creates ever be found again?
 *
 * WHY THIS EXISTS. A receipt credits GRNI (Goods Received Not Invoiced), a clearing liability that
 * is supposed to be cleared against the vendor's bill (Dr GRNI / Cr AP). The ad-hoc receipt action
 * writes a movement with a free-text `reference` and NO source_type/source_id, so nothing can ever
 * match a receipt to a bill. Measured on the demo books before this module: **166,120 EGP of GRNI
 * credits, zero debits, across 12 lines — 0 of 12 receipts carried a source link.**
 *
 * FR-WH-02 asks for exactly the missing thing: movements logged "with timestamp, user, and linked
 * work order or **procurement reference**". These tests pin that link, and never touch LedgerPoster
 * — they drive the service and then the real `accounting:sync-ledger` sweep.
 *
 * @see GlRegistryConformanceTest — the structural gate
 * @see SlaPenaltyLedgerDispatchTest — the bug that taught us to test the dispatch, not the arithmetic
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->svc = app(PurchaseRequestService::class);
    $this->asset = makeAsset(['code' => 'PGL']);
    $this->warehouse = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'FILT', 'name' => 'Air filter', 'unit' => 'each', 'unit_cost' => 50]);
    $this->buyer = makeUser('operations', [$this->asset->id]);
    $this->manager = makeUser('manager', [$this->asset->id]);
});

function receivedPr(float $qty = 10, float $cost = 50): PurchaseRequest
{
    $r = test()->svc->request([
        'asset_id' => test()->asset->id,
        'justification' => 'Stock out.',
        'warehouse_id' => test()->warehouse->id,
        'lines' => [['inventory_item_id' => test()->item->id, 'quantity' => $qty, 'unit_cost' => $cost]],
    ], test()->buyer);

    test()->svc->approve($r, null, test()->manager);
    test()->svc->order($r->fresh(), null, 'PO-1', test()->manager);

    return test()->svc->receive($r->fresh(), test()->buyer);
}

it('posts a purchase receipt to the ledger through the real sweep', function () {
    $pr = receivedPr(); // 10 × 50 = 500

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $movement = StockMovement::where('source_type', MorphMap::alias(PurchaseRequest::class))->where('source_id', $pr->id)->sole();
    $entry = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->where('source_id', $movement->id)->first();

    expect($entry)->not->toBeNull('a receipt must reach the GL through the real sweep');
    expect((float) $entry->lines->sum('debit'))->toBe(500.0);
    expect((float) $entry->lines->sum('debit'))->toBe((float) $entry->lines->sum('credit'));
    expect($entry->asset_id)->toBe($this->asset->id);
});

it('debits inventory and credits GRNI, not accounts payable', function () {
    // GRNI is a dedicated clearing liability precisely so the AP tie-out stays honest: a receipt
    // has no vendor bill behind it yet, and crediting AP would invent a payable nobody owes.
    receivedPr();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $lines = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->latest('id')->first()->lines()->with('account')->get();
    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($debit->account->type)->toBe('asset');       // inventory
    expect($credit->account->code)->toBe('21701001');   // GRNI
    expect($credit->account->code)->not->toBe('21101001'); // NOT the AP control
});

it('leaves every GRNI credit traceable back to the purchase that made it', function () {
    // THE POINT OF THIS MODULE. Before it, GRNI accumulated with no way back to a source: 12
    // receipts, 0 with a source link, 166,120 EGP that could never be cleared. A GRNI credit is
    // only clearable if you can answer "which purchase was this?".
    receivedPr(10, 50);
    receivedPr(4, 25);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $grni = LedgerAccount::where('code', '21701001')->sole();
    $lines = JournalLine::where('ledger_account_id', $grni->id)
        ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->with('entry')->get();

    expect($lines)->toHaveCount(2);
    expect((float) $lines->sum('credit'))->toBe(600.0);

    foreach ($lines as $line) {
        $movement = StockMovement::find($line->entry->source_id);
        expect($movement->source_type)->toBe(MorphMap::alias(PurchaseRequest::class));
        expect(PurchaseRequest::find($movement->source_id))->not->toBeNull(
            'every GRNI credit must trace to a purchase request, or it can never be cleared'
        );
    }
});

it('keeps the books tying out after a receipt', function () {
    receivedPr();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $result = app(BooksReconciliationService::class)->run(deep: true);

    expect($result['ok'])->toBeTrue(json_encode($result['checks'] ?? []));
    expect(app(BooksReconciliationService::class)->glDriftDiscrepancies())->toBeEmpty();
});

it('does not post twice when the sweep runs again', function () {
    receivedPr();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);
    $after = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->count();

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->count())->toBe($after);
});

it('posts nothing for a service-only purchase', function () {
    // No stock moved, so there is no inventory to debit and no GRNI to credit. The money for it is
    // the vendor's bill, which posts through its own journalizer.
    $r = $this->svc->request([
        'asset_id' => $this->asset->id, 'justification' => 'Annual service.',
        'lines' => [['description' => 'Chiller service visit', 'quantity' => 1, 'unit_cost' => 4000]],
    ], $this->buyer);
    $this->svc->approve($r, null, $this->manager);
    $this->svc->order($r->fresh(), null, 'PO-2', $this->manager);
    $this->svc->receive($r->fresh(), $this->buyer);

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(StockMovement::count())->toBe(0);
    expect(JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->count())->toBe(0);
    expect(app(BooksReconciliationService::class)->run(deep: true)['ok'])->toBeTrue();
});
