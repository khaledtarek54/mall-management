<?php

use App\Models\JournalEntry;
use App\Models\SlaPenalty;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Services\Accounting\FiscalCalendar;
use App\Services\ApplySlaPenaltyService;
use App\Services\FacilityWorkOrderService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — an applied SLA penalty must reach the ledger through the paths PRODUCTION
 * uses, not just when a test hands it to LedgerPoster.
 *
 * THE BUG (fixed 2026-07-16). SlaPenaltyJournalizer was correctly registered on
 * LedgerPoster, but SlaPenalty was absent from LedgerRealtimeSync::SOURCES, from
 * SOURCE_DATE_COLUMNS, and from SyncLedgerCommand's sweep. ApplySlaPenaltyService only set
 * the status and called VendorBill::recompute(). So applying a penalty CUT THE BILL'S AP
 * BALANCE AND POSTED NOTHING: the GL overstated the payable, the sweep couldn't self-heal
 * it, and `billing:reconcile`'s drift check couldn't see it either — because all of those
 * walked a hand-copied list the penalty wasn't on.
 *
 * WHY IT SHIPPED GREEN. SlaPenaltyChargeScenarioTest calls `LedgerPoster::post($penalty)`
 * directly, which proves the journalizer is correct while never exercising the dispatch.
 * These tests deliberately never touch LedgerPoster — they drive the service and then the
 * real `accounting:sync-ledger` sweep, so they fail if the wiring regresses.
 *
 * @see GlRegistryConformanceTest — the structural gate that makes the drift unshippable
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'DSP']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'category' => 'hvac', 'status' => 'active']);
    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);
    VendorContract::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'name' => 'HVAC SLA', 'status' => 'active',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => 100000,
        'sla_penalty_basis' => 'flat', 'sla_penalty_rate' => 500,
    ]);
});

/** A closed, late external CM whose penalty has been assessed and applied to a payable bill. */
function dispatchAppliedPenalty(float $subtotal = 5000): array
{
    $order = FacilityWorkOrder::create([
        'asset_id' => test()->asset->id, 'work_order_type' => 'cm', 'execution_type' => 'external',
        'vendor_id' => test()->vendor->id, 'description' => 'Chiller down', 'title' => 'Fix chiller',
        'category' => 'hvac', 'priority' => 'urgent', 'scheduled_for' => '2026-07-01',
    ]);
    // Completing a late external CM assesses the penalty as a side effect — the same path
    // the operator drives from the work-order screen.
    app(FacilityWorkOrderService::class)->transition($order, 'in_progress');
    test()->travel(6)->hours();
    app(FacilityWorkOrderService::class)->transition($order->fresh(), 'done');

    $bill = VendorBill::create([
        'vendor_id' => test()->vendor->id, 'asset_id' => test()->asset->id,
        'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => '2026-07-05', 'subtotal' => $subtotal, 'vat_amount' => 0,
    ]);
    $bill->recompute();

    $penalty = app(ApplySlaPenaltyService::class)->toBill(SlaPenalty::firstOrFail(), $bill->fresh());

    return [$penalty->fresh(), $bill->fresh()];
}

it('posts an applied penalty through the real accounting:sync-ledger sweep', function () {
    // The sweep is the self-healing backstop. Before the fix it never visited
    // SlaPenalty, so this entry was never created by any production path.
    [$penalty] = dispatchAppliedPenalty();

    expect(JournalEntry::where('source_type', SlaPenalty::class)->exists())
        ->toBeFalse('precondition: nothing has posted the penalty yet');

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::where('source_type', SlaPenalty::class)
        ->where('source_id', $penalty->id)->where('status', 'posted')->first();

    expect($entry)->not->toBeNull('the sweep did not post the applied penalty')
        ->and((float) $entry->lines->sum('debit'))->toBe(500.0)
        ->and((float) $entry->lines->sum('credit'))->toBe(500.0);
});

it('ties the ledger out to AP after the sweep — no phantom payable', function () {
    // The consequence of the bug, stated in money: the bill's balance drops by the penalty
    // while the GL's payable does not, so AP overstates what is owed by exactly 500.
    dispatchAppliedPenalty(5000);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $tie = app(BooksReconciliationService::class)->glTieOut();

    expect($tie['ap']['expected'])->toBe(4500.0)   // 5000 − 500 penalty
        ->and($tie['ap']['gl'])->toBe(4500.0)      // the GL agrees...
        ->and($tie['ap']['delta'])->toBe(0.0);     // ...so the close reports no discrepancy
});

it('sees an unposted applied penalty as GL drift', function () {
    // BooksReconciliationService::glDriftDiscrepancies walks the same registry. Before the
    // fix the penalty wasn't on the list, so `billing:reconcile` — the gate that is supposed
    // to catch exactly this — reported clean books while AP was wrong.
    dispatchAppliedPenalty();

    $drift = app(BooksReconciliationService::class)->glDriftDiscrepancies();

    expect($drift)->not->toBeEmpty('an applied-but-unposted penalty must surface as drift')
        ->and(collect($drift)->pluck('ref')->implode(' '))->toContain('SlaPenalty');
});

it('re-posts a penalty that is detached and re-applied', function () {
    // The entry must follow the document's real state, not a one-shot post.
    [$penalty, $bill] = dispatchAppliedPenalty();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    app(ApplySlaPenaltyService::class)->detach($penalty);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    // Detached = still owed, not yet deducted — an estimate, so it must not sit in the books.
    expect(JournalEntry::where('source_type', SlaPenalty::class)->where('status', 'posted')->exists())
        ->toBeFalse('a detached penalty must not keep a live journal entry');

    expect((float) $bill->fresh()->balance)->toBe(5000.0)
        ->and(app(BooksReconciliationService::class)->glTieOut()['ap']['delta'])->toBe(0.0);
});
