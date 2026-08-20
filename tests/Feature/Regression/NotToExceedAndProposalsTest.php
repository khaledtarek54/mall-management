<?php

/*
|--------------------------------------------------------------------------
| The EGP 4,000 job that became EGP 46,000 (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 6 — the BEFORE-the-money control. ServiceChannel §3: every job carries a
| not-to-exceed amount, and work expected to exceed it needs a proposal *first*. Scenario S4: a leak
| is reported, the contractor decides the riser must be replaced, does it, and invoices EGP 46,000
| against an expected EGP 4,000 repair.
|
| Atriom already had the AFTER control — `PurchaseRequest::billingVariance()` is a real three-way
| match — and nothing before the money. The operator saw the number when the invoice arrived, which
| is not a negotiation.
|
| **A proposal IS the estimate.** Its three buckets are the cost object's three buckets, so
| approving one makes step 2's planned-vs-actual mean "did the contractor deliver what they quoted?"
*/

use App\Models\FacilityWorkOrder;
use App\Models\Trade;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\WorkOrderProposal;
use App\Services\WorkOrderProposalService;
use Database\Seeders\RolesPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $this->trade = Trade::where('code', 'plumbing')->firstOrFail();
    $this->trade->update(['default_nte' => 5000]);

    $this->vendor = Vendor::create([
        'name' => 'Delta Plumbing', 'legal_name' => 'Delta Plumbing LLC',
        'status' => 'active', 'type' => 'contractor',
    ]);

    $this->svc = app(WorkOrderProposalService::class);
});

function leakJob($ctx, array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => $ctx->asset->id, 'work_order_type' => FacilityWorkOrder::TYPE_CM,
        'execution_type' => 'external', 'title' => 'Leak in the riser',
        'description' => 'Water into the unit below.', 'trade_id' => $ctx->trade->id,
        'vendor_id' => $ctx->vendor->id, 'status' => 'open', 'priority' => 'high',
        'scheduled_for' => now()->toDateString(),
    ], $attrs));
}

function quote($ctx, FacilityWorkOrder $job, float $labour, float $material, float $service = 0): WorkOrderProposal
{
    return $ctx->svc->submit($job, [
        'labour_amount' => $labour, 'material_amount' => $material, 'service_amount' => $service,
        'scope' => 'Replace the riser.',
    ]);
}

/* ---- the ceiling -------------------------------------------------------- */

it('starts a job at the ceiling its trade sets', function () {
    expect((float) leakJob($this)->nte_amount)->toBe(5000.0);
});

/**
 * Applied when the job is RAISED and never afterwards: changing a trade's default must not silently
 * re-authorise every open job in it.
 */
it('does not re-authorise an open job when the trade default changes', function () {
    $job = leakJob($this);
    $this->trade->update(['default_nte' => 90000]);

    expect((float) $job->fresh()->nte_amount)->toBe(5000.0);
});

/** A trade with no default leaves the job with no ceiling — honest, where 0 would mean "spend nothing". */
it('leaves a job with no ceiling when its trade sets none', function () {
    $this->trade->update(['default_nte' => null]);

    expect(leakJob($this)->nte_amount)->toBeNull();
});

/* ---- the proposal loop -------------------------------------------------- */

it('derives a quote total from its own breakdown', function () {
    $proposal = quote($this, leakJob($this), 12000, 24000, 2000);

    expect((float) $proposal->total_amount)->toBe(38000.0);
});

it('refuses a quote for nothing', function () {
    expect(fn () => quote($this, leakJob($this), 0, 0, 0))->toThrow(DomainException::class);
});

it('refuses a quote against a job that is already finished', function () {
    $job = leakJob($this, ['status' => 'done', 'completed_at' => now()]);

    expect(fn () => quote($this, $job, 1000, 0))->toThrow(DomainException::class);
});

