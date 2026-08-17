<?php

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderPart;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\StockMovementService;
use App\Services\WorkOrderPartService;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The money half of spare parts (FR-CM-09/10/11) — does approving a draw actually reach the
 * ledger through the paths PRODUCTION uses?
 *
 * WHY THIS EXISTS. The SLA penalty shipped green while posting nothing, because its test
 * called `LedgerPoster::post()` directly — proving the journalizer's arithmetic while never
 * exercising the dispatch. Parts shipped with **no GL coverage at all**, and the demo data
 * contains zero parts, so `billing:reconcile` was silent on them too: green meant nothing.
 *
 * These tests never touch LedgerPoster. They drive the service, then the real
 * `accounting:sync-ledger` sweep, and assert the books tie out — so they fail if the wiring
 * regresses the way the penalty's did.
 *
 * @see GlRegistryConformanceTest — the structural gate that makes that drift unshippable
 * @see SlaPenaltyLedgerDispatchTest — the same proof for the penalty
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->svc = app(WorkOrderPartService::class);
    $this->asset = makeAsset(['code' => 'GLP']);
    $this->wh = Warehouse::create(['asset_id' => $this->asset->id, 'name' => 'Main', 'code' => 'W1']);
    $this->item = InventoryItem::create(['sku' => 'PMP', 'name' => 'Pump seal', 'unit' => 'each', 'unit_cost' => 100]);

    app(StockMovementService::class)->record([
        'warehouse_id' => $this->wh->id, 'inventory_item_id' => $this->item->id,
        'type' => 'receipt', 'quantity' => 100, 'unit_cost' => 100,
    ]);

    $this->order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'description' => 'Pump leaking', 'title' => 'Fix pump', 'category' => 'plumbing',
        'scheduled_for' => now()->toDateString(),
    ]);
});

function approvedDraw(float $qty = 5): FacilityWorkOrderPart
{
    $part = test()->svc->requestInternal(test()->order, [
        'inventory_item_id' => test()->item->id, 'warehouse_id' => test()->wh->id, 'quantity' => $qty,
    ], makeUser('operations', [test()->asset->id])->id);

    return test()->svc->approve($part, makeUser('manager', [test()->asset->id]));
}

it('posts an approved draw to the ledger through the real sweep', function () {
    $part = approvedDraw(5); // 500 EGP of stock leaves the shelf

    // Deliberately NOT LedgerPoster::post() — the command production runs.
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $entry = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))
        ->where('source_id', $part->stock_movement_id)
        ->first();

    expect($entry)->not->toBeNull('an approved draw must reach the GL through the real sweep');
    expect((float) $entry->lines->sum('debit'))->toBe(500.0);
    expect((float) $entry->lines->sum('debit'))->toBe((float) $entry->lines->sum('credit')); // balanced
    // Dimensioned to the property that actually owns the stock.
    expect($entry->asset_id)->toBe($this->asset->id);
});

it('recognises the cost as an expense and takes it out of inventory', function () {
    // The accounting meaning of a draw: stock becomes cost the moment it is issued.
    approvedDraw(5);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $lines = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))
        ->latest('id')->first()->lines()->with('account')->get();

    $debit = $lines->firstWhere(fn ($l) => (float) $l->debit > 0);
    $credit = $lines->firstWhere(fn ($l) => (float) $l->credit > 0);

    expect($debit->account->type)->toBe('expense');   // repairs & maintenance
    expect($credit->account->type)->toBe('asset');    // inventory
});

it('keeps the books tying out after a draw', function () {
    approvedDraw(5);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    // The gate that was blind to the penalty bug. It must see parts too.
    $result = app(BooksReconciliationService::class)->run(deep: true);

    expect($result['ok'])->toBeTrue(json_encode($result['checks'] ?? []));
    expect(app(BooksReconciliationService::class)->glDriftDiscrepancies())->toBeEmpty();
});

it('does not post twice when the sweep runs again', function () {
    approvedDraw(5);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);
    $after = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->count();

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))->count())->toBe($after);
});

it('reverses the ledger when an issued draw is voided', function () {
    // Voiding returns the stock; the cost recognised against it must come back out, or the
    // GL keeps an expense for parts sitting on the shelf.
    $part = approvedDraw(5);
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    trashBypassingDeletionPolicy(StockMovement::find($part->stock_movement_id)); // void
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    $entry = JournalEntry::where('source_type', MorphMap::alias(StockMovement::class))
        ->where('source_id', $part->stock_movement_id)->first();

    expect($entry?->status ?? 'gone')->not->toBe('posted');
    expect($this->order->partsCost())->toBe(0.0); // and the job is no longer charged
    expect(app(BooksReconciliationService::class)->glDriftDiscrepancies())->toBeEmpty();
});

it('posts nothing for an external purchase — by design, and this is the seam to watch', function () {
    // An outside part never touched our stock, so there is no inventory to relieve and no
    // movement to post. The accounting document for it is the VENDOR BILL (Dr expense / Cr AP),
    // which posts through its own journalizer.
    //
    // ⚠️ The two are not linked yet: nothing forces a bill to exist for a recorded external
    // part. So `partsCost()` can exceed what the GL knows about the job. That is a REPORTING
    // gap, not a GL imbalance — the books still tie out, because an unrecorded purchase is
    // simply absent from them rather than wrong in them. Closing it is procurement's job
    // (FR-PROC-*): the bill is the seam. Documented in module 26 + 22.
    $this->svc->recordExternal($this->order, [
        'description' => 'Bespoke gasket', 'quantity' => 1, 'unit_cost' => 750,
    ], makeUser('operations', [$this->asset->id])->id);

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    expect(StockMovement::where('type', 'consumption')->count())->toBe(0);
    expect($this->order->partsCost())->toBe(750.0); // the job knows…
    // …and the books are undisturbed rather than wrong.
    expect(app(BooksReconciliationService::class)->run(deep: true)['ok'])->toBeTrue();
    expect(app(BooksReconciliationService::class)->glDriftDiscrepancies())->toBeEmpty();
});
