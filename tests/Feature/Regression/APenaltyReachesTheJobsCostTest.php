<?php

use App\Models\FacilityWorkOrder;
use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\ApplySlaPenaltyService;
use App\Services\AssessSlaPenaltyService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

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

it('reaches the job when a penalty is WAIVED — the caller in a different service', function () {
    // **The reason the cascade lives in `recompute()` rather than in `ApplySlaPenaltyService`**, and
    // the case that proves it: `AssessSlaPenaltyService::waive()` releases an APPLIED penalty and
    // reaches the bill only through `$bill?->refresh()->recompute()`. `SlaPenalty` has no boot
    // hooks, so nothing else touches the job — a fix inside the penalty service would have left this
    // caller reporting a job discounted by a penalty nobody is charging.
    //
    // The first version of this case used a PAYMENT instead, and it was vacuous: `act_service_cost`
    // depends on `subtotal − penalty_applied_amount` and a payment moves neither, so the assertion
    // read the same with and without the cascade.
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    expect((float) $this->order->fresh()->act_service_cost)->toEqual(42000.0);

    app(AssessSlaPenaltyService::class)->waive($this->penalty->fresh(), 'Contractor disputed it and we agreed.');

    expect((float) $this->order->fresh()->act_service_cost)->toEqual(50000.0)
        ->and((float) $this->bill->fresh()->balance)->toEqual(50000.0);
});

it('does not re-derive the job when nothing the job reads has moved', function () {
    // **The cascade is GUARDED, not unconditional.** `act_service_cost` is `subtotal` net of
    // `penalty_applied_amount` on a non-cancelled bill, and nothing else — so a `recompute()` that
    // moved none of those must not run the four costing aggregates.
    //
    // Measured without the guard: `VendorBillService::approve()` ran them TWICE (its own `save()`
    // fires the `saved` hook, then `recompute()` fired the cascade), `cancel()` three times, and
    // every vendor-bill payment paid for a `find()` plus four aggregates to re-derive a figure a
    // payment provably cannot move — 13 queries to 18.
    //
    // Asserted on `recompute()` directly rather than through a payment: `VendorBillPayment` has its
    // own `saved` hook onto the cost object, so a payment-driven test would be measuring that seam
    // instead of this one.
    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    $bill = $this->bill->fresh();

    DB::enableQueryLog();
    $bill->recompute();          // nothing dirty — the guard must refuse
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $q): bool => str_contains($q, 'facility_work_orders')))
        ->toBeEmpty('a no-op recompute() re-derived the job cost')
        // …and the figure is still right, which is the half a query count can never say.
        ->and((float) $this->order->fresh()->act_service_cost)->toEqual(42000.0);
});

it('keeps the planned-versus-actual variance honest — the figure the cost object exists for', function () {
    // The commit's own thesis, asserted rather than described. `costVariance()` is estimate LESS
    // actual, so on plan is 0 and a penalty the mall did not pay leaves it 8,000 to the good — which
    // is exactly the figure that read 0 while the penalty was invisible to the job.
    expect((float) $this->order->fresh()->costVariance())->toEqual(0.0);

    app(ApplySlaPenaltyService::class)->toBill($this->penalty, $this->bill);

    $order = $this->order->fresh();

    expect((float) $order->act_total_cost)->toEqual(42000.0)
        ->and((float) $order->est_total_cost)->toEqual(50000.0)
        ->and((float) $order->costVariance())->toEqual(8000.0);
});

it('does not fall over on a bill with no job at all', function () {
    // The null path. Nothing stops a future edit dropping the `?->`, and most vendor bills in a
    // real install carry no work order.
    $free = VendorBill::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'status' => 'approved',
        'category' => 'maintenance',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 4000, 'vat_amount' => 0, 'total' => 4000,
        'paid_amount' => 0, 'balance' => 4000,
    ]);

    $free->fresh()->recompute();

    expect($free->fresh()->facility_work_order_id)->toBeNull()
        ->and((float) $free->fresh()->balance)->toEqual(4000.0)
        // …and the job that DOES exist is untouched by it.
        ->and((float) $this->order->fresh()->act_service_cost)->toEqual(50000.0);
});
