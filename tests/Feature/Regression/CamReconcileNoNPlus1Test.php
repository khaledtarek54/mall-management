<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Services\CamReconciliationService;
use App\Services\Reconciliation\BooksReconciliationService;
use Illuminate\Support\Facades\DB;

/*
| Regression guard: the CAM section of the reconciliation harness must batch its
| backing-existence lookups (charge exists / credit-note exists / charge reached
| a non-cancelled invoice) into a handful of set queries — NOT one-per-billed-
| allocation. Otherwise billing:reconcile degrades O(n) with CAM allocations
| (audit D4). Behaviour is covered by BooksTieOutTest; this guards the query cost.
*/

it('batches CAM backing-existence checks (no per-allocation N+1 in reconcile)', function () {
    $asset = makeAsset();

    // Several leased units → several CAM allocations after generateAllocations().
    $lease = null;
    foreach (range(1, 8) as $i) {
        $lease = makeLease(makeUnit($asset, ['code' => "CN-{$i}", 'area_sqm' => 100]), null, ['status' => 'active']);
    }

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id,
        'period_year' => 2026,
        'total_actual_expense' => 16000,
        'total_estimated_collected' => 12000,
        'status' => 'draft',
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    // One real charge; mark every allocation billed against it so the loop exercises
    // BOTH the charge-exists and charge-reached-an-invoice backing checks.
    $charge = Charge::create([
        'lease_id' => $lease->id,
        'name' => 'CAM true-up',
        'type' => 'other',
        'amount' => 100,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => 0,
        'is_active' => false,
        'start_date' => now()->toDateString(),
    ]);
    CamAllocation::query()->update(['status' => 'billed', 'billed_charge_id' => $charge->id]);

    $allocationCount = CamAllocation::count();
    expect($allocationCount)->toBeGreaterThanOrEqual(8);

    DB::enableQueryLog();
    app(BooksReconciliationService::class)->run();
    $backingQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "charges"')
        || str_contains($q['query'], 'from "credit_notes"')
        || str_contains($q['query'], 'from "invoice_items"'))->count();
    DB::disableQueryLog();

    // Batched → a small constant number of set queries, well under one-per-allocation.
    expect($backingQueries)->toBeLessThan($allocationCount);
});