/**
 * **The moment the control does its work.** Approving raises the ceiling to what was agreed AND
 * writes the job's estimate from the quote's own buckets.
 */
it('raises the ceiling and sets the estimate when a quote is approved', function () {
    $job = leakJob($this);
    $this->svc->approve(quote($this, $job, 12000, 24000, 2000));

    $job->refresh();

    expect((float) $job->nte_amount)->toBe(38000.0)
        ->and((float) $job->est_labour_cost)->toBe(12000.0)
        ->and((float) $job->est_material_cost)->toBe(24000.0)
        ->and((float) $job->est_service_cost)->toBe(2000.0)
        // …and the derived total agrees, whichever road set the parts.
        ->and((float) $job->est_total_cost)->toBe(38000.0);
});

/**
 * Raised, never lowered. Approving a quote below an existing ceiling must not quietly tighten what
 * the contractor was already permitted for other work on the same job.
 */
it('never lowers a ceiling by approving a smaller quote', function () {
    $job = leakJob($this, ['nte_amount' => 20000]);
    $this->svc->approve(quote($this, $job, 1000, 500));

    expect((float) $job->fresh()->nte_amount)->toBe(20000.0);
});

/** Two live approvals would make "what was agreed?" unanswerable. */
it('withdraws a competing quote when one is approved', function () {
    $job = leakJob($this);
    $other = quote($this, $job, 30000, 0);
    $chosen = quote($this, $job, 38000, 0);

    $this->svc->approve($chosen);

    expect($other->fresh()->status)->toBe(WorkOrderProposal::STATUS_WITHDRAWN)
        ->and($chosen->fresh()->status)->toBe(WorkOrderProposal::STATUS_APPROVED);
});

/** A refusal says nothing was agreed — it must not touch what already was. */
it('leaves the ceiling and the estimate alone when a quote is refused', function () {
    $job = leakJob($this);
    $this->svc->reject(quote($this, $job, 38000, 0), 'Get a second price.');

    $job->refresh();

    expect((float) $job->nte_amount)->toBe(5000.0)
        ->and($job->est_total_cost)->toBeNull();
});

it('refuses a rejection with no reason', function () {
    $proposal = quote($this, leakJob($this), 38000, 0);

    expect(fn () => $this->svc->reject($proposal, '  '))->toThrow(DomainException::class);
    expect($proposal->fresh()->status)->toBe(WorkOrderProposal::STATUS_SUBMITTED);
});

it('answers a quote only once', function () {
    $proposal = quote($this, leakJob($this), 38000, 0);
    $this->svc->approve($proposal);

    expect(fn () => $this->svc->approve($proposal->fresh()))->toThrow(DomainException::class);
    expect(fn () => $this->svc->reject($proposal->fresh(), 'Changed my mind.'))->toThrow(DomainException::class);
});

/* ---- the breach, shown and never blocked -------------------------------- */

/**
 * **Scenario S4, end to end.** Quoted 38,000, authorised 38,000, billed 46,000 — over by 8,000, and
 * the operator can see it against a number somebody actually agreed to.
 */
it('shows what a job cost over the amount that was authorised', function () {
    $job = leakJob($this);
    $this->svc->approve(quote($this, $job, 12000, 24000, 2000));

    VendorBill::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'facility_work_order_id' => $job->id, 'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'Riser replacement, as done', 'subtotal' => 46000,
        'vat_amount' => 6440, 'total' => 52440,
    ]);

    $job->refresh();

    expect($job->overNteBy())->toBe(8000.0)
        ->and(FacilityWorkOrder::query()->overNte()->pluck('id')->all())->toBe([$job->id])
        // …and the cost object's own variance says the same thing from the other side.
        ->and((float) $job->costVariance())->toBe(-8000.0);
});

/**
 * **Shown, NEVER blocked** — the same settled reasoning as the three-way match: a job can
 * legitimately grow for something nobody could have proposed for, so jamming accounts payable would
 * be wrong. A stated deviation from ServiceChannel, which holds the invoice.
 */
