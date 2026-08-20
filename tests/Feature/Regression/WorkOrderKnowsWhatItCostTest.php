<?php

/*
|--------------------------------------------------------------------------
| A work order could not say what it cost — close-out step 2 (2026-08-20)
|--------------------------------------------------------------------------
| `docs/benchmarks/fm/01-maximo-work-and-asset.md` §4: the work order is the cost object. Six of the
| eight scenarios in `03-scenarios.md` failed on its absence.
|
| Every figure was already in the ledger — parts through StockMovement, contractor work through
| VendorBill, wages through Payroll — and **none of it could be attributed to the job, the machine
| or the shop.** In-house labour was captured nowhere at all, so internal work cost zero on every
| report and every outsourcing decision was wrong by the whole wage bill.
|
| `recomputeCosts()` is the single source of truth, the way `Invoice::recomputeTotals()` is for AR:
| three channels feed it and each one calls it. These pin the arithmetic, the exclusions, and — the
| one that matters most — that none of it reaches the general ledger.
*/

use App\Models\Equipment;
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\Trade;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->trade = Trade::where('code', 'hvac')->firstOrFail();
    $this->trade->update(['standard_hourly_rate' => 300]);

    $this->wo = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'ppm', 'title' => 'Chiller service',
        'trade_id' => $this->trade->id, 'status' => 'open', 'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
    ]);
});

function bookHours($ctx, float $hours, array $attrs = []): FacilityWorkOrderLabour
{
    return FacilityWorkOrderLabour::create(array_merge([
        'facility_work_order_id' => $ctx->wo->id,
        'worked_on' => now()->toDateString(),
        'hours' => $hours,
    ], $attrs));
}

function billTheJob($ctx, float $subtotal, array $attrs = []): VendorBill
{
    $vendor = Vendor::create([
        'name' => 'Delta FM '.uniqid(), 'legal_name' => 'Delta FM LLC', 'status' => 'active', 'type' => 'contractor',
    ]);

    return VendorBill::create(array_merge([
        'vendor_id' => $vendor->id, 'asset_id' => $ctx->asset->id,
        'facility_work_order_id' => $ctx->wo->id, 'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'Contracted work', 'subtotal' => $subtotal,
        'vat_amount' => round($subtotal * 0.14, 2), 'total' => round($subtotal * 1.14, 2),
    ], $attrs));
}

/* ---- labour: the bucket that did not exist ------------------------------ */

/**
 * **Cost is a consequence of reporting time.** Nobody types money: the technician says how long it
 * took, and the craft rate turns that into cost.
 */
it('turns reported hours into cost at the craft rate', function () {
    bookHours($this, 6);
    bookHours($this, 5.5);

    expect((float) $this->wo->fresh()->act_labour_hours)->toBe(11.5)
        ->and((float) $this->wo->fresh()->act_labour_cost)->toBe(3450.0);
});

/**
 * The rate is FROZEN at entry. A rise must not silently re-price work done last March — the same
 * origination rule that governs every other rate in this system.
 */
it('does not re-price work already done when the trade rate changes', function () {
    bookHours($this, 10);                              // 10 × 300 = 3,000
    $this->trade->update(['standard_hourly_rate' => 500]);
    bookHours($this, 10);                              // 10 × 500 = 5,000

    expect((float) $this->wo->fresh()->act_labour_cost)->toBe(8000.0);
});

/**
 * A trade with no rate produces hours and NO cost — visibly missing rather than invented. A default
 * rate would produce a number that looks computed and is a guess.
 */
it('records the hours but no cost when the trade has no rate', function () {
    $this->trade->update(['standard_hourly_rate' => null]);

    $line = bookHours($this, 8);

    expect($line->cost)->toBeNull()
        ->and((float) $this->wo->fresh()->act_labour_hours)->toBe(8.0)
        ->and((float) $this->wo->fresh()->act_labour_cost)->toBe(0.0);
});

