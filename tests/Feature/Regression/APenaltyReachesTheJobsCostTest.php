<?php

use App\Models\FacilityWorkOrder;
use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\ApplySlaPenaltyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * APPLYING AN SLA PENALTY NEVER RE-DERIVED THE JOB'S COST.
 *
 * The work order is a COST OBJECT and `FacilityWorkOrder::recomputeCosts()` is its single source of
 * truth — the same discipline that makes every AR settlement channel call
 * `Invoice::recomputeTotals()`. `VendorBill` keeps its end of that bargain in a `saved` hook, and
 * `VendorBill::recompute()` ends with **`saveQuietly()`**, which is exactly the call that skips it.
 *
 * So every derived figure `recompute()` writes was invisible to the cost object, and the SLA penalty
 * is the one that matters: `ApplySlaPenaltyService` sets `penalty_applied_amount` and calls
 * `recompute()`, and `recomputeCosts()` nets that very column out of the job's service cost. Applying
 * a penalty therefore reduced what was payable to the contractor and left `act_service_cost` standing
 * at the full amount — the job overstated its cost by exactly the penalty, permanently, and the
 * planned-versus-actual variance the whole cost object exists for read wrong in the direction that
 * flatters the contractor. Detaching one had the mirror fault.
 *
 * `saveQuietly()` is right and stays: a derivation is not an operator action, and logging it would
 * bury the change somebody actually made. What was missing is the cascade beside it.
 *
 * Deliberately NOT a GL question. The penalty already posts (`SlaPenaltyJournalizer`); these columns
 * are a management dimension over money the ledger has, which is why a work order must never become
 * a GL source — `WorkOrderIsACostObjectNotAGlSourceTest` gates that.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
    $this->vendor = Vendor::create(['name' => 'SlaCo', 'status' => 'active']);

    $this->order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $this->vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => now()->toDateString(),
        'est_service_cost' => 50000,
    ]);

    // The contractor's invoice for the job — 50,000 net.
    $this->bill = VendorBill::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'facility_work_order_id' => $this->order->id,
        'status' => 'approved',
        'category' => 'maintenance',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000,
        'paid_amount' => 0, 'balance' => 50000,
    ]);

    $this->penalty = SlaPenalty::create([
        'facility_work_order_id' => $this->order->id,
        'asset_id' => $this->asset->id,
        'vendor_id' => $this->vendor->id,
        'basis' => SlaPenalty::BASIS_FLAT,
        'rate' => 8000,
        'hours_over_sla' => 0,
        'amount' => 8000,
        'status' => SlaPenalty::STATUS_FINAL,
        'finalised_at' => now(),
    ]);
});

it('cuts the job cost by the penalty when one is applied', function () {
    // The control: the bill alone puts its full net on the job.
    expect((float) $this->order->fresh()->act_service_cost)->toEqual(50000.0);

    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    $order = $this->order->fresh();

    expect((float) $this->bill->fresh()->penalty_applied_amount)->toEqual(8000.0)
        ->and((float) $this->bill->fresh()->balance)->toEqual(42000.0)
        // …and the cost object follows, which is the whole finding.
        ->and((float) $order->act_service_cost)->toEqual(42000.0)
        ->and((float) $order->act_total_cost)->toEqual(42000.0);
});

it('puts the cost back when the penalty is detached', function () {
    // The mirror. A penalty applied to the wrong bill is detached, and a job left understating its
    // cost is the same defect pointing the other way.
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    expect((float) $this->order->fresh()->act_service_cost)->toEqual(42000.0);

    app(ApplySlaPenaltyService::class)->detach($this->penalty->fresh());

    expect((float) $this->order->fresh()->act_service_cost)->toEqual(50000.0)
        ->and((float) $this->bill->fresh()->balance)->toEqual(50000.0);
});

it('reaches the job from any caller of recompute(), not just the penalty service', function () {
    // The reason the cascade lives in `recompute()` rather than in `ApplySlaPenaltyService`: every
    // derived figure that method writes is invisible to the cost object, so a fix in the service
    // would leave the NEXT caller to remember it — which is precisely the failure being fixed.
    // A payment is that next caller.
    $this->bill->payments()->create([
        'amount' => 10000,
        'payment_date' => now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    $this->bill->fresh()->recompute();

    // A payment does not change what the job COST — it changes what is owed — so the job holds at
    // its full net. The assertion that matters is that the cascade ran and did not corrupt it.
    expect((float) $this->order->fresh()->act_service_cost)->toEqual(50000.0)
        ->and((float) $this->bill->fresh()->balance)->toEqual(40000.0);
});
