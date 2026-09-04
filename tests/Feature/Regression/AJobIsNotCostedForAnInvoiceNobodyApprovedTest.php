<?php

/*
|--------------------------------------------------------------------------
| A DRAFT contractor invoice counted as what the job COST (SW-072)
|--------------------------------------------------------------------------
| `vendor_bills.status` DEFAULTS to `draft` — measured on the live schema
| (`show columns from vendor_bills like 'status'` → Default: draft) — and
| `FacilityWorkOrder::recomputeCosts()` counted every bill that was not `cancelled`. So a bill
| keyed and not yet approved landed in `act_service_cost` the moment it was saved: money the
| general ledger has never seen, on a set of columns whose whole premise is that they are a
| management dimension OVER posted money. `VendorBill::isPostable()`/`scopePostable()` is the one
| definition of "has this any GL effect", and `VendorBillJournalizer` returns early for a draft, so
| the cost object was answering a question the document had already answered differently.
|
| **It moves money, which is why this is not merely a report.**
| `AssessSlaPenaltyService::jobValue()` prefers `act_service_cost` over the estimate, so a
| percent-of-value SLA penalty charged to a contractor was priced off an invoice nobody had
| approved — and `assess()` freezes a penalty the moment the job goes terminal, so approving or
| correcting the bill afterwards never re-priced it.
*/

use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPolicy;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContract;
use App\Services\AssessSlaPenaltyService;
use App\Services\FacilityWorkOrderService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'COST']);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'status' => Vendor::STATUS_ACTIVE]);

    SlaPolicy::create(['asset_id' => $this->asset->id, 'priority' => 'urgent', 'resolve_hours' => 1]);

    $this->job = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $this->vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'status' => 'open',
        'scheduled_for' => '2026-07-01',
        'est_service_cost' => 8000,
    ]);
});

/** A contractor invoice filed against this job. `draft` is the column's own default. */
function costObjectBill(array $attrs = []): VendorBill
{
    return VendorBill::create(array_merge([
        'vendor_id' => test()->vendor->id,
        'asset_id' => test()->asset->id,
        'facility_work_order_id' => test()->job->id,
        'category' => 'maintenance',
        'status' => 'draft',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'The job as invoiced',
        'subtotal' => 12000,
        'vat_amount' => 1680,
        'total' => 13680,
    ], $attrs));
}

it('does not count an invoice nobody has approved as what the job cost', function () {
    $bill = costObjectBill();

    expect((float) $this->job->fresh()->act_service_cost)->toBe(0.0)
        ->and((float) $this->job->fresh()->act_total_cost)->toBe(0.0);

    // The control, and it must succeed: approving is what puts the money on the books, and the
    // cost object has to follow it. A fix that simply stopped counting bills would satisfy the
    // refusal above on its own.
    $bill->update(['status' => 'approved']);

    expect((float) $this->job->fresh()->act_service_cost)->toBe(12000.0)
        ->and((float) $this->job->fresh()->act_total_cost)->toBe(12000.0);
});

it('never prices an SLA penalty off an invoice nobody has approved', function () {
    // The money half. Both figures are real and different, so neither branch can pass by
    // answering zero: the estimate is 8,000 and the unapproved invoice is 12,000, at 10%.
    VendorContract::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'name' => 'HVAC SLA',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'value' => 100000,
        'sla_penalty_basis' => 'percent_of_value',
        'sla_penalty_rate' => 10,
    ]);

    $bill = costObjectBill();

    app(FacilityWorkOrderService::class)->transition($this->job, 'in_progress');
    $this->travel(6)->hours();                       // past the 1h SLA on `urgent`

    $penalty = app(AssessSlaPenaltyService::class)->assess($this->job->fresh());

    // 10% of the ESTIMATE — the only figure anyone has committed to. Not 1,200.
    expect((float) $penalty->amount)->toBe(800.0);

    // The control: once the invoice is approved it IS the better figure, and the pending penalty
    // re-prices to it. This is the behaviour `SlaPenaltyScenarioTest` already pins for an approved
    // bill, reached here through the door that used to skip the approval entirely.
    $bill->update(['status' => 'approved']);

    expect((float) app(AssessSlaPenaltyService::class)->assess($this->job->fresh())->amount)->toBe(1200.0);
});

it('still ignores a cancelled invoice, and still counts a direct expense', function () {
    // The two channels the `postable()` allowlist had to leave exactly as they were. A cancelled
    // bill costing nothing is the older rule; petty cash reaching the job is the other half of the
    // service bucket, and `expenses.status` moved from `!= cancelled` to an allowlist in the same
    // change.
    $bill = costObjectBill(['status' => 'approved']);
    expect((float) $this->job->fresh()->act_service_cost)->toBe(12000.0);

    $bill->update(['status' => 'cancelled']);
    expect((float) $this->job->fresh()->act_service_cost)->toBe(0.0);

    $expense = Expense::create([
        'asset_id' => $this->asset->id,
        'facility_work_order_id' => $this->job->id,
        'category' => 'maintenance',
        'description' => 'Refrigerant, bought on the day',
        'amount' => 1200,
        'vat_amount' => 168,
        'total' => 1368,
        'paid_from' => 'cash',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);

    expect((float) $this->job->fresh()->act_service_cost)->toBe(1200.0);

    $expense->update(['status' => 'cancelled']);

    expect((float) $this->job->fresh()->act_service_cost)->toBe(0.0);
});