/** An electrician helping on an HVAC job books their OWN craft, or both trades are misreported. */
it('lets a line state a different craft from the job', function () {
    $electrical = Trade::where('code', 'electrical')->firstOrFail();
    $electrical->update(['standard_hourly_rate' => 450]);

    bookHours($this, 2);                                       // the job's trade: 2 × 300
    bookHours($this, 2, ['trade_id' => $electrical->id]);      // another craft: 2 × 450

    expect((float) $this->wo->fresh()->act_labour_cost)->toBe(1500.0);
});

it('takes the hours back off when a mis-keyed line is removed', function () {
    $line = bookHours($this, 6);
    expect((float) $this->wo->fresh()->act_labour_cost)->toBe(1800.0);

    $line->delete();

    expect((float) $this->wo->fresh()->act_labour_cost)->toBe(0.0)
        ->and((float) $this->wo->fresh()->act_labour_hours)->toBe(0.0);
});

/* ---- service: what the contractor charged ------------------------------- */

/** NET of VAT. The tax is recoverable and is not what the work cost the business. */
it('costs a contractor bill net of VAT', function () {
    billTheJob($this, 8000);      // + 1,120 VAT

    expect((float) $this->wo->fresh()->act_service_cost)->toBe(8000.0);
});

/**
 * A penalty credited against the bill genuinely reduces what the work cost, and
 * `SlaPenaltyJournalizer` credits the same expense account — so netting it here keeps this figure
 * and the ledger telling one story.
 */
it('nets an applied SLA penalty off what the job cost', function () {
    billTheJob($this, 10000, ['penalty_applied_amount' => 1500]);

    expect((float) $this->wo->fresh()->act_service_cost)->toBe(8500.0);
});

/** A cancelled document costs nothing — the same exclusion `VendorBill::recompute()` makes. */
it('ignores a cancelled bill', function () {
    $bill = billTheJob($this, 8000);
    expect((float) $this->wo->fresh()->act_service_cost)->toBe(8000.0);

    $bill->update(['status' => 'cancelled']);

    expect((float) $this->wo->fresh()->act_service_cost)->toBe(0.0);
});

/** Petty cash reaches a job too; leaving expenses out would make the cost object half-true. */
it('counts a direct expense booked to the job', function () {
    Expense::create([
        'asset_id' => $this->asset->id, 'facility_work_order_id' => $this->wo->id,
        'category' => 'maintenance', 'description' => 'Refrigerant, bought on the day',
        'amount' => 1200, 'vat_amount' => 168, 'total' => 1368,
        'paid_from' => 'cash', 'expense_date' => now()->toDateString(), 'status' => 'recorded',
    ]);

    expect((float) $this->wo->fresh()->act_service_cost)->toBe(1200.0);
});

/**
 * **A document moved between jobs leaves the old one overstated.** The previous owner recomputes
 * too — the failure mode nobody would ever notice, because both numbers look plausible.
 */
it('takes the cost off the job a bill is moved AWAY from', function () {
    $bill = billTheJob($this, 5000);
    expect((float) $this->wo->fresh()->act_service_cost)->toBe(5000.0);

    $other = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id, 'work_order_type' => 'ppm', 'title' => 'Another job',
        'trade_id' => $this->trade->id, 'status' => 'open', 'priority' => 'low',
        'scheduled_for' => now()->toDateString(),
    ]);

    $bill->update(['facility_work_order_id' => $other->id]);

    expect((float) $this->wo->fresh()->act_service_cost)->toBe(0.0)
        ->and((float) $other->fresh()->act_service_cost)->toBe(5000.0);
});

/* ---- the total, and the variance that makes it actionable --------------- */

it('adds the three buckets into one total', function () {
    bookHours($this, 10);          // 3,000
    billTheJob($this, 8000);       // 8,000

    $wo = $this->wo->fresh();

    expect((float) $wo->act_total_cost)
        ->toBe((float) $wo->act_labour_cost + (float) $wo->act_material_cost + (float) $wo->act_service_cost)
        ->and((float) $wo->act_total_cost)->toBe(11000.0);
});