it('still lets the bill be approved and paid when it exceeds the ceiling', function () {
    $job = leakJob($this);

    $bill = VendorBill::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'facility_work_order_id' => $job->id, 'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'Well over the ceiling', 'subtotal' => 46000,
        'vat_amount' => 6440, 'total' => 52440,
    ]);

    expect($bill->fresh()->status)->toBe('approved')
        // The breach is visible, which is the whole control.
        ->and($job->fresh()->overNteBy())->toBe(41000.0);
});

/** A job with no ceiling has nothing to exceed — null, never zero. */
it('reports no breach on a job that was never given a ceiling', function () {
    $this->trade->update(['default_nte' => null]);
    $job = leakJob($this);

    VendorBill::create([
        'vendor_id' => $this->vendor->id, 'asset_id' => $this->asset->id,
        'facility_work_order_id' => $job->id, 'category' => 'maintenance', 'status' => 'approved',
        'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'Uncapped', 'subtotal' => 9000, 'vat_amount' => 1260, 'total' => 10260,
    ]);

    expect($job->fresh()->overNteBy())->toBeNull()
        ->and(FacilityWorkOrder::query()->overNte()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Review pass (2026-08-20)
|--------------------------------------------------------------------------
| Every quote was treated as a replacement. That is right for a REVISED price and silently wrong for
| a SUPPLEMENT — the ordinary case where a contractor opens a wall, finds more work, and quotes for
| the extra. Measured on the live database: approving 38,000 then a supplement of 8,000 left the
| ceiling at 38,000 and collapsed the estimate to 8,000, so the job read as 38,000 overspent. Worse
| than unsupported — it corrupts the number planned-vs-actual is measured against, and nothing on
| the screen said which the operator was recording.
*/

it('adds a supplementary quote to the ceiling and the estimate', function () {
    $job = leakJob($this);
    $this->svc->approve(quote($this, $job, 38000, 0));

    $extra = $this->svc->submit($job, [
        'labour_amount' => 8000, 'material_amount' => 0, 'service_amount' => 0,
        'is_supplementary' => true, 'scope' => 'Found more once the wall was open.',
    ]);
    $this->svc->approve($extra);

    $job->refresh();

    expect((float) $job->nte_amount)->toBe(46000.0)
        ->and((float) $job->est_total_cost)->toBe(46000.0)
        ->and((float) $job->est_labour_cost)->toBe(46000.0);
});

/** The control: a FULL quote still replaces, or a revised price would double. */
it('still replaces the estimate when a quote is the whole price', function () {
    $job = leakJob($this);
    $this->svc->approve(quote($this, $job, 38000, 0));
    $this->svc->approve(quote($this, $job, 41000, 0));

    expect((float) $job->fresh()->est_total_cost)->toBe(41000.0)
        ->and((float) $job->fresh()->nte_amount)->toBe(41000.0);
});

/**
 * Two supplements for two different pieces of extra work are not alternatives to each other, so
 * approving one must not withdraw the other — unlike two competing whole prices, which are.
 */
it('does not withdraw a pending supplement when another is approved', function () {
    $job = leakJob($this);
    $this->svc->approve(quote($this, $job, 38000, 0));

    $first = $this->svc->submit($job, ['labour_amount' => 3000, 'material_amount' => 0, 'service_amount' => 0, 'is_supplementary' => true]);
    $second = $this->svc->submit($job, ['labour_amount' => 5000, 'material_amount' => 0, 'service_amount' => 0, 'is_supplementary' => true]);

    $this->svc->approve($first);

    expect($second->fresh()->status)->toBe(WorkOrderProposal::STATUS_SUBMITTED);

    // …and approving the second adds again, reaching 46,000.
    $this->svc->approve($second->fresh());
    expect((float) $job->fresh()->nte_amount)->toBe(46000.0);
});