/**
 * "Not estimated" and "estimated at nothing" are different claims, and planned-vs-actual is the
 * point of the pair — so the difference has to survive.
 */
it('keeps an un-estimated job distinct from one estimated at zero', function () {
    expect($this->wo->fresh()->est_total_cost)->toBeNull()
        ->and($this->wo->fresh()->costVariance())->toBeNull();

    $this->wo->update(['est_service_cost' => 0]);
    $this->wo->recomputeCosts();

    expect((float) $this->wo->fresh()->est_total_cost)->toBe(0.0)
        ->and((float) $this->wo->fresh()->costVariance())->toBe(0.0);
});

it('reports the overrun as a negative variance', function () {
    $this->wo->update(['est_service_cost' => 5000]);
    $this->wo->recomputeCosts();
    billTheJob($this, 12000);

    expect((float) $this->wo->fresh()->costVariance())->toBe(-7000.0);
});

/* ---- the roll-up that answers scenario S1 ------------------------------- */

/**
 * **S1: "what has this chiller cost us?"** — unanswerable before, because every figure was in the
 * ledger and none was attributable to the machine.
 */
it('rolls a machine\'s jobs up into what it has cost', function () {
    $machine = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'CH-02', 'name_en' => 'Chiller 2',
        'name_ar' => 'مبرد ٢', 'trade_id' => $this->trade->id, 'is_active' => true,
    ]);

    $this->wo->update(['equipment_id' => $machine->id]);
    bookHours($this, 10);        // 3,000
    billTheJob($this, 8000);     // 8,000

    $lifetime = FacilityWorkOrder::where('equipment_id', $machine->id)->sum('act_total_cost');

    expect((float) $lifetime)->toBe(11000.0);
});

/*
|--------------------------------------------------------------------------
| Review pass — what the cost channels do NOT cover (2026-08-20)
|--------------------------------------------------------------------------
| `recomputeCosts()` is called by the three COST channels, and none of them touches an estimate —
| so editing `est_service_cost` on the form left the stored `est_total_cost` at whatever it had
| been, and `costVariance()`, the number an operator acts on, was computed from the stale figure.
| Found on the live database during the step-2 review, with 5,763 tests green.
*/

it('re-derives the planned total when an estimate is edited on the form', function () {
    $this->wo->update(['est_labour_cost' => 1000, 'est_service_cost' => 2000]);

    expect((float) $this->wo->fresh()->est_total_cost)->toBe(3000.0);

    // …and again when one is changed, not just when the first is set.
    $this->wo->update(['est_service_cost' => 500]);

    expect((float) $this->wo->fresh()->est_total_cost)->toBe(1500.0);
});

/** Clearing every estimate returns the job to "not estimated" — not to "estimated at nothing". */
it('returns the planned total to null when every estimate is cleared', function () {
    $this->wo->update(['est_labour_cost' => 1000, 'est_service_cost' => 2000]);
    expect($this->wo->fresh()->est_total_cost)->not->toBeNull();

    $this->wo->update(['est_labour_cost' => null, 'est_service_cost' => null]);

    expect($this->wo->fresh()->est_total_cost)->toBeNull()
        ->and($this->wo->fresh()->costVariance())->toBeNull();
});

/**
 * Hours may be booked on a job already marked `done` — a timesheet routinely arrives after the
 * work did, and refusing it means the hours are never recorded, which is the gap this feature
 * exists to close. A part draw is refused at that point because it MOVES STOCK; an hour booked
 * only allocates a wage payroll has already posted.
 */
it('still books hours against a job that has been completed', function () {
    $this->wo->update(['status' => 'done', 'completed_at' => now()]);

    bookHours($this, 4);

    expect((float) $this->wo->fresh()->act_labour_cost)->toBe(1200.0);
});
